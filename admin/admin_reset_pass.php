<?php
/**
 * File: admin/admin_reset_pass.php
 * Purpose: Securely update the signed-in administrator's username and password.
 * Author: Imran IT Intern
 */

// ------------------------------------------------------
// SECTION 1: DATABASE CONNECTION & SESSION MANAGEMENT
// ------------------------------------------------------
require_once dirname(__DIR__) . '/config/db.php';

// Enforce strict session management to prevent session adoption/fixation attacks
ini_set('session.use_strict_mode', '1');

// Configure secure HTTP cookie parameters for session persistence
session_set_cookie_params([
    'httponly' => true,
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax'
]);
session_start();

// ------------------------------------------------------
// SECTION 2: AUTHENTICATION & ACCESS CONTROL HANDLERS
// ------------------------------------------------------
if (empty($_SESSION['admin_username'])) {
    header('Location: admin_login.php');
    exit();
}

// Ensure an anti-CSRF token exists for the active administrator session
if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}

// Handle explicit admin logout requests.
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin_username'], $_SESSION['admin_id'], $_SESSION['admin_user_id']);
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

// ------------------------------------------------
// SECTION 3: FETCH ACTIVE ADMINISTRATOR PROFILE
// ------------------------------------------------
$adminId = filter_var($_SESSION['admin_id'] ?? null, FILTER_VALIDATE_INT);
if ($adminId) {
    $adminStmt = $pdo->prepare('SELECT admin_id, username, password FROM admin WHERE admin_id = :admin_id LIMIT 1');
    $adminStmt->execute(['admin_id' => $adminId]);
} else {
    $adminStmt = $pdo->prepare('SELECT admin_id, username, password FROM admin WHERE username = :username LIMIT 1');
    $adminStmt->execute(['username' => $_SESSION['admin_username']]);
}
$currentAdmin = $adminStmt->fetch(PDO::FETCH_ASSOC);

if (!$currentAdmin) {
    session_unset();
    session_destroy();
    header('Location: admin_login.php');
    exit();
}

$_SESSION['admin_id']       = (int)$currentAdmin['admin_id'];
$_SESSION['admin_username'] = $currentAdmin['username'];

$error   = '';
$message = '';

// ------------------------------------------------
// SECTION 4: FORM SUBMISSION PROCESSING (POST)
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken      = $_POST['csrf_token'] ?? '';
    $currentPassword  = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $newUsername     = is_string($_POST['new_username'] ?? null) ? trim($_POST['new_username']) : '';
    $newPassword     = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if (!is_string($postedToken) || !hash_equals($_SESSION['admin_csrf_token'], $postedToken)) {
        $error = 'Your security token expired. Refresh the page and try again.';
    } elseif ($currentPassword === '' || !passwordMatchesStoredValue($currentPassword, $currentAdmin['password'])) {
        $error = 'Your current password is incorrect.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $newUsername)) {
        $error = 'Username must be 3–50 characters and use only letters, numbers, dots, underscores, or hyphens.';
    } elseif ($newPassword !== '' && strlen($newPassword) < 12) {
        $error = 'A new password must be at least 12 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new password and confirmation do not match.';
    } elseif ($newPassword !== '' && passwordMatchesStoredValue($newPassword, $currentAdmin['password'])) {
        $error = 'Choose a password different from your current password.';
    } else {
        try {
            $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM admin WHERE username = :username AND admin_id <> :admin_id');
            $duplicateStmt->execute([
                'username' => $newUsername,
                'admin_id' => $currentAdmin['admin_id']
            ]);

            if ((int)$duplicateStmt->fetchColumn() > 0) {
                $error = 'That username is already in use.';
            } else {
                $passwordHash = $newPassword !== ''
                    ? password_hash($newPassword, PASSWORD_DEFAULT)
                    : (password_get_info($currentAdmin['password'])['algo'] !== null
                        ? $currentAdmin['password']
                        : password_hash($currentPassword, PASSWORD_DEFAULT));

                $updateStmt = $pdo->prepare('UPDATE admin SET username = :username, password = :password WHERE admin_id = :admin_id');
                $updateStmt->execute([
                    'username' => $newUsername,
                    'password' => $passwordHash,
                    'admin_id' => $currentAdmin['admin_id']
                ]);

                session_regenerate_id(true);
                $_SESSION['admin_username']   = $newUsername;
                $_SESSION['admin_id']         = (int)$currentAdmin['admin_id'];
                $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
                
                $currentAdmin['username'] = $newUsername;
                $currentAdmin['password'] = $passwordHash;
                
                $message = 'Your administrator credentials have been updated.';
            }
        } catch (PDOException $e) {
            $error = 'Unable to update your credentials. Please try again.';
        }
    }
}

