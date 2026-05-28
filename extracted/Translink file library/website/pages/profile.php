<?php
$pageTitle = 'My Profile';
$userId = (int)$_SESSION['user_id'];

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newUsername = trim($_POST['username'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $removeProfileImage = isset($_POST['remove_profile_image']) && $_POST['remove_profile_image'] === '1';
    $errors = [];

    if (!$newUsername) {
        $errors[] = 'Username is required';
    } else {
        $existing = $db->fetchOne("SELECT id FROM users WHERE username = ? AND id != ?", [$newUsername, $userId]);
        if ($existing) $errors[] = 'Username already taken';
    }

    if (empty($errors)) {
        $imagePath = null;
        $current = $db->fetchOne("SELECT image FROM users WHERE id = ?", [$userId]);
        $imagePath = $current['image'] ?? null;

        if ($removeProfileImage && $current['image']) {
            if (file_exists(__DIR__ . '/../' . $current['image'])) {
                unlink(__DIR__ . '/../' . $current['image']);
            }
            $imagePath = null;
        }

        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $imgDir = __DIR__ . '/../uploads/users';
            if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $imgName = 'user_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['profile_image']['tmp_name'], $imgDir . '/' . $imgName);
                $imagePath = 'uploads/users/' . $imgName;
                if ($current['image'] && file_exists(__DIR__ . '/../' . $current['image'])) {
                    unlink(__DIR__ . '/../' . $current['image']);
                }
            }
        }

        if ($newPassword) {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $db->query("UPDATE users SET username=?, email=?, image=?, password_hash=? WHERE id=?",
                [$newUsername, $newEmail, $imagePath, $hash, $userId]);
        } else {
            $db->query("UPDATE users SET username=?, email=?, image=? WHERE id=?",
                [$newUsername, $newEmail, $imagePath, $userId]);
        }

        $_SESSION['user_username'] = $newUsername;
        $_SESSION['user_image'] = $imagePath;
        $success = 'Profile updated successfully';
    }
}

