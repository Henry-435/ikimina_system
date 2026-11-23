<?php
// ======================================================================
// Database Connection Handler
// ======================================================================

require_once __DIR__ . '/config.php';

// Turn off default mysqli error reporting to user screens for security
mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    // Log actual technical error to file
    log_app_error("Database connection failed: " . $conn->connect_error);
    
    // Display generic safe message to users
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Error - Ikimina</title>
        <link rel="icon" type="image/png" href="assets/images/favicon.png">
        <link rel="shortcut icon" type="image/png" href="assets/images/favicon.png">
        <link rel="apple-touch-icon" href="assets/images/favicon.png">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6fa; color: #263238; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
            .error-card { background: #fff; max-width: 480px; width: 100%; padding: 32px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); text-align: center; }
            .error-card h1 { color: #b42318; font-size: 22px; margin-top: 0; margin-bottom: 12px; }
            .error-card p { color: #555; line-height: 1.5; margin-bottom: 24px; }
            .btn { display: inline-block; background: #123b35; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <h1>Database Connection Error</h1>
            <p>The system is currently unable to connect to the database. The issue has been logged. Please make sure MySQL is running in XAMPP and refresh.</p>
            <a href="index.php" class="btn">Retry</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$conn->set_charset("utf8mb4");
