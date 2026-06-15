<?php
$brandSlug = $_GET['brand'] ?? '';
$modelSlug = $_GET['model'] ?? '';

$brand = $db->fetchOne("SELECT * FROM brands WHERE slug = ?", [$brandSlug]);
if (!$brand) { require __DIR__ . '/../includes/header.php'; echo '<div class="error-page"><h1>404</h1><p>Brand not found</p><a href="index.php" class="btn">Back to Home</a></div>'; require __DIR__ . '/../includes/footer.php'; exit; }

$model = $db->getModelBySlug($brand['id'], $modelSlug);
if (!$model) { require __DIR__ . '/../includes/header.php'; echo '<div class="error-page"><h1>404</h1><p>Model not found</p><a href="index.php?page=brand&slug=' . escape($brand['slug']) . '" class="btn">Back to ' . escape($brand['name']) . '</a></div>'; require __DIR__ . '/../includes/footer.php'; exit; }

$configs = $db->getConfigsByModel($model['id']);
$modelFirmware = $db->getFirmwareByBrandAndModel($brand['id'], $model['id']);
$modelManuals = $db->getManualsByBrandAndModel($brand['id'], $model['id']);
$modelSoftware = $db->getSoftwareByBrandAndModel($brand['id'], $model['id']);

$pageTitle = $model['name'];
require __DIR__ . '/../includes/header.php';
?>

<section class="modern-hero modern-hero-model" style="--brand-color: <?= escape($brand['color']) ?>">
    <div class="modern-hero-breadcrumb">
        <a href="index.php">Home</a> / <a href="index.php?page=brand&slug=<?= escape($brand['slug']) ?>"><?= escape($brand['name']) ?></a> / <span><?= escape($model['name']) ?></span>
    </div>
    <div class="modern-hero-main">
        <div class="modern-hero-copy">
            <h1><?= escape($model['name']) ?></h1>
            <p><?= escape($model['description'] ?: 'Configuration files, software, and firmware for this model.') ?></p>
            <div class="model-meta">
                <span><i class="fas fa-server"></i> Server: 88.99.188.166:2050</span>
                <span><i class="fas fa-wifi"></i> APN: internet.et</span>
            </div>
        </div>
        <div class="modern-hero-actions">
            <a href="#configs" class="modern-action-btn"><i class="fas fa-file-code"></i> Configs</a>
            <a href="#software" class="modern-action-btn"><i class="fas fa-gear"></i> Software</a>
            <a href="#manuals" class="modern-action-btn"><i class="fas fa-book"></i> Manuals</a>
            <a href="#firmware" class="modern-action-btn"><i class="fas fa-microchip"></i> Firmware</a>
        </div>
    </div>
    <div class="modern-kpi-grid">
        <div class="modern-kpi"><strong><?= count($configs) ?></strong><span>Configs</span></div>
        <div class="modern-kpi"><strong><?= count($modelSoftware) ?></strong><span>Software</span></div>
        <div class="modern-kpi"><strong><?= count($modelManuals) ?></strong><span>Manuals</span></div>
        <div class="modern-kpi"><strong><?= count($modelFirmware) ?></strong><span>Firmware</span></div>
    </div>
</section>

