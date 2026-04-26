<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

if ($global_user_login == true) {
    ?>
    <script nonce="<?= $csp_nonce ?>">location.href = "<?php echo $site_url . $path_admin ?>/dashboard";</script>
    <?php
    exit();
} else {
    if ($global_user_2fa == true) {
        ?>
        <script nonce="<?= $csp_nonce ?>">location.href = "<?php echo $site_url ?>2fa";</script>
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
    <title>Login - OwnPay</title>
    <link rel="shortcut icon" href="<?= $OwnPay_favicon ?? '' ?>">
    <link rel="stylesheet" href="<?php echo $site_url ?>assets/css/admin.css">
</head>

<body class="bg-gray-50 dark:bg-gray-900 antialiased">
    <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">

        <!-- Logo -->
        <a href="javascript:void(0)" class="flex items-center mb-6">
            <img src="<?= $OwnPay_logo_light ?? '' ?>" class="h-10" alt="OwnPay">
        </a>

        <!-- Login Card -->
        <div class="w-full bg-white rounded-xl shadow-lg dark:border md:mt-0 sm:max-w-md xl:p-0 dark:bg-gray-800 dark:border-gray-700">
            <div class="p-6 space-y-4 md:space-y-6 sm:p-8">
                <h1 class="text-xl font-bold leading-tight tracking-tight text-gray-900 md:text-2xl dark:text-white text-center">
                    Login to your account
                </h1>

                <form class="space-y-4 md:space-y-6" id="login-form" method="POST" action="">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

                    <div>
                        <label for="username" class="op-label">Email or Username</label>
                        <input type="text" name="username" id="username" class="op-input"
                            placeholder="Enter email or username"
                            value="<?php
                                // F4: demo creds gated by dual env-var check
                                // Show ONLY when (a) demo flag set, AND (b) APP_ENV != production,
                                // AND (c) explicit DEMO_MODE=1 env var set.
                                $_showDemo = !empty($op_demo_mode)
                                    && ((getenv('APP_ENV') ?: 'production') !== 'production')
                                    && (getenv('DEMO_MODE') === '1');
                                echo $_showDemo ? 'demo@ownpay.org' : '';
                            ?>">
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label for="password" class="text-sm font-medium text-gray-900 dark:text-white">Password</label>
                            <a href="forgot" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-500">Forgot password?</a>
                        </div>
                        <div class="relative">
                            <input type="password" name="password" id="password" class="op-input pr-12"
                                placeholder="Enter password"
                                value="<?php echo (!empty($_showDemo) ? '12345678' : ''); ?>">
                            <button type="button" id="toggle-password-btn" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center">
                        <input id="remember" type="checkbox" class="w-4 h-4 border border-gray-300 rounded bg-gray-50 focus:ring-3 focus:ring-primary-300 dark:bg-gray-700 dark:border-gray-600 dark:focus:ring-primary-600 dark:ring-offset-gray-800">
                        <label for="remember" class="ms-2 text-sm text-gray-500 dark:text-gray-300">Remember me on this device</label>
                    </div>

                    <button type="submit" id="login-submit" class="op-btn-primary w-full">
                        Sign in
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?php echo $site_url ?>assets/js/flowbite.min.js"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?php echo $site_url ?>assets/js/app.js?v=2.0"></script>

    <script nonce="<?= $csp_nonce ?>" data-cfasync="false">
        document.getElementById('toggle-password-btn').addEventListener('click', function() {
            const input = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            if (isPassword) {
                eyeIcon.innerHTML = '<path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /><path d="M3 3l18 18" />';
            } else {
                eyeIcon.innerHTML = '<path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />';
            }
        });

        document.getElementById('login-form').addEventListener('submit', function (e) {
            e.preventDefault();

            const form = this;
            const submitBtn = document.getElementById('login-submit');
            const originalText = submitBtn.innerHTML;

            submitBtn.innerHTML = '<div class="op-spinner" style="width:20px;height:20px;border-width:2px"></div>';
            submitBtn.disabled = true;

            const formData = new FormData(form);
            const params = new URLSearchParams(formData).toString();

            fetch('login', {
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