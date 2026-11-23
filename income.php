<?php
// ======================================================================
// Income Management - Recording Ledger with Mistake Correction & Filters
// ======================================================================

$pageTitle = 'Income Management - Ikimina';
$activePage = 'income';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$currentUser = current_user();
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
        $member_id = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
        if (!$member_id) { $member_id = null; }

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
                $createdBy = $currentUser['id'];

                $stmt = $conn->prepare("
                    INSERT INTO transactions
                    (source_id, transaction_type, amount, description, transaction_date, account_id, member_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("isdssiii", $source_id, $type, $amount, $description, $transaction_date, $account_id, $member_id, $createdBy);

                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    $stmt->close();
                    log_activity($conn, 'CREATE', 'transaction', $newId, "Recorded income: {$amount} RWF");
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
    $message = "Income recorded successfully in the ledger!";
    $messageType = "success";
} elseif (isset($_GET['updated'])) {
    $message = "Income record updated successfully!";
    $messageType = "success";
} elseif (isset($_GET['voided'])) {
    $message = "Transaction voided successfully and removed from active balances.";
    $messageType = "warning";
}

// Fetch income sources & accounts & members for form
$sources = $conn->query("SELECT id, source_name FROM income_sources ORDER BY source_name ASC");
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");
$members = $conn->query("SELECT id, full_name FROM members ORDER BY full_name ASC");

// Total active income calculation
$totalResult = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE transaction_type = 'INCOME' AND is_void = 0
");
$totalIncome = (float)($totalResult ? $totalResult->fetch_assoc()['total'] : 0);

// Income totals grouped by source (active only)
$sourceTotals = $conn->query("
    SELECT s.source_name, COALESCE(SUM(t.amount), 0) AS total
    FROM income_sources s
    LEFT JOIN transactions t
      ON s.id = t.source_id
      AND t.transaction_type = 'INCOME'
      AND t.is_void = 0
    GROUP BY s.id, s.source_name
    ORDER BY s.source_name ASC
");

// Filters for Transaction History
$filterFrom = trim($_GET['from_date'] ?? '');
$filterTo = trim($_GET['to_date'] ?? '');
$filterSource = filter_input(INPUT_GET, 'source_id', FILTER_VALIDATE_INT);
$filterAccount = filter_input(INPUT_GET, 'account_id', FILTER_VALIDATE_INT);
$showVoid = (isset($_GET['show_void']) && (int)$_GET['show_void'] === 1) ? 1 : 0;

$whereClause = "WHERE t.transaction_type = 'INCOME'";
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

if ($filterSource) {
    $whereClause .= " AND t.source_id = ?";
    $params[] = $filterSource;
    $types .= "i";
}

if ($filterAccount) {
    $whereClause .= " AND t.account_id = ?";
    $params[] = $filterAccount;
    $types .= "i";
}

$historySql = "
    SELECT t.id, t.amount, t.description, t.transaction_date, t.is_void, t.void_reason,
           s.source_name, a.account_name, m.full_name AS member_name, u.username AS creator_name
    FROM transactions t
    LEFT JOIN income_sources s ON t.source_id = s.id
    LEFT JOIN financial_accounts a ON t.account_id = a.id
    LEFT JOIN members m ON t.member_id = m.id
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
<div class="stat-banner stat-banner-green">
    <span>Total Recorded Income (Active)</span>
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
            <label for="member_id">Link to Member (Optional - e.g. Umugabane/Shares)</label>
            <select id="member_id" name="member_id">
                <option value="">None / General Income</option>
                <?php while ($member = $members->fetch_assoc()): ?>
                    <option value="<?= (int)$member['id'] ?>">
                        <?= htmlspecialchars($member['full_name']) ?> (#<?= (int)$member['id'] ?>)
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

        <div class="form-group col-span-2">
            <label for="transaction_date">Transaction Date <span class="required">*</span></label>
            <input type="date" id="transaction_date" name="transaction_date" required
                   value="<?= date('Y-m-d') ?>">
            <span class="input-hint">You can backdate to record income collected during past meetings.</span>
        </div>

        <div class="form-group col-span-2">
            <label for="description">Description / Notes</label>
            <textarea id="description" name="description" rows="2"
                      placeholder="e.g. Weekly canteen proceeds, or meeting contributions..."></textarea>
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

<!-- Income Transaction History with Filters -->
<div class="card">
    <div class="card-title">
        <span>Income Transaction History</span>
    </div>

    <!-- Filter Toolbar -->
    <form method="GET" action="income.php" class="filter-card">
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
                <label for="f_source">Source</label>
                <select id="f_source" name="source_id">
                    <option value="">All Sources</option>
                    <?php 
                    $sources->data_seek(0);
                    while ($s = $sources->fetch_assoc()): 
                    ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $filterSource === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['source_name']) ?>
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
                <?php if ($filterFrom !== '' || $filterTo !== '' || $filterSource || $filterAccount || $showVoid): ?>
                    <a href="income.php" class="btn btn-secondary btn-sm">Reset</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Source</th>
                    <th>Member</th>
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
                            <td data-label="Source"><strong><?= htmlspecialchars($row['source_name'] ?? 'Unknown') ?></strong></td>
                            <td data-label="Member"><?= htmlspecialchars($row['member_name'] ?? '—') ?></td>
                            <td data-label="Amount" class="<?= $isVoided ? '' : 'amount-income' ?>">
                                <?= $isVoided ? '' : '+' ?> <?= number_format((float)$row['amount']) ?> RWF
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
                                    <span class="badge badge-active">ACTIVE</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Actions">
                                <?php if (is_admin()): ?>
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <?php if (!$isVoided): ?>
                                            <a href="edit_transaction.php?id=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm" title="Edit this income entry">
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
                        <td colspan="9" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No income transactions match your criteria.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
