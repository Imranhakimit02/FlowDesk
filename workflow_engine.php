<?php
/**
 * File: workflow_engine.php
 * Purpose: Core logic engine for processing workflow step progression and ticket approvals.
 * Author: Imran IT Intern
 */

// --------------------------------------------------------------------------------
// Helper function to get HOD user ID for a target department
// Search for user in approvers table or users table assigned to department.
// --------------------------------------------------------------------------------
function getApproverUserIdsByDepartment($pdo, $departmentId) {
    if (!$departmentId) return [];

    // A department level may have several alternatives; the first decision at that level wins.
    $stmt = $pdo->prepare("
        SELECT a.approver_user_id
        FROM approvers a
        JOIN users u ON u.user_id = a.approver_user_id
        WHERE a.department_id = :dept_id
          AND u.role != 'staff'
        ORDER BY a.approver_id ASC
    ");
    $stmt->execute(['dept_id' => $departmentId]);
    $approverIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($approverIds) {
        return $approverIds;
    }

    // Fallback: search for user in database assigned to department
    $stmt = $pdo->prepare("
        SELECT user_id 
        FROM users 
        WHERE department_id = :dept_id AND role = 'hod'
        ORDER BY user_id ASC 
        LIMIT 1
    ");
    $stmt->execute(['dept_id' => $departmentId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ? [(int)$user['user_id']] : [];
}

// Backward-compatible single-user helper for existing assignment routing.
function getApproverUserIdByDepartment($pdo, $departmentId) {
    $approverIds = getApproverUserIdsByDepartment($pdo, $departmentId);
    return $approverIds[0] ?? null;
}

// --------------------------------------------------------------------------------
// Helper function to get HOD user ID for the ticket requester
// Search for requester department HOD in database.
// --------------------------------------------------------------------------------
function getRequesterHodUserIds($pdo, $requesterUserId) {
    if (!$requesterUserId) return [];

    $stmt = $pdo->prepare("
        SELECT u.department_id 
        FROM users u 
        WHERE u.user_id = :uid
    ");
    $stmt->execute(['uid' => $requesterUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && !empty($user['department_id'])) {
        return getApproverUserIdsByDepartment($pdo, $user['department_id']);
    }

    return [];
}

function getRequesterHodUserId($pdo, $requesterUserId) {
    $approverIds = getRequesterHodUserIds($pdo, $requesterUserId);
    return $approverIds[0] ?? null;
}

// ----------------------------------------------------------------
// Main ticket verification handler called by email verification
// Initializes and advances verified tickets through the workflow.
// ----------------------------------------------------------------
function processTicketWorkflow($pdo, $ticketId) {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // The verification transition may only initialize a ticket once.
        $ticketStmt = $pdo->prepare("
            SELECT ticket_id
            FROM tickets
            WHERE ticket_id = :ticket_id
              AND status = 'Pending Email Verification'
            FOR UPDATE
        ");
        $ticketStmt->execute(['ticket_id' => $ticketId]);
        if (!$ticketStmt->fetchColumn()) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $advanced = advanceTicketWorkflow($pdo, $ticketId);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $advanced;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// -------------------------------------------------------------
// Workflow processing function
// Advances ticket through defined workflow steps in database.
// -------------------------------------------------------------
function advanceTicketWorkflow($pdo, $ticketId) {
    // 1. Fetch ticket details joined with request_types to get target_department_id, workflow_route & requester id (created_by)
    $tStmt = $pdo->prepare("
        SELECT t.ticket_id, t.type_id, t.requester_id, rt.target_department_id, rt.workflow_route
        FROM tickets t
        JOIN request_types rt ON t.type_id = rt.type_id
        WHERE t.ticket_id = :id
    ");
    $tStmt->execute(['id' => $ticketId]);
    $ticket = $tStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) return false;

    $workflowRoute   = $ticket['workflow_route'];
    $targetDeptId    = $ticket['target_department_id'];
    $requesterUserId = $ticket['requester_id'];

    // 2. Check current highest step in ticket_approvals
    $aStmt = $pdo->prepare("
        SELECT MAX(step_number) AS max_step 
        FROM ticket_approvals 
        WHERE ticket_id = :id
    ");
    $aStmt->execute(['id' => $ticketId]);
    $currentStepNum = $aStmt->fetchColumn();

    $nextStepNum = ($currentStepNum === null) ? 1 : ((int)$currentStepNum + 1);

    // 3. Fetch definition for the next workflow step
    $sStmt = $pdo->prepare("
        SELECT approver_type, specific_user_id, status_label
        FROM workflow_steps 
        WHERE workflow_route = :route AND step_number = :step
    ");
    $sStmt->execute([
        'route' => $workflowRoute,
        'step'  => $nextStepNum
    ]);
    $nextStep = $sStmt->fetch(PDO::FETCH_ASSOC);

    // A requester-defined workflow is saved per ticket and takes precedence over
    // the request category's shared route for the same step number.
    $customStepStmt = $pdo->prepare("SELECT approver_user_id, status_label
        FROM ticket_worksteps WHERE ticket_id = :ticket_id AND step_number = :step_number");
    $customStepStmt->execute(['ticket_id' => $ticketId, 'step_number' => $nextStepNum]);
    $customStep = $customStepStmt->fetch(PDO::FETCH_ASSOC);
    $customWorkflowCountStmt = $pdo->prepare('SELECT COUNT(*) FROM ticket_worksteps WHERE ticket_id = :ticket_id');
    $customWorkflowCountStmt->execute(['ticket_id' => $ticketId]);
    $hasCustomWorkflow = (int)$customWorkflowCountStmt->fetchColumn() > 0;
    if ($hasCustomWorkflow && $customStep) {
        $nextStep = [
            'approver_type' => 'REQUESTER_SELECTED',
            'specific_user_id' => (int)$customStep['approver_user_id'],
            'status_label' => $customStep['status_label']
        ];
    } elseif ($hasCustomWorkflow) {
        // A ticket-specific route replaces the category route in its entirety.
        $nextStep = false;
    }

    // 4. Process step or complete workflow
    if ($nextStep) {
        $approverUserIds = [];

        if ($nextStep['approver_type'] === 'REQUESTER_HOD') {
            $approverUserIds = getRequesterHodUserIds($pdo, $requesterUserId);
        } elseif ($nextStep['approver_type'] === 'TARGET_HOD') {
            $approverUserIds = getApproverUserIdsByDepartment($pdo, $targetDeptId);
        } elseif ($nextStep['approver_type'] === 'REQUEST_TYPE_HOD') {
            $departmentStmt = $pdo->prepare("
                SELECT department_id
                FROM rt_approval_steps
                WHERE request_type_id = :type_id AND step_number = :step_number
                LIMIT 1
            ");
            $departmentStmt->execute([
                'type_id' => $ticket['type_id'],
                'step_number' => $nextStepNum
            ]);
            $stepDepartmentId = $departmentStmt->fetchColumn();
            $approverUserIds = getApproverUserIdsByDepartment($pdo, $stepDepartmentId ?: null);
        } elseif ($nextStep['approver_type'] === 'SPECIFIC_USER') {
            $approverUserIds = $nextStep['specific_user_id'] ? [(int)$nextStep['specific_user_id']] : [];
        } elseif ($nextStep['approver_type'] === 'REQUESTER_SELECTED') {
            $approverUserIds = [(int)$nextStep['specific_user_id']];
        }

        if (!$approverUserIds) {
            throw new RuntimeException("No designated approver is configured for workflow route {$workflowRoute}, step {$nextStepNum}.");
        }

        // Create one pending row per approver; any one approver's decision resolves the step.
        $insApp = $pdo->prepare("
            INSERT INTO ticket_approvals (
                ticket_id, step_number, approver_user_id, approval_token, created_at, status
            ) VALUES (
                :ticket_id, :step_number, :approver_id, :token, NOW(), 'Pending'
            )
        ");
        foreach (array_unique($approverUserIds) as $approverUserId) {
            // Generates 32 secure bytes -> converted into a 43-character URL-safe Base64 token
            $approvalToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $insApp->execute([
                'ticket_id'   => $ticketId,
                'step_number' => $nextStepNum,
                'approver_id' => $approverUserId,
                'token'       => $approvalToken
            ]);
        }

        // Update status on tickets table using status_label from workflow_steps
        $upTicket = $pdo->prepare("UPDATE tickets SET status = :status_label WHERE ticket_id = :id");
        $upTicket->execute([
            'status_label' => $nextStep['status_label'],
            'id'           => $ticketId
        ]);

    } else {
        // All workflow steps completed -> Update overall ticket record
        $upTicket = $pdo->prepare("UPDATE tickets SET status = 'Approved & Awaiting Assignment' WHERE ticket_id = :id");
        $upTicket->execute(['id' => $ticketId]);

        // Update final step in ticket_approvals table to 'Approved & Awaiting Assignment'
        $upApproval = $pdo->prepare("
            UPDATE ticket_approvals 
            SET status = 'Approved & Awaiting Assignment'
            WHERE ticket_id = :id 
              AND step_number = (
                  SELECT max_step FROM (
                      SELECT MAX(step_number) AS max_step 
                      FROM ticket_approvals 
                      WHERE ticket_id = :id
                  ) AS sub
              )
              AND status = 'Approved'
        ");
        $upApproval->execute(['id' => $ticketId]);
    }

    return true;
}