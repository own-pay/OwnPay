<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

if ($global_user_login == true) {

} else {
    if ($global_user_2fa == true) {
        ?>
        <script nonce="<?= $csp_nonce ?? '' ?>">location.href = "<?php echo $site_url ?>2fa";</script>
        <?php
        exit();
    } else {
        ?>
        <script nonce="<?= $csp_nonce ?? '' ?>">location.href = "<?php echo $site_url ?>login";</script>
        <?php
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo (!isset($_COOKIE['apTheme']) || $_COOKIE['apTheme'] === 'dark') ? 'dark' : ''; ?>">

<head>
    <meta charset="utf-8">
    <meta name="author" content="OwnPay">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>OwnPay</title>
    <link rel="shortcut icon" href="<?= $OwnPay_favicon ?? '' ?>">
    <link rel="stylesheet" href="<?php echo $site_url ?>assets/css/admin.css?v=4.0">
</head>

<body class="bg-gray-50 dark:bg-navy-850 antialiased">
    <a href="#main-content" class="op-skip-nav">Skip to main content</a>

    <?php include __DIR__ . '/layouts/_navbar.php'; ?>

    <?php include __DIR__ . '/layouts/_sidebar.php'; ?>

    <!-- ========== MAIN CONTENT ========== -->
    <div class="op-page-wrapper" id="main-content">
        <div class="root-print op-page-container">
            <div class="flex justify-center items-center py-32">
                <div class="op-spinner"></div>
            </div>
        </div>

        <?php include __DIR__ . '/layouts/_footer.php'; ?>
    </div>

    <?php include __DIR__ . '/layouts/_modals.php'; ?>

    <!-- ========== SCRIPTS ========== -->
    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?php echo $site_url ?>assets/js/flowbite.min.js"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?php echo $site_url ?>assets/js/app.js?v=2.0"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?php echo $site_url ?>assets/js/op-fetch.js?v=3.1"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>" src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>" src="https://cdn.jsdelivr.net/npm/choices.js@11.0.2/public/assets/scripts/choices.min.js"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>" src="https://cdn.jsdelivr.net/npm/hugerte@1/hugerte.min.js"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>" src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <input type="hidden" name="csrf_token_default" value="<?= $csrf_token; ?>">

    <script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
        // Store the page's CSP nonce for dynamic script injection in SPA navigation
        const __opCspNonce = '<?= addslashes($csp_nonce ?? '') ?>';

        // Chart global variables
        let chartTransactionStatistics = null;
        let chartGatewayStatistics = null;
        window.InvoiceCustomerChoices = null;

        function set_brand(brand_id) {
            var csrf_token_default = apGetCsrf();

            APSidebar.closeMobile();
            showProgress();

            const formData = new URLSearchParams();
            formData.append('action', 'set-default-brand');
            formData.append('brand_id', brand_id);
            formData.append('csrf_token', csrf_token_default);

            fetch('<?php echo $site_url . $path_admin ?>/dashboard', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
                .then(res => res.json())
                .then(response => {
                    apSetCsrf(response.csrf_token);

                    if (response.status === 'true') {
                        location.reload();
                    } else {
                        hideProgress();
                        APToast.show({
                            title: response.title,
                            description: response.message,
                            type: 'error',
                            timeout: 6000
                        });
                    }
                })
                .catch(error => {
                    hideProgress();
                    APToast.show({
                        title: 'Something Wrong!',
                        description: 'For further assistance, please contact our support team.',
                        type: 'error',
                        timeout: 6000
                    });
                });
        }

        function getAdminPath(url) {
            let cleanUrl = url.split('?')[0];
            let index = cleanUrl.indexOf('<?php echo $path_admin ?>/');
            if (index === -1) return '';
            return cleanUrl.substring(index + '<?php echo $path_admin ?>/'.length).replace(/^\/+/, '');
        }

        function getQueryParams(url) {
            const params = {};
            const queryString = url.split('?')[1];
            if (!queryString) return params;
            const searchParams = new URLSearchParams(queryString);
            for (const [key, value] of searchParams.entries()) {
                params[key] = value === '' ? true : value;
            }
            return params;
        }

        function load_content(page, url, nav_id, fromPopState = false) {
            const cleanPath = getAdminPath(url);
            const queryParams = getQueryParams(url);

            showProgress();
            APSidebar.closeMobile();

            // Close any open dropdowns
            document.getElementById('op-user-dropdown')?.classList.add('hidden');
            document.getElementById('op-brand-dropdown')?.classList.add('hidden');

            fetch('<?php echo $site_url ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    root: cleanPath,
                    params: JSON.stringify(queryParams),
                    csrf_token: apGetCsrf()
                })
            })
                .then(res => res.text())
                .then(html => {
                    const rootPrint = document.querySelector('.root-print');
                    rootPrint.innerHTML = html;

                    // Re-execute inline scripts (innerHTML doesn't run <script> tags)
                    // Replace let/const with var to avoid redeclaration errors on SPA re-navigation
                    // Override nonce with page's original CSP nonce to avoid CSP violations
                    rootPrint.querySelectorAll('script').forEach(oldScript => {
                        const newScript = document.createElement('script');
                        Array.from(oldScript.attributes).forEach(attr => {
                            if (attr.name === 'nonce') return; // skip — will set the page's nonce below
                            newScript.setAttribute(attr.name, attr.value);
                        });
                        if (typeof __opCspNonce === 'string' && __opCspNonce) {
                            newScript.nonce = __opCspNonce;
                        }
                        newScript.textContent = oldScript.textContent.replace(/^(\s*)(let|const)\s+/gm, '$1var ');
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });

                    initHugeRTE();
                    initInvoiceCustomer();
                    initToolTips();
                    initChoices();
                    initChoices('.js-select');
                    initTags();
                    hideProgress();

                    // Re-init Flowbite components for dynamic content
                    if (typeof initFlowbite === 'function') initFlowbite();

                    // Update active sidebar link
                    document.querySelectorAll('#op-sidebar .op-sidebar-link').forEach(link => {
                        link.classList.remove('active');
                    });
                    const activeItem = document.querySelector('#op-sidebar .' + nav_id);
                    if (activeItem) {
                        const link = activeItem.querySelector('.op-sidebar-link');
                        if (link) link.classList.add('active');
                    }
                    document.title = page + ' - OwnPay';

                    if (!fromPopState) {
                        history.pushState({ page, path: url, nav_id }, "", url);
                    }
                })
                .catch(error => {
                    document.querySelector('.root-print').innerHTML = '<div class="flex flex-col items-center justify-center py-32"><div class="w-20 h-20 mb-4 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4.99c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Connection Error</h3><p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Unable to load this page. Please check your connection.</p><button onclick="location.reload()" class="op-btn-primary">Reload Page</button></div>';
                    console.error('Navigation error:', error);
                });
        }

        window.addEventListener("popstate", function (event) {
            if (event.state) {
                load_content(event.state.page, event.state.path, event.state.nav_id, true);
            }
        });

        document.addEventListener("DOMContentLoaded", function () {
            let currentUrlV = window.location.href;

            if (currentUrlV == '<?php echo $site_url . $path_admin ?>/' || currentUrlV == '<?php echo $site_url . $path_admin ?>') {
                var currentUrl = '<?php echo $site_url . $path_admin ?>/dashboard';
            } else {
                var currentUrl = window.location.href;
            }

            const cleanPath = getAdminPath(currentUrl);
            let pageTitle = cleanPath.split('/').map(segment => segment.replace(/-/g, ' ').replace(/\b\w/g, char => char.toUpperCase())).join(' - ') || 'Dashboard';
            let nav_id = 'nav-item-' + (cleanPath.split('/')[0] || 'dashboard');

            load_content(pageTitle, currentUrl, nav_id);


            // ============================================================
            // CSP-Safe Delegated Event Listener
            // Handles all data-op-* attribute actions
            // ============================================================
            document.addEventListener('click', function(e) {
                // --- Navigation links (data-op-nav-title, data-op-nav-url, data-op-nav-id) ---
                var navEl = e.target.closest('[data-op-nav-title]');
                if (navEl) {
                    e.preventDefault();
                    var title = navEl.getAttribute('data-op-nav-title');
                    var url = navEl.getAttribute('data-op-nav-url');
                    var navId = navEl.getAttribute('data-op-nav-id');
                    var closeTarget = navEl.getAttribute('data-op-close');
                    if (closeTarget) {
                        document.getElementById(closeTarget)?.classList.add('hidden');
                    }
                    load_content(title, url, navId);
                    return;
                }

                // --- Action buttons (data-op-action) ---
                var actionEl = e.target.closest('[data-op-action]');
                if (actionEl) {
                    var action = actionEl.getAttribute('data-op-action');

                    if (action === 'theme-toggle') {
                        e.preventDefault();
                        APTheme.toggle();
                        return;
                    }


                    if (action === 'set-brand') {
                        e.preventDefault();
                        var brandId = actionEl.getAttribute('data-op-brand');
                        if (brandId && typeof set_brand === 'function') {
                            set_brand(brandId);
                        }
                        return;
                    }

                    if (action === 'two-step-verify') {
                        e.preventDefault();
                        if (typeof two_step_verify_tab_btn === 'function') {
                            two_step_verify_tab_btn();
                        }
                        return;
                    }

                    if (action === 'action-confirm') {
                        e.preventDefault();
                        if (typeof my_action_confirmation_btn === 'function') {
                            my_action_confirmation_btn();
                        }
                        return;
                    }
                }
            });

            // ============================================================
            // Mobile Search Toggle
            // ============================================================
            const mobileSearchToggle = document.getElementById('op-mobile-search-toggle');
            const mobileSearchBar = document.getElementById('op-mobile-search');
            if (mobileSearchToggle && mobileSearchBar) {
                mobileSearchToggle.addEventListener('click', function() {
                    mobileSearchBar.classList.toggle('hidden');
                    if (!mobileSearchBar.classList.contains('hidden')) {
                        mobileSearchBar.querySelector('input')?.focus();
                    }
                });
            }

            // ============================================================
            // Search — Quick navigation for all search inputs
            // ============================================================
            const searchMap = [
                { keywords: ['dashboard', 'home', 'overview'], title: 'Dashboard', url: '<?php echo $site_url . $path_admin ?>/dashboard', nav: 'nav-item-dashboard' },
                { keywords: ['transaction', 'payment', 'order'], title: 'Transaction', url: '<?php echo $site_url . $path_admin ?>/transaction', nav: 'nav-item-transaction' },
                { keywords: ['invoice', 'bill'], title: 'Invoice', url: '<?php echo $site_url . $path_admin ?>/invoice', nav: 'nav-item-invoice' },
                { keywords: ['payment link', 'link'], title: 'Payment Link', url: '<?php echo $site_url . $path_admin ?>/payment-link', nav: 'nav-item-payment-link' },
                { keywords: ['gateway', 'method'], title: 'Gateways', url: '<?php echo $site_url . $path_admin ?>/gateways', nav: 'nav-item-gateways' },
                { keywords: ['customer', 'client', 'user'], title: 'Customers', url: '<?php echo $site_url . $path_admin ?>/customers', nav: 'nav-item-customers' },
                { keywords: ['setting', 'config', 'preference'], title: 'Settings', url: '<?php echo $site_url . $path_admin ?>/settings', nav: 'nav-item-settings' },
                { keywords: ['report', 'analytic', 'stat'], title: 'Reports', url: '<?php echo $site_url . $path_admin ?>/reports', nav: 'nav-item-reports' },
                { keywords: ['brand', 'store', 'business'], title: 'Brands', url: '<?php echo $site_url . $path_admin ?>/brands', nav: 'nav-item-brands' },
                { keywords: ['sms', 'message', 'notification', 'device', 'phone'], title: 'SMS Center', url: '<?php echo $site_url . $path_admin ?>/sms-center', nav: 'nav-item-sms-center' },
                { keywords: ['activity', 'log', 'audit'], title: 'Settings', url: '<?php echo $site_url . $path_admin ?>/settings?tab=activity', nav: 'nav-item-settings' },
            ];

            function handleSearch(e) {
                if (e.key !== 'Enter') return;
                const query = e.target.value.trim().toLowerCase();
                if (!query) return;
                const match = searchMap.find(item => item.keywords.some(k => query.includes(k)));
                if (match) {
                    load_content(match.title, match.url, match.nav);
                    e.target.value = '';
                    if (mobileSearchBar) mobileSearchBar.classList.add('hidden');
                } else {
                    // Default: navigate to transactions with search query
                    load_content('Transaction', '<?php echo $site_url . $path_admin ?>/transaction', 'nav-item-transaction');
                    e.target.value = '';
                    if (mobileSearchBar) mobileSearchBar.classList.add('hidden');
                }
            }

            document.querySelectorAll('#op-mobile-search input, .op-navbar input[type="text"]').forEach(input => {
                input.addEventListener('keydown', handleSearch);
            });
        });
    </script>
</body>

</html>
