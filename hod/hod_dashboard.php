<?php
/**
 * File: hod/hod_dashboard.php
 * Purpose: Provide the protected landing page for verified HOD users to approve tickets and process reassignment requests.
 * Author: Imran IT Intern
 */

// -----------------------------------------------------------------------------------------
// Database connection and workflow engine setup
// Creates a new session or resumes an existing one based on a unique session identifier.
// -----------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/workflow_engine.php';
session_start();

// -------------------------------------
// Verified login check for HOD users
// -------------------------------------
$verifiedEmail = $_SESSION['hod_verified_email'] ?? '';

if (!filter_var($verifiedEmail, FILTER_VALIDATE_EMAIL)) {
    header('Location: hod_login.php');
    exit;
}

// ------------------------------------------------------------------
// Fetch HOD User Info & Department from table in database
// Fetch HOD account verifying user role is HOD or in approvers table.
// ------------------------------------------------------------------
$stmtHOD = $pdo->prepare("
    SELECT u.user_id, u.department_id, u.display_name, u.fullname, u.role 
    FROM users u 
    WHERE u.email = :email
      AND (u.role = 'hod' OR EXISTS (
          SELECT 1 FROM approvers a WHERE a.approver_user_id = u.user_id
      ))
    LIMIT 1
");

$stmtHOD->execute(['email' => $verifiedEmail]);
$currentHOD = $stmtHOD->fetch(PDO::FETCH_ASSOC);

if (!$currentHOD) {
    die('Error: HOD account not found.');
}

$hod_user_id = $currentHOD['user_id'];
$hod_dept_id = $currentHOD['department_id'];

// -----------------------------------------------------------
// Process Actions (POST Request)
// Process Reassignment Requests or Regular Ticket Approvals.
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket_id = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);

    // --------------------------------------------------------------------------
    // ACTION: Assign staff after workflow completion or reassignment approval
    // FIXED: Always check against the executing department's valid HODs
    // --------------------------------------------------------------------------
    if (isset($_POST['assignment_action']) && $_POST['assignment_action'] === 'assign' && $ticket_id) {
        $assignedStaffId = filter_input(INPUT_POST, 'assigned_staff_id', FILTER_VALIDATE_INT);

        try {
            if (!$assignedStaffId) {
                throw new Exception('Please select a staff member.');
            }

            $pdo->beginTransaction();
            $assignmentCheck = $pdo->prepare("
                SELECT t.ticket_id, t.attachment_file,
                       CASE WHEN EXISTS (SELECT 1 FROM ticket_worksteps cws WHERE cws.ticket_id = t.ticket_id)
                            THEN (SELECT final_user.department_id
                                  FROM ticket_approvals final_approval
                                  JOIN users final_user ON final_user.user_id = final_approval.approver_user_id
                                  WHERE final_approval.ticket_id = t.ticket_id
                                    AND final_approval.status IN ('Approved', 'Approved & Awaiting Assignment')
                                  ORDER BY final_approval.step_number DESC LIMIT 1)
                            ELSE rt.target_department_id END AS assignment_department_id
                FROM tickets t
                JOIN request_types rt ON rt.type_id = t.type_id
                WHERE t.ticket_id = :ticket_id
                  AND t.status IN (
                      'Approved & Awaiting Assignment', 
                      'Reassignment approved & Waiting for HOD assignment', 
                      'Pending Assignment'
                  )
                  AND t.assigned_staff_id IS NULL
                FOR UPDATE
            ");
            $assignmentCheck->execute(['ticket_id' => $ticket_id]);
            $assignment = $assignmentCheck->fetch(PDO::FETCH_ASSOC);

            if (!$assignment) {
                throw new Exception('This ticket is not awaiting staff assignment.');
            }

            // Verify active user is an authorized HOD for the executing department
            $designatedHodIds = getApproverUserIdsByDepartment($pdo, $assignment['assignment_department_id']);
            if (!$designatedHodIds || !in_array((int)$hod_user_id, array_map('intval', $designatedHodIds), true)) {
                throw new Exception('Only an HOD of the executing department can assign this ticket.');
            }

            $staffCheck = $pdo->prepare("
                SELECT user_id
                FROM users
                WHERE user_id = :staff_id
                  AND department_id = :department_id
                  AND role = 'staff'
                LIMIT 1
            ");
            $staffCheck->execute([
                'staff_id' => $assignedStaffId,
                'department_id' => $assignment['assignment_department_id']
            ]);
            if (!$staffCheck->fetchColumn()) {
                throw new Exception('Choose a staff member from the executing department.');
            }

            // 1. Update tickets table
            $assignStmt = $pdo->prepare("
                UPDATE tickets
                SET assigned_staff_id = :staff_id, status = 'Assign to Staff'
                WHERE ticket_id = :ticket_id
                  AND status IN (
                      'Approved & Awaiting Assignment', 
                      'Reassignment approved & Waiting for HOD assignment', 
                      'Pending Assignment'
                  )
                  AND assigned_staff_id IS NULL
            ");
            $assignStmt->execute([
                'staff_id'  => $assignedStaffId,
                'ticket_id' => $ticket_id
            ]);
            if ($assignStmt->rowCount() !== 1) {
                throw new Exception('This ticket has already been assigned.');
            }

            // 2. Update ticket_approvals status for HOD view
            $upApproval = $pdo->prepare("
                UPDATE ticket_approvals
                SET status = 'Assign to Staff', action_taken_at = NOW()
                WHERE ticket_id = :ticket_id
                  AND step_number = (
                      SELECT max_step FROM (
                          SELECT MAX(step_number) AS max_step
                          FROM ticket_approvals
                          WHERE ticket_id = :ticket_id
                      ) AS sub
                  )
            ");
            $upApproval->execute(['ticket_id' => $ticket_id]);

            // 3. Log assignment action
            $logStmt = $pdo->prepare("
                INSERT INTO ticket_logs (ticket_id, staff_id, action_type, comments, logged_at)
                VALUES (:ticket_id, :staff_id, 'Assigned', 'Ticket assigned to staff by HOD.', NOW())
            ");
            $logStmt->execute([
                'ticket_id' => $ticket_id,
                'staff_id'  => $assignedStaffId
            ]);

            $pdo->commit();
            header('Location: hod_dashboard.php?msg=assignment_processed');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = $e->getMessage();
        }
    }

    // --------------------------------------------------------------------------
    // ACTION A: Process Reassignment Requests (Step 1 Approval/Rejection)
    // --------------------------------------------------------------------------
    if (isset($_POST['reassignment_action']) && $ticket_id) {
        $reassignAction = $_POST['reassignment_action'];

        try {
            $pdo->beginTransaction();

            if ($reassignAction === 'reject') {
                $stmtLastStaff = $pdo->prepare("
                    SELECT staff_id FROM ticket_logs 
                    WHERE ticket_id = :ticket_id AND action_type = 'Request for Reassignment' 
                    ORDER BY log_id DESC LIMIT 1
                ");
                $stmtLastStaff->execute(['ticket_id' => $ticket_id]);
                $lastStaff = $stmtLastStaff->fetchColumn();

                if ($lastStaff) {
                    $rejectedStatus = 'Reassignment rejected & Assigned back to staff';

                    $stmtReject = $pdo->prepare("
                        UPDATE tickets 
                        SET status = :status, assigned_staff_id = :staff_id 
                        WHERE ticket_id = :ticket_id
                    ");
                    $stmtReject->execute([
                        'status'    => $rejectedStatus,
                        'staff_id'  => $lastStaff,
                        'ticket_id' => $ticket_id
                    ]);

                    $approvalToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    $stmtApproval = $pdo->prepare("
                        INSERT INTO ticket_approvals (
                            ticket_id, step_number, approver_user_id, approval_token, created_at, status, remarks, action_taken_at
                        ) VALUES (
                            :ticket_id, 0, :approver_id, :token, NOW(), :status, 'HOD rejected reassignment request', NOW()
                        )
                    ");
                    $stmtApproval->execute([
                        'ticket_id'   => $ticket_id,
                        'approver_id' => $hod_user_id,
                        'token'       => $approvalToken,
                        'status'      => $rejectedStatus
                    ]);

                    $stmtLog = $pdo->prepare("
                        INSERT INTO ticket_logs (ticket_id, staff_id, action_type, comments, logged_at) 
                        VALUES (:ticket_id, :hod_id, 'Reassignment Rejected', 'HOD rejected reassignment request. Returned to original staff.', NOW())
                    ");
                    $stmtLog->execute(['ticket_id' => $ticket_id, 'hod_id' => $hod_user_id]);
                }

            } elseif ($reassignAction === 'approve') {
                $approvedStatus = 'Reassignment approved & Waiting for HOD assignment';

                $stmtAccept = $pdo->prepare("
                    UPDATE tickets 
                    SET status = :status, assigned_staff_id = NULL 
                    WHERE ticket_id = :ticket_id AND status = 'Request for Reassignment'
                ");
                $stmtAccept->execute([
                    'status'    => $approvedStatus,
                    'ticket_id' => $ticket_id
                ]);

                $approvalToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $stmtApproval = $pdo->prepare("
                    INSERT INTO ticket_approvals (
                        ticket_id, step_number, approver_user_id, approval_token, created_at, status, remarks, action_taken_at
                    ) VALUES (
                        :ticket_id, 0, :approver_id, :token, NOW(), :status, 'HOD approved reassignment request', NOW()
                    )
                ");
                $stmtApproval->execute([
                    'ticket_id'   => $ticket_id,
                    'approver_id' => $hod_user_id,
                    'token'       => $approvalToken,
                    'status'      => $approvedStatus
                ]);

                $stmtLog = $pdo->prepare("
                    INSERT INTO ticket_logs (ticket_id, staff_id, action_type, comments, logged_at) 
                    VALUES (:ticket_id, :hod_id, 'Reassignment Approved', 'HOD approved reassignment request. Awaiting staff selection.', NOW())
                ");
                $stmtLog->execute(['ticket_id' => $ticket_id, 'hod_id' => $hod_user_id]);
            }

            $pdo->commit();
            header('Location: hod_dashboard.php?msg=reassignment_processed');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = $e->getMessage();
        }
    }

    // --------------------------------------------------------------------------
    // ACTION B: Process Regular Ticket Approvals (Dynamic Workflow Engine)
    // --------------------------------------------------------------------------
    $approval_id  = filter_input(INPUT_POST, 'approval_id', FILTER_VALIDATE_INT);
    $action       = $_POST['action'] ?? ''; 
    $remarksInput = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
    $remarks      = !empty($remarksInput) ? $remarksInput : NULL;

    if ($ticket_id && $approval_id && in_array($action, ['approve', 'reject'])) {
        try {
            if ($action === 'reject' && $remarksInput === '') {
                throw new Exception('Remarks are required when rejecting a ticket.');
            }

            $pdo->beginTransaction();

            $ticketLock = $pdo->prepare("SELECT ticket_id FROM tickets WHERE ticket_id = :ticket_id FOR UPDATE");
            $ticketLock->execute(['ticket_id' => $ticket_id]);
            if (!$ticketLock->fetchColumn()) {
                throw new Exception('Ticket not found.');
            }

            $approvalCheck = $pdo->prepare("
                SELECT ta.approval_id, ta.step_number, ta.approver_user_id,
                       t.requester_id, t.status AS ticket_status,
                       rt.target_department_id, rt.workflow_route,
                       step_approver.department_id AS step_approval_department_id,
                       CASE WHEN requester_step.ticket_id IS NOT NULL THEN 'REQUESTER_SELECTED' ELSE ws.approver_type END AS approver_type,
                       COALESCE(requester_step.approver_user_id, ws.specific_user_id) AS specific_user_id,
                       COALESCE(requester_step.status_label, ws.status_label) AS status_label
                FROM ticket_approvals ta
                JOIN tickets t ON t.ticket_id = ta.ticket_id
                JOIN request_types rt ON rt.type_id = t.type_id
                LEFT JOIN workflow_steps ws
                  ON ws.workflow_route = rt.workflow_route
                 AND ws.step_number = ta.step_number
                LEFT JOIN ticket_worksteps requester_step
                  ON requester_step.ticket_id = t.ticket_id
                 AND requester_step.step_number = ta.step_number
                LEFT JOIN rt_approval_steps step_approver
                  ON step_approver.request_type_id = rt.type_id
                 AND step_approver.step_number = ta.step_number
                WHERE ta.approval_id = :approval_id
                  AND ta.ticket_id = :ticket_id
                  AND ta.status = 'Pending'
                  AND ta.approver_user_id = :hod_user_id
                  AND ta.step_number = (
                      SELECT COALESCE(MAX(done.step_number), 0) + 1
                      FROM ticket_approvals done
                      WHERE done.ticket_id = ta.ticket_id
                        AND done.status IN ('Approved', 'Approved & Awaiting Assignment')
                        AND done.step_number > 0
                  )
                FOR UPDATE
            ");
            $approvalCheck->execute([
                'approval_id' => $approval_id,
                'ticket_id'   => $ticket_id,
                'hod_user_id' => $hod_user_id
            ]);
            $approval = $approvalCheck->fetch(PDO::FETCH_ASSOC);

            if (!$approval || $approval['ticket_status'] !== $approval['status_label']) {
                throw new Exception('This approval is not assigned to you, is no longer current, or has already been processed.');
            }

            if ($approval['approver_type'] === 'REQUESTER_HOD') {
                $designatedApproverIds = getRequesterHodUserIds($pdo, $approval['requester_id']);
            } elseif ($approval['approver_type'] === 'TARGET_HOD') {
                $designatedApproverIds = getApproverUserIdsByDepartment($pdo, $approval['target_department_id']);
            } elseif ($approval['approver_type'] === 'REQUEST_TYPE_HOD') {
                $designatedApproverIds = getApproverUserIdsByDepartment(
                    $pdo,
                    $approval['step_approval_department_id']
                );
            } elseif ($approval['approver_type'] === 'SPECIFIC_USER') {
                $designatedApproverIds = [(int)$approval['specific_user_id']];
            } elseif ($approval['approver_type'] === 'REQUESTER_SELECTED') {
                $designatedApproverIds = [(int)$approval['specific_user_id']];
            } else {
                throw new Exception('The workflow step has an unsupported approver type.');
            }

            if (!$designatedApproverIds || !in_array((int)$hod_user_id, array_map('intval', $designatedApproverIds), true)
                || (int)$approval['approver_user_id'] !== (int)$hod_user_id) {
                throw new Exception('Only the designated approver for this workflow step can process it.');
            }

            if ($action === 'reject') {
                $stmtAppUpdate = $pdo->prepare("
                    UPDATE ticket_approvals 
                    SET status = 'Rejected', remarks = :remarks, action_taken_at = NOW() 
                    WHERE approval_id = :approval_id
                      AND ticket_id = :ticket_id
                      AND approver_user_id = :hod_user_id
                      AND status = 'Pending'
                ");
                $stmtAppUpdate->execute([
                    'remarks'     => $remarks,
                    'approval_id' => $approval_id,
                    'ticket_id'   => $ticket_id,
                    'hod_user_id' => $hod_user_id
                ]);
                if ($stmtAppUpdate->rowCount() !== 1) {
                    throw new Exception('This approval has already been processed.');
                }

                $closeSiblingApprovals = $pdo->prepare("UPDATE ticket_approvals SET status = 'Superseded', action_taken_at = NOW() WHERE ticket_id = :ticket_id AND step_number = :step_number AND status = 'Pending'");
                $closeSiblingApprovals->execute(['ticket_id' => $ticket_id, 'step_number' => $approval['step_number']]);

                $stmtTicketUpdate = $pdo->prepare("
                    UPDATE tickets 
                    SET status = :status, hod_remarks = :remarks 
                    WHERE ticket_id = :ticket_id
                ");
                $stmtTicketUpdate->execute([
                    'status'    => 'Rejected at Step ' . (int)$approval['step_number'],
                    'remarks'   => $remarks,
                    'ticket_id' => $ticket_id
                ]);

            } elseif ($action === 'approve') {
                $checkNextStep = $pdo->prepare("
                    SELECT step_number 
                    FROM workflow_steps 
                    WHERE workflow_route = :route AND step_number = :next_step
                ");
                $checkNextStep->execute([
                    'route'     => $approval['workflow_route'],
                    'next_step' => (int)$approval['step_number'] + 1
                ]);
                $hasNextStep = (bool)$checkNextStep->fetchColumn();

                $approvalStatus = $hasNextStep ? 'Approved' : 'Approved & Awaiting Assignment';

                $stmtAppUpdate = $pdo->prepare("
                    UPDATE ticket_approvals 
                    SET status = :status, remarks = :remarks, action_taken_at = NOW() 
                    WHERE approval_id = :approval_id
                      AND ticket_id = :ticket_id
                      AND approver_user_id = :hod_user_id
                      AND status = 'Pending'
                ");
                $stmtAppUpdate->execute([
                    'status'      => $approvalStatus,
                    'remarks'     => $remarks,
                    'approval_id' => $approval_id,
                    'ticket_id'   => $ticket_id,
                    'hod_user_id' => $hod_user_id
                ]);
                if ($stmtAppUpdate->rowCount() !== 1) {
                    throw new Exception('This approval has already been processed.');
                }

                $closeSiblingApprovals = $pdo->prepare("UPDATE ticket_approvals SET status = 'Superseded', action_taken_at = NOW() WHERE ticket_id = :ticket_id AND step_number = :step_number AND status = 'Pending'");
                $closeSiblingApprovals->execute(['ticket_id' => $ticket_id, 'step_number' => $approval['step_number']]);

                $ticketRemarks = $pdo->prepare("UPDATE tickets SET hod_remarks = :remarks WHERE ticket_id = :ticket_id");
                $ticketRemarks->execute(['remarks' => $remarks, 'ticket_id' => $ticket_id]);

                advanceTicketWorkflow($pdo, $ticket_id);
            }

            $pdo->commit();
            header('Location: hod_dashboard.php?msg=success');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = $e->getMessage();
        }
    }
}

// --------------------------------------------------------------------------------
// Fetch Standard Pending Approvals for HOD from table in database
// --------------------------------------------------------------------------------
$stmtPending = $pdo->prepare("
    SELECT t.ticket_id, t.attachment_file, t.token, t.remarks AS ticket_remarks, t.meta_data, t.created_at, t.status AS current_ticket_status,
           u.fullname AS requester_name, u.email AS requester_email, u.display_name AS user_name,
           rt.type_name, rt.workflow_route,
           ta.approval_id, ta.step_number
    FROM tickets t
    JOIN users u ON t.requester_id = u.user_id
    JOIN request_types rt ON t.type_id = rt.type_id
    JOIN ticket_approvals ta ON ta.ticket_id = t.ticket_id AND ta.status = 'Pending'
    WHERE ta.approver_user_id = :hod_user_id
    ORDER BY t.ticket_id DESC
");
$stmtPending->execute(['hod_user_id' => $hod_user_id]);
$pendingTickets = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------------------------------------
// Fetch tickets awaiting staff assignment (Initial Workflow or Approved Reassignment)
// --------------------------------------------------------------------------------
$assignmentStmt = $pdo->query("
    SELECT t.ticket_id, t.attachment_file, t.meta_data, t.remarks AS ticket_remarks,
           t.created_at, t.status AS current_ticket_status,
           u.fullname AS requester_name, u.email AS requester_email, u.display_name AS user_name,
           rt.type_name,
           CASE WHEN EXISTS (SELECT 1 FROM ticket_worksteps cws WHERE cws.ticket_id = t.ticket_id)
                THEN (SELECT final_user.department_id
                      FROM ticket_approvals final_approval
                      JOIN users final_user ON final_user.user_id = final_approval.approver_user_id
                      WHERE final_approval.ticket_id = t.ticket_id
                        AND final_approval.status IN ('Approved', 'Approved & Awaiting Assignment')
                      ORDER BY final_approval.step_number DESC LIMIT 1)
                ELSE rt.target_department_id END AS target_department_id,
           d.department_name
    FROM tickets t
    JOIN users u ON u.user_id = t.requester_id
    JOIN request_types rt ON rt.type_id = t.type_id
    LEFT JOIN departments d ON d.department_id = CASE
        WHEN EXISTS (SELECT 1 FROM ticket_worksteps cws WHERE cws.ticket_id = t.ticket_id)
        THEN (SELECT final_user.department_id
              FROM ticket_approvals final_approval
              JOIN users final_user ON final_user.user_id = final_approval.approver_user_id
              WHERE final_approval.ticket_id = t.ticket_id
                AND final_approval.status IN ('Approved', 'Approved & Awaiting Assignment')
              ORDER BY final_approval.step_number DESC LIMIT 1)
        ELSE rt.target_department_id END
    WHERE t.status IN (
        'Approved & Awaiting Assignment', 
        'Reassignment approved & Waiting for HOD assignment', 
        'Pending Assignment'
    )
      AND t.assigned_staff_id IS NULL
    ORDER BY t.ticket_id DESC
");
$allAssignmentTickets = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

// FIXED: Filter assignment tickets by checking if active HOD belongs to target executing department
$pendingAssignments = [];
foreach ($allAssignmentTickets as $assignmentTicket) {
    $executingDeptHodIds = getApproverUserIdsByDepartment($pdo, $assignmentTicket['target_department_id']);
    if (in_array((int)$hod_user_id, array_map('intval', $executingDeptHodIds), true)) {
        $pendingAssignments[] = $assignmentTicket;
    }
}

// Combine actions queue
$pendingActions = [];
foreach ($pendingTickets as $pendingTicket) {
    $pendingActions[] = ['action_type' => 'approval', 'ticket' => $pendingTicket];
}
foreach ($pendingAssignments as $pendingAssignment) {
    $pendingActions[] = ['action_type' => 'assignment', 'ticket' => $pendingAssignment];
}
usort($pendingActions, static function ($left, $right) {
    return strcmp($right['ticket']['created_at'], $left['ticket']['created_at']);
});

// --------------------------------------------------------------------------------
// Fetch Ticket History & Progress Rows
// --------------------------------------------------------------------------------
$progressStmt = $pdo->prepare("
    SELECT t.ticket_id, t.created_at, t.status, t.remarks AS ticket_remarks,
           t.hod_remarks, t.attachment_file,
           requester.fullname AS requester_name,
           requester.display_name AS user_name,
           requester.email AS requester_email,
           rt.type_name, rt.target_department_id,
           assignee.display_name AS assigned_staff_name,
           GREATEST(
               t.created_at,
               COALESCE((
                   SELECT MAX(COALESCE(ta.action_taken_at, ta.created_at))
                   FROM ticket_approvals ta
                   WHERE ta.ticket_id = t.ticket_id
               ), t.created_at),
               COALESCE((
                   SELECT MAX(tl.logged_at)
                   FROM ticket_logs tl
                   WHERE tl.ticket_id = t.ticket_id
               ), t.created_at)
           ) AS last_activity
    FROM tickets t
    JOIN users requester ON requester.user_id = t.requester_id
    JOIN request_types rt ON rt.type_id = t.type_id
    LEFT JOIN users assignee ON assignee.user_id = t.assigned_staff_id
    WHERE EXISTS (
           SELECT 1
           FROM ticket_approvals handled
           WHERE handled.ticket_id = t.ticket_id
             AND handled.approver_user_id = :hod_user_id
             AND handled.status IN ('Approved', 'Approved & Awaiting Assignment', 'Rejected')
       )
       OR EXISTS (
           SELECT 1
           FROM users department_staff
           WHERE department_staff.user_id = t.assigned_staff_id
             AND department_staff.department_id = :hod_dept_id
             AND department_staff.role = 'staff'
       )
    ORDER BY t.created_at DESC
");
$progressStmt->execute([
    'hod_dept_id' => $hod_dept_id,
    'hod_user_id' => $hod_user_id
]);
$ticketProgressRows = $progressStmt->fetchAll(PDO::FETCH_ASSOC);

// Timeline builder
$ticketTimeline = [];
$progressTicketIds = array_map('intval', array_column($ticketProgressRows, 'ticket_id'));
foreach ($ticketProgressRows as $progressRow) {
    $ticketTimeline[(int)$progressRow['ticket_id']][] = [
        'event_at' => $progressRow['created_at'],
        'event' => 'Ticket submitted',
        'details' => $progressRow['ticket_remarks'] ?? ''
    ];
}

if (!empty($progressTicketIds)) {
    $ticketIdParams = [];
    $ticketIdPlaceholders = [];
    foreach ($progressTicketIds as $index => $progressTicketId) {
        $placeholder = 'progress_ticket_' . $index;
        $ticketIdPlaceholders[] = ':' . $placeholder;
        $ticketIdParams[$placeholder] = $progressTicketId;
    }
    $ticketIdList = implode(', ', $ticketIdPlaceholders);

    $approvalEventsStmt = $pdo->prepare("
        SELECT ta.ticket_id, ta.step_number, ta.status, ta.remarks,
               COALESCE(ta.action_taken_at, ta.created_at) AS event_at,
               approver.fullname AS approver_name
        FROM ticket_approvals ta
        LEFT JOIN users approver ON approver.user_id = ta.approver_user_id
        WHERE ta.ticket_id IN ($ticketIdList)
          AND ta.step_number >= 0
        ORDER BY event_at ASC, ta.approval_id ASC
    ");
    $approvalEventsStmt->execute($ticketIdParams);
    foreach ($approvalEventsStmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
        if ((int)$event['step_number'] === 0) {
            $eventText = 'Reassignment decision: ' . $event['status'];
        } else {
            $eventText = 'Step ' . $event['step_number'] . ': ' . $event['status'];
        }
        if (!empty($event['approver_name'])) {
            $eventText .= ' — ' . $event['approver_name'];
        }
        $ticketTimeline[(int)$event['ticket_id']][] = [
            'event_at' => $event['event_at'],
            'event' => $eventText,
            'details' => $event['remarks'] ?? ''
        ];
    }

    $logEventsStmt = $pdo->prepare("
        SELECT tl.ticket_id, tl.action_type, tl.comments, tl.logged_at,
               actor.fullname AS actor_name
        FROM ticket_logs tl
        LEFT JOIN users actor ON actor.user_id = tl.staff_id
        WHERE tl.ticket_id IN ($ticketIdList)
        ORDER BY tl.logged_at ASC, tl.log_id ASC
    ");
    $logEventsStmt->execute($ticketIdParams);
    foreach ($logEventsStmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
        if ($event['action_type'] === 'Assigned') {
            $eventText = 'Assigned to staff' . (!empty($event['actor_name']) ? ' — ' . $event['actor_name'] : '');
        } elseif ($event['action_type'] === 'Request for Reassignment') {
            $eventText = 'Staff requested reassignment' . (!empty($event['actor_name']) ? ' — ' . $event['actor_name'] : '');
        } else {
            $eventText = $event['action_type'] . (!empty($event['actor_name']) ? ' — ' . $event['actor_name'] : '');
        }
        $ticketTimeline[(int)$event['ticket_id']][] = [
            'event_at' => $event['logged_at'],
            'event' => $eventText,
            'details' => $event['comments'] ?? ''
        ];
    }

    foreach ($ticketTimeline as &$timelineEvents) {
        usort($timelineEvents, static function ($left, $right) {
            return strcmp($left['event_at'], $right['event_at']);
        });
    }
    unset($timelineEvents);
}

// --------------------------------------------------------------------------------
// Fetch Pending Reassignment Requests for HOD Approval (Step 1)
// --------------------------------------------------------------------------------
$stmtReassign = $pdo->prepare("
    SELECT t.ticket_id, t.token, t.attachment_file, t.remarks AS ticket_remarks, t.created_at, t.status,
           u.fullname AS requester_name, u.display_name AS user_name, u.email AS requester_email,
           rt.type_name,
           (
                SELECT tl.staff_id
                FROM ticket_logs tl
                WHERE tl.ticket_id = t.ticket_id AND tl.action_type = 'Request for Reassignment'
                ORDER BY tl.log_id DESC
                LIMIT 1
            ) AS requesting_staff_id,
           s.fullname AS requesting_staff_name
    FROM tickets t
    JOIN users u ON t.requester_id = u.user_id
    JOIN request_types rt ON t.type_id = rt.type_id
    LEFT JOIN users s ON s.user_id = (
        SELECT tl.staff_id FROM ticket_logs tl 
        WHERE tl.ticket_id = t.ticket_id AND tl.action_type = 'Request for Reassignment' 
        ORDER BY tl.log_id DESC LIMIT 1
    )
    WHERE CASE WHEN EXISTS (SELECT 1 FROM ticket_worksteps cws WHERE cws.ticket_id = t.ticket_id)
               THEN (SELECT final_user.department_id
                     FROM ticket_approvals final_approval
                     JOIN users final_user ON final_user.user_id = final_approval.approver_user_id
                     WHERE final_approval.ticket_id = t.ticket_id
                       AND final_approval.status IN ('Approved', 'Approved & Awaiting Assignment')
                     ORDER BY final_approval.step_number DESC LIMIT 1)
               ELSE rt.target_department_id END = :hod_dept_id
      AND t.status = 'Request for Reassignment'
    ORDER BY t.ticket_id DESC
");
$stmtReassign->execute(['hod_dept_id' => $hod_dept_id]);
$reassignmentRequests = $stmtReassign->fetchAll(PDO::FETCH_ASSOC);

// Fetch Department Staff List
$allStaff = $pdo->query("
    SELECT user_id, department_id, fullname, email
    FROM users
    WHERE role = 'staff'
    ORDER BY fullname ASC
")->fetchAll(PDO::FETCH_ASSOC);
$staffByDepartment = [];
foreach ($allStaff as $staffMember) {
    $staffByDepartment[$staffMember['department_id']][] = $staffMember;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - HOD Dashboard</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

    <!-- Executive Dark Header -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm mb-4">
        <div class="container">
            <a class="navbar-brand fw-bold text-uppercase" href="#">
                <span class="badge bg-primary me-2">HOD</span> Flowdesk Approval Portal
            </a>
            <div class="d-flex align-items-center gap-3">
                <span class="text-white-50 small">Signed in as: <strong class="text-white"><?= htmlspecialchars($currentHOD['fullname']) ?></strong> (<?= htmlspecialchars($verifiedEmail) ?>)</span>
                <a href="hod_login.php" class="btn btn-outline-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container my-4">

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'success'): ?>
            <div class="alert alert-success alert-dismissible fade show timed-alert" role="alert">
                <strong>Success!</strong> Action processed successfully.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'reassignment_processed'): ?>
            <div class="alert alert-success alert-dismissible fade show timed-alert" role="alert">
                <strong>Success!</strong> Reassignment request processed successfully.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'assignment_processed'): ?>
            <div class="alert alert-success alert-dismissible fade show timed-alert" role="alert">
                <strong>Success!</strong> Staff assignment completed.
            </div>
        <?php endif; ?>

        <?php if (isset($errorMsg)): ?>
            <div class="alert alert-danger alert-dismissible fade show timed-alert" role="alert">
                <strong>Error:</strong> <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <script>
        setTimeout(function() {
            var alerts = document.getElementsByClassName('timed-alert');
            for (var i = 0; i < alerts.length; i++) {
                alerts[i].style.display = 'none';
            }
        }, 5000);
        </script>

        <!-- Step 1: Reassignment Approval Queue -->
        <div class="card shadow-sm border-0 mb-5">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h3 class="h5 mb-0 fw-bold">Pending Reassignment Requests</h3>
                <span class="badge bg-info text-dark"><?= count($reassignmentRequests) ?> Pending</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Ticket ID</th>
                                <th>Requester</th>
                                <th>Category</th>
                                <th>Requesting Staff</th>
                                <th>Current Status</th>
                                <th style="min-width: 250px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($reassignmentRequests)): ?>
                                <?php foreach ($reassignmentRequests as $req): ?>
                                    <tr>
                                        <td><strong>#<?= htmlspecialchars($req['ticket_id']) ?></strong></td>
                                        <td><?= htmlspecialchars($req['requester_name']) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($req['type_name']) ?></span></td>
                                        <td><span class="fw-bold text-primary"><?= htmlspecialchars($req['requesting_staff_name'] ?? 'Staff') ?></span></td>
                                        <td><span class="badge bg-info text-dark"><?= htmlspecialchars($req['status']) ?></span></td>
                                        <td>
                                            <div class="d-flex flex-column gap-2">
                                                <form method="POST" action="hod_dashboard.php">
                                                    <input type="hidden" name="ticket_id" value="<?= $req['ticket_id'] ?>">
                                                    <input type="hidden" name="reassignment_action" value="approve">
                                                    <button type="submit" class="btn btn-success btn-sm w-100">
                                                        Approve Reassignment
                                                    </button>
                                                </form>

                                                <form method="POST" action="hod_dashboard.php">
                                                    <input type="hidden" name="ticket_id" value="<?= $req['ticket_id'] ?>">
                                                    <input type="hidden" name="reassignment_action" value="reject">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100" onclick="return confirm('Reject request and return ticket to assigned staff?');">
                                                        Reject (Return to Staff)
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No pending reassignment requests.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Action Queue (Workflow Step Approvals + Staff Assignments) -->
        <div class="card shadow-sm border-0 mb-5">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h3 class="h5 mb-0 fw-bold">Pending Request Actions</h3>
                <span class="badge bg-warning text-dark"><?= count($pendingActions) ?> Pending</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Ticket ID &amp; Date</th>
                                <th>Requester</th>
                                <th>Category</th>
                                <th>Request Details</th>
                                <th>Current Status</th>
                                <th style="min-width: 250px;">Next Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($pendingActions)): ?>
                                <?php foreach ($pendingActions as $pendingAction): ?>
                                    <?php
                                        $actionType = $pendingAction['action_type'];
                                        $actionTicket = $pendingAction['ticket'];
                                        $metaData = !empty($actionTicket['meta_data']) ? json_decode($actionTicket['meta_data'], true) : [];
                                    ?>
                                    <tr>
                                        <td>
                                            <strong>Ticket ID: </strong>#<?= htmlspecialchars($actionTicket['ticket_id']) ?><br>
                                            <strong>Date &amp; Time: </strong><br><small class="text-muted"><?= htmlspecialchars($actionTicket['created_at']) ?></small>
                                            <?php if (!empty($actionTicket['attachment_file'])): ?>
                                                <br><a href="../<?= htmlspecialchars(ltrim($actionTicket['attachment_file'], '/'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-link p-0">View Attachment</a>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($actionTicket['user_name']) ?><br><small class="text-muted"><?= htmlspecialchars($actionTicket['requester_email']) ?></small></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($actionTicket['type_name']) ?></span></td>
                                        <td>
                                            <strong>Remarks: </strong> <?= htmlspecialchars($actionTicket['ticket_remarks']) ?>
                                            <?php if (!empty($metaData) && is_array($metaData)): ?>
                                                <ul class="list-unstyled small mt-2 mb-0 bg-light p-2 rounded border">
                                                    <?php foreach ($metaData as $key => $value): ?>
                                                        <?php if (in_array($key, ['submission_ip', 'submitted_at'], true)) continue; ?>
                                                        <li><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?>:</strong> <?= htmlspecialchars(is_scalar($value) ? (string)$value : json_encode($value)) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($actionTicket['current_ticket_status']) ?></span></td>
                                        <td>
                                            <?php if ($actionType === 'approval'): ?>
                                                <form method="POST" action="hod_dashboard.php">
                                                    <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($actionTicket['ticket_id']) ?>">
                                                    <input type="hidden" name="approval_id" value="<?= htmlspecialchars($actionTicket['approval_id']) ?>">
                                                    <label for="rem_<?= $actionTicket['ticket_id'] ?>" class="form-label small fw-bold mb-1">Remarks <br><small class="text-muted">(only required for rejection)</small></label>
                                                    <textarea name="remarks" id="rem_<?= $actionTicket['ticket_id'] ?>" rows="2" class="form-control form-control-sm mb-2" placeholder="Enter a reason when rejecting..."></textarea>
                                                    <div class="d-flex gap-2">
                                                        <button type="submit" name="action" value="approve" class="btn btn-success btn-sm w-100" onclick="document.getElementById('rem_<?= $actionTicket['ticket_id'] ?>').required = false;">Approve</button>
                                                        <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm w-100" onclick="document.getElementById('rem_<?= $actionTicket['ticket_id'] ?>').required = true;">Reject</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <strong>Assign staff</strong><br>
                                                <?php 
                                                    $availableStaffList = $staffByDepartment[$actionTicket['target_department_id']] ?? []; 
                                                ?>
                                                <?php if (!empty($availableStaffList)): ?>
                                                    <form method="POST" action="hod_dashboard.php" class="mt-1">
                                                        <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($actionTicket['ticket_id']) ?>">
                                                        <input type="hidden" name="assignment_action" value="assign">
                                                        <select name="assigned_staff_id" class="form-select form-select-sm mb-2" required>
                                                            <option value="">-- Select Staff --</option>
                                                            <?php foreach ($availableStaffList as $staff): ?>
                                                                <option value="<?= htmlspecialchars($staff['user_id']) ?>"><?= htmlspecialchars($staff['fullname']) ?> (<?= htmlspecialchars($staff['email']) ?>)</option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="btn btn-primary btn-sm w-100">Assign Staff</button>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="alert alert-warning p-2 mb-0 mt-1 small" role="alert">
                                                        <strong>No staff available</strong> in this department to assign. Please add a staff member with role 'staff' to this department.
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No tickets are currently waiting for your action.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Ticket History & Progress Section -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white">
                <h3 class="h5 mb-0 fw-bold">Ticket History &amp; Progress</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Ticket</th>
                                <th>Requester</th>
                                <th>Category</th>
                                <th>Current Status</th>
                                <th>Assigned Staff</th>
                                <th>Activity Timeline</th>
                                <th>Last Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($ticketProgressRows)): ?>
                                <?php foreach ($ticketProgressRows as $progressRow): ?>
                                    <tr>
                                        <td>
                                            <strong>Ticket ID: </strong>#<?= htmlspecialchars($progressRow['ticket_id']) ?><br>
                                            <strong>Date &amp; Time: </strong><br><small class="text-muted"><?= htmlspecialchars($progressRow['created_at']) ?></small>
                                            <?php if (!empty($progressRow['attachment_file'])): ?>
                                                <br><a href="../<?= htmlspecialchars(ltrim($progressRow['attachment_file'], '/'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-link p-0">View Attachment</a>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($progressRow['user_name']) ?><br><small class="text-muted"><?= htmlspecialchars($progressRow['requester_email']) ?></small></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($progressRow['type_name']) ?></span></td>
                                        <td>
                                            <span class="badge bg-primary mb-1"><?= htmlspecialchars($progressRow['status']) ?></span>
                                            <?php if (!empty($progressRow['hod_remarks'])): ?>
                                                <br><small class="text-muted">HOD remarks: <?= htmlspecialchars($progressRow['hod_remarks']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-outline-dark text-dark border"><?= htmlspecialchars($progressRow['assigned_staff_name'] ?? 'Not assigned') ?></span></td>
                                        <td>
                                            <?php if (!empty($ticketTimeline[(int)$progressRow['ticket_id']])): ?>
                                                <ol class="ps-3 mb-0 small">
                                                    <?php foreach ($ticketTimeline[(int)$progressRow['ticket_id']] as $timelineEvent): ?>
                                                        <li class="mb-1">
                                                            <strong><?= htmlspecialchars($timelineEvent['event_at']) ?> &mdash; <?= htmlspecialchars($timelineEvent['event']) ?></strong>
                                                            <?php if (!empty($timelineEvent['details'])): ?>
                                                                <br><span class="text-muted"><?= htmlspecialchars($timelineEvent['details']) ?></span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ol>
                                            <?php else: ?>
                                                <span class="text-muted">No activity recorded yet.</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($progressRow['last_activity']) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">No ticket records are assigned to you or your staff.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>