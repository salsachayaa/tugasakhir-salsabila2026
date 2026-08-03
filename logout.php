<?php
require_once 'includes/functions.php';

// Log activity sebelum session dihancurkan (functions.php sudah menjalankan session_start())
if (isset($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], 'LOGOUT', 'Logout dari sistem');
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login
header('Location: login.php');
exit();
?>