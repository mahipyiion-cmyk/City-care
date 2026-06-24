<?php
// citizen_dashboard.php
// Citizen Grievance Dashboard

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/db/connect.php';

// Access Control Gate
require_citizen();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_ward = $_SESSION['user_ward'];

$breadcrumbs = [t('nav_dashboard') => ''];
$page_title = t('citizen_dashboard_title');

// Check if print view requested
$is_print = isset($_GET['export']) && $_GET['export'] === 'print';

// 1. Fetch complaints submitted by this user
try {
    $stmt_comp = $pdo->prepare("SELECT c.*, d.name as dept_name FROM complaints c LEFT JOIN departments d ON c.department_id = d.id WHERE c.citizen_id = ? ORDER BY c.id DESC");
    $stmt_comp->execute([$user_id]);
    $complaints = $stmt_comp->fetchAll();
} catch (PDOException $e) {
    $complaints = [];
}

// 2. Fetch last 5 notifications/admin replies for the user
try {
    $stmt_notif = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5");
    $stmt_notif->execute([$user_id]);
    $notifications = $stmt_notif->fetchAll();
} catch (PDOException $e) {
    $notifications = [];
}

// If print view is requested, output a minimal layout instead of standard header/footer
if ($is_print) {
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
    <head>
        <meta charset="UTF-8">
        <title>Exported_Complaints_<?php echo $user_id; ?>.html</title>
        <style>
            body { font-family: 'Noto Sans', sans-serif; padding: 30px; color: #333; }
            h1 { color: #002147; font-size: 20px; border-bottom: 2px solid #FF9933; padding-bottom: 10px; margin-bottom: 20px;}
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 13px; }
            th { background-color: #f2f2f2; color: #002147; }
            .badge { display: inline-block; padding: 3px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
            .badge-Pending { background-color: #ffebee; color: #D0021B; }
            .badge-In-Progress { background-color: #fff3e0; color: #FF9933; }
            .badge-Resolved { background-color: #e8f5e9; color: #138808; }
            .badge-Closed { background-color: #eee; color: #555; }
            .footer { margin-top: 35px; font-size: 11px; color: #666; border-top: 1px dashed #ccc; padding-top: 15px; }
        </style>
    </head>
    <body onload="window.print()">
        <h1>CityCare Grievance Export - Citizen Record</h1>
        <p><strong>Citizen Name:</strong> <?php echo htmlspecialchars($user_name); ?> | <strong>Ward:</strong> <?php echo htmlspecialchars($user_ward); ?></p>
        <p><strong>Export Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        
        <table>
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Category</th>
                    <th>Sub-Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Date Filed</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($complaints)): ?>
                    <tr><td colspan="6" style="text-align:center;">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($complaints as $c): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($c['ticket_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($c['category']); ?></td>
                            <td><?php echo htmlspecialchars($c['sub_category']); ?></td>
                            <td><?php echo htmlspecialchars($c['priority']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo str_replace(' ', '-', $c['status']); ?>">
                                    <?php echo htmlspecialchars($c['status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($c['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="footer">
            CityCare Civic Grievance System. This is a secure system-generated export document.
        </div>
    </body>
    </html>
    <?php
    exit();
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Welcome Banner -->
<div class="welcome-banner">
    <div>
        <h2 style="margin:0; font-size:1.4rem; color: var(--navy);"><?php echo t('welcome_back'); ?>, <?php echo htmlspecialchars($user_name); ?>!</h2>
        <span style="font-size:0.85rem; color: var(--text-muted); font-weight:600;"><?php echo t('ward_zone'); ?>: <strong><?php echo htmlspecialchars($user_ward); ?></strong></span>
    </div>
    <div class="btn-group">
        <a href="?export=print" target="_blank" class="btn btn-secondary no-print">📄 Export My Complaints</a>
    </div>
</div>

<!-- Dashboard Quick Tiles -->
<div class="dashboard-tiles">
    <div class="tile" onclick="location.href='/submit_complaint.php'">
        <span class="tile-icon">✍️</span>
        <span><?php echo t('register_new'); ?></span>
    </div>
    <div class="tile" onclick="location.href='#complaints-section'">
        <span class="tile-icon">🔍</span>
        <span><?php echo t('track_my'); ?></span>
    </div>
    <div class="tile" onclick="location.href='/replies.php'">
        <span class="tile-icon">💬</span>
        <span><?php echo t('view_replies'); ?></span>
    </div>
    <div class="tile" onclick="alert('Profile updates are locked for civic evaluation.')">
        <span class="tile-icon">👤</span>
        <span><?php echo t('update_profile'); ?></span>
    </div>
</div>

<!-- Layout Split: Table and Sidebar -->
<div class="dashboard-grid">
    
    <!-- Left Complaints List Section -->
    <div id="complaints-section" style="background: var(--white); padding: 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow-sm); border-top: 3px solid var(--navy);">
        <h3 style="margin-bottom:1.2rem;"><?php echo t('recent_complaints'); ?></h3>
        
        <?php if (empty($complaints)): ?>
            <div style="text-align:center; padding: 2rem; color: var(--text-muted);">
                <p><?php echo t('no_complaints'); ?></p>
                <a href="/submit_complaint.php" class="btn btn-accent" style="margin-top: 15px;"><?php echo t('register_new'); ?></a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('ticket_id'); ?></th>
                            <th><?php echo t('category'); ?></th>
                            <th><?php echo t('date_submitted'); ?></th>
                            <th><?php echo t('status'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $c): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($c['ticket_id']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars(t('cat_' . strtolower(explode(' ', $c['category'])[0]))); ?>
                                    <br><span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($c['sub_category']); ?></span>
                                </td>
                                <td><?php echo date('d M Y', strtotime($c['created_at'])); ?></td>
                                <td>
                                    <?php 
                                        $badge_class = 'badge-pending';
                                        if ($c['status'] === 'In Progress') $badge_class = 'badge-progress';
                                        elseif ($c['status'] === 'Resolved') $badge_class = 'badge-resolved';
                                        elseif ($c['status'] === 'Closed') $badge_class = 'badge-closed';
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo htmlspecialchars($c['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="/track_complaint.php?ticket_id=<?php echo urlencode($c['ticket_id']); ?>" class="btn btn-sm btn-secondary" style="font-size: 0.75rem; padding: 4px 8px;"><?php echo t('view_details'); ?></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Communication Panel -->
    <div class="side-panel">
        <h3><?php echo t('notifications_panel'); ?></h3>
        
        <?php if (empty($notifications)): ?>
            <p style="font-size:0.8rem; color: var(--text-muted); margin-top: 1rem;">No recent communications or alerts from department technicians.</p>
        <?php else: ?>
            <ul class="notification-list">
                <?php foreach ($notifications as $n): ?>
                    <li class="notification-item">
                        <h5 style="color: var(--navy);"><?php echo htmlspecialchars($n['title']); ?></h5>
                        <p><?php echo htmlspecialchars($n['message']); ?></p>
                        <div class="notification-date"><?php echo time_elapsed_string($n['created_at']); ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
