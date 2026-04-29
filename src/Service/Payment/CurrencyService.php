<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

class CurrencyService
{
    public static function moneyToInt(string $amount, int $decimals = 2): int
    {
        $amount = self::money_sanitize($amount);
        $multiplier = bcpow("10", (string) $decimals);
        return (int) bcmul($amount, $multiplier, 0);
    }

    public static function intToMoney(int $amount, int $decimals = 2): string
    {
        $divisor = bcpow("10", (string) $decimals);
        return bcdiv((string) $amount, $divisor, $decimals);
    }

    public static function money_sanitize(string|int|float|null $value): string
    {
        if (is_numeric($value)) {
            return (string) $value;
        }
        return "0";
    }

    public static function money_add($a, $b, int $scale = 8): string
    {
        $a = self::money_sanitize($a);
        $b = self::money_sanitize($b);
        return bcadd($a, $b, $scale);
    }

    public static function money_sub($a, $b, int $scale = 8): string
    {
        $a = self::money_sanitize($a);
        $b = self::money_sanitize($b);
        return bcsub($a, $b, $scale);
    }

    public static function money_mul($a, $b, int $scale = 8): string
    {
        $a = self::money_sanitize($a);
        $b = self::money_sanitize($b);
        return bcmul($a, $b, $scale);
    }

    public static function money_div($a, $b, int $scale = 8): string
    {
        $a = self::money_sanitize($a);
        $b = self::money_sanitize($b);
        if (bccomp($b, '0', $scale) === 0) {
            return "0";
        }
        return bcdiv($a, $b, $scale);
    }

    public static function money_round($amount, int $decimals = 2): string
    {
        $amount = self::money_sanitize($amount);
        $factor = bcpow('10', (string) ($decimals + 1));
        $tmp = bcmul($amount, $factor, 0);
        $tmp = bcdiv($tmp, '10', 0);
        return bcdiv($tmp, bcpow('10', (string) $decimals), $decimals);
    }

    public static function verifyPaymentTolerance(string $checkout, string $paid, string $tolerance): bool
    {
        $checkout = self::money_round($checkout);
        $paid = self::money_round($paid);
        $tolerance = self::money_round($tolerance);

        if (bccomp($checkout, "0", 8) <= 0 || bccomp($paid, "0", 8) <= 0) {
            return false;
        }

        // max allowed = checkout + tolerance
        $maxAllowed = self::money_add($checkout, $tolerance);

        return (
            bccomp($paid, $checkout, 8) >= 0 &&
            bccomp($paid, $maxAllowed, 8) <= 0
        );
    }
}
