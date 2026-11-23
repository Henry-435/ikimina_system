<?php
// ======================================================================
// Expenses Management
// ======================================================================

$pageTitle = 'Expenses Management - Ikimina';
$activePage = 'expenses';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$message = '';
$messageType = '';

// Handle Save Expense POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense'])) {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $message = "Security verification failed. Please try again.";
        $messageType = "error";
    } else {
        $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
        $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        $rawAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $amount = ($rawAmount !== false) ? round((float)$rawAmount, 2) : false;
        $description = trim($_POST['description'] ?? '');
        $transaction_date = $_POST['transaction_date'] ?? '';

        // Validate date strictly with DateTime
        $dateObj = DateTime::createFromFormat('Y-m-d', $transaction_date);
        $validDate = ($dateObj && $dateObj->format('Y-m-d') === $transaction_date);

        if (!$category_id || !$account_id || $amount === false || $amount <= 0 || !$validDate) {
            $message = "Please provide valid expense information. Amount must be greater than zero.";
            $messageType = "error";
        } else {
            // Verify category
            $checkCategory = $conn->prepare("SELECT id FROM expense_categories WHERE id = ?");
            $checkCategory->bind_param("i", $category_id);
            $checkCategory->execute();

            // Verify account
            $checkAccount = $conn->prepare("SELECT id FROM financial_accounts WHERE id = ?");
            $checkAccount->bind_param("i", $account_id);
            $checkAccount->execute();

            if ($checkCategory->get_result()->num_rows === 0 || $checkAccount->get_result()->num_rows === 0) {
                $message = "Invalid expense category or payment account selected.";
                $messageType = "error";
            } else {
                $type = 'EXPENSE';
                $stmt = $conn->prepare("
                    INSERT INTO transactions
                    (source_id, transaction_type, amount, description, transaction_date, account_id, expense_category_id)
                    VALUES (NULL, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sdssii", $type, $amount, $description, $transaction_date, $account_id, $category_id);

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: expenses.php?saved=1");
                    exit;
                } else {
                    $message = "Unable to record expense.";
                    $messageType = "error";
                    $stmt->close();
                }
            }
            $checkCategory->close();
            $checkAccount->close();
        }
    }
}

// Notifications
if (isset($_GET['saved'])) {
    $message = "Expense recorded successfully!";
    $messageType = "success";
}

// Expense categories
$categories = $conn->query("SELECT id, category_name FROM expense_categories ORDER BY category_name ASC");

// Financial accounts
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

// Total expenses
$totalResult = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE transaction_type = 'EXPENSE'
");
$totalExpenses = (float)($totalResult ? $totalResult->fetch_assoc()['total'] : 0);

// Expenses grouped by category
$categoryTotals = $conn->query("
    SELECT c.category_name, COALESCE(SUM(t.amount), 0) AS total
    FROM expense_categories c
    LEFT JOIN transactions t
      ON c.id = t.expense_category_id
      AND t.transaction_type = 'EXPENSE'
    GROUP BY c.id, c.category_name
    ORDER BY c.category_name ASC
");

// Expense transaction history
$history = $conn->query("
    SELECT t.id, t.amount, t.description, t.transaction_date,
           c.category_name, a.account_name
    FROM transactions t
    LEFT JOIN expense_categories c ON t.expense_category_id = c.id
    LEFT JOIN financial_accounts a ON t.account_id = a.id
    WHERE t.transaction_type = 'EXPENSE'
    ORDER BY t.transaction_date DESC, t.id DESC
");

require_once __DIR__ . '/includes/header.php';
?>

<!-- Stat Banner -->
<div class="stat-banner stat-banner-red">
    <span>Total Recorded Expenses</span>
    <strong><?= number_format($totalExpenses) ?> RWF</strong>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Record Expense Card -->
<div class="card">
    <div class="card-title">
        <span>+ Record New Expense</span>
    </div>

    <form method="POST" action="expenses.php" class="form-grid">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="category_id">Expense Category <span class="required">*</span></label>
            <select id="category_id" name="category_id" required>
                <option value="">Select category</option>
                <?php while ($category = $categories->fetch_assoc()): ?>
                    <option value="<?= (int)$category['id'] ?>">
                        <?= htmlspecialchars($category['category_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="account_id">Payment Account <span class="required">*</span></label>
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
                   placeholder="Enter expense amount">
        </div>

        <div class="form-group">
            <label for="transaction_date">Expense Date <span class="required">*</span></label>
            <input type="date" id="transaction_date" name="transaction_date" required
                   value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="description">Description / Purpose</label>
            <textarea id="description" name="description" rows="2"
                      placeholder="Explain what the money was spent on..."></textarea>
        </div>

        <div class="form-group col-span-2">
            <button type="submit" name="save_expense" class="btn btn-danger">
                Save Expense Record
            </button>
        </div>
    </form>
</div>

<!-- Expenses by Category Breakdown -->
<div class="card">
    <div class="card-title">
        <span>Expenses Breakdown by Category</span>
    </div>

    <div class="stat-grid" style="margin-bottom: 0;">
        <?php while ($item = $categoryTotals->fetch_assoc()): ?>
            <div class="stat-card" style="background: var(--bg-subtle);">
                <div class="stat-label"><?= htmlspecialchars($item['category_name']) ?></div>
                <div class="stat-value" style="font-size: 20px; color: var(--danger);">
                    <?= number_format((float)$item['total']) ?> RWF
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<!-- Expense History Table -->
<div class="card">
    <div class="card-title">
        <span>Expense Transaction History</span>
    </div>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Category</th>
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
                            <td data-label="Category"><strong><?= htmlspecialchars($row['category_name'] ?? 'Unknown') ?></strong></td>
                            <td data-label="Amount" class="amount-expense">- <?= number_format((float)$row['amount']) ?> RWF</td>
                            <td data-label="Account"><?= htmlspecialchars($row['account_name'] ?? 'Unknown') ?></td>
                            <td data-label="Date"><?= htmlspecialchars($row['transaction_date']) ?></td>
                            <td data-label="Description"><?= htmlspecialchars($row['description'] ?? '—') ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No expenses recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
