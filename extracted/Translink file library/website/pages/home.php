<?php
$brands = $db->getBrands();

$pageTitle = SITE_NAME;
require __DIR__ . '/../includes/header.php';
?>

<section class="hero">
    <div class="hero-orb"></div>
    <div class="hero-orb"></div>
    <div class="hero-orb"></div>
    <div class="hero-orbit-ring"></div>
    <div class="hero-orbit-ring"></div>
    <div class="hero-orbit-ring"></div>
    <div class="hero-signal"></div>
    <div class="hero-signal"></div>
    <div class="hero-signal"></div>
    <div class="hero-signal"></div>
    <div class="hero-signal"></div>
    <div class="hero-signal"></div>
    <div class="hero-signal"></div>
    <div class="hero-sat-dot"></div>
    <div class="hero-sat-dot"></div>
    <div class="hero-crosshair"></div>
    <div class="hero-crosshair"></div>
    <div class="hero-crosshair"></div>
    <div class="hero-content">
        <h1>Translink File Library</h1>
        <p class="hero-sub">Your central hub for GPS configurations, firmware updates, manuals, and fleet management tools — all in one place.</p>
        <form action="index.php" method="GET" class="hero-search">
            <input type="hidden" name="page" value="search">
            <div class="search-box">
                <input type="text" name="q" placeholder="Search devices, configs, or firmware..." required>
                <button type="submit"><i class="fas fa-search"></i> Search</button>
            </div>
        </form>

        <div class="hero-stats">
            <span><i class="fas fa-file"></i> GPS Configs</span>
            <span><i class="fas fa-microchip"></i> Firmware Updates</span>
            <span><i class="fas fa-file-pdf"></i> Manuals & Guides</span>
            <span><i class="fas fa-gear"></i> Configurator Software</span>
            <span class="hero-stat-video"><i class="fas fa-video"></i> Video Telematics</span>
        </div>
    </div>
</section>

<section class="brands-section">
    <div class="reveal">
        <h2 class="section-title">Select Device Brand</h2>
        <p class="section-subtitle">Choose a brand below to browse device models, configs, firmware, and documentation.</p>
    </div>
    <div class="brands-grid">
        <?php foreach ($brands as $i => $brand):
        $brandImg = $brand['image'] ?? null;
        ?>
        <a href="index.php?page=brand&slug=<?= escape($brand['slug']) ?>" class="brand-card reveal" style="--brand-color: <?= escape($brand['color']) ?>; transition-delay: <?= $i * 0.08 ?>s">
            <div class="brand-icon-large">
                <?php if ($brandImg): ?>
                <img src="<?= escape($brandImg) ?>" alt="<?= escape($brand['name']) ?>" class="brand-img-icon">
                <?php else: ?>
                <?= getBrandIcon($brand['icon'], $brand['slug']) ?>
                <?php endif; ?>
            </div>
            <h3><?= escape($brand['name']) ?></h3>
            <p><?= escape($brand['description']) ?></p>
            <span class="brand-btn">Browse Devices <i class="fas fa-arrow-right"></i></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="quick-links">
    <div class="reveal">
        <h2 class="section-title">Quick Access</h2>
        <p class="section-subtitle">Frequently accessed resources and popular categories.</p>
    </div>
    <div class="quick-grid">
        <a href="index.php#home-firmware" class="quick-card reveal" style="transition-delay: 0.05s">
            <div class="quick-icon-wrap"><i class="fas fa-microchip"></i></div>
            <h4>Latest Firmware</h4>
            <p>Download newest firmware updates for all devices</p>
        </a>
        <a href="index.php#home-manuals" class="quick-card reveal" style="transition-delay: 0.1s">
            <div class="quick-icon-wrap"><i class="fas fa-book"></i></div>
            <h4>Installation Manuals</h4>
            <p>Step-by-step installation PDFs and guides</p>
        </a>
        <a href="index.php#home-software" class="quick-card reveal" style="transition-delay: 0.15s">
            <div class="quick-icon-wrap"><i class="fas fa-gear"></i></div>
            <h4>Configurator Software</h4>
            <p>Device configuration tools and utilities</p>
        </a>
        <a href="index.php#home-video" class="quick-card reveal" style="transition-delay: 0.2s">
            <div class="quick-icon-wrap"><i class="fas fa-video"></i></div>
            <h4>Video Telematics</h4>
            <p>Dash cams and MDVR solutions for fleet safety</p>
        </a>
    </div>
</section>

