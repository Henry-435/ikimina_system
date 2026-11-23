<?php
// ======================================================================
// User Login Page with Brute-Force & Session Security
// ======================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect to dashboard if already logged in
if (is_logged_in()) {
    header("Location: index.php");
    exit;
}

$errorMessage = '';
$successMessage = '';

if (isset($_GET['logged_out'])) {
    $successMessage = "You have been logged out successfully.";
} elseif (isset($_GET['timeout'])) {
    $errorMessage = "Your session expired after 30 minutes of inactivity. Please log in again.";
}

// Check if no users exist in the system, to guide first-time setup
$userCountCheck = $conn->query("SELECT COUNT(*) AS total FROM users");
$totalUsers = (int)($userCountCheck ? $userCountCheck->fetch_assoc()['total'] : 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($token)) {
        $errorMessage = "Security verification failed. Please refresh and try again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = get_client_ip();

        if ($username === '' || $password === '') {
            $errorMessage = "Please enter both username and password.";
        } else {
            // Check brute-force lockout status
            if (is_login_locked($conn, $ip, $username)) {
                $errorMessage = "Too many failed login attempts. This account/IP has been locked for 10 minutes for security.";
            } else {
                $stmt = $conn->prepare("
                    SELECT id, username, full_name, password, role, is_active
                    FROM users
                    WHERE username = ?
                    LIMIT 1
                ");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();

                if ($user && password_verify($password, $user['password'])) {
                    if ((int)$user['is_active'] !== 1) {
                        $errorMessage = "This account has been disabled. Please contact the administrator.";
                    } else {
                        // Successful login: clear failed attempts
                        clear_failed_logins($conn, $ip, $username);

                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['last_activity'] = time();

                        // Update last login timestamp
                        $upStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        if ($upStmt) {
                            $upStmt->bind_param("i", $user['id']);
                            $upStmt->execute();
                            $upStmt->close();
                        }

                        header("Location: index.php");
                        exit;
                    }
                } else {
                    // Record failed attempt
                    record_failed_login($conn, $ip, $username);
                    $errorMessage = "Invalid username or password.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Ikimina Management System</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="assets/images/favicon.png">
    <link rel="apple-touch-icon" href="assets/images/favicon.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">

<div class="login-card">
    <div class="login-header">
        <img src="assets/images/favicon.png" alt="Logo" style="width: 64px; height: 64px; object-fit: contain; margin-bottom: 8px;">
        <h1>Ikimina</h1>
        <p>Treasurer Financial Portal</p>
    </div>

    <?php if ($totalUsers === 0): ?>
        <div class="alert alert-warning">
            No administrator account exists yet. <br>
            <a href="setup_admin.php" style="color: var(--primary); font-weight: bold; text-decoration: underline;">
                Click here to run setup_admin.php
            </a>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($successMessage) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <?= csrf_field() ?>

        <div class="form-group" style="margin-bottom: 18px;">
            <label for="username">Username <span class="required">*</span></label>
            <input type="text" id="username" name="username" required autocomplete="username" autofocus
                   placeholder="Enter your username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>

        <div class="form-group" style="margin-bottom: 24px;">
            <label for="password">Password <span class="required">*</span></label>
            <input type="password" id="password" name="password" required autocomplete="current-password"
                   placeholder="Enter your password">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%;">
            Sign In
        </button>
    </form>
</div>

</body>
</html>
