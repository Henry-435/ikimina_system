<?php
// ======================================================================
// User Management (Admin Only)
// ======================================================================

$pageTitle = 'User Management - Ikimina';
$activePage = 'users';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Only Administrators can access this page
require_admin();

$currentUser = current_user();
$message = '';
$messageType = '';

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "Security check failed. Please try again.";
        $messageType = "error";
    } else {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? '') === 'Staff' ? 'Staff' : 'Admin';

        if ($username === '' || $fullName === '' || $password === '') {
            $message = "All fields are required.";
            $messageType = "error";
        } elseif (strlen($password) < 6) {
            $message = "Password must be at least 6 characters long.";
        } else {
            // Check username uniqueness
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $checkStmt->bind_param("s", $username);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $message = "The username '" . htmlspecialchars($username) . "' is already taken.";
                $messageType = "error";
                $checkStmt->close();
            } else {
                $checkStmt->close();
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $isActive = 1;

                $addStmt = $conn->prepare("
                    INSERT INTO users (username, full_name, password, role, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $addStmt->bind_param("ssssi", $username, $fullName, $hashed, $role, $isActive);

                if ($addStmt->execute()) {
                    $message = "User '{$username}' added successfully!";
                    $messageType = "success";
                } else {
                    $message = "Failed to add user.";
                    $messageType = "error";
                }
                $addStmt->close();
            }
        }
    }
}

// Handle Toggle Active/Disabled Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "Security check failed.";
        $messageType = "error";
    } else {
        $targetUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if (!$targetUserId) {
            $message = "Invalid user ID.";
            $messageType = "error";
        } elseif ($targetUserId === $currentUser['id']) {
            $message = "You cannot disable your own active account.";
            $messageType = "error";
        } else {
            $toggleStmt = $conn->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?");
            $toggleStmt->bind_param("i", $targetUserId);
            if ($toggleStmt->execute()) {
                $message = "User status updated successfully.";
                $messageType = "success";
            } else {
                $message = "Failed to update user status.";
                $messageType = "error";
            }
            $toggleStmt->close();
        }
    }
}

// Handle Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "Security check failed.";
        $messageType = "error";
    } else {
        $targetUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $newPassword = $_POST['new_password'] ?? '';

        if (!$targetUserId || strlen($newPassword) < 6) {
            $message = "Password must be at least 6 characters.";
            $messageType = "error";
        } else {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $resetStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $resetStmt->bind_param("si", $hashed, $targetUserId);
            if ($resetStmt->execute()) {
                $message = "User password reset successfully.";
                $messageType = "success";
            } else {
                $message = "Failed to reset password.";
                $messageType = "error";
            }
            $resetStmt->close();
        }
    }
}

// Retrieve all users
$usersResult = $conn->query("
    SELECT id, username, full_name, role, is_active, last_login, created_at
    FROM users
    ORDER BY id ASC
");

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Add User Card -->
<div class="card">
    <div class="card-title">
        <span>+ Add New User</span>
    </div>

    <form method="POST" action="users.php" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_user">

        <div class="form-group">
            <label for="username">Username <span class="required">*</span></label>
            <input type="text" id="username" name="username" required placeholder="e.g. Yvette_ineza">
        </div>

        <div class="form-group">
            <label for="full_name">Full Name <span class="required">*</span></label>
            <input type="text" id="full_name" name="full_name" required placeholder="e.g. Ineza Yvette">
        </div>

        <div class="form-group">
            <label for="password">Password <span class="required">*</span></label>
            <input type="password" id="password" name="password" required minlength="6" placeholder="Min 6 characters">
        </div>

        <div class="form-group">
            <label for="role">Role <span class="required">*</span></label>
            <select id="role" name="role" required>
                <option value="Staff">Staff (Can record, cannot delete)</option>
                <option value="Admin">Admin (Full privileges)</option>
            </select>
        </div>

        <div class="form-group col-span-2">
            <button type="submit" class="btn btn-primary">
                Save User
            </button>
        </div>
    </form>
</div>

<!-- Users List Card -->
<div class="card">
    <div class="card-title">
        <span>System Users</span>
    </div>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($u = $usersResult->fetch_assoc()): ?>
                    <tr>
                        <td data-label="ID">#<?= (int)$u['id'] ?></td>
                        <td data-label="Username"><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                        <td data-label="Full Name"><?= htmlspecialchars($u['full_name'] ?? '') ?></td>
                        <td data-label="Role">
                            <span class="user-role-badge role-<?= strtolower($u['role']) ?>">
                                <?= htmlspecialchars($u['role']) ?>
                            </span>
                        </td>
                        <td data-label="Status">
                            <span class="badge badge-<?= (int)$u['is_active'] === 1 ? 'active' : 'disabled' ?>">
                                <?= (int)$u['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td data-label="Last Login">
                            <?= !empty($u['last_login']) ? htmlspecialchars($u['last_login']) : 'Never' ?>
                        </td>
                        <td data-label="Actions">
                            <div style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
                                <!-- Toggle Active/Disabled Form -->
                                <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                                    <form method="POST" action="users.php" style="display: inline;"
                                          onsubmit="return confirm('Change status for this user?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">
                                            <?= (int)$u['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- Quick Reset Password Trigger/Form -->
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="let p = prompt('Enter new password for <?= htmlspecialchars($u['username']) ?> (min 6 chars):'); if(p && p.length >= 6){ document.getElementById('rp_pwd_<?= (int)$u['id'] ?>').value = p; document.getElementById('rp_form_<?= (int)$u['id'] ?>').submit(); } else if(p !== null) { alert('Password must be at least 6 characters.'); }">
                                    Reset Password
                                </button>
                                
                                <form id="rp_form_<?= (int)$u['id'] ?>" method="POST" action="users.php" style="display: none;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="new_password" id="rp_pwd_<?= (int)$u['id'] ?>" value="">
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
