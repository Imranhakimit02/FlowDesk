<?php
/**
 * File: admin/admin_dashboard.php
 * Purpose: Enterprise Admin Command Center featuring database dynamic table management, 
 *          workflow and request type configuration, ticket overview metrics, and users, depts, and comps management.
 * Author: Imran IT Intern
 */

// ------------------------------------------------------
// SECTION 1: DATABASE CONNECTION & SESSION MANAGEMENT
// ------------------------------------------------------
// Require central PDO connection configuration.
// Start or resume an active session to persist admin authentication tokens.
require_once dirname(__DIR__) . '/config/db.php';
session_start();

// Initialize global user alert message variables to store dynamic feedback.
$message = '';
$error   = '';

/**
 * Format exceptions into detailed, user-readable multiline strings for high-visibility UI error handling.
 *
 * @param Throwable $exception The caught exception or error instance.
 * @param string    $action    Context description of the failing process step.
 * @return string   Formatted error message containing action name, SQL state codes (if PDO), and error text.
 */
function dashboardCaughtError(Throwable $exception, string $action): string
{
    // Log exception details into server system logs for backend debugging
    error_log('Flowdesk admin dashboard — ' . $action . ': ' . $exception->getMessage());

    $lines = [$action];
    
    // Inspect PDOException specifics if database query failed
    if ($exception instanceof PDOException) {
        $sqlState   = (string)$exception->getCode();
        $driverCode = (int)($exception->errorInfo[1] ?? 0);
        $sqlStateLine = 'SQLSTATE ' . $sqlState;
        
        if ($driverCode > 0) {
            $sqlStateLine .= ' (MySQL ' . $driverCode . ')';
        }
        $lines[] = $sqlStateLine;
    }
    
    $lines[] = $exception->getMessage();
    return implode("\n", $lines);
}

/**
 * Append error messages together into a single multiline string buffer for UI rendering.
 *
 * @param string|null $error Reference to the primary error message string variable.
 * @param string      $newError The incoming error message string to append.
 */
function dashboardAppendError(?string &$error, string $newError): void
{
    $newError = trim($newError);
    if ($newError === '') {
        return;
    }
    $error = ($error === '' || $error === null) ? $newError : $error . "\n\n" . $newError;
}

// -----------------------------------------------------
// SECTION 2: AUTHENTICATION & ACCESS CONTROL HANDLERS
// -----------------------------------------------------

// Handle explicit admin logout requests.
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // Unset all active admin authentication session variables
    unset($_SESSION['admin_username'], $_SESSION['admin_id'], $_SESSION['admin_user_id']);
    session_destroy();
    
    // Redirect user back to the admin login portal
    header("Location: admin_login.php");
    exit();
}

// Protect command center access: enforce valid admin user session existence.
if (!isset($_SESSION['admin_username'])) {
    header("Location: admin_login.php");
    exit();
}

// Synchronize admin_id session key across legacy/modern auth references.
if (!isset($_SESSION['admin_id']) && isset($_SESSION['admin_user_id'])) {
    $_SESSION['admin_id'] = $_SESSION['admin_user_id'];
}

// -------------------------------------------------------------------------
// SECTION 3: DATABASE SCHEMA DISCOVERY & SLICER PARAMETER INITIALIZATION
// -------------------------------------------------------------------------

// Dynamically discover all active tables in the connected database instance safely.
$allTables = [];
try {
    $tablesStmt = $pdo->query("SHOW TABLES");
    $allTables  = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    dashboardAppendError($error, dashboardCaughtError($e, 'Could not load database tables.'));
}

// Read and sanitize selected table and date slicer filter parameters from HTTP GET requests.
$hasSelectedTable = isset($_GET['table']) && in_array($_GET['table'], $allTables, true);
$selectedTable    = $hasSelectedTable ? trim($_GET['table']) : '';
$fromDate         = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$toDate           = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

// Process PowerBI-style quick preset date range selections.
if (isset($_GET['preset'])) {
    $preset = $_GET['preset'];
    if ($preset === 'today') {
        $fromDate = date('Y-m-d');
        $toDate   = date('Y-m-d');
    } elseif ($preset === '7days') {
        $fromDate = date('Y-m-d', strtotime('-7 days'));
        $toDate   = date('Y-m-d');
    } elseif ($preset === '30days') {
        $fromDate = date('Y-m-d', strtotime('-30 days'));
        $toDate   = date('Y-m-d');
    } elseif ($preset === 'all') {
        $fromDate = '';
        $toDate   = '';
    }
}

// Warn admin if an invalid/non-existent table parameter is requested via URL manipulation.
if (isset($_GET['table']) && $_GET['table'] !== '' && !$hasSelectedTable) {
    dashboardAppendError($error, 'Unknown table requested: ' . $_GET['table']);
}

// ------------------------------------------------------------
// SECTION 4: OVERVIEW METRICS & TICKET SLICER DATA FETCHING
// ------------------------------------------------------------

// Initialize metrics array with explicit zero values to avoid array key lookup notices.
$stats = [
    'users'          => 0,
    'departments'    => 0,
    'companies'      => 0,
    'request_types'  => 0,
    'workflow_steps' => 0,
    'tickets'        => 0
];

$overviewTickets = [];

