<?php
// replies.php
// Citizen view of replies and official comments

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/db/connect.php';

// Access Control Gate
require_citizen();

$user_id = $_SESSION['user_id'];
$breadcrumbs = [t('nav_dashboard') => '/citizen_dashboard.php', t('nav_replies') => ''];
$page_title = t('nav_replies');

$error = '';
$success = '';

// Handle Close Action (Mark as Satisfied)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = "CSRF Token Validation Failed. Security Violation.";
    } else {
        $complaint_id = (int)$_POST['complaint_id'];
        
        try {
            // Verify ownership
            $stmt_chk = $pdo->prepare("SELECT ticket_id, status FROM complaints WHERE id = ? AND citizen_id = ?");
            $stmt_chk->execute([$complaint_id, $user_id]);
            $ticket = $stmt_chk->fetch();
            
            if ($ticket) {
                if ($ticket['status'] === 'Closed') {
                    $error = "This ticket is already closed.";
                } else {
                    // Update status
                    $stmt_upd = $pdo->prepare("UPDATE complaints SET status = 'Closed' WHERE id = ?");
                    $stmt_upd->execute([$complaint_id]);
                    
                    // Insert log
                    $stmt_log = $pdo->prepare("INSERT INTO complaint_logs (complaint_id, action, remarks, done_by) VALUES (?, 'Closed', 'Citizen marked this grievance as satisfied and closed the ticket.', ?)");
                    $stmt_log->execute([$complaint_id, $user_id]);
                    
                    // Insert admin/staff notification
                    // Find assigned staff or notify admin
                    $stmt_assign = $pdo->prepare("SELECT department_id FROM complaints WHERE id = ?");
                    $stmt_assign->execute([$complaint_id]);
                    $dept_id = $stmt_assign->fetchColumn();
                    
                    if ($dept_id) {
                        $stmt_staff = $pdo->prepare("SELECT user_id FROM staff_assignments WHERE department_id = ?");
                        $stmt_staff->execute([$dept_id]);
                        $staff_ids = $stmt_staff->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($staff_ids as $sid) {
                            add_notification($pdo, $sid, "Ticket Closed", "Ticket {$ticket['ticket_id']} has been closed by the citizen.");
                        }
                    }
                    
                    $success = "Thank you! Ticket {$ticket['ticket_id']} has been marked as Satisfied and Closed.";
                }
            } else {
                $error = "Unauthorized ticket operation.";
            }
        } catch (PDOException $e) {
            $error = "Failed to close ticket: " . $e->getMessage();
        }
    }
}

