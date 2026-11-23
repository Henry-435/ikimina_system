<?php
// ======================================================================
// Expenses Management - Recording Ledger with Mistake Correction & Filters
// ======================================================================

$pageTitle = 'Expenses Management - Ikimina';
$activePage = 'expenses';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$currentUser = current_user();
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
            $checkAccount = $conn->prepare("SELECT id, account_name FROM financial_accounts WHERE id = ?");
            $checkAccount->bind_param("i", $account_id);
            $checkAccount->execute();
            $accResult = $checkAccount->get_result();
            $accountData = $accResult->fetch_assoc();

            if ($checkCategory->get_result()->num_rows === 0 || !$accountData) {
                $message = "Invalid expense category or payment account selected.";
                $messageType = "error";
            } else {
                $type = 'EXPENSE';
                $createdBy = $currentUser['id'];

                // Check balance before commit for informative soft warning
                $currentBal = get_account_balance($conn, $account_id);
                $projectedBal = $currentBal - $amount;

                $stmt = $conn->prepare("
                    INSERT INTO transactions
                    (source_id, transaction_type, amount, description, transaction_date, account_id, expense_category_id, created_by)
                    VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sdssiii", $type, $amount, $description, $transaction_date, $account_id, $category_id, $createdBy);

                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    $stmt->close();
                    log_activity($conn, 'CREATE', 'transaction', $newId, "Recorded expense: {$amount} RWF ({$accountData['account_name']})");

                    if ($projectedBal < 0) {
                        header("Location: expenses.php?saved=1&low_bal=1&acc=" . urlencode($accountData['account_name']) . "&bal=" . urlencode((string)$projectedBal));
                    } else {
                        header("Location: expenses.php?saved=1");
                    }
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
    if (isset($_GET['low_bal'])) {
        $accName = htmlspecialchars($_GET['acc'] ?? 'Account');
        $bal = number_format((float)($_GET['bal'] ?? 0));
        $message = "Expense recorded! ⚠️ Notice: {$accName} balance is currently negative ({$bal} RWF). Please verify if cash receipts from recent meetings still need to be recorded.";
        $messageType = "warning";
    } else {
        $message = "Expense recorded successfully in the ledger!";
        $messageType = "success";
    }
} elseif (isset($_GET['updated'])) {
    $message = "Expense record updated successfully!";
    $messageType = "success";
} elseif (isset($_GET['voided'])) {
    $message = "Expense voided successfully and removed from active totals.";
    $messageType = "warning";
}

// Categories & Accounts
$categories = $conn->query("SELECT id, category_name FROM expense_categories ORDER BY category_name ASC");
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

// Total active expenses
$totalResult = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE transaction_type = 'EXPENSE' AND is_void = 0
");
$totalExpenses = (float)($totalResult ? $totalResult->fetch_assoc()['total'] : 0);

// Expenses grouped by category (active only)
$categoryTotals = $conn->query("
    SELECT c.category_name, COALESCE(SUM(t.amount), 0) AS total
    FROM expense_categories c
    LEFT JOIN transactions t
      ON c.id = t.expense_category_id
      AND t.transaction_type = 'EXPENSE'
      AND t.is_void = 0
    GROUP BY c.id, c.category_name
    ORDER BY c.category_name ASC
");

// Filters for Expense History
$filterFrom = trim($_GET['from_date'] ?? '');
$filterTo = trim($_GET['to_date'] ?? '');
$filterCategory = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT);
$filterAccount = filter_input(INPUT_GET, 'account_id', FILTER_VALIDATE_INT);
$showVoid = (isset($_GET['show_void']) && (int)$_GET['show_void'] === 1) ? 1 : 0;

$whereClause = "WHERE t.transaction_type = 'EXPENSE'";
$params = [];
$types = "";

if ($showVoid === 0) {
    $whereClause .= " AND t.is_void = 0";
}

if ($filterFrom !== '') {
    $whereClause .= " AND t.transaction_date >= ?";
    $params[] = $filterFrom;
    $types .= "s";
}

if ($filterTo !== '') {
    $whereClause .= " AND t.transaction_date <= ?";
    $params[] = $filterTo;
    $types .= "s";
}

if ($filterCategory) {
    $whereClause .= " AND t.expense_category_id = ?";
    $params[] = $filterCategory;
    $types .= "i";
}

if ($filterAccount) {
    $whereClause .= " AND t.account_id = ?";
    $params[] = $filterAccount;
    $types .= "i";
}

$historySql = "
    SELECT t.id, t.amount, t.description, t.transaction_date, t.is_void, t.void_reason,
           c.category_name, a.account_name, u.username AS creator_name
    FROM transactions t
    LEFT JOIN expense_categories c ON t.expense_category_id = c.id
    LEFT JOIN financial_accounts a ON t.account_id = a.id
    LEFT JOIN users u ON t.created_by = u.id
    {$whereClause}
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT 200
";

$hStmt = $conn->prepare($historySql);
if (!empty($params)) {
    $hStmt->bind_param($types, ...$params);
}
$hStmt->execute();
$history = $hStmt->get_result();
$hStmt->close();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Stat Banner -->
<div class="stat-banner stat-banner-red">
    <span>Total Recorded Expenses (Active)</span>
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

<!-- Expense History Table with Filters -->
<div class="card">
    <div class="card-title">
        <span>Expense Transaction History</span>
    </div>

    <!-- Filter Toolbar -->
    <form method="GET" action="expenses.php" class="filter-card">
        <div class="filter-grid">
            <div class="filter-item">
                <label for="f_from">From Date</label>
                <input type="date" id="f_from" name="from_date" value="<?= htmlspecialchars($filterFrom) ?>">
            </div>

            <div class="filter-item">
                <label for="f_to">To Date</label>
                <input type="date" id="f_to" name="to_date" value="<?= htmlspecialchars($filterTo) ?>">
            </div>

            <div class="filter-item">
                <label for="f_cat">Category</label>
                <select id="f_cat" name="category_id">
                    <option value="">All Categories</option>
                    <?php 
                    $categories->data_seek(0);
                    while ($c = $categories->fetch_assoc()): 
                    ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filterCategory === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['category_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="filter-item">
                <label for="f_acc">Account</label>
                <select id="f_acc" name="account_id">
                    <option value="">All Accounts</option>
                    <?php 
                    $accounts->data_seek(0);
                    while ($a = $accounts->fetch_assoc()): 
                    ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $filterAccount === (int)$a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['account_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="filter-item" style="min-width: 120px;">
                <label for="f_void">Status</label>
                <select id="f_void" name="show_void">
                    <option value="0" <?= $showVoid === 0 ? 'selected' : '' ?>>Active Only</option>
                    <option value="1" <?= $showVoid === 1 ? 'selected' : '' ?>>Include Voided</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($filterFrom !== '' || $filterTo !== '' || $filterCategory || $filterAccount || $showVoid): ?>
                    <a href="expenses.php" class="btn btn-secondary btn-sm">Reset</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

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
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($history && $history->num_rows > 0): ?>
                    <?php while ($row = $history->fetch_assoc()): ?>
                        <?php $isVoided = ((int)$row['is_void'] === 1); ?>
                        <tr class="<?= $isVoided ? 'row-voided' : '' ?>">
                            <td data-label="ID">#<?= (int)$row['id'] ?></td>
                            <td data-label="Category"><strong><?= htmlspecialchars($row['category_name'] ?? 'Unknown') ?></strong></td>
                            <td data-label="Amount" class="<?= $isVoided ? '' : 'amount-expense' ?>">
                                <?= $isVoided ? '' : '-' ?> <?= number_format((float)$row['amount']) ?> RWF
                            </td>
                            <td data-label="Account"><?= htmlspecialchars($row['account_name'] ?? 'Unknown') ?></td>
                            <td data-label="Date"><?= htmlspecialchars($row['transaction_date']) ?></td>
                            <td data-label="Description"><?= htmlspecialchars($row['description'] ?? '—') ?></td>
                            <td data-label="Status">
                                <?php if ($isVoided): ?>
                                    <span class="badge badge-VOIDED" title="Reason: <?= htmlspecialchars($row['void_reason'] ?? 'Voided') ?>">
                                        VOIDED
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-OVERDUE">PAID</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Actions">
                                <?php if (is_admin()): ?>
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <?php if (!$isVoided): ?>
                                            <a href="edit_transaction.php?id=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm" title="Edit this expense entry">
                                                Edit
                                            </a>
                                            <a href="void_record.php?type=transaction&id=<?= (int)$row['id'] ?>" class="btn btn-danger btn-sm" title="Void this entry">
                                                Void
                                            </a>
                                        <?php else: ?>
                                            <small style="color: var(--text-muted); font-size: 11px;">Voided: <?= htmlspecialchars($row['void_reason'] ?? '') ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No expenses match your search criteria.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
