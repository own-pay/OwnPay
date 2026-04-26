<?php

declare(strict_types=1);

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;

class IpayMerchantGateway implements GatewayAdapterInterface
{
    use GatewayDefaults;
        public function info(): array
    {
            return [
                'title'       => 'Ipay Merchant',
                'logo'        => 'assets/logo.jpg',
                'currency'        => 'BDT',
                'tab'        => 'mfs',

                'gateway_type'        => 'automation',
                'sender_key'        => 'ipay',
                'sender_type'        => 'Merchant',
            ];
        }

        public function color(): array
    {
            return [
                'primary_color'        => '#019789',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#019789',
                'btn_text_color'        => '#FFFFFF',
            ];
        }
    public function fields(): array
    {
        return [];
    }


        public function supported_languages(): array
    {
            return [
                'en' => 'English',
                'bn' => 'বাংলা',
            ];
        }

        public function lang_text(): array
    {
            return [
                '1' => [
                    'en' => 'Go to your iPay Mobile App.',
                    'bn' => 'আপনার আইপে মোবাইল অ্যাপে যান।',
                ],

                '2' => [
                    'en' => 'Choose "Make Payment"',
                    'bn' => '“Make Payment” নির্বাচন করুন',
                ],

                '3' => [
                    'en' => 'Enter the Number: {mobile_number}',
                    'bn' => 'নম্বর লিখুন: {mobile_number}',
                ],

                '4' => [
                    'en' => 'Enter the Amount: {amount} {currency}',
                    'bn' => 'পরিমাণ লিখুন: {amount} {currency}',
                ],

                '5' => [
                    'en' => 'Now enter your iPay PIN to confirm.',
                    'bn' => 'এখন নিশ্চিত করতে আপনার আইপে পিন লিখুন।',
                ],

                '6' => [
                    'en' => 'Put the Transaction ID in the box below and press Verify',
                    'bn' => 'ট্রানজ্যাকশন আইডি নিচের বক্সে লিখুন এবং যাচাই করুন চাপুন।',
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
                    'value' => $data['options']['mobile_number'] ?? '',
                    'vars' => [
                        '{mobile_number}' => $data['options']['mobile_number'] ?? ''
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
