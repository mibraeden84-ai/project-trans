<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance();

// Require login for all public pages
if (!isLoggedIn()) {
    $redirect = $_SERVER['REQUEST_URI'];
    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}

$page = $_GET['page'] ?? 'home';

$pageTitle = SITE_NAME;

switch ($page) {
    case 'dashboard':
        require __DIR__ . '/pages/dashboard.php';
        break;
    case 'brand':
        require __DIR__ . '/pages/brand.php';
        break;
    case 'model':
        require __DIR__ . '/pages/model.php';
        break;
    case 'search':
        require __DIR__ . '/pages/search.php';
        break;
    case 'download':
        require __DIR__ . '/pages/download.php';
        break;
    case 'profile':
        require __DIR__ . '/pages/profile.php';
        break;
    default:
        require __DIR__ . '/pages/home.php';
        break;
}
