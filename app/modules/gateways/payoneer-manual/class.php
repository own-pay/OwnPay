<?php

declare(strict_types=1);

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;

class PayoneerManualGateway implements GatewayAdapterInterface
{
    use GatewayDefaults;
        public function info(): array
    {
            return [
                'title'       => 'Payoneer Manual',
                'logo'        => 'assets/logo.jpg',
                'currency'        => 'USD',
                'tab'        => 'global',

                'gateway_type'        => 'manual',
                'verify_by'        => 'trxid',
            ];
        }

        public function color(): array
    {
            return [
                'primary_color'        => '#252526',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#252526',
                'btn_text_color'        => '#FFFFFF',
            ];
        }

        public function fields(): array
    {
            return [
                [
                    'name'  => 'payoneer_email',
                    'label' => 'Enter payoneer email address',
                    'type'  => 'text',
                ],
            ];
        }

        public function supported_languages(): array
    {
            return [
                'en' => 'English',
                'bn' => 'বাংলা',
                'hi' => 'हिन्दी',
                'ur' => 'اردو',
                'ar' => 'العربية',
            ];
        }

        public function lang_text(): array
    {
            return [
                '1' => [
                    'en' => 'Go to your Payoneer Mobile App or Website',
                    'bn' => 'আপনার Payoneer মোবাইল অ্যাপ বা ওয়েবসাইটে যান',
                    'hi' => 'अपने Payoneer मोबाइल ऐप या वेबसाइट पर जाएं',
                    'ur' => 'اپنی Payoneer موبائل ایپ یا ویب سائٹ پر جائیں',
                    'ar' => 'انتقل إلى تطبيق Payoneer على هاتفك أو إلى الموقع الإلكتروني',
                ],

                '2' => [
                    'en' => 'Click "Pay"',
                    'bn' => '"Pay" অপশনে ক্লিক করুন',
                    'hi' => '"Pay" विकल्प पर क्लिक करें',
                    'ur' => '"Pay" پر کلک کریں',
                    'ar' => 'اضغط على خيار "Pay"',
                ],

                '3' => [
                    'en' => 'Choose "Pay to a Payoneer recipient"',
                    'bn' => '"Payoneer প্রাপকের কাছে পেমেন্ট" অপশনটি নির্বাচন করুন',
                    'hi' => '"Payoneer प्राप्तकर्ता को भुगतान" विकल्प चुनें',
                    'ur' => '"Payoneer وصول کنندہ کو ادائیگی" کا انتخاب کریں',
                    'ar' => 'اختر خيار "الدفع إلى مستلم Payoneer"',
                ],

                '4' => [
                    'en' => 'Enter the email address "{payoneer_email}"',
                    'bn' => 'ইমেইল ঠিকানা লিখুন "{payoneer_email}"',
                    'hi' => 'ईमेल पता दर्ज करें "{payoneer_email}"',
                    'ur' => 'ای میل ایڈریس درج کریں "{payoneer_email}"',
                    'ar' => 'أدخل عنوان البريد الإلكتروني "{payoneer_email}"',
                ],

                '5' => [
                    'en' => 'Enter amount: {amount} {currency}',
                    'bn' => 'পরিমাণ লিখুন: {amount} {currency}',
                    'hi' => 'राशि दर्ज करें: {amount} {currency}',
                    'ur' => 'رقم درج کریں: {amount} {currency}',
                    'ar' => 'أدخل المبلغ: {amount} {currency}',
                ],

                '6' => [
                    'en' => 'Check all details carefully and confirm the transfer',
                    'bn' => 'সব তথ্য ভালোভাবে যাচাই করে ট্রান্সফার নিশ্চিত করুন',
                    'hi' => 'सभी विवरण ध्यान से जांचें और ट्रांसफर की पुष्टि करें',
                    'ur' => 'تمام تفصیلات غور سے چیک کریں اور ٹرانسفر کی تصدیق کریں',
                    'ar' => 'تحقق من جميع التفاصيل بعناية ثم أكد التحويل',
                ],

                '7' => [
                    'en' => 'Enter the transaction ID in the box below and click "Submit"',
                    'bn' => 'নিচের বক্সে ট্রানজ্যাকশন আইডি লিখুন এবং "Submit" চাপুন',
                    'hi' => 'नीचे दिए गए बॉक्स में ट्रांज़ैक्शन ID दर्ज करें और "Submit" पर क्लिक करें',
                    'ur' => 'نیچے دیے گئے باکس میں ٹرانزیکشن ID درج کریں اور "Submit" پر کلک کریں',
                    'ar' => 'أدخل معرف المعاملة في الحقل أدناه ثم اضغط على "Submit"',
                ],

            ];
        }

        public function instructions($data): array
    {
            return [
                [
                    'icon' => '',
                    'text' => '1',
                    'copy' => false,
                ],
                [
                    'icon' => '',
                    'text' => '2',
                    'copy' => false,
                ],
                [
                    'icon' => '',
                    'text' => '3',
                    'copy' => false,
                ],
                [
                    'icon' => '',
                    'text' => '4',
                    'copy' => true,
                    'value' => $data['options']['payoneer_email'] ?? '',
                    'vars' => [
                        '{payoneer_email}' => $data['options']['payoneer_email'] ?? ''
                    ]
                ],
                [
                    'icon' => '',
                    'text' => '5',
                    'copy' => true,
                    'value' => $data['transaction']['local_net_amount'],
                    'vars' => [
                        '{amount}' => $data['transaction']['local_net_amount'],
                        '{currency}' => $data['transaction']['local_currency']
                    ]
                ],
                [
                    'icon' => '',
                    'text' => '6',
                    'copy' => false
                ],
                [
                    'icon' => '',
                    'text' => '7',
                    'copy' => false
                ],
            ];
        }
    }
