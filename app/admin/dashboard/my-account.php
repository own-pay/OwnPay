<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">My Account</div>
        <h2 class="op-page-title">My Account</h2>
    </div>
</div>

<!-- Profile Information -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
    <div class="xl:col-span-1">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Profile Information</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">Update your account profile information and email address.</p>
    </div>
    <div class="xl:col-span-2">
        <form action="" class="form-my-account-profile-information">
            <input type="hidden" name="action" value="my-account-profile-information">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

            <div class="op-card">
                <div class="op-card-body space-y-4">
                    <div>
                        <label class="op-label">Full Name <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="fullname" placeholder="Full Name"
                            value="<?php echo $global_user_response['response'][0]['full_name']; ?>" required>
                    </div>
                    <div>
                        <label class="op-label">Username <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="username" placeholder="Username"
                            value="<?php echo $global_user_response['response'][0]['username']; ?>" required>
                    </div>
                    <div>
                        <label class="op-label">Email Address <span class="text-red-500">*</span></label>
                        <input type="email" class="op-input" name="email-address" placeholder="Email Address"
                            value="<?php echo $global_user_response['response'][0]['email']; ?>" required>
                    </div>
                    <div>
                        <label class="op-label">New Password</label>
                        <input type="password" class="op-input" name="password" placeholder="Password" minlength="6">
                    </div>
                </div>
            </div>
            <div class="flex justify-end mt-4">
                <button class="op-btn-primary btn-my-account-profile-information">Save changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Browser Sessions -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
    <div class="xl:col-span-1">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Browser Sessions</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">Manage and log out your active sessions on other browsers and devices.</p>
    </div>
    <div class="xl:col-span-2">
        <div class="op-card">
            <div class="op-card-body">
                <p class="text-sm text-gray-500 dark:text-gray-400">If necessary, you may log out of all of your other browser sessions across all of your devices. If you feel your account has been compromised, you should also update your password.</p>
            </div>
        </div>
        <div class="flex justify-end mt-4">
            <form action="" class="form-my-account-browser-sessions">
                <input type="hidden" name="action" value="my-account-account-browser-sessions">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <button class="op-btn-primary btn-my-account-browser-sessions">Log Out Other Browser Sessions</button>
            </form>
        </div>
    </div>
</div>