// Calculate overall entity totals when viewing the primary landing dashboard view.
if (empty($selectedTable)) {
    try {
        if (in_array('users', $allTables, true))          $stats['users']          = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if (in_array('departments', $allTables, true))    $stats['departments']    = (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
        if (in_array('companies', $allTables, true))      $stats['companies']      = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
        if (in_array('request_types', $allTables, true))  $stats['request_types']  = (int)$pdo->query("SELECT COUNT(*) FROM request_types")->fetchColumn();
        if (in_array('workflow_steps', $allTables, true)) $stats['workflow_steps'] = (int)$pdo->query("SELECT COUNT(*) FROM workflow_steps")->fetchColumn();

        // Query tickets and calculate filtered metrics using current date slicer filters.
        if (in_array('tickets', $allTables, true)) {
            $ticketSql = "SELECT * FROM tickets";
            $ticketParams = [];
            $ticketWhere = [];

            // Apply date filters dynamically based on user input
            if (!empty($fromDate)) {
                $ticketWhere[] = "DATE(created_at) >= :from_date";
                $ticketParams['from_date'] = $fromDate;
            }
            if (!empty($toDate)) {
                $ticketWhere[] = "DATE(created_at) <= :to_date";
                $ticketParams['to_date'] = $toDate;
            }

            if (!empty($ticketWhere)) {
                $ticketSql .= " WHERE " . implode(' AND ', $ticketWhere);
            }

            $ticketSql .= " ORDER BY created_at DESC LIMIT 100";

            $tStmt = $pdo->prepare($ticketSql);
            $tStmt->execute($ticketParams);
            $overviewTickets = $tStmt->fetchAll(PDO::FETCH_ASSOC);
            $stats['tickets'] = count($overviewTickets);
        }
    } catch (PDOException $e) {
        dashboardAppendError($error, dashboardCaughtError($e, 'Could not load dashboard metrics and ticket records.'));
    }
}

// Define CRUD capability rules and read-only constraints per table.
$crudTables          = ['users', 'approvers', 'companies', 'departments', 'rt_approval_steps'];
$isCrudTable         = in_array($selectedTable, $crudTables, true);
$isRequestTypeTable  = ($selectedTable === 'request_types');
$isWorkflowStepTable = ($selectedTable === 'workflow_steps');
$isReadOnly          = in_array($selectedTable, ['admin', 'tickets', 'ticket_approvals', 'ticket_logs', 'request_types', 'workflow_steps'], true);

// Map tables to their respective timestamp date column for filtering.
$dateColumnMap = [
    'tickets'          => 'created_at',
    'ticket_approvals' => 'created_at',
    'ticket_logs'      => 'logged_at'
];
$dateColumn = $dateColumnMap[$selectedTable] ?? null;

// ------------------------------------------------------------------
// SECTION 5: TABLE STRUCTURE DISCOVERY & CRUD OPERATIONAL HANDLERS
// ------------------------------------------------------------------

$primaryKey   = '';
$tableColumns = [];
$autoIncrementColumns = [];
$tableData    = [];
$existingApproverUserIds = [];
$deptApproversMap = [];
$deptEligibleUsersMap = [];

if ($hasSelectedTable) {
    // Inspect target table structure to dynamically construct HTML tables and CRUD modal forms.
    try {
        $colStmt      = $pdo->query("DESCRIBE `$selectedTable`");
        $rawColumns   = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        $tableColumns = array_column($rawColumns, 'Field');
        
        foreach ($rawColumns as $column) {
            // Identify auto-incrementing columns to disable manual user input in modals
            if (str_contains(strtolower($column['Extra'] ?? ''), 'auto_increment')) {
                $autoIncrementColumns[] = $column['Field'];
            }
            // Identify designated primary key column for update/delete actions
            if ($column['Key'] === 'PRI') {
                $primaryKey = $column['Field'];
            }
        }
        // Default to first discovered column if explicit primary key index is absent
        if (empty($primaryKey) && !empty($tableColumns)) {
            $primaryKey = $tableColumns[0];
        }
        $visibleColumnCount = count($tableColumns) - (($selectedTable === 'admin' && in_array('password', $tableColumns, true)) ? 1 : 0);
    } catch (PDOException $e) {
        dashboardAppendError($error, dashboardCaughtError($e, "Could not read structure for table `{$selectedTable}`."));
    }

    // ------------------------------------------------------------------------
    // QUICK ACTION HANDLER: Promote eligible HOD user to Department Approver
    // ------------------------------------------------------------------------
    if (($selectedTable === 'users' || $selectedTable === 'departments') && isset($_GET['action']) && $_GET['action'] === 'make_approver' && isset($_GET['user_id'])) {
        $userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
        if (!$userId) {
            dashboardAppendError($error, 'make_approver failed. Invalid user_id provided.');
        } else {
            try {
                // Confirm user existence, role, and department assignment
                $uStmt = $pdo->prepare("SELECT user_id, department_id, role FROM users WHERE user_id = :id");
                $uStmt->execute(['id' => $userId]);
                $user = $uStmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    dashboardAppendError($error, "make_approver failed. No matching user found for user_id={$userId}.");
                } elseif (($user['role'] ?? '') === 'staff') {
                    dashboardAppendError($error, "make_approver failed. User has role=staff. Approvers require role=hod.");
                } elseif ($user['department_id'] === null || $user['department_id'] === '') {
                    dashboardAppendError($error, "make_approver failed. User has no assigned department_id.");
                } else {
                    // Inspect approvers table schema to ensure correct column naming
                    $appColsStmt = $pdo->query("DESCRIBE `approvers`");
                    $appCols     = array_column($appColsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
                    $userCol = in_array('approver_user_id', $appCols, true) ? 'approver_user_id' : (in_array('user_id', $appCols, true) ? 'user_id' : ($appCols[0] ?? 'approver_user_id'));
                    $deptCol = in_array('department_id', $appCols, true) ? 'department_id' : 'department_id';

                    // Insert approver link record
                    $insStmt = $pdo->prepare("INSERT INTO approvers (`$userCol`, `$deptCol`) VALUES (:user_id, :dept_id)");
                    $insStmt->execute(['user_id' => $user['user_id'], 'dept_id' => $user['department_id']]);
                    header("Location: admin_dashboard.php?table=" . urlencode($selectedTable) . "&msg=approver_added");
                    exit();
                }
            } catch (PDOException $e) {
                dashboardAppendError($error, dashboardCaughtError($e, "Could not insert approver record for user_id={$userId}."));
            }
        }
    }

    // -----------------------------------------
    // CRUD ACTION HANDLER: DELETE Record
    // Create, Read, Update, Delete operations
    // -----------------------------------------
    if ($isCrudTable && isset($_GET['crud_action']) && $_GET['crud_action'] === 'delete' && isset($_GET['id'])) {
        try {
            $delStmt = $pdo->prepare("DELETE FROM `$selectedTable` WHERE `$primaryKey` = :id");
            $delStmt->execute(['id' => $_GET['id']]);
            header("Location: admin_dashboard.php?table=" . urlencode($selectedTable) . "&msg=deleted");
            exit();
        } catch (PDOException $e) {
            dashboardAppendError($error, dashboardCaughtError($e, "Could not delete from `{$selectedTable}` where `{$primaryKey}`=" . var_export($_GET['id'], true) . '.'));
        }
    }

    // -------------------------------------------------------
    // CRUD ACTION HANDLER: CREATE (INSERT) or UPDATE Record
    // -------------------------------------------------------
    if ($isCrudTable && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crud_submit'])) {
        $isEdit = !empty($_POST['primary_key_val']);
        $fields = [];

        // Parse and validate POST fields matching table structure
        foreach ($tableColumns as $col) {
            if (!$isEdit && $col === $primaryKey) {
                continue; // Skip primary key input during creation for auto-increment tables
            }
            if ($selectedTable === 'admin' && $col === 'password') {
                $submittedPassword = $_POST[$col] ?? '';
                if ($submittedPassword === '') {
                    continue; // Retain current hashed password if left blank
                }
                $fields[$col] = password_hash($submittedPassword, PASSWORD_DEFAULT);
                continue;
            }
            if (isset($_POST[$col])) {
                $fields[$col] = $_POST[$col] === '' ? null : trim($_POST[$col]);
            }
        }

        try {
            if ($isEdit) {
                // UPDATE existing database record
                if (empty($fields)) {
                    dashboardAppendError($error, 'Update aborted: no submitted fields provided for `' . $selectedTable . '`.');
                } else {
                    $setClause = implode(', ', array_map(fn($col) => "`$col` = :$col", array_keys($fields)));
                    $stmt = $pdo->prepare("UPDATE `$selectedTable` SET $setClause WHERE `$primaryKey` = :pk_val");
                    foreach ($fields as $col => $val) {
                        $stmt->bindValue(":$col", $val);
                    }
                    $stmt->bindValue(':pk_val', $_POST['primary_key_val']);
                    $stmt->execute();

                    // Synchronize user display name if editing approver record
                    if ($selectedTable === 'approvers') {
                        $approverDisplayName = trim((string)($_POST['approver_display_name'] ?? ''));
                        if ($approverDisplayName !== '') {
                            $renameStmt = $pdo->prepare("
                                UPDATE users u
                                JOIN approvers a ON a.approver_user_id = u.user_id
                                SET u.display_name = :display_name
                                WHERE a.approver_id = :approver_id
                            ");
                            $renameStmt->execute([
                                'display_name' => $approverDisplayName,
                                'approver_id'  => $_POST['primary_key_val']
                            ]);
                        }
                    }
                    $message = "Record updated successfully!";
                }
            } else {
                // INSERT new database record
                if (empty($fields)) {
                    dashboardAppendError($error, 'Insert aborted: no submitted fields provided for `' . $selectedTable . '`.');
                } else {
                    $colNames = implode('`, `', array_keys($fields));
                    $placeholder = ':' . implode(', :', array_keys($fields));
                    $stmt = $pdo->prepare("INSERT INTO `$selectedTable` (`$colNames`) VALUES ($placeholder)");
                    foreach ($fields as $col => $val) {
                        $stmt->bindValue(":$col", $val);
                    }
                    $stmt->execute();
                    $message = "New record added successfully!";
                }
            }
        } catch (PDOException $e) {
            dashboardAppendError($error, dashboardCaughtError($e, ($isEdit ? 'Could not update' : 'Could not insert') . " `{$selectedTable}`."));
        }
    }

    // ------------------------------------------------------
    // FETCH DIRECTORY TABLE DATA SAFELY WITH DATE FILTERS
    // ------------------------------------------------------
    try {
        $whereConditions = [];
        $queryParams     = [];

        if ($dateColumn && !in_array($dateColumn, $tableColumns, true)) {
            dashboardAppendError($error, "Date filter skipped. Column `{$dateColumn}` does not exist on `{$selectedTable}`.");
            $dateColumn = null;
        }

        if ($dateColumn) {
            if (!empty($fromDate)) {
                $whereConditions[] = "DATE(`$dateColumn`) >= :from_date";
                $queryParams['from_date'] = $fromDate;
            }
            if (!empty($toDate)) {
                $whereConditions[] = "DATE(`$dateColumn`) <= :to_date";
                $queryParams['to_date'] = $toDate;
            }
        }

        $whereClause = !empty($whereConditions) ? ' WHERE ' . implode(' AND ', $whereConditions) : '';

        if ($selectedTable === 'approvers') {
            $sql = "SELECT a.*, u.display_name AS Name FROM approvers a JOIN users u ON a.approver_user_id = u.user_id $whereClause ORDER BY a.department_id LIMIT 200";
        } else {
            $sql = "SELECT * FROM `$selectedTable` $whereClause LIMIT 200";
        }

        $dataStmt = $pdo->prepare($sql);
        $dataStmt->execute($queryParams);
        $tableData = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        dashboardAppendError($error, dashboardCaughtError($e, "Could not load rows from `{$selectedTable}`."));
        $tableData = [];
    }

    // Load active approver user maps for badge status rendering
    $existingApproverUserIds = [];
    if ($selectedTable === 'users' || $selectedTable === 'departments') {
        try {
            $appStmt = $pdo->query("SELECT approver_user_id FROM approvers");
            foreach ($appStmt->fetchAll(PDO::FETCH_ASSOC) as $approverRow) {
                if (isset($approverRow['approver_user_id']) && $approverRow['approver_user_id'] !== '') {
                    $existingApproverUserIds[] = (int)$approverRow['approver_user_id'];
                }
            }
        } catch (PDOException $e) {
            dashboardAppendError($error, dashboardCaughtError($e, 'Could not load approver assignments.'));
        }
    }

    // Load department approvers and eligible HOD user maps for modal rendering
    $deptApproversMap = [];
    $deptEligibleUsersMap = [];
    if ($selectedTable === 'departments') {
        try {
            $appDeptStmt = $pdo->query("SELECT a.department_id, u.user_id, u.display_name FROM approvers a JOIN users u ON a.approver_user_id = u.user_id");
            foreach ($appDeptStmt->fetchAll(PDO::FETCH_ASSOC) as $appRow) {
                $deptApproversMap[(int)$appRow['department_id']][] = $appRow;
            }
            $eligStmt = $pdo->query("SELECT user_id, display_name, department_id, email FROM users WHERE department_id IS NOT NULL AND role = 'hod'");
            foreach ($eligStmt->fetchAll(PDO::FETCH_ASSOC) as $uRow) {
                $deptEligibleUsersMap[(int)$uRow['department_id']][] = $uRow;
            }
        } catch (PDOException $e) {
            dashboardAppendError($error, dashboardCaughtError($e, 'Could not load department approvers.'));
        }
    }
}

// Convert message keys into readable notification banners
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') {
        $message = "Record deleted successfully!";
    }
    if ($_GET['msg'] === 'approver_added') {
        $message = "User registered as department approver!";
    }
}

