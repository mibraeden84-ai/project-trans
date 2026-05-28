<?php
$myDownloadTotalCount = 0;
$myDownloadByDay = [];

$homeDashboardStats = [
    'brands' => 0,
    'models' => 0,
    'configs' => 0,
    'firmware' => 0,
    'manuals' => 0,
    'software' => 0,
    'total_files' => 0,
];
$homeRecentLibrary = [];
$fileTypeMeta = [
    'config' => ['label' => 'Config', 'icon' => 'fa-file-code'],
    'firmware' => ['label' => 'Firmware', 'icon' => 'fa-microchip'],
    'manual' => ['label' => 'Manual', 'icon' => 'fa-book'],
    'software' => ['label' => 'Software', 'icon' => 'fa-gear'],
];

function formatUsEtDashValue(?string $value, string $format): string {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    try {
        $dt = new DateTime($raw);
        // Keep stored timestamps displayed in USA Eastern Time for consistency.
        $dt->setTimezone(new DateTimeZone('America/New_York'));
        return $dt->format($format);
    } catch (Throwable $e) {
        return '';
    }
}

function nowUsEtDashLabel(string $format = 'h:i:s A'): string {
    try {
        $tz = defined('DASH_TIMEZONE') ? DASH_TIMEZONE : 'America/New_York';
        $label = defined('DASH_TIMEZONE_LABEL') ? DASH_TIMEZONE_LABEL : 'ET';
        $dt = new DateTime('now', new DateTimeZone($tz));
        return $dt->format($format) . ' ' . $label;
    } catch (Throwable $e) {
        return '';
    }
}

try {
    // Full totals (no date range)
    $homeDashboardStats['configs'] = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM config_files WHERE status = 'active'")['c'] ?? 0);
    $homeDashboardStats['firmware'] = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM firmware_files WHERE status = 'active'")['c'] ?? 0);
    $homeDashboardStats['manuals'] = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM manuals WHERE status = 'active'")['c'] ?? 0);
    $homeDashboardStats['software'] = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM software_files WHERE status = 'active'")['c'] ?? 0);
    $homeDashboardStats['total_files'] = $homeDashboardStats['configs'] + $homeDashboardStats['firmware'] + $homeDashboardStats['manuals'] + $homeDashboardStats['software'];

    $homeDashboardStats['brands'] = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM brands")['c'] ?? 0);
    $homeDashboardStats['models'] = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM device_models")['c'] ?? 0);

    $recentConfigs = $db->fetchAll(
        "SELECT c.id, c.name, c.created_at, b.name AS brand_name, 'config' AS file_type
         FROM config_files c
         JOIN device_models dm ON dm.id = c.device_model_id
         JOIN brands b ON b.id = dm.brand_id
         WHERE c.status = 'active'
         ORDER BY c.created_at DESC
         LIMIT 3"
    );
    $recentFirmware = $db->fetchAll(
        "SELECT f.id, f.name, f.created_at, b.name AS brand_name, 'firmware' AS file_type
         FROM firmware_files f
         JOIN brands b ON b.id = f.brand_id
         WHERE f.status = 'active'
         ORDER BY f.created_at DESC
         LIMIT 3"
    );
    $recentManuals = $db->fetchAll(
        "SELECT m.id, m.name, m.created_at, b.name AS brand_name, 'manual' AS file_type
         FROM manuals m
         JOIN brands b ON b.id = m.brand_id
         WHERE m.status = 'active'
         ORDER BY m.created_at DESC
         LIMIT 3"
    );
    $recentSoftware = $db->fetchAll(
        "SELECT s.id, s.name, s.created_at, b.name AS brand_name, 'software' AS file_type
         FROM software_files s
         JOIN brands b ON b.id = s.brand_id
         WHERE s.status = 'active'
         ORDER BY s.created_at DESC
         LIMIT 3"
    );

    $homeRecentLibrary = array_merge($recentConfigs, $recentFirmware, $recentManuals, $recentSoftware);
    usort($homeRecentLibrary, static function ($a, $b) {
        return strtotime((string)($b['created_at'] ?? '')) <=> strtotime((string)($a['created_at'] ?? ''));
    });
    $homeRecentLibrary = array_slice($homeRecentLibrary, 0, 6);

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId > 0) {
        if (ensureUserUsageColumns()) {
            $myRow = $db->fetchOne("SELECT total_downloads FROM users WHERE id = ?", [$currentUserId]);
            $myDownloadTotalCount = (int)($myRow['total_downloads'] ?? 0);
        }
        if (ensureUserDownloadEventsTable()) {
            $myDownloadByDay = $db->fetchAll(
                "SELECT DATE(downloaded_at) AS day_key, COUNT(*) AS c
                 FROM user_downloads
                 WHERE user_id = ?
                 GROUP BY DATE(downloaded_at)
                 ORDER BY day_key DESC
                 LIMIT 7",
                [$currentUserId]
            );
        }
    }
} catch (Throwable $e) {
    $homeRecentLibrary = [];
}

