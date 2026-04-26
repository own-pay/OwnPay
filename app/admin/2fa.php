<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

if ($global_user_2fa == true) {

} else {
    if ($global_user_login == true) {
        ?>
        <script nonce="<?= $csp_nonce ?? '' ?>">location.href = "<?php echo $site_url . $path_admin ?>/dashboard";</script>
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
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="author" content="OwnPay">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Two-Factor Authentication - OwnPay</title>
    <link rel="shortcut icon" href="<?= $OwnPay_favicon ?? '' ?>">
    <link rel="stylesheet" href="<?php echo $site_url ?>assets/css/admin.css">
</head>

<body class="bg-gray-50 dark:bg-gray-900 antialiased">
    <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">

        <!-- Logo -->
        <a href="javascript:void(0)" class="flex items-center mb-6">
            <img src="<?= $OwnPay_logo_light ?? '' ?>" class="h-10" alt="OwnPay">
        </a>

        <!-- 2FA Card -->
        <div class="w-full bg-white rounded-xl shadow-lg dark:border md:mt-0 sm:max-w-md xl:p-0 dark:bg-gray-800 dark:border-gray-700">
            <div class="p-6 space-y-4 md:space-y-6 sm:p-8">
                <h1 class="text-xl font-bold leading-tight tracking-tight text-gray-900 md:text-2xl dark:text-white text-center">
                    Authenticate Your Account
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center">
                    Login by verifying your 2FA via Google Authenticator
                </p>

                <form id="twofa-form" class="space-y-6" method="POST" action="">
                    <input type="hidden" name="action" value="2fa-verify">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

                    <!-- 6-digit code inputs -->
                    <div class="flex items-center justify-center gap-3">
                        <div class="flex gap-2">
                            <input type="text" name="code_one" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input
                                class="w-12 h-14 text-center text-xl font-semibold border border-gray-300 rounded-lg bg-gray-50 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white dark:focus:ring-primary-500">
                            <input type="text" name="code_two" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input
                                class="w-12 h-14 text-center text-xl font-semibold border border-gray-300 rounded-lg bg-gray-50 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white dark:focus:ring-primary-500">
                            <input type="text" name="code_three" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input
                                class="w-12 h-14 text-center text-xl font-semibold border border-gray-300 rounded-lg bg-gray-50 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white dark:focus:ring-primary-500">
                        </div>
                        <span class="text-2xl text-gray-300 dark:text-gray-600">—</span>
                        <div class="flex gap-2">
                            <input type="text" name="code_four" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input
                                class="w-12 h-14 text-center text-xl font-semibold border border-gray-300 rounded-lg bg-gray-50 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white dark:focus:ring-primary-500">
                            <input type="text" name="code_five" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input
                                class="w-12 h-14 text-center text-xl font-semibold border border-gray-300 rounded-lg bg-gray-50 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white dark:focus:ring-primary-500">
                            <input type="text" name="code_six" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input
                                class="w-12 h-14 text-center text-xl font-semibold border border-gray-300 rounded-lg bg-gray-50 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white dark:focus:ring-primary-500">
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <a href="?logout" class="op-btn-secondary w-full text-center">Logout</a>
                        <button type="submit" id="twofa-submit" class="op-btn-primary w-full">Verify</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?php echo $site_url ?>assets/js/flowbite.min.js"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?php echo $site_url ?>assets/js/app.js?v=2.0"></script>

    <script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
        // Auto-focus next input on digit entry
        document.addEventListener("DOMContentLoaded", function () {
            const inputs = document.querySelectorAll("[data-code-input]");
            inputs.forEach((input, i) => {
                input.addEventListener("input", (e) => {
                    if (e.target.value.length === 1 && i + 1 < inputs.length) {
                        inputs[i + 1].focus();
                    }
                });
                input.addEventListener("keydown", (e) => {
                    if (e.target.value.length === 0 && e.key === 'Backspace' && i > 0) {
                        inputs[i - 1].focus();
                    }
                });
                // Allow paste of full code
                input.addEventListener("paste", (e) => {
                    e.preventDefault();
                    const paste = (e.clipboardData || window.clipboardData).getData('text').trim();
                    if (/^\d{6}$/.test(paste)) {
                        inputs.forEach((inp, idx) => { inp.value = paste[idx] || ''; });
                        inputs[inputs.length - 1].focus();
                    }
                });
            });
        });

        document.getElementById('twofa-form').addEventListener('submit', function (e) {
            e.preventDefault();

            const form = this;
            const submitBtn = document.getElementById('twofa-submit');
            const originalText = submitBtn.innerHTML;

            submitBtn.innerHTML = '<div class="op-spinner" style="width:20px;height:20px;border-width:2px"></div>';
            submitBtn.disabled = true;

            const formData = new FormData(form);
            const params = new URLSearchParams(formData).toString();

            fetch('2fa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
                .then(res => res.json())
                .then(response => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;

                    const csrfInput = form.querySelector('input[name="csrf_token"]');
                    if (csrfInput && response.csrf_token) {
                        csrfInput.value = response.csrf_token;
                    }

                    if (response.status === 'true') {
                        location.href = response.target;
                    } else {
                        APToast.show({ title: response.title, description: response.message, type: 'error', timeout: 6000 });
                    }
                })
                .catch(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                    APToast.show({ title: 'Something Wrong!', description: 'For further assistance, please contact our support team.', type: 'error', timeout: 6000 });
                });
        });
    </script>
</body>

</html>
