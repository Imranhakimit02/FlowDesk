<?php
/**
 * File: staff/staff_email_verification.php
 * Purpose: Confirm the Staff email selected on the login page.
 * Author: Imran IT Intern
 */

// -----------------------------------------------------------------------------------------
// Database connection using db.php file.
// creates a new session or resumes an existing one based on a unique session identifier.
// -----------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/config/db.php';
session_start();

// -------------------------------------------------------
// Handle form submission and email verification logic
// -------------------------------------------------------
$message = '';
$messageType = 'error';
$email = $_SESSION['staff_login_email'] ?? '';

// ------------------------------------------------------------------------------------------------------------
// Check if the form is submitted via POST method
// If the user chooses "No", clear the session variables and redirect to the Staff login page
// If the user chooses "Yes", validate the email and check if it exists in the database
// If the email is valid and exists, set the verified email in the session and redirect to the Staff dashboard
// ------------------------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choice = $_POST['choice'] ?? '';

    if ($choice === 'no') {
        unset($_SESSION['staff_login_email'], $_SESSION['staff_verified_email']);
        header('Location: staff_login.php');
        exit;
    }

    if ($choice !== '' && $choice !== 'yes') {
        $message = 'Please choose Yes to continue or No to return to the Staff login page.';
    }

    $email = filter_var($_SESSION['staff_login_email'] ?? '', FILTER_VALIDATE_EMAIL);

    if ($choice === 'yes' && !$email) {
        $message = 'Please return to the Staff login page and enter an email address first.';
    } elseif ($choice === 'yes') {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE email = :email AND role = 'staff' LIMIT 1");
        $stmt->execute(['email' => $email]);

        if (!$stmt->fetch()) {
            $message = 'No Staff account was found for that email address.';
        } else {
            $_SESSION['staff_verified_email'] = $email;
            header('Location: staff_dashboard.php');
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - Staff Email Verification</title>
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
        </div>
    </nav>

    <div class="container my-5" style="max-width: 600px;">
        <div class="card shadow-sm border-0 text-center p-4">
            <h2 class="h4 mb-3">Flowdesk - Staff Email Verification</h2>
            <p class="text-muted mb-3">Confirm that you want to log in with the email entered on the Staff login page:</p>

            <?php if ($email !== ''): ?>
                <div class="alert alert-secondary fs-5 fw-bold text-dark my-3">
                    <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($email !== ''): ?>
                <form method="POST" action="staff_email_verification.php" class="d-flex justify-content-center gap-2 mt-3">
                    <button type="submit" name="choice" value="yes" class="btn btn-success fw-bold px-4">Yes, Continue to Dashboard</button>
                    <button type="submit" name="choice" value="no" class="btn btn-outline-secondary px-4">No, Return to Login</button>
                </form>
            <?php endif; ?>

            <?php if ($message !== ''): ?>
                <div class="alert alert-danger mt-4 mb-0" role="alert">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>