<?php
declare(strict_types=1);
/**
 * CLI: full audit-chain verification (recomputes every hash from the stored
 * content). Schedule nightly; exits non-zero on a broken chain so cron /
 * monitoring alerts. The web Audit page only verifies incrementally.
 *
 * Usage: php scripts/verify_audit_chain.php
 */
require __DIR__ . '/_cli.php';

$r = \App\Core\Audit::verify(true);
\App\Core\Audit::log('AUDIT_CHAIN_VERIFIED', null, [
    'ok' => $r['ok'], 'checked' => $r['checked'], 'broken_at' => $r['broken_at'], 'legacy_rows' => $r['legacy_rows'], 'channel' => 'cli',
]);
if ($r['ok']) {
    echo "Audit chain intact: {$r['checked']} entries verified ({$r['legacy_rows']} legacy link-only).\n";
    exit(0);
}
fwrite(STDERR, "AUDIT CHAIN BROKEN at #{$r['broken_at']}: {$r['reason']}\n");
exit(2);
