<?php
/**
 * File: admin/admin_login.php
 * Purpose: Display the Admin login page with username and password authentication.
 * Author: Imran IT Intern
 */

// -----------------------------------------------------------------------------------------
// Database connection using db.php file.
// Creates a new session or resumes an existing one based on a unique session identifier.
// -----------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/config/db.php';
session_start();

// -----------------------------------------------------------------------------------------
// FIXED: If already logged in, send to dashboard. Otherwise, stay on this page!
// -----------------------------------------------------------------------------------------
if (!empty($_SESSION['admin_user_id'])) {
    header('Location: admin_dashboard.php');
    exit;
}

$errorMsg = '';

// -----------------------------------------------------------
// Process Admin Username & Password Authentication
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("
                SELECT admin_id, username, password 
                FROM admin
                WHERE username = :username 
                LIMIT 1
            ");
            $stmt->execute(['username' => $username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            $admin = false;
        }

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id'] = (int)$admin['admin_id'];
            $_SESSION['admin_user_id'] = (int)$admin['admin_id'];
            $_SESSION['admin_username'] = $admin['username'];
            header('Location: admin_dashboard.php');
            exit;
        } elseif ($errorMsg === '') {
            $errorMsg = 'Login failed for username ' . $username . '.';
        }
    } else {
        $errorMsg = 'Login request was missing username or password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - System Administrator Login</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

    <!-- Admin Top Navbar -->
    <nav class="navbar navbar-dark bg-dark shadow-sm mb-5">
        <div class="container">
            <a class="navbar-brand fw-bold text-uppercase" href="#">
                <span class="badge bg-danger me-2">ADMIN</span> Process Flow Control Center
            </a>
            <span class="navbar-text text-white-50 small">System Administration Access</span>
        </div>
    </nav>

    <!-- Main Login Card Container -->
    <div class="container my-5" style="max-width: 450px;">
        <div class="card shadow border-0">
            <div class="card-header bg-dark text-white p-3 text-center">
                <h4 class="h5 mb-0 fw-bold">Administrator Login</h4>
            </div>
            <div class="card-body p-4">

                <?php if (!empty($errorMsg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Error</strong>
                        <pre class="mb-0 mt-2 small"><?= htmlspecialchars($errorMsg) ?></pre>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="admin_login.php">
                    <div class="mb-3">
                        <label for="username" class="form-label fw-bold small">Admin Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="username" class="form-control" placeholder="Enter username..." required autofocus>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label fw-bold small">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Enter password..." required>
                    </div>

                    <button type="submit" class="btn btn-dark w-100 fw-bold py-2">Login to Control Center</button>
                </form>

            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>