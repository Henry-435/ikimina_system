<?php
// ======================================================================
// Dashboard - Ikimina Management System
// ======================================================================

$pageTitle = 'Dashboard - Ikimina';
$activePage = 'dashboard';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Fetch overall financial statistics
$summaryQuery = $conn->query("SELECT * FROM overall_financial_summary");
$summary = $summaryQuery ? $summaryQuery->fetch_assoc() : [];

// Fetch Cash and Bank balances from financial_summary view
$accountsQuery = $conn->query("SELECT * FROM financial_summary ORDER BY account_id ASC");

// Fetch recent transactions (with correct joins for both Income and Expense categories)
$recentQuery = $conn->query("
    SELECT 
        t.id,
        t.transaction_type,
        t.amount,
        t.transaction_date,
        t.description,
        s.source_name,
        c.category_name,
        a.account_name
    FROM transactions t
    LEFT JOIN income_sources s ON t.source_id = s.id
    LEFT JOIN expense_categories c ON t.expense_category_id = c.id
    LEFT JOIN financial_accounts a ON t.account_id = a.id
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT 6
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
        <?php if ($accountsQuery && $accountsQuery->num_rows > 0): ?>
            <?php while ($account = $accountsQuery->fetch_assoc()): ?>
                <div class="stat-card" style="background: var(--bg-subtle);">
                    <div class="stat-label"><?= htmlspecialchars($account['account_name']) ?> Balance</div>
                    <div class="stat-value" style="font-size: 22px;">
                        <?= number_format((float)$account['current_balance']) ?> RWF
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="color: var(--text-muted);">No financial accounts found.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Transactions List -->
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
                    <th>Account</th>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentQuery && $recentQuery->num_rows > 0): ?>
                    <?php while ($t = $recentQuery->fetch_assoc()): ?>
                        <?php 
                            $isIncome = ($t['transaction_type'] === 'INCOME');
                            // Fix bug 8: Show category name for expense, source name for income
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
                            <td data-label="Account">
                                <?= htmlspecialchars($t['account_name'] ?? 'Not assigned') ?>
                            </td>
                            <td data-label="Description">
                                <?= htmlspecialchars($t['description'] ?? '—') ?>
                            </td>
                            <td data-label="Amount" class="<?= $isIncome ? 'amount-income' : 'amount-expense' ?>">
                                <?= $isIncome ? '+' : '-' ?> <?= number_format((float)$t['amount']) ?> RWF
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No transactions recorded yet. Use the quick actions above to record your first transaction.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
