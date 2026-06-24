<?php
// login.php
// User and Staff Authentication Portal

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/db/connect.php';

$page_title = t('nav_login');
$breadcrumbs = [t('nav_login') => ''];

$error = '';
$success = '';

// Check if success message is set by registration
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Initialize attempts counter in session
if (!isset($_SESSION['attempts_remaining'])) {
    $_SESSION['attempts_remaining'] = 5;
}

// Process login post
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if locked
    if ($_SESSION['attempts_remaining'] <= 0) {
        $error = "Account temporarily locked due to too many failed attempts. Please contact CityCare Admin.";
    } else {
        // Validate CSRF
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            $error = "CSRF Verification Failed. Security Violation.";
        } else {
            $username = sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $user_type = sanitize($_POST['user_type'] ?? 'citizen');
            
            if (empty($username) || empty($password)) {
                $error = "Please enter both credentials.";
            } else {
                try {
                    // Find user by email OR mobile AND matching user_type
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE (email = ? OR mobile = ?) AND user_type = ?");
                    $stmt->execute([$username, $username, $user_type]);
                    $user = $stmt->fetch();
                    
                    if ($user && password_verify($password, $user['password_hash'])) {
                        // Check if account active
                        if ($user['status'] !== 'active') {
                            $error = "This account is inactive. Please contact the administrator.";
                        } else {
                            // Successful login: reset attempts
                            $_SESSION['attempts_remaining'] = 5;
                            
                            // Regenerate session ID to prevent session fixation
                            session_regenerate_id(true);
                            
                            // Load variables
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['user_name'] = $user['name'];
                            $_SESSION['user_type'] = $user['user_type'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['user_mobile'] = $user['mobile'];
                            $_SESSION['user_ward'] = $user['ward'];
                            $_SESSION['lang'] = $user['language'];
                            
                            // Set cookies if Remember Me is checked
                            if (isset($_POST['remember_me'])) {
                                setcookie("remember_username", $username, time() + (86400 * 30), "/"); // 30 days
                            } else {
                                if (isset($_COOKIE['remember_username'])) {
                                    setcookie("remember_username", "", time() - 3600, "/");
                                }
                            }
                            
                            // Redirect based on user type
                            if ($user['user_type'] === 'admin') {
                                header("Location: /admin/dashboard.php");
                            } elseif ($user['user_type'] === 'staff') {
                                header("Location: /staff/dashboard.php");
                            } else {
                                // Redirect to previous intended page if exists
                                if (isset($_SESSION['login_redirect'])) {
                                    $dest = $_SESSION['login_redirect'];
                                    unset($_SESSION['login_redirect']);
                                    header("Location: " . $dest);
                                } else {
                                    header("Location: /citizen_dashboard.php");
                                }
                            }
                            exit();
                        }
                    } else {
                        // Credentials incorrect, decrement attempts
                        $_SESSION['attempts_remaining']--;
                        if ($_SESSION['attempts_remaining'] <= 0) {
                            $error = "Too many failed attempts. Access to this device has been locked.";
                        } else {
                            $error = "Invalid username or password. Remaining attempts: " . $_SESSION['attempts_remaining'];
                        }
                    }
                } catch (PDOException $e) {
                    $error = "Database authentication error: " . $e->getMessage();
                }
            }
        }
    }
}

// Forgot Password Request Handling (Simulated)
if (isset($_POST['forgot_submit'])) {
    $forgot_email = sanitize($_POST['forgot_email'] ?? '');
    if (filter_var($forgot_email, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $pdo->prepare("SELECT name FROM users WHERE email = ?");
            $stmt->execute([$forgot_email]);
            $name = $stmt->fetchColumn();
            
            if ($name) {
                // Log simulated password reset email
                $reset_token = bin2hex(random_bytes(16));
                $subject = "CityCare Grievance Portal Password Reset";
                $body = "Dear {$name},\n\nYou requested a password reset. Please click this link to complete the reset (Simulated Token: {$reset_token}):\nhttp://localhost/reset_password.php?token={$reset_token}";
                log_simulated_email($pdo, $forgot_email, $subject, $body);
                $success = "A simulated password recovery link has been dispatched to {$forgot_email}. Please check the system mail logs.";
            } else {
                $error = "No account found with this email address.";
            }
        } catch (PDOException $e) {
            $error = "Reset handler failed: " . $e->getMessage();
        }
    } else {
        $error = "Please enter a valid email address.";
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="form-container">
    <h2 style="text-align: center; margin-bottom: 1.5rem; color: var(--navy);"><?php echo t('login_title'); ?></h2>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <span><?php echo $error; ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <span><?php echo $success; ?></span>
        </div>
    <?php endif; ?>

    <form action="/login.php" method="POST">
        <?php echo csrf_field(); ?>
        
        <div class="form-group">
            <label for="username">Username (Email or Mobile Number)</label>
            <input type="text" id="username" name="username" class="form-control" 
                   placeholder="<?php echo t('username_placeholder'); ?>" 
                   value="<?php echo htmlspecialchars($_COOKIE['remember_username'] ?? ''); ?>" 
                   required>
        </div>

        <div class="form-group">
            <label for="password"><?php echo t('password'); ?></label>
            <input type="password" id="password" name="password" class="form-control" required>
        </div>

        <div class="form-group">
            <label for="user_type"><?php echo t('user_type'); ?></label>
            <select id="user_type" name="user_type" class="form-control" required>
                <option value="citizen"><?php echo t('citizen'); ?></option>
                <option value="staff"><?php echo t('staff'); ?></option>
                <option value="admin"><?php echo t('admin'); ?></option>
            </select>
        </div>

        <div class="form-row" style="align-items: center; justify-content: space-between; font-size: 0.85rem; margin-bottom: 1.5rem;">
            <div>
                <input type="checkbox" id="remember_me" name="remember_me" <?php echo isset($_COOKIE['remember_username']) ? 'checked' : ''; ?>>
                <label for="remember_me" style="display: inline; margin-left: 5px; cursor: pointer;"><?php echo t('remember_me'); ?></label>
            </div>
            <a href="#" id="forgotPasswordLink" style="font-weight: 600;"><?php echo t('forgot_password'); ?></a>
        </div>

        <button type="submit" class="btn btn-accent" style="width: 100%;"><?php echo t('login_btn'); ?></button>
    </form>

    <p style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem;">
        New citizen user? <a href="/register.php" style="font-weight: bold;"><?php echo t('nav_register'); ?></a>
    </p>
</div>

<!-- Forgot Password Modal -->
<div id="forgotModal" class="modal-overlay" role="dialog" aria-labelledby="forgot-heading">
    <div class="modal-content">
        <span class="modal-close" id="closeForgot">&times;</span>
        <h3 id="forgot-heading" style="color: var(--navy); margin-bottom: 1rem;"><?php echo t('forgot_password'); ?></h3>
        <p style="font-size: 0.85rem; margin-bottom: 1.5rem; color: var(--text-dark);">Enter your registered email address and we will simulate sending a password reset link.</p>
        
        <form action="/login.php" method="POST">
            <div class="form-group">
                <label for="forgot_email"><?php echo t('email_address'); ?></label>
                <input type="email" id="forgot_email" name="forgot_email" class="form-control" placeholder="example@email.com" required>
            </div>
            <button type="submit" name="forgot_submit" class="btn btn-accent" style="width: 100%; margin-top: 10px;">Send Reset Link</button>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
