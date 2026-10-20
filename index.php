<?php
/**
 * File: index.php
 * Purpose: The main entry point for the FlowDesk system, allowing user search and request submission.
 * Author: Imran IT Intern
 */

// --------------------
// Database connection
// --------------------
require_once __DIR__ . '/config/db.php';

// --------------------------
// Search (user) text field
// --------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';$users  = [];

// --------------------------------------------------------------------------------
// GET from table in database
// Search for users in the database based on the provided search term (email).
// --------------------------------------------------------------------------------
if (!empty($search)) {
    // Search strictly by email
    if (filter_var($search, FILTER_VALIDATE_EMAIL)) {
        $stmt =$pdo->prepare("
            SELECT u.user_id, u.display_name, u.fullname, u.email, u.department_id,
                   d.department_name,
                   c.company_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.department_id
            LEFT JOIN companies c ON d.company_id = c.company_id
            WHERE u.email = :search
        ");
        $stmt->execute(['search' =>$search]);
        $users =$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// --------------------------------------------------------------------
// GET request types from the database for the dropdown
// Search for request types and their associated target departments.
// --------------------------------------------------------------------
try {
    $stmtTypes =$pdo->query("
        SELECT rt.type_id, rt.type_name, rt.workflow_route, rt.is_requester_workflow, d.department_name AS target_dept
        FROM request_types rt
        LEFT JOIN departments d ON rt.target_department_id = d.department_id
        ORDER BY rt.type_id ASC
    ");
    $requestTypes =$stmtTypes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error loading request categories: ' . htmlspecialchars($e->getMessage()));
}

// Requesters may choose a private sequence from the same admin-approved approver pool.
$approverStmt =$pdo->query("SELECT DISTINCT u.user_id, u.display_name
    FROM approvers a JOIN users u ON u.user_id = a.approver_user_id
    WHERE u.role != 'staff' ORDER BY u.display_name, u.user_id");
$eligibleApprovers =$approverStmt->fetchAll(PDO::FETCH_ASSOC);

$maxWorkflowSteps = (int)$pdo->query("SELECT setting_value FROM approval_numbers WHERE setting_key = 'requester_workflow_max_steps'")->fetchColumn();
if ($maxWorkflowSteps < 1) {$maxWorkflowSteps = 5;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowdesk - User Request Portal</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

    <!-- Header Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm mb-4">
        <div class="container">
            <a class="navbar-brand fw-bold text-uppercase" href="index.php">
                <span class="badge bg-primary me-2">FLOWDESK</span> User Request Portal
            </a>
            <div class="d-flex align-items-center">
                <a href="track_ticket.php" class="btn btn-outline-light btn-sm fw-bold">Track Your Ticket</a>
            </div>
        </div>
    </nav>

    <div class="container my-4" style="max-width: 900px;">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h3 fw-bold mb-0 text-dark">Submit Request</h2>
        </div>

        <!-- Search Form Card -->
        <div class="card shadow-sm border-0 p-4 mb-4">
            <!-- <label for="search_email" class="form-label fw-bold text-muted small text-uppercase mb-2">Account Lookup</label> -->
            <form method="GET" action="index.php" class="row g-2 align-items-center">
                <div class="col-md-9">
                    <input type="email" id="search_email" name="search" class="form-control" placeholder="Enter your email address (e.g., john.doe@company.com)..." value="<?= htmlspecialchars($search) ?>" required>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary fw-bold w-100">Search User</button>
                    <?php if (!empty($search)): ?>
                        <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Account Not Found Alert (Visible only when search executed but yields no match) -->
        <?php if (!empty($search) && empty($users)): ?>
            <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
                <div>
                    <strong>No user account found.</strong>
                    <p>Please verify your email address or contact IT Support Team if your account needs to be registered.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- User Search Results Table (HIDDEN BY DEFAULT; Displays ONLY when user account is found) -->
        <?php if (!empty($search) && !empty($users)): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-dark text-white fw-bold">
                    User Details
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle mb-0">
                            <thead class="table-light text-uppercase small">
                                <tr>
                                    <th>ID</th>
                                    <th>Display Name</th>
                                    <th>Full Name</th>
                                    <th>Department</th>
                                    <th>Company</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as$user): ?>
                                    <tr>
                                        <td><strong>#<?= htmlspecialchars($user['user_id']) ?></strong></td>
                                        <td><span class="badge bg-primary"><?= htmlspecialchars($user['display_name']) ?></span></td>
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

            <!-- Request Submission Form (Only visible when user account is verified) -->
            <div class="card shadow-sm border-0 p-4 mb-5">
                <h3 class="h4 fw-bold mb-1">Submit Request</h3>
                <p class="text-muted small mb-4"><span class="text-danger">*</span> Indicates a required field</p>
                
                <form action="submit_ticket.php" method="POST" enctype="multipart/form-data">
                    <!-- Hidden User Identifiers -->
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($users[0]['user_id']) ?>">
                    <input type="hidden" name="user_email" value="<?= htmlspecialchars($users[0]['email']) ?>">

                    <!-- Category Dropdown -->
                    <div class="mb-3">
                        <label for="type_id" class="form-label fw-bold">1. Request Category <span class="text-danger">*</span></label>
                        <select name="type_id" id="type_id" class="form-select" required onchange="renderDynamicFields(this.value)">
                            <option value="">-- Select a category --</option>
                            <?php foreach ($requestTypes as$t): ?>
                                <option value="<?= htmlspecialchars($t['type_id']) ?>" data-requester-workflow="<?= !empty($t['is_requester_workflow']) ? '1' : '0' ?>">
                                    <?= !empty($t['is_requester_workflow']) ? '' : '(' . htmlspecialchars($t['type_id']) . ') ' ?><?= htmlspecialchars($t['type_name']) ?><?= !empty($t['is_requester_workflow']) ? '' : ' (Target: ' . htmlspecialchars($t['target_dept']) . ')' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Dynamic Extra Fields Container (Populated asynchronously by Database JSON) -->
                    <div id="dynamic-fields-container" class="mb-3"></div>

                    <div id="custom-request-details" class="mb-3" style="display:none;">
                        <label for="request_title" class="form-label fw-bold">Request title <span class="text-danger">*</span></label>
                        <input type="text" id="request_title" name="request_title" class="form-control" maxlength="190" placeholder="Briefly describe what needs approval">
                    </div>

                    <div id="custom-workflow-fields" class="card p-3 mb-3 bg-light border-0 shadow-sm" style="display:none;">
                        <h4 class="h5 mb-2 fw-bold">Build your approval steps</h4>
                        <p class="text-muted small mb-3">Choose how many steps you need, then select an approver for each step. Approvals happen in order. An approver may be selected more than once.</p>
                        <div class="mb-3">
                            <label for="custom_workflow_levels" class="form-label fw-bold">Number of approval steps (maximum <?= $maxWorkflowSteps ?>)</label>
                            <input type="number" id="custom_workflow_levels" name="custom_workflow_levels" class="form-control" min="1" max="<?= $maxWorkflowSteps ?>" value="1" onchange="renderCustomApprovers()" oninput="renderCustomApprovers()">
                        </div>
                        <div id="custom-approvers-container"></div>
                    </div>

                    <!-- Justification / Remarks -->
                    <div class="mb-3">
                        <label for="remarks" id="remarks-label" class="form-label fw-bold">2. Justification / Remarks <span class="text-danger">*</span></label>
                        <textarea name="remarks" id="remarks" class="form-control" rows="3" placeholder="Remark is required upon request..." required></textarea>
                    </div>

                    <!-- Supporting Attachment -->
                    <div class="mb-4">
                        <label for="attachment" class="form-label fw-bold">3. Attach Supporting File (optional)</label>
                        <input type="file" name="attachment" id="attachment" class="form-control" accept=".pdf, .doc, .docx, .jpg, .png, .zip">
                        <div class="form-text text-primary small">Accepted file types: .pdf, .doc, .docx, .jpg, .png, .zip</div>
                    </div>

                    <button type="submit" class="btn btn-success fw-bold px-4">Submit Ticket</button>
                </form>
            </div>

            <!-- JavaScript: Database JSON-Driven Dynamic Form Engine -->
            <script>
            async function renderDynamicFields(typeId) {
                const container = document.getElementById('dynamic-fields-container');
                container.innerHTML = ''; // Clear previous fields

                const selectedOption = document.getElementById('type_id').selectedOptions[0];
                const isRequesterWorkflow = selectedOption?.dataset.requesterWorkflow === '1';
                document.getElementById('custom-request-details').style.display = isRequesterWorkflow ? 'block' : 'none';
                document.getElementById('request_title').required = isRequesterWorkflow;
                document.getElementById('custom-workflow-fields').style.display = isRequesterWorkflow ? 'block' : 'none';
                document.getElementById('custom_workflow_levels').required = isRequesterWorkflow;
                
                // Dynamic innerHTML update for remarks label with red asterisk
                const remarksLabel = document.getElementById('remarks-label');
                const labelText = isRequesterWorkflow ? 'Request details / justification' : 'Justification / Request Remarks';
                remarksLabel.innerHTML = `${labelText} <span class="text-danger">*</span>`;
                
                document.getElementById('remarks').placeholder = isRequesterWorkflow ? 'Describe the request and why approval is needed...' : 'Remark is required upon request...';
                renderCustomApprovers();

                if (!typeId || isRequesterWorkflow) return;

                try {
                    // Fetch JSON schema from database via API endpoint
                    const response = await fetch(`api/get_request_schema.php?type_id=${typeId}`);
                    const data = await response.json();

                    if (!data.success || !data.schema || data.schema.length === 0) {
                        return; // No custom dynamic fields configured for this category
                    }

                    // Card container markup
                    let html = `
                        <div class="card p-3 bg-light border-0 shadow-sm mb-3">
                            <h4 class="h6 text-dark fw-bold mb-3">${data.type_name} Details</h4>
                    `;

                    // Loop through fields in JSON schema array
                    data.schema.forEach(field => {
                        const requiredAttr = field.required ? 'required' : '';
                        const requiredMark = field.required ? ' <span class="text-danger">*</span>' : '';
                        const placeholderAttr = field.placeholder ? `placeholder="${field.placeholder}"` : '';

                        html += `<div class="mb-3">`;

                        // 1. Checkbox / Toggle Field
                        if (field.type === 'checkbox') {
                            html += `
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="meta[${field.name}]" value="Yes" id="meta_${field.name}" ${requiredAttr}>
                                    <label class="form-check-label fw-bold" for="meta_${field.name}">${field.label}${requiredMark}</label>
                                </div>
                            `;
                        } 
                        // 2. Star Rating (1 to 5 Stars)
                        else if (field.type === 'rating') {
                            html += `<label class="form-label fw-bold d-block">${field.label}${requiredMark}</label>`;
                            html += `<div class="btn-group" role="group" aria-label="Rating">`;
                            for (let i = 1; i <= 5; i++) {
                                html += `
                                    <input type="radio" class="btn-check" name="meta[${field.name}]" id="star_${field.name}_${i}" value="${i} Stars" ${i === 1 && field.required ? 'required' : ''}>
                                    <label class="btn btn-outline-warning text-dark font-monospace" for="star_${field.name}_${i}">&#9733; ${i}</label>
                                `;
                            }
                            html += `</div>`;
                        }
                        // 3. Dropdown Select Input
                        else if (field.type === 'select') {
                            html += `<label class="form-label fw-bold">${field.label}${requiredMark}</label>`;
                            html += `<select name="meta[${field.name}]" class="form-select" ${requiredAttr}>`;
                            html += `<option value="">-- Select --</option>`;
                            if (Array.isArray(field.options)) {
                                field.options.forEach(opt => {
                                    html += `<option value="${opt}">${opt}</option>`;
                                });
                            }
                            html += `</select>`;
                        } 
                        // 4. Multiline Textarea Input
                        else if (field.type === 'textarea') {
                            html += `<label class="form-label fw-bold">${field.label}${requiredMark}</label>`;
                            html += `<textarea name="meta[${field.name}]" class="form-control" ${placeholderAttr} ${requiredAttr}></textarea>`;
                        } 
                        // 5. Standard Inputs (text, number, date, time)
                        else {
                            html += `<label class="form-label fw-bold">${field.label}${requiredMark}</label>`;
                            html += `<input type="${field.type}" name="meta[${field.name}]" class="form-control" ${placeholderAttr} ${requiredAttr}>`;
                        }

                        html += `</div>`;
                    });

                    html += `</div>`;
                    container.innerHTML = html;

                } catch (error) {
                    console.error('Error loading dynamic form schema:', error);
                }
            }

            const eligibleApprovers = <?= json_encode(array_map(static fn($approver) => [
                'id' => (int)$approver['user_id'],
                'name' => $approver['display_name']
            ], $eligibleApprovers), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            function renderCustomApprovers() {
                const container = document.getElementById('custom-approvers-container');
                const enabled = document.getElementById('type_id').selectedOptions[0]?.dataset.requesterWorkflow === '1';
                const levelsInput = document.getElementById('custom_workflow_levels');
                const maxLevels = Number(levelsInput.max);
                let count = Number(levelsInput.value || 1);
                if (!Number.isFinite(count) || count < 1) count = 1;
                if (count > maxLevels) {
                    count = maxLevels;
                    levelsInput.value = String(maxLevels);
                }
                container.innerHTML = '';
                if (!enabled) return;
                for (let i = 1; i <= count; i++) {
                    const wrap = document.createElement('div');
                    wrap.className = 'mb-3';
                    const label = document.createElement('label');
                    label.innerHTML = `Level ${i} approver <span class="text-danger">*</span>`;
                    label.className = 'form-label fw-bold';
                    const select = document.createElement('select');
                    select.name = `custom_approvers[${i}]`;
                    select.className = 'form-select';
                    select.required = true;
                    const empty = document.createElement('option');
                    empty.value = '';
                    empty.textContent = '-- Select an approver --';
                    select.appendChild(empty);
                    eligibleApprovers.forEach(person => {
                        const option = document.createElement('option');
                        option.value = person.id;
                        option.textContent = person.name;
                        select.appendChild(option);
                    });
                    wrap.append(label, select);
                    container.appendChild(wrap);
                }
            }
            </script>
        <?php endif; ?>

    </div>

    <!-- Bootstrap 5 JavaScript Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>