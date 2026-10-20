<?php
/**
 * File: hod/hod_login.php
 * Purpose: Display the HOD login page with email verification functionality.
 * Author: Imran IT Intern
 *
 */

// -----------------------------------------------------------------------------------------
// Database connection using db.php file.
// creates a new session or resumes an existing one based on a unique session identifier.
// -----------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/config/db.php';
session_start();

// -----------------------------
// Search (user) text field
// -----------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$users = [];

// -----------------------------------------------------------
// GET from users table with JOIN of other table in database
// -----------------------------------------------------------
if (!empty($search)) {
    // Search strictly by email
    if (filter_var($search, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.display_name, u.fullname, u.email, u.department_id,
                   d.department_name,
                   a.approver_id
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.department_id
            LEFT JOIN approvers a ON u.user_id = a.approver_user_id
            WHERE u.email = :search
              AND (u.role = 'hod' OR a.approver_id IS NOT NULL)
        ");
        $stmt->execute(['search' => $search]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        unset($_SESSION['hod_verified_email']);

        if (!empty($users)) {
            $_SESSION['hod_login_email'] = $users[0]['email'];
        } else {
            unset($_SESSION['hod_login_email']);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - HOD Login</title>
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
            <span class="navbar-text text-white-50 big">HOD Workspace</span>
        </div>
    </nav>

    <div class="container my-5" style="max-width: 900px;">
        
        <h2 class="mb-4">Flowdesk HOD - Login</h2>

        <!-- Search Form -->
        <div class="card shadow-sm p-4 mb-4 border-0">
            <form method="GET" action="hod_login.php" class="row g-2 align-items-center">
                <div class="col-md-8">
                    <input type="email" name="search" class="form-control" placeholder="Please enter your email (e.g: abc123@company.com)..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark">Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="hod_login.php" class="btn btn-outline-secondary ms-1">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- User Search Results Table -->
        <div class="card shadow-sm mb-4 border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Full Name</th>
                                <th>Department</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($search) && count($users) > 0): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><strong>#<?= htmlspecialchars($user['user_id']) ?></strong></td>
                                        <td><span class="badge bg-primary"><?= htmlspecialchars($user['display_name']) ?></span></td>
                                        <td><?= htmlspecialchars($user['fullname']) ?></td>
                                        <td><?= htmlspecialchars($user['department_name'] ?? 'User') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php elseif (!empty($search) && count($users) === 0): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">No user found with that email.</td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">No account found...</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Confirmation Button for Login -->
        <?php if (!empty($users)): ?>
            <div class="card shadow-sm p-4 border-0 bg-white">
                <h4 class="h5 mb-3 fw-bold">Confirm Login?</h4>
                <form action="hod_email_verification.php" method="POST">
                    <button type="submit" class="btn btn-success fw-bold px-4">Proceed to Email Verification</button>
                </form>
            </div>
        <?php endif; ?>

    </div>

    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>