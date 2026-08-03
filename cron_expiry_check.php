<?php
/**
 * cron_expiry_check.php
 * ============================================================
 * Script ini TIDAK dibuka lewat browser oleh user biasa.
 * Jalankan otomatis tiap hari lewat CRON JOB di server (bukan lewat session login).
 *
 * Fungsi:
 *  - Mengecek semua item di inventory_stock yang sudah kadaluarsa
 *    atau akan kadaluarsa dalam 30 hari (dan sisa stock > 0)
 *  - Mengirim email ke semua user role admin/pimpinan yang aktif
 *  - Mencatat pengiriman di tabel expiry_notifications supaya
 *    tidak mengirim ulang untuk item + status yang sama
 *
 * Cara setup CRON JOB (jalankan 1x sehari, misal jam 07:00):
 *   0 7 * * * /usr/bin/php /path/ke/project/cron_expiry_check.php >> /path/ke/project/cron_expiry_log.txt 2>&1
 *
 * Atau via cPanel > Cron Jobs, isi command:
 *   php -q /home/USERNAME/public_html/cron_expiry_check.php
 * ============================================================
 */

// Batasi akses: hanya boleh dijalankan dari CLI (cron), bukan dari browser.
// Jika ingin tetap bisa dites manual lewat browser, hapus/komentari blok if di bawah ini,
// tapi SANGAT disarankan tambahkan proteksi token/secret jika diakses via browser.
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['secret'] ?? '';
    $expectedSecret = defined('CRON_SECRET') ? CRON_SECRET : null;
    if (empty($expectedSecret) || $secret !== $expectedSecret) {
        http_response_code(403);
        die('Forbidden. Script ini hanya untuk dijalankan via CRON JOB.');
    }
}

require_once __DIR__ . '/includes/functions.php';

$startTime = date('Y-m-d H:i:s');
echo "=== Cron Expiry Check dimulai: $startTime ===\n";

try {
    $result = processExpiryNotifications(30);
    echo "Item diperiksa (kadaluarsa/akan kadaluarsa): {$result['checked']}\n";
    echo "Email notifikasi baru terkirim: {$result['emails_sent']}\n";
    echo "=== Selesai: " . date('Y-m-d H:i:s') . " ===\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log('cron_expiry_check.php error: ' . $e->getMessage());
}