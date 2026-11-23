<?php
// ======================================================================
// Edit Member Information
// ======================================================================

$pageTitle = 'Edit Member - Ikimina';
$activePage = 'members';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$message = '';
$messageType = '';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header("Location: members.php?error=invalid_id");
    exit;
}

// Fetch member details
$stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    header("Location: members.php?error=invalid_id");
    exit;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $message = "Security verification failed. Please try again.";
        $messageType = "error";
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($full_name === '') {
            $message = "Member full name is required.";
            $messageType = "error";
        } elseif ($phone !== '' && !preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
            $message = "Invalid phone number format. Please enter 9 to 15 digits.";
            $messageType = "error";
        } else {
            $updateStmt = $conn->prepare("
                UPDATE members
                SET full_name = ?, phone = ?, address = ?
                WHERE id = ?
            ");
            $updateStmt->bind_param("sssi", $full_name, $phone, $address, $id);

            if ($updateStmt->execute()) {
                $updateStmt->close();
                header("Location: members.php?updated=1");
                exit;
            } else {
                $message = "Unable to update member record.";
                $messageType = "error";
                $updateStmt->close();
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-title">
        <span>Edit Member #<?= (int)$member['id'] ?></span>
        <a href="members.php" class="btn btn-secondary btn-sm">← Back to Members</a>
    </div>

    <form method="POST" action="edit_member.php?id=<?= (int)$member['id'] ?>" class="form-grid">
        <?= csrf_field() ?>

        <div class="form-group col-span-2">
            <label for="full_name">Full Name <span class="required">*</span></label>
            <input type="text" id="full_name" name="full_name" required maxlength="150"
                   value="<?= htmlspecialchars($_POST['full_name'] ?? $member['full_name']) ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" maxlength="20"
                   value="<?= htmlspecialchars($_POST['phone'] ?? ($member['phone'] ?? '')) ?>">
            <span class="input-hint">Digits only, 9-15 characters</span>
        </div>

        <div class="form-group col-span-2">
            <label for="address">Address / Location</label>
            <input type="text" id="address" name="address" maxlength="200"
                   value="<?= htmlspecialchars($_POST['address'] ?? ($member['address'] ?? '')) ?>">
        </div>

        <div class="form-group col-span-2" style="display: flex; gap: 12px; margin-top: 10px;">
            <button type="submit" class="btn btn-primary">
                Save Changes
            </button>
            <a href="members.php" class="btn btn-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
