<?php
declare(strict_types=1);

namespace OwnPay\Controller\Page;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;

final class LandingController
{
    private Container $c;
    private EventManager $events;

    public function __construct(Container $c, EventManager $events) { $this->c = $c; $this->events = $events; }

    public function index(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $settings = [];
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM op_settings WHERE setting_key IN ('app_name','landing_title','landing_subtitle','landing_description','faqs')");
        foreach ($rows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }

        $faqs = json_decode($settings['faqs'] ?? '[]', true);
        $features = [
            ['title' => 'Multi-Gateway', 'description' => 'Accept payments through multiple gateways — API and manual.'],
            ['title' => 'SMS Verification', 'description' => 'Auto-verify mobile payments using companion app SMS parsing.'],
            ['title' => 'Self-Hosted', 'description' => 'Complete control. Your server, your data, your rules.'],
            ['title' => 'Plugin System', 'description' => 'Extend with custom gateways, themes, and integrations.'],
        ];
        $features = $this->events->applyFilters('landing.features', $features);

        $twig = $this->c->get(\Twig\Environment::class);
        return Response::html($twig->render('page/landing.twig', [
            'app_name'            => $settings['app_name'] ?? 'Own Pay',
            'landing_title'       => $settings['landing_title'] ?? null,
            'landing_subtitle'    => $settings['landing_subtitle'] ?? null,
            'landing_description' => $settings['landing_description'] ?? null,
            'features'            => $features,
            'faqs'                => $faqs,
            'faq_enabled'         => count($faqs) > 0,
        ]));
    }
}
