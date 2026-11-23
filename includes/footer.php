        </main> <!-- /.page-body -->
    </div> <!-- /.app-main -->

    <!-- Mobile Bottom Navigation Bar (< 640px) -->
    <nav class="mobile-bottom-nav">
        <a href="index.php" class="bottom-nav-item <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span>
            <span>Home</span>
        </a>
        <a href="income.php" class="bottom-nav-item <?= ($activePage ?? '') === 'income' ? 'active' : '' ?>">
            <span class="nav-icon">💰</span>
            <span>Income</span>
        </a>
        <a href="expenses.php" class="bottom-nav-item <?= ($activePage ?? '') === 'expenses' ? 'active' : '' ?>">
            <span class="nav-icon">💸</span>
            <span>Expenses</span>
        </a>
        <a href="loans.php" class="bottom-nav-item <?= ($activePage ?? '') === 'loans' ? 'active' : '' ?>">
            <span class="nav-icon">📑</span>
            <span>Loans</span>
        </a>
        <a href="#" id="bottomNavMore" class="bottom-nav-item">
            <span class="nav-icon">☰</span>
            <span>More</span>
        </a>
    </nav>
</div> <!-- /.app-container -->

<script src="assets/web.js"></script>
</body>
</html>
