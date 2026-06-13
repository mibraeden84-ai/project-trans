<?php
// Refresh user image from DB if session is missing it
if (isLoggedIn() && empty($_SESSION['user_image']) && empty($_SESSION['_img_refreshed'])) {
    $imgUser = $db->fetchOne("SELECT image FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if ($imgUser) {
        $_SESSION['user_image'] = $imgUser['image'];
    }
    $_SESSION['_img_refreshed'] = true;
}

$currentRole = $_SESSION['user_role'] ?? '';
$sidebarRoles = ['user', 'editor', 'viewer', 'admin'];
// Use the sidebar app layout for the full project after login (no top header + no footer).
$useSidebarLayout = isLoggedIn();
$currentPage = $_GET['page'] ?? 'home';
$sidebarPanelLabelMap = [
    'editor' => 'Editor Workspace',
    'viewer' => 'Viewer Workspace',
    'user' => 'User Workspace',
    'admin' => 'Admin Workspace',
];
$sidebarPanelLabel = $sidebarPanelLabelMap[$currentRole] ?? 'Workspace';
$currentSlug = $_GET['slug'] ?? '';
$currentBrandName = $currentSlug ? ucwords(str_replace('-', ' ', $currentSlug)) : 'Brand';
$brandIconOverrides = [
    'teltonika' => 'fa-microchip',
    'galileosky' => 'fa-globe',
    'starlink' => 'fa-satellite',
    'dash-cam' => 'fa-video',
];
$brandIconKeywordMap = [
    'sensor' => 'fa-microchip',
    'can' => 'fa-diagram-project',
    'camera' => 'fa-video',
    'dash cam' => 'fa-video',
    'dashcam' => 'fa-video',
    'video' => 'fa-video',
    'tracker' => 'fa-location-dot',
    'tracking' => 'fa-location-dot',
    'gps' => 'fa-satellite-dish',
    'telematic' => 'fa-tower-broadcast',
    'fleet' => 'fa-truck',
    'obd' => 'fa-car-side',
    'fuel' => 'fa-gas-pump',
    'temperature' => 'fa-temperature-half',
    'iot' => 'fa-wifi',
];
$brandIconPool = [
    'fa-compass',
    'fa-location-dot',
    'fa-tower-broadcast',
    'fa-map-location-dot',
    'fa-network-wired',
    'fa-route',
    'fa-gauge-high',
    'fa-car-side',
    'fa-signal',
    'fa-radio',
];
$resolveBrandIcon = static function (string $brandSlug, string $brandName) use ($brandIconOverrides, $brandIconKeywordMap, $brandIconPool): string {
    if (isset($brandIconOverrides[$brandSlug])) {
        return $brandIconOverrides[$brandSlug];
    }

    $haystack = strtolower(trim($brandName . ' ' . str_replace('-', ' ', $brandSlug)));
    foreach ($brandIconKeywordMap as $keyword => $iconClass) {
        if (strpos($haystack, $keyword) !== false) {
            return $iconClass;
        }
    }

    $iconSeed = (int)sprintf('%u', crc32($brandSlug ?: $brandName));
    return $brandIconPool[$iconSeed % count($brandIconPool)];
};
$sidebarBrands = [];
if (isset($db)) {
    try {
        $sidebarBrands = array_values(array_filter((array)$db->getBrands(), static function ($row) {
            return !empty($row['slug']);
        }));
    } catch (Throwable $e) {
        $sidebarBrands = [];
    }
}
$workspaceMeta = [
    'home' => ['title' => 'Workspace Home', 'subtitle' => 'Browse your GPS library by brand, model, and file type.', 'icon' => 'fa-house'],
    'dashboard' => ['title' => 'Workspace Dashboard', 'subtitle' => 'Track library activity and download insights.', 'icon' => 'fa-chart-line'],
    'brand' => ['title' => $currentBrandName, 'subtitle' => 'View models and available files for this brand.', 'icon' => 'fa-satellite-dish'],
    'model' => ['title' => 'Model Library', 'subtitle' => 'Explore files mapped to the selected device model.', 'icon' => 'fa-microchip'],
    'search' => ['title' => 'Search Results', 'subtitle' => 'Find firmware, manuals, configs, and software quickly.', 'icon' => 'fa-search'],
    'profile' => ['title' => 'My Profile', 'subtitle' => 'Manage account information and security settings.', 'icon' => 'fa-user'],
    'download' => ['title' => 'Download Center', 'subtitle' => 'Review file details before download.', 'icon' => 'fa-download'],
];
$workspaceCurrent = $workspaceMeta[$currentPage] ?? ['title' => 'Workspace', 'subtitle' => 'Your Translink device file hub.', 'icon' => 'fa-compass'];
$backHref = '';
if (!empty($_SERVER['HTTP_REFERER'])) {
    $ref = (string)$_SERVER['HTTP_REFERER'];
    $refParts = parse_url($ref);
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $refHost = (string)($refParts['host'] ?? '');
    // Only allow same-host referrers to avoid sending users off-site.
    if ($refHost !== '' && $host !== '' && strcasecmp($refHost, $host) === 0) {
        $backHref = $ref;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape(SITE_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg?v=2">
    <link rel="shortcut icon" type="image/svg+xml" href="assets/images/favicon.svg?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
<style>
    .navbar { transition: background 0.3s, backdrop-filter 0.3s, box-shadow 0.3s; }
</style>

</head>
<body class="<?= $useSidebarLayout ? 'layout-sidebar' : '' ?>">

<?php if ($useSidebarLayout): ?>
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Open navigation">
        <i class="fas fa-bars"></i>
    </button>
    <aside class="app-sidebar" id="appSidebar">
        <div class="app-sidebar-brand">
            <a href="index.php" class="app-sidebar-logo">
                <img src="assets/images/translink_logo.svg" alt="Translink" style="height:34px;width:auto">
            </a>
            <div class="app-sidebar-role"><?= escape($sidebarPanelLabel) ?></div>
        </div>
        <?php if (isLoggedIn()): ?>
        <div class="app-sidebar-user">
            <?php if (!empty($_SESSION['user_image'])): ?>
            <img src="<?= escape($_SESSION['user_image']) ?>" alt="" class="app-sidebar-user-avatar">
            <?php else: ?>
            <span class="app-sidebar-user-avatar app-sidebar-user-avatar-empty"><i class="fas fa-user"></i></span>
            <?php endif; ?>
            <div class="app-sidebar-user-meta">
                <strong><?= escape($_SESSION['user_username']) ?></strong>
                <span><?= escape(ucfirst($currentRole)) ?></span>
            </div>
        </div>
        <?php endif; ?>
        <nav class="app-sidebar-nav">
            <div class="app-sidebar-group-label">Workspace</div>
            <a href="index.php?page=dashboard" class="app-sidebar-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="index.php" class="app-sidebar-link <?= $currentPage === 'home' ? 'active' : '' ?>"><i class="fas fa-house"></i> Home</a>
            <?php foreach ($sidebarBrands as $sidebarBrand): ?>
            <?php
                $brandSlug = (string)($sidebarBrand['slug'] ?? '');
                $brandName = (string)($sidebarBrand['name'] ?? 'Brand');
                $brandIconClass = $resolveBrandIcon($brandSlug, $brandName);
            ?>
            <a href="index.php?page=brand&slug=<?= escape($brandSlug) ?>" class="app-sidebar-link <?= ($currentPage === 'brand' && ($_GET['slug'] ?? '') === $brandSlug) ? 'active' : '' ?>">
                <i class="fas <?= escape($brandIconClass) ?>"></i> <?= escape($brandName) ?>
            </a>
            <?php endforeach; ?>
            <div class="app-sidebar-group-label">Account</div>
            <a href="index.php?page=profile" class="app-sidebar-link <?= $currentPage === 'profile' ? 'active' : '' ?>"><i class="fas fa-user"></i> Profile</a>
        </nav>
        <div class="app-sidebar-footer">
            <a href="logout.php" class="app-sidebar-link app-sidebar-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            <?php if (!empty($_SESSION['_impersonator_id'])): ?>
            <a href="logout.php?switch_back=1" class="app-sidebar-link"><i class="fas fa-rotate-left"></i> Back to Admin</a>
            <?php endif; ?>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<?php else: ?>
    <nav class="navbar">
        <div class="container">
            <a href="index.php" class="nav-brand">
                <img src="assets/images/translink_logo.svg" alt="Translink" style="height:36px;width:auto">
            </a>
            <button class="nav-toggle" id="navToggle" aria-label="Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="nav-menu" id="navMenu">
                <a href="index.php" class="nav-link">Home</a>
                <?php foreach ($sidebarBrands as $sidebarBrand): ?>
                <?php
                    $brandSlug = (string)($sidebarBrand['slug'] ?? '');
                    $brandName = (string)($sidebarBrand['name'] ?? 'Brand');
                ?>
                <a href="index.php?page=brand&slug=<?= escape($brandSlug) ?>" class="nav-link"><?= escape($brandName) ?></a>
                <?php endforeach; ?>
                <?php if (isLoggedIn()): ?>
                <a href="index.php?page=profile" class="nav-user-pill">
                    <?php if (!empty($_SESSION['user_image'])): ?>
                    <img src="<?= escape($_SESSION['user_image']) ?>" alt="" class="nav-user-avatar">
                    <?php else: ?>
                    <span class="nav-user-avatar nav-user-avatar-empty"><i class="fas fa-user"></i></span>
                    <?php endif; ?>
                    <?= escape($_SESSION['user_username']) ?>
                </a>
                <?php if (!empty($_SESSION['_impersonator_id'])): ?>
                <a href="logout.php?switch_back=1" class="nav-link nav-link-back-admin"><i class="fas fa-rotate-left"></i> Back to Admin</a>
                <?php endif; ?>
                <a href="logout.php" class="nav-link nav-link-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
                <?php else: ?>
                <a href="login.php" class="nav-link nav-link-login"><i class="fas fa-sign-in-alt"></i> Login</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>
<?php endif; ?>

    <main class="main-content<?= $useSidebarLayout ? ' main-content-sidebar' : '' ?>">
        <div class="container">
            <div class="page-backbar">
                <?php if ($backHref): ?>
                <a class="workspace-action-btn btn-back" href="<?= escape($backHref) ?>" title="Go back"><i class="fas fa-arrow-left"></i> Back</a>
                <?php else: ?>
                <button type="button" class="workspace-action-btn btn-back" onclick="window.history.back()" title="Go back"><i class="fas fa-arrow-left"></i> Back</button>
                <?php endif; ?>
            </div>