<!-- ========== VIDEO TELEMATICS ========== -->
<?php
$videoFirmware = $db->getLatestVideoFirmware(4);
$videoSoftware = $db->getLatestVideoSoftware(4);
if (!empty($videoFirmware) || !empty($videoSoftware)):
?>
<section class="video-section" id="home-video">
    <div class="video-bg-glow"></div>
    <div class="video-bg-grid"></div>
    <div class="video-section-inner">
        <div class="reveal">
            <h2 class="section-title video-title"><i class="fas fa-video video-title-icon"></i> Video Telematics</h2>
            <p class="section-subtitle video-subtitle">Dash cams and MDVR solutions for fleet safety and driver monitoring.</p>
        </div>
        <div class="video-dash">
            <div class="video-dash-main">
                <div class="dash-screen">
                    <div class="dash-screen-header">
                        <span class="dash-screen-dot red"></span>
                        <span class="dash-screen-dot yellow"></span>
                        <span class="dash-screen-dot green"></span>
                        <span class="dash-screen-label">Live Feed — Dash Cam</span>
                    </div>
                    <div class="dash-screen-body">
                        <div class="dash-feed-map">
                            <div class="dash-map-marker" style="top:35%;left:45%"><i class="fas fa-map-pin"></i></div>
                            <div class="dash-map-marker" style="top:55%;left:30%"><i class="fas fa-map-pin"></i></div>
                            <div class="dash-map-marker" style="top:40%;left:65%"><i class="fas fa-map-pin"></i></div>
                            <div class="dash-map-marker pulse" style="top:48%;left:48%"><i class="fas fa-video"></i></div>
                            <div class="dash-feed-grid"></div>
                            <div class="dash-feed-scanline"></div>
                        </div>
                        <div class="dash-feed-overlay">
                            <div class="dash-feed-stat">
                                <span class="dash-feed-stat-label">STATUS</span>
                                <span class="dash-feed-stat-value recording">REC</span>
                            </div>
                            <div class="dash-feed-stat">
                                <span class="dash-feed-stat-label">VEHICLES</span>
                                <span class="dash-feed-stat-value">12</span>
                            </div>
                            <div class="dash-feed-stat">
                                <span class="dash-feed-stat-label">ALERTS</span>
                                <span class="dash-feed-stat-value" style="color:#ff6b6b">2</span>
                            </div>
                            <div class="dash-feed-stat">
                                <span class="dash-feed-stat-label">UPLOAD</span>
                                <span class="dash-feed-stat-value">4K</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="video-dash-side">
                <div class="dash-card reveal" style="transition-delay:0.05s">
                    <i class="fas fa-shield-alt"></i>
                    <div><strong>Collision Detection</strong><span>AI-powered alerts</span></div>
                </div>
                <div class="dash-card reveal" style="transition-delay:0.1s">
                    <i class="fas fa-map-marked-alt"></i>
                    <div><strong>GPS Tracking</strong><span>Real-time location</span></div>
                </div>
                <div class="dash-card reveal" style="transition-delay:0.15s">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <div><strong>Cloud Upload</strong><span>Auto-sync footage</span></div>
                </div>
                <div class="dash-card reveal" style="transition-delay:0.2s">
                    <i class="fas fa-mobile-alt"></i>
                    <div><strong>Live View</strong><span>Remote monitoring</span></div>
                </div>
            </div>
        </div>
        <?php if (!empty($videoFirmware)): ?>
        <div class="reveal">
            <h3 class="video-subsection-title"><i class="fas fa-microchip"></i> Dash Cam Firmware</h3>
        </div>
        <div class="files-grid video-grid">
            <?php foreach ($videoFirmware as $vf): ?>
            <div class="file-card video-file-card reveal" style="transition-delay:0.05s">
                <div class="file-icon video-file-icon"><i class="fas fa-video"></i></div>
                <div class="file-info">
                    <h4><?= escape($vf['name']) ?></h4>
                    <div class="file-meta">
                        <span class="badge badge-brand dash-cam">Dash Cam</span>
                        <span class="badge badge-version">v<?= escape($vf['version']) ?></span>
                        <span><?= formatFileSize($vf['file_size']) ?></span>
                    </div>
                    <?php if ($vf['changelog']): ?>
                    <p class="file-desc"><?= escape($vf['changelog']) ?></p>
                    <?php endif; ?>
                </div>
                <a href="index.php?page=download&type=firmware&id=<?= $vf['id'] ?>" class="btn btn-video">
                    <i class="fas fa-download"></i> Get
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($videoSoftware)): ?>
        <div class="reveal">
            <h3 class="video-subsection-title"><i class="fas fa-gear"></i> Dash Cam Software</h3>
        </div>
        <div class="files-grid video-grid">
            <?php foreach ($videoSoftware as $vs): ?>
            <div class="file-card video-file-card reveal" style="transition-delay:0.05s">
                <div class="file-icon video-file-icon"><i class="fas fa-video"></i></div>
                <div class="file-info">
                    <h4><?= escape($vs['name']) ?></h4>
                    <div class="file-meta">
                        <span class="badge badge-brand dash-cam">Dash Cam</span>
                        <span class="badge badge-version">v<?= escape($vs['version']) ?></span>
                        <span><?= formatFileSize($vs['file_size']) ?></span>
                    </div>
                    <?php if ($vs['description']): ?>
                    <p class="file-desc"><?= escape($vs['description']) ?></p>
                    <?php endif; ?>
                </div>
                <a href="index.php?page=download&type=software&id=<?= $vs['id'] ?>" class="btn btn-video">
                    <i class="fas fa-download"></i> Get
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="video-cta reveal">
            <a href="index.php?page=brand&slug=dash-cam" class="btn btn-video-cta">
                View All Dash Cam Products <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ========== LATEST MANUALS ========== -->
