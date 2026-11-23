<?php
// ======================================================================
// Member Statement & Ledger View (Printable)
// Shows member profile, all loans, repayments, and tagged contributions
// ======================================================================

$pageTitle = 'Member Statement - Ikimina';
$activePage = 'members';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    header("Location: members.php");
    exit;
}

// Fetch member
$mStmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
$mStmt->bind_param("i", $id);
$mStmt->execute();
$member = $mStmt->get_result()->fetch_assoc();
$mStmt->close();

if (!$member) {
    header("Location: members.php?error=invalid_id");
    exit;
}

// Fetch loans for this member (including repayments)
$loansQuery = $conn->prepare("
    SELECT 
        l.id, l.principal, l.interest_amount, l.total_due, l.loan_date, l.due_date, l.is_void, l.void_reason,
        a.account_name,
        COALESCE(SUM(CASE WHEN r.is_void = 0 THEN r.amount ELSE 0 END), 0) AS amount_paid,
        GREATEST(l.total_due - COALESCE(SUM(CASE WHEN r.is_void = 0 THEN r.amount ELSE 0 END), 0), 0) AS remaining_balance,
        CASE
            WHEN l.is_void = 1 THEN 'VOIDED'
            WHEN l.total_due <= COALESCE(SUM(CASE WHEN r.is_void = 0 THEN r.amount ELSE 0 END), 0) THEN 'COMPLETED'
            WHEN l.due_date < CURDATE() THEN 'OVERDUE'
            ELSE 'ACTIVE'
        END AS payment_status
    FROM loans l
    LEFT JOIN financial_accounts a ON l.disbursement_account_id = a.id
    LEFT JOIN repayments r ON l.id = r.loan_id
    WHERE l.member_id = ?
    GROUP BY l.id, l.principal, l.interest_amount, l.total_due, l.loan_date, l.due_date, l.is_void, l.void_reason, a.account_name
    ORDER BY l.loan_date DESC, l.id DESC
");
$loansQuery->bind_param("i", $id);
$loansQuery->execute();
$loans = $loansQuery->get_result();
$loansQuery->close();

// Fetch repayments made by this member
$repayQuery = $conn->prepare("
    SELECT r.id, r.amount, r.payment_date, r.notes, r.is_void, r.void_reason,
           r.loan_id, a.account_name
    FROM repayments r
    INNER JOIN loans l ON r.loan_id = l.id
    LEFT JOIN financial_accounts a ON r.account_id = a.id
    WHERE l.member_id = ?
    ORDER BY r.payment_date DESC, r.id DESC
");
$repayQuery->bind_param("i", $id);
$repayQuery->execute();
$repayments = $repayQuery->get_result();
$repayQuery->close();

// Fetch member contributions / tagged income transactions (e.g. Umugabane)
$contribQuery = $conn->prepare("
    SELECT t.id, t.amount, t.transaction_date, t.description, t.is_void,
           s.source_name, a.account_name
    FROM transactions t
    LEFT JOIN income_sources s ON t.source_id = s.id
    LEFT JOIN financial_accounts a ON t.account_id = a.id
    WHERE t.member_id = ?
    ORDER BY t.transaction_date DESC, t.id DESC
");
$contribQuery->bind_param("i", $id);
$contribQuery->execute();
$contributions = $contribQuery->get_result();
$contribQuery->close();

// Calculate member totals
$totalBorrowed = 0.0;
$totalRepaid = 0.0;
$totalOutstanding = 0.0;
$totalContributions = 0.0;

if ($loans && $loans->num_rows > 0) {
    while ($l = $loans->fetch_assoc()) {
        if ((int)$l['is_void'] === 0) {
            $totalBorrowed += (float)$l['total_due'];
            $totalRepaid += (float)$l['amount_paid'];
            $totalOutstanding += (float)$l['remaining_balance'];
        }
    }
    $loans->data_seek(0);
}

if ($contributions && $contributions->num_rows > 0) {
    while ($c = $contributions->fetch_assoc()) {
        if ((int)$c['is_void'] === 0) {
            $totalContributions += (float)$c['amount'];
        }
    }
    $contributions->data_seek(0);
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Action Bar (Hidden when printed) -->
<div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <a href="members.php" class="btn btn-secondary btn-sm">← Back to Members</a>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" onclick="window.print()" class="btn btn-primary btn-sm">
            🖨️ Print Member Statement
        </button>
        <?php if (is_admin()): ?>
            <a href="edit_member.php?id=<?= (int)$id ?>" class="btn btn-secondary btn-sm">Edit Profile</a>
        <?php endif; ?>
    </div>
</div>

<!-- Member Statement Card -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid var(--primary-light); padding-bottom: 16px; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
        <div>
            <h2 style="font-size: 22px; color: var(--primary); margin-bottom: 4px;">
                Member Statement: <?= htmlspecialchars($member['full_name']) ?>
            </h2>
            <p style="color: var(--text-muted); font-size: 14px;">
                Member ID: #<?= (int)$member['id'] ?> | Registered: <?= htmlspecialchars(substr($member['created_at'], 0, 10)) ?>
            </p>
        </div>
        <div style="text-align: right; font-size: 13px; color: var(--text-muted);">
            <div><strong>Ikimina Management System</strong></div>
            <div>Statement Date: <?= date('Y-m-d H:i') ?></div>
            <div>Phone: <?= htmlspecialchars($member['phone'] ?? '—') ?></div>
            <div>Address: <?= htmlspecialchars($member['address'] ?? '—') ?></div>
        </div>
    </div>

    <!-- Summary Metrics Grid -->
    <div class="stat-grid" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-label">Total Loans (Due)</div>
            <div class="stat-value" style="font-size: 20px;"><?= number_format($totalBorrowed) ?> RWF</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Repaid</div>
            <div class="stat-value" style="font-size: 20px; color: var(--success);"><?= number_format($totalRepaid) ?> RWF</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Current Outstanding Balance</div>
            <div class="stat-value" style="font-size: 20px; color: var(--danger);"><?= number_format($totalOutstanding) ?> RWF</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Member Contributions</div>
            <div class="stat-value" style="font-size: 20px; color: #6541a5;"><?= number_format($totalContributions) ?> RWF</div>
        </div>
    </div>

    <!-- Table 1: Loans -->
    <h3 style="font-size: 16px; color: var(--primary); margin-bottom: 12px;">Loans History</h3>
    <div class="table-wrapper" style="margin-bottom: 28px;">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>Loan #</th>
                    <th>Issue Date</th>
                    <th>Principal</th>
                    <th>Interest</th>
                    <th>Total Due</th>
                    <th>Paid</th>
                    <th>Remaining</th>
                    <th>Due Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($loans && $loans->num_rows > 0): ?>
                    <?php while ($l = $loans->fetch_assoc()): ?>
                        <tr class="<?= (int)$l['is_void'] === 1 ? 'row-voided' : '' ?>">
                            <td data-label="Loan #">#<?= (int)$l['id'] ?></td>
                            <td data-label="Issue Date"><?= htmlspecialchars($l['loan_date']) ?></td>
                            <td data-label="Principal"><?= number_format((float)$l['principal']) ?> RWF</td>
                            <td data-label="Interest"><?= number_format((float)$l['interest_amount']) ?> RWF</td>
                            <td data-label="Total Due"><strong><?= number_format((float)$l['total_due']) ?> RWF</strong></td>
                            <td data-label="Paid" class="amount-income"><?= number_format((float)$l['amount_paid']) ?> RWF</td>
                            <td data-label="Remaining" class="amount-expense">
                                <?= number_format((float)$l['remaining_balance']) ?> RWF
                            </td>
                            <td data-label="Due Date"><?= htmlspecialchars($l['due_date']) ?></td>
                            <td data-label="Status">
                                <span class="badge badge-<?= htmlspecialchars($l['payment_status']) ?>">
                                    <?= htmlspecialchars($l['payment_status']) ?>
                                </span>
                                <?php if ((int)$l['is_void'] === 1): ?>
                                    <small style="display: block; color: var(--text-muted);">(<?= htmlspecialchars($l['void_reason'] ?? 'Voided') ?>)</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="empty-td" style="text-align: center; padding: 18px; color: var(--text-muted);">
                            No loans recorded for this member.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Table 2: Repayments -->
    <h3 style="font-size: 16px; color: var(--primary); margin-bottom: 12px;">Repayment Records</h3>
    <div class="table-wrapper" style="margin-bottom: 28px;">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Payment Date</th>
                    <th>Loan #</th>
                    <th>Amount Paid</th>
                    <th>Account</th>
                    <th>Notes</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($repayments && $repayments->num_rows > 0): ?>
                    <?php while ($r = $repayments->fetch_assoc()): ?>
                        <tr class="<?= (int)$r['is_void'] === 1 ? 'row-voided' : '' ?>">
                            <td data-label="Receipt #">#<?= (int)$r['id'] ?></td>
                            <td data-label="Payment Date"><?= htmlspecialchars($r['payment_date']) ?></td>
                            <td data-label="Loan #">#<?= (int)$r['loan_id'] ?></td>
                            <td data-label="Amount Paid" class="amount-income">
                                + <?= number_format((float)$r['amount']) ?> RWF
                            </td>
                            <td data-label="Account"><?= htmlspecialchars($r['account_name'] ?? '—') ?></td>
                            <td data-label="Notes"><?= htmlspecialchars($r['notes'] ?? '—') ?></td>
                            <td data-label="Status">
                                <?php if ((int)$r['is_void'] === 1): ?>
                                    <span class="badge badge-VOIDED">VOIDED</span>
                                <?php else: ?>
                                    <span class="badge badge-COMPLETED">PAID</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="empty-td" style="text-align: center; padding: 18px; color: var(--text-muted);">
                            No repayments recorded yet for this member.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Table 3: Member Contributions / Shares -->
    <h3 style="font-size: 16px; color: var(--primary); margin-bottom: 12px;">Member Savings & Contributions (Imisanzu / Umugabane)</h3>
    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>Transaction #</th>
                    <th>Date</th>
                    <th>Source / Type</th>
                    <th>Amount</th>
                    <th>Account</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($contributions && $contributions->num_rows > 0): ?>
                    <?php while ($c = $contributions->fetch_assoc()): ?>
                        <tr class="<?= (int)$c['is_void'] === 1 ? 'row-voided' : '' ?>">
                            <td data-label="Tx #">#<?= (int)$c['id'] ?></td>
                            <td data-label="Date"><?= htmlspecialchars($c['transaction_date']) ?></td>
                            <td data-label="Source"><strong><?= htmlspecialchars($c['source_name'] ?? 'Contribution') ?></strong></td>
                            <td data-label="Amount" class="amount-income">+ <?= number_format((float)$c['amount']) ?> RWF</td>
                            <td data-label="Account"><?= htmlspecialchars($c['account_name'] ?? '—') ?></td>
                            <td data-label="Description"><?= htmlspecialchars($c['description'] ?? '—') ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-td" style="text-align: center; padding: 18px; color: var(--text-muted);">
                            No direct member savings/contributions tagged to this member yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
