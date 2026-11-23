<?php
// ======================================================================
// Authentication, Session Security, CSRF & Access Control
// ======================================================================

require_once __DIR__ . '/../config/config.php';

// Configure secure session cookie parameters before starting session
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// Inactivity Timeout Check (30 minutes)
if (!empty($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        // Session expired due to inactivity
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: login.php?timeout=1");
        exit;
    }
    // Refresh last activity timestamp
    $_SESSION['last_activity'] = time();
}

/**
 * Returns current CSRF token, generating one if needed.
 */
function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generates an HTML hidden input containing the CSRF token.
 */
function csrf_field(): string {
    $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verifies the provided CSRF token against session token.
 */
function verify_csrf_token(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Helper to check if a user is currently logged in.
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Helper to check if the current user has the Admin role.
 */
function is_admin(): bool {
    return is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'Admin';
}

/**
 * Returns current logged-in user array or null.
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'        => (int)$_SESSION['user_id'],
        'username'  => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User'),
        'role'      => $_SESSION['role'] ?? 'Staff',
    ];
}

/**
 * Enforces that user must be logged in. Redirects to login.php if not.
 */
function require_login(): void {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

/**
 * Enforces that user must be an Admin.
 * Displays a 403 Forbidden page or exits if the role is not Admin.
 */
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Denied - Ikimina</title>
            <link rel="icon" type="image/png" href="assets/images/favicon.png">
            <link rel="shortcut icon" type="image/png" href="assets/images/favicon.png">
            <link rel="apple-touch-icon" href="assets/images/favicon.png">
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6fa; color: #263238; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
                .denied-card { background: #fff; max-width: 480px; width: 100%; padding: 32px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); text-align: center; }
                .denied-card h1 { color: #b42318; font-size: 22px; margin-top: 0; }
                .denied-card p { color: #666; line-height: 1.5; margin-bottom: 24px; }
                .btn { display: inline-block; background: #123b35; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; }
            </style>
        </head>
        <body>
            <div class="denied-card">
                <h1>403 - Permission Denied</h1>
                <p>This action requires Administrator privileges. Your account does not have permission to view this page or perform this operation.</p>
                <a href="index.php" class="btn">Return to Dashboard</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

/**
 * Retrieve client IP address safely.
 */
function get_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return substr(trim($ip), 0, 45);
}

/**
 * Brute-force protection: Check if IP or username has exceeded maximum failed attempts within lockout window.
 */
function is_login_locked(mysqli $conn, string $ip, string $username): bool {
    $lockoutSeconds = LOCKOUT_DURATION;
    $maxAttempts = MAX_LOGIN_ATTEMPTS;

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM login_attempts
        WHERE (ip_address = ? OR username = ?)
          AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssi", $ip, $username, $lockoutSeconds);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($result['total'] ?? 0)) >= $maxAttempts;
}

/**
 * Record a failed login attempt in login_attempts table.
 */
function record_failed_login(mysqli $conn, string $ip, string $username): void {
    $stmt = $conn->prepare("
        INSERT INTO login_attempts (ip_address, username)
        VALUES (?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param("ss", $ip, $username);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Clear failed login attempts after successful authentication.
 */
function clear_failed_logins(mysqli $conn, string $ip, string $username): void {
    $stmt = $conn->prepare("
        DELETE FROM login_attempts
        WHERE ip_address = ? OR username = ?
    ");
    if ($stmt) {
        $stmt->bind_param("ss", $ip, $username);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Record an action into activity_logs table for audit trail.
 */
function log_activity(mysqli $conn, string $action, string $entity_type, int $entity_id, ?string $details = null): void {
    $userId = $_SESSION['user_id'] ?? null;
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param("issis", $userId, $action, $entity_type, $entity_id, $details);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Get current balance for a single account.
 * Uses an inline query so it works on shared hosts that deny CREATE VIEW.
 */
function get_account_balance(mysqli $conn, int $accountId): float {
    $stmt = $conn->prepare("
        SELECT
            a.opening_balance
            + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id AND t.transaction_type = 'INCOME'  AND t.is_void = 0), 0)
            - COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id AND t.transaction_type = 'EXPENSE' AND t.is_void = 0), 0)
            - COALESCE((SELECT SUM(tr.amount) FROM transfers tr WHERE tr.from_account_id = a.id AND tr.is_void = 0), 0)
            + COALESCE((SELECT SUM(tr.amount) FROM transfers tr WHERE tr.to_account_id   = a.id AND tr.is_void = 0), 0)
            - COALESCE((SELECT SUM(l.principal) FROM loans l WHERE l.disbursement_account_id = a.id AND l.is_void = 0), 0)
            + COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.account_id = a.id AND r.is_void = 0), 0)
            AS current_balance
        FROM financial_accounts a
        WHERE a.id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $accountId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return round((float)($res['current_balance'] ?? 0.00), 2);
    }
    return 0.00;
}

/**
 * Get all account balances as an array keyed by account_id.
 * Uses inline SQL — works with or without the financial_summary view.
 */
function get_all_account_balances(mysqli $conn): array {
    $result = $conn->query("
        SELECT
            a.id AS account_id,
            a.account_name,
            a.opening_balance
            + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id AND t.transaction_type = 'INCOME'  AND t.is_void = 0), 0)
            - COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id AND t.transaction_type = 'EXPENSE' AND t.is_void = 0), 0)
            - COALESCE((SELECT SUM(tr.amount) FROM transfers tr WHERE tr.from_account_id = a.id AND tr.is_void = 0), 0)
            + COALESCE((SELECT SUM(tr.amount) FROM transfers tr WHERE tr.to_account_id   = a.id AND tr.is_void = 0), 0)
            - COALESCE((SELECT SUM(l.principal) FROM loans l WHERE l.disbursement_account_id = a.id AND l.is_void = 0), 0)
            + COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.account_id = a.id AND r.is_void = 0), 0)
            AS current_balance
        FROM financial_accounts a
        ORDER BY a.id ASC
    ");
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['current_balance'] = round((float)$row['current_balance'], 2);
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Get the overall financial summary totals.
 * Uses inline SQL — works with or without the overall_financial_summary view.
 */
function get_overall_summary(mysqli $conn): array {
    // Total income and expenses from transactions
    $txResult = $conn->query("
        SELECT
            COALESCE(SUM(CASE WHEN transaction_type = 'INCOME'  AND is_void = 0 THEN amount ELSE 0 END), 0) AS total_income,
            COALESCE(SUM(CASE WHEN transaction_type = 'EXPENSE' AND is_void = 0 THEN amount ELSE 0 END), 0) AS total_expenses
        FROM transactions
    ");
    $tx = $txResult ? $txResult->fetch_assoc() : ['total_income' => 0, 'total_expenses' => 0];

    // Total loans and repayments
    $loanResult = $conn->query("
        SELECT
            COALESCE(SUM(CASE WHEN is_void = 0 THEN total_due ELSE 0 END), 0) AS total_loans
        FROM loans
    ");
    $loans = $loanResult ? $loanResult->fetch_assoc() : ['total_loans' => 0];

    $repResult = $conn->query("
        SELECT COALESCE(SUM(CASE WHEN is_void = 0 THEN amount ELSE 0 END), 0) AS total_repaid FROM repayments
    ");
    $rep = $repResult ? $repResult->fetch_assoc() : ['total_repaid' => 0];

    // Outstanding: active + overdue loans remaining balance
    $outResult = $conn->query("
        SELECT COALESCE(SUM(GREATEST(l.total_due - COALESCE(r.paid, 0), 0)), 0) AS outstanding
        FROM loans l
        LEFT JOIN (
            SELECT loan_id, SUM(amount) AS paid FROM repayments WHERE is_void = 0 GROUP BY loan_id
        ) r ON l.id = r.loan_id
        WHERE l.is_void = 0 AND l.total_due > COALESCE(r.paid, 0)
    ");
    $out = $outResult ? $outResult->fetch_assoc() : ['outstanding' => 0];

    // All balances to sum for total_cash_and_bank
    $balances = get_all_account_balances($conn);
    $totalCashBank = array_sum(array_column($balances, 'current_balance'));

    return [
        'total_cash_and_bank' => round($totalCashBank, 2),
        'total_income'        => round((float)$tx['total_income'], 2),
        'total_expenses'      => round((float)$tx['total_expenses'], 2),
        'total_loans'         => round((float)$loans['total_loans'], 2),
        'total_repaid'        => round((float)$rep['total_repaid'], 2),
        'outstanding_loans'   => round((float)$out['outstanding'], 2),
    ];
}

