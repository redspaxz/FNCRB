<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Module 4 — COBAC asset classification & provisioning.
 * COBAC reference classification by days past due (simplified CEMAC convention):
 *   HEALTHY   0-30 dpd (current)
 *   WATCH     31-90
 *   UNCERTAIN 91-180
 *   DOUBTFUL  181-360
 *   COMPROMISED > 360 or written off
 * Restructured credits are floored at WATCH (never "healthy" while restructured).
 * Settled credits carry no risk class weight (outstanding 0 → provision 0).
 *
 * Single registry-wide definitions used by every report, dashboard and feed:
 *   - "open" portfolio = statuses ACTIVE + RESTRUCTURED (still outstanding);
 *   - NPL = open credit more than 90 days past due
 *           (classes UNCERTAIN, DOUBTFUL, COMPROMISED).
 */
final class ClassificationService
{
    public const CLASSES = ['HEALTHY', 'WATCH', 'UNCERTAIN', 'DOUBTFUL', 'COMPROMISED'];
    public const OPEN_STATUSES = ['ACTIVE', 'RESTRUCTURED'];
    public const NPL_CLASSES = ['UNCERTAIN', 'DOUBTFUL', 'COMPROMISED'];
    /** SQL fragments (constants only — never user input). */
    public const OPEN_SQL = "('ACTIVE','RESTRUCTURED')";
    public const NPL_SQL = "('UNCERTAIN','DOUBTFUL','COMPROMISED')";

    public static function classify(int $daysPastDue, string $status = 'ACTIVE'): string
    {
        if ($status === 'WRITTEN_OFF') return 'COMPROMISED';
        if ($status === 'SETTLED') return 'HEALTHY';
        $cls = match (true) {
            $daysPastDue <= 30  => 'HEALTHY',
            $daysPastDue <= 90  => 'WATCH',
            $daysPastDue <= 180 => 'UNCERTAIN',
            $daysPastDue <= 360 => 'DOUBTFUL',
            default             => 'COMPROMISED',
        };
        if ($status === 'RESTRUCTURED' && $cls === 'HEALTHY') $cls = 'WATCH';
        return $cls;
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

    public static function isNpl(string $class): bool
    {
        return in_array($class, self::NPL_CLASSES, true);
    }
}
