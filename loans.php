<?php
// ======================================================================
// Loans Management
// ======================================================================

$pageTitle = 'Loans Management - Ikimina';
$activePage = 'loans';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$message = '';
$messageType = '';

// Handle Save Loan POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_loan'])) {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $message = "Security verification failed. Please try again.";
        $messageType = "error";
    } else {
        $member_id = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
        $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        
        $rawPrincipal = filter_input(INPUT_POST, 'principal', FILTER_VALIDATE_FLOAT);
        $principal = ($rawPrincipal !== false) ? round((float)$rawPrincipal, 2) : false;

        $rawInterest = filter_input(INPUT_POST, 'interest_amount', FILTER_VALIDATE_FLOAT);
        $interest = ($rawInterest !== false) ? round((float)$rawInterest, 2) : 0.00;

        $loan_date = $_POST['loan_date'] ?? '';
        $due_date = $_POST['due_date'] ?? '';

        $loanDateObj = DateTime::createFromFormat('Y-m-d', $loan_date);
        $dueDateObj = DateTime::createFromFormat('Y-m-d', $due_date);

        // Valid dates: both valid format, and due_date not before loan_date
        $validDates = $loanDateObj && $dueDateObj &&
            ($loanDateObj->format('Y-m-d') === $loan_date) &&
            ($dueDateObj->format('Y-m-d') === $due_date) &&
            ($due_date >= $loan_date);

        if (!$member_id || !$account_id || $principal === false || $principal <= 0 || $interest < 0 || !$validDates) {
            $message = "Please enter valid loan details. Due date cannot be earlier than the loan disbursement date.";
            $messageType = "error";
        } else {
            // Verify member exists
            $checkMember = $conn->prepare("SELECT id FROM members WHERE id = ?");
            $checkMember->bind_param("i", $member_id);
            $checkMember->execute();

            // Verify account exists
            $checkAccount = $conn->prepare("SELECT id FROM financial_accounts WHERE id = ?");
            $checkAccount->bind_param("i", $account_id);
            $checkAccount->execute();

            if ($checkMember->get_result()->num_rows === 0 || $checkAccount->get_result()->num_rows === 0) {
                $message = "Invalid member or disbursement account selected.";
                $messageType = "error";
            } else {
                $total_due = round($principal + $interest, 2);
                $status = 'ACTIVE';

                $stmt = $conn->prepare("
                    INSERT INTO loans
                    (member_id, principal, interest_amount, total_due, loan_date, due_date, status, disbursement_account_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("idddsssi", $member_id, $principal, $interest, $total_due, $loan_date, $due_date, $status, $account_id);

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: loans.php?saved=1");
                    exit;
                } else {
                    $message = "Unable to record the loan.";
                    $messageType = "error";
                    $stmt->close();
                }
            }
            $checkMember->close();
            $checkAccount->close();
        }
    }
}

// Notifications
if (isset($_GET['saved'])) {
    $message = "Loan registered successfully!";
    $messageType = "success";
}

// Members list
$members = $conn->query("SELECT id, full_name FROM members ORDER BY full_name ASC");

// Financial accounts
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

