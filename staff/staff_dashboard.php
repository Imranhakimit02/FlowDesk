<?php
/**
 * File: staff/staff_dashboard.php
 * Purpose: Provide the protected landing page for verified staff users.
 * Author: Imran IT Intern
 */

// -----------------------------------------------------------------------------------------
// Database connection using db.php file.
// creates a new session or resumes an existing one based on a unique session identifier.
// -----------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/config/db.php';
session_start();

// ------------------------------------------------------------
// Handle staff email verification and session management
// ------------------------------------------------------------
$verifiedEmail = $_SESSION['staff_verified_email'] ?? '';

if (!filter_var($verifiedEmail, FILTER_VALIDATE_EMAIL)) {
    header('Location: staff_login.php');
    exit;
}

// -----------------------------------------------------------------------------------
// Fetch staff user info (SELECT query) by email and ensure they are a staff member
// -----------------------------------------------------------------------------------
$stmtStaff = $pdo->prepare("
    SELECT u.user_id, u.department_id, u.display_name, u.fullname, u.role
    FROM users u
    WHERE u.email = :email AND u.role = 'staff'
    LIMIT 1
");
$stmtStaff->execute(['email' => $verifiedEmail]);
$currentStaff = $stmtStaff->fetch(PDO::FETCH_ASSOC);

if (!$currentStaff) {
    // If no staff user found, redirect to login page
    die('Error: Staff account not found.');
}

$staff_user_id = $currentStaff['user_id'];

// ----------------------------------------------
// Process staff ticket updates (POST request)
// ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket_id   = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
    $action_type = $_POST['action_type'] ?? '';
    $comments    = isset($_POST['comments']) ? trim($_POST['comments']) : '';

    $validActions = ['Completed', 'Request for Reassignment'];

    // Both staff actions must include an explanatory comment.
    $commentRequired = in_array($action_type, $validActions, true);

    if ($ticket_id && in_array($action_type, $validActions)) {
        if ($commentRequired && empty($comments)) {
            $errorMsg = 'A log comment is required for "Completed" and "Request for Reassignment".';
        } else {
            try {
                $pdo->beginTransaction();

                // Unassign staff automatically, if reassignment requested so it routes back to HOD
                if ($action_type === 'Request for Reassignment') {
                    $stmtUpdateTicket = $pdo->prepare("
                        UPDATE tickets 
                        SET status = 'Request for Reassignment', assigned_staff_id = NULL 
                        WHERE ticket_id = :ticket_id AND assigned_staff_id = :staff_id
                    ");
                    $stmtUpdateTicket->execute([
                        'ticket_id' => $ticket_id,
                        'staff_id'  => $staff_user_id
                    ]);

                } else {
                    $stmtUpdateTicket = $pdo->prepare("
                        UPDATE tickets
                        SET status = :status 
                        WHERE ticket_id = :ticket_id AND assigned_staff_id = :staff_id
                    ");
                    $stmtUpdateTicket->execute([
                        'status'    => $action_type,
                        'ticket_id' => $ticket_id,
                        'staff_id'  => $staff_user_id
                    ]);
                }

                // Log the required comment alongside every allowed staff action.
                $stmtInsertLog = $pdo->prepare("
                    INSERT INTO ticket_logs (
                        ticket_id, staff_id, action_type, comments, logged_at
                    ) VALUES (
                        :ticket_id, :staff_id, :action_type, :comments, NOW()
                    )
                ");

                $stmtInsertLog->execute([
                    'ticket_id'   => $ticket_id,
                    'staff_id'    => $staff_user_id,
                    'action_type' => $action_type,
                    'comments'    => $comments
                ]);

                $pdo->commit();
                header('Location: staff_dashboard.php?msg=updated');
                exit;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMsg = 'Database Error: ' . $e->getMessage();
            }
        }
    } else {
        $errorMsg = 'Please select a valid action.';
    }
}

// ----------------------------------------------
// Fetch assigned tickets for the current staff
// ----------------------------------------------
$stmtAssigned = $pdo->prepare("
    SELECT DISTINCT t.ticket_id, t.token, t.remarks AS ticket_remarks, t.meta_data, t.created_at, t.status, t.attachment_file, t.assigned_staff_id,
           u.fullname AS requester_name, u.email AS requester_email,
           rt.type_name, d.department_name AS target_dept
    FROM tickets t
    JOIN users u ON t.requester_id = u.user_id
    JOIN request_types rt ON t.type_id = rt.type_id
    LEFT JOIN departments d ON d.department_id = CASE
        WHEN EXISTS (SELECT 1 FROM ticket_worksteps cws WHERE cws.ticket_id = t.ticket_id)
        THEN (SELECT final_user.department_id
              FROM ticket_approvals final_approval
              JOIN users final_user ON final_user.user_id = final_approval.approver_user_id
              WHERE final_approval.ticket_id = t.ticket_id
                AND final_approval.status = 'Approved'
              ORDER BY final_approval.step_number DESC LIMIT 1)
        ELSE rt.target_department_id END
    LEFT JOIN ticket_logs tl ON t.ticket_id = tl.ticket_id
    WHERE t.assigned_staff_id = :staff_id
        OR (tl.staff_id = :staff_id AND tl.action_type = 'Request for Reassignment')
    ORDER BY t.ticket_id DESC
");
$stmtAssigned->execute(['staff_id' => $staff_user_id]);
$assignedTickets = $stmtAssigned->fetchAll(PDO::FETCH_ASSOC);

// Fetch all activity logs for assigned tickets
$assignedTicketIds = array_column($assignedTickets, 'ticket_id');
$ticketLogs = [];

if (!empty($assignedTicketIds)) {
    $inClause = implode(',', array_map('intval', $assignedTicketIds));
    $stmtLogs = $pdo->query("
        SELECT tl.*, u.fullname AS staff_name
        FROM ticket_logs tl
        JOIN users u ON tl.staff_id = u.user_id
        WHERE tl.ticket_id IN ($inClause)
        ORDER BY tl.logged_at DESC
    ");
    
    while ($log = $stmtLogs->fetch(PDO::FETCH_ASSOC)) {
        $ticketLogs[$log['ticket_id']][] = $log;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - Staff Dashboard</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

    <!-- Staff Operational Header -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-secondary shadow-sm mb-4">
        <div class="container">
            <a class="navbar-brand fw-bold text-uppercase" href="#">
                <span class="badge bg-info text-dark me-2">STAFF</span> Flowdesk Operational Portal
            </a>
            <div class="d-flex align-items-center gap-3">
                <span class="text-white-50 small">Signed in as: <strong class="text-white"><?= htmlspecialchars($currentStaff['fullname']) ?></strong> (<?= htmlspecialchars($verifiedEmail) ?>)</span>
                <a href="staff_login.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container my-4">

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
            <div id="success-alert" class="alert alert-success alert-dismissible fade show timed-alert" role="alert">
                <strong>Success!</strong> Ticket status updated and logged successfully.
            </div>
        <?php endif; ?>

        <?php if (isset($errorMsg)): ?>
            <div id="error-alert" class="alert alert-danger alert-dismissible fade show timed-alert" role="alert">
                <strong>Notice! </strong> <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <script>
        // The text will be disappeared after 5 seconds
        setTimeout(function() {
            var alerts = document.getElementsByClassName('timed-alert');
                for (var i = 0; i < alerts.length; i++) {
                    alerts[i].style.display = 'none';
                }
            }, 5000);
        </script>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                <h3 class="h5 mb-0 fw-bold">My Assigned Tickets</h3>
                <span class="badge bg-info text-dark"><?= count($assignedTickets) ?> Assigned</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th>Ticket ID &amp; Date</th>
                                <th>Requester</th>
                                <th>Category</th>
                                <th>Requester's Remarks</th>
                                <th>Request Details</th>
                                <th>Current Status</th>
                                <th style="min-width: 250px;">Update Status</th>
                                <th style="min-width: 200px;">Activity History</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($assignedTickets)): ?>
                                <?php foreach ($assignedTickets as $ticket): ?>
                                    <?php 
                                        // Decode JSON string to PHP Array
                                        $metaData = !empty($ticket['meta_data']) ? json_decode($ticket['meta_data'], true) : [];
                                    ?>
                                    <tr>
                                        <td>
                                            <strong>Ticket ID: </strong>#<?= htmlspecialchars($ticket['ticket_id']) ?><br>
                                            <strong>Date &amp; Time: </strong><br><small class="text-muted"><?= htmlspecialchars($ticket['created_at']) ?></small>
                                            <?php if (!empty($ticket['attachment_file'])): ?>
                                                <br><a href="../<?= htmlspecialchars(ltrim($ticket['attachment_file'], '/'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-link p-0">View Attachment</a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong>Name: </strong><?= htmlspecialchars($ticket['requester_name']) ?><br>
                                            <small class="text-muted"><strong>Email: </strong><?= htmlspecialchars($ticket['requester_email']) ?></small>
                                        </td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($ticket['type_name']) ?></span></td>
                                        <td><?= htmlspecialchars($ticket['ticket_remarks']) ?></td>
                                        
                                        <!-- Metadata JSON Display Cell -->
                                        <td>
                                            <?php if (!empty($metaData) && is_array($metaData)): ?>
                                                <ul class="list-unstyled small mb-0 bg-light p-2 rounded border">
                                                    <?php foreach ($metaData as $key => $value): ?>
                                                        <?php if (in_array($key, ['submission_ip', 'submitted_at'])) continue; ?>
                                                        <li>
                                                            <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?>:</strong> 
                                                            <?= htmlspecialchars($value) ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <span class="text-muted small">None</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php 
                                                $status = strtolower($ticket['status']);
                                                $badgeClass = 'bg-secondary';
                                                if ($status === 'completed') {
                                                    $badgeClass = 'bg-success';
                                                } elseif (str_contains($status, 'reassignment')) {
                                                    $badgeClass = 'bg-warning text-dark';
                                                } else {
                                                    $badgeClass = 'bg-info text-dark';
                                                }
                                            ?>
                                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($ticket['status']) ?></span>
                                        </td>

                                        <td>
                                            <?php 
                                                $isCurrentlyAssignedToMe = ($ticket['assigned_staff_id'] == $staff_user_id);
                                                $canUpdate = $isCurrentlyAssignedToMe && !in_array($ticket['status'], ['Completed', 'Ticket Cancelled', 'Request for Reassignment']);
                                            ?>

                                            <?php if ($canUpdate): ?>
                                                <form method="POST" action="staff_dashboard.php">
                                                    <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($ticket['ticket_id']) ?>">
                                                    
                                                    <div class="mb-2">
                                                        <label for="action_<?= $ticket['ticket_id'] ?>" class="form-label small fw-bold">Action:</label>
                                                        <select name="action_type" id="action_<?= $ticket['ticket_id'] ?>" class="form-select form-select-sm" required>
                                                            <option value="">-- Select Status --</option>
                                                            <option value="Completed">Completed</option>
                                                            <option value="Request for Reassignment">Request for Reassignment</option>
                                                        </select>
                                                    </div>

                                                    <div class="mb-2">
                                                        <label for="comm_<?= $ticket['ticket_id'] ?>" class="form-label small fw-bold">Log Comments: <span class="text-danger">*</span></label>
                                                        <textarea name="comments" id="comm_<?= $ticket['ticket_id'] ?>" rows="2" class="form-control form-control-sm" placeholder="Explain the completion or reassignment request..." required></textarea>
                                                    </div>

                                                    <button type="submit" class="btn btn-primary btn-sm w-100">Update Ticket</button>
                                                </form>
                                            <?php else: ?>
                                                <em class="text-muted small">No further actions available.</em>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if (!empty($ticketLogs[$ticket['ticket_id']])): ?>
                                                <ul class="ps-3 mb-0 small">
                                                    <?php foreach ($ticketLogs[$ticket['ticket_id']] as $log): ?>
                                                        <li class="mb-1">
                                                            <strong>[<?= htmlspecialchars($log['action_type']) ?>]</strong> &mdash; 
                                                            <?= htmlspecialchars($log['comments']) ?> 
                                                            <br><span class="text-muted">Action taken on <?= htmlspecialchars($log['logged_at']) ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <span class="text-muted small">No activity logged yet.</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-3">No tickets currently assigned to you.</td>
                                </tr>
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