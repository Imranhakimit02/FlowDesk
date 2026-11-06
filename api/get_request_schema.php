<?php
/**
 * File: api/get_request_schema.php
 * Purpose: API endpoint to fetch the JSON form schema for a selected request category.
 * Author: Imran IT Intern
 */

// -----------------------------
// Return JSON response headers
// -----------------------------
header('Content-Type: application/json; charset=utf-8');

// ---------------------------------
// Include your database connection
// ---------------------------------
require_once __DIR__ . '/../config/db.php';

// -------------------------------------------
// Validate type_id from GET query parameter
// -------------------------------------------
$typeId = isset($_GET['type_id']) ? filter_var($_GET['type_id'], FILTER_VALIDATE_INT) : false;

if (!$typeId) {
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid or missing Request Category ID.'
    ]);
    exit;
}

try {
    // Fetch type_name and form_schema for the requested type_id
    $stmt = $pdo->prepare("
        SELECT type_name, form_schema 
        FROM request_types 
        WHERE type_id = :type_id
    ");
    $stmt->execute(['type_id' => $typeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Decode stored JSON string from database column
        $schemaData = !empty($row['form_schema']) ? json_decode($row['form_schema'], true) : [];

        echo json_encode([
            'success'   => true,
            'type_name' => $row['type_name'],
            'schema'    => $schemaData
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error'   => 'Request category not found.'
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Database query error: ' . $e->getMessage()
    ]);
}