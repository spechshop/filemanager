<?php
declare(strict_types=1);
namespace plugins\Request;

final class recycleBin
{
    public static function message(string $code): string
    {
        return match ($code) {
            'conflict' => 'Já existe um item no local original. Restaure com outro nome para preservar os dois.',
            'not_found' => 'O item ou o local de origem não está mais disponível.',
            'busy' => 'A lixeira está ocupada. Tente novamente em alguns instantes.',
            'partial' => 'A operação não foi concluída. Uma cópia foi preservada; verifique a lixeira e a pasta de origem.',
            default => 'Não foi possível concluir a operação. Verifique as permissões e o espaço disponível.',
        };
    }

    public static function move(array $paths, string $owner): array
    {
        if (!$paths || count($paths) > 500) throw new \DomainException('trash selection');
        $paths = array_unique(array_map(static fn($path) => securityPolicy::entryPath($path, $owner), $paths));
        usort($paths, static fn($a, $b) => strlen($a) <=> strlen($b));
        $selected = [];
        foreach ($paths as $path) {
            foreach ($selected as $parent) if (!is_link($parent) && securityPolicy::within($path, $parent)) continue 2;
            $selected[] = $path;
        }
        return self::batch('put', $selected, $owner);
    }

    public static function batch(string $action, array $items, string $owner, bool $rename = false): array
    {
        $completed = []; $errors = [];
        foreach ($items as $item) {
            try {
                $result = safeFiles::trash($action, $owner, $action === 'put' ? $item : '', $action === 'put' ? '' : $item, $rename);
                if (empty($result['success'])) {
                    $code = $result['code'] ?? 'failed';
                    $errors[] = ['item' => $item, 'code' => $code, 'message' => self::message($code)];
                } else $completed[] = $result;
            } catch (\Throwable $error) {
                $errors[] = ['item' => $item, 'code' => 'failed', 'message' => self::message('failed')];
            }
        }
        securityPolicy::log('trash_' . $action);
        return ['success' => !$errors, 'information' => $errors ? 'partial' : 'ok',
            'completed' => $completed, 'errors' => $errors, 'movedToTrash' => $action === 'put' ? count($completed) : 0];
    }
}
