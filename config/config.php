<?php
// ======================================================================
// System Configuration Settings
// ======================================================================

// Database Credentials
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
