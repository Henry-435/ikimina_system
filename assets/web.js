/**
 * Ikimina Management System - UI Interactions
 * Off-canvas mobile menu, drawer toggles, and deletion confirmations
 */

document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.app-sidebar');
    const backdrop = document.querySelector('.sidebar-backdrop');
    const menuToggleBtn = document.querySelector('.menu-toggle-btn');
    const sidebarCloseBtn = document.querySelector('.sidebar-close-btn');
    const bottomNavMore = document.getElementById('bottomNavMore');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (menuToggleBtn) {
        menuToggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            openSidebar();
        });
    }

    if (sidebarCloseBtn) {
        sidebarCloseBtn.addEventListener('click', function (e) {
            e.preventDefault();
            closeSidebar();
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    if (bottomNavMore) {
        bottomNavMore.addEventListener('click', function (e) {
            e.preventDefault();
            openSidebar();
        });
    }

    // Auto-dismissible notices or alert close button support
    document.querySelectorAll('.alert').forEach(function (alert) {
        alert.addEventListener('click', function (e) {
            if (e.target.classList.contains('alert-close')) {
                alert.style.display = 'none';
            }
        });
    });
});
