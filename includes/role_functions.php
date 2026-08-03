<?php
/**
 * role_functions.php
 * Kumpulan fungsi terkait role/hak akses user.
 * File ini di-require duluan dari functions.php.
 */

// Ambil role user yang sedang login (dari session -> DB)
function getCurrentUserRole() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    // Hindari query berulang dalam 1 request
    static $cachedRole = null;
    static $cachedUserId = null;

    if ($cachedRole !== null && $cachedUserId === $_SESSION['user_id']) {
        return $cachedRole;
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    $cachedUserId = $_SESSION['user_id'];
    $cachedRole = $row['role'] ?? null;

    return $cachedRole;
}

// Cek apakah role tertentu boleh mengelola data (tambah/edit/hapus)
// Sesuai komentar di dashboard.php: true untuk 'admin' dan 'pimpinan'
function userCanManage($role) {
    return in_array($role, ['admin', 'pimpinan'], true);
}

// Label tampilan untuk role (opsional, dipakai kalau butuh nama role yang rapi di UI)
function getRoleLabel($role) {
    $labels = [
        'admin'    => 'Administrator',
        'pimpinan' => 'Pimpinan',
        'staff'    => 'Staff',
    ];

    return $labels[$role] ?? ucfirst((string) $role);
}