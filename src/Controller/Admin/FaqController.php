<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class FaqController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function save(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $faqs = $req->post('faqs', []);
        $clean = [];
        foreach ($faqs as $f) {
            if (!empty($f['question'])) {
                $clean[] = ['question' => $f['question'], 'answer' => $f['answer'] ?? ''];
            }
        }
        $json = json_encode($clean);
        $exists = $db->fetchOne("SELECT id FROM op_settings WHERE setting_key = 'faqs'");
        if ($exists) { $db->update("UPDATE op_settings SET setting_value = :v WHERE setting_key = 'faqs'", ['v' => $json]); }
        else { $db->insert("INSERT INTO op_settings (setting_key, setting_value) VALUES ('faqs', :v)", ['v' => $json]); }

        $_SESSION['flash_success'] = 'FAQs saved';
        return Response::redirect('/admin/settings#tab-faq');
    }
}
