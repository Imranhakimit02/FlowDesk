<?php
/**
 * File: user_email_verification.php
 * Purpose: Handle email verification for ticket requests.
 * Author: Imran IT Intern
 *
 * This file processes email verification requests 
 * and updates the status of tickets accordingly.
 */

// ----------------------------------------
// Database connection & Workflow engine
// ----------------------------------------
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/workflow_engine.php';

// ------------------------------------------------------------
// Process Form Submissions (POST Action)
// Update ticket status based on user confirmation (Yes/No)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket_id = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
    $email     = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $action    = $_POST['action'] ?? '';

    if ($ticket_id && $email && in_array($action, ['yes', 'no'])) {
        try {
            if ($action === 'no') {
                // Cancel ticket if user clicks NO
                $stmt = $pdo->prepare("
                    UPDATE tickets 
                    SET status = 'Ticket Cancelled' 
                    WHERE ticket_id = :ticket_id AND status = 'Pending Email Verification'
                ");
                $stmt->execute(['ticket_id' => $ticket_id]);
                $resultStatus = 'cancelled';
            } else {
                // User clicked YES: Trigger dynamic workflow engine processing
                $resultStatus = processTicketWorkflow($pdo, $ticket_id) ? 'confirmed' : 'already_processed';
            }

            header('Location: user_email_verification.php?email=' . urlencode($email) . '&status=' . $resultStatus);
            exit;

        } catch (Exception $e) {
            die('Database Error: ' . htmlspecialchars($e->getMessage()));
        }
    }
}

// ---------------------------------------------------------------------------------
// Fetch Tickets for Display (GET Request)
// Use the email parameter to retrieve tickets pending verification for that user
// ---------------------------------------------------------------------------------
$email   = isset($_GET['email']) ? trim($_GET['email']) : '';
$tickets = [];

if (!empty($email)) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, u.email AS requester_email, rt.type_name
            FROM tickets t
            JOIN users u ON t.requester_id = u.user_id
            JOIN request_types rt ON t.type_id = rt.type_id
            WHERE u.email = :email AND t.status = 'Pending Email Verification'
            ORDER BY t.ticket_id DESC
        ");
        $stmt->execute(['email' => $email]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die('Error loading tickets: ' . htmlspecialchars($e->getMessage()));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - Email Verification</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

    <div class="container my-5">
        
        <h2 class="mb-4">Flowdesk Email Verification</h2>

        <div class="card shadow-sm p-4 mb-4">
            <p class="text-muted mb-0">Confirm or cancel your pending request below. Email notifications are disabled in this testing environment.</p>
            <?php if (!empty($email)): ?>
                <p class="text-secondary small mt-2 mb-0">Pending request confirmations for <strong><?= htmlspecialchars($email) ?></strong> are listed below.</p>
            <?php endif; ?>
        </div>

        <!-- Alert Banner After User Action -->
        <?php if (isset($_GET['status'])): ?>
            <?php if ($_GET['status'] === 'confirmed'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <strong>Success!</strong> Your ticket request has been confirmed.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php elseif ($_GET['status'] === 'cancelled'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    Your ticket request has been cancelled.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php elseif ($_GET['status'] === 'already_processed'): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    This ticket has already been verified or processed.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Pending Tickets Table -->
        <div class="card shadow-sm mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Email</th>
                                <th>Request Details</th>
                                <th style="width: 180px;">Confirm Request</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($tickets)): ?>
                                <?php foreach ($tickets as $ticket): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($ticket['requester_email']) ?></td>
                                        <td>
                                            <strong>Type:</strong> <?= htmlspecialchars($ticket['type_name']) ?><br>
                                            <strong>Remarks:</strong> <?= htmlspecialchars($ticket['remarks']) ?><br>
                                            <strong>Status:</strong> <span class="badge bg-warning text-dark"><?= htmlspecialchars($ticket['status']) ?></span>
                                        </td>
                                        <td>
                                            <!-- Form submits back to this same file -->
                                            <form method="POST" action="user_email_verification.php" class="d-flex gap-2">
                                                <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($ticket['ticket_id']) ?>">
                                                <input type="hidden" name="email" value="<?= htmlspecialchars($ticket['requester_email']) ?>">
                                                <button type="submit" name="action" value="yes" class="btn btn-success btn-sm fw-bold w-100">Yes</button>
                                                <button type="submit" name="action" value="no" class="btn btn-danger btn-sm fw-bold w-100">No</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-3">No pending tickets found for verification.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <a href="index.php" class="btn btn-secondary">Return to Home Page</a>
        </div>

    </div>

    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>