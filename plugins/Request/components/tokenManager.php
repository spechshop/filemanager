<?php
declare(strict_types=1);
namespace plugins\Request;

/** Administrative token operations share the CLI editor's atomic storage lock. */
final class tokenManager
{
    private static function admin(array $record): bool
    {
        return empty($record['revoked']) && securityPolicy::expiration($record['expire'] ?? null) > time()
            && ($record['admin'] ?? (!array_key_exists('allowedRoots', $record) && ($record['terminal'] ?? true) === true)) === true;
    }

    private static function revision(array $record): string
    {
        return hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public static function listing(string $actor): array
    {
        securityPolicy::requireAdmin($actor);
        $tokens = privateJson::read(securityPolicy::tokenFile());
        $result = [];
        foreach ($tokens as $key => $record) {
            if (!is_array($record)) throw new \RuntimeException('Cadastro de tokens inválido.');
            $expire = securityPolicy::expiration($record['expire'] ?? null);
            $result[] = [
                'id' => hash('sha256', (string) $key), 'revision' => self::revision($record),
                'preview' => strlen((string) $key) >= 16 ? substr((string) $key, 0, 4) . '…' . substr((string) $key, -4) : '••••••••',
                'nameClient' => (string) ($record['nameClient'] ?? ''), 'expire' => $expire,
                'revoked' => !empty($record['revoked']),
                'status' => !empty($record['revoked']) ? 'revoked' : ($expire <= time() ? 'expired' : 'active'),
                'admin' => ($record['admin'] ?? (!array_key_exists('allowedRoots', $record) && ($record['terminal'] ?? true) === true)) === true,
                'terminal' => ($record['terminal'] ?? !array_key_exists('allowedRoots', $record)) === true,
                'allowedRoots' => $record['allowedRoots'] ?? null, 'current' => (string) $key === $actor,
            ];
        }
        securityPolicy::requireAdmin($actor);
        return ['success' => true, 'tokens' => $result];
    }

    public static function mutate(string $actor, array $input): array
    {
        $action = $input['action'] ?? '';
        if (!is_string($action) || !in_array($action, ['create', 'update', 'revoke', 'restore', 'rotate', 'delete', 'clean', 'reveal'], true)) {
            throw new \InvalidArgumentException('Ação de token inválida.');
        }
        $secret = null;
        privateJson::update(securityPolicy::tokenFile(), static function (array $tokens) use ($actor, $input, $action, &$secret): array {
            // Recheck authorization under the same lock used by every mutation.
            if (!isset($tokens[$actor]) || !self::admin($tokens[$actor])) throw new \DomainException('Permissão administrativa removida.');
            $key = null;
            if (!in_array($action, ['create', 'clean'], true)) {
                foreach ($tokens as $candidate => $_) {
                    if (is_string($input['id'] ?? null) && hash_equals(hash('sha256', (string) $candidate), $input['id'])) { $key = $candidate; break; }
                }
                if ($key === null) throw new \InvalidArgumentException('Token não encontrado. Atualize a lista.');
                if (!is_string($input['revision'] ?? null) || !hash_equals(self::revision($tokens[$key]), $input['revision'])) {
                    throw new \UnexpectedValueException('Este token foi alterado. Atualize a lista antes de continuar.');
                }
                if ($key === $actor && in_array($action, ['revoke', 'rotate', 'delete'], true)) {
                    throw new \InvalidArgumentException('Use outro token administrativo para alterar a chave ou remover o acesso atual.');
                }
            }
            if ($action === 'create' || $action === 'update') {
                $name = $input['nameClient'] ?? null;
                $expire = securityPolicy::expiration($input['expire'] ?? null);
                if (!is_string($name) || trim($name) === '' || strlen($name) > 200) throw new \InvalidArgumentException('Informe um nome de até 200 caracteres.');
                if ($expire <= time() || $expire > 253402300799) throw new \InvalidArgumentException('Informe uma data de expiração futura válida.');
                foreach (['admin', 'terminal'] as $field) {
                    if (!in_array($input[$field] ?? null, ['true', 'false'], true)) throw new \InvalidArgumentException('Permissão inválida.');
                }
                $roots = $input['allowedRoots'] ?? null;
                if (!is_string($roots) || strlen($roots) > 32768) throw new \InvalidArgumentException('Diretórios permitidos inválidos.');
                $roots = array_values(array_filter(array_map('trim', explode("\n", $roots)), static fn($root) => $root !== ''));
                foreach ($roots as &$root) {
                    if (!str_starts_with($root, '/') || str_contains($root, "\0") || !is_dir($root) || ($real = realpath($root)) === false) throw new \InvalidArgumentException('Cada diretório deve ser um caminho absoluto existente.');
                    $root = $real;
                }
                unset($root);
                if (!$roots && $input['admin'] !== 'true') throw new \InvalidArgumentException('Informe ao menos um diretório para tokens sem administração.');
                if ($key === null) { $key = bin2hex(random_bytes(32)); $secret = $key; }
                $record = $tokens[$key] ?? [];
                $record = array_replace($record, ['nameClient' => trim($name), 'expire' => $expire, 'admin' => $input['admin'] === 'true', 'terminal' => $input['terminal'] === 'true']);
                if ($roots) $record['allowedRoots'] = array_values(array_unique($roots));
                else unset($record['allowedRoots']);
                $tokens[$key] = $record;
            } elseif ($action === 'clean') {
                foreach ($tokens as $candidate => $record) {
                    if ($candidate !== $actor && securityPolicy::expiration($record['expire'] ?? null) <= time()) unset($tokens[$candidate]);
                }
            } elseif ($action === 'delete') {
                unset($tokens[$key]);
            } elseif ($action === 'revoke' || $action === 'restore') {
                $tokens[$key]['revoked'] = $action === 'revoke';
            } elseif ($action === 'rotate') {
                $secret = bin2hex(random_bytes(32));
                $tokens[$secret] = $tokens[$key];
                unset($tokens[$key]);
            } elseif ($action === 'reveal') {
                $secret = (string) $key;
            }
            if (!self::admin($tokens[$actor] ?? [])) throw new \InvalidArgumentException('O token atual deve manter acesso administrativo e validade futura.');
            return $tokens;
        });
        return ['success' => true, 'message' => $action === 'reveal' ? 'Chave disponível para cópia.' : 'Operação concluída.', 'secret' => $secret];
    }
}
