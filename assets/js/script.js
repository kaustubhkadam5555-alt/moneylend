/**
 * MoneyLend - Application Script
 * 
 * Vanilla JavaScript handling mobile sidebar toggling, responsive interactions,
 * accessible alert dismissals, and Bootstrap component initialization.
 */

document.addEventListener('DOMContentLoaded', function () {
    // -------------------------------------------------------------
    // 1. Mobile Sidebar Toggle Functionality
    // -------------------------------------------------------------
    const sidebar = document.getElementById('appSidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const sidebarClose = document.getElementById('sidebarClose');

    function openSidebar() {
        if (sidebar && sidebarBackdrop) {
            sidebar.classList.add('show-sidebar');
            sidebarBackdrop.classList.add('show-backdrop');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling on mobile
        }
    }

    function closeSidebar() {
        if (sidebar && sidebarBackdrop) {
            sidebar.classList.remove('show-sidebar');
            sidebarBackdrop.classList.remove('show-backdrop');
            document.body.style.overflow = '';
        }
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (sidebar && sidebar.classList.contains('show-sidebar')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', function (e) {
            e.preventDefault();
            closeSidebar();
        });
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', function () {
            closeSidebar();
        });
    }

    // Auto-close mobile sidebar when clicking any navigation link
    if (sidebar) {
        const sidebarLinks = sidebar.querySelectorAll('.sidebar-link');
        sidebarLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth < 992) {
                    closeSidebar();
                }
            });
        });
    }

    // Auto-close sidebar on screen resize larger than tablet breakpoint
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992) {
            closeSidebar();
        }
    });

    // -------------------------------------------------------------
    // 2. Initialize Bootstrap Tooltips (if available)
    // -------------------------------------------------------------
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    // -------------------------------------------------------------
    // 3. Auto-dismiss Success Flash Alerts (keep errors visible)
    // -------------------------------------------------------------
    const successAlerts = document.querySelectorAll('.alert-success.alert-dismissible');
    successAlerts.forEach(function (alert) {
        setTimeout(function () {
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            } else {
                alert.style.transition = 'opacity 0.4s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 400);
            }
        }, 5000);
    });

    // -------------------------------------------------------------
    // 4. Duplicate Form Submission Prevention (POST Forms)
    // -------------------------------------------------------------
    const postForms = document.querySelectorAll('form[method="POST"], form[method="post"]');
    postForms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            // Respect HTML5 client-side validation
            if (!form.checkValidity()) {
                return;
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                // Defer button disabling slightly to allow submit dispatch
                setTimeout(function () {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('disabled');
                    const originalHtml = submitBtn.innerHTML;
                    submitBtn.setAttribute('data-original-html', originalHtml);
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
                }, 10);
            }
        });
    });
});
