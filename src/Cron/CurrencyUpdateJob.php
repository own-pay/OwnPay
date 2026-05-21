<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\System\HttpClient;

/**
 * Class CurrencyUpdateJob
 *
 * Enterprise cron job executing currency exchange rate updates for multi-currency transaction operations.
 * Synchronizes the `op_exchange_rates` table with external exchange rate services to enable
 * real-time, gateway-scoped currency conversions during checkout processes.
 *
 * @package OwnPay\Cron
 */
final class CurrencyUpdateJob
{
    /**
     * @var \OwnPay\Core\Database The database connection instance.
     */
    private \OwnPay\Core\Database $db;

    /**
     * @var HttpClient HTTP client used to fetch rate feeds from the remote provider.
     */
    private HttpClient $http;

    /**
     * CurrencyUpdateJob constructor.
     *
     * @param \OwnPay\Core\Database $db   The database connection instance.
     * @param HttpClient|null      $http Optional HTTP client service.
     */
    public function __construct(\OwnPay\Core\Database $db, ?HttpClient $http = null)
    {
        $this->db = $db;
        $this->http = $http ?? new HttpClient(10, 5);
    }

    /**
     * Runs the exchange rate synchronisation process.
     *
     * Fetches current USD-based rates, parses the payload, and dynamically inserts or updates
     * matching records in `op_exchange_rates` under strict float representation standards.
     *
     * @return array{success: bool, updated?: int, error?: string} The synchronization result payload.
     */
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
                    "SELECT id FROM op_exchange_rates WHERE base_currency = 'USD' AND target_currency = :cur",
                    ['cur' => $currency]
                );

                if ($exists) {
                    $this->db->update(
                        "UPDATE op_exchange_rates SET rate = :rate, updated_at = NOW()
                         WHERE base_currency = 'USD' AND target_currency = :cur",
                        ['rate' => (string) $rate, 'cur' => $currency]
                    );
                } else {
                    $this->db->insert(
                        "INSERT INTO op_exchange_rates (base_currency, target_currency, rate, updated_at)
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
