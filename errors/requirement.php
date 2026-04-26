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
    <title>System Requirements - OwnPay</title>
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

        <!-- Requirements Card -->
        <div class="w-full max-w-lg bg-white rounded-xl shadow-lg dark:bg-gray-800 dark:border dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h1 class="text-xl font-bold text-gray-900 dark:text-white mb-1">
                    System Requirements Check
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Please wait while we check your server requirements.
                </p>
            </div>

            <div class="p-6 space-y-3">
                <?php
                $satisfied_btn = true;

                foreach ($requirements as $req) {
                    if (!$req['check']) {
                        $satisfied_btn = false;
                    }

                    $passed = $req['check'];
                    $statusColor = $passed ? 'text-green-500' : 'text-red-500';
                    $bgColor = $passed ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800';
                    $statusText = $passed ? 'Passed' : 'Failed';
                ?>
                    <div class="flex justify-between items-center border rounded-lg p-3 <?= $bgColor ?>">
                        <div>
                            <span class="font-semibold text-gray-900 dark:text-white text-sm"><?= $req['name'] ?></span>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                Required: <?= $req['required'] ?> &middot; Current: <?= $req['current'] ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($passed): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5l10 -10" /></svg>
                            <?php else: ?>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                            <?php endif; ?>
                            <span class="<?= $statusColor ?> text-sm font-semibold"><?= $statusText ?></span>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</body>

</html>