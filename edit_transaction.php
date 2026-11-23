<?php
// ======================================================================
// Edit Transaction (Income or Expense) - Admin Only
// ======================================================================

$pageTitle = 'Edit Transaction - Ikimina';
$activePage = 'dashboard';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    header("Location: index.php");
    exit;
}

// Fetch transaction
$stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$tx = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tx) {
    header("Location: index.php");
    exit;
}

if ((int)$tx['is_void'] === 1) {
    die("This transaction is voided and cannot be edited. Its record is preserved for audit purposes.");
}

$isIncome = ($tx['transaction_type'] === 'INCOME');
$activePage = $isIncome ? 'income' : 'expenses';
$returnUrl = $isIncome ? 'income.php' : 'expenses.php';
$message = '';
$messageType = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "Security verification failed. Please try again.";
        $messageType = "error";
    } else {
        $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        $rawAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $amount = ($rawAmount !== false) ? round((float)$rawAmount, 2) : false;
        $transaction_date = $_POST['transaction_date'] ?? '';
        $description = trim($_POST['description'] ?? '');

        $dateObj = DateTime::createFromFormat('Y-m-d', $transaction_date);
        $validDate = ($dateObj && $dateObj->format('Y-m-d') === $transaction_date);

        if (!$account_id || $amount === false || $amount <= 0 || !$validDate) {
            $message = "Please enter valid transaction details.";
            $messageType = "error";
        } else {
            if ($isIncome) {
                $source_id = filter_input(INPUT_POST, 'source_id', FILTER_VALIDATE_INT);
                $member_id = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
                if (!$member_id) { $member_id = null; }

                if (!$source_id) {
                    $message = "Please select an income source.";
                    $messageType = "error";
                } else {
                    $up = $conn->prepare("
                        UPDATE transactions 
                        SET source_id = ?, account_id = ?, amount = ?, transaction_date = ?, description = ?, member_id = ?
                        WHERE id = ?
                    ");
                    $up->bind_param("iidssii", $source_id, $account_id, $amount, $transaction_date, $description, $member_id, $id);
                    if ($up->execute()) {
                        $up->close();
                        log_activity($conn, 'EDIT', 'transaction', $id, "Updated income to {$amount} RWF on {$transaction_date}");
                        header("Location: income.php?updated=1");
                        exit;
                    } else {
                        $message = "Failed to update income record.";
                        $messageType = "error";
                        $up->close();
                    }
                }
            } else {
                $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
                if (!$category_id) {
                    $message = "Please select an expense category.";
                    $messageType = "error";
                } else {
                    $up = $conn->prepare("
                        UPDATE transactions 
                        SET expense_category_id = ?, account_id = ?, amount = ?, transaction_date = ?, description = ?
                        WHERE id = ?
                    ");
                    $up->bind_param("iidssi", $category_id, $account_id, $amount, $transaction_date, $description, $id);
                    if ($up->execute()) {
                        $up->close();
                        log_activity($conn, 'EDIT', 'transaction', $id, "Updated expense to {$amount} RWF on {$transaction_date}");
                        header("Location: expenses.php?updated=1");
                        exit;
                    } else {
                        $message = "Failed to update expense record.";
                        $messageType = "error";
                        $up->close();
                    }
                }
            }
        }
    }
}

// Fetch categories or sources
$sources = $conn->query("SELECT id, source_name FROM income_sources ORDER BY source_name ASC");
$categories = $conn->query("SELECT id, category_name FROM expense_categories ORDER BY category_name ASC");
$accounts = $conn->query("SELECT id, account_name FROM financial_accounts ORDER BY account_name ASC");
$members = $conn->query("SELECT id, full_name FROM members ORDER BY full_name ASC");

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 650px; margin: 0 auto;">
    <div class="card-title">
        <span>Edit <?= $isIncome ? 'Income' : 'Expense' ?> Record #<?= (int)$id ?></span>
        <a href="<?= htmlspecialchars($returnUrl) ?>" class="btn btn-secondary btn-sm">Cancel</a>
    </div>

    <form method="POST" action="edit_transaction.php?id=<?= (int)$id ?>" class="form-grid">
        <?= csrf_field() ?>

        <?php if ($isIncome): ?>
            <div class="form-group">
                <label for="source_id">Income Source <span class="required">*</span></label>
                <select id="source_id" name="source_id" required>
                    <?php while ($s = $sources->fetch_assoc()): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)$tx['source_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['source_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="member_id">Link to Member (Optional)</label>
                <select id="member_id" name="member_id">
                    <option value="">None / General Income</option>
                    <?php while ($m = $members->fetch_assoc()): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= (int)$tx['member_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['full_name']) ?> (#<?= (int)$m['id'] ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="form-group col-span-2">
                <label for="category_id">Expense Category <span class="required">*</span></label>
                <select id="category_id" name="category_id" required>
                    <?php while ($c = $categories->fetch_assoc()): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$tx['expense_category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['category_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="account_id">Account <span class="required">*</span></label>
            <select id="account_id" name="account_id" required>
                <?php while ($a = $accounts->fetch_assoc()): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$tx['account_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['account_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="amount">Amount (RWF) <span class="required">*</span></label>
            <input type="number" id="amount" name="amount" min="0.01" step="0.01" inputmode="decimal" required
                   value="<?= htmlspecialchars($tx['amount']) ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="transaction_date">Date <span class="required">*</span></label>
            <input type="date" id="transaction_date" name="transaction_date" required
                   value="<?= htmlspecialchars($tx['transaction_date']) ?>">
        </div>

        <div class="form-group col-span-2">
            <label for="description">Description / Notes</label>
            <textarea id="description" name="description" rows="2"><?= htmlspecialchars($tx['description'] ?? '') ?></textarea>
        </div>

        <div class="form-group col-span-2" style="display: flex; gap: 12px; margin-top: 10px;">
            <button type="submit" class="btn btn-primary">
                Save Changes
            </button>
            <a href="<?= htmlspecialchars($returnUrl) ?>" class="btn btn-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
