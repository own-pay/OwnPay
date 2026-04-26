<?php

declare(strict_types=1);

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;

class PayeerManualGateway implements GatewayAdapterInterface
{
    use GatewayDefaults;
        public function info(): array
    {
            return [
                'title'       => 'Payeer Manual',
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
                'primary_color'        => '#64b3df',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#64b3df',
                'btn_text_color'        => '#FFFFFF',
            ];
        }

        public function fields(): array
    {
            return [
                [
                    'name'  => 'recipient_payeer_account',
                    'label' => 'Recipient Payeer Account',
                    'type'  => 'text',
                ]
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
                    'en' => 'Log in to your Payeer account at Payeer.com',
                    'bn' => 'আপনার Payeer একাউন্টে লগইন করুন Payeer.com এ',
                    'hi' => 'अपने Payeer अकाउंट में लॉग इन करें Payeer.com पर',
                    'ur' => 'اپنے Payeer اکاؤنٹ میں لاگ ان کریں Payeer.com پر',
                    'ar' => 'قم بتسجيل الدخول إلى حساب Payeer الخاص بك على Payeer.com',
                ],

                '2' => [
                    'en' => 'Go to the "Send Money" or "Transfer" section',
                    'bn' => '"Send Money" বা "Transfer" সেকশনে যান',
                    'hi' => '"Send Money" या "Transfer" सेक्शन में जाएं',
                    'ur' => '"Send Money" یا "Transfer" سیکشن میں جائیں',
                    'ar' => 'انتقل إلى قسم "إرسال الأموال" أو "تحويل"',
                ],

                // Step: Enter recipient
                '3' => [
                    'en' => 'Enter the recipient\'s Payeer account ID: {recipient_payeer_account}',
                    'bn' => 'গ্রাহকের Payeer অ্যাকাউন্ট আইডি লিখুন: {recipient_payeer_account}',
                    'hi' => 'प्राप्तकर्ता का Payeer अकाउंट ID दर्ज करें: {recipient_payeer_account}',
                    'ur' => 'وصول کنندہ کے Payeer اکاؤنٹ ID درج کریں: {recipient_payeer_account}',
                    'ar' => 'أدخل معرف حساب Payeer للمستلم: {recipient_payeer_account}',
                ],

                // Step: Enter amount
                '4' => [
                    'en' => 'Enter the amount: {amount} {currency}',
                    'bn' => 'পরিমাণ লিখুন: {amount} {currency}',
                    'hi' => 'राशि दर्ज करें: {amount} {currency}',
                    'ur' => 'رقم درج کریں: {amount} {currency}',
                    'ar' => 'أدخل المبلغ: {amount} {currency}',
                ],

                '5' => [
                    'en' => 'Select the currency and your Payeer balance to pay from',
                    'bn' => 'মূল্য নির্বাচন করুন এবং কোন Payeer ব্যালেন্স থেকে পরিশোধ করবেন তা নির্বাচন করুন',
                    'hi' => 'मुद्रा और अपने Payeer बैलेंस का चयन करें जिससे भुगतान करना है',
                    'ur' => 'کرنسی اور اپنے Payeer بیلنس کا انتخاب کریں جس سے ادائیگی کریں',
                    'ar' => 'اختر العملة ورصيد Payeer الخاص بك للدفع منه',
                ],

                '6' => [
                    'en' => 'Check all details carefully and confirm the transfer',
                    'bn' => 'সব তথ্য যাচাই করুন এবং ট্রান্সফার নিশ্চিত করুন',
                    'hi' => 'सारी जानकारी जांचें और ट्रांसफर की पुष्टि करें',
                    'ur' => 'تمام تفصیلات چیک کریں اور ٹرانسفر کی تصدیق کریں',
                    'ar' => 'تحقق من جميع التفاصيل وأكد التحويل',
                ],

                '7' => [
                    'en' => 'Enter the transaction ID in the box below and click "Submit"',
                    'bn' => 'ট্রানজ্যাকশন আইডি নিচের বক্সে লিখুন এবং "Submit" চাপুন',
                    'hi' => 'नीचे बॉक्स में ट्रांज़ैक्शन ID दर्ज करें और "Submit" पर क्लिक करें',
                    'ur' => 'ٹرانزیکشن ID نیچے والے باکس میں درج کریں اور "Submit" پر کلک کریں',
                    'ar' => 'أدخل معرف المعاملة في الصندوق أدناه وانقر على "Submit"',
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
                    'copy' => false
                ],
                [
                    'icon' => '',
                    'text' => '3',
                    'copy' => true,
                    'value' => $data['options']['recipient_payeer_account'] ?? '',
                    'vars' => [
                        '{recipient_payeer_account}' => $data['options']['recipient_payeer_account'] ?? ''
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
                [
                    'icon' => '',
                    'text' => '7',
                    'copy' => false
                ],

            ];
        }
    }
