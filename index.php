<?php
// index.php
// CityCare Digital Complaint Portal - Home Page

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/db/connect.php';

$page_title = t('nav_home');
$breadcrumbs = []; // Home is the root, no breadcrumbs needed

// Initialize stats with fallbacks
$total_count = 0;
$resolved_count = 0;
$pending_count = 0;
$dept_count = 0;
$db_needs_setup = false;

try {
    // Attempt queries to verify DB table presence
    $total_count = $pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
    $resolved_count = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status IN ('Resolved', 'Closed')")->fetchColumn();
    $pending_count = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status = 'Pending'")->fetchColumn();
    $dept_count = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
} catch (PDOException $e) {
    // Tables might not be seeded yet
    $db_needs_setup = true;
    
    // Fallback numbers for visual demo when installer hasn't run
    $total_count = 1450;
    $resolved_count = 1120;
    $pending_count = 330;
    $dept_count = 6;
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Setup Alert Banner -->
<?php if ($db_needs_setup): ?>
    <div class="alert alert-danger" style="margin-top: 15px;">
        <span><strong>⚙️ Database Setup Required:</strong> The database tables have not been initialized yet. Please click the button to set up tables and seed demo data: </span>
        <a href="/db/install.php" class="btn btn-accent btn-sm" style="margin-left:15px; padding: 4px 10px; font-size:12px; display:inline-block;">Run DB Installer</a>
    </div>
<?php endif; ?>

<!-- Hero Section -->
<section class="hero" aria-labelledby="hero-heading">
    <div class="hero-content">
        <h2 id="hero-heading"><?php echo t('hero_title'); ?></h2>
        <p><?php echo t('hero_subtitle'); ?></p>
        <div class="btn-group" style="justify-content: center;">
            <a href="/submit_complaint.php" class="btn btn-accent"><?php echo t('hero_cta'); ?></a>
            <a href="/track_complaint.php" class="btn btn-secondary" style="border-color: white; "><?php echo t('nav_track'); ?></a>
        </div>
    </div>
</section>

<!-- Stats Bar -->
<section class="stats-bar" aria-label="Portal Grievance Statistics">
    <div class="stat-card saffron-border">
        <span class="stat-label"><?php echo t('stats_total'); ?></span>
        <div class="stat-number" id="counter-total"><?php echo $total_count; ?></div>
    </div>
    <div class="stat-card resolved-border">
        <span class="stat-label"><?php echo t('stats_resolved'); ?></span>
        <div class="stat-number" id="counter-resolved"><?php echo $resolved_count; ?></div>
    </div>
    <div class="stat-card pending-border">
        <span class="stat-label"><?php echo t('stats_pending'); ?></span>
        <div class="stat-number" id="counter-pending"><?php echo $pending_count; ?></div>
    </div>
    <div class="stat-card">
        <span class="stat-label"><?php echo t('stats_active_depts'); ?></span>
        <div class="stat-number" id="counter-depts"><?php echo $dept_count; ?></div>
    </div>
</section>

<!-- Categories Grid -->
<section style="margin-bottom: 3rem;">
    <h2 class="section-title"><?php echo t('cat_title'); ?></h2>
    <div class="grid-categories">
        <div class="category-card" onclick="location.href='/submit_complaint.php?cat=Road+%26+Potholes'">
            <span class="category-icon" role="img" aria-label="Road icon">🛣️</span>
            <h3><?php echo t('cat_road'); ?></h3>
            <p>Report damaged asphalt, municipal potholes, broken pavers, and street paving issues.</p>
        </div>
        <div class="category-card" onclick="location.href='/submit_complaint.php?cat=Garbage+Collection'">
            <span class="category-icon" role="img" aria-label="Garbage icon">🗑️</span>
            <h3><?php echo t('cat_garbage'); ?></h3>
            <p>Report skipped collections, overflowing street bins, illegal dumping, and market waste.</p>
        </div>
        <div class="category-card" onclick="location.href='/submit_complaint.php?cat=Water+Leakage'">
            <span class="category-icon" role="img" aria-label="Water pipe icon">💧</span>
            <h3><?php echo t('cat_water'); ?></h3>
            <p>Report leaking water mains, pipeline bursts, supply contamination, or zero supply hours.</p>
        </div>
        <div class="category-card" onclick="location.href='/submit_complaint.php?cat=Street+Lights'">
            <span class="category-icon" role="img" aria-label="Street light icon">💡</span>
            <h3><?php echo t('cat_lights'); ?></h3>
            <p>Report non-functional streetlights, blinking poles, dangerous wiring sparks, or damage.</p>
        </div>
        <div class="category-card" onclick="location.href='/submit_complaint.php?cat=Drainage+Blockage'">
            <span class="category-icon" role="img" aria-label="Drainage blockage icon">🪠</span>
            <h3><?php echo t('cat_drainage'); ?></h3>
            <p>Report backed-up sewers, manhole overflow, clogged storm drains, or missing manhole covers.</p>
        </div>
        <div class="category-card" onclick="location.href='/submit_complaint.php?cat=Illegal+Construction'">
            <span class="category-icon" role="img" aria-label="Construction icon">🏗️</span>
            <h3><?php echo t('cat_illegal'); ?></h3>
            <p>Report commercial encroachment, building height violations, unauthorized additions, and hazards.</p>
        </div>
    </div>
</section>

<!-- How It Works Timeline Stepper -->
<section class="steps-section" aria-labelledby="steps-heading">
    <h2 class="section-title" id="steps-heading"><?php echo t('how_works_title'); ?></h2>
    <div class="steps-grid">
        <div class="step-card">
            <div class="step-number">1</div>
            <h4><?php echo t('step_1_title'); ?></h4>
            <p><?php echo t('step_1_desc'); ?></p>
        </div>
        <div class="step-card">
            <div class="step-number">2</div>
            <h4><?php echo t('step_2_title'); ?></h4>
            <p><?php echo t('step_2_desc'); ?></p>
        </div>
        <div class="step-card">
            <div class="step-number">3</div>
            <h4><?php echo t('step_3_title'); ?></h4>
            <p><?php echo t('step_3_desc'); ?></p>
        </div>
        <div class="step-card">
            <div class="step-number">4</div>
            <h4><?php echo t('step_4_title'); ?></h4>
            <p><?php echo t('step_4_desc'); ?></p>
        </div>
        <div class="step-card">
            <div class="step-number">5</div>
            <h4><?php echo t('step_5_title'); ?></h4>
            <p><?php echo t('step_5_desc'); ?></p>
        </div>
    </div>
</section>

<!-- Simple JS Animation script for Stats Counters -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const animateCounter = (id, targetVal) => {
        const el = document.getElementById(id);
        if(!el) return;
        let count = 0;
        const speed = Math.ceil(targetVal / 80);
        const timer = setInterval(() => {
            count += speed;
            if (count >= targetVal) {
                el.innerText = targetVal;
                clearInterval(timer);
            } else {
                el.innerText = count;
            }
        }, 15);
    };
    
    // Animate stats
    animateCounter('counter-total', <?php echo $total_count; ?>);
    animateCounter('counter-resolved', <?php echo $resolved_count; ?>);
    animateCounter('counter-pending', <?php echo $pending_count; ?>);
    animateCounter('counter-depts', <?php echo $dept_count; ?>);
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
