<?php
// track_complaint.php
// Public Complaint Tracking Portal (No login required)

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/db/connect.php';

$page_title = t('nav_track');
$breadcrumbs = [t('nav_track') => ''];

$search_query = sanitize($_GET['ticket_id'] ?? $_GET['search'] ?? '');
$complaint = null;
$logs = [];
$replies = [];
$multiple_matches = [];
$error = '';
$success_msg = '';

// Handle follow-up comment post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_followup'])) {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = "CSRF Verification Failed. Security Violation.";
    } else {
        $complaint_id = (int)$_POST['complaint_id'];
        $comment_text = sanitize($_POST['comment_text'] ?? '');
        
        if (empty($comment_text)) {
            $error = "Follow-up comment cannot be empty.";
        } else {
            try {
                // Determine commenter ID (Use session if logged in, fallback to ticket citizen owner)
                $stmt_owner = $pdo->prepare("SELECT citizen_id, ticket_id FROM complaints WHERE id = ?");
                $stmt_owner->execute([$complaint_id]);
                $ticket_info = $stmt_owner->fetch();
                
                if ($ticket_info) {
                    $commenter_id = $_SESSION['user_id'] ?? $ticket_info['citizen_id'];
                    
                    // Add comment to logs
                    $stmt_ins = $pdo->prepare("INSERT INTO complaint_logs (complaint_id, action, remarks, done_by) VALUES (?, 'Citizen Follow-up', ?, ?)");
                    $stmt_ins->execute([$complaint_id, $comment_text, $commenter_id]);
                    
                    // Add a notification for staff/admin if assigned
                    $stmt_assign = $pdo->prepare("SELECT department_id FROM complaints WHERE id = ?");
                    $stmt_assign->execute([$complaint_id]);
                    $dept_id = $stmt_assign->fetchColumn();
                    if ($dept_id) {
                        // Find staff in this department
                        $stmt_staff = $pdo->prepare("SELECT user_id FROM staff_assignments WHERE department_id = ?");
                        $stmt_staff->execute([$dept_id]);
                        $staff_ids = $stmt_staff->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($staff_ids as $sid) {
                            add_notification($pdo, $sid, "New Comment added", "A follow-up was posted on ticket {$ticket_info['ticket_id']}.");
                        }
                    }
                    
                    $success_msg = "Follow-up comment posted successfully.";
                    // Reload complaint info
                    $search_query = $ticket_info['ticket_id'];
                }
            } catch (PDOException $e) {
                $error = "Comment post failed: " . $e->getMessage();
            }
        }
    }
}

