<?php
// ======================================================================
// Shared Application Header & Navigation Layout
// ======================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

// Every page using this header requires authentication
require_login();

$currentUser = current_user();
$pageTitle = $pageTitle ?? 'Ikimina Management System';
$activePage = $activePage ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="assets/images/favicon.png">
    <link rel="apple-touch-icon" href="assets/images/favicon.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app-container">
    <!-- Off-canvas Backdrop for Mobile (< 900px) -->
    <div class="sidebar-backdrop"></div>

    <!-- App Sidebar -->
    <aside class="app-sidebar">
        <div class="sidebar-header">
            <a href="index.php" class="brand-title">
                <img src="assets/images/favicon.png" alt="Logo" style="width: 28px; height: 28px; object-fit: contain; vertical-align: middle;"> Ikimina
            </a>
            <button type="button" class="sidebar-close-btn" aria-label="Close navigation menu">&times;</button>
        </div>

        <nav class="sidebar-menu">
            <a href="index.php" class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                <span>📊</span> Dashboard
            </a>
            <a href="members.php" class="nav-link <?= $activePage === 'members' ? 'active' : '' ?>">
                <span>👥</span> Members
            </a>
            <a href="income.php" class="nav-link <?= $activePage === 'income' ? 'active' : '' ?>">
                <span>💰</span> Income
            </a>
            <a href="expenses.php" class="nav-link <?= $activePage === 'expenses' ? 'active' : '' ?>">
                <span>💸</span> Expenses
            </a>
            <a href="loans.php" class="nav-link <?= $activePage === 'loans' ? 'active' : '' ?>">
                <span>📑</span> Loans
            </a>
            <a href="repayments.php" class="nav-link <?= $activePage === 'repayments' ? 'active' : '' ?>">
                <span>🔄</span> Repayments
            </a>
            <a href="transfers.php" class="nav-link <?= $activePage === 'transfers' ? 'active' : '' ?>">
                <span>↔️</span> Transfers
            </a>
            <a href="#" class="nav-link <?= $activePage === 'reports' ? 'active' : '' ?>" title="Reports feature coming in Phase 3">
                <span>📈</span> Reports <span class="badge-coming">Soon</span>
            </a>
            <?php if (is_admin()): ?>
            <a href="users.php" class="nav-link <?= $activePage === 'users' ? 'active' : '' ?>">
                <span>⚙️</span> Users
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="user-meta">
                    <div class="user-name" title="<?= htmlspecialchars($currentUser['full_name']) ?>">
                        <?= htmlspecialchars($currentUser['full_name']) ?>
                    </div>
                    <span class="user-role-badge role-<?= strtolower($currentUser['role']) ?>">
                        <?= htmlspecialchars($currentUser['role']) ?>
                    </span>
                </div>
                <a href="logout.php" class="logout-link" title="Logout">Logout</a>
            </div>
        </div>
    </aside>

    <!-- App Main Content Area -->
    <div class="app-main">
        <!-- Top Sticky Header -->
        <header class="app-topbar">
            <div class="topbar-left">
                <button type="button" class="menu-toggle-btn" aria-label="Toggle navigation">☰</button>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
            </div>
            <div class="topbar-right">
                <span class="user-role-badge role-<?= strtolower($currentUser['role']) ?>">
                    <?= htmlspecialchars($currentUser['role']) ?>
                </span>
                <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
            </div>
        </header>

        <!-- Page Main Body -->
        <main class="page-body">
