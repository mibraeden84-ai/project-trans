        </div>
    </main>
    <?php
    $hideFooterForUserLayout = ($useSidebarLayout ?? false);
    ?>
    <?php if (!$hideFooterForUserLayout): ?>
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-col">
                    <div class="footer-info">
                        <strong>Translink File Library</strong>
                        <p>GPS configuration files, firmware, manuals, and software for fleet tracking devices. Your central hub for all Translink GPS resources.</p>
                    </div>
                    <div style="margin-top:16px;display:flex;gap:12px">
                        <a href="#" style="color:rgba(255,255,255,0.5);font-size:1.1rem;transition:color 0.2s" onmouseover="this.style.color='#fff'" onmouseout="this.style.color=''"><i class="fab fa-facebook"></i></a>
                        <a href="#" style="color:rgba(255,255,255,0.5);font-size:1.1rem;transition:color 0.2s" onmouseover="this.style.color='#fff'" onmouseout="this.style.color=''"><i class="fab fa-linkedin"></i></a>
                        <a href="#" style="color:rgba(255,255,255,0.5);font-size:1.1rem;transition:color 0.2s" onmouseover="this.style.color='#fff'" onmouseout="this.style.color=''"><i class="fab fa-youtube"></i></a>
                        <a href="#" style="color:rgba(255,255,255,0.5);font-size:1.1rem;transition:color 0.2s" onmouseover="this.style.color='#fff'" onmouseout="this.style.color=''"><i class="fab fa-github"></i></a>
                    </div>
                </div>
                <div class="footer-col">
                    <h4>Brands</h4>
                    <ul>
                        <li><a href="index.php?page=brand&slug=teltonika"><i class="fas fa-chevron-right"></i> Teltonika</a></li>
                        <li><a href="index.php?page=brand&slug=galileosky"><i class="fas fa-chevron-right"></i> GalileoSky</a></li>
                        <li><a href="index.php?page=brand&slug=starlink"><i class="fas fa-chevron-right"></i> StarLink</a></li>
                        <li><a href="index.php?page=brand&slug=dash-cam"><i class="fas fa-chevron-right"></i> Dash Cam</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Resources</h4>
                    <ul>
                        <li><a href="index.php?page=search&q=config"><i class="fas fa-chevron-right"></i> Configuration Files</a></li>
                        <li><a href="index.php?page=search&q=firmware"><i class="fas fa-chevron-right"></i> Firmware Updates</a></li>
                        <li><a href="index.php?page=search&q=manual"><i class="fas fa-chevron-right"></i> Manuals & Guides</a></li>
                        <li><a href="index.php?page=search&q=software"><i class="fas fa-chevron-right"></i> Software Tools</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Support</h4>
                    <ul>
                        <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                        <?php if (canManageFiles()): ?><li><a href="admin/dashboard.php"><i class="fas fa-chevron-right"></i> Admin Dashboard</a></li><?php endif; ?>
                        <li><a href="index.php?page=profile"><i class="fas fa-chevron-right"></i> My Profile</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <span>&copy; <?= date('Y') ?> Translink. All rights reserved.</span>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <a href="#">Terms of Service</a>
                    <a href="#">Contact</a>
                </div>
            </div>
        </div>
    </footer>
    <?php endif; ?>
    <script src="assets/js/script.js"></script>
    <script>
    // Scroll reveal
    document.addEventListener('DOMContentLoaded', function() {
        var revealEls = document.querySelectorAll('.reveal');
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
        revealEls.forEach(function(el) { observer.observe(el); });

        // Navbar glass on scroll
        var navbar = document.querySelector('.navbar');
        if (navbar) {
            window.addEventListener('scroll', function() {
                navbar.classList.toggle('scrolled', window.scrollY > 20);
            });
            if (window.scrollY > 20) navbar.classList.add('scrolled');
        }

        // User workspace: page-only filter (does not jump to global search page)
        var pageFilterToggle = document.getElementById('workspacePageFilterToggle');
        var pageFilterWrap = document.getElementById('workspacePageFilterWrap');
        var pageFilterInput = document.getElementById('workspacePageFilterInput');
        var pageFilterClear = document.getElementById('workspacePageFilterClear');
        var pageFilterMeta = document.getElementById('workspacePageFilterMeta');
        var pageFilterEmpty = document.getElementById('workspacePageFilterEmpty');
        var pageFilterRoot = document.querySelector('.main-content-sidebar .container');

        if (pageFilterToggle && pageFilterWrap && pageFilterInput && pageFilterRoot) {
            var pageFilterSelectors = [
                '[data-page-filter-item]',
                '.brand-card',
                '.quick-card',
                '.model-card',
                '.file-card',
                '.dash-card',
                '.modern-kpi',
                '.search-type-chip',
                '.profile-kpi',
                '.profile-meta-item',
                '.profile-section',
                '.user-hub-kpi',
                '.user-hub-recent-item',
                '.files-table tbody tr',
                'table tbody tr'
            ].join(',');

            var pageFilterItems = Array.prototype.slice.call(pageFilterRoot.querySelectorAll(pageFilterSelectors)).filter(function(el) {
                return !el.closest('.workspace-topbar') && !el.closest('.workspace-page-filter-wrap');
            });

            if (pageFilterItems.length === 0) {
                pageFilterItems = Array.prototype.slice.call(pageFilterRoot.querySelectorAll('section, .modern-surface, .profile-section')).filter(function(el) {
                    return !el.closest('.workspace-topbar') && !el.closest('.workspace-page-filter-wrap');
                });
            }

            pageFilterItems.forEach(function(item) {
                item.dataset.filterBaseDisplay = item.tagName === 'TR' ? 'table-row' : '';
            });

            function applyPageFilter() {
                var term = (pageFilterInput.value || '').trim().toLowerCase();
                var total = pageFilterItems.length;
                var visible = 0;

                pageFilterItems.forEach(function(item) {
                    var text = (item.textContent || '').toLowerCase();
                    var isMatch = term === '' ? true : text.indexOf(term) !== -1;
                    item.style.display = isMatch ? (item.dataset.filterBaseDisplay || '') : 'none';
                    if (isMatch) visible++;
                });

                if (!pageFilterMeta) return;
                if (term === '') {
                    pageFilterMeta.textContent = '';
                    if (pageFilterEmpty) pageFilterEmpty.hidden = true;
                } else {
                    pageFilterMeta.textContent = visible + '/' + total;
                    if (pageFilterEmpty) pageFilterEmpty.hidden = visible !== 0;
                }
            }

            function openPageFilter() {
                pageFilterWrap.hidden = false;
                pageFilterToggle.setAttribute('aria-expanded', 'true');
                setTimeout(function() {
                    pageFilterInput.focus();
                    pageFilterInput.select();
                }, 10);
            }

            function closePageFilter(reset) {
                if (reset) {
                    pageFilterInput.value = '';
                    applyPageFilter();
                }
                pageFilterWrap.hidden = true;
                pageFilterToggle.setAttribute('aria-expanded', 'false');
                if (pageFilterEmpty) pageFilterEmpty.hidden = true;
            }

            pageFilterToggle.addEventListener('click', function() {
                if (pageFilterWrap.hidden) {
                    openPageFilter();
                } else {
                    closePageFilter(true);
                }
            });

            pageFilterInput.addEventListener('input', applyPageFilter);
            pageFilterInput.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closePageFilter(true);
                    pageFilterToggle.focus();
                }
            });

            if (pageFilterClear) {
                pageFilterClear.addEventListener('click', function() {
                    pageFilterInput.value = '';
                    applyPageFilter();
                    pageFilterInput.focus();
                });
            }
        }
    });
    </script>
</body>
</html>
