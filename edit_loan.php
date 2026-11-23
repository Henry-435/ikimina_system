<?php
// ======================================================================
// Edit Loan Record (Admin Only)
// ======================================================================

$pageTitle = 'Edit Loan - Ikimina';
$activePage = 'loans';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    header("Location: loans.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM loans WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    header("Location: loans.php");
    exit;
}

if ((int)$loan['is_void'] === 1) {
    die("This loan has been voided and cannot be edited.");
}

// Calculate how much has been repaid
$paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS paid FROM repayments WHERE loan_id = ? AND is_void = 0");
$paidStmt->bind_param("i", $id);
$paidStmt->execute();
$amountPaid = (float)$paidStmt->get_result()->fetch_assoc()['paid'];
$paidStmt->close();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "Security verification failed.";
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

        $validDates = $loanDateObj && $dueDateObj &&
            ($loanDateObj->format('Y-m-d') === $loan_date) &&
            ($dueDateObj->format('Y-m-d') === $due_date) &&
            ($due_date >= $loan_date);

        if (!$member_id || !$account_id || $principal === false || $principal <= 0 || $interest < 0 || !$validDates) {
            $message = "Please enter valid loan information. Due date cannot be before loan date.";
            $messageType = "error";
        } else {
            $total_due = round($principal + $interest, 2);

            // Cannot reduce total due below what is already repaid
            if ($total_due < $amountPaid) {
                $message = "Total Due (" . number_format($total_due) . " RWF) cannot be less than the amount already repaid (" . number_format($amountPaid) . " RWF).";
                $messageType = "error";
            } else {
                $newStatus = ($amountPaid >= $total_due) ? 'COMPLETED' : (($due_date < date('Y-m-d')) ? 'OVERDUE' : 'ACTIVE');

                $up = $conn->prepare("
                    UPDATE loans 
                    SET member_id = ?, disbursement_account_id = ?, principal = ?, interest_amount = ?, total_due = ?, loan_date = ?, due_date = ?, status = ?
                    WHERE id = ?
                ");
                $up->bind_param("iidddsssi", $member_id, $account_id, $principal, $interest, $total_due, $loan_date, $due_date, $newStatus, $id);

                if ($up->execute()) {
                    $up->close();
                    log_activity($conn, 'EDIT', 'loan', $id, "Updated loan: Principal={$principal}, Due={$total_due}");
                    header("Location: loans.php?updated=1");
                    exit;
                } else {
                    $message = "Failed to update loan record.";
                    $messageType = "error";
                    $up->close();
                }
            }
        }
    }
}

$members = $conn->query("SELECT id, full_name FROM members ORDER BY full_name ASC");
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 650px; margin: 0 auto;">
    <div class="card-title">
        <span>Edit Loan Record #<?= (int)$id ?></span>
        <a href="loans.php" class="btn btn-secondary btn-sm">Cancel</a>
    </div>

    <?php if ($amountPaid > 0): ?>
        <div class="alert alert-info" style="font-size: 13px;">
            Note: <strong><?= number_format($amountPaid) ?> RWF</strong> has already been repaid against this loan.
        </div>
    <?php endif; ?>

    <form method="POST" action="edit_loan.php?id=<?= (int)$id ?>" class="form-grid">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="member_id">Member <span class="required">*</span></label>
            <select id="member_id" name="member_id" required>
                <?php while ($m = $members->fetch_assoc()): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= (int)$loan['member_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['full_name']) ?> (#<?= (int)$m['id'] ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="account_id">Disbursement Account <span class="required">*</span></label>
            <select id="account_id" name="account_id" required>
                <?php while ($a = $accounts->fetch_assoc()): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$loan['disbursement_account_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['account_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="principal">Principal (RWF) <span class="required">*</span></label>
            <input type="number" id="principal" name="principal" min="0.01" step="0.01" inputmode="decimal" required
                   value="<?= htmlspecialchars($loan['principal']) ?>">
        </div>

        <div class="form-group">
            <label for="interest_amount">Interest (RWF)</label>
            <input type="number" id="interest_amount" name="interest_amount" min="0" step="0.01" inputmode="decimal" required
                   value="<?= htmlspecialchars($loan['interest_amount']) ?>">
        </div>

        <div class="form-group">
            <label for="loan_date">Loan Issue Date <span class="required">*</span></label>
            <input type="date" id="loan_date" name="loan_date" required
                   value="<?= htmlspecialchars($loan['loan_date']) ?>">
        </div>

        <div class="form-group">
            <label for="due_date">Repayment Due Date <span class="required">*</span></label>
            <input type="date" id="due_date" name="due_date" required
                   value="<?= htmlspecialchars($loan['due_date']) ?>">
        </div>

        <div class="form-group col-span-2" style="display: flex; gap: 12px; margin-top: 10px;">
            <button type="submit" class="btn btn-primary">
                Save Loan Changes
            </button>
            <a href="loans.php" class="btn btn-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
