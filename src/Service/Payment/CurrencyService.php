<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

/**
 * Service for currency conversion, formatting, and exchange rate operations.
 *
 * All financial amounts are processed as decimal strings utilizing BCMath functions
 * to preserve numeric precision without floating-point inaccuracies.
 */
final class CurrencyService
{
    /**
     * Cache of active currencies.
     *
     * @var array<string, array{rate: string, symbol: string, decimals: int}>
     */
    private array $currencies = [];

    /**
     * The site's primary base currency code.
     *
     * @var string
     */
    private string $baseCurrency;

    /**
     * Initializes the currency service by retrieving the base currency and loading active rates.
     *
     * @param \OwnPay\Core\Database $db Core database interface.
     */
    public function __construct(private readonly \OwnPay\Core\Database $db)
    {
        $row = $this->db->fetchOne(
            "SELECT `value` FROM op_system_settings WHERE `group_name` = 'general' AND `key_name` = 'base_currency' LIMIT 1"
        );
        $val = $row['value'] ?? '';
        $this->baseCurrency = is_string($val) && $val !== '' ? $val : 'USD';
        $this->loadCurrencies();
    }

    /**
     * Converts an amount from one currency to another using high-precision BCMath math operations.
     *
     * @param string $amount The numeric amount to convert.
     * @param string $from The source currency ISO 4217 code.
     * @param string $to The target currency ISO 4217 code.
     * @return string The converted amount as a decimal string.
     * @throws \RuntimeException If exchange rates for the currencies are not configured.
     */
    public function convert(string $amount, string $from, string $to): string
    {
        if ($from === $to) {
            return $amount;
        }

        $fromRate = $this->getRate($from);
        $toRate = $this->getRate($to);

        if ($fromRate === '0' || $toRate === '0') {
            throw new \RuntimeException("Exchange rate not available for {$from}/{$to}");
        }

        /** @var numeric-string $amount */
        /** @var numeric-string $fromRate */
        /** @var numeric-string $toRate */
        // Convert to base, then to target
        $baseAmount = bcdiv($amount, $fromRate, 8);
        /** @var numeric-string $baseAmount */
        return bcmul($baseAmount, $toRate, $this->getDecimals($to));
    }

    /**
     * Formats the given amount with the corresponding currency symbol and decimal configuration.
     *
     * @param string $amount The numeric amount to format.
     * @param string $currency The target currency ISO 4217 code.
     * @return string The formatted currency representation (e.g., "$100.00").
     */
    public function format(string $amount, string $currency): string
    {
        $symbol = $this->getSymbol($currency);
        $decimals = $this->getDecimals($currency);
        $formatted = number_format((float) $amount, $decimals, '.', ',');
        return $symbol . $formatted;
    }

    /**
     * Retrieves the current exchange rate for a currency relative to the base currency.
     *
     * @param string $currency The currency ISO 4217 code.
     * @return string The exchange rate as a decimal string, or '0' if not found.
     */
    public function getRate(string $currency): string
    {
        return $this->currencies[$currency]['rate'] ?? '0';
    }

    /**
     * Retrieves the currency symbol for the specified currency code.
     *
     * @param string $currency The currency ISO 4217 code.
     * @return string The currency symbol (e.g., "$", "৳"), or code with space suffix if not found.
     */
    public function getSymbol(string $currency): string
    {
        return $this->currencies[$currency]['symbol'] ?? $currency . ' ';
    }

    /**
     * Retrieves the decimal place precision configured for the specified currency.
     *
     * @param string $currency The currency ISO 4217 code.
     * @return int The number of decimal places (defaults to 2).
     */
    public function getDecimals(string $currency): int
    {
        return $this->currencies[$currency]['decimals'] ?? 2;
    }

    /**
     * Returns an array of all active supported currency codes.
     *
     * @return string[] List of active currency ISO 4217 codes.
     */
    public function supported(): array
    {
        return array_keys($this->currencies);
    }

    /**
     * Validates that an amount is positive and does not exceed the target currency's decimal precision.
     *
     * @param string $amount The numeric amount string.
     * @param string $currency The currency ISO 4217 code.
     * @return bool True if the amount is numeric, positive, and matches precision rules; false otherwise.
     */
    public function validateAmount(string $amount, string $currency): bool
    {
        if (!is_numeric($amount)) {
            return false;
        }
        /** @var numeric-string $amount */
        if (bccomp($amount, '0', 8) <= 0) {
            return false;
        }
        // Check decimal places
        $parts = explode('.', $amount);
        if (isset($parts[1]) && strlen($parts[1]) > $this->getDecimals($currency)) {
            return false;
        }
        return true;
    }

