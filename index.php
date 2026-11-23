<?php
// ======================================================================
// Dashboard - Ikimina Management System
// Digital Recording Ledger: Active balances, soft negative balance warnings,
// clean category joins, and recent active transactions
// ======================================================================

$pageTitle = 'Dashboard - Ikimina';
$activePage = 'dashboard';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

// Use view-free helper functions (work on any shared host, including InfinityFree)
$summary     = get_overall_summary($conn);
$accountRows = get_all_account_balances($conn);
$negativeAccounts = [];

foreach ($accountRows as $acc) {
    if ($acc['current_balance'] < 0) {
        $negativeAccounts[] = $acc['account_name'] . ' (' . number_format($acc['current_balance']) . ' RWF)';
    }
}

// Fetch recent active transactions (excludes voided records, joins categories, sources, members)
$recentQuery = $conn->query("
    SELECT 
        t.id,
        t.transaction_type,
        t.amount,
        t.transaction_date,
        t.description,
        s.source_name,
        c.category_name,
        a.account_name,
        m.full_name AS member_name
    FROM transactions t
    LEFT JOIN income_sources s ON t.source_id = s.id
    LEFT JOIN expense_categories c ON t.expense_category_id = c.id
    LEFT JOIN financial_accounts a ON t.account_id = a.id
    LEFT JOIN members m ON t.member_id = m.id
    WHERE t.is_void = 0
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT 8
");

require_once __DIR__ . '/includes/header.php';
?>

<!-- Quick Action Buttons -->
<div class="quick-actions">
    <a href="income.php" class="quick-action-btn btn-income">
        <span>➕</span> Record Income
    </a>
    <a href="expenses.php" class="quick-action-btn btn-expense">
        <span>➖</span> Record Expense
    </a>
    <a href="repayments.php" class="quick-action-btn btn-repayment">
        <span>🔄</span> Record Repayment
    </a>
    <a href="loans.php" class="quick-action-btn btn-loan">
        <span>📑</span> Issue Loan
    </a>
    <a href="transfers.php" class="quick-action-btn">
        <span>↔️</span> Transfer Money
    </a>
</div>

<!-- Informative Soft Negative Balance Notice (Digital Recording Ledger principle) -->
<?php if (!empty($negativeAccounts)): ?>
    <div class="alert alert-warning" style="margin-bottom: 24px; border-left: 4px solid var(--warning);">
        <strong>⚠️ Note for Treasurer:</strong> 
        The following account balance is currently negative: 
        <strong><?= htmlspecialchars(implode(', ', $negativeAccounts)) ?></strong>.<br>
        This is normal if cash expenses or loans were paid out during a meeting before typing in today's contributions. 
        Please remember to record pending cash income or member contributions when received.
    </div>
<?php endif; ?>

<!-- Financial Summary Overview Cards -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Cash & Bank</div>
        <div class="stat-value" style="color: var(--primary);">
            <?= number_format((float)($summary['total_cash_and_bank'] ?? 0)) ?> RWF
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Income</div>
        <div class="stat-value" style="color: var(--success);">
            <?= number_format((float)($summary['total_income'] ?? 0)) ?> RWF
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Expenses</div>
        <div class="stat-value" style="color: var(--danger);">
            <?= number_format((float)($summary['total_expenses'] ?? 0)) ?> RWF
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Outstanding Loans</div>
        <div class="stat-value" style="color: var(--warning);">
            <?= number_format((float)($summary['outstanding_loans'] ?? 0)) ?> RWF
        </div>
    </div>
</div>

<!-- Account Balances Cards -->
<div class="card">
    <div class="card-title">
        <span>Account Balances</span>
        <a href="transfers.php" class="btn btn-secondary btn-sm">Manage Transfers</a>
    </div>

    <div class="stat-grid" style="margin-bottom: 0;">
        <?php if (!empty($accountRows)): ?>
            <?php foreach ($accountRows as $account): ?>
                <?php 
                    $bal = (float)$account['current_balance'];
                    $isNegative = ($bal < 0);
                ?>
                <div class="stat-card" style="background: var(--bg-subtle); <?= $isNegative ? 'border: 1px solid var(--warning);' : '' ?>">
                    <div class="stat-label">
                        <?= htmlspecialchars($account['account_name']) ?> Balance
                        <?php if ($isNegative): ?>
                            <span style="color: var(--warning); font-size: 11px; font-weight: 600;">(Negative)</span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-value" style="font-size: 22px; color: <?= $isNegative ? 'var(--danger)' : 'var(--text-main)' ?>;">
                        <?= number_format($bal) ?> RWF
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: var(--text-muted);">No financial accounts found.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Active Transactions List -->
<div class="card">
    <div class="card-title">
        <span>Recent Transactions</span>
        <div style="display: flex; gap: 8px;">
            <a href="income.php" class="btn btn-secondary btn-sm">View Income</a>
            <a href="expenses.php" class="btn btn-secondary btn-sm">View Expenses</a>
        </div>
    </div>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Category / Source</th>
                    <th>Member</th>
                    <th>Account</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <?php if (is_admin()): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentQuery && $recentQuery->num_rows > 0): ?>
                    <?php while ($t = $recentQuery->fetch_assoc()): ?>
                        <?php 
                            $isIncome = ($t['transaction_type'] === 'INCOME');
                            $categoryName = $isIncome 
                                ? ($t['source_name'] ?? 'Other Income') 
                                : ($t['category_name'] ?? 'General Expense');
                        ?>
                        <tr>
                            <td data-label="Date"><?= htmlspecialchars($t['transaction_date']) ?></td>
                            <td data-label="Type">
                                <span class="badge badge-<?= $isIncome ? 'active' : 'OVERDUE' ?>">
                                    <?= htmlspecialchars($t['transaction_type']) ?>
                                </span>
                            </td>
                            <td data-label="Category / Source">
                                <strong><?= htmlspecialchars($categoryName) ?></strong>
                            </td>
                            <td data-label="Member">
                                <?= htmlspecialchars($t['member_name'] ?? '—') ?>
                            </td>
                            <td data-label="Account">
                                <?= htmlspecialchars($t['account_name'] ?? 'Not assigned') ?>
                            </td>
                            <td data-label="Description">
                                <?= htmlspecialchars($t['description'] ?? '—') ?>
                            </td>
                            <td data-label="Amount" class="<?= $isIncome ? 'amount-income' : 'amount-expense' ?>">
                                <?= $isIncome ? '+' : '-' ?> <?= number_format((float)$t['amount']) ?> RWF
                            </td>
                            <?php if (is_admin()): ?>
                                <td data-label="Actions">
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <a href="edit_transaction.php?id=<?= (int)$t['id'] ?>" class="btn btn-secondary btn-sm" title="Edit this transaction">
                                            Edit
                                        </a>
                                        <a href="void_record.php?type=transaction&id=<?= (int)$t['id'] ?>" class="btn btn-danger btn-sm" title="Void this transaction">
                                            Void
                                        </a>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= is_admin() ? '8' : '7' ?>" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No active transactions recorded yet. Use the quick actions above to record your first transaction.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