<?php
$latestManuals = [];
try {
    $latestManuals = $db->fetchAll(
        "SELECT m.*, b.name as brand_name, b.slug as brand_slug, dm.name as model_name
         FROM manuals m
         JOIN brands b ON m.brand_id = b.id
         LEFT JOIN device_models dm ON m.device_model_id = dm.id
         WHERE m.status = 'active'
         ORDER BY m.created_at DESC
         LIMIT 6"
    );
} catch (Throwable $e) {
    $latestManuals = [];
}
if (!empty($latestManuals)):
?>
<section class="manuals-section home-manuals" id="home-manuals">
    <div class="reveal">
        <h2 class="section-title"><i class="fas fa-book"></i> Manuals & Guides</h2>
        <p class="section-subtitle">Installation guides and documentation across all supported brands.</p>
    </div>
    <div class="files-grid">
        <?php foreach ($latestManuals as $m): ?>
        <div class="file-card reveal" style="transition-delay: 0.05s">
            <div class="file-icon"><i class="<?= getFileIcon($m['name']) ?>"></i></div>
            <div class="file-info">
                <h4><?= escape($m['name']) ?></h4>
                <div class="file-meta">
                    <span class="badge badge-brand <?= escape($m['brand_slug']) ?>"><?= escape($m['brand_name']) ?></span>
                    <span><?= formatFileSize($m['file_size']) ?></span>
                    <span><i class="fas fa-download"></i> <?= (int)$m['download_count'] ?></span>
                </div>
                <?php if (!empty($m['model_name'])): ?>
                <p class="file-desc">Model: <?= escape($m['model_name']) ?></p>
                <?php endif; ?>
            </div>
            <a href="index.php?page=download&type=manual&id=<?= $m['id'] ?>" class="btn btn-download">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ========== LATEST SOFTWARE ========== -->
<?php
$latestSoftware = $db->getLatestSoftware(6);
if (!empty($latestSoftware)):
?>
<section class="software-section home-software" id="home-software">
    <div class="reveal">
        <h2 class="section-title"><i class="fas fa-gear"></i> Configurator Software</h2>
        <p class="section-subtitle">Device configuration tools and utilities for all supported brands.</p>
    </div>
    <div class="files-grid">
        <?php foreach ($latestSoftware as $s): ?>
        <div class="file-card reveal" style="transition-delay: 0.05s">
            <div class="file-icon"><i class="fas fa-gear"></i></div>
            <div class="file-info">
                <h4><?= escape($s['name']) ?></h4>
                <div class="file-meta">
                    <span class="badge badge-brand <?= escape($s['brand_slug']) ?>"><?= escape($s['brand_name']) ?></span>
                    <span class="badge badge-version">v<?= escape($s['version']) ?></span>
                    <span><?= formatFileSize($s['file_size']) ?></span>
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
</section>
<?php endif; ?>

<!-- ========== LATEST FIRMWARE UPDATES ========== -->
<?php
$latestFirmware = $db->getLatestFirmware(6);
if (!empty($latestFirmware)):
?>
<section class="firmware-section home-firmware" id="home-firmware">
    <div class="reveal">
        <h2 class="section-title"><i class="fas fa-microchip"></i> Latest Firmware Updates</h2>
        <p class="section-subtitle">Keep your devices up to date with the newest firmware releases.</p>
    </div>
    <div class="files-grid">
        <?php foreach ($latestFirmware as $fw): ?>
        <div class="file-card firmware-card reveal" style="transition-delay: 0.05s">
            <div class="file-icon"><i class="fas fa-microchip"></i></div>
            <div class="file-info">
                <h4><?= escape($fw['name']) ?></h4>
                <div class="file-meta">
                    <span class="badge badge-brand <?= escape($fw['brand_slug']) ?>"><?= escape($fw['brand_name']) ?></span>
                    <span class="badge badge-version">v<?= escape($fw['version']) ?></span>
                    <span><?= formatFileSize($fw['file_size']) ?></span>
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
</section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
