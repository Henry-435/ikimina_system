<?php
// ======================================================================
// Transfers Management (Cash <-> Bank) - Ikimina Management System
// Digital Recording Ledger: Audit tracking, soft negative warning, filtering, Edit & Void
// ======================================================================

$pageTitle = 'Transfers Management - Ikimina';
$activePage = 'transfers';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
$currentUser = current_user();

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
            // Verify both accounts exist and fetch names
            $fromStmt = $conn->prepare("SELECT id, account_name FROM financial_accounts WHERE id = ?");
            $fromStmt->bind_param("i", $from_id);
            $fromStmt->execute();
            $fromAccount = $fromStmt->get_result()->fetch_assoc();
            $fromStmt->close();

            $toStmt = $conn->prepare("SELECT id, account_name FROM financial_accounts WHERE id = ?");
            $toStmt->bind_param("i", $to_id);
            $toStmt->execute();
            $toAccount = $toStmt->get_result()->fetch_assoc();
            $toStmt->close();

            if (!$fromAccount || !$toAccount) {
                $message = "One or both selected financial accounts do not exist.";
                $messageType = "error";
            } else {
                $createdBy = $currentUser['id'];

                // Check source balance for informative soft warning (Digital Recording Ledger principle)
                $currentBal = get_account_balance($conn, $from_id);
                $projectedBal = round($currentBal - $amount, 2);

                $stmt = $conn->prepare("
                    INSERT INTO transfers (from_account_id, to_account_id, amount, transfer_date, description, created_by, is_void)
                    VALUES (?, ?, ?, ?, ?, ?, 0)
                ");
                $stmt->bind_param("iidssi", $from_id, $to_id, $amount, $transfer_date, $description, $createdBy);

                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    $stmt->close();

                    log_activity(
                        $conn,
                        'CREATE',
                        'TRANSFER',
                        $newId,
                        "Transferred " . number_format($amount) . " RWF from {$fromAccount['account_name']} to {$toAccount['account_name']}"
                    );

                    if ($projectedBal < 0) {
                        header("Location: transfers.php?saved=1&low_bal=1&acc=" . urlencode($fromAccount['account_name']) . "&bal=" . urlencode((string)$projectedBal));
                    } else {
                        header("Location: transfers.php?saved=1");
                    }
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

// Notifications
if (isset($_GET['saved'])) {
    $message = "Transfer recorded successfully!";
    $messageType = "success";
    if (isset($_GET['low_bal']) && isset($_GET['acc']) && isset($_GET['bal'])) {
        $message .= " Notice: " . htmlspecialchars($_GET['acc']) . " balance is currently negative (" . number_format((float)$_GET['bal']) . " RWF). Please verify pending cash receipts or meeting contributions.";
        $messageType = "warning";
    }
} elseif (isset($_GET['updated'])) {
    $message = "Transfer record updated successfully!";
    $messageType = "success";
} elseif (isset($_GET['voided'])) {
    $message = "Transfer voided successfully and account balances restored.";
    $messageType = "warning";
}

// Financial accounts list
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

// Total transferred amount (active only)
$totalResult = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM transfers WHERE is_void = 0");
$totalTransferred = (float)($totalResult ? $totalResult->fetch_assoc()['total'] : 0);

// Filters for Transfer History
$filterFrom = trim($_GET['from_date'] ?? '');
$filterTo = trim($_GET['to_date'] ?? '');
$filterAccount = filter_input(INPUT_GET, 'account_id', FILTER_VALIDATE_INT);
$showVoid = (isset($_GET['show_void']) && (int)$_GET['show_void'] === 1) ? 1 : 0;

$whereClauses = ["1=1"];
$params = [];
$types = "";

if ($showVoid === 0) {
    $whereClauses[] = "t.is_void = 0";
}

if ($filterFrom !== '') {
    $whereClauses[] = "t.transfer_date >= ?";
    $params[] = $filterFrom;
    $types .= "s";
}

if ($filterTo !== '') {
    $whereClauses[] = "t.transfer_date <= ?";
    $params[] = $filterTo;
    $types .= "s";
}

if ($filterAccount) {
    $whereClauses[] = "(t.from_account_id = ? OR t.to_account_id = ?)";
    $params[] = $filterAccount;
    $params[] = $filterAccount;
    $types .= "ii";
}

$whereSql = implode(" AND ", $whereClauses);

$historyQuery = "
    SELECT t.id, t.amount, t.transfer_date, t.description, t.is_void, t.void_reason,
           f.account_name AS from_account,
           a.account_name AS to_account,
           u.full_name AS recorder_name
    FROM transfers t
    INNER JOIN financial_accounts f ON t.from_account_id = f.id
    INNER JOIN financial_accounts a ON t.to_account_id = a.id
    LEFT JOIN users u ON t.created_by = u.id
    WHERE $whereSql
    ORDER BY t.transfer_date DESC, t.id DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($historyQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $history = $stmt->get_result();
    $stmt->close();
} else {
    $history = $conn->query($historyQuery);
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Stat Banner -->
<div class="stat-banner stat-banner-purple">
    <span>Total Transfers Recorded (Active)</span>
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

<!-- Transfer History Table with Filters -->
<div class="card">
    <div class="card-title">
        <span>Account Transfer History</span>
    </div>

    <!-- Filter Toolbar -->
    <form method="GET" action="transfers.php" class="filter-card">
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
                <?php if ($filterFrom !== '' || $filterTo !== '' || $filterAccount || $showVoid): ?>
                    <a href="transfers.php" class="btn btn-secondary btn-sm">Reset</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>From Account</th>
                    <th>To Account</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Recorded By</th>
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
                            <td data-label="From Account"><strong><?= htmlspecialchars($row['from_account']) ?></strong></td>
                            <td data-label="To Account"><strong><?= htmlspecialchars($row['to_account']) ?></strong></td>
                            <td data-label="Amount" class="amount-neutral">
                                <?= number_format((float)$row['amount']) ?> RWF
                            </td>
                            <td data-label="Date"><?= htmlspecialchars($row['transfer_date']) ?></td>
                            <td data-label="Recorded By"><?= htmlspecialchars($row['recorder_name'] ?? 'System') ?></td>
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
                                            <a href="edit_transfer.php?id=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm" title="Edit this transfer">
                                                Edit
                                            </a>
                                            <a href="void_record.php?type=transfer&id=<?= (int)$row['id'] ?>" class="btn btn-danger btn-sm" title="Void this transfer">
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
                            No account transfers match your search criteria.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