<section id="configs" class="modern-surface configs-section">
    <div class="modern-section-head">
        <h2 class="section-title"><i class="fas fa-file-code"></i> Configuration Files</h2>
        <span class="modern-section-meta"><?= count($configs) ?> files</span>
    </div>
    <?php if (empty($configs)): ?>
    <div class="empty-state">
        <i class="fas fa-folder-open"></i>
        <p>No configuration files available yet.</p>
    </div>
    <?php else: ?>
    <div class="files-grid">
        <?php foreach ($configs as $cfg): ?>
        <?php
        $cfgSystemType = strtolower(trim((string)($cfg['system_type'] ?? '')));
        if ($cfgSystemType === '' && !empty($model['system_type'])) {
            $cfgSystemType = strtolower(trim((string)$model['system_type']));
        }
        $cfgSystemLabel = 'General';
        $cfgSystemClass = 'badge-system-general';
        if ($cfgSystemType === 'advanced') {
            $cfgSystemLabel = 'Advanced';
            $cfgSystemClass = 'badge-system-advanced';
        } elseif ($cfgSystemType === 'standard') {
            $cfgSystemLabel = 'Standard';
            $cfgSystemClass = 'badge-system-standard';
        }
        ?>
        <div class="file-card">
            <div class="file-icon"><i class="<?= getFileIcon($cfg['name']) ?>"></i></div>
            <div class="file-info">
                <h4><?= escape($cfg['name']) ?></h4>
                <div class="file-meta">
                    <span class="badge <?= $cfgSystemClass ?>"><i class="fas fa-layer-group"></i> <?= $cfgSystemLabel ?></span>
                    <span class="badge badge-version">v<?= escape($cfg['version']) ?></span>
                    <span><?= formatFileSize($cfg['file_size']) ?></span>
                    <span><i class="fas fa-download"></i> <?= (int)$cfg['download_count'] ?></span>
                </div>
                <?php if ($cfg['description']): ?>
                <p class="file-desc"><?= escape($cfg['description']) ?></p>
                <?php endif; ?>
            </div>
            <a href="index.php?page=download&type=config&id=<?= $cfg['id'] ?>" class="btn btn-download">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section id="software" class="modern-surface software-section">
    <div class="modern-section-head">
        <h2 class="section-title"><i class="fas fa-gear"></i> Configurator Software</h2>
        <span class="modern-section-meta"><?= count($modelSoftware) ?> files</span>
    </div>
    <?php if (empty($modelSoftware)): ?>
    <div class="empty-state">
        <i class="fas fa-gear"></i>
        <p>No configurator software available yet.</p>
    </div>
    <?php else: ?>
    <div class="files-grid">
        <?php foreach ($modelSoftware as $s): ?>
        <div class="file-card">
            <div class="file-icon"><i class="fas fa-gear"></i></div>
            <div class="file-info">
                <h4><?= escape($s['name']) ?></h4>
                <div class="file-meta">
                    <span class="badge badge-version">v<?= escape($s['version']) ?></span>
                    <span><?= formatFileSize($s['file_size']) ?></span>
                    <span><i class="fas fa-download"></i> <?= (int)$s['download_count'] ?></span>
                </div>
                <?php if ($s['description']): ?>
                <p class="file-desc"><?= escape($s['description']) ?></p>
                <?php endif; ?>
            </div>
            <a href="index.php?page=download&type=software&id=<?= $s['id'] ?>" class="btn btn-download">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section id="manuals" class="modern-surface manuals-section">
    <div class="modern-section-head">
        <h2 class="section-title"><i class="fas fa-book"></i> Manuals & Guides</h2>
        <span class="modern-section-meta"><?= count($modelManuals) ?> files</span>
    </div>
    <?php if (empty($modelManuals)): ?>
    <div class="empty-state">
        <i class="fas fa-book"></i>
        <p>No manuals available yet.</p>
    </div>
    <?php else: ?>
    <div class="files-grid">
        <?php foreach ($modelManuals as $m): ?>
        <div class="file-card">
            <div class="file-icon"><i class="<?= getFileIcon($m['name']) ?>"></i></div>
            <div class="file-info">
                <h4><?= escape($m['name']) ?></h4>
                <div class="file-meta">
                    <span><?= formatFileSize($m['file_size']) ?></span>
                    <span><i class="fas fa-download"></i> <?= (int)$m['download_count'] ?></span>
                </div>
                <?php if (!empty($m['description'])): ?>
                <p class="file-desc"><?= escape($m['description']) ?></p>
                <?php endif; ?>
            </div>
            <a href="index.php?page=download&type=manual&id=<?= $m['id'] ?>" class="btn btn-download">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section id="firmware" class="modern-surface firmware-section">
    <div class="modern-section-head">
        <h2 class="section-title"><i class="fas fa-microchip"></i> Firmware</h2>
        <span class="modern-section-meta"><?= count($modelFirmware) ?> files</span>
    </div>
    <?php if (empty($modelFirmware)): ?>
    <div class="empty-state">
        <i class="fas fa-microchip"></i>
        <p>No firmware files available yet.</p>
    </div>
    <?php else: ?>
    <div class="files-grid">
        <?php foreach ($modelFirmware as $fw): ?>
        <div class="file-card firmware-card">
            <div class="file-icon"><i class="fas fa-microchip"></i></div>
            <div class="file-info">
                <h4><?= escape($fw['name']) ?></h4>
                <div class="file-meta">
                    <span class="badge badge-warning">v<?= escape($fw['version']) ?></span>
                    <span><?= formatFileSize($fw['file_size']) ?></span>
                    <span><i class="fas fa-download"></i> <?= (int)$fw['download_count'] ?></span>
                </div>
                <?php if ($fw['changelog']): ?>
                <p class="file-desc"><?= escape($fw['changelog']) ?></p>
                <?php endif; ?>
            </div>
            <a href="index.php?page=download&type=firmware&id=<?= $fw['id'] ?>" class="btn btn-warning">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
