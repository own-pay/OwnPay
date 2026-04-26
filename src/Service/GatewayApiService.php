<?php
declare(strict_types=1);

namespace OwnPay\Service;

/**
 * Gateway API Service
 *
 * Checkout-facing SDK functions used by gateway modules.
 * Provides URLs, transaction checks, asset loading, and UI helpers.
 */
class GatewayApiService
{
    /**
     * Return the database table prefix from the environment.
     */
    private static function dbPrefix(): string
    {
        return $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
    }

    /**
     * Return the site URL (protocol + host + optional directory).
     *
     * Replicates the logic from app/core/adapter.php without globals.
     */
    private static function siteUrl(): string
    {
        $fullDomain = op_site_url('fulldomain');
        $directory = ($fullDomain === 'http://localhost') ? 'OwnPay-panel/' : '';
        return $fullDomain . '/' . $directory;
    }

    /**
     * Return the payment path segment from application settings.
     */
    private static function pathPayment(): string
    {
        $value = EnvironmentService::get('geneal-application-settings-paymentPath');
        if ($value !== null && $value !== '') {
            return $value;
        }
        return 'payment';
    }

    public static function op_set_lang($lang)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['ui_language'] = preg_replace('/[^a-z]/', '', $lang);
        }
    }

    public static function op_site_address()
    {
        return self::siteUrl();
    }

    public static function op_callback_url()
    {
        $url = op_site_url();

        $separator = (parse_url($url, PHP_URL_QUERY) ? '&' : '?');
        $url .= $separator . 'op_callback';

        return $url;
    }

    public static function op_ipn_url($gatewayid)
    {
        return self::siteUrl() . 'ipn/' . $gatewayid;
    }

    public static function op_check_transaction($ppid = '')
    {
        $db_prefix = self::dbPrefix();

        $params = [':ref' => $ppid];

        $response_transaciton = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref', '* FROM', $params);

        if ($response_transaciton['status'] === true) {
            return true;
        } else {
            return false;
        }
    }

    public static function op_check_transaction_id($trxid = '')
    {
        $db_prefix = self::dbPrefix();

        $params = [':trx_id' => $trxid];

        $response_transaciton = CrudService::select($db_prefix . 'transaction', 'WHERE trx_id = :trx_id', '* FROM', $params);

        if ($response_transaciton['status'] === true) {
            return true;
        } else {
            return false;
        }
    }

    public static function op_checkout_address($paymentid = '')
    {
        $path_payment = self::pathPayment();
        $resolvedId = $paymentid !== '' ? $paymentid : ($GLOBALS['paymentID124123412'] ?? '');

        return self::siteUrl() . $path_payment . '/' . $resolvedId;
    }

    public static function op_hexToRgba($hex, $opacity = 1)
{
    $hex = str_replace('#', '', $hex);
    if (strlen($hex) == 3) {
        $r = hexdec($hex[0] . $hex[0]);
        $g = hexdec($hex[1] . $hex[1]);
        $b = hexdec($hex[2] . $hex[2]);
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    return "rgba($r,$g,$b,$opacity)";
}

    public static function op_assets($position = '')
    {
        $site_url = self::siteUrl();

        if ($position == "head") {
            echo '
                <script src="https://cdn.tailwindcss.com"></script>
                <link rel="stylesheet" href="' . $site_url . 'assets/css/choices.min.css">

                <style>
                    @import url("https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap");
                    *,::after,::before{box-sizing:border-box}
                    .card{background:#fff;border:1px solid rgba(98,105,118,.16);border-radius:.75rem;box-shadow:0 1px 2px rgba(0,0,0,.05)}
                    .card-body{padding:1.5rem}
                    .row{display:flex;flex-wrap:wrap;margin-right:-.75rem;margin-left:-.75rem}
                    .row>*{flex-shrink:0;width:100%;max-width:100%;padding-right:.75rem;padding-left:.75rem}
                    .g-3{gap:1rem}
                    @media(min-width:768px){.col-md-4{flex:0 0 auto;width:33.333%}.col-md-8{flex:0 0 auto;width:66.666%}.col-md-12{flex:0 0 auto;width:100%}}
                    @media(min-width:992px){.col-lg-6{flex:0 0 auto;width:50%}}
                    .btn{display:inline-flex;align-items:center;justify-content:center;padding:.5rem 1rem;font-size:.875rem;font-weight:500;border-radius:.5rem;border:1px solid transparent;cursor:pointer;transition:all .15s}
                    .btn-primary{background:#4f46e5;color:#fff;border-color:#4f46e5}.btn-primary:hover{background:#4338ca}
                    .d-none{display:none!important}.d-flex{display:flex!important}
                    .text-center{text-align:center}
                    .flex-md-row-reverse{flex-direction:row-reverse}
                    .justify-content-center{justify-content:center}.justify-content-md-start{justify-content:flex-start}
                    .align-items-center{align-items:center}
                    .gap-3{gap:1rem}
                    .mt-1{margin-top:.25rem}.mb-3{margin-bottom:1rem}
                    .form-control,.form-control-wrap input,.form-control-wrap select{display:block;width:100%;padding:.5rem .75rem;font-size:.875rem;line-height:1.5;color:#1e293b;background:#fff;border:1px solid #cbd5e1;border-radius:.5rem;transition:border-color .15s}
                    .form-control:focus,.form-control-wrap input:focus{border-color:#4f46e5;outline:0;box-shadow:0 0 0 3px rgba(79,70,229,.1)}
                    @media print{.no-print{display:none!important}}
                </style>
            ';
        } else {
            echo '
                <script src="' . $site_url . 'assets/js/custom-toast.js?v=1.2"></script>
                <script src="' . $site_url . 'assets/js/op-fetch.js?v=1.0"></script>
                <script src="' . $site_url . 'assets/js/choices.min.js"></script>
                <script src="https://cdn.jsdelivr.net/npm/hugerte@1/hugerte.min.js"></script>
            ';
        }
    }
}
