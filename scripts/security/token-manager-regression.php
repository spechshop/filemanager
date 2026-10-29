<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/plugins/Request/components/privateJson.php';
require dirname(__DIR__, 2) . '/plugins/Request/components/securityPolicy.php';
require dirname(__DIR__, 2) . '/plugins/Request/components/tokenManager.php';
use plugins\Request\{privateJson, securityPolicy, tokenManager};
$directory = sys_get_temp_dir() . '/fm-token-manager-' . bin2hex(random_bytes(10));
mkdir($directory, 0700);
$file = $directory . '/tokens.json';
putenv('FILEMANAGER_TOKENS_FILE=' . $file);
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function denied(callable $operation, string $exception): void {
    try { $operation(); } catch (Throwable $error) { if ($error instanceof $exception) return; throw $error; }
    throw new RuntimeException('Expected rejection: ' . $exception);
}
function row(string $key): array {
    foreach (tokenManager::listing('admin-fixture')['tokens'] as $record) if ($record['id'] === hash('sha256', $key)) return $record;
    throw new RuntimeException('Missing record');
}
function operate(string $key, string $action, array $extra = []): array {
    $row = row($key);
    return tokenManager::mutate('admin-fixture', array_merge(['action' => $action, 'id' => $row['id'], 'revision' => $row['revision']], $extra));
}
try {
    privateJson::update($file, fn() => [
        'admin-fixture' => ['expire' => time() + 86400, 'nameClient' => 'Admin'],
        'restricted-fixture' => ['expire' => time() + 86400, 'allowedRoots' => [$directory]],
        'expired-fixture' => ['expire' => (time() - 60) * 1000],
    ]);
    $listing = tokenManager::listing('admin-fixture');
    check(!str_contains(json_encode($listing), 'admin-fixture'), 'Listing leaked secret');
    check(row('restricted-fixture')['terminal'] === false, 'Legacy scoped terminal permission changed');
    denied(fn() => tokenManager::listing('restricted-fixture'), DomainException::class);
    denied(fn() => tokenManager::mutate('restricted-fixture', ['action' => 'clean']), DomainException::class);
    $fields = ['nameClient' => '<img src=x onerror=alert(1)>', 'expire' => (string) (time() + 3600), 'admin' => 'false', 'terminal' => 'false', 'allowedRoots' => $directory];
    $created = tokenManager::mutate('admin-fixture', ['action' => 'create'] + $fields);
    $key = $created['secret'];
    check(strlen($key) === 64 && securityPolicy::validToken($key), 'Creation failed');
    check(!securityPolicy::adminAllowed($key) && !securityPolicy::terminalAllowed($key), 'Permissions changed');
    check(securityPolicy::roots($key) === [$directory], 'Scope changed');
    $stale = row($key);
    operate($key, 'update', array_replace($fields, ['nameClient' => 'Renamed', 'terminal' => 'true']));
    check(row($key)['nameClient'] === 'Renamed' && securityPolicy::terminalAllowed($key), 'Update failed');
    operate($key, 'revoke');
    check(!securityPolicy::validToken($key), 'Revocation failed');
    $before = file_get_contents($file);
    denied(fn() => tokenManager::mutate('admin-fixture', ['action' => 'update', 'id' => $stale['id'], 'revision' => $stale['revision']] + $fields), UnexpectedValueException::class);
    check(file_get_contents($file) === $before, 'Stale update changed store');
    operate($key, 'restore');
    check(securityPolicy::validToken($key), 'Restoration failed');
    check(operate($key, 'reveal')['secret'] === $key, 'Reveal failed');
    $rotated = operate($key, 'rotate')['secret'];
    check(!securityPolicy::validToken($key) && securityPolicy::validToken($rotated), 'Rotation failed');
    foreach (['revoke', 'rotate', 'delete'] as $action) denied(fn() => operate('admin-fixture', $action), InvalidArgumentException::class);
    denied(fn() => operate('admin-fixture', 'update', $fields), InvalidArgumentException::class);
    denied(fn() => tokenManager::mutate('admin-fixture', ['action' => 'create'] + array_replace($fields, ['allowedRoots' => ''])), InvalidArgumentException::class);
    denied(fn() => tokenManager::mutate('admin-fixture', ['action' => 'create'] + array_replace($fields, ['expire' => '1'])), InvalidArgumentException::class);
    tokenManager::mutate('admin-fixture', ['action' => 'clean']);
    check(!isset(privateJson::read($file)['expired-fixture']), 'Expired cleanup failed');
    operate($rotated, 'delete');
    check(!securityPolicy::validToken($rotated), 'Delete failed');
    check((fileperms($file) & 0077) === 0, 'Token store is not private');
    file_put_contents($file, '{invalid');
    denied(fn() => tokenManager::mutate('admin-fixture', ['action' => 'clean']), JsonException::class);
    check(file_get_contents($file) === '{invalid', 'Corrupt evidence overwritten');
    echo "PASS token management: permissions, lifecycle, stale updates, self protection, corruption and private storage\n";
} finally {
    foreach (glob($directory . '/*') as $entry) unlink($entry);
    rmdir($directory);
    putenv('FILEMANAGER_TOKENS_FILE');
}
