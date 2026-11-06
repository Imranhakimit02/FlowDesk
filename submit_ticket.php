<?php
/**
 * File: submit_ticket.php
 * Purpose: Handle the submission of new tickets and process JSON metadata.
 * Author: Imran IT Intern
 * API files that sit between user and the database
 * GET data from insert by user(ticket request) 
 * and POST data into the table's database.
 */

// --------------------
// Database connection
// --------------------
require_once __DIR__ . '/config/db.php';

// -------------------------------------------------------------
// Redirect back to previous page if error occured (5 seconds)
// Automatically redirect to the previous page
// -------------------------------------------------------------
function dieWithRedirect($errorMessage) {
    die(
        htmlspecialchars($errorMessage) . "<br><br>" .
        "Returning to the previous page in <span id='countdown'>5</span> seconds..." .
        "<script>
            var seconds = 5;
            var countdownElement = document.getElementById('countdown');
            
            var interval = setInterval(function() {
                seconds--;
                countdownElement.textContent = seconds;
                
                if (seconds <= 0) {
                    clearInterval(interval);
                    window.history.back();
                }
            }, 1000);
        </script>"
    );
}

// ------------------------------------------------------
// Post / submit ticket processing into `tickets` table
// ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// -----------------------------------------------------------
// POST variables from the form submission
// Use filter_input to validate and sanitize the input data
// -----------------------------------------------------------
$requester_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);$type_id      = filter_input(INPUT_POST, 'type_id', FILTER_VALIDATE_INT);
$user_email   = filter_input(INPUT_POST, 'user_email', FILTER_VALIDATE_EMAIL);$remarks      = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
$customWorkflowLevels = filter_var($_POST['custom_workflow_levels'] ?? null, FILTER_VALIDATE_INT);
$customApprovers = is_array($_POST['custom_approvers'] ?? null) ? $_POST['custom_approvers'] : [];
$requestTitle = isset($_POST['request_title']) && is_string($_POST['request_title']) ? trim($_POST['request_title']) : '';

// --------------------------------------------------------
// Basic validation to ensure required fields are present
// --------------------------------------------------------
if (!$requester_id || !$type_id || !$user_email || empty($remarks)) {
    die('Error: Required ticket fields are missing.');
}

// ----------------------------------------------------------------
// Process dynamic JSON metadata parameters from index.php
// ----------------------------------------------------------------
$metaArray =$_POST['meta'] ?? [];

// Auto-fill unchecked checkbox fields as "No"
if ($type_id) {
    try {
        $schemaStmt =$pdo->prepare("SELECT form_schema FROM request_types WHERE type_id = :type_id");
        $schemaStmt->execute(['type_id' =>$type_id]);
        $rawSchema =$schemaStmt->fetchColumn();
        
        if ($rawSchema) {
            $schemaFields = json_decode($rawSchema, true) ?: [];
            foreach ($schemaFields as$f) {
                if (($f['type'] ?? '') === 'checkbox' && !isset($metaArray[$f['name']])) {
                    $metaArray[$f['name']] = 'No';
                }
            }
        }
    } catch (PDOException $e) {
        // Fallthrough if schema check fails
    }
}

// Append submission diagnostics
$metaArray['submission_ip'] = $_SERVER['REMOTE_ADDR'];$metaArray['submitted_at']  = date('Y-m-d H:i:s');

