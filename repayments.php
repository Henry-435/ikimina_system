<?php
// ======================================================================
// Repayments Management
// Keeps existing transactional integrity, row locking and overpayment checks
// Fixes float rounding bug with round(..., 2)
// ======================================================================

$pageTitle = 'Repayments Management - Ikimina';
$activePage = 'repayments';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

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

                // Lock selected loan row for update
                $loanStmt = $conn->prepare("
                    SELECT id, total_due, due_date
                    FROM loans
                    WHERE id = ?
                    FOR UPDATE
                ");
                $loanStmt->bind_param("i", $loan_id);
                $loanStmt->execute();
                $loan = $loanStmt->get_result()->fetch_assoc();
                $loanStmt->close();

                if (!$loan) {
                    throw new Exception("The selected loan record does not exist.");
                }

                // Verify receiving financial account exists
                $accountStmt = $conn->prepare("SELECT id FROM financial_accounts WHERE id = ?");
                $accountStmt->bind_param("i", $account_id);
                $accountStmt->execute();
                if ($accountStmt->get_result()->num_rows === 0) {
                    $accountStmt->close();
                    throw new Exception("Invalid financial receiving account.");
                }
                $accountStmt->close();

                // Calculate amount already repaid on this loan
                $paidStmt = $conn->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS paid
                    FROM repayments
                    WHERE loan_id = ?
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

                // Prevent overpayment, accounting for exact rounding (Bug 6 fix)
                if ($amount > $remaining) {
                    throw new Exception(
                        "Repayment of " . number_format($amount, 2) . " RWF exceeds the remaining balance of " .
                        number_format($remaining, 2) . " RWF."
                    );
                }

                // Record repayment entry
                $insert = $conn->prepare("
                    INSERT INTO repayments (loan_id, amount, payment_date, notes, account_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insert->bind_param("idssi", $loan_id, $amount, $payment_date, $notes, $account_id);
                $insert->execute();
                $insert->close();

                // Update loan status column
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

// Notification
if (isset($_GET['saved'])) {
    $message = "Repayment recorded successfully!";
    $messageType = "success";
}

// Total repayments collected
$totalResult = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM repayments");
$totalRepaid = (float)($totalResult ? $totalResult->fetch_assoc()['total'] : 0);

// Total outstanding loans balance
$outstandingResult = $conn->query("
    SELECT COALESCE(SUM(
        GREATEST(l.total_due - COALESCE(r.paid, 0), 0)
    ), 0) AS outstanding
    FROM loans l
    LEFT JOIN (
        SELECT loan_id, SUM(amount) AS paid
        FROM repayments
        GROUP BY loan_id
    ) r ON l.id = r.loan_id
");
$totalOutstanding = (float)($outstandingResult ? $outstandingResult->fetch_assoc()['outstanding'] : 0);

// Active loans that still have a remaining balance
$loans = $conn->query("
    SELECT l.id, m.full_name, l.total_due,
           COALESCE(r.paid, 0) AS paid,
           GREATEST(l.total_due - COALESCE(r.paid, 0), 0) AS remaining
    FROM loans l
    INNER JOIN members m ON l.member_id = m.id
    LEFT JOIN (
        SELECT loan_id, SUM(amount) AS paid
        FROM repayments
        GROUP BY loan_id
    ) r ON l.id = r.loan_id
    WHERE l.total_due > COALESCE(r.paid, 0)
    ORDER BY m.full_name ASC
");

// Financial accounts
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

// Repayment history
$history = $conn->query("
    SELECT r.id, r.amount, r.payment_date, r.notes,
           m.full_name, l.id AS loan_number, a.account_name
    FROM repayments r
    INNER JOIN loans l ON r.loan_id = l.id
    INNER JOIN members m ON l.member_id = m.id
    LEFT JOIN financial_accounts a ON r.account_id = a.id
    ORDER BY r.payment_date DESC, r.id DESC
");

require_once __DIR__ . '/includes/header.php';
?>

<!-- Statistics Overview -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Money Repaid</div>
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
                <?php while ($loan = $loans->fetch_assoc()): ?>
                    <option value="<?= (int)$loan['id'] ?>">
                        <?= htmlspecialchars($loan['full_name']) ?> — Loan #<?= (int)$loan['id'] ?> — Remaining: <?= number_format((float)$loan['remaining'], 2) ?> RWF
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="account_id">Receiving Account <span class="required">*</span></label>
            <select id="account_id" name="account_id" required>
                <option value="">Choose Cash or Bank</option>
                <?php while ($account = $accounts->fetch_assoc()): ?>
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

<!-- Repayment History Table -->
<div class="card">
    <div class="card-title">
        <span>Repayment Transaction History</span>
    </div>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Member</th>
                    <th>Loan ID</th>
                    <th>Amount Repaid</th>
                    <th>Account</th>
                    <th>Payment Date</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($history && $history->num_rows > 0): ?>
                    <?php while ($row = $history->fetch_assoc()): ?>
                        <tr>
                            <td data-label="ID">#<?= (int)$row['id'] ?></td>
                            <td data-label="Member"><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                            <td data-label="Loan ID">#<?= (int)$row['loan_number'] ?></td>
                            <td data-label="Amount Repaid" class="amount-income">
                                + <?= number_format((float)$row['amount']) ?> RWF
                            </td>
                            <td data-label="Account"><?= htmlspecialchars($row['account_name'] ?? 'Unknown') ?></td>
                            <td data-label="Payment Date"><?= htmlspecialchars($row['payment_date']) ?></td>
                            <td data-label="Notes"><?= htmlspecialchars($row['notes'] ?? '—') ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No repayments recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
