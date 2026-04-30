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
    private string $baseCurrency = 'USD';

    public function __construct(private readonly \OwnPay\Core\Database $db)
    {
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
        $rows = $this->db->fetchAll("SELECT code, symbol, decimals FROM op_currencies WHERE status = 'active'");
        $rates = $this->db->fetchAll("SELECT from_currency, to_currency, rate FROM op_exchange_rates");

        foreach ($rows as $row) {
            $this->currencies[$row['code']] = [
                'rate' => '1.00000000', // Default
                'symbol' => $row['symbol'],
                'decimals' => (int) $row['decimals'],
            ];
        }

        // Apply exchange rates
        foreach ($rates as $rate) {
            if ($rate['from_currency'] === $this->baseCurrency && isset($this->currencies[$rate['to_currency']])) {
                $this->currencies[$rate['to_currency']]['rate'] = $rate['rate'];
            }
        }

        // Base currency always rate 1
        if (isset($this->currencies[$this->baseCurrency])) {
            $this->currencies[$this->baseCurrency]['rate'] = '1.00000000';
        }
    }
}