// Perform search
if (!empty($search_query)) {
    try {
        // 1. Check if query is ticket format (CMP-YYYYMMDD-XXXX)
        if (preg_match('/^CMP-\d{8}-\d{4}$/i', $search_query)) {
            $stmt = $pdo->prepare("SELECT c.*, d.name as dept_name, u.name as citizen_name, u.mobile as citizen_mobile FROM complaints c JOIN users u ON c.citizen_id = u.id LEFT JOIN departments d ON c.department_id = d.id WHERE c.ticket_id = ?");
            $stmt->execute([$search_query]);
            $complaint = $stmt->fetch();
            
            if (!$complaint) {
                $error = "No complaint found matching Ticket ID: " . htmlspecialchars($search_query);
            }
        } 
        // 2. Otherwise assume mobile number lookup
        elseif (preg_match('/^\d{10}$/', $search_query)) {
            // Find all complaints for this user mobile
            $stmt = $pdo->prepare("SELECT c.*, d.name as dept_name FROM complaints c JOIN users u ON c.citizen_id = u.id LEFT JOIN departments d ON c.department_id = d.id WHERE u.mobile = ? ORDER BY c.id DESC");
            $stmt->execute([$search_query]);
            $multiple_matches = $stmt->fetchAll();
            
            if (empty($multiple_matches)) {
                $error = "No complaints found associated with Mobile Number: " . htmlspecialchars($search_query);
            } elseif (count($multiple_matches) === 1) {
                // If only one complaint, redirect to direct ticket view
                header("Location: /track_complaint.php?ticket_id=" . urlencode($multiple_matches[0]['ticket_id']));
                exit();
            }
        } else {
            $error = "Invalid format. Please enter a valid Ticket ID or 10-digit Mobile Number.";
        }

        // Fetch logs and replies if single complaint is loaded
        if ($complaint) {
            // Fetch audit timeline logs
            $stmt_logs = $pdo->prepare("SELECT cl.*, u.name as actor_name, u.user_type as actor_role FROM complaint_logs cl JOIN users u ON cl.done_by = u.id WHERE cl.complaint_id = ? ORDER BY cl.id ASC");
            $stmt_logs->execute([$complaint['id']]);
            $logs = $stmt_logs->fetchAll();
            
            // Fetch official replies
            $stmt_replies = $pdo->prepare("SELECT ar.*, u.name as replier_name FROM admin_replies ar JOIN users u ON ar.replied_by = u.id WHERE ar.complaint_id = ? ORDER BY ar.id ASC");
            $stmt_replies->execute([$complaint['id']]);
            $replies = $stmt_replies->fetchAll();
        }
    } catch (PDOException $e) {
        $error = "Search operation failed: " . $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="form-container" style="max-width: 850px; border-left-color: var(--navy);">
    <h2 style="text-align: center; margin-bottom: 1.5rem; color: var(--navy);"><?php echo t('public_tracking_title'); ?></h2>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <span><?php echo $error; ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success">
            <span><?php echo $success_msg; ?></span>
        </div>
    <?php endif; ?>

    <!-- Search Input Form -->
    <form action="/track_complaint.php" method="GET" class="no-print">
        <div class="form-group">
            <label for="search"><?php echo t('enter_ticket_tracking'); ?></label>
            <div style="display:flex; gap:10px;">
                <input type="text" id="search" name="search" class="form-control" placeholder="CMP-20260609-0001 or 9876543210" value="<?php echo htmlspecialchars($_GET['ticket_id'] ?? $_GET['search'] ?? ''); ?>" required>
                <button type="submit" class="btn btn-accent" style="white-space:nowrap;"><?php echo t('btn_track'); ?></button>
            </div>
        </div>
    </form>

    <!-- Case A: Multiple matching complaints from mobile lookup -->
    <?php if (!empty($multiple_matches)): ?>
        <div style="margin-top:2rem;">
            <h3 style="color:var(--navy); font-size:1.1rem; border-bottom:1px solid var(--border-color); padding-bottom:5px; margin-bottom:1rem;">Complaints Linked to Mobile Number</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Ticket ID</th>
                            <th>Category</th>
                            <th>Date Submitted</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($multiple_matches as $m): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($m['ticket_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($m['category']); ?><br><span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($m['sub_category']); ?></span></td>
                                <td><?php echo date('d-m-Y', strtotime($m['created_at'])); ?></td>
                                <td>
                                    <?php 
                                        $cls = 'badge-pending';
                                        if ($m['status'] === 'In Progress') $cls = 'badge-progress';
                                        elseif ($m['status'] === 'Resolved') $cls = 'badge-resolved';
                                        elseif ($m['status'] === 'Closed') $cls = 'badge-closed';
                                    ?>
                                    <span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($m['status']); ?></span>
                                </td>
                                <td>
                                    <a href="/track_complaint.php?ticket_id=<?php echo urlencode($m['ticket_id']); ?>" class="btn btn-secondary btn-sm" style="font-size:0.75rem; padding:4px 8px;">Select</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Case B: Loaded Single Complaint Timeline -->
    <?php if ($complaint): ?>
        <hr style="margin: 2rem 0; border: none; border-top: 1px solid var(--border-color);">
        
        <!-- Ticket Information Table -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; border-bottom: 2px solid var(--navy); padding-bottom:10px; margin-bottom:1.5rem;">
            <h3 style="margin:0; color:var(--navy);">Ticket Status Details</h3>
            <button onclick="window.print()" class="btn btn-secondary btn-sm no-print">🖨️ Print Receipt</button>
        </div>

        <table style="width: 100%; font-size: 0.85rem; margin-bottom: 2rem;">
            <tr style="background: none;"><td style="width:30%; font-weight:bold; border:none; padding:4px 0;">Ticket ID:</td><td style="border:none; padding:4px 0;"><strong><?php echo htmlspecialchars($complaint['ticket_id']); ?></strong></td></tr>
            <tr style="background: none;"><td style="font-weight:bold; border:none; padding:4px 0;">Category / Sub:</td><td style="border:none; padding:4px 0;"><?php echo htmlspecialchars($complaint['category']); ?> &raquo; <?php echo htmlspecialchars($complaint['sub_category']); ?></td></tr>
            <tr style="background: none;"><td style="font-weight:bold; border:none; padding:4px 0;">Ward / Priority:</td><td style="border:none; padding:4px 0;"><strong><?php echo htmlspecialchars($complaint['ward']); ?></strong> | <span class="badge badge-priority-<?php echo strtolower($complaint['priority']); ?>"><?php echo htmlspecialchars($complaint['priority']); ?></span></td></tr>
            <tr style="background: none;"><td style="font-weight:bold; border:none; padding:4px 0;">Current Status:</td><td style="border:none; padding:4px 0;">
                <?php 
                    $badge_class = 'badge-pending';
                    if ($complaint['status'] === 'In Progress') $badge_class = 'badge-progress';
                    elseif ($complaint['status'] === 'Resolved') $badge_class = 'badge-resolved';
                    elseif ($complaint['status'] === 'Closed') $badge_class = 'badge-closed';
                ?>
                <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($complaint['status']); ?></span>
            </td></tr>
            <tr style="background: none;"><td style="font-weight:bold; border:none; padding:4px 0;">Submitted On:</td><td style="border:none; padding:4px 0;"><?php echo date('d-m-Y H:i A', strtotime($complaint['created_at'])); ?></td></tr>
            <tr style="background: none;"><td style="font-weight:bold; border:none; padding:4px 0;">Location Details:</td><td style="border:none; padding:4px 0;"><?php echo htmlspecialchars($complaint['address']); ?></td></tr>
            <?php if ($complaint['latitude'] && $complaint['longitude']): ?>
                <tr style="background: none;"><td style="font-weight:bold; border:none; padding:4px 0;">GPS Coordinates:</td><td style="border:none; padding:4px 0;">Latitude: <?php echo htmlspecialchars($complaint['latitude']); ?>, Longitude: <?php echo htmlspecialchars($complaint['longitude']); ?></td></tr>
            <?php endif; ?>
            <tr style="background: none;"><td style="font-weight:bold; border:none; padding:4px 0;">Description:</td><td style="border:none; padding:4px 0; font-style:italic;"><?php echo htmlspecialchars($complaint['description']); ?></td></tr>
            <?php if ($complaint['photo_path']): ?>
                <tr style="background: none;"><td style="font-weight:bold; border:none; padding:4px 0;">Grievance Photo:</td><td style="border:none; padding:4px 0;">
                    <a href="/<?php echo htmlspecialchars($complaint['photo_path']); ?>" target="_blank">
                        <img src="/<?php echo htmlspecialchars($complaint['photo_path']); ?>" style="max-width:180px; border:1px solid #ddd; border-radius:4px; padding:4px; background:#white;" alt="Complaint Photo">
                    </a>
                </td></tr>
            <?php endif; ?>
        </table>

        <!-- Step timeline representation -->
        <h3 style="color:var(--navy); font-size:1.1rem; border-bottom:1px solid var(--border-color); padding-bottom:5px; margin-bottom:1.5rem;">Resolution Timeline Status</h3>
        
        <?php
            $status = $complaint['status'];
            // Detect step active levels based on logs
            $has_assign = false;
            $has_inspect = false;
            $has_wip = false;
            $has_resolve = ($status === 'Resolved' || $status === 'Closed');
            
            foreach ($logs as $l) {
                if ($l['action'] === 'Assigned to Department') $has_assign = true;
                if ($l['action'] === 'Under Inspection') $has_inspect = true;
                if ($l['action'] === 'Work in Progress') $has_wip = true;
            }
        ?>
        <ul class="stepper-vertical">
            <!-- Step 1: Submitted -->
            <li class="stepper-step completed">
                <div class="step-title"><?php echo t('stepper_submitted'); ?></div>
                <div class="step-desc">Grievance successfully registered on portal. Date: <?php echo date('d-m-Y', strtotime($complaint['created_at'])); ?></div>
            </li>
            <!-- Step 2: Assigned -->
            <li class="stepper-step <?php echo $has_assign ? 'completed' : ($status === 'Pending' ? 'active' : ''); ?>">
                <div class="step-title"><?php echo t('stepper_assigned'); ?></div>
                <div class="step-desc">
                    <?php if ($has_assign): ?>
                        Assigned to <strong><?php echo htmlspecialchars($complaint['dept_name'] ?? 'Municipal Department'); ?></strong>.
                    <?php else: ?>
                        Pending administrative review and allocation.
                    <?php endif; ?>
                </div>
            </li>
            <!-- Step 3: Under Inspection -->
            <li class="stepper-step <?php echo $has_inspect ? 'completed' : ($has_assign && !$has_inspect ? 'active' : ''); ?>">
                <div class="step-title"><?php echo t('stepper_inspect'); ?></div>
                <div class="step-desc">
                    <?php if ($has_inspect): ?>
                        Technical inspection of site completed. Work list created.
                    <?php else: ?>
                        Awaiting technician dispatch and inspection.
                    <?php endif; ?>
                </div>
            </li>
            <!-- Step 4: Work in Progress -->
            <li class="stepper-step <?php echo $has_wip ? 'completed' : ($has_inspect && !$has_wip ? 'active' : ''); ?>">
                <div class="step-title"><?php echo t('stepper_wip'); ?></div>
                <div class="step-desc">
                    <?php if ($has_wip): ?>
                        Repair work is actively being processed by municipal contractors.
                    <?php else: ?>
                        Awaiting dispatch of materials and repair crews.
                    <?php endif; ?>
                </div>
            </li>
            <!-- Step 5: Resolved -->
            <li class="stepper-step <?php echo $has_resolve ? 'completed' : ($has_wip && !$has_resolve ? 'active' : ''); ?>">
                <div class="step-title"><?php echo t('stepper_resolved'); ?></div>
                <div class="step-desc">
                    <?php if ($has_resolve): ?>
                        Resolution confirmed by Ward Officer. Action completed.
                    <?php else: ?>
                        Awaiting final work completion signature.
                    <?php endif; ?>
                </div>
            </li>
        </ul>

        <!-- Timeline Remarks list -->
        <h3 style="color:var(--navy); font-size:1.1rem; border-bottom:1px solid var(--border-color); padding-bottom:5px; margin-top:2.5rem; margin-bottom:1rem;"><?php echo t('official_remarks'); ?></h3>
        <?php if (empty($logs)): ?>
            <p style="font-size:0.85rem; color:var(--text-muted);">No timeline logs available.</p>
        <?php else: ?>
            <div style="background-color: var(--white); border: 1px solid var(--border-color); border-radius:var(--radius); padding:10px 15px; margin-bottom:2rem;">
                <?php foreach ($logs as $l): ?>
                    <div style="padding:10px 0; border-bottom:1px solid #eee; font-size:0.8rem;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:3px;">
                            <strong><?php echo htmlspecialchars($l['action']); ?></strong>
                            <span style="color:var(--text-muted);"><?php echo date('d-m-Y h:i A', strtotime($l['done_at'])); ?></span>
                        </div>
                        <p style="color:#495057; margin-bottom:3px;"><?php echo htmlspecialchars($l['remarks']); ?></p>
                        <span style="font-size:0.7rem; color:var(--text-muted);">Executed by: <?php echo htmlspecialchars($l['actor_name']); ?> (<?php echo htmlspecialchars(ucfirst($l['actor_role'])); ?>)</span>
                        <?php if ($l['photo_path']): ?>
                            <div style="margin-top:5px;">
                                <a href="/<?php echo htmlspecialchars($l['photo_path']); ?>" target="_blank" style="font-size:0.75rem; font-weight:bold; color:var(--saffron);">View Attached Action Image</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Replies Panel -->
        <h3 style="color:var(--navy); font-size:1.1rem; border-bottom:1px solid var(--border-color); padding-bottom:5px; margin-bottom:1rem;">Official Communication Thread</h3>
        <?php if (empty($replies)): ?>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:2rem;">No official replies posted yet.</p>
        <?php else: ?>
            <div style="margin-bottom:2rem;">
                <?php foreach ($replies as $r): ?>
                    <div style="background-color:#fff3e0; border-left:4px solid var(--saffron); border-radius:4px; padding:15px; margin-bottom:10px; font-size:0.85rem;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:5px; font-weight:bold; color:var(--navy);">
                            <span>Official Reply from <?php echo htmlspecialchars($r['replier_name']); ?></span>
                            <span style="font-size:0.75rem; color:var(--text-muted);"><?php echo date('d-m-Y h:i A', strtotime($r['replied_at'])); ?></span>
                        </div>
                        <p style="color: #333; margin-bottom: 5px;"><?php echo htmlspecialchars($r['reply_text']); ?></p>
                        <?php if ($r['photo_path']): ?>
                            <div>
                                <a href="/<?php echo htmlspecialchars($r['photo_path']); ?>" target="_blank" style="font-weight:bold; color:var(--navy);">Resolution Photo attachment</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Citizen Follow-up commenting form -->
        <div class="no-print" style="background-color:var(--bg-light); border:1px solid var(--border-color); padding:20px; border-radius:var(--radius); margin-top:2rem;">
            <h4 style="margin-bottom:10px;"><?php echo t('add_followup'); ?></h4>
            <form action="/track_complaint.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="complaint_id" value="<?php echo $complaint['id']; ?>">
                
                <div class="form-group">
                    <label for="comment_text" style="display:none;">Comment</label>
                    <textarea id="comment_text" name="comment_text" class="form-control" rows="3" placeholder="Provide additional details or progress update inquiries..." required></textarea>
                </div>
                <button type="submit" name="post_followup" class="btn btn-accent" style="margin-top:10px;"><?php echo t('post_comment'); ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
