<?php
// ======================================================================
// Income Management
// ======================================================================

$pageTitle = 'Income Management - Ikimina';
$activePage = 'income';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$message = '';
$messageType = '';

// Handle Income POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_income'])) {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $message = "Security verification failed. Please try again.";
        $messageType = "error";
    } else {
        $source_id = filter_input(INPUT_POST, 'source_id', FILTER_VALIDATE_INT);
        $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        $rawAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $amount = ($rawAmount !== false) ? round((float)$rawAmount, 2) : false;
        $description = trim($_POST['description'] ?? '');
        $transaction_date = $_POST['transaction_date'] ?? '';

        // Validate date strictly with DateTime
        $dateObj = DateTime::createFromFormat('Y-m-d', $transaction_date);
        $validDate = ($dateObj && $dateObj->format('Y-m-d') === $transaction_date);

        if (!$source_id || !$account_id || $amount === false || $amount <= 0 || !$validDate) {
            $message = "Please enter valid income details. Amount must be greater than zero.";
            $messageType = "error";
        } else {
            // Verify income source exists
            $checkSource = $conn->prepare("SELECT id FROM income_sources WHERE id = ?");
            $checkSource->bind_param("i", $source_id);
            $checkSource->execute();

            // Verify financial account exists
            $checkAccount = $conn->prepare("SELECT id FROM financial_accounts WHERE id = ?");
            $checkAccount->bind_param("i", $account_id);
            $checkAccount->execute();

            if ($checkSource->get_result()->num_rows === 0 || $checkAccount->get_result()->num_rows === 0) {
                $message = "Invalid income source or financial account selected.";
                $messageType = "error";
            } else {
                $type = 'INCOME';
                $stmt = $conn->prepare("
                    INSERT INTO transactions
                    (source_id, transaction_type, amount, description, transaction_date, account_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("isdssi", $source_id, $type, $amount, $description, $transaction_date, $account_id);

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: income.php?saved=1");
                    exit;
                } else {
                    $message = "Unable to save income record.";
                    $messageType = "error";
                    $stmt->close();
                }
            }
            $checkSource->close();
            $checkAccount->close();
        }
    }
}

// Notifications
if (isset($_GET['saved'])) {
    $message = "Income recorded successfully!";
    $messageType = "success";
}

// Fetch income sources
$sources = $conn->query("SELECT id, source_name FROM income_sources ORDER BY source_name ASC");

// Fetch financial accounts
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

// Total income calculation
$totalResult = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE transaction_type = 'INCOME'
");
$totalIncome = (float)($totalResult ? $totalResult->fetch_assoc()['total'] : 0);

// Income totals grouped by source
$sourceTotals = $conn->query("
    SELECT s.source_name, COALESCE(SUM(t.amount), 0) AS total
    FROM income_sources s
    LEFT JOIN transactions t
      ON s.id = t.source_id
      AND t.transaction_type = 'INCOME'
    GROUP BY s.id, s.source_name
    ORDER BY s.source_name ASC
");

// Income transaction history
$history = $conn->query("
    SELECT t.id, t.amount, t.description, t.transaction_date,
           s.source_name, a.account_name
    FROM transactions t
    LEFT JOIN income_sources s ON t.source_id = s.id
    LEFT JOIN financial_accounts a ON t.account_id = a.id
    WHERE t.transaction_type = 'INCOME'
    ORDER BY t.transaction_date DESC, t.id DESC
");

require_once __DIR__ . '/includes/header.php';
?>

<!-- Stat Banner -->
<div class="stat-banner stat-banner-green">
    <span>Total Recorded Income</span>
    <strong><?= number_format($totalIncome) ?> RWF</strong>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Record Income Card -->
<div class="card">
    <div class="card-title">
        <span>+ Record New Income</span>
    </div>

    <form method="POST" action="income.php" class="form-grid">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="source_id">Income Source <span class="required">*</span></label>
            <select id="source_id" name="source_id" required>
                <option value="">Select income source</option>
                <?php while ($source = $sources->fetch_assoc()): ?>
                    <option value="<?= (int)$source['id'] ?>">
                        <?= htmlspecialchars($source['source_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="account_id">Receiving Account <span class="required">*</span></label>
            <select id="account_id" name="account_id" required>
                <option value="">Select account</option>
                <?php while ($account = $accounts->fetch_assoc()): ?>
                    <option value="<?= (int)$account['id'] ?>">
                        <?= htmlspecialchars($account['account_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="amount">Amount (RWF) <span class="required">*</span></label>
            <input type="number" id="amount" name="amount" min="0.01" step="0.01" inputmode="decimal" required
                   placeholder="Enter amount received">
        </div>

        <div class="form-group">
            <label for="transaction_date">Transaction Date <span class="required">*</span></label>
            <input type="date" id="transaction_date" name="transaction_date" required
                   value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="description">Description / Notes</label>
            <textarea id="description" name="description" rows="2"
                      placeholder="Additional information or notes..."></textarea>
        </div>

        <div class="form-group col-span-2">
            <button type="submit" name="save_income" class="btn btn-success">
                Save Income Record
            </button>
        </div>
    </form>
</div>

<!-- Income by Source Breakdown -->
<div class="card">
    <div class="card-title">
        <span>Income Breakdown by Source</span>
    </div>

    <div class="stat-grid" style="margin-bottom: 0;">
        <?php while ($item = $sourceTotals->fetch_assoc()): ?>
            <div class="stat-card" style="background: var(--bg-subtle);">
                <div class="stat-label"><?= htmlspecialchars($item['source_name']) ?></div>
                <div class="stat-value" style="font-size: 20px; color: var(--success);">
                    <?= number_format((float)$item['total']) ?> RWF
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<!-- Income Transaction History -->
<div class="card">
    <div class="card-title">
        <span>Income Transaction History</span>
    </div>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Source</th>
                    <th>Amount</th>
                    <th>Account</th>
                    <th>Date</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($history && $history->num_rows > 0): ?>
                    <?php while ($row = $history->fetch_assoc()): ?>
                        <tr>
                            <td data-label="ID">#<?= (int)$row['id'] ?></td>
                            <td data-label="Source"><strong><?= htmlspecialchars($row['source_name'] ?? 'Unknown') ?></strong></td>
                            <td data-label="Amount" class="amount-income">+ <?= number_format((float)$row['amount']) ?> RWF</td>
                            <td data-label="Account"><?= htmlspecialchars($row['account_name'] ?? 'Unknown') ?></td>
                            <td data-label="Date"><?= htmlspecialchars($row['transaction_date']) ?></td>
                            <td data-label="Description"><?= htmlspecialchars($row['description'] ?? '—') ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No income transactions recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