if (isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === 'dashboard_live') {
    header('Content-Type: application/json; charset=utf-8');

    $daily = [];
    foreach ($myDownloadByDay as $dayRow) {
        $dayKey = (string)($dayRow['day_key'] ?? '');
        $daily[] = [
            'count' => (int)($dayRow['c'] ?? 0),
            'label' => $dayKey !== '' ? date('M d', strtotime($dayKey)) : '',
        ];
    }

    $recent = [];
    foreach ($homeRecentLibrary as $recentItem) {
        $typeKey = (string)($recentItem['file_type'] ?? 'config');
        $typeMeta = $fileTypeMeta[$typeKey] ?? $fileTypeMeta['config'];
        $name = (string)($recentItem['name'] ?? 'Untitled');
        $brandName = (string)($recentItem['brand_name'] ?? 'Brand');
        $createdAt = (string)($recentItem['created_at'] ?? '');
        $recent[] = [
            'name' => $name,
            'type_label' => (string)($typeMeta['label'] ?? 'File'),
            'type_icon' => (string)($typeMeta['icon'] ?? 'fa-file-code'),
            'brand_name' => $brandName,
            'created_label' => formatUsEtDashValue($createdAt, 'M d, Y'),
            'search_url' => 'index.php?page=search&q=' . urlencode($name),
        ];
    }

    echo json_encode([
        'success' => true,
        'generated_label' => nowUsEtDashLabel('h:i:s A'),
        'stats' => [
            'total_files' => (int)$homeDashboardStats['total_files'],
            'brands' => (int)$homeDashboardStats['brands'],
            'models' => (int)$homeDashboardStats['models'],
            'configs' => (int)$homeDashboardStats['configs'],
            'firmware' => (int)$homeDashboardStats['firmware'],
            'manuals' => (int)$homeDashboardStats['manuals'],
            'software' => (int)$homeDashboardStats['software'],
            'my_downloads_total' => (int)$myDownloadTotalCount,
        ],
        'daily' => $daily,
        'recent' => $recent,
    ]);
    exit;
}

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<section class="user-hub user-hub-shell reveal" id="userDashboardRoot">
    <div class="user-hub-top">
        <div class="user-hub-title">
            <h2><i class="fas fa-chart-line"></i> Workspace Dashboard</h2>
            <p>Live overview of your library activity with quick shortcuts.</p>
        </div>
    </div>
    <div class="user-hub-date-filter">
        <span class="user-hub-date-meta" id="userDashLiveStatus">Live every 12s (USA ET)</span>
    </div>
    <div class="user-hub-kpis user-hub-kpis-modern">
        <div class="user-hub-kpi"><em class="user-hub-kpi-icon"><i class="fas fa-layer-group"></i></em><strong id="kpiTotalFiles"><?= (int)$homeDashboardStats['total_files'] ?></strong><span>Total Files</span></div>
        <div class="user-hub-kpi"><em class="user-hub-kpi-icon"><i class="fas fa-sitemap"></i></em><strong id="kpiBrands"><?= (int)$homeDashboardStats['brands'] ?></strong><span>Brands</span></div>
        <div class="user-hub-kpi"><em class="user-hub-kpi-icon"><i class="fas fa-microchip"></i></em><strong id="kpiModels"><?= (int)$homeDashboardStats['models'] ?></strong><span>Models</span></div>
        <div class="user-hub-kpi"><em class="user-hub-kpi-icon"><i class="fas fa-file-code"></i></em><strong id="kpiConfigs"><?= (int)$homeDashboardStats['configs'] ?></strong><span>Configs</span></div>
        <div class="user-hub-kpi"><em class="user-hub-kpi-icon"><i class="fas fa-memory"></i></em><strong id="kpiFirmware"><?= (int)$homeDashboardStats['firmware'] ?></strong><span>Firmware</span></div>
        <div class="user-hub-kpi"><em class="user-hub-kpi-icon"><i class="fas fa-book-open"></i></em><strong id="kpiManuals"><?= (int)$homeDashboardStats['manuals'] ?></strong><span>Manuals</span></div>
        <div class="user-hub-kpi"><em class="user-hub-kpi-icon"><i class="fas fa-gear"></i></em><strong id="kpiSoftware"><?= (int)$homeDashboardStats['software'] ?></strong><span>Software</span></div>
        <div class="user-hub-kpi"><em class="user-hub-kpi-icon"><i class="fas fa-download"></i></em><strong id="kpiMyDownloadsTotal"><?= (int)$myDownloadTotalCount ?></strong><span>My Downloads</span></div>
    </div>
    <div class="user-hub-download-daily" id="userDashDailyWrap" style="<?= empty($myDownloadByDay) ? 'display:none' : '' ?>">
        <?php foreach ($myDownloadByDay as $myDay): ?>
        <span><strong><?= (int)($myDay['c'] ?? 0) ?></strong> on <?= escape(date('M d', strtotime((string)($myDay['day_key'] ?? 'now')))) ?></span>
        <?php endforeach; ?>
    </div>
    <div class="user-hub-recent">
        <div class="user-hub-recent-head">
            <h3><i class="fas fa-clock"></i> Recent Updates</h3>
            <span>Newest items added to library</span>
        </div>
        <div id="userDashRecentWrap">
        <?php if (empty($homeRecentLibrary)): ?>
        <div class="user-hub-empty">No recent uploads found yet.</div>
        <?php else: ?>
        <div class="user-hub-recent-list">
            <?php foreach ($homeRecentLibrary as $recentItem): ?>
            <?php $typeKey = (string)($recentItem['file_type'] ?? 'config'); ?>
            <?php $typeMeta = $fileTypeMeta[$typeKey] ?? $fileTypeMeta['config']; ?>
            <a class="user-hub-recent-item" href="index.php?page=search&q=<?= urlencode((string)($recentItem['name'] ?? '')) ?>">
                <span class="user-hub-recent-icon"><i class="fas <?= escape($typeMeta['icon']) ?>"></i></span>
                <span class="user-hub-recent-body">
                    <strong><?= escape($recentItem['name'] ?? 'Untitled') ?></strong>
                    <small><?= escape($typeMeta['label']) ?> - <?= escape($recentItem['brand_name'] ?? 'Brand') ?> - <?= escape(date('M d, Y', strtotime((string)($recentItem['created_at'] ?? 'now')))) ?></small>
                </span>
                <i class="fas fa-arrow-right user-hub-recent-arrow"></i>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div>
    </div>
