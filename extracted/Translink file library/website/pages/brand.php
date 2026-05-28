<?php
$slug = $_GET['slug'] ?? '';
$brand = $db->fetchOne("SELECT * FROM brands WHERE slug = ?", [$slug]);

if (!$brand) {
    require __DIR__ . '/../includes/header.php';
    echo '<div class="error-page"><h1>404</h1><p>Brand not found</p><a href="index.php" class="btn">Back to Home</a></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$models = $db->getModelsByBrand($brand['id']);
$firmware = $db->getFirmwareByBrand($brand['id']);
$manuals = $db->getManualsByBrand($brand['id']);
$software = $db->getSoftwareByBrand($brand['id']);

$pageTitle = $brand['name'];
require __DIR__ . '/../includes/header.php';
?>

<section class="modern-hero modern-hero-brand" style="--brand-color: <?= escape($brand['color']) ?>">
    <div class="modern-hero-breadcrumb">
        <a href="index.php">Home</a> / <span><?= escape($brand['name']) ?></span>
    </div>
    <div class="modern-hero-main">
        <div class="modern-hero-icon"><?= getBrandIcon($brand['icon'], $brand['slug']) ?></div>
        <div class="modern-hero-copy">
            <h1><?= escape($brand['name']) ?> Device Library</h1>
            <p><?= escape($brand['description']) ?></p>
        </div>
        <div class="modern-hero-actions">
            <a href="#models" class="modern-action-btn"><i class="fas fa-microchip"></i> Browse Models</a>
        </div>
    </div>
    <div class="modern-kpi-grid">
        <div class="modern-kpi"><strong><?= count($models) ?></strong><span>Models</span></div>
        <div class="modern-kpi"><strong><?= count($firmware) ?></strong><span>Firmware</span></div>
        <div class="modern-kpi"><strong><?= count($manuals) ?></strong><span>Manuals</span></div>
        <div class="modern-kpi"><strong><?= count($software) ?></strong><span>Software</span></div>
    </div>
</section>

<section id="models" class="modern-surface models-section">
    <div class="modern-section-head">
        <h2 class="section-title"><i class="fas fa-microchip"></i> Device Models</h2>
        <span class="modern-section-meta"><?= count($models) ?> available</span>
    </div>
    <?php if (empty($models)): ?>
    <div class="empty-state">
        <i class="fas fa-microchip"></i>
        <p>No models available for this brand yet.</p>
    </div>
    <?php else: ?>
    <div class="models-grid"><?php renderModelCards($models, $db, $brand); ?></div>
    <?php endif; ?>
</section>

<?php
function renderModelCards($models, $db, $brand) {
    foreach ($models as $model):
        $configs = $db->getConfigsByModel($model['id']);
        $modelFirmware = $db->getFirmwareByBrandAndModel($brand['id'], $model['id']);
        $modelManuals = $db->getManualsByBrandAndModel($brand['id'], $model['id']);
        $modelSoftware = $db->getSoftwareByBrandAndModel($brand['id'], $model['id']);
    ?>
    <div class="model-card modern-model-card">
        <div class="model-header">
            <h3><?= escape($model['name']) ?>
                <?php if (!empty($model['system_type'])): ?>
                <span class="badge" style="background:<?= $model['system_type'] === 'advanced' ? '#005aa0' : '#6c757d' ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:0.7rem;margin-left:6px;vertical-align:middle"><?= $model['system_type'] === 'advanced' ? 'Advanced' : 'Standard' ?></span>
                <?php endif; ?>
            </h3>
            <?php if ($model['description']): ?>
            <p><?= escape($model['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="model-stats">
            <span><i class="fas fa-file-code"></i> <?= count($configs) ?> configs</span>
            <span><i class="fas fa-microchip"></i> <?= count($modelFirmware) ?> firmware</span>
            <span><i class="fas fa-book"></i> <?= count($modelManuals) ?> manuals</span>
            <span><i class="fas fa-gear"></i> <?= count($modelSoftware) ?> software</span>
        </div>
        <div class="model-actions">
            <a href="index.php?page=model&brand=<?= escape($brand['slug']) ?>&model=<?= escape($model['slug']) ?>" class="btn btn-primary">
                <i class="fas fa-folder-open"></i> Open
            </a>
        </div>
    </div>
    <?php endforeach;
}
?>

<?php if (!empty($software)): ?>
<section class="modern-surface software-section">
    <div class="modern-section-head">
        <h2 class="section-title"><i class="fas fa-gear"></i> Configurator Software</h2>
        <span class="modern-section-meta"><?= count($software) ?> files</span>
    </div>
    <div class="files-table-wrapper">
        <table class="files-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Model</th>
                    <th>Version</th>
                    <th>Size</th>
                    <th>Downloads</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($software as $s): ?>
                <tr>
                    <td class="file-name"><i class="<?= getFileIcon($s['name']) ?>"></i> <?= escape($s['name']) ?></td>
                    <td><?= escape($s['model_name'] ?? 'All') ?></td>
                    <td><span class="badge badge-version">v<?= escape($s['version']) ?></span></td>
                    <td><?= formatFileSize($s['file_size']) ?></td>
                    <td><?= (int)$s['download_count'] ?></td>
                    <td><a href="index.php?page=download&type=software&id=<?= $s['id'] ?>" class="btn btn-sm btn-download"><i class="fas fa-download"></i></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($firmware)): ?>
<section class="modern-surface firmware-section">
    <div class="modern-section-head">
        <h2 class="section-title"><i class="fas fa-microchip"></i> Firmware Updates</h2>
        <span class="modern-section-meta"><?= count($firmware) ?> files</span>
    </div>
    <div class="files-table-wrapper">
        <table class="files-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Model</th>
                    <th>Version</th>
                    <th>Size</th>
                    <th>Downloads</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($firmware as $fw): ?>
                <tr>
                    <td class="file-name"><i class="<?= getFileIcon($fw['name']) ?>"></i> <?= escape($fw['name']) ?></td>
                    <td><?= escape($fw['model_name'] ?? 'All') ?></td>
                    <td><span class="badge badge-version">v<?= escape($fw['version']) ?></span></td>
                    <td><?= formatFileSize($fw['file_size']) ?></td>
                    <td><?= (int)$fw['download_count'] ?></td>
                    <td><a href="index.php?page=download&type=firmware&id=<?= $fw['id'] ?>" class="btn btn-sm btn-download"><i class="fas fa-download"></i></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($manuals)): ?>
<section class="modern-surface manuals-section">
    <div class="modern-section-head">
        <h2 class="section-title"><i class="fas fa-book"></i> Manuals & Guides</h2>
        <span class="modern-section-meta"><?= count($manuals) ?> files</span>
    </div>
    <div class="files-table-wrapper">
        <table class="files-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Model</th>
                    <th>Size</th>
                    <th>Downloads</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($manuals as $m): ?>
                <tr>
                    <td class="file-name"><i class="<?= getFileIcon($m['name']) ?>"></i> <?= escape($m['name']) ?></td>
                    <td><?= escape($m['model_name'] ?? 'General') ?></td>
                    <td><?= formatFileSize($m['file_size']) ?></td>
                    <td><?= (int)$m['download_count'] ?></td>
                    <td><a href="index.php?page=download&type=manual&id=<?= $m['id'] ?>" class="btn btn-sm btn-download"><i class="fas fa-download"></i></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
