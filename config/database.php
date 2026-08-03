<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'usermanagement');

// Create connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Email configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'salsachayaa@gmail.com');
define('SMTP_PASS', 'puqnbzmrngkqsvok'); // App Password Gmail
define('SMTP_FROM', 'salsachayaa@gmail.com');
define('SMTP_FROM_NAME', 'User Management System');

// Base URL
define('BASE_URL', 'http://localhost/usermanagement');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>