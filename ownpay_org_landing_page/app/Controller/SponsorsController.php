<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page SponsorsController
 * File: app/Controller/SponsorsController.php
 */

require_once ROOT_PATH . '/app/Database.php';
require_once ROOT_PATH . '/app/Controller/Controller.php';

class SponsorsController extends Controller
{
    /**
     * Render the full sponsors page.
     */
    public function index(): void
    {
        $db = Database::getConnection();

        // Fetch all active sponsors
        $stmt = $db->prepare("SELECT * FROM `op_org_sponsors` WHERE `active` = 1 ORDER BY `display_order` ASC");
        $stmt->execute();
        $sponsors = $stmt->fetchAll();

        // Fetch settings
        $stmt = $db->query("SELECT `setting_key`, `setting_value` FROM `op_org_settings`");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $this->render('sponsors', [
            'sponsors' => $sponsors,
            'settings' => $settings,
            'title' => 'OwnPay Sponsors Showcase',
            'description' => 'Valued organizations and partners backing the open-source payment ecosystem.'
        ]);
    }
}
