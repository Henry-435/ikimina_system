<?php
// ======================================================================
// Edit Repayment Record (Admin Only)
// ======================================================================

$pageTitle = 'Edit Repayment - Ikimina';
$activePage = 'repayments';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    header("Location: repayments.php");
    exit;
}

// Fetch repayment
$stmt = $conn->prepare("
    SELECT r.*, m.full_name, l.total_due, l.due_date
    FROM repayments r
    INNER JOIN loans l ON r.loan_id = l.id
    INNER JOIN members m ON l.member_id = m.id
    WHERE r.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$repayment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$repayment) {
    header("Location: repayments.php");
    exit;
}

if ((int)$repayment['is_void'] === 1) {
    die("This repayment is voided and cannot be edited.");
}

$loanId = (int)$repayment['loan_id'];

// Calculate sum of other active repayments for this loan
$otherStmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_other
    FROM repayments
    WHERE loan_id = ? AND id != ? AND is_void = 0
");
$otherStmt->bind_param("ii", $loanId, $id);
$otherStmt->execute();
$otherPaid = (float)$otherStmt->get_result()->fetch_assoc()['total_other'];
$otherStmt->close();

$totalDue = (float)$repayment['total_due'];
$maxAllowed = round($totalDue - $otherPaid, 2);

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "Security verification failed.";
        $messageType = "error";
    } else {
        $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        $rawAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $amount = ($rawAmount !== false) ? round((float)$rawAmount, 2) : false;
        $payment_date = $_POST['payment_date'] ?? '';
        $notes = trim($_POST['notes'] ?? '');

        $dateObj = DateTime::createFromFormat('Y-m-d', $payment_date);
        $validDate = ($dateObj && $dateObj->format('Y-m-d') === $payment_date);

        if (!$account_id || $amount === false || $amount <= 0 || !$validDate) {
            $message = "Please enter valid repayment information.";
            $messageType = "error";
        } elseif ($amount > $maxAllowed) {
            $message = "Repayment cannot exceed the remaining balance of " . number_format($maxAllowed, 2) . " RWF.";
            $messageType = "error";
        } else {
            $conn->begin_transaction();
            try {
                $up = $conn->prepare("
                    UPDATE repayments
                    SET account_id = ?, amount = ?, payment_date = ?, notes = ?
                    WHERE id = ?
                ");
                $up->bind_param("idssi", $account_id, $amount, $payment_date, $notes, $id);
                $up->execute();
                $up->close();

                // Recalculate loan status
                $newTotalPaid = $otherPaid + $amount;
                $newStatus = ($newTotalPaid >= $totalDue) ? 'COMPLETED' : (($repayment['due_date'] < date('Y-m-d')) ? 'OVERDUE' : 'ACTIVE');
                
                $upL = $conn->prepare("UPDATE loans SET status = ? WHERE id = ?");
                $upL->bind_param("si", $newStatus, $loanId);
                $upL->execute();
                $upL->close();

                $conn->commit();
                log_activity($conn, 'EDIT', 'repayment', $id, "Updated repayment on Loan #{$loanId} to {$amount} RWF");
                header("Location: repayments.php?updated=1");
                exit;

            } catch (Throwable $e) {
                $conn->rollback();
                $message = "Error saving changes: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

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
        <span>Edit Repayment #<?= (int)$id ?></span>
        <a href="repayments.php" class="btn btn-secondary btn-sm">Cancel</a>
    </div>

    <div style="background: var(--bg-subtle); padding: 14px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 14px;">
        <p><strong>Member:</strong> <?= htmlspecialchars($repayment['full_name']) ?></p>
        <p><strong>Loan ID:</strong> #<?= (int)$loanId ?></p>
        <p><strong>Total Due:</strong> <?= number_format($totalDue) ?> RWF</p>
        <p><strong>Max Allowed for this Payment:</strong> <?= number_format($maxAllowed, 2) ?> RWF</p>
    </div>

    <form method="POST" action="edit_repayment.php?id=<?= (int)$id ?>" class="form-grid">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="account_id">Receiving Account <span class="required">*</span></label>
            <select id="account_id" name="account_id" required>
                <?php while ($a = $accounts->fetch_assoc()): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$repayment['account_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['account_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="amount">Amount Repaid (RWF) <span class="required">*</span></label>
            <input type="number" id="amount" name="amount" min="0.01" max="<?= $maxAllowed ?>" step="0.01" inputmode="decimal" required
                   value="<?= htmlspecialchars($repayment['amount']) ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="payment_date">Payment Date <span class="required">*</span></label>
            <input type="date" id="payment_date" name="payment_date" required
                   value="<?= htmlspecialchars($repayment['payment_date']) ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="notes">Payment Notes</label>
            <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars($repayment['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-group col-span-2" style="display: flex; gap: 12px; margin-top: 10px;">
            <button type="submit" class="btn btn-primary">
                Save Repayment Changes
            </button>
            <a href="repayments.php" class="btn btn-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
