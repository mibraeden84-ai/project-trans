document.addEventListener('DOMContentLoaded', function() {
    // Mobile nav toggle
    const navToggle = document.getElementById('navToggle');
    const navMenu = document.getElementById('navMenu');
    if (navToggle && navMenu) {
        navToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
        });
        document.addEventListener('click', function(e) {
            if (!navToggle.contains(e.target) && !navMenu.contains(e.target)) {
                navMenu.classList.remove('active');
            }
        });
    }

    // Sidebar toggle for user/editor layout
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('appSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            document.body.classList.toggle('sidebar-open');
        });
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', function() {
                document.body.classList.remove('sidebar-open');
            });
        }
        document.addEventListener('click', function(e) {
            if (window.innerWidth > 768) return;
            if (!document.body.classList.contains('sidebar-open')) return;
            const clickedToggle = sidebarToggle.contains(e.target);
            const clickedSidebar = sidebar.contains(e.target);
            if (!clickedToggle && !clickedSidebar) {
                document.body.classList.remove('sidebar-open');
            }
        });
    }

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // File card click enhancement
    document.querySelectorAll('.file-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (e.target.tagName === 'A' || e.target.closest('a')) return;
            const downloadBtn = this.querySelector('.btn-download, .btn-warning');
            if (downloadBtn) downloadBtn.click();
        });
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        if (document.body.classList.contains('sidebar-open')) {
            document.body.classList.remove('sidebar-open');
            return;
        }
        closeUploadModal();
    });

    // Upload modal brand -> model cascade
    const brandSelect = document.getElementById('modalBrand');
    const modelSelect = document.getElementById('modalModel');
    if (brandSelect && modelSelect) {
        brandSelect.addEventListener('change', function() {
            const brandId = this.value;
            modelSelect.innerHTML = '<option value="">— All / None —</option>';
            if (!brandId) return;
            fetch('upload_handler.php?action=models&brand_id=' + brandId)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        data.models.forEach(function(m) {
                            const opt = document.createElement('option');
                            opt.value = m.id;
                            opt.textContent = m.name;
                            modelSelect.appendChild(opt);
                        });
                    }
                }).catch(() => {});
        });
    }
});

// ============ UPLOAD MODAL ============

function openUploadModal() {
    document.getElementById('uploadModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    // Pre-fill display name from file if set
    updateDisplayNameFromFile();
}

function closeUploadModal(e) {
    if (e && e.target !== e.currentTarget) return;
    document.getElementById('uploadModal').classList.remove('active');
    document.body.style.overflow = '';
}

function modalToggleFields() {
    const type = document.getElementById('modalUploadType').value;
    document.getElementById('modalBrandRow').style.display = (type === 'common') ? 'none' : 'flex';
    document.getElementById('modalCategoryField').style.display = (type === 'common') ? 'block' : 'none';
    document.getElementById('modalChangelogField').style.display = (type === 'firmware') ? 'block' : 'none';
}

function updateFileName(input) {
    const placeholder = document.getElementById('filePlaceholder');
    const display = document.getElementById('fileNameDisplay');
    if (input.files.length > 0) {
        placeholder.style.display = 'none';
        display.textContent = input.files[0].name;
        display.style.display = 'inline';
        updateDisplayNameFromFile();
    } else {
        placeholder.style.display = 'flex';
        display.style.display = 'none';
    }
}

function updateDisplayNameFromFile() {
    const fileInput = document.getElementById('uploadFileInput');
    const nameInput = document.getElementById('displayName');
    if (fileInput.files.length > 0 && !nameInput.dataset.userEdited) {
        nameInput.value = fileInput.files[0].name.replace(/\.[^.]+$/, '');
    }
}

// Mark name as user-edited on first manual change
document.addEventListener('DOMContentLoaded', function() {
    const dn = document.getElementById('displayName');
    if (dn) {
        dn.addEventListener('input', function() { this.dataset.userEdited = '1'; });
    }
});

function submitUploadLegacy(e) {
    e.preventDefault();
    const form = document.getElementById('uploadForm');
    const formData = new FormData(form);
    const btn = document.getElementById('uploadSubmitBtn');
    const status = document.getElementById('uploadStatus');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    status.textContent = '';
    status.className = 'upload-status';

    fetch('upload_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            status.textContent = '✅ ' + data.message;
            status.className = 'upload-status success';
            setTimeout(function() {
                closeUploadModal();
                location.reload();
            }, 1200);
        } else {
            status.textContent = '❌ ' + data.message;
            status.className = 'upload-status error';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-upload"></i> Upload';
        }
    })
    .catch(err => {
        status.textContent = '❌ Upload failed. Check server.';
        status.className = 'upload-status error';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload"></i> Upload';
    });
}

function submitUpload(e) {
    e.preventDefault();
    const form = document.getElementById('uploadForm');
    const formData = new FormData(form);
    const btn = document.getElementById('uploadSubmitBtn');
    const status = document.getElementById('uploadStatus');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    status.textContent = 'Preparing upload...';
    status.className = 'upload-status';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'upload_handler.php', true);

    xhr.upload.onprogress = function(ev) {
        if (!ev.lengthComputable) {
            status.textContent = 'Uploading file...';
            return;
        }
        const pct = Math.min(98, Math.round((ev.loaded / ev.total) * 100));
        status.textContent = pct < 95
            ? ('Uploading file... ' + pct + '%')
            : 'Finalizing upload...';
    };

    xhr.onload = function() {
        let data = null;
        try {
            data = JSON.parse(xhr.responseText || '{}');
        } catch (err) {
            data = null;
        }

        if (xhr.status >= 200 && xhr.status < 300 && data && data.success) {
            status.textContent = 'Upload complete. ' + data.message;
            status.className = 'upload-status success';
            setTimeout(function() {
                closeUploadModal();
                location.reload();
            }, 1200);
            return;
        }

        status.textContent = (data && data.message) ? data.message : 'Upload failed. Check server.';
        status.className = 'upload-status error';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload"></i> Upload';
    };

    xhr.onerror = function() {
        status.textContent = 'Upload failed. Check server.';
        status.className = 'upload-status error';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload"></i> Upload';
    };

    xhr.send(formData);
}