ensureUserUsageColumns();
$user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
$joinedDate = !empty($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'N/A';
$roleColor = $user['role'] === 'admin' ? '#005aa0' : ($user['role'] === 'editor' ? '#00a86b' : '#6c757d');
$libraryStats = [
    'brands' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM brands")['c'] ?? 0),
    'models' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM device_models")['c'] ?? 0),
    'configs' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM config_files WHERE status='active'")['c'] ?? 0),
    'firmware' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM firmware_files WHERE status='active'")['c'] ?? 0),
    'manuals' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM manuals WHERE status='active'")['c'] ?? 0),
    'software' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM software_files WHERE status='active'")['c'] ?? 0),
    'my_downloads' => (int)($user['total_downloads'] ?? 0),
];
require __DIR__ . '/../includes/header.php';
?>
<style>
.profile-page {
    margin: 28px auto 48px;
    max-width: 1160px;
}
.profile-hero {
    background: linear-gradient(130deg, #0f1b2f, #193a5d 58%, #0c7a60);
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.12);
    color: #fff;
    padding: 22px 24px;
    box-shadow: 0 20px 46px rgba(15, 23, 42, 0.34);
    position: relative;
    overflow: hidden;
}
.profile-hero::after {
    content: "";
    position: absolute;
    inset: 0;
    background:
        linear-gradient(110deg, rgba(255,255,255,0.08), transparent 35%),
        repeating-linear-gradient(90deg, rgba(255,255,255,0.05) 0 1px, transparent 1px 34px);
    pointer-events: none;
}
.profile-hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.profile-hero h1 {
    font-size: 1.42rem;
    font-weight: 700;
    line-height: 1.2;
    margin-bottom: 3px;
}
.profile-hero p {
    font-size: 0.92rem;
    color: rgba(255,255,255,0.83);
}
.profile-hero-badges {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.profile-hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid rgba(255,255,255,0.26);
    background: rgba(255,255,255,0.1);
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
}
.profile-shell {
    margin-top: 16px;
    display: grid;
    grid-template-columns: 300px minmax(0, 1fr);
    gap: 16px;
}
.profile-panel, .profile-editor {
    background: #fff;
    border: 1px solid #e4edf7;
    border-radius: 12px;
    box-shadow: 0 16px 32px rgba(16, 24, 40, 0.09);
}
.profile-panel {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.profile-avatar-wrap {
    width: 104px;
    height: 104px;
    border-radius: 50%;
    margin: 2px auto 6px;
    overflow: hidden;
    border: 3px solid #eef4fb;
    box-shadow: 0 10px 22px rgba(0, 90, 160, 0.22);
}
.profile-avatar-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.profile-avatar-empty {
    width: 100%;
    height: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #f1f5f9;
    color: #64748b;
    font-size: 2rem;
}
.profile-identity {
    text-align: center;
}
.profile-identity h2 {
    font-size: 1.14rem;
    color: #0f172a;
    margin-bottom: 2px;
    line-height: 1.3;
}
.profile-identity p {
    font-size: 0.84rem;
    color: #64748b;
}
.profile-role {
    margin-top: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 700;
    color: #fff;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.profile-meta {
    margin-top: 4px;
    border-top: 1px solid #e8eef7;
    padding-top: 12px;
    display: grid;
    gap: 8px;
}
.profile-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.81rem;
    color: #475569;
}
.profile-meta-item i {
    width: 20px;
    text-align: center;
    color: #005aa0;
}
.profile-kpis {
    border-top: 1px solid #e8eef7;
    padding-top: 12px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}
.profile-kpi {
    border: 1px solid #e2ebf5;
    border-radius: 10px;
    background: linear-gradient(160deg, #f8fbff, #f2f8ff);
    padding: 9px 8px;
    text-align: center;
}
.profile-kpi strong {
    display: block;
    font-size: 1.03rem;
    color: #0f172a;
    line-height: 1.1;
}
.profile-kpi span {
    font-size: 0.72rem;
    color: #64748b;
}
.profile-editor {
    padding: 22px;
}
.profile-editor-head {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
}
.profile-editor-head h2 {
    font-size: 1.28rem;
    color: #0f172a;
    line-height: 1.2;
}
.profile-editor-head p {
    color: #64748b;
    font-size: 0.86rem;
}
.profile-alert {
    padding: 11px 13px;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 0.84rem;
    border: 1px solid transparent;
}
.profile-alert.success {
    background: #ecfdf3;
    color: #065f46;
    border-color: #bbf7d0;
}
.profile-alert.error {
    background: #fef2f2;
    color: #991b1b;
    border-color: #fecaca;
}
.profile-form {
    display: grid;
    gap: 14px;
}
.profile-section {
    border: 1px solid #e3edf8;
    border-radius: 10px;
    padding: 14px;
    background: #fcfdff;
}
.profile-section h3 {
    font-size: 0.92rem;
    color: #0f172a;
    margin-bottom: 10px;
}
.profile-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.profile-field label {
    display: block;
    font-size: 0.81rem;
    font-weight: 700;
    color: #334155;
    margin-bottom: 5px;
}
.profile-field input[type="text"],
.profile-field input[type="email"],
.profile-field input[type="password"],
.profile-field input[type="file"] {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid #d7e2f0;
    border-radius: 8px;
    font-size: 0.9rem;
    outline: none;
    background: #fff;
}
.profile-field input:focus {
    border-color: #005aa0;
    box-shadow: 0 0 0 3px rgba(0,90,160,0.1);
}
.profile-password-wrap {
    display: flex;
    gap: 8px;
}
.profile-password-wrap input {
    flex: 1;
}
.profile-icon-btn {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    border: 1px solid #d7e2f0;
    background: #f8fafc;
    color: #334155;
    cursor: pointer;
}
.profile-icon-btn:hover {
    border-color: #94a3b8;
    background: #f1f5f9;
}
.profile-password-meter {
    margin-top: 8px;
    height: 6px;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
}
.profile-password-meter-fill {
    height: 100%;
    width: 0;
    background: #ef4444;
    transition: width 0.25s, background-color 0.25s;
}
.profile-password-label {
    margin-top: 6px;
    font-size: 0.78rem;
    color: #64748b;
}
.profile-check {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 0.82rem;
    color: #475569;
    margin-top: 8px;
}
.profile-check input {
    width: 15px;
    height: 15px;
}
.profile-save-wrap {
    display: flex;
    justify-content: flex-end;
}
.profile-save {
    margin-top: 2px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: none;
    border-radius: 9px;
    padding: 12px 20px;
    background: linear-gradient(135deg, #005aa0, #003d73);
    color: #fff;
    font-size: 0.9rem;
    font-weight: 700;
    cursor: pointer;
}
.profile-save:hover {
    filter: brightness(1.05);
}
@media (max-width: 940px) {
    .profile-shell {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 680px) {
    .profile-page { margin-top: 20px; }
    .profile-hero { padding: 16px; }
    .profile-editor { padding: 16px; }
    .profile-row { grid-template-columns: 1fr; gap: 10px; }
}
</style>

<div class="profile-page">
    <section class="profile-hero">
        <div class="profile-hero-content">
            <div>
                <h1>Account Center</h1>
                <p>Manage your identity, security, and profile details from one place.</p>
            </div>
            <div class="profile-hero-badges">
                <span class="profile-hero-badge"><i class="fas fa-user-check"></i> Active Account</span>
                <span class="profile-hero-badge"><i class="fas fa-shield-alt"></i> Role: <?= escape($user['role']) ?></span>
            </div>
        </div>
    </section>

    <div class="profile-shell">
        <aside class="profile-panel">
            <div class="profile-avatar-wrap" id="profileAvatarWrap">
                <?php if (!empty($user['image'])): ?>
                <img src="<?= escape($user['image']) ?>" alt="" id="profileAvatarPreview">
                <?php else: ?>
                <div class="profile-avatar-empty" id="profileAvatarEmpty"><i class="fas fa-user"></i></div>
                <img src="" alt="" id="profileAvatarPreview" style="display:none">
                <?php endif; ?>
            </div>
            <div class="profile-identity">
                <h2><?= escape($user['username']) ?></h2>
                <p><?= escape($user['email'] ?: 'No email set') ?></p>
                <span class="profile-role" style="background:<?= $roleColor ?>"><?= escape($user['role']) ?></span>
            </div>
            <div class="profile-meta">
                <div class="profile-meta-item"><i class="fas fa-calendar-alt"></i> Joined <?= escape($joinedDate) ?></div>
                <div class="profile-meta-item"><i class="fas fa-id-badge"></i> User ID #<?= (int)$user['id'] ?></div>
            </div>
            <div class="profile-kpis">
                <div class="profile-kpi"><strong><?= $libraryStats['brands'] ?></strong><span>Brands</span></div>
                <div class="profile-kpi"><strong><?= $libraryStats['models'] ?></strong><span>Models</span></div>
                <div class="profile-kpi"><strong><?= $libraryStats['configs'] ?></strong><span>Configs</span></div>
                <div class="profile-kpi"><strong><?= $libraryStats['firmware'] ?></strong><span>Firmware</span></div>
                <div class="profile-kpi"><strong><?= $libraryStats['manuals'] ?></strong><span>Manuals</span></div>
                <div class="profile-kpi"><strong><?= $libraryStats['software'] ?></strong><span>Software</span></div>
                <div class="profile-kpi"><strong><?= $libraryStats['my_downloads'] ?></strong><span>My Downloads</span></div>
            </div>
        </aside>

        <section class="profile-editor">
            <div class="profile-editor-head">
                <div>
                    <h2>My Profile</h2>
                    <p>Update your personal details and keep your account secure.</p>
                </div>
            </div>

            <?php if (!empty($success)): ?>
            <div class="profile-alert success"><i class="fas fa-check-circle"></i> <?= escape($success) ?></div>
            <?php endif; ?>
            <?php if (!empty($errors)): foreach ($errors as $e): ?>
            <div class="profile-alert error"><i class="fas fa-exclamation-circle"></i> <?= escape($e) ?></div>
            <?php endforeach; endif; ?>

            <form method="POST" enctype="multipart/form-data" class="profile-form">
                <div class="profile-section">
                    <h3><i class="fas fa-id-card"></i> Identity</h3>
                    <div class="profile-row">
                        <div class="profile-field">
                            <label>Username</label>
                            <input type="text" name="username" value="<?= escape($user['username']) ?>" required>
                        </div>
                        <div class="profile-field">
                            <label>Email</label>
                            <input type="email" name="email" value="<?= escape($user['email'] ?? '') ?>" placeholder="optional">
                        </div>
                    </div>
                </div>

                <div class="profile-section">
                    <h3><i class="fas fa-lock"></i> Security</h3>
                    <div class="profile-field">
                        <label>New Password <span style="font-weight:500;color:#64748b">(leave blank to keep current)</span></label>
                        <div class="profile-password-wrap">
                            <input type="password" name="new_password" id="newPasswordInput" placeholder="Enter new password" autocomplete="new-password" oninput="updatePasswordStrength()">
                            <button type="button" class="profile-icon-btn" onclick="toggleProfilePassword()" title="Show/Hide"><i class="fas fa-eye"></i></button>
                            <button type="button" class="profile-icon-btn" onclick="generateProfilePassword()" title="Generate"><i class="fas fa-key"></i></button>
                        </div>
                        <div class="profile-password-meter" aria-hidden="true">
                            <div class="profile-password-meter-fill" id="passwordStrengthFill"></div>
                        </div>
                        <div class="profile-password-label" id="passwordStrengthLabel">Password strength: not set</div>
                    </div>
                </div>

                <div class="profile-section">
                    <h3><i class="fas fa-image"></i> Avatar</h3>
                    <div class="profile-field">
                        <label>Profile Image</label>
                        <input type="file" name="profile_image" id="profileImageInput" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewProfileImage(this)">
                        <label class="profile-check"><input type="checkbox" name="remove_profile_image" value="1"> Remove current profile image</label>
                    </div>
                </div>

                <div class="profile-save-wrap">
                    <button type="submit" name="update_profile" class="profile-save"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
function toggleProfilePassword() {
    var input = document.getElementById('newPasswordInput');
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
}

function generateProfilePassword() {
    var input = document.getElementById('newPasswordInput');
    if (!input) return;
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    var pwd = '';
    for (var i = 0; i < 14; i++) {
        pwd += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    input.value = pwd;
    input.type = 'text';
    updatePasswordStrength();
}

function previewProfileImage(fileInput) {
    if (!fileInput || !fileInput.files || !fileInput.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var img = document.getElementById('profileAvatarPreview');
        var empty = document.getElementById('profileAvatarEmpty');
        if (img) {
            img.src = e.target.result;
            img.style.display = 'block';
        }
        if (empty) empty.style.display = 'none';
    };
    reader.readAsDataURL(fileInput.files[0]);
}

function updatePasswordStrength() {
    var input = document.getElementById('newPasswordInput');
    var fill = document.getElementById('passwordStrengthFill');
    var label = document.getElementById('passwordStrengthLabel');
    if (!input || !fill || !label) return;

    var value = input.value || '';
    if (!value) {
        fill.style.width = '0%';
        fill.style.backgroundColor = '#ef4444';
        label.textContent = 'Password strength: not set';
        return;
    }

    var score = 0;
    if (value.length >= 8) score++;
    if (value.length >= 12) score++;
    if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
    if (/[0-9]/.test(value)) score++;
    if (/[^A-Za-z0-9]/.test(value)) score++;

    var pct = (score / 5) * 100;
    fill.style.width = pct + '%';

    if (score <= 2) {
        fill.style.backgroundColor = '#ef4444';
        label.textContent = 'Password strength: weak';
    } else if (score <= 3) {
        fill.style.backgroundColor = '#f59e0b';
        label.textContent = 'Password strength: medium';
    } else {
        fill.style.backgroundColor = '#10b981';
        label.textContent = 'Password strength: strong';
    }
}

updatePasswordStrength();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
