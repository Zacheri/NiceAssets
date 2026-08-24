<?php

declare(strict_types=1);

namespace App\Services;

final class Depreciation
{
    public const LIFETIME_MONTHS = 60;

    public static function monthsSince(?string $date): ?int
    {
        if ($date === null || $date === '' || $date === '0000-00-00') {
            return null;
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return null;
        }
        $years = (int) date('Y') - (int) date('Y', $ts);
        $months = (int) date('n') - (int) date('n', $ts);
        return max(0, $years * 12 + $months);
    }

    public static function calc(float $cost, ?string $purchaseDate): array
    {
        $monthly = $cost / self::LIFETIME_MONTHS;
        $months = self::monthsSince($purchaseDate);

        if ($months === null) {
            return [
                'annual' => $cost / 5,
                'monthly' => $monthly,
                'months_elapsed' => null,
                'accumulated' => 0.0,
                'value' => $cost,
                'remaining_months' => null,
                'fully' => false,
                'progress' => 0.0,
            ];
        }

        $elapsed = min($months, self::LIFETIME_MONTHS);
        $value = max(0.0, $cost - $monthly * $elapsed);

        return [
            'annual' => $cost / 5,
            'monthly' => $monthly,
            'months_elapsed' => $months,
            'accumulated' => $cost - $value,
            'value' => $value,
            'remaining_months' => max(0, self::LIFETIME_MONTHS - $months),
            'fully' => $months >= self::LIFETIME_MONTHS,
            'progress' => $cost > 0 ? min(100.0, round(($elapsed / self::LIFETIME_MONTHS) * 100, 1)) : 0.0,
        ];
    }
}
