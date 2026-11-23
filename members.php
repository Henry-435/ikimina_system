<?php
// ======================================================================
// Members Management
// ======================================================================

$pageTitle = 'Members Management - Ikimina';
$activePage = 'members';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$message = '';
$messageType = '';

// Handle Registration POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_member'])) {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $message = "Security verification failed. Please try again.";
        $messageType = "error";
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        // Validation
        if ($full_name === '') {
            $message = "Member full name is required.";
            $messageType = "error";
        } elseif ($phone !== '' && !preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
            $message = "Invalid phone number format. Please enter 9 to 15 digits (e.g., 078XXXXXXX or +25078XXXXXXX).";
            $messageType = "error";
        } else {
            // Check for duplicate name warning
            $checkName = $conn->prepare("SELECT id, phone FROM members WHERE LOWER(TRIM(full_name)) = LOWER(?) LIMIT 1");
            $checkName->bind_param("s", $full_name);
            $checkName->execute();
            $duplicateName = $checkName->get_result()->fetch_assoc();
            $checkName->close();

            // Check for duplicate phone warning
            $duplicatePhone = null;
            if ($phone !== '') {
                $checkPhone = $conn->prepare("SELECT id, full_name FROM members WHERE phone = ? LIMIT 1");
                $checkPhone->bind_param("s", $phone);
                $checkPhone->execute();
                $duplicatePhone = $checkPhone->get_result()->fetch_assoc();
                $checkPhone->close();
            }

            if ($duplicatePhone) {
                $message = "Warning: A member with phone number '{$phone}' already exists (#{$duplicatePhone['id']} - {$duplicatePhone['full_name']}).";
                $messageType = "error";
            } elseif ($duplicateName && empty($_POST['confirm_duplicate'])) {
                $message = "Warning: A member with the name '{$full_name}' already exists (#{$duplicateName['id']}). Please check if this is a duplicate before proceeding.";
                $messageType = "warning";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO members (full_name, phone, address)
                    VALUES (?, ?, ?)
                ");
                $stmt->bind_param("sss", $full_name, $phone, $address);

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: members.php?registered=1");
                    exit;
                } else {
                    $message = "Unable to register member. Please try again.";
                    $messageType = "error";
                    $stmt->close();
                }
            }
        }
    }
}

// Notifications handling
if (isset($_GET['registered'])) {
    $message = "Member registered successfully!";
    $messageType = "success";
} elseif (isset($_GET['updated'])) {
    $message = "Member information updated successfully!";
    $messageType = "success";
} elseif (isset($_GET['deleted'])) {
    $message = "Member deleted successfully!";
    $messageType = "success";
} elseif (isset($_GET['error'])) {
    $errCode = $_GET['error'];
    switch ($errCode) {
        case 'invalid_request':
            $message = "Security check failed. Please refresh and try again.";
            break;
        case 'invalid_id':
            $message = "Invalid member ID specified.";
            break;
        case 'has_loans':
            $message = "Unable to delete member: This member has active or past loan records attached.";
            break;
        case 'delete_failed':
            $message = "Unable to delete member. Database operation failed.";
            break;
        case 'permission_denied':
            $message = "Permission denied: Only Administrators can delete member records.";
            break;
        default:
            $message = "An unexpected error occurred.";
    }
    $messageType = "error";
}

// Search members
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $stmt = $conn->prepare("
        SELECT * FROM members
        WHERE full_name LIKE ? OR phone LIKE ?
        ORDER BY id DESC
    ");
    $searchTerm = "%{$search}%";
    $stmt->bind_param("ss", $searchTerm, $searchTerm);
    $stmt->execute();
    $members = $stmt->get_result();
} else {
    $members = $conn->query("SELECT * FROM members ORDER BY id DESC");
}

// Total members count
$totalResult = $conn->query("SELECT COUNT(*) AS total FROM members");
$totalMembers = (int)($totalResult ? $totalResult->fetch_assoc()['total'] : 0);

require_once __DIR__ . '/includes/header.php';
?>

<!-- Statistics Banner -->
<div class="stat-banner stat-banner-blue">
    <span>Total Registered Members</span>
    <strong><?= $totalMembers ?> Members</strong>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Register Member Card -->
<div class="card">
    <div class="card-title">
        <span>+ Register New Member</span>
    </div>

    <form method="POST" action="members.php" class="form-grid-3">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="full_name">Full Name <span class="required">*</span></label>
            <input type="text" id="full_name" name="full_name" required maxlength="150"
                   placeholder="e.g. Jean Pierre Habimana"
                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" maxlength="20"
                   placeholder="e.g. 0788123456"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            <span class="input-hint">Digits only, 9-15 characters</span>
        </div>

        <div class="form-group">
            <label for="address">Address / Location</label>
            <input type="text" id="address" name="address" maxlength="200"
                   placeholder="e.g. Kigali, Kicukiro"
                   value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
        </div>

        <?php if (!empty($duplicateName)): ?>
            <div class="form-group col-span-full">
                <label style="color: var(--warning-text); font-weight: bold;">
                    <input type="checkbox" name="confirm_duplicate" value="1" required>
                    I understand another member has this same name and want to proceed anyway.
                </label>
            </div>
        <?php endif; ?>

        <div class="form-group col-span-full">
            <button type="submit" name="register_member" class="btn btn-primary">
                Register Member
            </button>
        </div>
    </form>
</div>

<!-- Registered Members List Card -->
<div class="card">
    <div class="card-title">
        <span>Members Directory</span>
    </div>

    <!-- Search Bar -->
    <form method="GET" action="members.php" class="search-bar">
        <input type="text" name="search" class="search-input"
               placeholder="Search by name or phone..."
               value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search !== ''): ?>
            <a href="members.php" class="btn btn-secondary btn-sm">Clear Search</a>
        <?php endif; ?>
    </form>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Registered On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($members && $members->num_rows > 0): ?>
                    <?php while ($row = $members->fetch_assoc()): ?>
                        <tr>
                            <td data-label="ID">#<?= (int)$row['id'] ?></td>
                            <td data-label="Full Name"><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                            <td data-label="Phone"><?= htmlspecialchars($row['phone'] ?? '—') ?></td>
                            <td data-label="Address"><?= htmlspecialchars($row['address'] ?? '—') ?></td>
                            <td data-label="Registered On"><?= htmlspecialchars(substr($row['created_at'], 0, 10)) ?></td>
                            <td data-label="Actions">
                                <div style="display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;">
                                    <a href="member_statement.php?id=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm" title="View loans and repayment ledger">
                                        📜 Ledger
                                    </a>
                                    <a href="edit_member.php?id=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm">
                                        Edit
                                    </a>

                                    <?php if (is_admin()): ?>
                                        <form method="POST" action="delete_member.php" style="display: inline;"
                                              onsubmit="return confirm('Are you sure you want to delete this member? This action cannot be undone.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            <?= $search !== '' ? 'No members found matching your search.' : 'No members registered yet.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
