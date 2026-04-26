<?php
declare(strict_types=1);

namespace OwnPay\Service;

/**
 * Gateway Renderer Service
 *
 * Handles gateway listing, info retrieval, checkout rendering,
 * and form field rendering for payment links and invoices.
 */
class GatewayRendererService
{
    /**
     * Return the database table prefix from the environment.
     */
    private static function dbPrefix(): string
    {
        return $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
    }

    public static function op_gateways($tab = '', $data = [])
    {
        $db_prefix = self::dbPrefix();

        $params = [':tab' => $tab, ':brand_id' => $data['brand']['id']];

        $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE tab = :tab AND brand_id = :brand_id AND status = "active"', '* FROM', $params);

        $gatewayList = [];

        if ($response_gateway['status'] === true) {
            $currencyRates = [];

            $currencyRes = CrudService::select($db_prefix . 'currency', ' WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $data['brand']['id']]);

            if (!empty($currencyRes['response'])) {
                foreach ($currencyRes['response'] as $c) {
                    $currencyRates[$c['code']] = $c['rate'];
                }
            }

            foreach ($response_gateway['response'] as $row) {
                $txnAmount = money_sanitize($data['transaction']['amount']);
                $txnCurrency = $data['transaction']['currency'];

                if ($txnCurrency === $row['currency']) {
                    $convertedAmount = $txnAmount;
                } else {
                    if (isset($currencyRates[$row['currency']])) {
                        $convertedAmount = money_div($txnAmount, $currencyRates[$row['currency']]);
                    } else {
                        $convertedAmount = "0";
                    }
                }

                $fixed_discount = money_sanitize($response_gateway['response'][0]['fixed_discount']);
                $percentage_discount = money_sanitize($response_gateway['response'][0]['percentage_discount']);

                $fixed_charge = money_sanitize($response_gateway['response'][0]['fixed_charge']);
                $percentage_charge = money_sanitize($response_gateway['response'][0]['percentage_charge']);

                $percentageDiscountAmount = money_div(money_mul($convertedAmount, $percentage_discount, 8), "100", 8);
                $totalDiscount = money_add($fixed_discount, $percentageDiscountAmount, 8);

                $percentageChargeAmount = money_div(money_mul($convertedAmount, $percentage_charge, 8), "100", 8);
                $totalProcessingFee = money_add($fixed_charge, $percentageChargeAmount, 8);

                $convertedAmount = money_add(money_sub($convertedAmount, $totalDiscount, 8), $totalProcessingFee, 8);

                $min = money_sanitize($row['min_allow']);
                $max = money_sanitize($row['max_allow']);

                $hasNoMax = bccomp($max, '0', 2) <= 0 || empty($max);

                $isAboveMin = bccomp(money_round($convertedAmount), $min, 2) >= 0;
                $isBelowMax = $hasNoMax ? true : (bccomp(money_round($convertedAmount), $max, 2) <= 0);

                if ($isAboveMin && $isBelowMax) {
                    $gatewayList[] = [
                        'gateway_id' => $row['gateway_id'],
                        'slug' => $row['slug'],
                        'name' => $row['name'],
                        'display' => $row['display'],
                        'logo' => $row['logo'],
                        'currency' => $row['currency'],
                        'min_allow' => money_round($row['min_allow']),
                        'max_allow' => money_round($row['max_allow']),
                        'fixed_discount' => money_round($row['fixed_discount']),
                        'percentage_discount' => money_round($row['percentage_discount']),
                        'fixed_charge' => money_round($row['fixed_charge']),
                        'percentage_charge' => money_round($row['percentage_charge']),
                        'primary_color' => $row['primary_color'],
                        'text_color' => $row['text_color'],
                        'btn_color' => $row['btn_color'],
                        'btn_text_color' => $row['btn_text_color'],
                    ];
                }
            }

            return [
                'status' => true,
                'gateway' => $gatewayList
            ];
        }

        return [
            'status' => false,
            'gateway' => []
        ];
    }

    public static function op_gateway_info($gateway_id = '', $data = [])
    {
        $db_prefix = self::dbPrefix();

        $params = [':gateway_id' => $gateway_id, ':brand_id' => $data['brand']['id']];

        $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE gateway_id = :gateway_id AND brand_id = :brand_id AND status = "active"', '* FROM', $params);

        if ($response_gateway['status'] === true) {
            $row = $response_gateway['response'][0];
            $currencyRates = [];

            $currencyRes = CrudService::select($db_prefix . 'currency', ' WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $data['brand']['id']]);

            if (!empty($currencyRes['response'])) {
                foreach ($currencyRes['response'] as $c) {
                    $currencyRates[$c['code']] = $c['rate'];
                }
            }

            $txnAmount = money_sanitize($data['transaction']['amount']);
            $txnCurrency = $data['transaction']['currency'];

            if ($txnCurrency === $row['currency']) {
                $convertedAmount = $txnAmount;
            } else {
                if (isset($currencyRates[$row['currency']])) {
                    $convertedAmount = money_div($txnAmount, $currencyRates[$row['currency']]);
                } else {
                    $convertedAmount = "0";
                }
            }

            $fixed_discount = money_sanitize($response_gateway['response'][0]['fixed_discount']);
            $percentage_discount = money_sanitize($response_gateway['response'][0]['percentage_discount']);

            $fixed_charge = money_sanitize($response_gateway['response'][0]['fixed_charge']);
            $percentage_charge = money_sanitize($response_gateway['response'][0]['percentage_charge']);

            $percentageDiscountAmount = money_div(money_mul($convertedAmount, $percentage_discount, 8), "100", 8);
            $totalDiscount = money_add($fixed_discount, $percentageDiscountAmount, 8);

            $percentageChargeAmount = money_div(money_mul($convertedAmount, $percentage_charge, 8), "100", 8);
            $totalProcessingFee = money_add($fixed_charge, $percentageChargeAmount, 8);

            $convertedAmount = money_add(money_sub($convertedAmount, $totalDiscount, 8), $totalProcessingFee, 8);

            $min = money_sanitize($row['min_allow']);
            $max = money_sanitize($row['max_allow']);

            $hasNoMax = bccomp($max, '0', 2) <= 0 || empty($max);

            $isAboveMin = bccomp(money_round($convertedAmount), $min, 2) >= 0;
            $isBelowMax = $hasNoMax ? true : (bccomp(money_round($convertedAmount), $max, 2) <= 0);

            if ($isAboveMin && $isBelowMax) {
                if (file_exists(__DIR__ . '/../pp-modules/pp-gateways/' . $response_gateway['response'][0]['slug'] . '/class.php')) {
                    require_once __DIR__ . '/../pp-modules/pp-gateways/' . $response_gateway['response'][0]['slug'] . '/class.php';

                    $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $response_gateway['response'][0]['slug']))) . 'Gateway';

                    $gateway = new $class();

                    $gateway_info = $gateway->info();

                    if (method_exists($gateway, 'supported_languages')) {
                        $supported_languages = $gateway->supported_languages();
                    } else {
                        $supported_languages = [];
                    }
                } else {
                    if ($response_gateway['response'][0]['tab'] == 'bank') {
                        $supported_languages = [
                            'en' => 'English',
                            'bn' => 'বাংলা',
                            'hi' => 'हिन्दी',
                            'ur' => 'اردو',
                            'ar' => 'العربية',
                        ];
                    } else {
                        $supported_languages = [];
                    }
                }

                $gatewayList = [
                    'gateway_id' => $row['gateway_id'],
                    'slug' => $row['slug'],
                    'name' => $row['name'],
                    'display' => $row['display'],
                    'logo' => $row['logo'],
                    'currency' => $row['currency'],
                    'min_allow' => money_round($row['min_allow']),
                    'max_allow' => money_round($row['max_allow']),
                    'fixed_discount' => money_round($row['fixed_discount']),
                    'percentage_discount' => money_round($row['percentage_discount']),
                    'fixed_charge' => money_round($row['fixed_charge']),
                    'percentage_charge' => money_round($row['percentage_charge']),
                    'primary_color' => $row['primary_color'],
                    'text_color' => $row['text_color'],
                    'btn_color' => $row['btn_color'],
                    'btn_text_color' => $row['btn_text_color'],
                ];

                return [
                    'status' => true,
                    'gateway' => $gatewayList,
                    'supported_languages' => $supported_languages
                ];
            } else {
                return [
                    'status' => false,
                    'gateway' => []
                ];
            }
        }

