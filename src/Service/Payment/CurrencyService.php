<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

/**
 * Currency service — conversion, formatting, exchange rates.
 *
 * All amounts stored as DECIMAL(18,2) strings using bcmath.
 */
final class CurrencyService
{
    /** @var array<string, array{rate: string, symbol: string, decimals: int}> */
    private array $currencies = [];
    private string $baseCurrency;

    public function __construct(private readonly \OwnPay\Core\Database $db)
    {
        // AUD-C7 fix: load base currency from system settings instead of hardcoding USD
        $row = $this->db->fetchOne(
            "SELECT `value` FROM op_system_settings WHERE `group_name` = 'general' AND `key_name` = 'base_currency' LIMIT 1"
        );
        $this->baseCurrency = ($row['value'] ?? '') !== '' ? $row['value'] : 'USD';
        $this->loadCurrencies();
    }

    /**
     * Convert amount between currencies.
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

        // Convert to base, then to target
        $baseAmount = bcdiv($amount, $fromRate, 8);
        return bcmul($baseAmount, $toRate, $this->getDecimals($to));
    }

    /**
     * Format amount with currency symbol.
     */
    public function format(string $amount, string $currency): string
    {
        $symbol = $this->getSymbol($currency);
        $decimals = $this->getDecimals($currency);
        $formatted = number_format((float) $amount, $decimals, '.', ',');
        return $symbol . $formatted;
    }

    /**
     * Get exchange rate to base currency.
     */
    public function getRate(string $currency): string
    {
        return $this->currencies[$currency]['rate'] ?? '0';
    }

    public function getSymbol(string $currency): string
    {
        return $this->currencies[$currency]['symbol'] ?? $currency . ' ';
    }

    public function getDecimals(string $currency): int
    {
        return $this->currencies[$currency]['decimals'] ?? 2;
    }

    /**
     * Get all supported currencies.
     * @return string[]
     */
    public function supported(): array
    {
        return array_keys($this->currencies);
    }

    /**
     * Validate amount is positive and has correct precision.
     */
    public function validateAmount(string $amount, string $currency): bool
    {
        if (!is_numeric($amount)) {
            return false;
        }
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

    private function loadCurrencies(): void
    {
        $rows = $this->db->fetchAll("SELECT code, symbol, decimal_places FROM op_currencies WHERE status = 'active'");
        $rates = $this->db->fetchAll("SELECT base_currency, target_currency, rate FROM op_exchange_rates");

        foreach ($rows as $row) {
            $this->currencies[$row['code']] = [
                'rate' => '0', // AUD-C7 fix: default to '0' — missing rate triggers explicit error in convert()
                'symbol' => $row['symbol'],
                'decimals' => (int) $row['decimal_places'],
            ];
        }

        // Apply exchange rates
        foreach ($rates as $rate) {
            if ($rate['base_currency'] === $this->baseCurrency && isset($this->currencies[$rate['target_currency']])) {
                $this->currencies[$rate['target_currency']]['rate'] = $rate['rate'];
            }
        }

        // Base currency always rate 1
        if (isset($this->currencies[$this->baseCurrency])) {
            $this->currencies[$this->baseCurrency]['rate'] = '1.00000000';
        }
    }

    /**
     * Create or update a currency.
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
     * List all currencies (for admin settings).
     */
    public function listAll(): array
    {
        return $this->db->fetchAll("SELECT code, name FROM op_currencies ORDER BY code");
    }

    /**
     * Update exchange rate for a currency (admin settings).
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
}
