<?php

declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\CrudService;
use OwnPay\Service\DateTimeService;
use OwnPay\Service\EnvironmentService;

/**
 * CurrencyUpdateJob — refresh exchange rates for brands with autoExchange enabled.
 *
 * For each eligible brand (throttled to once every 5 hours per brand), fetches
 * rates from the Fawaz Ahmed currency API via multi-cURL and updates the
 * per-brand `currency` table.
 *
 * Previously embedded in index.php (~70 lines).
 */
final class CurrencyUpdateJob
{
    private const THROTTLE_SECONDS = 5 * 3600;
    private const RATE_API_BASE = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/';
    private const CURL_TIMEOUT = 10;

    private string $dbPrefix;

    public function __construct(?string $dbPrefix = null)
    {
        $this->dbPrefix = $dbPrefix ?? ($_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_');
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $autoBrands = CrudService::select(
            $this->dbPrefix . 'brands',
            'WHERE autoExchange = :auto',
            '* FROM',
            [':auto' => 'enabled']
        );

        if ($autoBrands['status'] !== true || empty($autoBrands['response'])) {
            return ['brands_checked' => 0, 'rates_updated' => 0];
        }

        $multiHandle = curl_multi_init();
        /** @var array<int, \CurlHandle> $handles */
        $handles = [];
        /** @var array<int, array<string, mixed>> $brandMap */
        $brandMap = [];
        $now = DateTimeService::getCurrentDatetime('Y-m-d H:i:s');
        $skipped = 0;

        foreach ($autoBrands['response'] as $row) {
            $lastExchange = EnvironmentService::get('last-auto-exchange', (string) $row['brand_id']);

            if (empty($lastExchange)) {
                EnvironmentService::set('last-auto-exchange', $now, (string) $row['brand_id']);
                $skipped++;
                continue;
            }

            if (strtotime($now) - strtotime($lastExchange) < self::THROTTLE_SECONDS) {
                $skipped++;
                continue;
            }

            EnvironmentService::set('last-auto-exchange', $now, (string) $row['brand_id']);

            $url = self::RATE_API_BASE . strtolower((string) $row['currency_code']) . '.json';
            $ch = curl_init($url);

            if ($ch === false) {
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[] = $ch;
            $brandMap[spl_object_id($ch)] = $row;
        }

        // Drive concurrent requests to completion
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        $ratesUpdated = 0;

        foreach ($handles as $ch) {
            $row = $brandMap[spl_object_id($ch)] ?? null;
            $response = curl_multi_getcontent($ch);
            curl_multi_remove_handle($multiHandle, $ch);

            if ($row === null || !$response) {
                continue;
            }

            $data = json_decode($response, true);
            $code = strtolower((string) $row['currency_code']);

            if (!is_array($data) || !isset($data[$code]) || !is_array($data[$code])) {
                continue;
            }

            foreach ($data[$code] as $currency => $rate) {
                if ($currency === $code) {
                    continue;
                }
                if (!is_numeric($rate) || $rate <= 0) {
                    continue;
                }

                $converted = number_format(1 / (float) $rate, 4, '.', '');

                CrudService::update(
                    $this->dbPrefix . 'currency',
                    ['rate', 'updated_date'],
                    [$converted, $now],
                    'brand_id = :where_brand_id AND code = :where_code',
                    [':where_brand_id' => $row['brand_id'], ':where_code' => $currency]
                );
                $ratesUpdated++;
            }
        }

        curl_multi_close($multiHandle);

        return [
            'brands_checked' => count($autoBrands['response']),
            'brands_skipped' => $skipped,
            'rates_updated' => $ratesUpdated,
        ];
    }
}
