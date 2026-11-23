<?php
// ======================================================================
// Loans Management - Recording Ledger with Mistake Correction & Filters
// ======================================================================

$pageTitle = 'Loans Management - Ikimina';
$activePage = 'loans';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$currentUser = current_user();
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
            $checkMember = $conn->prepare("SELECT id, full_name FROM members WHERE id = ?");
            $checkMember->bind_param("i", $member_id);
            $checkMember->execute();
            $mRes = $checkMember->get_result();
            $memberData = $mRes->fetch_assoc();

            // Verify account exists
            $checkAccount = $conn->prepare("SELECT id, account_name FROM financial_accounts WHERE id = ?");
            $checkAccount->bind_param("i", $account_id);
            $checkAccount->execute();
            $accRes = $checkAccount->get_result();
            $accountData = $accRes->fetch_assoc();

            if (!$memberData || !$accountData) {
                $message = "Invalid member or disbursement account selected.";
                $messageType = "error";
            } else {
                $total_due = round($principal + $interest, 2);
                $status = 'ACTIVE';
                $createdBy = $currentUser['id'];

                // Balance check for soft warning
                $currentBal = get_account_balance($conn, $account_id);
                $projectedBal = $currentBal - $principal;

                $stmt = $conn->prepare("
                    INSERT INTO loans
                    (member_id, principal, interest_amount, total_due, loan_date, due_date, status, disbursement_account_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("idddsssii", $member_id, $principal, $interest, $total_due, $loan_date, $due_date, $status, $account_id, $createdBy);

                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    $stmt->close();
                    log_activity($conn, 'CREATE', 'loan', $newId, "Issued loan: {$principal} RWF to {$memberData['full_name']}");

                    if ($projectedBal < 0) {
                        header("Location: loans.php?saved=1&low_bal=1&acc=" . urlencode($accountData['account_name']) . "&bal=" . urlencode((string)$projectedBal));
                    } else {
                        header("Location: loans.php?saved=1");
                    }
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
    if (isset($_GET['low_bal'])) {
        $accName = htmlspecialchars($_GET['acc'] ?? 'Account');
        $bal = number_format((float)($_GET['bal'] ?? 0));
        $message = "Loan registered! ⚠️ Notice: {$accName} balance is currently negative ({$bal} RWF). Please verify pending cash collections.";
        $messageType = "warning";
    } else {
        $message = "Loan registered successfully!";
        $messageType = "success";
    }
} elseif (isset($_GET['updated'])) {
    $message = "Loan details updated successfully!";
    $messageType = "success";
} elseif (isset($_GET['voided'])) {
    $message = "Loan voided successfully and removed from active totals.";
    $messageType = "warning";
}

// Members & Accounts
$members = $conn->query("SELECT id, full_name FROM members ORDER BY full_name ASC");
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

// Loan summary stats (active only)
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
        WHERE is_void = 0
        GROUP BY loan_id
     ) r ON l.id = r.loan_id
     WHERE l.is_void = 0
");
$loanSummary = $summary ? $summary->fetch_assoc() : ['total_loans' => 0, 'total_due' => 0, 'outstanding' => 0];

// Filters for Loan Records
$filterFrom = trim($_GET['from_date'] ?? '');
$filterTo = trim($_GET['to_date'] ?? '');
$filterMember = filter_input(INPUT_GET, 'member_id', FILTER_VALIDATE_INT);
$showVoid = (isset($_GET['show_void']) && (int)$_GET['show_void'] === 1) ? 1 : 0;

$whereClause = "WHERE 1=1";
$params = [];
$types = "";

if ($showVoid === 0) {
    $whereClause .= " AND l.is_void = 0";
}

if ($filterFrom !== '') {
    $whereClause .= " AND l.loan_date >= ?";
    $params[] = $filterFrom;
    $types .= "s";
}

if ($filterTo !== '') {
    $whereClause .= " AND l.loan_date <= ?";
    $params[] = $filterTo;
    $types .= "s";
}

if ($filterMember) {
    $whereClause .= " AND l.member_id = ?";
    $params[] = $filterMember;
    $types .= "i";
}

$loanHistorySql = "
    SELECT
        l.id, l.member_id,
        m.full_name,
        l.principal,
        l.interest_amount,
        l.total_due,
        l.loan_date,
        l.due_date,
        l.is_void,
        l.void_reason,
        a.account_name,
        COALESCE(r.paid, 0) AS amount_paid,
        GREATEST(l.total_due - COALESCE(r.paid, 0), 0) AS remaining_balance,
        CASE
            WHEN l.is_void = 1 THEN 'VOIDED'
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
        WHERE is_void = 0
        GROUP BY loan_id
     ) r ON l.id = r.loan_id
     {$whereClause}
     ORDER BY l.id DESC
     LIMIT 200