        return [
            'status' => false,
            'gateway' => []
        ];
    }

    public static function op_gateway_render($gateway_id = '', $data = [])
    {
        $db_prefix = self::dbPrefix();

        unset($data['options'], $data['lang']);

        $params = [':gateway_id' => $gateway_id, ':brand_id' => $data['brand']['id']];

        $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE gateway_id = :gateway_id AND brand_id = :brand_id  AND status = "active"', '* FROM', $params);
        if ($response_gateway['status'] == true) {

            $options = [];

            $params = [':gateway_id' => $gateway_id];
            $response_gateways_parameter = CrudService::select($db_prefix . 'gateways_parameter', 'WHERE gateway_id = :gateway_id', '* FROM', $params);
            foreach ($response_gateways_parameter['response'] as $field) {
                $value = $field['value'];

                if (!empty($field['multiple']) && !empty($value)) {
                    $value = is_array($value) ? $value : json_decode($value, true);
                }

                $options[$field['option_name']] = $value;
            }

            $data['options'] = $options;

            $gatewayInfo = [
                'gateway_id' => $response_gateway['response'][0]['gateway_id'],
                'slug' => $response_gateway['response'][0]['slug'],
                'name' => $response_gateway['response'][0]['name'],
                'display' => $response_gateway['response'][0]['display'],
                'logo' => $response_gateway['response'][0]['logo'],
                'currency' => $response_gateway['response'][0]['currency'],
                'min_allow' => money_round($response_gateway['response'][0]['min_allow']),
                'max_allow' => money_round($response_gateway['response'][0]['max_allow']),

                'fixed_discount' => money_round($response_gateway['response'][0]['fixed_discount']),
                'percentage_discount' => money_round($response_gateway['response'][0]['percentage_discount']),
                'fixed_charge' => money_round($response_gateway['response'][0]['fixed_charge']),
                'percentage_charge' => money_round($response_gateway['response'][0]['percentage_charge']),

                'primary_color' => $response_gateway['response'][0]['primary_color'],
                'text_color' => $response_gateway['response'][0]['text_color'],
                'btn_color' => $response_gateway['response'][0]['btn_color'],
                'btn_text_color' => $response_gateway['response'][0]['btn_text_color'],
            ];

            $data['gateway'] = $gatewayInfo;

            $currencyRates = [];

            $currencyRes = CrudService::select($db_prefix . 'currency', ' WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $response_gateway['response'][0]['brand_id']]);

            if (!empty($currencyRes['response'])) {
                foreach ($currencyRes['response'] as $c) {
                    $currencyRates[$c['code']] = $c['rate'];
                }
            }

            $txnAmount = money_sanitize($data['transaction']['amount']);
            $txnCurrency = $data['transaction']['currency'];
            $gatewayCurrency = $response_gateway['response'][0]['currency'];

            if ($txnCurrency === $gatewayCurrency) {
                $convertedAmount = $txnAmount;
            } else {
                if (isset($currencyRates[$gatewayCurrency])) {
                    $convertedAmount = money_div($txnAmount, $currencyRates[$gatewayCurrency]);
                } else {
                    $convertedAmount = "0";
                }
            }

            $fixed_discount = money_sanitize($response_gateway['response'][0]['fixed_discount']);
            $percentage_discount = money_sanitize($response_gateway['response'][0]['percentage_discount']);

            $fixed_charge = money_sanitize($response_gateway['response'][0]['fixed_charge']);
            $percentage_charge = money_sanitize($response_gateway['response'][0]['percentage_charge']);

            $percentageDiscountAmount = money_div(money_mul($convertedAmount, $percentage_discount, 8), "100", 8);
            $totalDiscount = money_add($fixed_discount, $percentageDiscountAmount, 8);

            $percentageChargeAmount = money_div(money_mul($convertedAmount, $percentage_charge, 8), "100", 8);
            $totalProcessingFee = money_add($fixed_charge, $percentageChargeAmount, 8);

            $convertedAmount = money_add(money_sub($convertedAmount, $totalDiscount, 8), $totalProcessingFee, 8);

            if ($txnCurrency !== $gatewayCurrency && isset($currencyRates[$gatewayCurrency])) {
                $totalDiscount = money_mul($totalDiscount, $currencyRates[$gatewayCurrency], 8);
                $totalProcessingFee = money_mul($totalProcessingFee, $currencyRates[$gatewayCurrency], 8);
            }

            $data['transaction']['amount'] = money_round($txnAmount, 2);
            $data['transaction']['processing_fee'] = money_round($totalProcessingFee, 2);
            $data['transaction']['discount_amount'] = money_round($totalDiscount, 2);
            $data['transaction']['local_net_amount'] = money_round($convertedAmount, 2);
            $data['transaction']['local_currency'] = $gatewayCurrency;

            if (file_exists(__DIR__ . '/../pp-modules/pp-gateways/' . $response_gateway['response'][0]['slug'] . '/class.php')) {
                require_once __DIR__ . '/../pp-modules/pp-gateways/' . $response_gateway['response'][0]['slug'] . '/class.php';

                $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $response_gateway['response'][0]['slug']))) . 'Gateway';

                $gateway = new $class();

                $gateway_info = $gateway->info();

                if (method_exists($gateway, 'supported_languages')) {
                    $supported_languages = $gateway->supported_languages();
                } else {
                    $supported_languages = [];
                }

                if (method_exists($gateway, 'lang_text')) {
                    $lang_text = $gateway->lang_text();
                } else {
                    $lang_text = [];
                }
            } else {
                if ($response_gateway['response'][0]['tab'] == 'bank') {
                    $gateway = '';

                    $supported_languages = [
                        'en' => 'English',
                        'bn' => 'বাংলা',
                        'hi' => 'हिन्दी',
                        'ur' => 'اردو',
                        'ar' => 'العربية',
                    ];

                    $lang_text = [
                        'bank_step_bank_name' => [
                            'en' => 'Bank Name: {bank_name}',
                            'bn' => 'ব্যাংকের নাম: {bank_name}',
                            'hi' => 'बैंक का नाम: {bank_name}',
                            'ur' => 'بینک کا نام: {bank_name}',
                            'ar' => 'اسم البنك: {bank_name}',
                        ],

                        'bank_step_account_name' => [
                            'en' => 'Account Name: {account_holder_name}',
                            'bn' => 'অ্যাকাউন্টের নাম: {account_holder_name}',
                            'hi' => 'खाते का नाम: {account_holder_name}',
                            'ur' => 'اکاؤنٹ کا نام: {account_holder_name}',
                            'ar' => 'اسم الحساب: {account_holder_name}',
                        ],

                        'bank_step_account_number' => [
                            'en' => 'Account Number: {account_number}',
                            'bn' => 'অ্যাকাউন্ট নম্বর: {account_number}',
                            'hi' => 'खाता संख्या: {account_number}',
                            'ur' => 'اکاؤنٹ نمبر: {account_number}',
                            'ar' => 'رقم الحساب: {account_number}',
                        ],

                        'bank_step_branch_name' => [
                            'en' => 'Branch Name: {branch_name}',
                            'bn' => 'শাখার নাম: {branch_name}',
                            'hi' => 'शाखा का नाम: {branch_name}',
                            'ur' => 'برانچ کا نام: {branch_name}',
                            'ar' => 'اسم الفرع: {branch_name}',
                        ],

                        'bank_step_routing_number' => [
                            'en' => 'Routing Number: {routing_number}',
                            'bn' => 'রাউটিং নম্বর: {routing_number}',
                            'hi' => 'रूटिंग नंबर: {routing_number}',
                            'ur' => 'روٹنگ نمبر: {routing_number}',
                            'ar' => 'رقم التوجيه: {routing_number}',
                        ],

                        'bank_step_swift_code' => [
                            'en' => 'Swift Code: {swift_code}',
                            'bn' => 'সুইফট কোড: {swift_code}',
                            'hi' => 'स्विफ्ट कोड: {swift_code}',
                            'ur' => 'سوئفٹ کوڈ: {swift_code}',
                            'ar' => 'رمز السويفت: {swift_code}',
                        ],

                        'bank_step_amount' => [
                            'en' => 'Amount: {amount} {currency}',
                            'bn' => 'পরিমাণ: {amount} {currency}',
                            'hi' => 'राशि: {amount} {currency}',
                            'ur' => 'رقم: {amount} {currency}',
                            'ar' => 'المبلغ: {amount} {currency}',
                        ],

                        'bank_step_slip' => [
                            'en' => 'Upload the Payment Slip in the box below and press Submit',
                            'bn' => 'নিচের বক্সে পেমেন্ট স্লিপ আপলোড করুন এবং জমা দিন চাপুন।',
                            'hi' => 'नीचे दिए गए बॉक्स में भुगतान रसीद अपलोड करें और "सबमिट" दबाएँ।',
                            'ur' => 'نیچے دیے گئے باکس میں ادائیگی کی رسید اپ لوڈ کریں اور "Submit" دبائیں۔',
                            'ar' => 'قم برفع إيصال الدفع في المربع أدناه ثم اضغط على "إرسال".',
                        ],
                    ];

                    $instructions = [
                        [
                            'icon' => '',
                            'text' => 'bank_step_bank_name',
                            'copy' => true,
                            'value' => $data['options']['bank_name'],
                            'vars' => [
                                '{bank_name}' => $data['options']['bank_name']
                            ]
                        ],
                        [
                            'icon' => '',
                            'text' => 'bank_step_account_name',
                            'copy' => true,
                            'value' => $data['options']['account_holder_name'],
                            'vars' => [
                                '{account_holder_name}' => $data['options']['account_holder_name']
                            ]
                        ],
                        [
                            'icon' => '',
                            'text' => 'bank_step_account_number',
                            'copy' => true,
                            'value' => $data['options']['account_number'],
                            'vars' => [
                                '{account_number}' => $data['options']['account_number']
                            ]
                        ],
                        [
                            'icon' => '',
                            'text' => 'bank_step_branch_name',
                            'copy' => true,
                            'value' => $data['options']['branch_name'],
                            'vars' => [
                                '{branch_name}' => $data['options']['branch_name']
                            ]
                        ],
                        [
                            'icon' => '',
                            'text' => 'bank_step_routing_number',
                            'copy' => true,
                            'value' => $data['options']['routing_number'],
                            'vars' => [
                                '{routing_number}' => $data['options']['routing_number']
                            ]
                        ],
                        [
                            'icon' => '',
                            'text' => 'bank_step_swift_code',
                            'copy' => true,
                            'value' => $data['options']['swift_code'],
                            'vars' => [
                                '{swift_code}' => $data['options']['swift_code']
                            ]
                        ],
                        [
                            'icon' => '',
                            'text' => 'bank_step_amount',
                            'copy' => true,
                            'value' => $data['transaction']['local_net_amount'],
                            'vars' => [
                                '{amount}' => number_format((float) $data['transaction']['local_net_amount'], 2),
                                '{currency}' => $data['transaction']['local_currency']
                            ]
                        ],
                        [
                            'icon' => '',
                            'text' => 'bank_step_slip',
                            'copy' => false,
                        ],
                    ];

                    $gateway_info = [
                        'gateway_type' => 'manual',
                        'verify_by' => 'slip',
                    ];
                } else {
                    return false;
                }
            }

            $lang_text['verify'] = [
                'en' => 'Verify',
                'bn' => 'যাচাই করুন',
                'hi' => 'सत्यापित करें',
                'ur' => 'تصدیق کریں',
                'ar' => 'تحقق',
            ];

            $lang_text['transaction_id'] = [
                'en' => 'Transaction ID',
                'bn' => 'ট্রানজ্যাকশন আইডি',
                'hi' => 'लेन-देन आईडी',
                'ur' => 'لین دین آئی ڈی',
                'ar' => 'معرّف المعاملة',
            ];

            $lang_text['enter_transaction_id'] = [
                'en' => 'Enter transaction ID',
                'bn' => 'ট্রানজ্যাকশন আইডি লিখুন',
                'hi' => 'लेन-देन आईडी दर्ज करें',
                'ur' => 'لین دین آئی ڈی درج کریں',
                'ar' => 'أدخل معرّف المعاملة',
            ];

            $lang_text['upload_slip'] = [
                'en' => 'Upload Payment Slip',
                'bn' => 'পেমেন্ট স্লিপ আপলোড করুন',
                'hi' => 'भुगतान स्लिप अपलोड करें',
                'ur' => 'ادائیگی سلپ اپ لوڈ کریں',
                'ar' => 'ارفع إيصال الدفع',
            ];

            $lang_text['mobile_number'] = [
                'en' => 'Mobile Number',
                'bn' => 'মোবাইল নম্বর',
                'hi' => 'मोबाइल नंबर',
                'ur' => 'موبائل نمبر',
                'ar' => 'رقم الجوال',
            ];

            $lang_text['submit'] = [
                'en' => 'Submit',
                'bn' => 'জমা দিন',
                'hi' => 'जमा करें',
                'ur' => 'جمع کریں',
                'ar' => 'إرسال',
            ];

            $language = resolveModuleLanguage($data['brand']['locale']['language'], $supported_languages);

            // Build $lang array for developer
            $lang = buildLangArray($lang_text, $language);

            $data['lang'] = $lang; // or whatever new value

            // If you also want to keep discount in sync (optional)
            //$data['transaction']['discount_amount'] = number_format((float)$data['transaction']['discount_amount'],2,'.','');

            if (is_callable([$gateway, 'instructions'])) {
                $instructions = $gateway->instructions($data);
            }

            if (isset($instructions)) {
                echo '<ol class="payment-instructions">';

                $rowli = 0;

                foreach ($instructions as $step) {
                    $rowli = $rowli + 1;

                    // Resolve language directly
                    $text = $lang[$step['text']] ?? $step['text'];

                    // Replace variables
                    if (!empty($step['vars'])) {
                        foreach ($step['vars'] as $k => $v) {
                            $text = str_replace($k, '<span class="dynamic-value">' . $v . '</span>', $text);
                        }
                    }

                    echo '<li class="li-' . $rowli . '">';
                    echo ($step['icon'] == "") ? '<div class="dot"></div>' : $step['icon'];

                    echo '<p>';
                    echo $text;

                    /* Copy button */
                    if (!empty($step['copy']) && isset($step['value'])) {
                        echo ' <span class="button-icon"
                                onclick="copy_value(\'' . htmlspecialchars($step['value'], ENT_QUOTES) . '\')">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                    <path d="M7 9.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667l0 -8.666"/>
                                    <path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1"/>
                                </svg>
                            </span>';
                    }

                    /* Action button */
                    if (!empty($step['action'])) {
                        $action = $step['action'];

                        if ($action['type'] === 'image' && !empty($action['value'])) {
                            echo ' <span class="button-icon"
                                    onclick="op_show_image(\'' . htmlspecialchars($action['value'], ENT_QUOTES) . '\')">
                                    ' . $action['label'] . '
                                </span>';
                        } else {
                            echo '<style>.li-' . $rowli . '{display: none !important;}</style>';
                        }
                    }

                    echo '</p>';
                    echo '</li>';

                }

                echo '</ol>';

                echo '
                        <div id="pp-image-modal" class="pp-modal" style="display:none;">
                            <div class="pp-modal-content">
                                <span class="pp-close" onclick="op_close_image()">&times;</span>
                                <div class="pp-model-image-b"><img id="pp-modal-image" src="" alt="Preview"></div>
                            </div>
                        </div>

                        <script data-cfasync="false">
                            function op_show_image(src) {
                                const modal = document.getElementById("pp-image-modal");
                                const img = document.getElementById("pp-modal-image");

                                img.src = src;
                                modal.style.display = "flex";
                            }

                            function op_close_image() {
                                document.getElementById("pp-image-modal").style.display = "none";
                            }
                        </script>
                    ';
            }

            if (isset($gateway_info)) {
                if (isset($gateway_info['gateway_type']) && $gateway_info['gateway_type'] == "automation") {
                    echo '
                            <form class="payment-form-submit" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action-v2" value="transaction-verify">
                                <input type="hidden" name="gateway-id" value="' . $data['gateway']['gateway_id'] . '">
                                <input type="hidden" name="transaction-id" value="' . $data['transaction']['ref'] . '">

                                <div class="form-group  mt-3" style="display: none">
                                    <label class="form-label">' . $data['lang']['mobile_number'] . '</label>
                                    <div class="form-control-wrap">
                                        <input type="text" class="form-control" name="mobile_number" placeholder="' . $data['lang']['mobile_number'] . '"> 
                                    </div>
                                </div>

                                <div class="form-group  mt-3">
                                    <label class="form-label">' . $data['lang']['transaction_id'] . '</label>
                                    <div class="form-control-wrap">
                                        <input type="text" class="form-control" name="trxid" placeholder="' . $data['lang']['enter_transaction_id'] . '" required=""> 
                                    </div>
                                </div>

                                <button class="btn btn-primary w-100 payment-form-btn mt-3" type="submit">' . $data['lang']['verify'] . '</button>
                            </form>

                            <script data-cfasync="false">
                                document.addEventListener("DOMContentLoaded", function() {
                                    const form = document.querySelector(".payment-form-submit");
                                    const mobileWrapper = form.querySelector(`.form-group[style*="display: none"]`);
                                    const submitBtn = form.querySelector(".payment-form-btn");

                                    form.addEventListener("submit", function(e) {
                                        e.preventDefault();

                                        const formData = new FormData(form);

                                        submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

                                        fetch("", { // replace "" with your PHP AJAX URL if needed
                                            method: "POST",
                                            body: formData
                                        })
                                        .then(res => res.json())
                                        .then(data => {
                                            submitBtn.innerHTML = `' . $data['lang']['verify'] . '`;

                                            if(data.status === "true") {
                                                // Verified successfully
                                                success(data); // pass data if needed
                                            } else if(data.status === "false") {
                                                // Failed verification
                                                if(data.visible_number && data.visible_number === "true") {
                                                    mobileWrapper.style.display = "block";
                                                }
                                                // Call failed handler with title & message
                                                failed(data.title, data.message);
                                            } else {
                                                // Unexpected response
                                                failed("Unexpected Response", "Please try again later.");
                                            }
                                        })
                                        .catch(err => {
                                            submitBtn.innerHTML = `' . $data['lang']['verify'] . '`;
                                            console.error(err);
                                            failed("Request Error", "Something went wrong. Please try again.");
                                        });
                                    });
                                });

                            </script>

                        ';
                }
                if (isset($gateway_info['gateway_type']) && $gateway_info['gateway_type'] == "manual") {
                    if (isset($gateway_info['verify_by']) && $gateway_info['verify_by'] == "trxid") {
                        echo '
                                <form class="payment-form-submit" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action-v2" value="transaction-verify">
                                    <input type="hidden" name="gateway-id" value="' . $data['gateway']['gateway_id'] . '">
                                    <input type="hidden" name="transaction-id" value="' . $data['transaction']['ref'] . '">

                                    <div class="form-group  mt-3">
                                        <label class="form-label">' . $data['lang']['transaction_id'] . '</label>
                                        <div class="form-control-wrap">
                                            <input type="text" class="form-control" name="trxid" placeholder="' . $data['lang']['enter_transaction_id'] . '" required=""> 
                                        </div>
                                    </div>

                                    <button class="btn btn-primary w-100 payment-form-btn mt-3" type="submit">' . $data['lang']['submit'] . '</button>
                                </form>

                                <script data-cfasync="false">
                                    document.addEventListener("DOMContentLoaded", function() {
                                        const form = document.querySelector(".payment-form-submit");
                                        const mobileWrapper = form.querySelector(`.form-group[style*="display: none"]`);
                                        const submitBtn = form.querySelector(".payment-form-btn");

                                        form.addEventListener("submit", function(e) {
                                            e.preventDefault();

                                            const formData = new FormData(form);

                                            submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

                                            fetch("", { // replace "" with your PHP AJAX URL if needed
                                                method: "POST",
                                                body: formData
                                            })
                                            .then(res => res.json())
                                            .then(data => {
                                                submitBtn.innerHTML = `' . $data['lang']['verify'] . '`;

                                                if(data.status === "true") {
                                                    // Verified successfully
                                                    success(data); // pass data if needed
                                                } else if(data.status === "false") {
                                                    // Call failed handler with title & message
                                                    failed(data.title, data.message);
                                                } else {
                                                    // Unexpected response
                                                    failed("Unexpected Response", "Please try again later.");
                                                }
                                            })
                                            .catch(err => {
                                                submitBtn.innerHTML = `' . $data['lang']['verify'] . '`;
                                                console.error(err);
                                                failed("Request Error", "Something went wrong. Please try again.");
                                            });
                                        });
                                    });

                                </script>
                            ';
                    } else {
                        if (isset($gateway_info['verify_by']) && $gateway_info['verify_by'] == "slip") {
                            echo '
                                    <form class="payment-form-submit" method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="action-v2" value="transaction-verify">
                                        <input type="hidden" name="gateway-id" value="' . $data['gateway']['gateway_id'] . '">
                                        <input type="hidden" name="transaction-id" value="' . $data['transaction']['ref'] . '">

                                        <div class="form-group  mt-3">
                                            <label class="form-label">' . $data['lang']['upload_slip'] . '</label>
                                            <div class="form-control-wrap">
                                                <input type="file" class="form-control" name="slip" accept = "image/*" placeholder="' . $data['lang']['upload_slip'] . '" required=""> 
                                            </div>
                                        </div>

                                        <button class="btn btn-primary w-100 payment-form-btn mt-3" type="submit">' . $data['lang']['submit'] . '</button>
                                    </form>

                                    <script data-cfasync="false">
                                        document.addEventListener("DOMContentLoaded", function() {
                                            const form = document.querySelector(".payment-form-submit");
                                            const mobileWrapper = form.querySelector(`.form-group[style*="display: none"]`);
                                            const submitBtn = form.querySelector(".payment-form-btn");

                                            form.addEventListener("submit", function(e) {
                                                e.preventDefault();

                                                const formData = new FormData(form);

                                                submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

                                                fetch("", { // replace "" with your PHP AJAX URL if needed
                                                    method: "POST",
                                                    body: formData
                                                })
                                                .then(res => res.json())
                                                .then(data => {
                                                    submitBtn.innerHTML = `' . $data['lang']['verify'] . '`;

                                                    if(data.status === "true") {
                                                        // Verified successfully
                                                        success(data); // pass data if needed
                                                    } else if(data.status === "false") {
                                                        // Call failed handler with title & message
                                                        failed(data.title, data.message);
                                                    } else {
                                                        // Unexpected response
                                                        failed("Unexpected Response", "Please try again later.");
                                                    }
                                                })
                                                .catch(err => {
                                                    submitBtn.innerHTML = `' . $data['lang']['verify'] . '`;
                                                    console.error(err);
                                                    failed("Request Error", "Something went wrong. Please try again.");
                                                });
                                            });
                                        });

                                    </script>
                                ';
                        }
                    }
                }
            }

            if (isset($_GET['op_callback'])) {
                if (is_callable([$gateway, 'callback'])) {
                    $gateway->callback($data);
                }
            } else {
                if (is_callable([$gateway, 'process_payment'])) {
                    $gateway->process_payment($data);
                }
            }
        } else {
            return false;
        }
    }

    public static function op_renderFormFields(string $type = '', array $data = [])
    {
        if ($type == "payment-link") {
            $paymentLinkID = $data['paymentLink']['pid'] ?? '';
            $fields = $data['paymentLink']['fields'] ?? '';

            echo "<input type='hidden' name='action-v2' value='payment-link'>";
            echo "<input type='hidden' name='itemid' value='" . $paymentLinkID . "'>";

            echo '<div class="mb-3">';
            echo "<label class='form-label' for='full-name'>" . $data['lang']['full_name'] . " <span class='text-danger'>*</span></label>";
            echo "<input type='text' name='full-name' id='full-name' class='form-control' required>";
            echo '</div>';

            echo '<div class="mb-3">';
            echo "<label class='form-label' for='email-address'>" . $data['lang']['email_address'] . " <span class='text-danger'>*</span></label>";
            echo "<input type='email' name='email-address' id='email-address' class='form-control' required>";
            echo '</div>';

            echo '<div class="mb-3">';
            echo "<label class='form-label' for='mobile-number'>" . $data['lang']['mobile_number'] . " <span class='text-danger'>*</span></label>";
            echo "<input type='text' name='mobile-number' id='mobile-number' class='form-control' required>";
            echo '</div>';

            echo '<div class="mb-3">';
            echo "<label class='form-label' for='mobile-number'>" . $data['lang']['amount'] . " <span class='text-danger'>*</span></label>";
            echo '
                        <div class="input-group mb-2">
                            <span class="input-group-text"> ' . $data['paymentLink']['currency'] . ' </span>
                            <input type="text" class="form-control" placeholder="Amount" value="' . money_round($data['paymentLink']['total'], 2) . '" autocomplete="off" readonly>
                        </div>';
            echo '</div>';

            foreach ($fields as $field) {
                $name = htmlspecialchars($field['name']);
                $label = htmlspecialchars($field['label']);
                $type = $field['type'];
                $required = (!empty($field['required']) && $field['required'] !== 'false') ? 'required' : '';

                echo '<div class="mb-3">';

                // Show label for all except checkbox (we put label inside input for checkbox)
                if ($type == 'checkbox') {
                    echo "<label class='form-label' for='{$name}'>{$label}";
                    if ($required)
                        echo ' <span class="text-danger">*</span>';
                    echo "</label>";
                }

                switch ($type) {

                    case 'text':
                        echo "<input type='text' name='{$name}' id='{$name}' class='form-control' {$required}>";
                        break;

                    case 'textarea':
                        echo "<textarea name='{$name}' id='{$name}' class='form-control' {$required}></textarea>";
                        break;

                    case 'select':
                        echo "<select name='{$name}' id='{$name}' class='form-control' {$required}>";
                        if (!empty($field['options'])) {
                            foreach ($field['options'] as $opt) {
                                echo "<option value='" . htmlspecialchars($opt) . "'>" . htmlspecialchars($opt) . "</option>";
                            }
                        }
                        echo "</select>";
                        break;

                    case 'checkbox':
                        if (!empty($field['options'])) {
                            foreach ($field['options'] as $opt) {

                                $optValue = htmlspecialchars($opt);
                                $optId = $name . '_' . preg_replace('/\s+/', '_', strtolower($opt));

                                echo "<div class='form-check'>";
                                echo "<input 
                                            type='checkbox'
                                            name='{$name}[]'
                                            id='{$optId}'
                                            class='form-check-input'
                                            value='{$optValue}'
                                            {$required}
                                        >";
                                echo "<label class='form-check-label' for='{$optId}'>{$optValue}</label>";
                                echo "</div>";
                            }
                        }
                        break;

                    case 'radio':
                        if (!empty($field['options'])) {
                            foreach ($field['options'] as $opt) {
                                $radioId = $name . '_' . preg_replace('/\s+/', '_', strtolower($opt));
                                echo "<div class='form-check'>";
                                echo "<input type='radio' name='{$name}' id='{$radioId}' class='form-check-input' value='" . htmlspecialchars($opt) . "' {$required}>";
                                echo "<label class='form-check-label' for='{$radioId}'>" . htmlspecialchars($opt) . "</label>";
                                echo "</div>";
                            }
                        }
                        break;

                    case 'file':
                        $accept = '';
                        if (!empty($field['options'])) {
                            $exts = array_map(function ($e) {
                                return "." . $e;
                            }, $field['options']);
                            $accept = 'accept="' . implode(',', $exts) . '"';
                        }
                        echo "<input type='file' name='{$name}' id='{$name}' class='form-control' {$accept} {$required}>";
                        break;

                    default:
                        echo "<input type='text' name='{$name}' id='{$name}' class='form-control' {$required}>";
                        break;
                }

                // Optional context hint (for invoice, payment_link, etc.)
                if (!empty($field['hint'])) {
                    echo "<small class='form-text text-muted'>{$field['hint']}</small>";
                }

                echo '</div>';
            }
        }

        if ($type == "payment-link-default") {
            $paymentLinkID = $data['paymentLink']['pid'] ?? '';
            $currency = $data['paymentLink']['currency'] ?? '';

            echo "<input type='hidden' name='action-v2' value='payment-link-default'>";
            echo "<input type='hidden' name='itemid' value='" . $paymentLinkID . "'>";

            echo '<div class="mb-3">';
            echo "<label class='form-label' for='full-name'>" . $data['lang']['full_name'] . " <span class='text-danger'>*</span></label>";
            echo "<input type='text' name='full-name' id='full-name' class='form-control' required>";
            echo '</div>';

            echo '<div class="mb-3">';
            echo "<label class='form-label' for='email-address'>" . $data['lang']['email_address'] . " <span class='text-danger'>*</span></label>";
            echo "<input type='email' name='email-address' id='email-address' class='form-control' required>";
            echo '</div>';

            echo '<div class="mb-3">';
            echo "<label class='form-label' for='mobile-number'>" . $data['lang']['mobile_number'] . " <span class='text-danger'>*</span></label>";
            echo "<input type='text' name='mobile-number' id='mobile-number' class='form-control' required>";
            echo '</div>';

            echo '<div class="mb-3">';
            echo "<label class='form-label' for='mobile-number'>" . $data['lang']['amount'] . " <span class='text-danger'>*</span></label>";
            echo '
                        <div class="input-group mb-2">
                            <span class="input-group-text"> ' . $data['paymentLink']['currency'] . ' </span>
                            <input type="number" name="amount" class="form-control" placeholder="Amount" value="0" autocomplete="off">
                        </div>';
            echo '</div>';
        }

        if ($type == "invoice") {
            $invoiceID = $data['invoice']['iid'] ?? '';

            echo "<input type='hidden' name='action-v2' value='invoice'>";
            echo "<input type='hidden' name='itemid' value='" . $invoiceID . "'>";
        }
    }
}
