<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/manage-tokens.php';
$tmp = sys_get_temp_dir() . '/fm-token-storage-' . bin2hex(random_bytes(10));
mkdir($tmp, 0700); $file = $tmp . '/tokens.json';
try {
    file_put_contents($file, json_encode(['fixture' => ['expire' => 9999999999]]));
    $loaded = loadTokens($file);
    file_put_contents($file, json_encode(['fixture' => ['expire' => 9999999999, 'revoked' => true]]));
    $before = file_get_contents($file);
    ob_start(); $ok = saveTokens($file, $loaded); ob_end_clean();
    if ($ok || file_get_contents($file) !== $before) throw new RuntimeException('Stale editor restored a revoked token');
    $loaded = loadTokens($file); $loaded['another-fixture'] = ['expire' => 9999999999];
    if (!saveTokens($file, $loaded) || empty(loadTokens($file)['fixture']['revoked'])) throw new RuntimeException('Fresh transaction lost revocation');
    file_put_contents($file, '{invalid');
    $denied = false;
    ob_start(); try { loadTokens($file); } catch (RuntimeException $error) { $denied = true; } finally { ob_end_clean(); }
    if (!$denied || file_get_contents($file) !== '{invalid') throw new RuntimeException('Corrupt token evidence changed');
    echo "PASS stale token editors cannot undo revocations; malformed stores are preserved\n";
} finally { foreach (glob($tmp . '/*') as $entry) unlink($entry); rmdir($tmp); }
