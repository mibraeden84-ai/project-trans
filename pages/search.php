<?php
$q = trim($_GET['q'] ?? '');
$results = [];
$typeCounts = ['config' => 0, 'firmware' => 0, 'manual' => 0, 'software' => 0];
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = defined('SEARCH_PAGE_SIZE') ? SEARCH_PAGE_SIZE : 24;
$offset = ($page - 1) * $perPage;
$totalResults = 0;

if (!empty($q)) {
    $searchPayload = $db->search($q, $perPage, $offset);
    $results = $searchPayload['items'] ?? [];
    $typeCounts = $searchPayload['type_counts'] ?? $typeCounts;
    $totalResults = (int)($searchPayload['total'] ?? count($results));
}
$totalPages = max(1, (int)ceil($totalResults / max(1, $perPage)));

$pageTitle = empty($q) ? 'Search' : "Search: $q";
require __DIR__ . '/../includes/header.php';
?>

<section class="modern-hero modern-hero-search">
    <div class="modern-hero-breadcrumb">
        <a href="index.php">Home</a> / <span>Search</span>
    </div>
    <div class="modern-hero-main">
        <div class="modern-hero-copy">
            <h1><i class="fas fa-search"></i> Search Library</h1>
            <p>Find firmware, configs, manuals, and software across all brands in one place.</p>
            <form action="index.php" method="GET" class="search-form">
                <input type="hidden" name="page" value="search">
                <div class="search-box search-box-modern">
                    <div class="search-input-wrap">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="text" name="q" placeholder="Search by device name, config, firmware..." value="<?= escape($q) ?>" required autofocus>
                    </div>
                    <button type="submit" class="search-submit-btn"><i class="fas fa-search"></i> Search</button>
                </div>
            </form>
            <div class="search-quick-label">Quick Filters</div>
            <div class="modern-hero-actions">
                <a href="index.php?page=search&q=firmware" class="modern-action-btn"><i class="fas fa-microchip"></i> Firmware</a>
                <a href="index.php?page=search&q=manual" class="modern-action-btn"><i class="fas fa-book"></i> Manuals</a>
                <a href="index.php?page=search&q=config" class="modern-action-btn"><i class="fas fa-file-code"></i> Configs</a>
                <a href="index.php?page=search&q=software" class="modern-action-btn"><i class="fas fa-gear"></i> Software</a>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($q)): ?>
<section class="modern-surface search-results">
    <div class="modern-section-head">
        <h2 class="section-title">Results for "<?= escape($q) ?>"</h2>
        <span class="modern-section-meta"><?= $totalResults ?> file(s) found</span>
    </div>
    <?php if (!empty($results)): ?>
    <div class="search-type-chips">
        <span class="search-type-chip"><i class="fas fa-file-code"></i> Configs: <?= $typeCounts['config'] ?></span>
        <span class="search-type-chip"><i class="fas fa-microchip"></i> Firmware: <?= $typeCounts['firmware'] ?></span>
        <span class="search-type-chip"><i class="fas fa-book"></i> Manuals: <?= $typeCounts['manual'] ?></span>
        <span class="search-type-chip"><i class="fas fa-gear"></i> Software: <?= $typeCounts['software'] ?></span>
    </div>
    <?php endif; ?>
    <?php if (empty($results)): ?>
    <div class="empty-state">
        <i class="fas fa-search-minus"></i>
        <p>No results found. Try a different search term.</p>
    </div>
    <?php else: ?>
    <div class="files-grid">
        <?php foreach ($results as $r): ?>
        <div class="file-card <?= escape($r['type']) ?>-card">
            <div class="file-icon"><i class="<?= getFileIcon($r['name']) ?>"></i></div>
            <div class="file-info">
                <h4><?= escape($r['name']) ?></h4>
                <div class="file-meta">
                    <span class="badge badge-type"><?= escape(ucfirst($r['type'])) ?></span>
                    <?php if (isset($r['version'])): ?>
                    <span class="badge badge-version">v<?= escape($r['version']) ?></span>
                    <?php endif; ?>
                    <?php if (isset($r['file_size'])): ?>
                    <span><?= formatFileSize($r['file_size']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($r['brand_name'])): ?>
                <p class="file-desc">Brand: <?= escape($r['brand_name']) ?> <?= isset($r['model_name']) ? '- ' . escape($r['model_name']) : '' ?></p>
                <?php endif; ?>
            </div>
            <?php
            $type = $r['type'];
            $downloadPage = $type === 'config' ? 'config' : ($type === 'firmware' ? 'firmware' : ($type === 'software' ? 'software' : 'manual'));
            ?>
            <a href="index.php?page=download&type=<?= $downloadPage ?>&id=<?= $r['id'] ?>" class="btn btn-download">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($totalResults > $perPage): ?>
    <div class="settings-nav" style="margin-top:18px">
        <?php if ($page > 1): ?>
        <a class="settings-nav-link" href="index.php?page=search&q=<?= urlencode($q) ?>&p=<?= $page - 1 ?>"><i class="fas fa-arrow-left"></i> Previous</a>
        <?php endif; ?>
        <span class="settings-nav-link" style="pointer-events:none;background:#f8fbff">Page <?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
        <a class="settings-nav-link" href="index.php?page=search&q=<?= urlencode($q) ?>&p=<?= $page + 1 ?>">Next <i class="fas fa-arrow-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
