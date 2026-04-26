<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="author" content="OwnPay">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Maintenance - OwnPay</title>
    <link rel="shortcut icon" href="<?= $OwnPay_favicon ?? '' ?>">
    <link rel="stylesheet" href="<?php echo $site_url ?>assets/css/admin.css">
</head>

<body class="bg-gray-50 dark:bg-gray-900 antialiased">
    <div class="flex flex-col items-center justify-center min-h-screen px-6 py-12">
        <!-- Logo -->
        <a href="javascript:void(0)" class="flex items-center mb-8">
            <img src="<?= $OwnPay_logo_light ?? '' ?>" class="h-10 block dark:hidden" alt="OwnPay">
            <img src="<?= $OwnPay_logo_dark ?? '' ?>" class="h-10 hidden dark:block" alt="OwnPay">
        </a>

        <!-- Maintenance Card -->
        <div class="w-full max-w-lg bg-white rounded-xl shadow-lg dark:bg-gray-800 dark:border dark:border-gray-700 p-8 text-center">
            <!-- Icon -->
            <div class="flex items-center justify-center w-16 h-16 mx-auto mb-6 rounded-full bg-yellow-100 dark:bg-yellow-900/30">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-yellow-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77 -3.77a6 6 0 0 1 -7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1 -3 -3l6.91 -6.91a6 6 0 0 1 7.94 -7.94l-3.76 3.76z" />
                </svg>
            </div>

            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-3">
                Under Maintenance
            </h1>
            <p class="text-gray-500 dark:text-gray-400 mb-8 leading-relaxed">
                Sorry for the inconvenience, but we're performing some maintenance at the moment.
                We'll be back online shortly!
            </p>

            <a href="<?php echo $site_url ?>" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700 focus:ring-4 focus:ring-primary-300 dark:bg-primary-600 dark:hover:bg-primary-700 dark:focus:ring-primary-800 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12l14 0" /><path d="M5 12l6 6" /><path d="M5 12l6 -6" />
                </svg>
                Take me home
            </a>
        </div>
    </div>
</body>

</html>