<?php
// ======================================================================
// Repayments Management - Ikimina Management System
// Keeps existing transactional integrity, row locking and overpayment checks
// Fixes float rounding bug with round(..., 2)
// Digital Recording Ledger: Audit tracking, filtering, Edit & Void workflows
// ======================================================================

$pageTitle = 'Repayments Management - Ikimina';
$activePage = 'repayments';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
$currentUser = current_user();

$message = '';
$messageType = '';

// Handle Save Repayment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_repayment'])) {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $message = "Security verification failed. Please try again.";
        $messageType = "error";
    } else {
        $loan_id = filter_input(INPUT_POST, 'loan_id', FILTER_VALIDATE_INT);
        $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        
        $rawAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $amount = ($rawAmount !== false) ? round((float)$rawAmount, 2) : false;

        $payment_date = $_POST['payment_date'] ?? '';
        $notes = trim($_POST['notes'] ?? '');

        $dateObj = DateTime::createFromFormat('Y-m-d', $payment_date);
        $validDate = ($dateObj && $dateObj->format('Y-m-d') === $payment_date);

        if (!$loan_id || !$account_id || $amount === false || $amount <= 0 || !$validDate) {
            $message = "Please enter valid repayment details. Amount must be greater than zero.";
            $messageType = "error";
        } else {
            try {
                // Begin database transaction for atomicity
                $conn->begin_transaction();

                // Lock selected loan row for update (must not be voided)
                $loanStmt = $conn->prepare("
                    SELECT l.id, l.member_id, l.total_due, l.due_date, l.is_void, m.full_name
                    FROM loans l
                    INNER JOIN members m ON l.member_id = m.id
                    WHERE l.id = ?
                    FOR UPDATE
                ");
                $loanStmt->bind_param("i", $loan_id);
                $loanStmt->execute();
                $loan = $loanStmt->get_result()->fetch_assoc();
                $loanStmt->close();

                if (!$loan || (int)$loan['is_void'] === 1) {
                    throw new Exception("The selected loan record does not exist or has been voided.");
                }

                // Verify receiving financial account exists
                $accountStmt = $conn->prepare("SELECT id, account_name FROM financial_accounts WHERE id = ?");
                $accountStmt->bind_param("i", $account_id);
                $accountStmt->execute();
                $accountRow = $accountStmt->get_result()->fetch_assoc();
                $accountStmt->close();

                if (!$accountRow) {
                    throw new Exception("Invalid financial receiving account.");
                }

                // Calculate amount already repaid on this loan (active non-void repayments only)
                $paidStmt = $conn->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS paid
                    FROM repayments
                    WHERE loan_id = ? AND is_void = 0
                ");
                $paidStmt->bind_param("i", $loan_id);
                $paidStmt->execute();
                $paid = round((float)$paidStmt->get_result()->fetch_assoc()['paid'], 2);
                $paidStmt->close();

                $totalDue = round((float)$loan['total_due'], 2);
                $remaining = round($totalDue - $paid, 2);

                if ($remaining <= 0) {
                    throw new Exception("This loan has already been fully repaid.");
                }

                // Prevent overpayment, accounting for exact rounding
                if ($amount > $remaining) {
                    throw new Exception(
                        "Repayment of " . number_format($amount, 2) . " RWF exceeds the remaining balance of " .
                        number_format($remaining, 2) . " RWF."
                    );
                }

                // Record repayment entry with audit info
                $createdBy = $currentUser['id'];
                $insert = $conn->prepare("
                    INSERT INTO repayments (loan_id, amount, payment_date, notes, account_id, created_by, is_void)
                    VALUES (?, ?, ?, ?, ?, ?, 0)
                ");
                $insert->bind_param("idssii", $loan_id, $amount, $payment_date, $notes, $account_id, $createdBy);
                $insert->execute();
                $newRepaymentId = $insert->insert_id;
                $insert->close();

                // Update loan status column live
                $newPaid = round($paid + $amount, 2);
                if ($newPaid >= $totalDue) {
                    $newStatus = 'COMPLETED';
                } elseif ($loan['due_date'] < date('Y-m-d')) {
                    $newStatus = 'OVERDUE';
                } else {
                    $newStatus = 'ACTIVE';
                }

                $update = $conn->prepare("UPDATE loans SET status = ? WHERE id = ?");
                $update->bind_param("si", $newStatus, $loan_id);
                $update->execute();
                $update->close();

                // Commit transaction
                $conn->commit();

                // Audit log entry
                log_activity(
                    $conn,
                    'CREATE',
                    'REPAYMENT',
                    $newRepaymentId,
                    "Recorded repayment of " . number_format($amount) . " RWF for Loan #{$loan_id} ({$loan['full_name']}) into {$accountRow['account_name']}"
                );

                header("Location: repayments.php?saved=1");
                exit;

            } catch (Throwable $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

// Notifications
if (isset($_GET['saved'])) {
    $message = "Repayment recorded successfully!";
    $messageType = "success";
} elseif (isset($_GET['updated'])) {
    $message = "Repayment record updated successfully!";
    $messageType = "success";
} elseif (isset($_GET['voided'])) {
    $message = "Repayment voided successfully and loan balance recalculated.";
    $messageType = "warning";
}

// Total repayments collected (active only)
$totalResult = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM repayments WHERE is_void = 0");
$totalRepaid = (float)($totalResult ? $totalResult->fetch_assoc()['total'] : 0);

// Total outstanding loans balance (inline query — no view dependency)
$outstandingResult = $conn->query("
    SELECT COALESCE(SUM(GREATEST(l.total_due - COALESCE(r.paid, 0), 0)), 0) AS outstanding
    FROM loans l
    LEFT JOIN (
        SELECT loan_id, SUM(amount) AS paid FROM repayments WHERE is_void = 0 GROUP BY loan_id
    ) r ON l.id = r.loan_id
    WHERE l.is_void = 0 AND l.total_due > COALESCE(r.paid, 0)
");
$totalOutstanding = (float)($outstandingResult ? $outstandingResult->fetch_assoc()['outstanding'] : 0);

// Active non-void loans that still have a remaining balance for the form dropdown
$loans = $conn->query("
    SELECT l.id, m.full_name, l.total_due,
           COALESCE(r.paid, 0) AS paid,
           GREATEST(l.total_due - COALESCE(r.paid, 0), 0) AS remaining
    FROM loans l
    INNER JOIN members m ON l.member_id = m.id
    LEFT JOIN (
        SELECT loan_id, SUM(amount) AS paid
        FROM repayments
        WHERE is_void = 0
        GROUP BY loan_id
    ) r ON l.id = r.loan_id
    WHERE l.is_void = 0 AND l.total_due > COALESCE(r.paid, 0)
    ORDER BY m.full_name ASC
");

// Financial accounts
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

// Filters for Repayment History
$filterFrom = trim($_GET['from_date'] ?? '');
$filterTo = trim($_GET['to_date'] ?? '');
$filterAccount = filter_input(INPUT_GET, 'account_id', FILTER_VALIDATE_INT);
$showVoid = (isset($_GET['show_void']) && (int)$_GET['show_void'] === 1) ? 1 : 0;

$whereClauses = ["1=1"];
$params = [];
$types = "";

if ($showVoid === 0) {
    $whereClauses[] = "r.is_void = 0";
}

if ($filterFrom !== '') {
    $whereClauses[] = "r.payment_date >= ?";
    $params[] = $filterFrom;
    $types .= "s";
}

if ($filterTo !== '') {
    $whereClauses[] = "r.payment_date <= ?";
    $params[] = $filterTo;
    $types .= "s";
}

if ($filterAccount) {
    $whereClauses[] = "r.account_id = ?";
    $params[] = $filterAccount;
    $types .= "i";
}

$whereSql = implode(" AND ", $whereClauses);

$historyQuery = "
    SELECT r.id, r.amount, r.payment_date, r.notes, r.is_void, r.void_reason,
           m.full_name, l.id AS loan_number, a.account_name,
           u.full_name AS recorder_name
    FROM repayments r
    INNER JOIN loans l ON r.loan_id = l.id
    INNER JOIN members m ON l.member_id = m.id
    LEFT JOIN financial_accounts a ON r.account_id = a.id
    LEFT JOIN users u ON r.created_by = u.id
    WHERE $whereSql
    ORDER BY r.payment_date DESC, r.id DESC
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

<!-- Statistics Overview -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Money Repaid (Active)</div>
        <div class="stat-value" style="color: var(--success);">
            <?= number_format($totalRepaid) ?> RWF
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Outstanding Balance</div>
        <div class="stat-value" style="color: var(--danger);">
            <?= number_format($totalOutstanding) ?> RWF
        </div>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Record Repayment Card -->
<div class="card">
    <div class="card-title">
        <span>+ Record New Repayment</span>
    </div>

    <form method="POST" action="repayments.php" class="form-grid">
        <?= csrf_field() ?>

        <div class="form-group col-span-2">
            <label for="loan_id">Select Member Loan <span class="required">*</span></label>
            <select id="loan_id" name="loan_id" required>
                <option value="">Choose loan to repay</option>
                <?php if ($loans && $loans->num_rows > 0): ?>
                    <?php while ($loan = $loans->fetch_assoc()): ?>
                        <option value="<?= (int)$loan['id'] ?>">
                            <?= htmlspecialchars($loan['full_name']) ?> — Loan #<?= (int)$loan['id'] ?> — Remaining: <?= number_format((float)$loan['remaining'], 2) ?> RWF
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="account_id">Receiving Account <span class="required">*</span></label>
            <select id="account_id" name="account_id" required>
                <option value="">Choose Cash or Bank</option>
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
            <label for="amount">Amount Repaid (RWF) <span class="required">*</span></label>
            <input type="number" id="amount" name="amount" min="0.01" step="0.01" inputmode="decimal" required
                   placeholder="Enter amount received">
        </div>

        <div class="form-group">
            <label for="payment_date">Payment Date <span class="required">*</span></label>
            <input type="date" id="payment_date" name="payment_date" required
                   value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="notes">Payment Notes</label>
            <textarea id="notes" name="notes" rows="2"
                      placeholder="Receipt number, bank transfer reference, or notes..."></textarea>
        </div>

        <div class="form-group col-span-2">
            <button type="submit" name="save_repayment" class="btn btn-success">
                Save Repayment Record
            </button>
        </div>
    </form>
</div>

<!-- Repayment History Table with Filters -->
<div class="card">
    <div class="card-title">
        <span>Repayment Transaction History</span>
    </div>

    <!-- Filter Toolbar -->
    <form method="GET" action="repayments.php" class="filter-card">
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
                    <a href="repayments.php" class="btn btn-secondary btn-sm">Reset</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Member</th>
                    <th>Loan</th>
                    <th>Amount Repaid</th>
                    <th>Account</th>
                    <th>Date</th>
                    <th>Recorded By</th>
                    <th>Notes</th>
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
                            <td data-label="Member"><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                            <td data-label="Loan">
                                <a href="loans.php" title="View loans">Loan #<?= (int)$row['loan_number'] ?></a>
                            </td>
                            <td data-label="Amount Repaid" class="<?= $isVoided ? '' : 'amount-income' ?>">
                                <?= $isVoided ? '' : '+' ?> <?= number_format((float)$row['amount']) ?> RWF
                            </td>
                            <td data-label="Account"><?= htmlspecialchars($row['account_name'] ?? 'Unknown') ?></td>
                            <td data-label="Date"><?= htmlspecialchars($row['payment_date']) ?></td>
                            <td data-label="Recorded By"><?= htmlspecialchars($row['recorder_name'] ?? 'System') ?></td>
                            <td data-label="Notes"><?= htmlspecialchars($row['notes'] ?? '—') ?></td>
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
                                            <a href="edit_repayment.php?id=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm" title="Edit this repayment">
                                                Edit
                                            </a>
                                            <a href="void_record.php?type=repayment&id=<?= (int)$row['id'] ?>" class="btn btn-danger btn-sm" title="Void this repayment">
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
                        <td colspan="10" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No repayments match your search criteria.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
