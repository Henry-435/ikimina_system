<?php
// ======================================================================
// Transfers Management (Cash <-> Bank)
// ======================================================================

$pageTitle = 'Transfers Management - Ikimina';
$activePage = 'transfers';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$message = '';
$messageType = '';

// Handle Save Transfer POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_transfer'])) {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $message = "Security verification failed. Please try again.";
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
            $message = "Please enter valid transfer details. Source and destination accounts must be different.";
            $messageType = "error";
        } else {
            // Verify both accounts exist
            $check = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM financial_accounts
                WHERE id IN (?, ?)
            ");
            $check->bind_param("ii", $from_id, $to_id);
            $check->execute();
            $accountCount = (int)$check->get_result()->fetch_assoc()['total'];
            $check->close();

            if ($accountCount !== 2) {
                $message = "One or both selected financial accounts do not exist.";
                $messageType = "error";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO transfers (from_account_id, to_account_id, amount, transfer_date, description)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iidss", $from_id, $to_id, $amount, $transfer_date, $description);

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: transfers.php?saved=1");
                    exit;
                } else {
                    $message = "Unable to record account transfer.";
                    $messageType = "error";
                    $stmt->close();
                }
            }
        }
    }
}

// Notification
if (isset($_GET['saved'])) {
    $message = "Transfer recorded successfully!";
    $messageType = "success";
}

// Financial accounts list
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

// Total transferred amount
$totalResult = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM transfers");
$totalTransferred = (float)($totalResult ? $totalResult->fetch_assoc()['total'] : 0);

// Transfer transaction history
$history = $conn->query("
    SELECT t.id, t.amount, t.transfer_date, t.description,
           f.account_name AS from_account,
           a.account_name AS to_account
    FROM transfers t
    INNER JOIN financial_accounts f ON t.from_account_id = f.id
    INNER JOIN financial_accounts a ON t.to_account_id = a.id
    ORDER BY t.transfer_date DESC, t.id DESC
");

require_once __DIR__ . '/includes/header.php';
?>

<!-- Stat Banner -->
<div class="stat-banner stat-banner-purple">
    <span>Total Transfers Recorded</span>
    <strong><?= number_format($totalTransferred) ?> RWF</strong>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Record Transfer Card -->
<div class="card">
    <div class="card-title">
        <span>+ Record New Account Transfer</span>
    </div>

    <form method="POST" action="transfers.php" class="form-grid">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="from_account_id">Transfer From (Source Account) <span class="required">*</span></label>
            <select id="from_account_id" name="from_account_id" required>
                <option value="">Select source account</option>
                <?php while ($account = $accounts->fetch_assoc()): ?>
                    <option value="<?= (int)$account['id'] ?>">
                        <?= htmlspecialchars($account['account_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="to_account_id">Transfer To (Destination Account) <span class="required">*</span></label>
            <select id="to_account_id" name="to_account_id" required>
                <option value="">Select destination account</option>
                <?php 
                $accounts->data_seek(0);
                while ($account = $accounts->fetch_assoc()): 
                ?>
                    <option value="<?= (int)$account['id'] ?>">
                        <?= htmlspecialchars($account['account_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="amount">Amount (RWF) <span class="required">*</span></label>
            <input type="number" id="amount" name="amount" min="0.01" step="0.01" inputmode="decimal" required
                   placeholder="Enter transfer amount">
        </div>

        <div class="form-group">
            <label for="transfer_date">Transfer Date <span class="required">*</span></label>
            <input type="date" id="transfer_date" name="transfer_date" required
                   value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="description">Transfer Description / Reason</label>
            <textarea id="description" name="description" rows="2"
                      placeholder="e.g. Deposit cash to bank account or ATM withdrawal..."></textarea>
        </div>

        <div class="form-group col-span-2">
            <button type="submit" name="save_transfer" class="btn btn-primary" style="background: #6541a5;">
                Save Transfer Record
            </button>
        </div>
    </form>
</div>

<!-- Transfer History Table -->
<div class="card">
    <div class="card-title">
        <span>Account Transfer History</span>
    </div>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>From Account</th>
                    <th>To Account</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($history && $history->num_rows > 0): ?>
                    <?php while ($row = $history->fetch_assoc()): ?>
                        <tr>
                            <td data-label="ID">#<?= (int)$row['id'] ?></td>
                            <td data-label="From Account"><strong><?= htmlspecialchars($row['from_account']) ?></strong></td>
                            <td data-label="To Account"><strong><?= htmlspecialchars($row['to_account']) ?></strong></td>
                            <td data-label="Amount" class="amount-neutral">
                                <?= number_format((float)$row['amount']) ?> RWF
                            </td>
                            <td data-label="Date"><?= htmlspecialchars($row['transfer_date']) ?></td>
                            <td data-label="Description"><?= htmlspecialchars($row['description'] ?? '—') ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No account transfers recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
