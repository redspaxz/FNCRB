<?php
declare(strict_types=1);
/**
 * Unit tests for pure domain logic (no database, no web server).
 *   php tests/unit.php
 */
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Export;
use App\Core\Totp;
use App\Core\Validator;
use App\Services\ClassificationService as CS;
use App\Services\ReconciliationService as RS;

$pass = 0; $fail = 0;
function t(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . "\n";
}

echo "Classification\n";
t('0 dpd healthy', CS::classify(0) === 'HEALTHY');
t('31 dpd watch', CS::classify(31) === 'WATCH');
t('91 dpd uncertain (NPL)', CS::classify(91) === 'UNCERTAIN' && CS::isNpl('UNCERTAIN'));
t('90 dpd not NPL', !CS::isNpl(CS::classify(90)));
t('361 dpd compromised', CS::classify(361) === 'COMPROMISED');
t('written off compromised', CS::classify(0, 'WRITTEN_OFF') === 'COMPROMISED');
t('restructured floored at watch', CS::classify(0, 'RESTRUCTURED') === 'WATCH');
t('settled healthy', CS::classify(400, 'SETTLED') === 'HEALTHY');
t('provision 40% doubtful', CS::provision('DOUBTFUL', 1000) === 400);

echo "TOTP\n";
$sec = Totp::generateSecret();
$now = 1_800_000_000;
$code = Totp::code($sec, $now);
$step = Totp::verifyStep($sec, $code, null, $now);
t('valid code accepted', $step === intdiv($now, 30));
t('same code replay refused', Totp::verifyStep($sec, $code, $step, $now) === null);
t('next window code accepted after last step', Totp::verifyStep($sec, Totp::code($sec, $now + 30), $step, $now + 30) === $step + 1);
t('garbage refused', Totp::verifyStep($sec, 'abcdef') === null);
t('RFC 6238 vector (SHA1, T=59)', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59) === '287082');

echo "Crypto\n";
$enc = Crypto::encrypt('JBSWY3DPEHPK3PXP');
t('round trip', Crypto::decrypt($enc) === 'JBSWY3DPEHPK3PXP' && str_starts_with($enc, 'enc:v1:'));
t('legacy plaintext passthrough', Crypto::decrypt('JBSWY3DPEHPK3PXP') === 'JBSWY3DPEHPK3PXP');
t('tampered ciphertext rejected', Crypto::decrypt(substr($enc, 0, -4) . 'AAAA') === null);

echo "Validator\n";
t('int from string', Validator::int(['a' => '42'], 'a', 0) === 42);
t('int rejects array', Validator::int(['a' => [1]], 'a') === null);
t('int rejects float string', Validator::int(['a' => '1.5'], 'a') === null);
t('date strict', Validator::date(['d' => '2026-02-30'], 'd') === null && Validator::date(['d' => '2026-02-28'], 'd') === '2026-02-28');
t('identifier charset', Validator::identifier(['x' => 'RCCM/DLA-2023.01'], 'x') !== null && Validator::identifier(['x' => "a'b"], 'x') === null);
t('generated password meets policy', Validator::password(Validator::generatePassword()));

echo "Identity matching\n";
t('word order + accents', RS::namesMatch('Étienne TABI', 'Tabi Etienne'));
t('initial', RS::namesMatch('E. Tabi', 'Etienne Tabi'));
t('one-letter typo', RS::namesMatch('Clarisse Abena', 'Clarise Abena'));
t('corporate suffix ignored', RS::namesMatch('Société Bâti-Plus SARL', 'Societe Bati Plus'));
t('different person refused', !RS::namesMatch('John Doe', 'Etienne Tabi'));
t('shared surname only refused', !RS::namesMatch('Paul Tabi', 'Etienne Tabi'));

echo "Export\n";
t('formula neutralized', Export::safeCell('=1+1') === "'=1+1" && Export::safeCell('@SUM(A1)') === "'@SUM(A1)");
t('plain text untouched', Export::safeCell('Tabi') === 'Tabi' && Export::safeCell(-5) === -5);
$csv = Export::buildCsv([['=cmd', 'ok']], ['A', 'B']);
t('csv row neutralized', str_contains($csv, "'=cmd"));
$x = Export::buildXlsx('S', ['H'], [['v', 1]]);
$tmp = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($tmp, $x);
$z = new ZipArchive(); $z->open($tmp);
t('xlsx has styles part', $z->locateName('xl/styles.xml') !== false);

echo "Audit hashing\n";
$row = ['action' => 'X', 'entity' => 'loan', 'entity_id' => '5', 'details' => Audit::canonicalJson(['b' => 1, 'a' => ['z' => 2, 'y' => 1]]),
        'user_id' => '3', 'institution_id' => null, 'ip_address' => '127.0.0.1', 'ts_ms' => '1700000000000', 'prev_hash' => str_repeat('0', 64)];
$h = Audit::hashRow($row);
$row2 = $row; $row2['user_id'] = 3; $row2['ts_ms'] = 1700000000000;
$row2['details'] = '{"a": {"y": 1, "z": 2}, "b": 1}'; // DB-normalized JSON (key order / spacing)
t('hash stable across type/JSON normalization', Audit::hashRow($row2) === $h);
$row3 = $row; $row3['details'] = Audit::canonicalJson(['b' => 2]);
t('content change changes hash', Audit::hashRow($row3) !== $h);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