// Loan summary stats
$summary = $conn->query("
    SELECT
        COUNT(*) AS total_loans,
        COALESCE(SUM(l.total_due), 0) AS total_due,
        COALESCE(SUM(
            CASE WHEN COALESCE(r.paid, 0) < l.total_due
            THEN l.total_due - COALESCE(r.paid, 0)
            ELSE 0 END
        ), 0) AS outstanding
     FROM loans l
     LEFT JOIN (
        SELECT loan_id, SUM(amount) AS paid
        FROM repayments
        GROUP BY loan_id
     ) r ON l.id = r.loan_id
");
$loanSummary = $summary ? $summary->fetch_assoc() : ['total_loans' => 0, 'total_due' => 0, 'outstanding' => 0];

// Loan history list
$loanHistory = $conn->query("
    SELECT
        l.id,
        m.full_name,
        l.principal,
        l.interest_amount,
        l.total_due,
        l.loan_date,
        l.due_date,
        l.status,
        a.account_name,
        COALESCE(r.paid, 0) AS amount_paid,
        GREATEST(l.total_due - COALESCE(r.paid, 0), 0) AS remaining_balance,
        CASE
            WHEN l.total_due <= COALESCE(r.paid, 0) THEN 'COMPLETED'
            WHEN l.due_date < CURDATE() THEN 'OVERDUE'
            ELSE 'ACTIVE'
        END AS payment_status
     FROM loans l
     INNER JOIN members m ON l.member_id = m.id
     LEFT JOIN financial_accounts a ON l.disbursement_account_id = a.id
     LEFT JOIN (
        SELECT loan_id, SUM(amount) AS paid
        FROM repayments
        GROUP BY loan_id
     ) r ON l.id = r.loan_id
     ORDER BY l.id DESC
");

require_once __DIR__ . '/includes/header.php';
?>

<!-- Loan Statistics Grid -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Loans Registered</div>
        <div class="stat-value"><?= (int)$loanSummary['total_loans'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Amount Due</div>
        <div class="stat-value"><?= number_format((float)$loanSummary['total_due']) ?> RWF</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Outstanding</div>
        <div class="stat-value" style="color: var(--danger);"><?= number_format((float)$loanSummary['outstanding']) ?> RWF</div>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Register New Loan Card -->
<div class="card">
    <div class="card-title">
        <span>+ Issue / Register New Loan</span>
    </div>

    <form method="POST" action="loans.php" class="form-grid">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="member_id">Select Member <span class="required">*</span></label>
            <select id="member_id" name="member_id" required>
                <option value="">Choose member</option>
                <?php while ($member = $members->fetch_assoc()): ?>
                    <option value="<?= (int)$member['id'] ?>">
                        <?= htmlspecialchars($member['full_name']) ?> (#<?= (int)$member['id'] ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="account_id">Disbursement Account <span class="required">*</span></label>
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
            <label for="principal">Principal Amount (RWF) <span class="required">*</span></label>
            <input type="number" id="principal" name="principal" min="0.01" step="0.01" inputmode="decimal" required
                   placeholder="Enter borrowed principal">
        </div>

        <div class="form-group">
            <label for="interest_amount">Interest Amount (RWF)</label>
            <input type="number" id="interest_amount" name="interest_amount" min="0" step="0.01" inputmode="decimal"
                   value="0" required placeholder="Enter agreed interest">
        </div>

        <div class="form-group">
            <label for="loan_date">Loan Issue Date <span class="required">*</span></label>
            <input type="date" id="loan_date" name="loan_date" required
                   value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group">
            <label for="due_date">Repayment Due Date <span class="required">*</span></label>
            <!-- Bug 12 fixed: min=today removed to allow back-dated/historical loans -->
            <input type="date" id="due_date" name="due_date" required>
            <span class="input-hint">Must be on or after loan issue date</span>
        </div>

        <div class="form-group col-span-2">
            <button type="submit" name="save_loan" class="btn btn-primary">
                Save Loan Record
            </button>
        </div>
    </form>
</div>

<!-- Loan Records Table -->
<div class="card">
    <div class="card-title">
        <span>Loan Records Portfolio</span>
    </div>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Member</th>
                    <th>Principal</th>
                    <th>Interest</th>
                    <th>Total Due</th>
                    <th>Paid</th>
                    <th>Remaining</th>
                    <th>Loan Date</th>
                    <th>Due Date</th>
                    <th>Account</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($loanHistory && $loanHistory->num_rows > 0): ?>
                    <?php while ($loan = $loanHistory->fetch_assoc()): ?>
                        <tr>
                            <td data-label="ID">#<?= (int)$loan['id'] ?></td>
                            <td data-label="Member"><strong><?= htmlspecialchars($loan['full_name']) ?></strong></td>
                            <td data-label="Principal"><?= number_format((float)$loan['principal']) ?> RWF</td>
                            <td data-label="Interest"><?= number_format((float)$loan['interest_amount']) ?> RWF</td>
                            <td data-label="Total Due"><strong><?= number_format((float)$loan['total_due']) ?> RWF</strong></td>
                            <td data-label="Paid" class="amount-income"><?= number_format((float)$loan['amount_paid']) ?> RWF</td>
                            <td data-label="Remaining" class="amount-expense">
                                <strong><?= number_format((float)$loan['remaining_balance']) ?> RWF</strong>
                            </td>
                            <td data-label="Loan Date"><?= htmlspecialchars($loan['loan_date']) ?></td>
                            <td data-label="Due Date"><?= htmlspecialchars($loan['due_date']) ?></td>
                            <td data-label="Account"><?= htmlspecialchars($loan['account_name'] ?? 'Unknown') ?></td>
                            <td data-label="Status">
                                <span class="badge badge-<?= htmlspecialchars($loan['payment_status']) ?>">
                                    <?= htmlspecialchars($loan['payment_status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No loans recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