    /**
     * Loads active currencies and exchange rates from the database into the in-memory cache.
     *
     * @return void
     */
    private function loadCurrencies(): void
    {
        $rows = $this->db->fetchAll("SELECT code, symbol, decimal_places FROM op_currencies WHERE status = 'active'");
        $rates = $this->db->fetchAll("SELECT base_currency, target_currency, rate FROM op_exchange_rates");

        foreach ($rows as $row) {
            $code = $row['code'] ?? '';
            $symbol = $row['symbol'] ?? '';
            $decimalPlaces = $row['decimal_places'] ?? 2;
            if (is_string($code) && $code !== '' && is_string($symbol)) {
                $this->currencies[$code] = [
                    'rate' => '0',
                    'symbol' => $symbol,
                    'decimals' => is_scalar($decimalPlaces) ? (int) $decimalPlaces : 2,
                ];
            }
        }

        // Apply exchange rates
        foreach ($rates as $rate) {
            $base = $rate['base_currency'] ?? '';
            $target = $rate['target_currency'] ?? '';
            $rateVal = $rate['rate'] ?? '0';
            if (is_string($base) && is_string($target) && is_scalar($rateVal)) {
                if ($base === $this->baseCurrency && isset($this->currencies[$target])) {
                    $this->currencies[$target]['rate'] = (string) $rateVal;
                }
            }
        }

        // Base currency always rate 1
        if (isset($this->currencies[$this->baseCurrency])) {
            $this->currencies[$this->baseCurrency]['rate'] = '1.00000000';
        }
    }

    /**
     * Creates a new currency or updates an existing currency definition in the database.
     *
     * @param string $code The currency ISO 4217 code.
     * @param string $name The friendly name of the currency.
     * @param string $symbol The symbol character or string.
     * @param string $status The status of the currency ('active' or 'inactive').
     * @param int $decimalPlaces The currency precision (number of decimal places).
     * @return void
     */
    public function upsert(string $code, string $name, string $symbol, string $status = 'active', int $decimalPlaces = 2): void
    {
        $exists = $this->db->fetchOne("SELECT id FROM op_currencies WHERE code = :code", ['code' => $code]);
        if ($exists) {
            $this->db->execute(
                "UPDATE op_currencies SET name = :name, symbol = :sym, status = :st, decimal_places = :dp WHERE code = :code",
                ['name' => $name, 'sym' => $symbol, 'st' => $status, 'dp' => $decimalPlaces, 'code' => $code]
            );
        } else {
            $this->db->execute(
                "INSERT INTO op_currencies (code, name, symbol, status, decimal_places) VALUES (:code, :name, :sym, :st, :dp)",
                ['code' => $code, 'name' => $name, 'sym' => $symbol, 'st' => $status, 'dp' => $decimalPlaces]
            );
        }
    }

    /**
     * Retrieves all currency codes, names, symbols, decimals, status, and exchange rates.
     *
     * @return array<int, array<string, mixed>> List of currency metadata arrays.
     */
    public function listAll(): array
    {
        $currencies = $this->db->fetchAll("SELECT code, name, symbol, decimal_places, status FROM op_currencies ORDER BY code");
        $rates = $this->db->fetchAll(
            "SELECT target_currency, rate FROM op_exchange_rates WHERE base_currency = :base",
            ['base' => $this->baseCurrency]
        );
        $rateMap = [];
        foreach ($rates as $r) {
            $tc = $r['target_currency'] ?? '';
            if (is_string($tc)) {
                $rateMap[strtoupper($tc)] = $r['rate'] ?? '0';
            }
        }
        foreach ($currencies as &$c) {
            $code = $c['code'] ?? '';
            if (is_string($code)) {
                $c['rate'] = $rateMap[strtoupper($code)] ?? '0';
            } else {
                $c['rate'] = '0';
            }
        }
        unset($c);
        return $currencies;
    }

    /**
     * Updates or creates the exchange rate for the specified target currency.
     *
     * @param string $targetCurrency The target currency ISO 4217 code.
     * @param string $rate The exchange rate relative to the base currency.
     * @return void
     */
    public function updateExchangeRate(string $targetCurrency, string $rate): void
    {
        $exists = $this->db->fetchOne(
            "SELECT id FROM op_exchange_rates WHERE base_currency = :base AND target_currency = :target",
            ['base' => $this->baseCurrency, 'target' => $targetCurrency]
        );
        if ($exists) {
            $this->db->execute(
                "UPDATE op_exchange_rates SET rate = :rate WHERE base_currency = :base AND target_currency = :target",
                ['rate' => $rate, 'base' => $this->baseCurrency, 'target' => $targetCurrency]
            );
        } else {
            $this->db->execute(
                "INSERT INTO op_exchange_rates (base_currency, target_currency, rate) VALUES (:base, :target, :rate)",
                ['base' => $this->baseCurrency, 'target' => $targetCurrency, 'rate' => $rate]
            );
        }
        // Reload in-memory cache
        if (isset($this->currencies[$targetCurrency])) {
            $this->currencies[$targetCurrency]['rate'] = $rate;
        }
    }

    /**
     * Instantly triggers the exchange rate synchronization job.
     *
     * @return array{success: bool, updated?: int, skipped?: bool, error?: string}
     */
    public function syncRates(): array
    {
        $job = new \OwnPay\Cron\CurrencyUpdateJob($this->db);
        $res = $job->run(true); // force = true to bypass manual mode check
        $this->loadCurrencies(); // Reload in-memory cache
        return $res;
    }
}

