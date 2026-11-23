<?php
// ======================================================================
// Delete Member Handler (Admin Role Only)
// ======================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Enforce login and Admin privileges
require_admin();

// Allow deletion only via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: members.php");
    exit;
}

// Verify CSRF token
$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
    header("Location: members.php?error=invalid_request");
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header("Location: members.php?error=invalid_id");
    exit;
}

// Check whether the member has any associated loans
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM loans WHERE member_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if ((int)$row['total'] > 0) {
    header("Location: members.php?error=has_loans");
    exit;
}

// Delete member record
$stmt = $conn->prepare("DELETE FROM members WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    header("Location: members.php?deleted=1");
} else {
    header("Location: members.php?error=delete_failed");
}
$stmt->close();

exit;
