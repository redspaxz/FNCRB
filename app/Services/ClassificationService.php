<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Module 4 — COBAC asset classification & provisioning.
 * COBAC reference classification by days past due (simplified CEMAC convention):
 *   HEALTHY  0-30 dpud (current)
 *   WATCH    31-90
 *   UNCERTAIN 91-180
 *   DOUBTFUL 181-360
 *   COMPROMISED > 360 or written off
 * Provisioning rates on outstanding balance.
 */
final class ClassificationService
{
    public static function classify(int $daysPastDue, string $status = 'ACTIVE'): string
    {
        if ($status === 'WRITTEN_OFF') return 'COMPROMISED';
        return match (true) {
            $daysPastDue <= 30  => 'HEALTHY',
            $daysPastDue <= 90  => 'WATCH',
            $daysPastDue <= 180 => 'UNCERTAIN',
            $daysPastDue <= 360 => 'DOUBTFUL',
            default             => 'COMPROMISED',
        };
    }

    public static function provisionRate(string $class): float
    {
        return match ($class) {
            'HEALTHY'    => 0.00,
            'WATCH'      => 0.05,
            'UNCERTAIN'  => 0.20,
            'DOUBTFUL'   => 0.40,
            'COMPROMISED' => 1.00,
            default      => 0.00,
        };
    }

    public static function provision(string $class, int $outstandingXaf): int
    {
        return (int) round($outstandingXaf * self::provisionRate($class));
    }
}
