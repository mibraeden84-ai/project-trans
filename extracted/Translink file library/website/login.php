<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    $role = $_SESSION['user_role'] ?? '';
    if (in_array($role, ['editor', 'admin'])) {
        header('Location: admin/dashboard.php');
    } else {
        $redirect = $_GET['redirect'] ?? 'index.php';
        header("Location: $redirect");
    }
    exit;
}

$db = Database::getInstance();
$error = '';

// Forgot password -> send request to admin email
$adminContact = $db->fetchOne(
    "SELECT username, email FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1"
);
$adminEmail = trim((string)($adminContact['email'] ?? ''));
if ($adminEmail === '') {
    $adminEmail = 'admin@translink.et';
}
$adminName = trim((string)($adminContact['username'] ?? 'admin'));
$forgotMailSubject = rawurlencode('Password reset request - ' . SITE_NAME);
$forgotMailBody = rawurlencode(
    "Hello {$adminName},\n\nPlease reset my password for my account.\nUsername: [your username]\n\nThank you."
);
$forgotPasswordHref = 'mailto:' . $adminEmail . '?subject=' . $forgotMailSubject . '&body=' . $forgotMailBody;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $user = $db->fetchOne("SELECT * FROM users WHERE username = ?", [$username]);
        if ($user && password_verify($password, $user['password_hash'])) {
            if (!$user['is_active']) {
                $error = 'Account deactivated — contact administrator';
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_image'] = $user['image'];
                unset(
                    $_SESSION['_impersonator_id'],
                    $_SESSION['_impersonator_username'],
                    $_SESSION['_impersonator_role'],
                    $_SESSION['_impersonator_image'],
                    $_SESSION['_perms'],
                    $_SESSION['_img_refreshed']
                );
                markUserLoginActivity((int)$user['id']);

                $role = $user['role'];
                if (in_array($role, ['editor', 'admin'])) {
                    header('Location: admin/dashboard.php');
                } else {
                    $redirect = $_GET['redirect'] ?? 'index.php';
                    header("Location: $redirect");
                }
                exit;
            }
        } else {
            $error = 'Invalid username or password';
        }
    } else {
        $error = 'Please fill in all fields';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 3px; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #0b1a2e;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            padding: 16px 0;
        }

        /* === Animated telematics gradient background === */
        .telematics-bg {
            position: absolute;
            inset: -50%;
            z-index: 0;
            background:
                radial-gradient(ellipse at 15% 30%, rgba(0,140,255,0.3) 0%, transparent 45%),
                radial-gradient(ellipse at 75% 70%, rgba(0,232,150,0.2) 0%, transparent 40%),
                radial-gradient(ellipse at 50% 10%, rgba(77,166,255,0.15) 0%, transparent 40%),
                radial-gradient(ellipse at 80% 20%, rgba(0,90,160,0.25) 0%, transparent 35%),
                radial-gradient(ellipse at 30% 80%, rgba(0,200,220,0.12) 0%, transparent 35%);
            animation: meshFlow 20s ease-in-out infinite alternate;
        }
        @keyframes meshFlow {
            0% { transform: translate(0, 0) rotate(0deg) scale(1); }
            50% { transform: translate(2%, 1.5%) rotate(2deg) scale(1.05); }
            100% { transform: translate(-1.5%, -2%) rotate(-2deg) scale(1.03); }
        }

        /* === Satellite orbital paths === */
        .orbit-ring {
            position: absolute;
            border-radius: 50%;
            border: 1.5px solid rgba(0,140,255,0.2);
            pointer-events: none;
            z-index: 0;
            animation: orbitSpin var(--dur) linear infinite;
        }
        .orbit-ring:nth-child(1) { width: 700px; height: 280px; top: 20%; left: -5%; --dur: 35s; }
        .orbit-ring:nth-child(2) { width: 500px; height: 200px; top: 55%; left: 50%; --dur: 28s; animation-direction: reverse; border-color: rgba(0,200,120,0.18); }
        .orbit-ring:nth-child(3) { width: 600px; height: 240px; top: 8%; left: 40%; --dur: 32s; border-color: rgba(0,200,120,0.15); }
        .orbit-ring:nth-child(4) { width: 380px; height: 160px; top: 62%; left: -2%; --dur: 25s; animation-direction: reverse; border-color: rgba(59,130,246,0.18); }
        .orbit-ring:nth-child(5) { width: 250px; height: 250px; top: 35%; left: 32%; --dur: 20s; border-color: rgba(100,200,255,0.15); border-style: dashed; }
        @keyframes orbitSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* === GPS signal wave rings === */
        .signal-ring {
            position: absolute;
            border-radius: 50%;
            border: 2px solid rgba(0,200,120,0.2);
            pointer-events: none;
            z-index: 0;
            animation: signalPulse var(--dur) ease-out infinite;
            animation-delay: var(--del);
        }
        .signal-ring:nth-child(6) { width: 30px; height: 30px; top: 22%; left: 72%; --dur: 4s; --del: 0s; }
        .signal-ring:nth-child(7) { width: 30px; height: 30px; top: 22%; left: 72%; --dur: 4s; --del: 1s; }
        .signal-ring:nth-child(8) { width: 30px; height: 30px; top: 22%; left: 72%; --dur: 4s; --del: 2s; }
        .signal-ring:nth-child(9) { width: 30px; height: 30px; top: 22%; left: 72%; --dur: 4s; --del: 3s; }
        .signal-ring:nth-child(10) { width: 24px; height: 24px; top: 68%; left: 18%; --dur: 5s; --del: 0s; border-color: rgba(0,140,255,0.2); }
        .signal-ring:nth-child(11) { width: 24px; height: 24px; top: 68%; left: 18%; --dur: 5s; --del: 1s; border-color: rgba(0,140,255,0.2); }
        .signal-ring:nth-child(12) { width: 24px; height: 24px; top: 68%; left: 18%; --dur: 5s; --del: 2s; border-color: rgba(0,140,255,0.2); }
        @keyframes signalPulse {
            0% { transform: scale(0.5); opacity: 0.8; }
            100% { transform: scale(4); opacity: 0; }
        }

        /* === Orbiting satellite dots (visible) === */
        .sat-dot {
            position: absolute;
            width: 8px; height: 8px;
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            background: #00e896;
            box-shadow: 0 0 16px rgba(0,232,150,0.6), 0 0 40px rgba(0,232,150,0.2);
            animation: satOrbit var(--dur) linear infinite;
            animation-delay: var(--del);
        }
        .sat-dot::after {
            content: '';
            position: absolute;
            top: 2px; left: 2px; right: 2px; bottom: 2px;
            border-radius: 50%;
            background: radial-gradient(circle, #fff 30%, transparent);
        }
        .sat-dot:nth-child(13) { --dur: 35s; --del: 0s; }
        .sat-dot:nth-child(14) { --dur: 28s; --del: 7s; background: #4da6ff; box-shadow: 0 0 16px rgba(77,166,255,0.6), 0 0 40px rgba(77,166,255,0.2); }
        @keyframes satOrbit {
            0% { transform: rotate(0deg) translateX(350px) rotate(0deg); }
            100% { transform: rotate(360deg) translateX(350px) rotate(-360deg); }
        }

        /* === GPS target crosshair === */
        .gps-crosshair {
            position: absolute;
            pointer-events: none;
            z-index: 0;
            opacity: 0.35;
            animation: crosshairPulse 3s ease-in-out infinite;
        }
        .gps-crosshair::before, .gps-crosshair::after {
            content: '';
            position: absolute;
            background: rgba(0,232,150,0.6);
        }
        .gps-crosshair::before {
            width: 24px; height: 2px;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            box-shadow: 0 -8px 0 rgba(0,232,150,0.4), 0 8px 0 rgba(0,232,150,0.4);
        }
        .gps-crosshair::after {
            width: 2px; height: 24px;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            box-shadow: -8px 0 0 rgba(0,232,150,0.4), 8px 0 0 rgba(0,232,150,0.4);
        }
        .gps-crosshair:nth-child(17) { top: 15%; left: 20%; animation-delay: 0s; }
        .gps-crosshair:nth-child(18) { top: 75%; left: 75%; animation-delay: 1.5s; }
        @keyframes crosshairPulse {
            0%, 100% { opacity: 0.25; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.1); }
        }

        /* === Top/bottom accent glow lines === */
        .glow-line {
            position: absolute;
            z-index: 0;
            pointer-events: none;
        }
        .glow-line:nth-child(15) {
            top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, rgba(0,90,160,0.5), rgba(0,168,107,0.5), transparent);
        }
        .glow-line:nth-child(16) {
            bottom: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, rgba(0,168,107,0.35), rgba(59,130,246,0.35), transparent);
        }

        /* === GPS coordinate grid overlay === */
        .grid-overlay {
            position: absolute;
            inset: 0;
            z-index: 0;
            background-image:
                linear-gradient(rgba(0,140,255,0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,140,255,0.06) 1px, transparent 1px);
            background-size: 70px 70px;
            mask-image: radial-gradient(ellipse at center, black 35%, transparent 72%);
            -webkit-mask-image: radial-gradient(ellipse at center, black 35%, transparent 72%);
        }

        /* === Layout === */
        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 460px;
            padding: 20px;
            margin: auto;
        }

        .login-shell {
            position: relative;
            border-radius: 22px;
            padding: 1px;
            background: linear-gradient(140deg, rgba(0,90,160,0.45), rgba(0,168,107,0.26), rgba(255,255,255,0.12));
            box-shadow: 0 28px 80px rgba(0,0,0,0.5);
            animation: shellFloat 5.2s ease-in-out infinite;
        }
        .login-shell::before {
            content: '';
            position: absolute;
            width: 160px;
            height: 160px;
            top: -42px;
            right: -52px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0,168,107,0.2), transparent 65%);
            pointer-events: none;
        }
        .login-shell::after {
            content: '';
            position: absolute;
            width: 140px;
            height: 140px;
            bottom: -44px;
            left: -44px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0,90,160,0.25), transparent 65%);
            pointer-events: none;
        }
        @keyframes shellFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }

        /* === Card === */
        .login-card {
            background: linear-gradient(180deg, rgba(18, 24, 42, 0.9), rgba(14, 20, 36, 0.9));
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border-radius: 21px;
            padding: 48px 40px 40px;
            box-shadow:
                0 32px 80px rgba(0,0,0,0.5),
                0 0 0 1px rgba(255,255,255,0.06) inset;
            animation: cardIn 0.8s cubic-bezier(0.22, 1, 0.36, 1) both;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .login-card::before {
            content: '';
            position: absolute;
            top: -1px; left: -1px; right: -1px;
            height: 2px;
            background: linear-gradient(90deg, transparent, #005aa0, #00a86b, transparent);
            background-size: 200% 100%;
            animation: borderGlow 4s ease-in-out infinite;
        }
        .login-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 120px; height: 120px;
            background: radial-gradient(circle, rgba(0,90,160,0.08), transparent);
            pointer-events: none;
        }
        @keyframes cardIn {
            0% { opacity: 0; transform: scale(0.94) translateY(40px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }
        @keyframes borderGlow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        /* === Logo === */
        .logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .logo img {
            height: 40px;
            width: auto;
            filter: drop-shadow(0 0 20px rgba(0,90,160,0.3));
            animation: logoIn 0.8s cubic-bezier(0.22, 1, 0.36, 1) 0.15s both;
        }
        @keyframes logoIn {
            0% { opacity: 0; transform: scale(0.8) translateY(-10px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }

        .login-card h1 {
            text-align: center;
            font-size: 1.65rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.5px;
            line-height: 1.25;
            animation: fadeUp 0.6s ease 0.25s both;
        }
        .login-card .subtitle {
            text-align: center;
            color: rgba(255,255,255,0.45);
            font-size: 0.9rem;
            margin-top: 6px;
            margin-bottom: 18px;
            font-weight: 400;
            animation: fadeUp 0.6s ease 0.3s both;
        }
        .login-chip-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 26px;
            flex-wrap: wrap;
            animation: fadeUp 0.6s ease 0.33s both;
        }
        .login-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.06);
            color: rgba(255,255,255,0.7);
            font-size: 0.74rem;
            font-weight: 600;
        }
        .login-chip i {
            font-size: 0.75rem;
            color: rgba(120,200,255,0.85);
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* === Form groups with floating labels === */
        .form-group {
            margin-bottom: 20px;
            position: relative;
            animation: fadeUp 0.6s ease 0.35s both;
        }
        .form-group:nth-child(2) { animation-delay: 0.4s; }

        .input-wrap {
            position: relative;
        }
        .input-wrap .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.25);
            font-size: 1rem;
            transition: all 0.3s;
            pointer-events: none;
            z-index: 2;
        }
        .input-wrap .pw-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: rgba(255,255,255,0.25);
            font-size: 0.95rem;
            cursor: pointer;
            padding: 6px;
            border-radius: 8px;
            transition: all 0.25s;
            z-index: 2;
            opacity: 0.9;
            pointer-events: auto;
        }
        .input-wrap .pw-toggle.show { opacity: 1; pointer-events: auto; }
        .input-wrap .pw-toggle:hover { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.6); }

        .form-group label {
            color: rgba(255,255,255,0.75);
            font-weight: 500;
            font-size: 0.85rem;
            margin-bottom: 6px;
            display: block;
        }
        .form-group input {
            width: 100%;
            padding: 16px 18px 16px 46px;
            border: 1.5px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.3s;
            background: rgba(255,255,255,0.04);
            color: #fff;
            font-family: inherit;
            caret-color: #005aa0;
        }
        .form-group input:focus {
            border-color: rgba(0,90,160,0.5);
            background: rgba(255,255,255,0.06);
            box-shadow: 0 0 0 4px rgba(0,90,160,0.08), 0 0 30px rgba(0,90,160,0.05);
        }
        .form-group input::placeholder { color: rgba(255,255,255,0.2); transition: opacity 0.2s; }
        .form-group input:focus::placeholder { opacity: 0.5; }
        .form-group:focus-within .input-icon {
            color: rgba(0,90,160,0.7);
        }
        .form-group:focus-within .pw-toggle.show { opacity: 1; }

        /* Autofill override */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 30px rgba(18,24,42) inset;
            -webkit-text-fill-color: #fff;
            caret-color: #fff;
            border-color: rgba(0,90,160,0.5);
        }

        /* === Floating label === */
        .float-label {
            position: absolute;
            left: 46px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.3);
            font-size: 0.95rem;
            pointer-events: none;
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
            z-index: 1;
            font-weight: 400;
            background: transparent;
            padding: 0 4px;
        }
        .form-group input:focus ~ .float-label,
        .form-group input:not(:placeholder-shown) ~ .float-label {
            top: 0;
            transform: translateY(-50%) scale(0.85);
            color: rgba(0,90,160,0.7);
            background: rgba(18,24,42,0.9);
            backdrop-filter: blur(4px);
        }

        /* === Remember / Forgot row === */
        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
            font-size: 0.84rem;
            animation: fadeUp 0.6s ease 0.45s both;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
        }
        .form-options label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.4);
            cursor: pointer;
            font-weight: 400;
            transition: color 0.2s;
        }
        .form-options label:hover { color: rgba(255,255,255,0.6); }
        .form-options label input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: #005aa0;
            cursor: pointer;
            border-radius: 4px;
            background: transparent;
            border: 1.5px solid rgba(255,255,255,0.2);
        }
        .form-options a {
            color: rgba(0,90,160,0.7);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.25s;
            font-size: 0.84rem;
        }
        .form-options a:hover { color: #005aa0; }

        /* === Button with ripple === */
        .btn {
            width: 100%;
            padding: 16px 24px;
            background: linear-gradient(135deg, #005aa0, #004e91 55%, #007e65);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.35s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
            letter-spacing: 0.01em;
            animation: fadeUp 0.6s ease 0.5s both;
        }
        .btn::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1), transparent);
            opacity: 0;
            transition: opacity 0.35s;
        }
        .btn::after {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 14px;
            background: linear-gradient(135deg, #005aa0, #00a86b, #005aa0);
            background-size: 200% 200%;
            z-index: -1;
            animation: btnBorder 4s ease-in-out infinite;
            opacity: 0;
            transition: opacity 0.35s;
        }
        .btn:hover::after { opacity: 0.5; }
        @keyframes btnBorder {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 40px rgba(0,90,160,0.35);
        }
        .btn:hover::before { opacity: 1; }
        .btn:active { transform: translateY(0); }
        .btn .btn-text { transition: all 0.3s; }
        .btn .btn-icon { font-size: 0.9rem; transition: transform 0.3s; }
        .btn:hover .btn-icon { transform: translateX(5px); }

        /* Button loading */
        .btn.loading { pointer-events: none; }
        .btn.loading .btn-text, .btn.loading .btn-icon { opacity: 0; }
        .btn .spinner {
            display: none;
            position: absolute;
            width: 20px; height: 20px;
            border: 2.5px solid rgba(255,255,255,0.15);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }
        .btn.loading .spinner { display: block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* === Ripple effect === */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            transform: scale(0);
            animation: rippleAnim 0.6s ease-out;
            pointer-events: none;
        }
        @keyframes rippleAnim {
            to { transform: scale(4); opacity: 0; }
        }

        /* === Error === */
        .error {
            background: rgba(239, 68, 68, 0.1);
            backdrop-filter: blur(8px);
            color: #fca5a5;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid rgba(239, 68, 68, 0.15);
            animation: shakeX 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97);
        }
        .error i { font-size: 1rem; color: #ef4444; }
        .caps-warning {
            display: none;
            margin: 0 0 14px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(251,191,36,0.3);
            background: rgba(251,191,36,0.08);
            color: #fcd34d;
            font-size: 0.8rem;
            align-items: center;
            gap: 8px;
        }
        .caps-warning.show {
            display: inline-flex;
        }
        @keyframes shakeX {
            0%, 100% { transform: translateX(0); }
            15% { transform: translateX(-8px); }
            30% { transform: translateX(8px); }
            45% { transform: translateX(-5px); }
            60% { transform: translateX(5px); }
            75% { transform: translateX(-2px); }
            90% { transform: translateX(2px); }
        }

        /* === Card footer === */
        .card-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.06);
            animation: fadeUp 0.6s ease 0.55s both;
        }
        .card-footer a {
            color: rgba(255,255,255,0.3);
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }
        .card-footer a:hover { color: #fff; gap: 12px; }

        /* === Brand tag === */
        .brand-tag {
            position: absolute;
            bottom: 28px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255,255,255,0.15);
            font-size: 0.7rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            z-index: 1;
            animation: tagIn 0.8s ease 0.8s both;
            pointer-events: none;
            white-space: nowrap;
            font-weight: 500;
        }
        @keyframes tagIn {
            from { opacity: 0; transform: translateX(-50%) translateY(16px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        /* === Responsive === */
        @media (max-width: 480px) {
            .login-card { padding: 36px 24px 32px; }
            .login-card h1 { font-size: 1.4rem; }
            .telematics-bg { inset: -100%; }
            .login-shell {
                border-radius: 18px;
            }
            .form-options {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }
        @media (max-width: 380px) {
            .login-wrapper { padding: 14px; }
            .login-card { padding: 30px 18px 26px; }
            .login-card h1 { font-size: 1.28rem; }
            .login-chip { font-size: 0.7rem; }
            .btn { padding: 14px 20px; }
        }
        @media (max-height: 760px) {
            .brand-tag {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="telematics-bg"></div>
    <div class="orbit-ring"></div>
    <div class="orbit-ring"></div>
    <div class="orbit-ring"></div>
    <div class="orbit-ring"></div>
    <div class="orbit-ring"></div>
    <div class="signal-ring"></div>
    <div class="signal-ring"></div>
    <div class="signal-ring"></div>
    <div class="signal-ring"></div>
    <div class="signal-ring"></div>
    <div class="signal-ring"></div>
    <div class="signal-ring"></div>
    <div class="sat-dot"></div>
    <div class="sat-dot"></div>
    <div class="glow-line"></div>
    <div class="glow-line"></div>
    <div class="gps-crosshair"></div>
    <div class="gps-crosshair"></div>

    <div class="login-wrapper">
        <div class="login-shell">
        <div class="login-card">
            <div class="logo">
                <img src="assets/images/translink_logo.svg" alt="Translink">
            </div>
            <h1>Welcome Back</h1>
            <p class="subtitle">Sign in to your account to continue</p>
            <div class="login-chip-row">
                <span class="login-chip"><i class="fas fa-bolt"></i> Fast Access</span>
                <span class="login-chip"><i class="fas fa-satellite-dish"></i> Translink Hub</span>
                <span class="login-chip"><i class="fas fa-layer-group"></i> Workspace</span>
            </div>

            <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i>
                <?= escape($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" id="loginForm" onsubmit="return handleLogin(this)">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" id="loginPassword" name="password" placeholder="Enter your password" required>
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="pw-toggle" onclick="togglePassword()" tabindex="-1" title="Show password">
                            <i class="fas fa-eye" id="pwIcon"></i>
                        </button>
                    </div>
                </div>
                <div id="capsWarning" class="caps-warning"><i class="fas fa-triangle-exclamation"></i> Caps Lock is on</div>
                <div class="form-options">
                    <label>
                        <input type="checkbox" name="remember" value="1"> Remember me
                    </label>
                    <a href="<?= escape($forgotPasswordHref) ?>">Forgot password?</a>
                </div>
                <button type="submit" class="btn" id="loginBtn">
                    <span class="btn-text">Sign In</span>
                    <span class="spinner"></span>
                    <i class="fas fa-arrow-right btn-icon"></i>
                </button>
            </form>

        </div>
        </div>
    </div>
    <div class="brand-tag"><?= SITE_NAME ?></div>

    <script>
    function togglePassword() {
        var p = document.getElementById('loginPassword');
        var i = document.getElementById('pwIcon');
        if (p.type === 'password') {
            p.type = 'text';
            i.className = 'fas fa-eye-slash';
        } else {
            p.type = 'password';
            i.className = 'fas fa-eye';
        }
    }
    function handleLogin(form) {
        var btn = document.getElementById('loginBtn');
        btn.classList.add('loading');
        setTimeout(function() { form.submit(); }, 200);
        return false;
    }
    (function() {
        var passwordInput = document.getElementById('loginPassword');
        var capsWarning = document.getElementById('capsWarning');
        if (!passwordInput || !capsWarning) return;
        function syncCaps(event) {
            if (event && typeof event.getModifierState === 'function' && event.getModifierState('CapsLock')) {
                capsWarning.classList.add('show');
            } else {
                capsWarning.classList.remove('show');
            }
        }
        passwordInput.addEventListener('keydown', syncCaps);
        passwordInput.addEventListener('keyup', syncCaps);
        passwordInput.addEventListener('blur', function() {
            capsWarning.classList.remove('show');
        });
    })();
    </script>
</body>
</html>
