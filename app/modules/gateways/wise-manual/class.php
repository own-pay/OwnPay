<?php

declare(strict_types=1);

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;

class WiseManualGateway implements GatewayAdapterInterface
{
    use GatewayDefaults;
        public function info(): array
    {
            return [
                'title'       => 'Wise Manual',
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
                'primary_color'        => '#163300',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#163300',
                'btn_text_color'        => '#FFFFFF',
            ];
        }

        public function fields(): array
    {
            return [
                [
                    'name'  => 'recipient_wise_account',
                    'label' => 'Recipient Wise Account',
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
                    'en' => 'Go to your Wise Mobile App or Website',
                    'bn' => 'আপনার Wise মোবাইল অ্যাপ বা ওয়েবসাইটে যান',
                    'hi' => 'अपने Wise मोबाइल ऐप या वेबसाइट पर जाएं',
                    'ur' => 'اپنے Wise موبائل ایپ یا ویب سائٹ پر جائیں',
                    'ar' => 'انتقل إلى تطبيق Wise على الهاتف أو الموقع الإلكتروني',
                ],

                '2' => [
                    'en' => 'Click "Send"',
                    'bn' => '"Send" বাটনে ক্লিক করুন',
                    'hi' => '"Send" पर क्लिक करें',
                    'ur' => '"Send" پر کلک کریں',
                    'ar' => 'انقر على "إرسال"',
                ],

                '3' => [
                    'en' => 'Choose Currency "{currency}"',
                    'bn' => 'মুদ্রা নির্বাচন করুন "{currency}"',
                    'hi' => 'मुद्रा चुनें "{currency}"',
                    'ur' => 'کرنسی منتخب کریں "{currency}"',
                    'ar' => 'اختر العملة "{currency}"',
                ],

                // Step: Enter amount
                '4' => [
                    'en' => 'Enter amount: {amount} {currency}',
                    'bn' => 'পরিমাণ লিখুন: {amount} {currency}',
                    'hi' => 'राशि दर्ज करें: {amount} {currency}',
                    'ur' => 'رقم درج کریں: {amount} {currency}',
                    'ar' => 'أدخل المبلغ: {amount} {currency}',
                ],

                // Step: Enter recipient
                '5' => [
                    'en' => 'Enter the recipient\'s Wise account: {recipient_wise_account}',
                    'bn' => 'প্রাপকের Wise অ্যাকাউন্ট লিখুন: {recipient_wise_account}',
                    'hi' => 'प्राप्तकर्ता का Wise खाता दर्ज करें: {recipient_wise_account}',
                    'ur' => 'وصول کنندہ کا Wise اکاؤنٹ درج کریں: {recipient_wise_account}',
                    'ar' => 'أدخل حساب Wise الخاص بالمستلم: {recipient_wise_account}',
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
                    'ar' => 'أدخل رقم المعاملة في المربع أدناه ثم انقر على "إرسال"',
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
                    'copy' => false,
                    'vars' => [
                        '{currency}' => $data['transaction']['local_currency'] ?? ''
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
                    'copy' => true,
                    'value' => $data['options']['recipient_wise_account'] ?? '',
                    'vars' => [
                        '{recipient_wise_account}' => $data['options']['recipient_wise_account'] ?? ''
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
