<?php
/**
 * File: track_ticket.php
 * Purpose: The entry point for tracking user ticket history in the Flowdesk system.
 * Author: Imran IT Intern
 */

// --------------------
// Database connection
// --------------------
require_once __DIR__ . '/config/db.php';

// --------------------------
// Search (user) text field
// --------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$users   = [];
$tickets = [];

// --------------------------------------------------------------------------------
// GET from table in database
// Fetch user details and ticket history by email
// --------------------------------------------------------------------------------
if (!empty($search) && filter_var($search, FILTER_VALIDATE_EMAIL)) {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.display_name, u.fullname, u.email, u.department_id,
               d.department_name,
               c.company_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.department_id
        LEFT JOIN companies c ON d.company_id = c.company_id
        WHERE u.email = :search
    ");
    $stmt->execute(['search' => $search]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch tickets for this email
    $stmtTickets = $pdo->prepare("
        SELECT t.ticket_id, t.type_id, t.remarks, t.created_at, t.status, t.hod_remarks, t.assigned_staff_id,
               rt.type_name
        FROM tickets t
        JOIN users u ON t.requester_id = u.user_id
        LEFT JOIN request_types rt ON t.type_id = rt.type_id
        WHERE u.email = :search
        ORDER BY t.ticket_id DESC
    ");
    $stmtTickets->execute(['search' => $search]);
    $tickets = $stmtTickets->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - Ticket Tracking</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .navbar-slate {
            background-color: #2c3e50;
        }
        .bg-slate-header {
            background-color: #34495e;
            color: #ffffff;
        }
        .btn-slate {
            background-color: #34495e;
            color: #ffffff;
            border: none;
        }
        .btn-slate:hover {
            background-color: #2c3e50;
            color: #ffffff;
        }
        .badge-slate {
            background-color: #e2e8f0;
            color: #334155;
        }
    </style>
</head>
<body class="bg-light">

    <!-- Professional Slate Navigation Header -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-slate shadow-sm mb-4">
        <div class="container">
            <a class="navbar-brand fw-bold text-uppercase d-flex align-items-center" href="track_ticket.php">
                <span class="badge badge-slate me-2">FLOWDESK</span> Status Tracking Portal
            </a>
            <div class="d-flex align-items-center">
                <a href="index.php" class="btn btn-outline-light btn-sm fw-bold">Submit Request Ticket</a>
            </div>
        </div>
    </nav>

    <div class="container my-4" style="max-width: 900px;">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h3 fw-bold mb-0 text-dark">Track Your Request Status</h2>
        </div>

        <!-- Search Form Card -->
        <div class="card shadow-sm border-0 p-4 mb-4">
            <label for="search_email" class="form-label fw-bold text-muted small text-uppercase mb-2">Account Lookup</label>
            <form method="GET" action="track_ticket.php" class="row g-2 align-items-center">
                <div class="col-md-9">
                    <input type="email" id="search_email" name="search" class="form-control" placeholder="Enter your email address (e.g., john.doe@company.com)..." value="<?= htmlspecialchars($search) ?>" required>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-slate fw-bold w-100">Search User</button>
                    <?php if (!empty($search)): ?>
                        <a href="track_ticket.php" class="btn btn-outline-secondary">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Account Not Found Alert (Visible only when search executed but yields no match) -->
        <?php if (!empty($search) && empty($users)): ?>
            <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
                <div>
                    <strong>No account or ticket record found.</strong> Please verify your email address or submit a new ticket request.
                </div>
            </div>
        <?php endif; ?>

        <!-- User Details Table (HIDDEN BY DEFAULT; Displays ONLY when user account is found) -->
        <?php if (!empty($search) && !empty($users)): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-slate-header fw-bold">
                    User Details
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle mb-0">
                            <thead class="table-light text-uppercase small text-secondary">
                                <tr>
                                    <th>ID</th>
                                    <th>Display Name</th>
                                    <th>Full Name</th>
                                    <th>Department</th>
                                    <th>Company</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><strong>#<?= htmlspecialchars($user['user_id']) ?></strong></td>
                                        <td><span class="badge badge-slate fw-bold"><?= htmlspecialchars($user['display_name']) ?></span></td>
                                        <td class="fw-bold"><?= htmlspecialchars($user['fullname']) ?></td>
                                        <td><?= htmlspecialchars($user['department_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($user['company_name'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Ticket Request History Table -->
            <div class="card shadow-sm border-0 mb-5">
                <div class="card-header bg-slate-header fw-bold d-flex justify-content-between align-items-center">
                    <span>Ticket Request History</span>
                    <span class="badge bg-light text-dark fw-bold"><?= count($tickets) ?> Tickets</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0">
                            <thead class="table-light text-uppercase small text-secondary">
                                <tr>
                                    <th>Ticket ID</th>
                                    <th>Category</th>
                                    <th>Remarks</th>
                                    <th>Date Created</th>
                                    <th>Status</th>
                                    <th>HOD Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($tickets)): ?>
                                    <?php foreach ($tickets as $ticket): ?>
                                        <tr>
                                            <td><strong>#<?= htmlspecialchars($ticket['ticket_id']) ?></strong></td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($ticket['type_name'] ?? $ticket['type_id']) ?></span></td>
                                            <td><?= htmlspecialchars($ticket['remarks']) ?></td>
                                            <td><small class="text-muted"><?= htmlspecialchars($ticket['created_at']) ?></small></td>
                                            <td>
                                                <?php 
                                                    $statusLower = strtolower($ticket['status']);
                                                    $badgeClass = 'bg-secondary';
                                                    if (str_contains($statusLower, 'approved') || $statusLower === 'completed') {
                                                        $badgeClass = 'bg-success';
                                                    } elseif (str_contains($statusLower, 'rejected') || str_contains($statusLower, 'cancelled')) {
                                                        $badgeClass = 'bg-danger';
                                                    } elseif (str_contains($statusLower, 'pending')) {
                                                        $badgeClass = 'bg-warning text-dark';
                                                    }
                                                ?>
                                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($ticket['status']) ?></span>
                                            </td>
                                            <td><small class="text-muted"><?= htmlspecialchars($ticket['hod_remarks'] ?? '-') ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">No ticket history found for this email address.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>