/**
 * Validates submitted plaintext password against stored database value.
 */
function passwordMatchesStoredValue(string $submitted, string $stored): bool
{
    $info = password_get_info($stored);
    if ($info['algo'] !== null) {
        return password_verify($submitted, $stored);
    }
    
    if (preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
        return hash_equals(strtolower($stored), md5($submitted));
    }
    
    return hash_equals($stored, $submitted);
}

// Fetch dynamic table list for command center jump select
$allTables = [];
try {
    $allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $allTables = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - Account Security</title>
    <!-- Bootstrap 5 CSS CDN & Custom Google Fonts -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --sidebar-width: 260px;
            --bg-body: #0b0f19;
            --bg-card: #111827;
            --border-color: #1f2937;
            --text-white: #ffffff;
            --accent-blue: #3b82f6;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-white);
            min-height: 100vh;
        }

        body, p, span, div, h1, h2, h3, h4, h5, h6, label, td, th, input, select, textarea, a {
            color: #ffffff !important;
        }

        ::placeholder {
            color: #94a3b8 !important;
            opacity: 1 !important;
        }

        .sidebar-nav::-webkit-scrollbar { width: 6px; }
        .sidebar-nav::-webkit-scrollbar-track { background: #0d1322; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: #1f2937; border-radius: 3px; }

        .app-sidebar {
            width: var(--sidebar-width);
            background-color: #0d1322;
            border-right: 1px solid var(--border-color);
            position: fixed;
            top: 0; bottom: 0; left: 0;
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            padding: 1.5rem;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-nav { padding: 1rem 0.75rem; flex-grow: 1; overflow-y: auto; }
        .nav-section-title { font-size: 0.7rem; text-transform: uppercase; color: #ffffff !important; font-weight: 700; padding: 0.85rem 0.75rem 0.35rem; letter-spacing: 0.05em; opacity: 0.8; }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.6rem 0.75rem;
            color: #ffffff !important;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .sidebar-link:hover, .sidebar-link.active {
            background-color: rgba(59, 130, 246, 0.25);
            color: #ffffff !important;
        }

        .sidebar-link.active { border-left: 3px solid var(--accent-blue); }

        .app-main { margin-left: var(--sidebar-width); padding: 2rem; }
        .glass-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; }

        .btn-action-primary { background-color: var(--accent-blue); color: #fff !important; border: none; font-weight: 600; }
        .btn-action-primary:hover { background-color: #2563eb; }

        .error-panel {
            background-color: #2a1215;
            border: 1px solid #f87171;
            color: #fecaca !important;
        }
        .error-panel, .error-panel strong, .error-panel pre {
            color: #fecaca !important;
        }
    </style>
</head>
<body>

    <!-- ---------------------------------------- -->
    <!-- COMPONENT: LEFT SIDEBAR NAVIGATION BAR   -->
    <!-- ---------------------------------------- -->
    <aside class="app-sidebar">
        <div class="sidebar-brand d-flex align-items-center gap-2">
            <span class="badge bg-danger fs-6">ADMIN</span> FLOWDESK
        </div>

        <div class="sidebar-nav">
            <a href="admin_dashboard.php" class="sidebar-link">
                ⚡ Dashboard Overview
            </a>

            <div class="nav-section-title">Workflow & Schemas</div>
            <a href="workflow_steps_manager.php" class="sidebar-link">🔀 Workflow Steps Builder</a>
            <a href="request_type_manager.php" class="sidebar-link">📋 Request Form Builder</a>
            <a href="admin_dashboard.php?table=workflow_steps" class="sidebar-link">🪜 Workflow Route Table</a>
            <a href="admin_dashboard.php?table=request_types" class="sidebar-link">📂 Request Categories Directory</a>

            <div class="nav-section-title">Directory Management</div>
            <a href="admin_dashboard.php?table=users" class="sidebar-link">👥 User Accounts</a>
            <a href="admin_dashboard.php?table=departments" class="sidebar-link">🏢 Department Entities</a>
            <a href="admin_dashboard.php?table=companies" class="sidebar-link">🏛️ Company Entities</a>
            <a href="admin_dashboard.php?table=approvers" class="sidebar-link">🛡️ Approver Lists</a>

            <div class="nav-section-title">Audit & Logs</div>
            <a href="admin_dashboard.php?table=tickets" class="sidebar-link">🎟️ Requester Ticket History</a>
            <a href="admin_dashboard.php?table=ticket_approvals" class="sidebar-link">✅ HOD Approval History</a>
            <a href="admin_dashboard.php?table=ticket_logs" class="sidebar-link">📜 Staff Activity Logs</a>
        </div>

        <div class="p-3 border-top border-secondary">
            <div class="small mb-2">User: <strong><?= htmlspecialchars($_SESSION['admin_username']); ?></strong></div>
            <a href="admin_reset_pass.php" class="btn btn-outline-light btn-sm w-100 mb-2 fw-semibold">Account Setting</a>
            <a href="admin_dashboard.php?action=logout" class="btn btn-danger btn-sm w-100 fw-semibold">Logout</a>
        </div>
    </aside>

    <!-- ----------------------------------------- -->
    <!-- COMPONENT: MAIN WORKSPACE CONTAINER       -->
    <!-- ----------------------------------------- -->
    <main class="app-main">

        <!-- Top Header Controls & Direct Database Jump Selector -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">Account Security Settings</h4>
                <p class="small mb-0">Update administrator credentials and system access passwords</p>
                <br>
                <a href="admin_dashboard.php" class="btn btn-outline-secondary btn-sm fw-semibold">&larr; Back to Dashboard</a>
            </div>
            
            <form method="GET" action="admin_dashboard.php" class="d-flex align-items-center gap-2">
                <select name="table" class="form-select form-select-sm bg-dark border-secondary" onchange="this.form.submit()">
                    <option value="">-- Select Database Table --</option>
                    <?php foreach ($allTables as $table): ?>
                        <option value="<?= htmlspecialchars($table) ?>"><?= htmlspecialchars($table) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <!-- System Response Feedback Banners -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-success bg-dark text-success border-success alert-dismissible fade show mb-4" role="alert">
                <strong>Success:</strong> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert error-panel alert-dismissible fade show mb-4" role="alert" aria-live="assertive">
                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="glass-card p-4 mx-auto" style="max-width: 650px;">
            <p class="small text-secondary mb-4">Update credentials for account <strong><?= htmlspecialchars($currentAdmin['username']) ?></strong>. Re-enter your current password to authorize changes.</p>

            <form method="POST" action="admin_reset_pass.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_csrf_token']) ?>">

                <div class="mb-3">
                    <label for="new_username" class="form-label small fw-bold">Administrator Username <span class="text-danger">*</span></label>
                    <input type="text" id="new_username" name="new_username" class="form-control bg-dark border-secondary" value="<?= htmlspecialchars($currentAdmin['username']) ?>" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]{3,50}" autocomplete="username" required>
                </div>

                <div class="mb-3">
                    <label for="new_password" class="form-label small fw-bold">New Password <span class="text-secondary fw-normal">(leave blank to keep current)</span></label>
                    <input type="password" id="new_password" name="new_password" class="form-control bg-dark border-secondary" minlength="12" autocomplete="new-password">
                </div>

                <div class="mb-3">
                    <label for="confirm_password" class="form-label small fw-bold">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control bg-dark border-secondary" minlength="12" autocomplete="new-password">
                </div>

                <div class="mb-4">
                    <label for="current_password" class="form-label small fw-bold">Current Authorization Password <span class="text-danger">*</span></label>
                    <input type="password" id="current_password" name="current_password" class="form-control bg-dark border-secondary" autocomplete="current-password" required>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-action-primary btn-sm px-4 fw-bold">Save Account Settings</button>
                    <a href="admin_dashboard.php" class="btn btn-outline-secondary btn-sm fw-semibold">Cancel</a>
                </div>
            </form>
        </div>

    </main>

    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>