<!-- Two-factor Authentication -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
    <div class="xl:col-span-1">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Two-factor authentication (2FA)</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">Add additional security to your account using two factor authentication.</p>
    </div>
    <div class="xl:col-span-2">
        <div class="op-card">
            <div class="op-card-body">
                <div class="flex items-center justify-between">
                    <label class="op-label mb-0">Authenticator app</label>
                    <?php if ($global_user_response['response'][0]['2fa_status'] == "enable") { ?>
                        <span class="op-badge-primary">Enabled</span>
                    <?php } else { ?>
                        <span class="op-badge-danger">Disabled</span>
                    <?php } ?>
                </div>
                <?php if ($global_user_response['response'][0]['2fa_status'] == "enable") { ?>
                    <button class="op-btn-danger mt-3" data-modal-target="modal-2fa" data-modal-toggle="modal-2fa">Disable</button>
                <?php } else { ?>
                    <button class="op-btn-primary mt-3" data-modal-target="modal-2fa" data-modal-toggle="modal-2fa">Enable</button>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<!-- 2FA Modal -->
<?php if ($global_user_response['response'][0]['2fa_status'] == "enable") { ?>
<div id="modal-2fa" tabindex="-1" class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full flex items-center justify-center"
     data-modal-backdrop="static">
    <div class="relative w-full max-w-md max-h-full">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <form action="" class="form-my-account-two-factor-authentication">
                <input type="hidden" name="action" value="my-account-account-two-factor-authentication">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Two-factor authentication</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" data-modal-hide="modal-2fa">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                    </button>
                </div>
                <div class="p-4 space-y-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">To disable 2FA, please enter the authentication code from your Two-Factor Authentication app (e.g., Google Authenticator) and then confirm.</p>
                    <div>
                        <label class="op-label">Enter the 6-digit code <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="auth-code" placeholder="Enter code" required>
                    </div>
                </div>
                <div class="flex items-center justify-between p-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="button" class="op-btn-secondary" data-modal-hide="modal-2fa">Close</button>
                    <button class="op-btn-primary btn-my-account-two-factor-authentication">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url . $path_admin ?>/dashboard';

    document.querySelector('.form-my-account-two-factor-authentication').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = document.querySelector(".btn-my-account-two-factor-authentication");
        var btnHTML = btn.innerHTML;
        btn.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        var formData = new URLSearchParams(new FormData(this)).toString();

        fetch('<?php echo $site_url . $path_admin ?>/dashboard', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(r => r.json())
        .then(response => {
            apRotateCsrf(response.csrf_token);
            document.querySelector(".form-my-account-two-factor-authentication").reset();
            closeAllModals();
            btn.innerHTML = btnHTML;

            if (response.status === 'true') {
                apToast('success', response.title, response.message);
                location.href = '<?php echo $site_url . $path_admin ?>/my-account';
            } else {
                apToast('error', response.title, response.message);
            }
        })
        .catch(err => apToastError());
    });
</script>

<?php } else { ?>
<div id="modal-2fa" tabindex="-1" class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full flex items-center justify-center"
     data-modal-backdrop="static">
    <div class="relative w-full max-w-md max-h-full">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <form action="" class="form-my-account-two-factor-authentication">
                <input type="hidden" name="action" value="my-account-account-two-factor-authentication">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Two-factor authentication</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" data-modal-hide="modal-2fa">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                    </button>
                </div>
                <div class="p-4 space-y-4">
                    <div class="p-4 bg-blue-50 dark:bg-blue-900/30 rounded-lg text-sm text-blue-700 dark:text-blue-300">
                        You'll need an app like <strong>Google Authenticator</strong> (
                        <a href="https://itunes.apple.com/us/app/google-authenticator/id388497605" target="_blank" class="underline font-medium">iOS</a>,
                        <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank" class="underline font-medium">Android</a>
                        ) to complete this process.
                    </div>

                    <p class="text-sm text-gray-500 dark:text-gray-400">Scan this QR code with your authenticator app:</p>

                    <?php
                    $userEncoded = urlencode($global_user_response['response'][0]['email']);
                    $issuerEncoded = urlencode("OwnPay");
                    $secretEncoded = urlencode($global_user_response['response'][0]['2fa_secret']);

                    $qrData = "otpauth://totp/{$issuerEncoded}:{$userEncoded}?secret={$secretEncoded}&issuer={$issuerEncoded}";
                    $qrOptions = new \chillerlan\QRCode\QROptions([
                        'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                        'scale' => 10,
                    ]);
                    $qrCodeUrl = (new \chillerlan\QRCode\QRCode($qrOptions))->render($qrData);
                    ?>

                    <div class="flex justify-center">
                        <img src="<?php echo $qrCodeUrl; ?>" class="max-w-[180px] border border-gray-200 dark:border-gray-600 p-3 rounded-lg" alt="2FA QR Code">
                    </div>

                    <p class="text-sm text-gray-500 dark:text-gray-400">Or enter this code manually:
                        <strong class="font-mono text-gray-900 dark:text-white"><?php echo $global_user_response['response'][0]['2fa_secret'] ?></strong>
                    </p>

                    <div>
                        <label class="op-label">Enter the 6-digit code <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="auth-code" placeholder="Enter code" required>
                    </div>
                </div>
                <div class="flex items-center justify-between p-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="button" class="op-btn-secondary" data-modal-hide="modal-2fa">Close</button>
                    <button class="op-btn-primary btn-my-account-two-factor-authentication">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    document.querySelector('.form-my-account-two-factor-authentication').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = document.querySelector(".btn-my-account-two-factor-authentication");
        var btnHTML = btn.innerHTML;
        btn.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        var formData = new URLSearchParams(new FormData(this)).toString();

        fetch('<?php echo $site_url . $path_admin ?>/dashboard', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(r => r.json())
        .then(response => {
            apRotateCsrf(response.csrf_token);
            document.querySelector(".form-my-account-two-factor-authentication").reset();
            closeAllModals();
            btn.innerHTML = btnHTML;

            if (response.status === 'true') {
                apToast('success', response.title, response.message);
                location.href = '<?php echo $site_url . $path_admin ?>/my-account';
            } else {
                apToast('error', response.title, response.message);
            }
        })
        .catch(err => apToastError());
    });
</script>
<?php } ?>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    document.querySelector('.form-my-account-browser-sessions').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var my_two_step_verify_code = document.querySelector("#my-two-step-verify-code")?.value || '';
        var btnClass = 'btn-my-account-browser-sessions';

        if (my_two_step_verify_code !== "") {
            closeAllModals();
            formData.append('my-two-step-verify-code', my_two_step_verify_code);
            var btn = document.querySelector('.' + btnClass);
            var btnHTML = btn.innerHTML;
            btn.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

            fetch('<?php echo $site_url . $path_admin ?>/dashboard', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(response => {
                    apRotateCsrf(response.csrf_token);
                    var el = document.querySelector("#my-two-step-verify-code");
                    if (el) el.value = '';
                    btn.innerHTML = btnHTML;

                    if (response.status === 'true') {
                        apToast('success', response.title, response.message);
                    } else {
                        apToast('error', response.title, response.message);
                    }
                })
                .catch(err => apToastError());
        } else {
            show_two_step_verify_tab(btnClass);
        }
    });

    document.querySelector('.form-my-account-profile-information').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var my_two_step_verify_code = document.querySelector("#my-two-step-verify-code")?.value || '';
        var btnClass = 'btn-my-account-profile-information';

        if (my_two_step_verify_code !== "") {
            closeAllModals();
            formData.append('my-two-step-verify-code', my_two_step_verify_code);
            var btn = document.querySelector('.' + btnClass);
            var btnHTML = btn.innerHTML;
            btn.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

            fetch('<?php echo $site_url . $path_admin ?>/dashboard', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(response => {
                    apRotateCsrf(response.csrf_token);
                    var el = document.querySelector("#my-two-step-verify-code");
                    if (el) el.value = '';
                    btn.innerHTML = btnHTML;

                    if (response.status === 'true') {
                        apToast('success', response.title, response.message);
                    } else {
                        apToast('error', response.title, response.message);
                    }
                })
                .catch(err => apToastError());
        } else {
            show_two_step_verify_tab(btnClass);
        }
    });
</script>
