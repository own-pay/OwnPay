<?php

declare(strict_types=1);

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;

class PaypalManualGateway implements GatewayAdapterInterface
{
    use GatewayDefaults;
        public function info(): array
    {
            return [
                'title'       => 'PayPal Manual',
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
                'primary_color'        => '#253b80',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#253b80',
                'btn_text_color'        => '#FFFFFF',
            ];
        }

        public function fields(): array
    {
            return [
                [
                    'name'  => 'paypal_email',
                    'label' => 'Enter paypal email address',
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
                    'en' => 'Go to your PayPal Mobile App or Website',
                    'bn' => 'আপনার PayPal মোবাইল অ্যাপ বা ওয়েবসাইটে যান',
                    'hi' => 'अपने PayPal मोबाइल ऐप या वेबसाइट पर जाएं',
                    'ur' => 'اپنی PayPal موبائل ایپ یا ویب سائٹ پر جائیں',
                    'ar' => 'انتقل إلى تطبيق PayPal على هاتفك أو إلى الموقع الإلكتروني',
                ],

                '2' => [
                    'en' => 'Choose "Send Payment"',
                    'bn' => '"Send Payment" অপশনটি নির্বাচন করুন',
                    'hi' => '"Send Payment" विकल्प चुनें',
                    'ur' => '"Send Payment" کا انتخاب کریں',
                    'ar' => 'اختر خيار "إرسال دفعة"',
                ],

                '3' => [
                    'en' => 'Enter the email address "{paypal_email}"',
                    'bn' => 'ইমেইল ঠিকানা লিখুন "{paypal_email}"',
                    'hi' => 'ईमेल पता दर्ज करें "{paypal_email}"',
                    'ur' => 'ای میل ایڈریس درج کریں "{paypal_email}"',
                    'ar' => 'أدخل عنوان البريد الإلكتروني "{paypal_email}"',
                ],

                '4' => [
                    'en' => 'Enter amount: {amount} {currency}',
                    'bn' => 'পরিমাণ লিখুন: {amount} {currency}',
                    'hi' => 'राशि दर्ज करें: {amount} {currency}',
                    'ur' => 'رقم درج کریں: {amount} {currency}',
                    'ar' => 'أدخل المبلغ: {amount} {currency}',
                ],

                '5' => [
                    'en' => 'Check all details carefully and confirm the transfer',
                    'bn' => 'সব তথ্য ভালোভাবে যাচাই করে ট্রান্সফার নিশ্চিত করুন',
                    'hi' => 'सभी विवरण ध्यान से जांचें और ट्रांसफर की पुष्टि करें',
                    'ur' => 'تمام تفصیلات غور سے چیک کریں اور ٹرانسفر کی تصدیق کریں',
                    'ar' => 'تحقق من جميع التفاصيل بعناية ثم أكد التحويل',
                ],

                '6' => [
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
                    'copy' => true,
                    'value' => $data['options']['paypal_email'] ?? '',
                    'vars' => [
                        '{paypal_email}' => $data['options']['paypal_email'] ?? ''
                    ]
                ],
                [
                    'icon' => '',
                    'text' => '4',
                    'copy' => true,
                    'value' => $data['transaction']['local_net_amount'],
                    'vars' => [
                        '{amount}' => $data['transaction']['local_net_amount'],
                        '{currency}' => $data['transaction']['local_currency']
                    ]
                ],
                [
                    'icon' => '',
                    'text' => '5',
                    'copy' => false
                ],
                [
                    'icon' => '',
                    'text' => '6',
                    'copy' => false
                ],
            ];
        }
    }
