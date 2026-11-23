<?php
// ======================================================================
// Universal Void Record Handler (Admin Only)
// Sets is_void = 1 with a required reason. Preserves historical records.
// ======================================================================

$pageTitle = 'Void Record - Ikimina';
$activePage = 'dashboard';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$currentUser = current_user();
$type = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING) ?? (filter_input(INPUT_POST, 'type', FILTER_SANITIZE_STRING) ?? '');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? (filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?? 0);

$allowedTypes = ['transaction', 'loan', 'repayment', 'transfer'];
if (!in_array($type, $allowedTypes, true) || $id < 1) {
    header("Location: index.php");
    exit;
}

$errorMessage = '';
$recordInfo = null;
$redirectUrl = 'index.php';

// Fetch target record details for preview
switch ($type) {
    case 'transaction':
        $stmt = $conn->prepare("
            SELECT t.*, s.source_name, c.category_name, a.account_name
            FROM transactions t
            LEFT JOIN income_sources s ON t.source_id = s.id
            LEFT JOIN expense_categories c ON t.expense_category_id = c.id
            LEFT JOIN financial_accounts a ON t.account_id = a.id
            WHERE t.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $recordInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($recordInfo) {
            $redirectUrl = ($recordInfo['transaction_type'] === 'INCOME') ? 'income.php' : 'expenses.php';
        }
        break;

    case 'loan':
        $stmt = $conn->prepare("
            SELECT l.*, m.full_name, a.account_name
            FROM loans l
            INNER JOIN members m ON l.member_id = m.id
            LEFT JOIN financial_accounts a ON l.disbursement_account_id = a.id
            WHERE l.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $recordInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $redirectUrl = 'loans.php';
        break;

    case 'repayment':
        $stmt = $conn->prepare("
            SELECT r.*, m.full_name, a.account_name
            FROM repayments r
            INNER JOIN loans l ON r.loan_id = l.id
            INNER JOIN members m ON l.member_id = m.id
            LEFT JOIN financial_accounts a ON r.account_id = a.id
            WHERE r.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $recordInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $redirectUrl = 'repayments.php';
        break;

    case 'transfer':
        $stmt = $conn->prepare("
            SELECT tr.*, f.account_name AS from_account, t.account_name AS to_account
            FROM transfers tr
            INNER JOIN financial_accounts f ON tr.from_account_id = f.id
            INNER JOIN financial_accounts t ON tr.to_account_id = t.id
            WHERE tr.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $recordInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $redirectUrl = 'transfers.php';
        break;
}

if (!$recordInfo) {
    header("Location: index.php");
    exit;
}

if ((int)$recordInfo['is_void'] === 1) {
    header("Location: {$redirectUrl}?already_void=1");
    exit;
}

// Process POST Void Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMessage = "Security verification failed. Please try again.";
    } else {
        $voidReason = trim($_POST['void_reason'] ?? '');
        if ($voidReason === '') {
            $errorMessage = "A reason is mandatory to void any financial record.";
        } else {
            $userId = $currentUser['id'];

            // Validation for loans: cannot void if non-voided repayments exist
            if ($type === 'loan') {
                $checkRepay = $conn->prepare("SELECT COUNT(*) AS total FROM repayments WHERE loan_id = ? AND is_void = 0");
                $checkRepay->bind_param("i", $id);
                $checkRepay->execute();
                $repayCount = (int)$checkRepay->get_result()->fetch_assoc()['total'];
                $checkRepay->close();

                if ($repayCount > 0) {
                    $errorMessage = "Cannot void this loan because active repayments exist for it. Please void the repayments first.";
                }
            }

            if ($errorMessage === '') {
                $table = ($type === 'transfer') ? 'transfers' : (($type === 'transaction') ? 'transactions' : (($type === 'loan') ? 'loans' : 'repayments'));
                $upStmt = $conn->prepare("
                    UPDATE {$table}
                    SET is_void = 1, void_reason = ?, voided_by = ?, voided_at = NOW()
                    WHERE id = ?
                ");
                $upStmt->bind_param("sii", $voidReason, $userId, $id);

                if ($upStmt->execute()) {
                    $upStmt->close();

                    // If repayment voided, update loan status
                    if ($type === 'repayment') {
                        $loanId = (int)$recordInfo['loan_id'];
                        // Recalculate remaining
                        $ls = $conn->prepare("
                            SELECT l.total_due, l.due_date, COALESCE(SUM(r.amount), 0) AS paid
                            FROM loans l
                            LEFT JOIN repayments r ON l.id = r.loan_id AND r.is_void = 0
                            WHERE l.id = ?
                            GROUP BY l.id, l.total_due, l.due_date
                        ");
                        $ls->bind_param("i", $loanId);
                        $ls->execute();
                        $lData = $ls->get_result()->fetch_assoc();
                        $ls->close();

                        if ($lData) {
                            $newPaid = (float)$lData['paid'];
                            $tot = (float)$lData['total_due'];
                            $newStatus = ($newPaid >= $tot) ? 'COMPLETED' : (($lData['due_date'] < date('Y-m-d')) ? 'OVERDUE' : 'ACTIVE');
                            $upL = $conn->prepare("UPDATE loans SET status = ? WHERE id = ?");
                            $upL->bind_param("si", $newStatus, $loanId);
                            $upL->execute();
                            $upL->close();
                        }
                    }

                    // Log activity
                    log_activity($conn, 'VOID', $type, $id, "Reason: {$voidReason}");

                    header("Location: {$redirectUrl}?voided=1");
                    exit;
                } else {
                    $errorMessage = "Database update failed: " . htmlspecialchars($conn->error);
                    $upStmt->close();
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width: 650px; margin: 0 auto;">
    <div class="card-title" style="color: var(--danger);">
        <span>⚠️ Void <?= ucfirst($type) ?> Record #<?= (int)$id ?></span>
        <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn btn-secondary btn-sm">Cancel</a>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-warning" style="display: block; line-height: 1.6;">
        <strong>Notice:</strong> Voiding a record marks it as canceled and removes its amount from all account balances and totals. The record will remain visible in the ledger marked as <code>VOIDED</code> for accountability and honesty.
    </div>

    <!-- Record summary box -->
    <div style="background: var(--bg-subtle); padding: 16px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 14px;">
        <h4 style="margin-bottom: 8px; color: var(--primary);">Record Details:</h4>
        <?php if ($type === 'transaction'): ?>
            <p><strong>Type:</strong> <?= htmlspecialchars($recordInfo['transaction_type']) ?></p>
            <p><strong>Category/Source:</strong> <?= htmlspecialchars($recordInfo['category_name'] ?? ($recordInfo['source_name'] ?? 'Other')) ?></p>
            <p><strong>Amount:</strong> <?= number_format((float)$recordInfo['amount']) ?> RWF</p>
            <p><strong>Account:</strong> <?= htmlspecialchars($recordInfo['account_name'] ?? '—') ?></p>
            <p><strong>Date:</strong> <?= htmlspecialchars($recordInfo['transaction_date']) ?></p>
            <p><strong>Description:</strong> <?= htmlspecialchars($recordInfo['description'] ?? '—') ?></p>
        <?php elseif ($type === 'loan'): ?>
            <p><strong>Member:</strong> <?= htmlspecialchars($recordInfo['full_name']) ?></p>
            <p><strong>Principal:</strong> <?= number_format((float)$recordInfo['principal']) ?> RWF</p>
            <p><strong>Interest:</strong> <?= number_format((float)$recordInfo['interest_amount']) ?> RWF</p>
            <p><strong>Total Due:</strong> <?= number_format((float)$recordInfo['total_due']) ?> RWF</p>
            <p><strong>Disbursement Account:</strong> <?= htmlspecialchars($recordInfo['account_name'] ?? '—') ?></p>
            <p><strong>Loan Date:</strong> <?= htmlspecialchars($recordInfo['loan_date']) ?></p>
        <?php elseif ($type === 'repayment'): ?>
            <p><strong>Member:</strong> <?= htmlspecialchars($recordInfo['full_name']) ?></p>
            <p><strong>Loan ID:</strong> #<?= (int)$recordInfo['loan_id'] ?></p>
            <p><strong>Amount Repaid:</strong> <?= number_format((float)$recordInfo['amount']) ?> RWF</p>
            <p><strong>Receiving Account:</strong> <?= htmlspecialchars($recordInfo['account_name'] ?? '—') ?></p>
            <p><strong>Payment Date:</strong> <?= htmlspecialchars($recordInfo['payment_date']) ?></p>
        <?php elseif ($type === 'transfer'): ?>
            <p><strong>From:</strong> <?= htmlspecialchars($recordInfo['from_account']) ?></p>
            <p><strong>To:</strong> <?= htmlspecialchars($recordInfo['to_account']) ?></p>
            <p><strong>Amount:</strong> <?= number_format((float)$recordInfo['amount']) ?> RWF</p>
            <p><strong>Date:</strong> <?= htmlspecialchars($recordInfo['transfer_date']) ?></p>
        <?php endif; ?>
    </div>

    <form method="POST" action="void_record.php?type=<?= urlencode($type) ?>&id=<?= (int)$id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="form-group" style="margin-bottom: 20px;">
            <label for="void_reason">Reason for Voiding <span class="required">*</span></label>
            <textarea id="void_reason" name="void_reason" rows="3" required
                      placeholder="Explain why this record is being voided (e.g., 'Duplicate entry entered by mistake', 'Wrong amount entered', etc.)..."><?= htmlspecialchars($_POST['void_reason'] ?? '') ?></textarea>
            <span class="input-hint">This reason will be permanently attached to the record for review.</span>
        </div>

        <div style="display: flex; gap: 12px;">
            <button type="submit" class="btn btn-danger">
                Confirm & Void Record
            </button>
            <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn btn-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
