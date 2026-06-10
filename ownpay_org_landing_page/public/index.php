<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page Front Controller
 * File: public/index.php
 */

// Load configuration
require_once dirname(__DIR__) . '/config/config.php';

// Require core engine files
require_once ROOT_PATH . '/app/Database.php';
require_once ROOT_PATH . '/app/Router.php';

// Require controllers
require_once ROOT_PATH . '/app/Controller/Controller.php';
require_once ROOT_PATH . '/app/Controller/HomeController.php';
require_once ROOT_PATH . '/app/Controller/WaitlistController.php';
require_once ROOT_PATH . '/app/Controller/DonateController.php';
require_once ROOT_PATH . '/app/Controller/SponsorsController.php';
require_once ROOT_PATH . '/app/Controller/AdminController.php';

// Initialize Router
$router = new Router();

// Public Routes
$router->get('/', 'HomeController@index');
$router->get('/privacy-policy', 'HomeController@privacy');
$router->get('/architecture', 'HomeController@architecture');
$router->get('/security', 'HomeController@security');
$router->get('/robots.txt', 'HomeController@robots');
$router->get('/sitemap.xml', 'HomeController@sitemap');

// Donation & Showcase Routes
$router->get('/donate', 'DonateController@index');
$router->post('/donate/initiate', 'DonateController@initiate');
$router->get('/donate/callback', 'DonateController@callback');
$router->post('/donate/callback', 'DonateController@callback');
$router->post('/donate/message', 'DonateController@submitMessage');
$router->get('/donors', 'DonateController@donors');
$router->get('/sponsors', 'SponsorsController@index');

// API Routes
$router->post('/waitlist', 'WaitlistController@subscribe');
$router->post('/api/sync-stars', 'HomeController@syncStars');
$router->get('/api/sponsor', 'HomeController@sponsorInfo');

// Admin Panel Routes
$router->get('/admin/login', 'AdminController@login');
$router->post('/admin/login', 'AdminController@login');
$router->get('/admin/logout', 'AdminController@logout');
$router->get('/admin/dashboard', 'AdminController@dashboard');
$router->get('/admin/subscribers', 'AdminController@subscribers');
$router->post('/admin/subscribers', 'AdminController@subscribers');
$router->get('/admin/donations', 'AdminController@donations');
$router->post('/admin/donations', 'AdminController@donations');
$router->get('/admin/sponsors', 'AdminController@sponsors');
$router->post('/admin/sponsors', 'AdminController@sponsors');
$router->get('/admin/contributors', 'AdminController@contributors');
$router->post('/admin/contributors', 'AdminController@contributors');
$router->get('/admin/settings', 'AdminController@settings');
$router->post('/admin/settings', 'AdminController@settings');
$router->get('/admin/audit-log', 'AdminController@auditLog');
$router->post('/admin/sitemap', 'AdminController@sitemap');

// Dispatch Request
$router->dispatch();