</section>

<script>
(function initUserDashboardLiveRefresh() {
    var root = document.getElementById('userDashboardRoot');
    if (!root || typeof fetch !== 'function') return;

    var statusEl = document.getElementById('userDashLiveStatus');
    var dailyWrap = document.getElementById('userDashDailyWrap');
    var recentWrap = document.getElementById('userDashRecentWrap');
    var pollMs = 12000;
    var inFlight = false;
    var timerId = null;

    function safeText(v) { return v == null ? '' : String(v); }
    function esc(v) {
        return safeText(v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function setNum(id, v) {
        var el = document.getElementById(id);
        if (!el) return;
        var n = parseInt(v, 10);
        el.textContent = Number.isFinite(n) ? String(Math.max(0, n)) : '0';
    }
    function params() {
        var p = new URLSearchParams();
        p.set('page', 'dashboard');
        p.set('ajax', 'dashboard_live');
        return p.toString();
    }
    function renderDaily(items) {
        if (!dailyWrap) return;
        if (!Array.isArray(items) || items.length === 0) {
            dailyWrap.style.display = 'none';
            dailyWrap.innerHTML = '';
            return;
        }
        var html = '';
        items.forEach(function(item) {
            html += '<span><strong>' + esc(item.count) + '</strong> on ' + esc(item.label) + '</span>';
        });
        dailyWrap.innerHTML = html;
        dailyWrap.style.display = '';
    }
    function renderRecent(items) {
        if (!recentWrap) return;
        if (!Array.isArray(items) || items.length === 0) {
            recentWrap.innerHTML = '<div class="user-hub-empty">No recent uploads found yet.</div>';
            return;
        }
        var html = '<div class="user-hub-recent-list">';
        items.forEach(function(item) {
            html += '<a class="user-hub-recent-item" href="' + esc(item.search_url) + '">';
            html += '<span class="user-hub-recent-icon"><i class="fas ' + esc(item.type_icon) + '"></i></span>';
            html += '<span class="user-hub-recent-body">';
            html += '<strong>' + esc(item.name) + '</strong>';
            html += '<small>' + esc(item.type_label) + ' - ' + esc(item.brand_name) + ' - ' + esc(item.created_label) + '</small>';
            html += '</span>';
            html += '<i class="fas fa-arrow-right user-hub-recent-arrow"></i>';
            html += '</a>';
        });
        html += '</div>';
        recentWrap.innerHTML = html;
    }
    function apply(data) {
        if (!data || data.success !== true || !data.stats) return;
        setNum('kpiTotalFiles', data.stats.total_files);
        setNum('kpiBrands', data.stats.brands);
        setNum('kpiModels', data.stats.models);
        setNum('kpiConfigs', data.stats.configs);
        setNum('kpiFirmware', data.stats.firmware);
        setNum('kpiManuals', data.stats.manuals);
        setNum('kpiSoftware', data.stats.software);
        setNum('kpiMyDownloadsTotal', data.stats.my_downloads_total);
        renderDaily(data.daily || []);
        renderRecent(data.recent || []);
        if (statusEl) statusEl.textContent = 'Live updated ' + safeText(data.generated_label);
    }
    function refresh() {
        if (inFlight) return;
        inFlight = true;
        fetch('index.php?' + params(), {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(apply)
        .catch(function() {
            if (statusEl) statusEl.textContent = 'Live refresh retrying...';
        })
        .finally(function() {
            inFlight = false;
        });
    }
    function start() {
        if (timerId) return;
        timerId = window.setInterval(refresh, pollMs);
    }
    function stop() {
        if (!timerId) return;
        window.clearInterval(timerId);
        timerId = null;
    }
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stop();
        } else {
            refresh();
            start();
        }
    });
    window.addEventListener('beforeunload', stop);
    refresh();
    start();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
