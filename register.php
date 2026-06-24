<?php
// register.php
// User Registration script with input validations, CSRF protection, and simulated OTP mechanism.

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/db/connect.php';

$page_title = t('nav_register');
$breadcrumbs = [t('nav_register') => ''];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = "CSRF Token Validation Failed. Security Violation.";
    } else {
        // 2. Read and sanitize inputs
        $name = sanitize($_POST['name'] ?? '');
        $mobile = sanitize($_POST['mobile'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $address = sanitize($_POST['address'] ?? '');
        $ward = sanitize($_POST['ward'] ?? '');
        $user_type = sanitize($_POST['user_type'] ?? 'citizen');
        $language = sanitize($_POST['language'] ?? 'en');

        // Validation Checks
        if (empty($name) || empty($mobile) || empty($email) || empty($password) || empty($address) || empty($ward)) {
            $error = t('error_required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('error_email_format');
        } elseif (!preg_match('/^\d{10}$/', $mobile)) {
            $error = t('error_mobile_format');
        } elseif ($password !== $confirm_password) {
            $error = t('error_password_match');
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            try {
                // Check if user already exists
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR mobile = ?");
                $stmt->execute([$email, $mobile]);
                if ($stmt->fetchColumn() > 0) {
                    $error = "A user account with this email address or mobile number already exists.";
                } else {
                    // Hash Password securely
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    
                    // Insert record
                    $stmt = $pdo->prepare("INSERT INTO users (name, mobile, email, password_hash, address, ward, user_type, language, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                    $stmt->execute([$name, $mobile, $email, $password_hash, $address, $ward, $user_type, $language]);
                    
                    $new_user_id = $pdo->lastInsertId();
                    
                    // If staff, initialize department assignment (defaults to ROAD, can be managed by admin)
                    if ($user_type === 'staff') {
                        // Find first department
                        $dept_id = $pdo->query("SELECT id FROM departments LIMIT 1")->fetchColumn();
                        if ($dept_id) {
                            $stmt_assign = $pdo->prepare("INSERT INTO staff_assignments (user_id, department_id) VALUES (?, ?)");
                            $stmt_assign->execute([$new_user_id, $dept_id]);
                        }
                    }
                    
                    // Simulate email registration welcome message
                    $mail_subject = "Welcome to CityCare Grievance Portal";
                    $mail_body = "Dear {$name},\n\nYour profile has been created successfully. Your account is active. Use your mobile number or email to login.";
                    log_simulated_email($pdo, $email, $mail_subject, $mail_body);
                    
                    // Redirect on success
                    $_SESSION['success_message'] = t('success_register');
                    header("Location: /login.php");
                    exit();
                }
            } catch (PDOException $e) {
                $error = "Database registry error: " . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="form-container">
    <h2 style="text-align: center; margin-bottom: 1.5rem; color: var(--navy);"><?php echo t('nav_register'); ?></h2>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert">
            <span><?php echo $error; ?></span>
        </div>
    <?php endif; ?>

    <form id="registerForm" action="/register.php" method="POST">
        <?php echo csrf_field(); ?>
        
        <div class="form-group">
            <label for="name"><?php echo t('full_name'); ?> *</label>
            <input type="text" id="name" name="name" class="form-control" placeholder="<?php echo t('enter_full_name'); ?>" required>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="mobile"><?php echo t('mobile_number'); ?> *</label>
                <input type="tel" id="mobile" name="mobile" class="form-control" placeholder="<?php echo t('enter_mobile'); ?>" required pattern="\d{10}">
            </div>
            <div class="form-group">
                <label for="email"><?php echo t('email_address'); ?> *</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="<?php echo t('enter_email'); ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="password"><?php echo t('password'); ?> *</label>
                <input type="password" id="password" name="password" class="form-control" required minlength="6">
                <div class="strength-meter">
                    <div id="strengthBar" class="strength-bar"></div>
                </div>
                <div id="strengthText" class="strength-text" 
                     data-weak="<?php echo t('strength_weak'); ?>"
                     data-medium="<?php echo t('strength_medium'); ?>"
                     data-strong="<?php echo t('strength_strong'); ?>"></div>
            </div>
            <div class="form-group">
                <label for="confirm_password"><?php echo t('confirm_password'); ?> *</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
            </div>
        </div>

        <div class="form-group">
            <label for="address"><?php echo t('address'); ?> *</label>
            <textarea id="address" name="address" class="form-control" rows="2" placeholder="<?php echo t('enter_address'); ?>" required></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="ward"><?php echo t('ward_zone'); ?> *</label>
                <select id="ward" name="ward" class="form-control" required>
                    <option value=""><?php echo t('select_ward'); ?></option>
                    <option value="Ward 1">Ward 1 - East Zone</option>
                    <option value="Ward 2">Ward 2 - West Zone</option>
                    <option value="Ward 3">Ward 3 - North Zone</option>
                    <option value="Ward 4">Ward 4 - South Zone</option>
                    <option value="Ward 5">Ward 5 - Central Zone</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="user_type"><?php echo t('user_type'); ?> *</label>
                <select id="user_type" name="user_type" class="form-control" required>
                    <option value="citizen"><?php echo t('citizen'); ?></option>
                    <option value="staff"><?php echo t('staff'); ?></option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="language"><?php echo t('lang_pref'); ?></label>
            <select id="language" name="language" class="form-control">
                <option value="en">English</option>
                <option value="gu">ગુજરાતી</option>
                <option value="hi">हिन्दी</option>
            </select>
        </div>

        <button type="submit" class="btn btn-accent" style="width: 100%; margin-top: 1rem;"><?php echo t('submit'); ?></button>
    </form>
    
    <p style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem;">
        Already registered? <a href="/login.php" style="font-weight: bold;"><?php echo t('login_title'); ?></a>
    </p>
</div>

<!-- OTP Verification Modal -->
<div id="otpModal" class="modal-overlay" role="dialog" aria-labelledby="modal-heading">
    <div class="modal-content">
        <span class="modal-close">&times;</span>
        <h3 id="modal-heading" style="color: var(--navy); margin-bottom: 1rem;"><?php echo t('otp_heading'); ?></h3>
        <p style="font-size: 0.85rem; margin-bottom: 1.5rem; color: var(--text-dark);"><?php echo t('otp_helper'); ?></p>
        
        <div class="form-group">
            <label for="otp_code"><?php echo t('otp_code'); ?></label>
            <input type="text" id="otp_code" class="form-control" style="text-align: center; font-size: 1.4rem; letter-spacing: 5px; font-weight: bold;" maxlength="4" placeholder="0000" required>
        </div>
        
        <button type="button" id="verifyOtpBtn" class="btn btn-accent" style="width: 100%; margin-top: 10px;"><?php echo t('verify_otp'); ?></button>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
