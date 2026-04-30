<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\System\HttpClient;

/**
 * Currency update job — fetches exchange rates from external API.
 */
final class CurrencyUpdateJob
{
    private \OwnPay\Core\Database $db;
    private HttpClient $http;

    public function __construct(\OwnPay\Core\Database $db, ?HttpClient $http = null)
    {
        $this->db = $db;
        $this->http = $http ?? new HttpClient(10, 5);
    }

    public function run(): array
    {
        $apiUrl = getenv('EXCHANGE_RATE_API_URL') ?: 'https://api.exchangerate-api.com/v4/latest/USD';

        try {
            $response = $this->http->get($apiUrl);
            if ($response['status'] !== 200) {
                return ['success' => false, 'error' => "HTTP {$response['status']}"];
            }

            $data = json_decode($response['body'], true);
            $rates = $data['rates'] ?? [];
            $updated = 0;

            foreach ($rates as $currency => $rate) {
                $exists = $this->db->fetchOne(
                    "SELECT id FROM op_exchange_rates WHERE from_currency = 'USD' AND to_currency = :cur",
                    ['cur' => $currency]
                );

                if ($exists) {
                    $this->db->update(
                        "UPDATE op_exchange_rates SET rate = :rate, updated_at = NOW()
                         WHERE from_currency = 'USD' AND to_currency = :cur",
                        ['rate' => (string) $rate, 'cur' => $currency]
                    );
                } else {
                    $this->db->insert(
                        "INSERT INTO op_exchange_rates (from_currency, to_currency, rate, updated_at)
                         VALUES ('USD', :cur, :rate, NOW())",
                        ['cur' => $currency, 'rate' => (string) $rate]
                    );
                }
                $updated++;
            }

            return ['success' => true, 'updated' => $updated];

        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