";

$lhStmt = $conn->prepare($loanHistorySql);
if (!empty($params)) {
    $lhStmt->bind_param($types, ...$params);
}
$lhStmt->execute();
$loanHistory = $lhStmt->get_result();
$lhStmt->close();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Loan Statistics Grid -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Loans Issued (Active)</div>
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

<!-- Loan Records Table with Filters -->
<div class="card">
    <div class="card-title">
        <span>Loan Records Portfolio</span>
    </div>

    <!-- Filter Toolbar -->
    <form method="GET" action="loans.php" class="filter-card">
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
                <label for="f_mem">Member</label>
                <select id="f_mem" name="member_id">
                    <option value="">All Members</option>
                    <?php 
                    $members->data_seek(0);
                    while ($m = $members->fetch_assoc()): 
                    ?>
                        <option value="<?= (int)$m['id'] ?>" <?= $filterMember === (int)$m['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['full_name']) ?>
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
                <?php if ($filterFrom !== '' || $filterTo !== '' || $filterMember || $showVoid): ?>
                    <a href="loans.php" class="btn btn-secondary btn-sm">Reset</a>
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
                    <th>Principal</th>
                    <th>Interest</th>
                    <th>Total Due</th>
                    <th>Paid</th>
                    <th>Remaining</th>
                    <th>Loan Date</th>
                    <th>Due Date</th>
                    <th>Account</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($loanHistory && $loanHistory->num_rows > 0): ?>
                    <?php while ($loan = $loanHistory->fetch_assoc()): ?>
                        <?php $isVoided = ((int)$loan['is_void'] === 1); ?>
                        <tr class="<?= $isVoided ? 'row-voided' : '' ?>">
                            <td data-label="ID">#<?= (int)$loan['id'] ?></td>
                            <td data-label="Member">
                                <a href="member_statement.php?id=<?= (int)$loan['member_id'] ?>" style="color: var(--primary); font-weight: 600;">
                                    <?= htmlspecialchars($loan['full_name']) ?>
                                </a>
                            </td>
                            <td data-label="Principal"><?= number_format((float)$loan['principal']) ?> RWF</td>
                            <td data-label="Interest"><?= number_format((float)$loan['interest_amount']) ?> RWF</td>
                            <td data-label="Total Due"><strong><?= number_format((float)$loan['total_due']) ?> RWF</strong></td>
                            <td data-label="Paid" class="amount-income"><?= number_format((float)$loan['amount_paid']) ?> RWF</td>
                            <td data-label="Remaining" class="<?= $isVoided ? '' : 'amount-expense' ?>">
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
                            <td data-label="Actions">
                                <?php if (is_admin()): ?>
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <?php if (!$isVoided): ?>
                                            <a href="edit_loan.php?id=<?= (int)$loan['id'] ?>" class="btn btn-secondary btn-sm" title="Edit loan details">
                                                Edit
                                            </a>
                                            <a href="void_record.php?type=loan&id=<?= (int)$loan['id'] ?>" class="btn btn-danger btn-sm" title="Void loan">
                                                Void
                                            </a>
                                        <?php else: ?>
                                            <small style="color: var(--text-muted); font-size: 11px;">Voided: <?= htmlspecialchars($loan['void_reason'] ?? '') ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="12" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No loans match your search criteria.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