// Fetch foreign key datasets for modal dropdown selections
$companiesList = [];
$departmentsList = [];
try {
    $companiesList = $pdo->query("SELECT company_id, company_name FROM companies")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    dashboardAppendError($error, dashboardCaughtError($e, 'Could not load companies for dropdown selects.'));
}
try {
    $departmentsList = $pdo->query("SELECT d.department_id, d.department_name, c.company_name FROM departments d JOIN companies c ON d.company_id = c.company_id")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    dashboardAppendError($error, dashboardCaughtError($e, 'Could not load departments for dropdown selects.'));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - Command Center</title>

    <!-- Bootstrap 5 CSS CDN & Custom Google Fonts -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Accessible High Contrast Dark Layout Styles -->
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

        /* Enforce pure white text contrast across all elements */
        body, p, span, div, h1, h2, h3, h4, h5, h6, label, td, th, input, select, textarea, a {
            color: #ffffff !important;
        }

        /* High-contrast placeholder text styling */
        ::placeholder {
            color: #94a3b8 !important;
            opacity: 1 !important;
        }

        /* Sidebar navigation scrollbar customization */
        .sidebar-nav::-webkit-scrollbar { width: 6px; }
        .sidebar-nav::-webkit-scrollbar-track { background: #0d1322; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: #1f2937; border-radius: 3px; }

        /* Fixed Left Application Sidebar */
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

        /* Main Application Workspace */
        .app-main { margin-left: var(--sidebar-width); padding: 2rem; }
        .glass-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; }
        
        .metric-card {
            background: linear-gradient(135deg, #111827 0%, #1f2937 100%);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem;
        }

        .metric-label { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.05em; opacity: 0.9; }

        /* Custom Dark Data Table Styling */
        .table-dark-custom {
            --bs-table-bg: var(--bg-card);
            --bs-table-border-color: var(--border-color);
            --bs-table-color: #ffffff;
            font-size: 0.825rem;
        }
        
        .table-dark-custom thead { background-color: #1f2937; color: #ffffff !important; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

        .btn-action-primary { background-color: var(--accent-blue); color: #fff !important; border: none; font-weight: 600; }
        .btn-action-primary:hover { background-color: #2563eb; }

        /* Custom Accessible Alert Box Styling */
        .dark-alert-warning {
            background-color: #1e1b00;
            border: 1px solid #eab308;
            color: #fef08a !important;
            border-radius: 8px;
            padding: 1rem;
        }
        .dark-alert-warning strong, .dark-alert-warning span {
            color: #fef08a !important;
        }

        .error-panel {
            background-color: #2a1215;
            border: 1px solid #f87171;
            color: #fecaca !important;
        }
        .error-panel, .error-panel strong, .error-panel pre {
            color: #fecaca !important;
        }
        .error-panel pre {
            background: #1a0b0d;
            border: 1px solid #7f1d1d;
            border-radius: 6px;
            padding: 0.75rem;
            white-space: pre-wrap;
            word-break: break-word;
            color: #fde047 !important;
            margin: 0.5rem 0 0;
        }
    </style>
</head>
<body>

    <!-- ----------------------------------------- -->
    <!-- COMPONENT: LEFT SIDEBAR NAVIGATION BAR    -->
    <!-- ----------------------------------------- -->
    <aside class="app-sidebar">
        <div class="sidebar-brand d-flex align-items-center gap-2">
            <span class="badge bg-danger fs-6">ADMIN</span> FLOWDESK
        </div>

        <div class="sidebar-nav">
            <a href="admin_dashboard.php" class="sidebar-link <?= empty($selectedTable) ? 'active' : '' ?>">
                ⚡ Dashboard Overview
            </a>

            <div class="nav-section-title">Workflow & Schemas</div>
            <a href="workflow_steps_manager.php" class="sidebar-link">🔀 Workflow Steps Builder</a>
            <a href="request_type_manager.php" class="sidebar-link">📋 Request Form Builder</a>
            <a href="admin_dashboard.php?table=workflow_steps" class="sidebar-link <?= $selectedTable === 'workflow_steps' ? 'active' : '' ?>">🪜 Workflow Route Table</a>
            <a href="admin_dashboard.php?table=request_types" class="sidebar-link <?= $selectedTable === 'request_types' ? 'active' : '' ?>">📂 Request Categories Directory</a>

            <div class="nav-section-title">Directory Management</div>
            <a href="admin_dashboard.php?table=users" class="sidebar-link <?= $selectedTable === 'users' ? 'active' : '' ?>">👥 User Accounts</a>
            <a href="admin_dashboard.php?table=departments" class="sidebar-link <?= $selectedTable === 'departments' ? 'active' : '' ?>">🏢 Department Entities</a>
            <a href="admin_dashboard.php?table=companies" class="sidebar-link <?= $selectedTable === 'companies' ? 'active' : '' ?>">🏛️ Company Entities</a>
            <a href="admin_dashboard.php?table=approvers" class="sidebar-link <?= $selectedTable === 'approvers' ? 'active' : '' ?>">🛡️ Approver Lists</a>

            <div class="nav-section-title">Audit & Logs</div>
            <a href="admin_dashboard.php?table=tickets" class="sidebar-link <?= $selectedTable === 'tickets' ? 'active' : '' ?>">🎟️ Requester Ticket History</a>
            <a href="admin_dashboard.php?table=ticket_approvals" class="sidebar-link <?= $selectedTable === 'ticket_approvals' ? 'active' : '' ?>">✅ HOD Approval History</a>
            <a href="admin_dashboard.php?table=ticket_logs" class="sidebar-link <?= $selectedTable === 'ticket_logs' ? 'active' : '' ?>">📜 Staff Activity Logs</a>
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
                <h4 class="fw-bold mb-1">System Command Center</h4>
                <p class="small mb-0">Overview and table administration portal</p>
            </div>
            
            <form method="GET" action="admin_dashboard.php" class="d-flex align-items-center gap-2">
                <select name="table" class="form-select form-select-sm bg-dark border-secondary" onchange="this.form.submit()">
                    <option value="">-- Select Database Table --</option>
                    <?php foreach ($allTables as $table): ?>
                        <option value="<?= htmlspecialchars($table) ?>" <?= $table === $selectedTable ? 'selected' : '' ?>><?= htmlspecialchars($table) ?></option>
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
                <strong>Error</strong>
                <pre><?= htmlspecialchars($error) ?></pre>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- -------------------------------------------------------------- -->
        <!-- VIEW 1: LANDING OVERVIEW METRICS & TICKET DATA SLICER PANEL    -->
        <!-- -------------------------------------------------------------- -->
        <?php if (empty($selectedTable)): ?>
            <!-- System Metric Overview Cards -->
            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-5 g-3 mb-4">
                <div class="col">
                    <div class="metric-card">
                        <div class="metric-label text-uppercase font-monospace" style="font-size: 15px;">USERS</div>
                        <div class="fs-3 fw-bold my-1"><?= $stats['users'] ?></div>
                        <a href="admin_dashboard.php?table=users" class="small text-decoration-none fw-semibold">Manage Users &rarr;</a>
                    </div>
                </div>
                <div class="col">
                    <div class="metric-card">
                        <div class="metric-label text-uppercase font-monospace" style="font-size: 15px;">DEPARTMENTS</div>
                        <div class="fs-3 fw-bold my-1"><?= $stats['departments'] ?></div>
                        <a href="admin_dashboard.php?table=departments" class="small text-decoration-none fw-semibold">Manage Departments &rarr;</a>
                    </div>
                </div>
                <div class="col">
                    <div class="metric-card">
                        <div class="metric-label text-uppercase font-monospace" style="font-size: 15px;">COMPANIES</div>
                        <div class="fs-3 fw-bold my-1"><?= $stats['companies'] ?></div>
                        <a href="admin_dashboard.php?table=companies" class="small text-decoration-none fw-semibold">Manage Companies &rarr;</a>
                    </div>
                </div>
                <div class="col">
                    <div class="metric-card">
                        <div class="metric-label text-uppercase font-monospace" style="font-size: 15px;">REQUEST CATEGORIES</div>
                        <div class="fs-3 fw-bold my-1"><?= $stats['request_types'] ?></div>
                        <a href="request_type_manager.php" class="small text-decoration-none fw-semibold">Manage Request Forms &rarr;</a>
                    </div>
                </div>
                <div class="col">
                    <div class="metric-card">
                        <div class="metric-label text-uppercase font-monospace" style="font-size: 15px;">WORKFLOW STEPS</div>
                        <div class="fs-3 fw-bold my-1"><?= $stats['workflow_steps'] ?></div>
                        <a href="workflow_steps_manager.php" class="small text-decoration-none fw-semibold">Manage Workflow Steps &rarr;</a>
                    </div>
                </div>
            </div>

            <!-- Ticket Viewing Data with PowerBI-style Date Slicer Control Panel -->
            <div class="glass-card p-4 mb-4">
                <div class="col-md-2 col-sm-6">
                    <div class="metric-card">
                        <div class="metric-label text-uppercase font-monospace" style="font-size: 15px;">TICKETS</div>
                        <div class="fs-3 fw-bold my-1"><?= $stats['tickets'] ?></div>
                    </div>
                </div>
                <br>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">🎟️ View Ticket Data</h5>
                    <span class="badge bg-secondary" style="font-size: 1rem;">Total Tickets: <?= count($overviewTickets) ?></span>
                </div>

                <!-- Date Slicer Form Controls -->
                <form method="GET" action="admin_dashboard.php" class="row g-2 align-items-end mb-4">
                    <div class="col-md-5 d-flex gap-1">
                        <a href="admin_dashboard.php?preset=all" class="btn btn-sm btn-outline-light border-secondary">All Time</a>
                        <a href="admin_dashboard.php?preset=today" class="btn btn-sm btn-outline-light border-secondary">Today</a>
                        <a href="admin_dashboard.php?preset=7days" class="btn btn-sm btn-outline-light border-secondary">Last 7 Days</a>
                        <a href="admin_dashboard.php?preset=30days" class="btn btn-sm btn-outline-light border-secondary">Last 30 Days</a>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm bg-dark border-secondary" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm bg-dark border-secondary" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-action-primary btn-sm w-100">Apply</button>
                    </div>
                </form>

                <!-- Dynamic Ticket Records Data Table -->
                <div class="table-responsive">
                    <table class="table table-dark-custom table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <?php if (!empty($overviewTickets)): ?>
                                    <?php foreach (array_keys($overviewTickets[0]) as $thCol): ?>
                                        <th class="py-3 px-3"><?= htmlspecialchars(strtoupper($thCol)) ?></th>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <th class="py-3 px-3">TICKET DATA</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($overviewTickets)): ?>
                                <?php foreach ($overviewTickets as $tRow): ?>
                                    <tr>
                                        <?php foreach ($tRow as $tCell): ?>
                                            <td class="px-3"><?= htmlspecialchars($tCell ?? 'NULL') ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="100" class="text-center py-4">No ticket records found for the selected date range.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ------------------------------------------------------- -->
        <!-- VIEW 2: ACTIVE DIRECTORY TABLE DATA VIEW & MANAGEMENT   -->
        <!-- ------------------------------------------------------- -->
        <?php if ($hasSelectedTable): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Table Directory: <span><?= htmlspecialchars($selectedTable) ?></span></h5>
                <?php if ($isCrudTable && $selectedTable !== 'approvers' && $selectedTable !== 'rt_approval_steps'): ?>
                    <button type="button" class="btn btn-success btn-sm fw-bold" onclick="openCrudModal()">+ Add New Record</button>
                <?php endif; ?>
            </div>

            <!-- Date Filter Header Bar for Specific Log/Ticket Directory Tables -->
            <?php if ($dateColumn): ?>
                <div class="glass-card p-3 mb-4">
                    <form method="GET" action="admin_dashboard.php" class="row g-2 align-items-end">
                        <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Filter From Date</label>
                            <input type="date" name="from_date" class="form-control form-control-sm bg-dark border-secondary" placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($fromDate) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Filter To Date</label>
                            <input type="date" name="to_date" class="form-control form-control-sm bg-dark border-secondary" placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($toDate) ?>">
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button type="submit" class="btn btn-action-primary btn-sm">Filter Records</button>
                            <?php if (!empty($fromDate) || !empty($toDate)): ?>
                                <a href="admin_dashboard.php?table=<?= urlencode($selectedTable) ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Directory Data Table Container -->
            <div class="glass-card overflow-hidden mb-5">
                <div class="table-responsive">
                    <table class="table table-dark-custom table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <?php foreach ($tableColumns as $col): ?>
                                    <?php if ($selectedTable === 'admin' && $col === 'password') continue; ?>
                                    
                                    <?php 
                                    // Comment: Hide IS_REQUESTER_WORKFLOW column header from directory table
                                    if ($selectedTable === 'request_types' && strtolower($col) === 'is_requester_workflow') continue; 
                                    ?>

                                    <th class="py-3 px-3"><?= htmlspecialchars(strtoupper($col)) ?></th>
                                <?php endforeach; ?>

                                <?php if ($selectedTable === 'approvers'): ?>
                                    <th class="py-3 px-3">APPROVER NAME</th>
                                <?php elseif ($selectedTable === 'departments'): ?>
                                    <th class="py-3 px-3">APPROVER(S)</th>
                                <?php endif; ?>

                                <?php if ($isCrudTable): ?>
                                    <th class="py-3 px-3">ACTIONS</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($tableData)): ?>
                                <?php foreach ($tableData as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $columnName => $cell): ?>
                                            <?php if ($selectedTable === 'approvers' && $columnName === 'Name') continue; ?>
                                            <?php if ($selectedTable === 'admin' && $columnName === 'password') continue; ?>
                                            
                                            <?php 
                                            // Comment: Hide IS_REQUESTER_WORKFLOW cell value from directory table
                                            if ($selectedTable === 'request_types' && strtolower($columnName) === 'is_requester_workflow') continue; 
                                            ?>

                                            <td class="px-3"><?= htmlspecialchars($cell ?? 'NULL') ?></td>
                                        <?php endforeach; ?>

                                        <?php if ($selectedTable === 'approvers'): ?>
                                            <td class="px-3 fw-bold"><?= htmlspecialchars($row['Name'] ?? '') ?></td>
                                        <?php elseif ($selectedTable === 'departments'): ?>
                                            <td class="px-3">
                                                <?php 
                                                    $deptId = (int)$row['department_id'];
                                                    $assignedApprovers = $deptApproversMap[$deptId] ?? [];
                                                ?>
                                                <?php if (!empty($assignedApprovers)): ?>
                                                    <?php foreach ($assignedApprovers as $appItem): ?>
                                                        <span class="badge bg-primary me-1"><?= htmlspecialchars($appItem['display_name']) ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="small">None assigned</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>

                                        <?php if ($isCrudTable): ?>
                                            <td class="text-nowrap px-3">
                                                <button type="button" class="btn btn-warning btn-sm me-1 fw-bold" onclick='editRecord(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>

                                                <?php if ($selectedTable === 'users'): ?>
                                                    <?php 
                                                        $isAlreadyApprover = in_array((int)$row['user_id'], $existingApproverUserIds, true);
                                                        $isStaffAccount = (($row['role'] ?? 'staff') === 'staff');
                                                    ?>
                                                    <?php if ($isAlreadyApprover): ?>
                                                        <span class="badge bg-success me-1">Approver</span>
                                                    <?php elseif ($isStaffAccount): ?>
                                                        <span class="btn btn-secondary btn-sm disabled me-1">Staff</span>
                                                    <?php else: ?>
                                                        <a href="admin_dashboard.php?table=users&action=make_approver&user_id=<?= urlencode($row['user_id']) ?>" class="btn btn-info btn-sm me-1 fw-bold" onclick="return confirm('Promote this user to Department Approver?');">+ Approver</a>
                                                    <?php endif; ?>
                                                <?php elseif ($selectedTable === 'departments'): ?>
                                                    <button type="button" class="btn btn-info btn-sm me-1 fw-bold" onclick='openDepartmentApproverModal(<?= (int)$row['department_id'] ?>, <?= htmlspecialchars(json_encode($row['department_name']), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($deptEligibleUsersMap[(int)$row['department_id']] ?? []), ENT_QUOTES, "UTF-8") ?>)'>+ Approver</button>
                                                <?php endif; ?>

                                                <a href="admin_dashboard.php?table=<?= urlencode($selectedTable) ?>&crud_action=delete&id=<?= urlencode($row[$primaryKey]) ?>" class="btn btn-danger btn-sm fw-bold" onclick="return confirm('Delete this record?');">Delete</a>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="100" class="text-center py-4">No records found matching criteria in "<?= htmlspecialchars($selectedTable) ?>".</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Component: Department Approver Selection -->
            <?php if ($selectedTable === 'departments'): ?>
                <div class="modal fade" id="deptApproverModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content bg-dark border-secondary">
                            <div class="modal-header border-secondary">
                                <h5 class="modal-title fw-bold" id="deptModalTitle">Assign Department Approver</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <p class="small mb-3">Select an HOD for <strong id="deptModalName"></strong>:</p>
                                <div id="deptApproverSelectContainer"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                let bsDeptModal = null;
                document.addEventListener('DOMContentLoaded', function() {
                    const modalEl = document.getElementById('deptApproverModal');
                    if (modalEl) bsDeptModal = new bootstrap.Modal(modalEl);
                });

                function escapeHtml(value) {
                    return String(value ?? '').replace(/[&<>"']/g, function(char) {
                        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
                    });
                }

                function openDepartmentApproverModal(deptId, deptName, eligibleUsers) {
                    document.getElementById('deptModalName').innerText = deptName;
                    const container = document.getElementById('deptApproverSelectContainer');
                    container.innerHTML = '';

                    if (!eligibleUsers || eligibleUsers.length === 0) {
                        container.innerHTML = `
                            <div class="dark-alert-warning mb-0">
                                ⚠️ <strong>No eligible HOD rows</strong> for department <strong>${escapeHtml(deptName)}</strong> (users.role=hod and matching department_id).
                            </div>`;
                    } else {
                        let html = '<div class="list-group">';
                        eligibleUsers.forEach(user => {
                            const isAlready = <?= json_encode($existingApproverUserIds) ?>.includes(Number(user.user_id));
                            const email = escapeHtml(user.email);
                            if (isAlready) {
                                html += `<div class="list-group-item d-flex justify-content-between align-items-center bg-dark border-secondary">
                                            <span><strong>${email}</strong></span>
                                            <span class="badge bg-success">Already Approver</span>
                                         </div>`;
                            } else {
                                html += `<div class="list-group-item d-flex justify-content-between align-items-center bg-dark border-secondary">
                                            <span><strong>${email}</strong></span>
                                            <a href="admin_dashboard.php?table=departments&action=make_approver&user_id=${encodeURIComponent(user.user_id)}" 
                                               class="btn btn-sm btn-primary fw-bold" 
                                               onclick="return confirm('Set ${email} as approver?');">Set Approver</a>
                                         </div>`;
                            }
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    }
                    if (bsDeptModal) bsDeptModal.show();
                }
                </script>
            <?php endif; ?>

            <!-- Modal Component: Dynamic CRUD Form Generation -->
            <?php if ($isCrudTable): ?>
                <div class="modal fade" id="crudModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content bg-dark border-secondary">
                            <div class="modal-header border-secondary">
                                <h5 class="modal-title fw-bold" id="modalTitle">Manage Record</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="admin_dashboard.php?table=<?= urlencode($selectedTable) ?>">
                                <div class="modal-body p-4">
                                    <input type="hidden" name="crud_submit" value="1">
                                    <input type="hidden" name="primary_key_val" id="primary_key_val" value="">

                                    <?php 
                                    $fieldPlaceholders = [
                                        'company_name'    => 'e.g: Leader Energy Group Berhad',
                                        'department_name' => 'e.g: IT DEPARTMENT',
                                        'display_name'    => 'e.g: ALEX IT',
                                        'fullname'        => 'e.g: ALEX MERCER',
                                        'email'           => 'e.g: alex.it@legb.com',
                                        'type_name'       => 'e.g: Hardware Replacement Request',
                                        'workflow_route'  => 'e.g: L1, L2, L3'
                                    ];
                                    ?>

                                    <?php foreach ($tableColumns as$col): ?>
                                        <?php 
                                        // Comment: Hide IS_REQUESTER_WORKFLOW from CRUD modal
                                        if ($selectedTable === 'request_types' && strtolower($col) === 'is_requester_workflow') continue; 
                                        ?>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold"><?= htmlspecialchars($col) ?></label>
                                            
                                            <?php if (in_array($col,$autoIncrementColumns, true)): ?>
                                                <input type="text" id="field_<?= $col ?>" name="<?= $col ?>" class="form-control bg-secondary border-secondary" placeholder="Generated automatically by system" disabled>
                                            <?php elseif ($col ===$primaryKey): ?>
                                                <input type="text" id="field_<?= $col ?>" name="<?= $col ?>" class="form-control bg-dark border-secondary" placeholder="Enter identifier">
                                            <?php elseif ($col === 'company_id'): ?>
                                                <select id="field_<?= $col ?>" name="<?= $col ?>" class="form-select bg-dark border-secondary">
                                                    <option value="">-- Select Parent Company --</option>
                                                    <?php foreach ($companiesList as$comp): ?>
                                                        <option value="<?= htmlspecialchars($comp['company_id']) ?>"><?= htmlspecialchars($comp['company_name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php elseif ($col === 'department_id'): ?>
                                                <select id="field_<?= $col ?>" name="<?= $col ?>" class="form-select bg-dark border-secondary">
                                                    <option value="">-- Select Department --</option>
                                                    <?php foreach ($departmentsList as$dept): ?>
                                                        <option value="<?= htmlspecialchars($dept['department_id']) ?>"><?= htmlspecialchars($dept['department_name'] . ' (' .$dept['company_name'] . ')') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php elseif ($col === 'role'): ?>
                                                <select id="field_<?= $col ?>" name="<?= $col ?>" class="form-select bg-dark border-secondary">
                                                    <option value="">-- Select User Role --</option>
                                                    <option value="staff">Staff</option>
                                                    <option value="hod">HOD</option>
                                                </select>
                                            <?php elseif (in_array($col, ['password', 'password_hash'], true)): ?>
                                                <input type="password" id="field_<?= $col ?>" name="<?= $col ?>" class="form-control bg-dark border-secondary" placeholder="Leave blank to preserve current password">
                                            <?php else: ?>
                                                <?php $phValue =$fieldPlaceholders[$col] ?? ('Enter ' . str_replace('_', ' ', $col)); ?>
                                                <textarea id="field_<?= $col ?>" name="<?= $col ?>" class="form-control bg-dark border-secondary" rows="2" placeholder="<?= htmlspecialchars($phValue) ?>"></textarea>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if ($selectedTable === 'approvers'): ?>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold">Approver Name</label>
                                            <input type="text" id="field_Name" name="approver_display_name" class="form-control bg-dark border-secondary" placeholder="e.g: John Doe">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer border-secondary">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-action-primary btn-sm">Save Record</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <script>
                let bsModal = null;
                document.addEventListener('DOMContentLoaded', function() {
                    const modalEl = document.getElementById('crudModal');
                    if (modalEl) bsModal = new bootstrap.Modal(modalEl);
                });

                function openCrudModal() {
                    document.getElementById('modalTitle').innerText = 'Add Record';
                    document.getElementById('primary_key_val').value = '';
                    let form = document.querySelector('#crudModal form');
                    if (form) form.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach(input => input.value = '');
                    if (bsModal) bsModal.show();
                }

                function editRecord(rowData) {
                    document.getElementById('modalTitle').innerText = 'Edit Record';
                    document.getElementById('primary_key_val').value = rowData['<?= $primaryKey ?>'] || '';
                    for (let key in rowData) {
                        let input = document.getElementById('field_' + key);
                        if (input) {
                            if (key === 'password') input.value = '';
                            else input.value = rowData[key] !== null ? rowData[key] : '';
                        }
                    }
                    if (bsModal) bsModal.show();
                }
                </script>
            <?php endif; ?>
        <?php endif; ?>

    </main>

    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>