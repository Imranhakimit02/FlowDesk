<?php
/**
 * File: admin/workflow_steps_manager.php
 * Purpose: Standalone manager page to build, edit, and organize approval workflow steps.
 * Author: Imran IT Intern
 */

// ------------------------------------------------------
// SECTION 1: DATABASE CONNECTION & SESSION MANAGEMENT
// ------------------------------------------------------
require_once dirname(__DIR__) . '/config/db.php';
session_start();

// ------------------------------------------------------
// SECTION 2: AUTHENTICATION & ACCESS CONTROL HANDLERS
// ------------------------------------------------------
if (!isset($_SESSION['admin_username'])) {
    header("Location: admin_login.php");
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin_username'], $_SESSION['admin_id'], $_SESSION['admin_user_id']);
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

$message = '';
$error   = '';
$_SESSION['workflow_settings_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_requester_workflow_limit'])) {
    $token = $_POST['csrf_token'] ?? '';
    $maxSteps = filter_var($_POST['max_steps'] ?? null, FILTER_VALIDATE_INT);
    if (!is_string($token) || !hash_equals($_SESSION['workflow_settings_csrf'], $token)) {
        $error = 'Your security token expired. Refresh the page and try again.';
    } elseif (!$maxSteps || $maxSteps < 1 || $maxSteps > 100) {
        $error = 'Enter a maximum between 1 and 100 approval steps.';
    } else {
        $saveLimit = $pdo->prepare("INSERT INTO approval_numbers (setting_key, setting_value)
            VALUES ('requester_workflow_max_steps', :setting_value)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $saveLimit->execute(['setting_value' => (string)$maxSteps]);
        $_SESSION['workflow_settings_csrf'] = bin2hex(random_bytes(32));
        $message = 'Maximum requester workflow steps updated.';
    }
}
$storedMaxSteps = $pdo->query("SELECT setting_value FROM approval_numbers WHERE setting_key = 'requester_workflow_max_steps'")->fetchColumn();
$requesterWorkflowMaxSteps = $storedMaxSteps !== false ? (int)$storedMaxSteps : 5;

// ------------------------------------------------------
// SECTION 3: USER LOOKUPS & WORKFLOW CRUD OPERATIONS
// ------------------------------------------------------
$usersList = [];
try {
    $uStmt = $pdo->query("
        SELECT DISTINCT u.user_id, u.display_name, a.approver_id, a.approver_user_id
        FROM users u
        JOIN approvers a ON u.user_id = a.approver_user_id
        WHERE u.display_name NOT LIKE '%STAFF%'
        ORDER BY u.display_name ASC;
    ");
    $usersList = $uStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $usersList = [];
}

if (isset($_GET['action'])) {
    if ($_GET['action'] === 'delete' && isset($_GET['id'])) {
        $stepId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if ($stepId) {
            try {
                $delStmt = $pdo->prepare("DELETE FROM workflow_steps WHERE workflow_id = :id");
                $delStmt->execute(['id' => $stepId]);
                header("Location: workflow_steps_manager.php?msg=deleted");
                exit();
            } catch (PDOException $e) {
                $error = "Error deleting workflow step: " . $e->getMessage();
            }
        }
    } elseif ($_GET['action'] === 'delete_route' && isset($_GET['route'])) {
        $routeCode = trim($_GET['route']);
        if (!empty($routeCode)) {
            try {
                $delStmt = $pdo->prepare("DELETE FROM workflow_steps WHERE workflow_route = :route");
                $delStmt->execute(['route' => $routeCode]);
                header("Location: workflow_steps_manager.php?msg=route_deleted");
                exit();
            } catch (PDOException $e) {
                $error = "Error deleting workflow route: " . $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_workflow_route'])) {
    $workflowRoute = strtoupper(trim($_POST['workflow_route'] ?? ''));
    $approverTypes = array_values($_POST['approver_type'] ?? []);
    $specificUsers = array_values($_POST['specific_user_id'] ?? []);
    $statusLabels  = array_values($_POST['status_label'] ?? []);

    if (!preg_match('/^L([1-9][0-9]*)$/', $workflowRoute, $routeMatch)) {
        $error = 'Enter a valid route code such as L1, L4, or L12.';
    } else {
        $requiredStepCount = (int)$routeMatch[1];
        if ($requiredStepCount > 99) {
            $error = 'This builder supports workflow levels L1 through L99.';
        } elseif (count($approverTypes) !== $requiredStepCount) {
            $error = "Route {$workflowRoute} requires exactly {$requiredStepCount} approval steps.";
        } else {
            foreach ($approverTypes as $index => $type) {
                if (!in_array($type, ['REQUESTER_HOD', 'TARGET_HOD', 'REQUEST_TYPE_HOD', 'SPECIFIC_USER'], true)) {
                    $error = 'Select an approver type for every step.';
                    break;
                }
                if (trim($statusLabels[$index] ?? '') === '') {
                    $error = 'Enter a status label for every step.';
                    break;
                }
                if ($type === 'SPECIFIC_USER' && empty($specificUsers[$index])) {
                    $error = 'Select a specific approver for every Specific User step.';
                    break;
                }
            }
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $delStmt = $pdo->prepare("DELETE FROM workflow_steps WHERE workflow_route = :route");
            $delStmt->execute(['route' => $workflowRoute]);

            $insStmt = $pdo->prepare("
                INSERT INTO workflow_steps (workflow_route, step_number, approver_type, specific_user_id, status_label)
                VALUES (:route, :step_num, :approver_type, :specific_user_id, :status_label)
            ");

            foreach ($approverTypes as $index => $type) {
                $stepNum  = $index + 1;
                $userId   = ($type === 'SPECIFIC_USER' && !empty($specificUsers[$index])) ? $specificUsers[$index] : null;
                $label    = trim($statusLabels[$index]);

                $insStmt->execute([
                    'route'            => $workflowRoute,
                    'step_num'         => $stepNum,
                    'approver_type'    => $type,
                    'specific_user_id' => $userId,
                    'status_label'     => $label
                ]);
            }

            $pdo->commit();
            header("Location: workflow_steps_manager.php?msg=saved");
            exit();

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') {
        $message = "Workflow step deleted successfully!";
    } elseif ($_GET['msg'] === 'route_deleted') {
        $message = "Entire workflow route deleted successfully!";
    } elseif ($_GET['msg'] === 'saved') {
        $message = "Workflow route configuration saved successfully!";
    }
}

$stepsStmt = $pdo->query("SELECT ws.*, u.fullname AS specific_user_name 
                          FROM workflow_steps ws 
                          LEFT JOIN users u ON ws.specific_user_id = u.user_id 
                          ORDER BY ws.workflow_route ASC, ws.step_number ASC");
$allSteps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

$groupedSteps = [];
foreach ($allSteps as $step) {
    $groupedSteps[$step['workflow_route']][] = $step;
}

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
    <title>Flowdesk - Workflow Steps Manager</title>
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

        .table-dark-custom {
            --bs-table-bg: var(--bg-card);
            --bs-table-border-color: var(--border-color);
            --bs-table-color: #ffffff;
            font-size: 0.825rem;
        }
        
        .table-dark-custom thead { background-color: #1f2937; color: #ffffff !important; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

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

    <!-- ----------------------------------------- -->
    <!-- COMPONENT: LEFT SIDEBAR NAVIGATION BAR    -->
    <!-- ----------------------------------------- -->
    <aside class="app-sidebar">
        <div class="sidebar-brand d-flex align-items-center gap-2">
            <span class="badge bg-danger fs-6">ADMIN</span> FLOWDESK
        </div>

        <div class="sidebar-nav">
            <a href="admin_dashboard.php" class="sidebar-link">
                ⚡ Dashboard Overview
            </a>

            <div class="nav-section-title">Workflow & Schemas</div>
            <a href="workflow_steps_manager.php" class="sidebar-link active">🔀 Workflow Steps Builder</a>
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
                <h4 class="fw-bold mb-1">Workflow Steps Builder</h4>
                <p class="small mb-0">Configure sequential multi-tier approval routes and status labels</p>
            </div>
            
            <form method="GET" action="admin_dashboard.php" class="d-flex align-items-center gap-2">
                <select name="table" class="form-select form-select-sm bg-dark border-secondary" onchange="this.form.submit()">
                    <option value="">-- Select Database Table --</option>
                    <?php foreach ($allTables as$table): ?>
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

        <div class="mb-4 d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-success btn-sm fw-bold" onclick="openStepModal()">+ Add New Workflow Route</button>
        </div>

        <!-- Limit Configuration Glass Card -->
        <div class="glass-card p-4 mb-4">
            <h6 class="fw-bold mb-2">Requester-Defined Workflow Limit</h6>
            <p class="text-secondary small mb-3">Set maximum sequential approval steps for free-flow requester workflows.</p>
            <form method="POST" action="workflow_steps_manager.php" class="row g-3 align-items-end">
                <input type="hidden" name="save_requester_workflow_limit" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['workflow_settings_csrf']) ?>">
                <div class="col-md-4">
                    <label for="max_steps" class="form-label small fw-bold">Maximum Steps (1–100)</label>
                    <input id="max_steps" name="max_steps" type="number" min="1" max="100" value="<?= htmlspecialchars((string)$requesterWorkflowMaxSteps) ?>" class="form-control form-control-sm bg-dark border-secondary" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-action-primary btn-sm w-100">Save Limit</button>
                </div>
            </form>
        </div>

        <!-- Workflow Steps Table Glass Card -->
        <div class="glass-card overflow-hidden mb-5">
            <div class="table-responsive">
                <table class="table table-dark-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="py-3 px-3">ID</th>
                            <th class="py-3 px-3">ROUTE</th>
                            <th class="py-3 px-3">STEP #</th>
                            <th class="py-3 px-3">APPROVER TYPE</th>
                            <th class="py-3 px-3">STATUS LABEL</th>
                            <th class="py-3 px-3">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($allSteps)): ?>
                            <?php foreach ($allSteps as$s): ?>
                                <tr>
                                    <td class="px-3"><strong>#<?= htmlspecialchars($s['workflow_id']) ?></strong></td>
                                    <td class="px-3"><span class="badge bg-primary fw-bold"><?= htmlspecialchars($s['workflow_route']) ?></span></td>
                                    <td class="px-3"><strong>Step <?= htmlspecialchars($s['step_number']) ?></strong></td>
                                    <td class="px-3"><code class="text-info fw-bold"><?= htmlspecialchars($s['approver_type']) ?></code></td>
                                    <td class="px-3"><?= htmlspecialchars($s['status_label']) ?></td>
                                    <td class="text-nowrap px-3">
                                        <button type="button" class="btn btn-warning btn-sm me-1 fw-bold" onclick='editRoute(<?= htmlspecialchars(json_encode($s['workflow_route']), ENT_QUOTES, "UTF-8") ?>)'>Edit Route</button>
                                        <a href="workflow_steps_manager.php?action=delete&id=<?= $s['workflow_id'] ?>" class="btn btn-danger btn-sm fw-bold" onclick="return confirm('Delete this step?');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">No workflow steps configured yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal Component: Workflow Steps Modal -->
    <div class="modal fade" id="stepModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold" id="modalTitle">Add Workflow Route</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="workflow_steps_manager.php">
                    <div class="modal-body p-4">
                        <input type="hidden" name="save_workflow_route" value="1">

                        <div class="mb-3">
                            <label for="workflow_route" class="form-label small fw-bold">Workflow Route Code <span class="text-danger">*</span></label>
                            <input type="text" name="workflow_route" id="workflow_route" class="form-control bg-dark border-secondary" placeholder="e.g: L1, L2, L4" pattern="L[1-9][0-9]*" oninput="syncStepsToRoute()" required>
                            <div id="workflow-step-hint" class="form-text small text-secondary">Route codes dynamically auto-generate step fields.</div>
                        </div>

                        <div id="steps-container">
                            <div class="card bg-dark border-secondary p-3 mb-3 step-card">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong class="step-title">Step 1</strong>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Approver Type <span class="text-danger">*</span></label>
                                    <select name="approver_type[]" class="form-select bg-dark border-secondary approver-type-select" onchange="toggleUserSelect(this); generateStatusLabel(this)" required>
                                        <option value="">-- Select Approver Role --</option>
                                        <option value="REQUESTER_HOD">Department HOD (Requester's Manager)</option>
                                        <option value="TARGET_HOD">Target Department HOD</option>
                                        <option value="REQUEST_TYPE_HOD">Selected Department HOD (Per Category)</option>
                                        <option value="SPECIFIC_USER">Specific User (Fixed)</option>
                                    </select>
                                </div>

                                <div class="mb-3 user-select-container" style="display: none;">
                                    <label class="form-label small fw-bold">Specific User <span class="text-danger">*</span></label>
                                    <select name="specific_user_id[]" class="form-select bg-dark border-secondary">
                                        <option value="">-- Select User --</option>
                                        <?php foreach ($usersList as$u): ?>
                                            <option value="<?= $u['approver_user_id'] ?>"><?= htmlspecialchars($u['display_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="form-label small fw-bold">Status Label <span class="text-danger">*</span></label>
                                    <input type="text" name="status_label[]" class="form-control bg-dark border-secondary" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-action-primary btn-sm">Save Full Route</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript Handling preserved -->
    <script>
    const groupedStepsData = <?= json_encode($groupedSteps, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let bsStepModal = null;

    document.addEventListener('DOMContentLoaded', function() {
        const modalEl = document.getElementById('stepModal');
        if (modalEl) {
            bsStepModal = new bootstrap.Modal(modalEl);
        }
    });

    function toggleUserSelect(selectElem) {
        let container = selectElem.closest('.step-card').querySelector('.user-select-container');
        if (selectElem.value === 'SPECIFIC_USER') {
            container.style.display = 'block';
            container.querySelector('select').required = true;
        } else {
            container.style.display = 'none';
            container.querySelector('select').value = '';
            container.querySelector('select').required = false;
        }
    }

    function generateStatusLabel(selectElem) {
        const card = selectElem.closest('.step-card');
        const labelInput = card.querySelector('input[name="status_label[]"]');
        const stepTitle = card.querySelector('.step-title').innerText;
        const stepNumber = Number(stepTitle.replace('Step ', ''));
        const defaultLabels = {
            REQUESTER_HOD: 'Requester Department HOD Approval',
            TARGET_HOD: 'Target Department HOD Approval',
            REQUEST_TYPE_HOD: 'Selected Department HOD Approval',
            SPECIFIC_USER: 'Designated User Approval'
        };
        const approvalDescription = defaultLabels[selectElem.value];
        if (!approvalDescription || !Number.isInteger(stepNumber)) return;

        const previousGenerated = labelInput.dataset.generatedLabel || '';
        if (!labelInput.value.trim() || labelInput.value.trim() === previousGenerated) {
            labelInput.value = `Pending Step ${stepNumber}: ${approvalDescription}`;
            labelInput.dataset.generatedLabel = labelInput.value;
        }
    }

    function addStepRow(data = null) {
        let container = document.getElementById('steps-container');
        let stepCards = container.querySelectorAll('.step-card');
        let nextStepNum = stepCards.length + 1;

        let template = stepCards[0].cloneNode(true);
        template.querySelector('.step-title').innerText = 'Step ' + nextStepNum;
        
        let approverSelect = template.querySelector('.approver-type-select');
        let userSelect     = template.querySelector('.user-select-container select');
        let statusInput    = template.querySelector('input[name="status_label[]"]');

        if (data) {
            approverSelect.value = data.approver_type || '';
            userSelect.value     = data.specific_user_id || '';
            statusInput.value    = data.status_label || '';
            statusInput.dataset.generatedLabel = data.status_label || '';
        } else {
            approverSelect.selectedIndex = 0;
            userSelect.selectedIndex     = 0;
            statusInput.value            = '';
            statusInput.dataset.generatedLabel = '';
        }

        toggleUserSelect(approverSelect);
        container.appendChild(template);
    }

    function syncStepsToRoute() {
        const routeCode = document.getElementById('workflow_route').value.trim().toUpperCase();
        const match = routeCode.match(/^L([1-9][0-9]*)$/);
        const hint = document.getElementById('workflow-step-hint');

        if (!match) return;

        const requiredCount = Number(match[1]);
        if (!Number.isSafeInteger(requiredCount) || requiredCount > 99) return;

        const container = document.getElementById('steps-container');
        while (container.querySelectorAll('.step-card').length < requiredCount) {
            addStepRow();
        }
        while (container.querySelectorAll('.step-card').length > requiredCount) {
            container.lastElementChild.remove();
        }
        reindexSteps();
        hint.innerText = `${routeCode} requires exactly ${requiredCount} approval steps.`;
    }

    function reindexSteps() {
        let cards = document.querySelectorAll('#steps-container .step-card');
        cards.forEach((card, idx) => {
            card.querySelector('.step-title').innerText = 'Step ' + (idx + 1);
            generateStatusLabel(card.querySelector('.approver-type-select'));
        });
    }

    function resetStepModal() {
        document.getElementById('workflow_route').value = '';
        let container = document.getElementById('steps-container');
        let cards = container.querySelectorAll('.step-card');
        
        for (let i = 1; i < cards.length; i++) {
            cards[i].remove();
        }

        let firstCard = cards[0];
        firstCard.querySelector('.step-title').innerText = 'Step 1';
        firstCard.querySelectorAll('input').forEach(i => i.value = '');
        firstCard.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        firstCard.querySelector('.user-select-container').style.display = 'none';
        firstCard.querySelector('.user-select-container select').required = false;
    }

    function openStepModal() {
        document.getElementById('modalTitle').innerText = 'Add Workflow Route';
        resetStepModal();
        if (bsStepModal) {
            bsStepModal.show();
        }
    }

    function editRoute(routeCode) {
        document.getElementById('modalTitle').innerText = 'Edit Workflow Route (' + routeCode + ')';
        resetStepModal();
        
        document.getElementById('workflow_route').value = routeCode;
        
        let steps = groupedStepsData[routeCode] || [];
        if (steps.length > 0) {
            let container = document.getElementById('steps-container');
            let firstCard = container.querySelector('.step-card');
            
            let approverSelect = firstCard.querySelector('.approver-type-select');
            let userSelect     = firstCard.querySelector('.user-select-container select');
            let statusInput    = firstCard.querySelector('input[name="status_label[]"]');

            approverSelect.value = steps[0].approver_type || '';
            userSelect.value     = steps[0].specific_user_id || '';
            statusInput.value    = steps[0].status_label || '';
            statusInput.dataset.generatedLabel = steps[0].status_label || '';

            toggleUserSelect(approverSelect);

            for (let i = 1; i < steps.length; i++) {
                addStepRow(steps[i]);
            }
        }

        syncStepsToRoute();
        if (bsStepModal) {
            bsStepModal.show();
        }
    }
    </script>
    
    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>