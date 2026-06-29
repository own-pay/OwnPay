<?php
declare(strict_types=1);

namespace OwnPay\Controller\Page;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Repository\SettingsRepository;

/**
 * Class LandingController
 *
 * Handles HTTP requests to render the public landing/marketing page.
 *
 * @package OwnPay\Controller\Page
 */
final class LandingController
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var EventManager The event manager.
     */
    private EventManager $events;

    /**
     * @var SettingsRepository The settings repository.
     */
    private SettingsRepository $settingsRepo;

    /**
     * LandingController constructor.
     *
     * @param Container          $c            The DI container.
     * @param EventManager       $events       The event manager.
     * @param SettingsRepository $settingsRepo The settings repository.
     */
    public function __construct(Container $c, EventManager $events, SettingsRepository $settingsRepo)
    {
        $this->c            = $c;
        $this->events       = $events;
        $this->settingsRepo = $settingsRepo;
    }

    /**
     * Renders the public-facing landing page.
     * Redirects to the login route if the landing page is disabled.
     *
     * GET /
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with rendered HTML landing page.
     */
    public function index(Request $req): Response
    {
        $general  = $this->settingsRepo->getGroup('general');
        $landing  = $this->settingsRepo->getGroup('landing');
        $branding = $this->settingsRepo->getGroup('branding');

        // Redirect to login if landing page disabled
        if (($landing['landing_enabled'] ?? '1') === '0') {
            $loginSlug = $landing['admin_login_slug'] ?? 'login';
            return Response::redirect('/' . ltrim($loginSlug, '/'));
        }

        // FAQs from general settings
        $faqs = [];
        if (!empty($general['faqs'])) {
            $decoded = json_decode($general['faqs'], true);
            $faqs = is_array($decoded) ? $decoded : [];
        }

        // Features: DB override or defaults
        $defaultFeatures = [
            ['title' => 'Multi-Gateway',      'description' => 'Accept payments through multiple gateways - API and manual.'],
            ['title' => 'SMS Verification',   'description' => 'Auto-verify mobile payments using companion app SMS parsing.'],
            ['title' => 'Self-Hosted',        'description' => 'Complete control. Your server, your data, your rules.'],
            ['title' => 'Plugin System',      'description' => 'Extend with custom gateways, themes, and integrations.'],
        ];
        $features = $defaultFeatures;
        if (!empty($landing['features'])) {
            $dbFeatures = json_decode($landing['features'], true);
            if (is_array($dbFeatures) && count($dbFeatures) > 0) {
                $features = $dbFeatures;
            }
        }
        $filteredFeatures = $this->events->applyFilter('landing.features', $features);
        $features = is_array($filteredFeatures) ? $filteredFeatures : $features;
 
        $showFaq      = ($landing['landing_show_faq']      ?? '1') === '1' && count($faqs) > 0;
        $showFeatures = ($landing['landing_show_features'] ?? '1') === '1' && count($features) > 0;
 
        $twig = $this->c->get(\Twig\Environment::class);
        if (!$twig instanceof \Twig\Environment) {
            throw new \RuntimeException('Twig Environment not found.');
        }

        $appName = isset($general['app_name']) ? $general['app_name'] : 'OwnPay';
        $landingTitle = isset($landing['landing_title']) ? $landing['landing_title'] : null;
        $landingSubtitle = isset($landing['landing_subtitle']) ? $landing['landing_subtitle'] : null;
        $landingDescription = isset($branding['site_meta_description']) ? $branding['site_meta_description'] : null;
        $landingCtaText = isset($landing['landing_cta_text']) ? $landing['landing_cta_text'] : 'Get Started';
        $landingCtaUrl = isset($landing['landing_cta_url']) ? $landing['landing_cta_url'] : 'https://ownpay.org';
        $siteFavicon = isset($branding['site_favicon']) ? $branding['site_favicon'] : '';
        $siteLogo = isset($branding['site_logo']) ? $branding['site_logo'] : '';
        $siteSeoTitle = isset($branding['site_seo_title']) ? $branding['site_seo_title'] : null;

        return Response::html($twig->render('page/landing.twig', [
            'app_name'            => $appName,
            'landing_title'       => $landingTitle,
            'landing_subtitle'    => $landingSubtitle,
            'landing_description' => $landingDescription,
            'landing_cta_text'    => $landingCtaText,
            'landing_cta_url'     => $landingCtaUrl,
            'site_favicon'        => $siteFavicon,
            'site_logo'           => $siteLogo,
            'site_seo_title'      => $siteSeoTitle,
            'features'            => $showFeatures ? $features : [],
            'faqs'                => $showFaq      ? $faqs    : [],
            'faq_enabled'         => $showFaq,
        ]));
    }
}
