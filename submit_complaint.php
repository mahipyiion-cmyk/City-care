<?php
// submit_complaint.php
// Grievance submission form with CSRF validation, file uploading, and receipts.

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/db/connect.php';

// Access Gate
require_citizen();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_ward = $_SESSION['user_ward'];

$page_title = t('lodge_title');
$breadcrumbs = [t('nav_dashboard') => '/citizen_dashboard.php', t('nav_lodge') => ''];

$error = '';
$success_ticket = $_GET['success'] ?? '';
$receipt = null;

// If success ticket is passed, query details for receipt display
if (!empty($success_ticket)) {
    try {
        $stmt_rec = $pdo->prepare("SELECT c.*, u.name as citizen_name FROM complaints c JOIN users u ON c.citizen_id = u.id WHERE c.ticket_id = ? AND c.citizen_id = ?");
        $stmt_rec->execute([$success_ticket, $user_id]);
        $receipt = $stmt_rec->fetch();
        
        if (!$receipt) {
            $success_ticket = ''; // Reset if not found or unauthorized
        }
    } catch (PDOException $e) {
        $success_ticket = '';
    }
}

// Process Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($success_ticket)) {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = "CSRF Token Validation Failed. Security Violation.";
    } else {
        $category = sanitize($_POST['category'] ?? '');
        $sub_category = sanitize($_POST['sub_category'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $ward = sanitize($_POST['ward'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $priority = sanitize($_POST['priority'] ?? 'Normal');
        $latitude = $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
        $longitude = $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
        
        // Validations
        if (empty($category) || empty($sub_category) || empty($address) || empty($ward) || empty($description)) {
            $error = "Please fill in all mandatory fields.";
        } elseif (strlen($description) < 30 || strlen($description) > 500) {
            $error = "Description must be between 30 and 500 characters.";
        } else {
            // Process photo upload
            $photo_path = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = upload_image($_FILES['photo'], 'uploads/');
                if ($upload['status']) {
                    $photo_path = $upload['path'];
                } else {
                    $error = "Photo Upload Failed: " . $upload['error'];
                }
            }
            
            if (empty($error)) {
                try {
                    // Generate unique ticket ID
                    $ticket_id = generate_ticket_id($pdo);
                    
                    // Insert complaint
                    $stmt = $pdo->prepare("INSERT INTO complaints (ticket_id, citizen_id, category, sub_category, description, photo_path, latitude, longitude, ward, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                    $stmt->execute([
                        $ticket_id,
                        $user_id,
                        $category,
                        $sub_category,
                        $description,
                        $photo_path,
                        $latitude,
                        $longitude,
                        $ward,
                        $priority
                    ]);
                    
                    $complaint_id = $pdo->lastInsertId();
                    
                    // Add audit log
                    $stmt_log = $pdo->prepare("INSERT INTO complaint_logs (complaint_id, action, remarks, done_by) VALUES (?, 'Submitted', 'Grievance registered on digital portal.', ?)");
                    $stmt_log->execute([$complaint_id, $user_id]);
                    
                    // Add citizen notification
                    add_notification($pdo, $user_id, "Grievance Filed", "Your complaint regarding {$category} has been logged under Ticket ID: {$ticket_id}.");
                    
                    // Simulate email trigger
                    $subject = "CityCare Complaint Logged: " . $ticket_id;
                    $body = "Dear {$user_name},\n\nYour civic complaint regarding '{$category} - {$sub_category}' has been successfully logged with ticket ID {$ticket_id}.\nYou can track its status live at: http://localhost/track_complaint.php?ticket_id={$ticket_id}\n\nThank you,\nCityCare Digital Grievance Cell.";
                    log_simulated_email($pdo, $_SESSION['user_email'], $subject, $body);
                    
                    // Redirect to prevent duplicate posts
                    header("Location: /submit_complaint.php?success=" . urlencode($ticket_id));
                    exit();
                } catch (PDOException $e) {
                    $error = "Failed to log grievance in database: " . $e->getMessage();
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if (!empty($success_ticket) && $receipt): ?>
    <!-- SUCCESS RECEIPT VIEW -->
    <div class="form-container" style="max-width: 750px; text-align: center; border-left-color: var(--green);">
        <div class="no-print" style="margin-bottom: 20px;">
            <div style="font-size: 4rem; color: var(--green);">✅</div>
            <h2 style="color: var(--green);">Complaint Registered Successfully</h2>
            <p style="color: var(--text-dark); margin-bottom: 15px;">Your grievance has been logged and assigned to the municipal department queue.</p>
        </div>
        
        <!-- Printable Ticket Card -->
        <div style="text-align: left; padding: 25px; border: 1px solid var(--border-color); border-radius: var(--radius); background-color: var(--bg-light); margin-bottom: 20px;">
            <div style="display:flex; justify-content:space-between; border-bottom: 2px solid var(--navy); padding-bottom: 10px; margin-bottom: 15px;">
                <h3 style="margin:0; color: var(--navy); font-size: 1.25rem;">CITYCARE CIVIC RECEIPT</h3>
                <strong style="color: var(--red); font-size:1.1rem;"><?php echo htmlspecialchars($receipt['ticket_id']); ?></strong>
            </div>
            
            <table style="width: 100%; border: none;">
                <tr style="background: none;"><td style="width: 35%; font-weight: bold; border:none; padding: 6px 0;">Citizen Name:</td><td style="border:none; padding: 6px 0;"><?php echo htmlspecialchars($receipt['citizen_name']); ?></td></tr>
                <tr style="background: none;"><td style="font-weight: bold; border:none; padding: 6px 0;">Date Filed:</td><td style="border:none; padding: 6px 0;"><?php echo date('d M Y, h:i A', strtotime($receipt['created_at'])); ?></td></tr>
                <tr style="background: none;"><td style="font-weight: bold; border:none; padding: 6px 0;">Category:</td><td style="border:none; padding: 6px 0;"><?php echo htmlspecialchars($receipt['category']); ?> (<?php echo htmlspecialchars($receipt['sub_category']); ?>)</td></tr>
                <tr style="background: none;"><td style="font-weight: bold; border:none; padding: 6px 0;">Ward / Address:</td><td style="border:none; padding: 6px 0;"><strong><?php echo htmlspecialchars($receipt['ward']); ?></strong> - <?php echo htmlspecialchars($receipt['description']); ?></td></tr>
                <tr style="background: none;"><td style="font-weight: bold; border:none; padding: 6px 0;">Priority / Status:</td><td style="border:none; padding: 6px 0;"><span class="badge badge-priority-<?php echo strtolower($receipt['priority']); ?>"><?php echo htmlspecialchars($receipt['priority']); ?></span> | <span class="badge badge-pending"><?php echo htmlspecialchars($receipt['status']); ?></span></td></tr>
                <?php if ($receipt['latitude'] && $receipt['longitude']): ?>
                    <tr style="background: none;"><td style="font-weight: bold; border:none; padding: 6px 0;">Geotag coordinates:</td><td style="border:none; padding: 6px 0;"><?php echo htmlspecialchars($receipt['latitude']); ?>, <?php echo htmlspecialchars($receipt['longitude']); ?></td></tr>
                <?php endif; ?>
            </table>
            
            <p style="margin-top: 15px; font-size:0.75rem; color: var(--text-muted); line-height: 1.4; border-top: 1px solid var(--border-color); padding-top: 10px;">
                Note: You can use this Ticket ID to track the real-time resolution status of this complaint from our public portal home page without logging in.
            </p>
        </div>
        
        <div class="btn-group no-print" style="justify-content: center;">
            <button onclick="window.print()" class="btn btn-secondary">🖨️ Print Receipt</button>
            <a href="/citizen_dashboard.php" class="btn btn-accent">Go to Dashboard</a>
        </div>
    </div>

<?php else: ?>
    <!-- FORM ENTRY VIEW -->
    <div class="form-container">
        <h2 style="text-align: center; margin-bottom: 1.5rem; color: var(--navy);"><?php echo t('lodge_title'); ?></h2>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <form action="/submit_complaint.php" method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            
            <!-- Hidden coordinates -->
            <input type="hidden" id="latitude" name="latitude" value="">
            <input type="hidden" id="longitude" name="longitude" value="">

            <div class="form-row">
                <div class="form-group">
                    <label>Citizen Name (Auto-filled)</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_name); ?>" disabled style="background-color: #e9ecef;">
                </div>
                <div class="form-group">
                    <label><?php echo t('date_today'); ?></label>
                    <input type="text" class="form-control" value="<?php echo date('d-m-Y'); ?>" disabled style="background-color: #e9ecef;">
                </div>
            </div>

            <div class="form-group">
                <label for="complaint_category"><?php echo t('category'); ?> *</label>
                <select id="complaint_category" name="category" class="form-control" required>
                    <option value="">-- Select Category --</option>
                    <?php
                        $cats = ['Road & Potholes', 'Garbage Collection', 'Water Leakage', 'Street Lights', 'Drainage Blockage', 'Illegal Construction', 'Stray Animals', 'Other Municipal Issues'];
                        $pre_select = $_GET['cat'] ?? '';
                        foreach ($cats as $cat) {
                            $sel = ($pre_select === $cat) ? 'selected' : '';
                            echo "<option value=\"$cat\" $sel>" . t('cat_' . strtolower(explode(' ', $cat)[0])) . "</option>";
                        }
                    ?>
                </select>
            </div>

            <!-- Dynamic Sub-category loaded via JS in main.js -->
            <div class="form-group" id="sub_category_group" style="display: <?php echo !empty($pre_select) ? 'block' : 'none'; ?>;">
                <label for="complaint_sub_category"><?php echo t('sub_category'); ?> *</label>
                <select id="complaint_sub_category" name="sub_category" class="form-control <?php echo empty($pre_select) ? 'sub-cat-select' : ''; ?>">
                    <!-- Options populated via JS -->
                    <?php if (!empty($pre_select)): ?>
                        <!-- Fallback template populate in case of page reload with preselect -->
                        <option value="">-- Choose Sub-category --</option>
                        <script>
                            document.addEventListener("DOMContentLoaded", function() {
                                // Trigger category change event manually to load items
                                document.getElementById('complaint_category').dispatchEvent(new Event('change'));
                            });
                        </script>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="ward"><?php echo t('ward_zone'); ?> *</label>
                    <select id="ward" name="ward" class="form-control" required>
                        <option value=""><?php echo t('select_ward'); ?></option>
                        <?php
                            $wards = ['Ward 1', 'Ward 2', 'Ward 3', 'Ward 4', 'Ward 5'];
                            foreach ($wards as $w) {
                                $sel = ($user_ward === $w) ? 'selected' : '';
                                echo "<option value=\"$w\" $sel>$w</option>";
                            }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="priority"><?php echo t('priority_level'); ?></label>
                    <select id="priority" name="priority" class="form-control">
                        <option value="Normal"><?php echo t('priority_normal'); ?></option>
                        <option value="Urgent"><?php echo t('priority_urgent'); ?></option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="address"><?php echo t('address'); ?> / Location Description *</label>
                <input type="text" id="address" name="address" class="form-control" placeholder="Specify exact street name, landmark, near shop, etc." required>
            </div>

            <!-- GPS Geotagging button -->
            <div class="form-group">
                <label><?php echo t('location_label'); ?></label>
                <button type="button" id="gps_trigger" class="btn btn-secondary btn-sm" style="font-size:0.8rem; padding: 6px 12px;">🗺️ <?php echo t('use_my_location'); ?></button>
                <span id="gps_status" style="margin-left:10px; font-size:0.8rem; font-weight:600; color: var(--text-muted);">Coordinates not saved yet (Optional).</span>
            </div>

            <div class="form-group">
                <label for="complaint_desc"><?php echo t('description_label'); ?> *</label>
                <textarea id="complaint_desc" name="description" class="form-control" rows="4" minlength="30" maxlength="500" placeholder="Please describe the issue in detail..." required></textarea>
                <div style="display:flex; justify-content:space-between; font-size:0.75rem; margin-top:5px;">
                    <span style="color: var(--text-muted);"><?php echo t('description_helper'); ?></span>
                    <span style="font-weight:600;"><span id="char_counter" style="color:#D0021B;">0</span> / 500 <?php echo t('characters'); ?></span>
                </div>
            </div>

            <div class="form-group">
                <label for="photo_upload"><?php echo t('upload_photo'); ?></label>
                <input type="file" id="photo_upload" name="photo" class="form-control" accept="image/jpeg, image/png">
                <span style="font-size:0.75rem; color: var(--text-muted);"><?php echo t('upload_photo_helper'); ?></span>
                
                <!-- Image Preview box -->
                <div class="upload-preview" id="preview_wrap">
                    <p style="font-size:0.8rem; font-weight:600; margin-bottom:5px;">Selected Photo Preview:</p>
                    <img id="preview_img" src="#" alt="Thumbnail Preview">
                </div>
            </div>

            <div class="btn-group" style="margin-top: 1.5rem;">
                <button type="submit" class="btn btn-accent"><?php echo t('submit'); ?></button>
                <a href="/citizen_dashboard.php" class="btn btn-secondary"><?php echo t('nav_home'); ?></a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
