<?php
// ======================================================================
// Edit Account Transfer (Admin Only)
// ======================================================================

$pageTitle = 'Edit Transfer - Ikimina';
$activePage = 'transfers';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    header("Location: transfers.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM transfers WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$transfer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transfer) {
    header("Location: transfers.php");
    exit;
}

if ((int)$transfer['is_void'] === 1) {
    die("This transfer is voided and cannot be edited.");
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "Security verification failed.";
        $messageType = "error";
    } else {
        $from_id = filter_input(INPUT_POST, 'from_account_id', FILTER_VALIDATE_INT);
        $to_id = filter_input(INPUT_POST, 'to_account_id', FILTER_VALIDATE_INT);
        $rawAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $amount = ($rawAmount !== false) ? round((float)$rawAmount, 2) : false;
        $transfer_date = $_POST['transfer_date'] ?? '';
        $description = trim($_POST['description'] ?? '');

        $dateObj = DateTime::createFromFormat('Y-m-d', $transfer_date);
        $validDate = ($dateObj && $dateObj->format('Y-m-d') === $transfer_date);

        if (!$from_id || !$to_id || $from_id === $to_id || $amount === false || $amount <= 0 || !$validDate) {
            $message = "Please enter valid transfer details. Source and destination must be different.";
            $messageType = "error";
        } else {
            $up = $conn->prepare("
                UPDATE transfers
                SET from_account_id = ?, to_account_id = ?, amount = ?, transfer_date = ?, description = ?
                WHERE id = ?
            ");
            $up->bind_param("iidssi", $from_id, $to_id, $amount, $transfer_date, $description, $id);

            if ($up->execute()) {
                $up->close();
                log_activity($conn, 'EDIT', 'transfer', $id, "Updated transfer to {$amount} RWF on {$transfer_date}");
                header("Location: transfers.php?updated=1");
                exit;
            } else {
                $message = "Failed to update transfer.";
                $messageType = "error";
                $up->close();
            }
        }
    }
}

$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 650px; margin: 0 auto;">
    <div class="card-title">
        <span>Edit Account Transfer #<?= (int)$id ?></span>
        <a href="transfers.php" class="btn btn-secondary btn-sm">Cancel</a>
    </div>

    <form method="POST" action="edit_transfer.php?id=<?= (int)$id ?>" class="form-grid">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="from_account_id">Transfer From <span class="required">*</span></label>
            <select id="from_account_id" name="from_account_id" required>
                <?php while ($a = $accounts->fetch_assoc()): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$transfer['from_account_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['account_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="to_account_id">Transfer To <span class="required">*</span></label>
            <select id="to_account_id" name="to_account_id" required>
                <?php 
                $accounts->data_seek(0);
                while ($a = $accounts->fetch_assoc()): 
                ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$transfer['to_account_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['account_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="amount">Amount (RWF) <span class="required">*</span></label>
            <input type="number" id="amount" name="amount" min="0.01" step="0.01" inputmode="decimal" required
                   value="<?= htmlspecialchars($transfer['amount']) ?>">
        </div>

        <div class="form-group">
            <label for="transfer_date">Date <span class="required">*</span></label>
            <input type="date" id="transfer_date" name="transfer_date" required
                   value="<?= htmlspecialchars($transfer['transfer_date']) ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="description">Description / Notes</label>
            <textarea id="description" name="description" rows="2"><?= htmlspecialchars($transfer['description'] ?? '') ?></textarea>
        </div>

        <div class="form-group col-span-2" style="display: flex; gap: 12px; margin-top: 10px;">
            <button type="submit" class="btn btn-primary" style="background: #6541a5;">
                Save Transfer Changes
            </button>
            <a href="transfers.php" class="btn btn-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
