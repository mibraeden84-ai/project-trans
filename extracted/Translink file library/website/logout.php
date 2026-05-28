<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_GET['switch_back']) && !empty($_SESSION['_impersonator_id'])) {
    finalizeUserUsageSession((int)($_SESSION['user_id'] ?? 0));
    $_SESSION['user_id'] = (int)$_SESSION['_impersonator_id'];
    $_SESSION['user_username'] = $_SESSION['_impersonator_username'] ?? 'admin';
    $_SESSION['user_role'] = $_SESSION['_impersonator_role'] ?? 'admin';
    $_SESSION['user_image'] = $_SESSION['_impersonator_image'] ?? null;
    unset(
        $_SESSION['_impersonator_id'],
        $_SESSION['_impersonator_username'],
        $_SESSION['_impersonator_role'],
        $_SESSION['_impersonator_image'],
        $_SESSION['_perms'],
        $_SESSION['_img_refreshed']
    );
    markUserLoginActivity((int)($_SESSION['user_id'] ?? 0));
    header('Location: admin/dashboard.php#users');
    exit;
}

finalizeUserUsageSession((int)($_SESSION['user_id'] ?? 0));
unset(
    $_SESSION['user_id'],
    $_SESSION['user_username'],
    $_SESSION['user_role'],
    $_SESSION['user_image'],
    $_SESSION['_perms'],
    $_SESSION['_img_refreshed'],
    $_SESSION['_impersonator_id'],
    $_SESSION['_impersonator_username'],
    $_SESSION['_impersonator_role'],
    $_SESSION['_impersonator_image']
);
header('Location: index.php');
exit;
