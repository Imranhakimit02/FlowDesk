<?php
/**
 * File: config\db.php
 * Purpose: Create a shared PDO connection for the whole application.
 * Author: Imran IT Intern
 *
 * This file centralizes database access so all modules can reuse the same
 * connection settings for the flowdeskdb database.
 */

// ----------------------------------
// SECTION: Database configuration
// ----------------------------------
$host = '127.0.0.1';
$dbname = 'flowdeskdb';
$username = 'root';
$password = '';
$charset = 'utf8mb4';

// ---------------------
// SECTION: Build DSN
// ---------------------
$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

// -----------------------
// SECTION: PDO options
// -----------------------
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// ----------------------------------
// SECTION: Connect to the database
// ----------------------------------
try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    die('<strong>Database connection failed:</strong> ' . $e->getMessage());
}

// -------------------------------------------------
// Supports same name repetition parameter in SQL
// -------------------------------------------------
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);