<?php
declare(strict_types=1);
// CLI: generate an institution API key and store its SHA-256 hash.
// Usage: php scripts/make_api_key.php <institution_code>
require dirname(__DIR__) . '/app/bootstrap.php';

$code = $argv[1] ?? null;
if (!$code) { fwrite(STDERR, "Usage: php scripts/make_api_key.php <institution_code>\n"); exit(1); }

$stmt = App\Core\Database::pdo()->prepare("SELECT id FROM institutions WHERE code = ?");
$stmt->execute([$code]);
$id = $stmt->fetchColumn();
if (!$id) { fwrite(STDERR, "Institution not found: $code\n"); exit(1); }

$key = 'fncrb_' . bin2hex(random_bytes(24));
$upd = App\Core\Database::pdo()->prepare("UPDATE institutions SET api_key_hash = ? WHERE id = ?");
$upd->execute([hash('sha256', $key), $id]);

echo "API key for $code (shown once, store securely):\n$key\n";