// Fetch complaints that have replies
try {
    // Select complaints that have at least one entry in admin_replies
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.* 
        FROM complaints c
        JOIN admin_replies ar ON c.id = ar.complaint_id
        WHERE c.citizen_id = ?
        ORDER BY c.id DESC
    ");
    $stmt->execute([$user_id]);
    $complaints = $stmt->fetchAll();
} catch (PDOException $e) {
    $complaints = [];
}
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<div style="background: var(--white); padding: 2rem; border-radius: var(--radius); box-shadow: var(--shadow-sm); border-top: 3px solid var(--saffron);">
    <h2 style="margin-bottom:1.5rem; color: var(--navy);">Official Replies on Your Complaints</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><span><?php echo $error; ?></span></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><span><?php echo $success; ?></span></div>
    <?php endif; ?>

    <?php if (empty($complaints)): ?>
        <p style="color:var(--text-muted); text-align:center; padding: 2rem;">No official responses have been logged on your registered grievances yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table id="replies-table">
                <thead>
                    <tr>
                        <th>Ticket ID</th>
                        <th>Category</th>
                        <th>Latest Reply Snapshot</th>
                        <th>Status</th>
                        <th>Date of Reply</th>
                        <th class="no-print">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($complaints as $c): 
                        // Fetch latest reply and count of replies
                        $stmt_rep = $pdo->prepare("SELECT ar.*, u.name as replier_name FROM admin_replies ar JOIN users u ON ar.replied_by = u.id WHERE ar.complaint_id = ? ORDER BY ar.id DESC LIMIT 1");
                        $stmt_rep->execute([$c['id']]);
                        $latest_reply = $stmt_rep->fetch();
                        
                        // Fetch all replies for this complaint in ascending order
                        $stmt_all_rep = $pdo->prepare("SELECT ar.*, u.name as replier_name FROM admin_replies ar JOIN users u ON ar.replied_by = u.id WHERE ar.complaint_id = ? ORDER BY ar.id ASC");
                        $stmt_all_rep->execute([$c['id']]);
                        $all_replies = $stmt_all_rep->fetchAll();
                    ?>
                        <!-- Main Header Row -->
                        <tr class="expandable-header" data-target="row-details-<?php echo $c['id']; ?>" style="cursor:pointer;">
                            <td><strong><?php echo htmlspecialchars($c['ticket_id']); ?></strong> <span style="font-size:0.75rem; color:var(--saffron);">[Click to Expand]</span></td>
                            <td><?php echo htmlspecialchars($c['category']); ?></td>
                            <td>
                                <?php 
                                    $snip = $latest_reply['reply_text'] ?? '';
                                    if (strlen($snip) > 50) $snip = substr($snip, 0, 50) . '...';
                                    echo htmlspecialchars($snip);
                                ?>
                            </td>
                            <td>
                                <?php 
                                    $cls = 'badge-pending';
                                    if ($c['status'] === 'In Progress') $cls = 'badge-progress';
                                    elseif ($c['status'] === 'Resolved') $cls = 'badge-resolved';
                                    elseif ($c['status'] === 'Closed') $cls = 'badge-closed';
                                ?>
                                <span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($c['status']); ?></span>
                            </td>
                            <td><?php echo $latest_reply ? date('d-m-Y', strtotime($latest_reply['replied_at'])) : ''; ?></td>
                            <td class="no-print">
                                <?php if ($c['status'] !== 'Closed'): ?>
                                    <form action="/replies.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to mark this grievance as resolved and close the ticket?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="complaint_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" name="close_ticket" class="btn btn-secondary btn-sm" style="font-size:0.7rem; padding:4px 8px; border-color:var(--green); color:var(--green);"><?php echo t('satisfaction_close'); ?></button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:0.8rem; color:var(--text-muted); font-weight:bold;">RESOLVED & CLOSED</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Expandable Details Row -->
                        <tr id="row-details-<?php echo $c['id']; ?>" style="display:none; background-color: var(--bg-light);">
                            <td colspan="6" style="padding: 20px;">
                                <div style="border:1px solid var(--border-color); padding: 15px; border-radius:4px; background: white; margin-bottom:15px;">
                                    <h4 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:5px;">Original Complaint Details</h4>
                                    <p><strong>Description:</strong> <?php echo htmlspecialchars($c['description']); ?></p>
                                    <?php if ($c['photo_path']): ?>
                                        <div style="margin-top:10px;">
                                            <strong>Citizen Uploaded Photo:</strong><br>
                                            <a href="/<?php echo htmlspecialchars($c['photo_path']); ?>" target="_blank">
                                                <img src="/<?php echo htmlspecialchars($c['photo_path']); ?>" style="max-width:150px; margin-top:5px; border:1px solid #ddd; padding:2px; border-radius:4px;" alt="Original Photo">
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="border:1px solid var(--border-color); padding: 15px; border-radius:4px; background: white;">
                                    <h4 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:5px; color: var(--navy);">Official Communication History</h4>
                                    
                                    <?php foreach ($all_replies as $rep): ?>
                                        <div style="padding: 10px 0; border-bottom: 1px dashed #eee; font-size:0.85rem;">
                                            <div style="display:flex; justify-content:space-between; margin-bottom:4px; font-weight:bold; color:var(--navy);">
                                                <span>Reply by: <?php echo htmlspecialchars($rep['replier_name']); ?></span>
                                                <span style="color:var(--text-muted); font-weight:normal;"><?php echo date('d-m-Y h:i A', strtotime($rep['replied_at'])); ?></span>
                                            </div>
                                            <p style="color:#333;"><?php echo htmlspecialchars($rep['reply_text']); ?></p>
                                            <?php if ($rep['photo_path']): ?>
                                                <div style="margin-top:5px;">
                                                    <a href="/<?php echo htmlspecialchars($rep['photo_path']); ?>" target="_blank" style="font-weight:bold; color:var(--saffron);">Resolution Attachment Image</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <div style="margin-top:15px; font-size:0.8rem;">
                                        <a href="/track_complaint.php?ticket_id=<?php echo urlencode($c['ticket_id']); ?>" class="btn btn-secondary btn-sm" style="font-size:0.75rem; padding:5px 10px;">View Full Ticket Timeline & Log Comments</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
