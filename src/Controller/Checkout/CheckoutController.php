<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;

/**
 * Checkout controller — renders checkout page, handles gateway selection.
 * Fires: checkout.before, checkout.render, checkout.gateway.selected
 */
final class CheckoutController
{
    private Container $c;
    private EventManager $events;

    public function __construct(Container $c, EventManager $events)
    {
        $this->c = $c;
        $this->events = $events;
    }

    /**
     * GET /checkout/{ref}
     */
    public function show(Request $req, string $ref): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $txn = $db->fetchOne(
            "SELECT t.*, m.business_name as merchant_name, m.id as merchant_id FROM op_transactions t JOIN op_merchants m ON m.id = t.merchant_id WHERE t.trx_id = :ref AND t.status IN ('pending','created')",
            ['ref' => $ref]
        );

        if (!$txn) {
            return $this->renderStatus($ref, 'expired');
        }

        $mid = (int) $txn['merchant_id'];
        $this->events->doAction('checkout.before', $txn);

        // Load gateways
        $manualGateways = $db->fetchAll("SELECT * FROM op_manual_gateways WHERE merchant_id = :mid AND status = 'active' ORDER BY sort_order", ['mid' => $mid]);
        $apiGateways = $db->fetchAll("SELECT g.slug, g.name, g.logo, gc.config, g.type FROM op_gateway_configs gc JOIN op_gateways g ON g.id = gc.gateway_id WHERE gc.merchant_id = :mid AND gc.status = 'active'", ['mid' => $mid]);

        // Categorize gateways
        $gateways = ['mfs' => [], 'bank' => [], 'global' => []];
        foreach ($manualGateways as $gw) {
            $cat = $gw['category'] ?? 'mfs';
            $gateways[$cat][] = array_merge($gw, ['mode' => 'manual']);
        }
        foreach ($apiGateways as $gw) {
            $cat = $gw['type'] ?? 'global';
            $gateways[$cat][] = array_merge($gw, ['mode' => 'api']);
        }

        // Load merchant settings
        $brand = $this->loadBrand($mid);
        $faqs = json_decode($db->fetchOne("SELECT setting_value FROM op_settings WHERE setting_key = 'faqs'")['setting_value'] ?? '[]', true);

        // Invoice items if applicable
        $items = [];
        if (!empty($txn['invoice_id'])) {
            $items = $db->fetchAll("SELECT * FROM op_invoice_items WHERE invoice_id = :iid", ['iid' => $txn['invoice_id']]);
        }

        $data = [
            'txn'      => $txn,
            'gateways' => $gateways,
            'brand'    => $brand,
            'items'    => $items,
            'faqs'     => $faqs,
            'config'   => $this->buildJsConfig($txn, $brand),
        ];

        $data = $this->events->applyFilters('checkout.render', $data);

        $twig = $this->c->get(\Twig\Environment::class);
        return Response::html($twig->render('checkout/checkout.twig', $data));
    }

    private function renderStatus(string $ref, string $status): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $txn = $db->fetchOne("SELECT * FROM op_transactions WHERE trx_id = :ref", ['ref' => $ref]);
        return Response::html($twig->render('checkout/checkout-status.twig', [
            'txn'    => $txn ?? ['trx_id' => $ref],
            'status' => $status ?: ($txn['status'] ?? 'expired'),
        ]));
    }

    private function loadBrand(int $mid): array
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $merchant = $db->fetchOne("SELECT * FROM op_merchants WHERE id = :mid", ['mid' => $mid]);
        $settings = $db->fetchAll("SELECT setting_key, setting_value FROM op_settings WHERE setting_key IN ('app_name','theme_primary','theme_accent','support_email')");
        $s = [];
        foreach ($settings as $r) { $s[$r['setting_key']] = $r['setting_value']; }
        return [
            'name'          => $merchant['business_name'] ?? $s['app_name'] ?? 'Own Pay',
            'logo'          => $merchant['logo'] ?? '',
            'color'         => $s['theme_primary'] ?? '#0D9488',
            'support_email' => $s['support_email'] ?? '',
        ];
    }

    private function buildJsConfig(array $txn, array $brand): array
    {
        return [
            'txnRef'         => $txn['trx_id'],
            'timeoutEnabled' => true,
            'timeoutSeconds' => 600,
            'gatewayMeta'    => [
                'bkash'  => ['color' => '#E2136E', 'type' => 'Send Money', 'logoText' => 'b'],
                'nagad'  => ['color' => '#F6921E', 'type' => 'Send Money', 'logoText' => 'N'],
                'rocket' => ['color' => '#8B2E86', 'type' => 'Send Money', 'logoText' => 'R'],
            ],
        ];
    }
}
