<?php
// ======================================================================
// System Configuration Settings
// ======================================================================

// -----------------------------------------------------------------------
// DATABASE CREDENTIALS
// Update these values when deploying to hosting (InfinityFree, Hostinger, etc.)
//
// InfinityFree example:
//   DB_HOST  => 'sql312.infinityfree.com'   (from your hosting control panel)
//   DB_USER  => 'if9_12345678'              (your cPanel MySQL username)
//   DB_PASS  => 'yourpassword'              (the password you set)
//   DB_NAME  => 'if9_12345678_ikimina_db'   (your full database name)
//
// Local XAMPP:
//   DB_HOST  => 'localhost'
//   DB_USER  => 'root'
//   DB_PASS  => ''
//   DB_NAME  => 'ikimina_db'
// -----------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ikimina_db');

// Application Settings
define('APP_NAME', 'Ikimina Management System');
define('APP_CURRENCY', 'RWF');

// Security & Session Settings
define('SESSION_TIMEOUT', 1800);       // Inactivity timeout: 30 minutes in seconds
define('MAX_LOGIN_ATTEMPTS', 5);      // Brute-force threshold
define('LOCKOUT_DURATION', 600);      // Lockout duration: 10 minutes in seconds

// Error Logging Helper
function log_app_error(string $message): void {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/app_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] " . $message . PHP_EOL, FILE_APPEND);
}
