<?php
if (!defined('OWNPAY_INIT')) {
  http_response_code(403);
  exit('Direct access not allowed');
}
?>

<!DOCTYPE html>
<html lang="en" class="dark">

<head>
  <meta charset="utf-8">
  <meta name="author" content="OwnPay">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>404 - OwnPay</title>
  <link rel="shortcut icon" href="<?= $OwnPay_favicon ?? '' ?>">
  <link rel="stylesheet" href="<?= ($site_url ?? '/') ?>assets/css/admin.css">
  <script>
    // Apply saved theme immediately to prevent flash
    if (localStorage.getItem('op-theme') === 'dark' || (!localStorage.getItem('op-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  </script>
</head>

<body class="bg-gray-50 dark:bg-gray-900 antialiased">
  <div class="flex items-center justify-center min-h-screen px-6 py-12">
    <div class="text-center max-w-lg">
      <!-- 404 Number -->
      <h1 class="text-9xl font-extrabold tracking-widest text-primary-600 dark:text-primary-500">404</h1>

      <!-- Decorative line -->
      <div class="w-24 h-1 mx-auto mt-4 mb-6 rounded-full bg-gradient-to-r from-primary-400 to-primary-600"></div>

      <!-- Title -->
      <h2 class="text-2xl font-bold text-gray-900 dark:text-white md:text-3xl">
        Page not found
      </h2>

      <!-- Description -->
      <p class="mt-4 text-gray-500 dark:text-gray-400 text-base leading-relaxed">
        Sorry, the page you're looking for doesn't exist or has been moved. Let's get you back on track.
      </p>

      <!-- Action -->
      <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
        <a href="<?= ($site_url ?? '/') ?>login"
           class="inline-flex items-center gap-2 px-6 py-3 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700 focus:ring-4 focus:ring-primary-300 dark:bg-primary-600 dark:hover:bg-primary-700 dark:focus:ring-primary-800 transition-all">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12l14 0"></path>
            <path d="M5 12l6 6"></path>
            <path d="M5 12l6 -6"></path>
          </svg>
          Go to Dashboard
        </a>
      </div>

      <!-- Logo -->
      <div class="mt-12 opacity-40">
        <img src="<?= $OwnPay_logo_light ?? '' ?>" class="h-6 mx-auto dark:hidden" alt="OwnPay">
        <img src="<?= $OwnPay_logo_dark ?? $OwnPay_logo_light ?? '' ?>" class="h-6 mx-auto hidden dark:block" alt="OwnPay">
      </div>
    </div>
  </div>
</body>

</html>