<?php
// ======================================================================
// System Activity Log (Admin Only Audit Trail)
// Tracks who logged, edited, or voided transactions
// ======================================================================

$pageTitle = 'Activity Log - Ikimina';
$activePage = 'activity_log';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

// Filters
$actionFilter = filter_input(INPUT_GET, 'action_filter', FILTER_SANITIZE_STRING) ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT a.*, u.username, u.full_name
    FROM activity_logs a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE 1=1
";
$params = [];
$types = "";

if ($actionFilter !== '') {
    $sql .= " AND a.action = ?";
    $params[] = $actionFilter;
    $types .= "s";
}

if ($search !== '') {
    $sql .= " AND (a.details LIKE ? OR a.entity_type LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
    $st = "%{$search}%";
    $params[] = $st;
    $params[] = $st;
    $params[] = $st;
    $params[] = $st;
    $types .= "ssss";
}

$sql .= " ORDER BY a.created_at DESC, a.id DESC LIMIT 100";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs = $stmt->get_result();
$stmt->close();

require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-title">
        <span>System Audit Trail & Activity Log</span>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="activity_log.php" class="filter-card">
        <div class="filter-grid">
            <div class="filter-item">
                <label for="action_filter">Action Type</label>
                <select id="action_filter" name="action_filter">
                    <option value="">All Actions</option>
                    <option value="EDIT" <?= $actionFilter === 'EDIT' ? 'selected' : '' ?>>Edits (EDIT)</option>
                    <option value="VOID" <?= $actionFilter === 'VOID' ? 'selected' : '' ?>>Voided Records (VOID)</option>
                    <option value="DELETE" <?= $actionFilter === 'DELETE' ? 'selected' : '' ?>>Deletions (DELETE)</option>
                </select>
            </div>

            <div class="filter-item" style="flex: 2;">
                <label for="search">Search Details / User</label>
                <input type="text" id="search" name="search" placeholder="Search by user or details..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filter Log</button>
                <?php if ($actionFilter !== '' || $search !== ''): ?>
                    <a href="activity_log.php" class="btn btn-secondary btn-sm">Reset</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="table-wrapper">
        <table class="app-table responsive-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Entity ID</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs && $logs->num_rows > 0): ?>
                    <?php while ($log = $logs->fetch_assoc()): ?>
                        <?php 
                            $badgeClass = 'info';
                            if ($log['action'] === 'VOID') { $badgeClass = 'OVERDUE'; }
                            elseif ($log['action'] === 'EDIT') { $badgeClass = 'ACTIVE'; }
                        ?>
                        <tr>
                            <td data-label="Timestamp"><?= htmlspecialchars($log['created_at']) ?></td>
                            <td data-label="User">
                                <strong><?= htmlspecialchars($log['full_name'] ?? ($log['username'] ?? 'System')) ?></strong>
                            </td>
                            <td data-label="Action">
                                <span class="badge badge-<?= $badgeClass ?>">
                                    <?= htmlspecialchars($log['action']) ?>
                                </span>
                            </td>
                            <td data-label="Entity"><?= htmlspecialchars(ucfirst($log['entity_type'])) ?></td>
                            <td data-label="Entity ID">#<?= (int)$log['entity_id'] ?></td>
                            <td data-label="Details"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-td" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No activity logged yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
