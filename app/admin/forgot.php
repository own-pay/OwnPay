<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

if ($global_user_2fa == true) {
    ?>
    <script nonce="<?= $csp_nonce ?? '' ?>">location.href = "<?php echo $site_url ?>2fa";</script>
    <?php
    exit();
} else {
    if ($global_user_login == true) {
        ?>
        <script nonce="<?= $csp_nonce ?? '' ?>">location.href = "<?php echo $site_url . $path_admin ?>/dashboard";</script>
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
    <title>Forgot Password - OwnPay</title>
    <link rel="shortcut icon" href="<?= $OwnPay_favicon ?? '' ?>">
    <link rel="stylesheet" href="<?php echo $site_url ?>assets/css/admin.css">
</head>

<body class="bg-gray-50 dark:bg-gray-900 antialiased">
    <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">

        <!-- Logo -->
        <a href="javascript:void(0)" class="flex items-center mb-6">
            <img src="<?= $OwnPay_logo_light ?? '' ?>" class="h-10" alt="OwnPay">
        </a>

        <!-- Forgot Password Card -->
        <div class="w-full bg-white rounded-xl shadow-lg dark:border md:mt-0 sm:max-w-md xl:p-0 dark:bg-gray-800 dark:border-gray-700">
            <div class="p-6 space-y-4 md:space-y-6 sm:p-8">
                <h1 class="text-xl font-bold leading-tight tracking-tight text-gray-900 md:text-2xl dark:text-white text-center">
                    Forgot password
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center">
                    Enter your email address and your password will be reset and emailed to you.
                </p>

                <form id="forgot-form" class="space-y-4 md:space-y-6" method="POST" action="">
                    <input type="hidden" name="action" value="forgot-password">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

                    <div>
                        <label for="email-address" class="op-label">Email address</label>
                        <input type="email" name="email-address" id="email-address" class="op-input"
                            placeholder="Enter email address" required>
                    </div>

                    <button type="submit" id="forgot-submit" class="op-btn-primary w-full">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /><path d="M3 7l9 6l9 -6" /></svg>
                        Send me new password
                    </button>
                </form>
            </div>
        </div>

        <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">
            Forget it, <a href="login" class="font-medium text-primary-600 hover:underline dark:text-primary-500">send me back</a> to the sign in screen.
        </p>
    </div>

    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?php echo $site_url ?>assets/js/flowbite.min.js"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?php echo $site_url ?>assets/js/app.js?v=2.0"></script>

    <script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
        document.getElementById('forgot-form').addEventListener('submit', function (e) {
            e.preventDefault();

            const form = this;
            const submitBtn = document.getElementById('forgot-submit');
            const originalText = submitBtn.innerHTML;

            submitBtn.innerHTML = '<div class="op-spinner" style="width:20px;height:20px;border-width:2px"></div>';
            submitBtn.disabled = true;

            const formData = new FormData(form);
            const params = new URLSearchParams(formData).toString();

            fetch('forgot', {
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
                        APToast.show({ title: response.title, description: response.message, type: 'success', timeout: 6000 });
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
