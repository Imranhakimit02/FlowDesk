<?php
/**
 * File: admin/request_type_manager.php
 * Purpose: Manage request categories and dynamic JSON form schemas for the ticketing system.
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

$successMsg = '';
$errorMsg   = '';

// ---------------------------------------------
// SECTION 3: GET PROCESSING - DELETE CATEGORY
// ---------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $deleteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($deleteId) {
        try {
            $delStmt = $pdo->prepare("DELETE FROM request_types WHERE type_id = :id");
            $delStmt->execute(['id' => $deleteId]);
            header("Location: request_type_manager.php?msg=deleted");
            exit();
        } catch (PDOException $e) {
            $errorMsg = "Error deleting category: " . $e->getMessage();
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $successMsg = "Request category deleted successfully!";
}

// ---------------------------------------------------------------------------
// SECTION 4: POST PROCESSING - CREATE OR EDIT REQUEST CATEGORY & JSON SCHEMA
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action               = $_POST['action'];
    $editTypeId           = filter_input(INPUT_POST, 'type_id', FILTER_VALIDATE_INT);
    $typeName             = trim($_POST['type_name'] ?? '');
    $isRequesterWorkflow = isset($_POST['is_requester_workflow']) ? 1 : 0;
    $targetDeptId         = $isRequesterWorkflow ? null : filter_input(INPUT_POST, 'target_department_id', FILTER_VALIDATE_INT);
    $workflowRoute        = $isRequesterWorkflow
        ? 'REQUESTER_DEFINED'
        : trim($_POST['workflow_route'] ?? '');
    $stepDepartmentInputs = is_array($_POST['step_department_ids'] ?? null) ? $_POST['step_department_ids'] : [];

    $fieldsInput = $isRequesterWorkflow ? [] : ($_POST['fields'] ?? []);
    $schemaArray = [];

    foreach ($fieldsInput as $f) {
        if (!empty($f['label']) && !empty($f['name'])) {
            $fieldConfig = [
                'name'     => strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', trim($f['name']))),
                'label'    => trim($f['label']),
                'type'     => $f['type'],
                'required' => isset($f['required']) && $f['required'] == '1'
            ];

            if ($f['type'] === 'select' && !empty($f['options'])) {
                $opts = array_map('trim', explode(',', $f['options']));
                $fieldConfig['options'] = array_values(array_filter($opts));
            }

            if (!empty($f['placeholder'])) {
                $fieldConfig['placeholder'] = trim($f['placeholder']);
            }

            $schemaArray[] = $fieldConfig;
        }
    }

    $jsonSchema = json_encode($schemaArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!empty($typeName) && ($isRequesterWorkflow || $targetDeptId)) {
        try {
            $dynamicStepsStmt = $pdo->prepare("SELECT step_number FROM workflow_steps WHERE workflow_route = :route AND approver_type = 'REQUEST_TYPE_HOD' ORDER BY step_number ASC");
            $dynamicStepsStmt->execute(['route' => $workflowRoute]);
            $dynamicStepNumbers = $isRequesterWorkflow ? [] : $dynamicStepsStmt->fetchAll(PDO::FETCH_COLUMN);
            $validDepartmentIds = array_map('intval', $pdo->query('SELECT department_id FROM departments')->fetchAll(PDO::FETCH_COLUMN));
            $stepDepartments = [];

            foreach ($dynamicStepNumbers as $stepNumber) {
                $stepNumber = (int)$stepNumber;
                $departmentId = filter_var($stepDepartmentInputs[$stepNumber] ?? null, FILTER_VALIDATE_INT);
                if (!$departmentId || !in_array((int)$departmentId, $validDepartmentIds, true)) {
                    throw new RuntimeException("Choose an approval department for workflow step {$stepNumber}.");
                }
                $stepDepartments[$stepNumber] = (int)$departmentId;
            }

            $pdo->beginTransaction();
            if ($action === 'edit_category' && $editTypeId) {
                $stmt = $pdo->prepare("
                    UPDATE request_types 
                    SET type_name = :type_name, 
                        target_department_id = :target_dept, 
                        workflow_route = :workflow, 
                        form_schema = :form_schema,
                        is_requester_workflow = :is_requester_workflow
                    WHERE type_id = :type_id
                ");
                $stmt->execute([
                    'type_name'             => $typeName,
                    'target_dept'           => $targetDeptId,
                    'workflow'              => $workflowRoute,
                    'form_schema'           => $jsonSchema,
                    'is_requester_workflow' => $isRequesterWorkflow,
                    'type_id'               => $editTypeId
                ]);
                $savedTypeId = $editTypeId;
                $successMsg = "Request category updated successfully!";
            } elseif ($action === 'add_category') {
                $stmt = $pdo->prepare("
                    INSERT INTO request_types (type_name, target_department_id, workflow_route, form_schema, is_requester_workflow)
                    VALUES (:type_name, :target_dept, :workflow, :form_schema, :is_requester_workflow)
                ");
                $stmt->execute([
                    'type_name'             => $typeName,
                    'target_dept'           => $targetDeptId,
                    'workflow'              => $workflowRoute,
                    'form_schema'           => $jsonSchema,
                    'is_requester_workflow' => $isRequesterWorkflow
                ]);
                $savedTypeId = (int)$pdo->lastInsertId();
                $successMsg = "New request category added successfully!";
            }

            if (isset($savedTypeId)) {
                $deleteMappings = $pdo->prepare('DELETE FROM rt_approval_steps WHERE request_type_id = :type_id');
                $deleteMappings->execute(['type_id' => $savedTypeId]);
                $insertMapping = $pdo->prepare('INSERT INTO rt_approval_steps (request_type_id, step_number, department_id) VALUES (:type_id, :step_number, :department_id)');
                foreach ($stepDepartments as $stepNumber => $departmentId) {
                    $insertMapping->execute([
                        'type_id'       => $savedTypeId,
                        'step_number'   => $stepNumber,
                        'department_id' => $departmentId
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = $e->getMessage();
        }
    } else {
        $errorMsg = "Please fill in all required category details.";
    }
}

// ---------------------------------------
// SECTION 5: DATA DISCOVERY & FETCHING
// ---------------------------------------
$editingCategory = null;
$editingStepDepartments = [];
if (isset($_GET['edit_id'])) {
    $editId = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
    if ($editId) {
        $editStmt = $pdo->prepare("SELECT * FROM request_types WHERE type_id = :id");
        $editStmt->execute(['id' => $editId]);
        $editingCategory = $editStmt->fetch(PDO::FETCH_ASSOC);
        if ($editingCategory) {
            $mappingStmt = $pdo->prepare('SELECT step_number, department_id FROM rt_approval_steps WHERE request_type_id = :type_id');
            $mappingStmt->execute(['type_id' => $editingCategory['type_id']]);
            foreach ($mappingStmt->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
                $editingStepDepartments[(int)$mapping['step_number']] = (int)$mapping['department_id'];
            }
        }
    }
}

try {
    $departments = $pdo->query("SELECT * FROM departments ORDER BY department_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    $categoriesStmt = $pdo->query("
        SELECT rt.type_id, rt.type_name, rt.target_department_id,
               rt.workflow_route, rt.form_schema, rt.is_requester_workflow, d.department_name
        FROM request_types rt
        LEFT JOIN departments d ON rt.target_department_id = d.department_id
        ORDER BY rt.type_id ASC
    ");
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
    $approvalSummaryStmt = $pdo->query("
        SELECT mapping.request_type_id, mapping.step_number, department.department_name
        FROM rt_approval_steps mapping
        JOIN departments department ON department.department_id = mapping.department_id
        ORDER BY mapping.request_type_id, mapping.step_number
    ");
    $approvalDepartmentsByType = [];
    foreach ($approvalSummaryStmt->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
        $approvalDepartmentsByType[(int)$mapping['request_type_id']][] = 'Step ' . $mapping['step_number'] . ': ' . $mapping['department_name'];
    }
} catch (PDOException $e) {
    die('Error loading database records: ' . htmlspecialchars($e->getMessage()));
}

$workflowRoutes = [];
$dynamicApprovalSteps = [];
try {
    $routeStepStmt = $pdo->query('SELECT workflow_route, step_number, approver_type FROM workflow_steps ORDER BY workflow_route ASC, step_number ASC');
    foreach ($routeStepStmt->fetchAll(PDO::FETCH_ASSOC) as $routeStep) {
        $route = $routeStep['workflow_route'];
        if (!isset($dynamicApprovalSteps[$route])) {
            $dynamicApprovalSteps[$route] = [];
        }
        if ($routeStep['approver_type'] === 'REQUEST_TYPE_HOD') {
            $dynamicApprovalSteps[$route][] = (int)$routeStep['step_number'];
        }
    }
    $workflowRoutes = array_keys($dynamicApprovalSteps);

    if (empty($workflowRoutes)) {
        $workflowRoutes = ['No Route Exist!'];
    }
} catch (PDOException $e) {
    $workflowRoutes = ['No Route Exist!'];
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
    <title>Flowdesk - Admin Form Builder</title>
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
            <a href="workflow_steps_manager.php" class="sidebar-link">🔀 Workflow Steps Builder</a>
            <a href="request_type_manager.php" class="sidebar-link active">📋 Request Form Builder</a>
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
                <h4 class="fw-bold mb-1">Dynamic Request Category Builder</h4>
                <p class="small mb-0">Construct custom category forms and workflow routing rules</p>
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
        <?php if (!empty($successMsg)): ?>
            <div class="alert alert-success bg-dark text-success border-success alert-dismissible fade show mb-4" role="alert">
                <strong>Success:</strong> <?= htmlspecialchars($successMsg) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert error-panel alert-dismissible fade show mb-4" role="alert" aria-live="assertive">
                <strong>Error:</strong> <?= htmlspecialchars($errorMsg) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Category Builder Form Glass Card -->
        <div class="glass-card p-4 mb-5">
            <h5 class="fw-bold mb-3"><?= $editingCategory ? 'Edit Category & Dynamic Fields' : 'Create New Category & Custom Fields' ?></h5>
            <form method="POST" action="request_type_manager.php">
                <input type="hidden" name="action" value="<?= $editingCategory ? 'edit_category' : 'add_category' ?>">
                <?php if ($editingCategory): ?>
                    <input type="hidden" name="type_id" value="<?= $editingCategory['type_id'] ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label for="type_name" class="form-label small fw-bold">Category Name <span class="text-danger">*</span></label>
                    <input type="text" id="type_name" name="type_name" class="form-control bg-dark border-secondary" required placeholder="e.g: Parking Pass Request" value="<?= htmlspecialchars($editingCategory['type_name'] ?? '') ?>">
                </div>

                <div id="standard-category-fields">
                    <div class="mb-3">
                        <label for="target_department_id" class="form-label small fw-bold">Target Department <span class="text-danger">*</span></label>
                        <select id="target_department_id" name="target_department_id" class="form-select bg-dark border-secondary" required>
                            <option value="">-- Select Target Department --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= htmlspecialchars($d['department_id']) ?>" <?= isset($editingCategory) && $editingCategory['target_department_id'] == $d['department_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <fieldset id="standard-workflow-fields" class="p-0 m-0 border-0">
                    <div class="mb-3">
                        <label for="workflow_route" class="form-label small fw-bold">Workflow Approval Route <span class="text-danger">*</span></label>
                        <select id="workflow_route" name="workflow_route" class="form-select bg-dark border-secondary" required onchange="renderStepApprovalDepartments()">
                            <option value="">-- Select Workflow Route --</option>
                            <?php foreach ($workflowRoutes as $route): ?>
                                <option value="<?= htmlspecialchars($route) ?>" <?= isset($editingCategory) && $editingCategory['workflow_route'] === $route ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($route) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="step-approval-departments" class="mb-3 p-3 bg-dark border border-secondary rounded" style="display:none;"></div>

                    <hr class="border-secondary my-4">

                    <h6 class="fw-bold mb-3">Form Questions / Dynamic Fields</h6>
                    <div id="builder-container" class="mb-3"></div>

                    <button type="button" class="btn btn-outline-light btn-sm mb-3 fw-semibold" onclick="addQuestionField()">+ Add Question Field</button>
                </fieldset>

                <div class="d-flex gap-2 pt-2">
                    <button type="submit" class="btn btn-action-primary btn-sm px-4 fw-bold"><?= $editingCategory ? 'Update Category & Schema' : 'Save Category & Form Schema' ?></button>
                    <?php if ($editingCategory): ?>
                        <a href="request_type_manager.php" class="btn btn-outline-secondary btn-sm fw-semibold">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <h5 class="fw-bold mb-3">Configured Request Categories</h5>

        <!-- Categories Table Card -->
        <div class="glass-card overflow-hidden mb-5">
            <div class="table-responsive">
                <table class="table table-dark-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="py-3 px-3">ID</th>
                            <th class="py-3 px-3">CATEGORY NAME</th>
                            <th class="py-3 px-3">TARGET DEPT</th>
                            <th class="py-3 px-3">DYNAMIC APPROVAL DEPTS</th>
                            <th class="py-3 px-3">WORKFLOW</th>
                            <th class="py-3 px-3">FORM SCHEMA (JSON)</th>
                            <th class="py-3 px-3">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($categories)): ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td class="px-3"><strong>#<?= htmlspecialchars($cat['type_id']) ?></strong></td>
                                    <td class="px-3"><strong><?= htmlspecialchars($cat['type_name']) ?></strong></td>
                                    <td class="px-3"><?= htmlspecialchars($cat['department_name'] ?? 'N/A') ?></td>
                                    <td class="px-3"><?= htmlspecialchars(implode(', ', $approvalDepartmentsByType[(int)$cat['type_id']] ?? []) ?: 'None') ?></td>
                                    <td class="px-3"><span class="badge bg-primary"><?= htmlspecialchars($cat['workflow_route'] ?? 'N/A') ?></span></td>
                                    <td class="px-3">
                                        <pre class="bg-dark p-2 border border-secondary rounded mb-0 small text-info" style="max-height: 80px; overflow-y: auto;"><?= htmlspecialchars($cat['form_schema'] ?? '[]') ?></pre>
                                    </td>
                                    <td class="text-nowrap px-3">
                                        <a href="request_type_manager.php?edit_id=<?= $cat['type_id'] ?>" class="btn btn-warning btn-sm me-1 fw-bold">Edit</a>
                                        <a href="request_type_manager.php?action=delete&id=<?= $cat['type_id'] ?>" class="btn btn-danger btn-sm fw-bold" onclick="return confirm('Delete this request category?');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">No categories configured in system.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- JavaScript logic preserved -->
    <script>
    const dynamicApprovalSteps = <?= json_encode($dynamicApprovalSteps, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const approvalDepartments = <?= json_encode(array_map(static fn($department) => [
        'id' => (int)$department['department_id'],
        'name' => $department['department_name']
    ], $departments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const savedStepDepartments = <?= json_encode($editingStepDepartments, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const selectedDepartmentsByRoute = {};

    const initialWorkflowRoute = document.getElementById('workflow_route').value;
    selectedDepartmentsByRoute[initialWorkflowRoute] = { ...savedStepDepartments };
    let lastRenderedWorkflowRoute = initialWorkflowRoute;

    function renderStepApprovalDepartments() {
        const container = document.getElementById('step-approval-departments');
        const routeSelect = document.getElementById('workflow_route');

        const previousSelects = container.querySelectorAll('select[name^="step_department_ids["]');
        if (!selectedDepartmentsByRoute[lastRenderedWorkflowRoute]) {
            selectedDepartmentsByRoute[lastRenderedWorkflowRoute] = {};
        }
        previousSelects.forEach(select => {
            const match = select.name.match(/\[(\d+)\]/);
            if (match) selectedDepartmentsByRoute[lastRenderedWorkflowRoute][match[1]] = select.value;
        });

        const selectedRoute = routeSelect.value;
        const steps = dynamicApprovalSteps[selectedRoute] || [];
        container.replaceChildren();

        if (steps.length === 0) {
            container.style.display = 'none';
            lastRenderedWorkflowRoute = selectedRoute;
            return;
        }

        container.style.display = 'block';
        const heading = document.createElement('h6');
        heading.className = 'fw-bold mb-1';
        heading.textContent = 'Additional Approval Step Departments';
        container.appendChild(heading);

        const routeSelections = selectedDepartmentsByRoute[selectedRoute] || {};
        steps.forEach(stepNumber => {
            const wrapper = document.createElement('div');
            wrapper.className = 'mb-3';

            const label = document.createElement('label');
            label.htmlFor = `step_department_${stepNumber}`;
            label.innerHTML = `Step ${stepNumber} Approving Department <span class="text-danger">*</span>`;
            label.className = 'form-label fw-bold small mb-1';

            const select = document.createElement('select');
            select.id = `step_department_${stepNumber}`;
            select.name = `step_department_ids[${stepNumber}]`;
            select.className = 'form-select bg-dark border-secondary';
            select.required = true;

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '-- Select Department --';
            select.appendChild(placeholder);

            approvalDepartments.forEach(department => {
                const option = document.createElement('option');
                option.value = department.id;
                option.textContent = department.name;
                select.appendChild(option);
            });

            select.value = routeSelections[stepNumber] || '';
            wrapper.append(label, select);
            container.appendChild(wrapper);
        });
        lastRenderedWorkflowRoute = selectedRoute;
    }

    renderStepApprovalDepartments();

    let fieldCounter = 0;

    function generateVariableKey(labelText) {
        return labelText
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9\s_]/g, '')
            .replace(/\s+/g, '_');
    }

    function syncVariableKey(id) {
        const labelInput = document.getElementById(`field_label_${id}`);
        const keyInput   = document.getElementById(`field_key_${id}`);
        
        if (labelInput && keyInput) {
            keyInput.value = generateVariableKey(labelInput.value);
        }
    }

    function addQuestionField(data = null) {
        const container = document.getElementById('builder-container');
        const id = fieldCounter++;

        const labelVal       = data ? data.label || '' : '';
        const nameVal        = data ? data.name || '' : '';
        const typeVal        = data ? data.type || 'text' : 'text';
        const placeholderVal = data ? data.placeholder || '' : '';
        const isRequired     = data ? (data.required ? 'checked' : '') : 'checked';
        const optionsVal     = data && data.options ? data.options.join(', ') : '';

        const row = document.createElement('div');
        row.id = `field_row_${id}`;
        row.className = 'card bg-dark border-secondary p-3 mb-2';
        
        row.innerHTML = `
            <div class="row g-2 align-items-center">
                <div class="col-md-3">
                    <input type="text" id="field_label_${id}" name="fields[${id}][label]" value="${labelVal}" placeholder="Field Label (e.g: Vehicle Plate No)" class="form-control form-control-sm bg-dark border-secondary" onkeyup="syncVariableKey(${id})" required>
                </div>
                <div class="col-md-2">
                    <input type="text" id="field_key_${id}" name="fields[${id}][name]" value="${nameVal}" placeholder="Variable" class="form-control form-control-sm bg-secondary border-secondary" readonly required>
                </div>
                <div class="col-md-2">
                    <select name="fields[${id}][type]" onchange="toggleSelectOptions(this, ${id})" class="form-select form-select-sm bg-dark border-secondary">
                        <option value="text"           ${typeVal === 'text' ? 'selected' : ''}>Short Text</option>
                        <option value="number"         ${typeVal === 'number' ? 'selected' : ''}>Number</option>
                        <option value="date"           ${typeVal === 'date' ? 'selected' : ''}>Date</option>
                        <option value="time"           ${typeVal === 'time' ? 'selected' : ''}>Time</option>
                        <option value="select"         ${typeVal === 'select' ? 'selected' : ''}>Dropdown Select</option>
                        <option value="textarea"       ${typeVal === 'textarea' ? 'selected' : ''}>Long Text Area</option>
                        <option value="checkbox"       ${typeVal === 'checkbox' ? 'selected' : ''}>Checkbox / Toggle</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" name="fields[${id}][placeholder]" value="${placeholderVal}" placeholder="Placeholder" class="form-control form-control-sm bg-dark border-secondary">
                </div>
                <div class="col-md-1 text-center">
                    <div class="form-check d-inline-block">
                        <input class="form-check-input" type="checkbox" name="fields[${id}][required]" value="1" ${isRequired} id="req_${id}">
                        <label class="form-check-label small" for="req_${id}">Req</label>
                    </div>
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" onclick="document.getElementById('field_row_${id}').remove()" class="btn btn-danger btn-sm">X</button>
                </div>
            </div>
            <div id="options_wrap_${id}" class="mt-2" style="display: ${typeVal === 'select' ? 'block' : 'none'};">
                <input type="text" name="fields[${id}][options]" value="${optionsVal}" placeholder="Comma-separated options (e.g: Option A, Option B)" class="form-control form-control-sm bg-dark border-secondary">
            </div>
        `;

        container.appendChild(row);
    }

    function toggleSelectOptions(selectElem, id) {
        const wrap = document.getElementById(`options_wrap_${id}`);
        if (wrap) {
            wrap.style.display = selectElem.value === 'select' ? 'block' : 'none';
        }
    }

    window.onload = function() {
        <?php if ($editingCategory && !empty($editingCategory['form_schema'])): ?>
            const initialSchema = <?= $editingCategory['form_schema'] ?>;
            if (Array.isArray(initialSchema) && initialSchema.length > 0) {
                initialSchema.forEach(field => addQuestionField(field));
            } else {
                addQuestionField();
            }
        <?php else: ?>
            addQuestionField();
        <?php endif; ?>
    };
    </script>
    
    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>