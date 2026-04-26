<?php

declare(strict_types=1);

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;

class TaptapSendManualGateway implements GatewayAdapterInterface
{
    use GatewayDefaults;
        public function info(): array
    {
            return [
                'title'       => 'TapTap Send Manual',
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
                'primary_color'        => '#03691f',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#03691f',
                'btn_text_color'        => '#FFFFFF',
            ];
        }

        public function fields(): array
    {
            return [
                [
                    'name'  => 'recipient_country',
                    'label' => 'Enter recipient country',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'payment_method',
                    'label' => 'Payment Method',
                    'type'  => 'select',
                    'options' => [
                        'Bkash'  => 'Bkash',
                        'Nagad' => 'Nagad',
                        'Rocket' => 'Rocket',
                        'Upay' => 'Upay',
                    ],
                    'value' => 'Bkash',
                    'required' => false,
                    'multiple' => false,
                ],
                [
                    'name'  => 'mfs_number',
                    'label' => 'MFS Number',
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
                    'en' => 'Go to your TapTap Send Mobile App',
                    'bn' => 'আপনার TapTap Send মোবাইল অ্যাপে যান',
                    'hi' => 'अपने TapTap Send मोबाइल ऐप पर जाएं',
                    'ur' => 'اپنی TapTap Send موبائل ایپ پر جائیں',
                    'ar' => 'انتقل إلى تطبيق TapTap Send على هاتفك',
                ],

                '2' => [
                    'en' => 'Choose recipient country "{recipient_country}"',
                    'bn' => 'প্রাপকের দেশ নির্বাচন করুন "{recipient_country}"',
                    'hi' => 'प्राप्तकर्ता का देश चुनें "{recipient_country}"',
                    'ur' => 'وصول کنندہ کا ملک منتخب کریں "{recipient_country}"',
                    'ar' => 'اختر بلد المستلم "{recipient_country}"',
                ],

                // Step: Enter amount
                '3' => [
                    'en' => 'Enter amount: {amount} {currency}',
                    'bn' => 'পরিমাণ লিখুন: {amount} {currency}',
                    'hi' => 'राशि दर्ज करें: {amount} {currency}',
                    'ur' => 'رقم درج کریں: {amount} {currency}',
                    'ar' => 'أدخل المبلغ: {amount} {currency}',
                ],

                // Step: Choose payout method
                '4' => [
                    'en' => 'Choose the recipient payout method: {payment_method}',
                    'bn' => 'প্রাপকের পেমেন্ট পদ্ধতি নির্বাচন করুন: {payment_method}',
                    'hi' => 'प्राप्तकर्ता की भुगतान विधि चुनें: {payment_method}',
                    'ur' => 'وصول کنندہ کا ادائیگی کا طریقہ منتخب کریں: {payment_method}',
                    'ar' => 'اختر طريقة استلام المستلم: {payment_method}',
                ],

                // Step: Enter payout number
                '5' => [
                    'en' => 'Enter the recipient payout number: {mfs_number}',
                    'bn' => 'প্রাপকের পেআউট নম্বর লিখুন: {mfs_number}',
                    'hi' => 'प्राप्तकर्ता का भुगतान नंबर दर्ज करें: {mfs_number}',
                    'ur' => 'وصول کنندہ کا ادائیگی نمبر درج کریں: {mfs_number}',
                    'ar' => 'أدخل رقم استلام المستلم: {mfs_number}',
                ],

                // Step: Confirm transfer
                '6' => [
                    'en' => 'Check all details carefully and confirm the transfer',
                    'bn' => 'সব তথ্য ভালোভাবে যাচাই করে ট্রান্সফার নিশ্চিত করুন',
                    'hi' => 'सभी विवरण ध्यान से जांचें और ट्रांसफर की पुष्टि करें',
                    'ur' => 'تمام تفصیلات غور سے چیک کریں اور ٹرانسفر کی تصدیق کریں',
                    'ar' => 'تحقق من جميع التفاصيل بعناية ثم أكد التحويل',
                ],

                // Step: Submit transaction ID
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
                    'vars' => [
                        '{recipient_country}' => $data['options']['recipient_country'] ?? ''
                    ]
                ],
                [
                    'icon' => '',
                    'text' => '3',
                    'copy' => true,
                    'value' => $data['transaction']['local_net_amount'],
                    'vars' => [
                        '{amount}' => $data['transaction']['local_net_amount'],
                        '{currency}' => $data['transaction']['local_currency']
                    ]
                ],
                [
                    'icon' => '',
                    'text' => '4',
                    'copy' => true,
                    'value' => $data['options']['payment_method'] ?? '',
                    'vars' => [
                        '{payment_method}' => $data['options']['payment_method'] ?? ''
                    ]
                ],
                [
                    'icon' => '',
                    'text' => '5',
                    'copy' => true,
                    'value' => $data['options']['mfs_number'] ?? '',
                    'vars' => [
                        '{mfs_number}' => $data['options']['mfs_number'] ?? ''
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