// Encode array to JSON string for MySQL storage 
// (Database storing method: meta_data column in tickets table is of type JSON)
$jsonMetaData = json_encode($metaArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------------------
// Generate a URL-safe Base64 ticket token (32 bytes / 256-bit entropy)
// Produces a compact, highly secure token string
// ---------------------------------------------------------------------
$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

// -------------------------------------------------------------------
// Process file attachment if provided
// Check if a file was uploaded and handle it securely
// Only allow certain file types and store them in a secure directory
// -------------------------------------------------------------------
$attachment_file = null;
if (isset($_FILES['attachment']) &&$_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath   =$_FILES['attachment']['tmp_name'];
    $fileName      =$_FILES['attachment']['name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'png', 'zip'];

    if (!in_array($fileExtension,$allowedExtensions)) {
        die('Error: File type not supported.');
    }

    // Upload directory for attachments
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Unique filename using token and user email to avoid collisions
    $newFileName     =$requester_id . '_' . $user_email . '.' .$fileExtension;
    $destPath        = $uploadDir .$newFileName;

    // Move the uploaded file to the designated directory
    if (move_uploaded_file($fileTmpPath,$destPath)) {
        $attachment_file = 'uploads/' .$newFileName;
    } else {
        die('Error: Unable to upload attachment.');
    }
}

// -------------------------------------------------------
// Insert into `tickets` table matching exact schema
// -------------------------------------------------------
try {
    // Fetch Workflow Route and Target Department
    $stmtRoute =$pdo->prepare("
        SELECT workflow_route, target_department_id, is_requester_workflow
        FROM request_types
        WHERE type_id = :type_id
    ");
    $stmtRoute->execute(['type_id' =>$type_id]);
    $requestType =$stmtRoute->fetch(PDO::FETCH_ASSOC);

    if (!$requestType) {
        die('Error: Invalid request type.');
    }
    $customWorkflowEnabled = !empty($requestType['is_requester_workflow']);

    $requesterCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE user_id = :user_id AND email = :email');$requesterCheck->execute(['user_id' => $requester_id, 'email' =>$user_email]);
    if ((int)$requesterCheck->fetchColumn() !== 1) {
        die('Error: Requester account details do not match. Please return to the request form.');
    }

    // The browser's approver list is only a convenience; revalidate every selected
    // person against the current admin-managed approver table on the server.
    $workflowApprovers = [];
    if ($customWorkflowEnabled) {
        $configuredMaxSteps = (int)$pdo->query("SELECT setting_value FROM approval_numbers WHERE setting_key = 'requester_workflow_max_steps'")->fetchColumn();
        if ($configuredMaxSteps < 1) {$configuredMaxSteps = 5;
        }
        if ($requestTitle === '' || strlen($requestTitle) > 190 || !$customWorkflowLevels || $customWorkflowLevels < 1 || $customWorkflowLevels >$configuredMaxSteps || count($customApprovers) !==$customWorkflowLevels) {
            die('Error: Enter a request title and choose a valid number of approval steps with an approver for each.');
        }
        $eligibleStmt =$pdo->prepare("SELECT COUNT(*) FROM approvers a
            JOIN users u ON u.user_id = a.approver_user_id
            WHERE a.approver_user_id = :user_id AND u.role != 'staff'");
        for ($stepNumber = 1; $stepNumber <=$customWorkflowLevels; $stepNumber++) {$approverId = filter_var($customApprovers[$stepNumber] ?? null, FILTER_VALIDATE_INT);
            if (!$approverId) {
                die('Error: Select an eligible approver for every level.');
            }
            if ((int)$approverId === (int)$requester_id) {
                dieWithRedirect('Error: Requesters cannot approve their own ticket.');
            }
            $eligibleStmt->execute(['user_id' =>$approverId]);
            if ((int)$eligibleStmt->fetchColumn() < 1) { dieWithRedirect('Error: One of the selected approvers is no longer eligible.'); }$workflowApprovers[$stepNumber] =$approverId;
        }
    }

    if ($customWorkflowEnabled) {
        $metaArray['request_title'] =$requestTitle;
    }
    $jsonMetaData = json_encode($metaArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Insert ticket into `tickets` table
    $stmt =$pdo->prepare("
        INSERT INTO tickets (
            token, 
            requester_id, 
            type_id, 
            remarks, 
            meta_data,
            attachment_file, 
            created_at, 
            status, 
            hod_remarks, 
            assigned_staff_id
        ) VALUES (
            :token, 
            :requester_id, 
            :type_id, 
            :remarks, 
            :meta_data,
            :attachment_file, 
            NOW(), 
            'Pending Email Verification', 
            NULL, 
            NULL
        )
    ");

    $pdo->beginTransaction();$stmt->execute([
        'token'           => $token,
        'requester_id'    => $requester_id,
        'type_id'         => $type_id,
        'remarks'         => $remarks,
        'meta_data'       => $jsonMetaData,
        'attachment_file' => $attachment_file
    ]);

    $ticketId = (int)$pdo->lastInsertId();
    if ($workflowApprovers) {
        $insertCustomStep =$pdo->prepare("INSERT INTO ticket_worksteps
            (ticket_id, step_number, approver_user_id, status_label)
            VALUES (:ticket_id, :step_number, :approver_user_id, :status_label)");
        foreach ($workflowApprovers as$stepNumber => $approverId) {$insertCustomStep->execute([
                'ticket_id' => $ticketId,
                'step_number' => $stepNumber,
                'approver_user_id' => $approverId,
                'status_label' => "Pending Requester-Selected Approval — Level {$stepNumber}"
            ]);
        }
    }
    $pdo->commit();

    // Redirect to index page with tracking token
    header('Location: user_email_verification.php?pending_verification=1&email=' . urlencode($user_email));
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {$pdo->rollBack();
    }
    die('Database Error: ' . htmlspecialchars($e->getMessage()));
}