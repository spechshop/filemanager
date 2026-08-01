<?php

namespace plugins\Request;

class fileManagerConfig
{
    private const CONFIG_FILE = __DIR__ . '/../../configInterface.json';
    private const CODEX_PREFERENCES_FILE = __DIR__ . '/../../../database/codex-preferences.lotus';

    public static function read(): array
    {
        $contents = @file_get_contents(self::CONFIG_FILE);
        $config = is_string($contents) ? json_decode($contents, true) : null;

        if (!is_array($config)) {
            throw new \RuntimeException('Não foi possível ler a configuração do File Manager.');
        }

        return $config;
    }

    public static function write(array $config): void
    {
        $json = json_encode(
            $config,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new \RuntimeException('Não foi possível serializar a configuração.');
        }

        $directory = dirname(self::CONFIG_FILE);
        $temporaryFile = tempnam($directory, '.configInterface.');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Não foi possível preparar o arquivo de configuração.');
        }

        try {
            $permissions = @fileperms(self::CONFIG_FILE);
            if (file_put_contents($temporaryFile, $json . PHP_EOL, LOCK_EX) === false) {
                throw new \RuntimeException('Não foi possível salvar a configuração.');
            }
            if ($permissions !== false) {
                @chmod($temporaryFile, $permissions & 0777);
            }
            if (!@rename($temporaryFile, self::CONFIG_FILE)) {
                throw new \RuntimeException('Não foi possível aplicar a nova configuração.');
            }
        } finally {
            if (file_exists($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    public static function update(callable $mutator): array
    {
        $lockFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'lotus-filemanager-config-'
            . hash('sha256', self::CONFIG_FILE) . '.lock';
        $lock = @fopen($lockFile, 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('Não foi possível bloquear a configuração para escrita.');
        }

        try {
            $config = self::read();
            $updated = $mutator($config);
            if (!is_array($updated)) {
                throw new \RuntimeException('A atualização da configuração retornou um valor inválido.');
            }
            self::write($updated);
            $GLOBALS['interface'] = $updated;
            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function serviceEnabled(array $config, string $service): bool
    {
        $defaults = ['pty' => true, 'lsp' => true, 'gpt' => false, 'codex' => false];
        return ($config['fileManager']['services'][$service] ?? $defaults[$service] ?? false) !== false;
    }

    public static function setServiceEnabled(string $service, bool $enabled): array
    {
        return self::update(static function (array $config) use ($service, $enabled): array {
            $config['fileManager'] = is_array($config['fileManager'] ?? null)
                ? $config['fileManager']
                : [];
            $config['fileManager']['services'] = is_array($config['fileManager']['services'] ?? null)
                ? $config['fileManager']['services']
                : [];
            $config['fileManager']['services'][$service] = $enabled;
            return $config;
        });
    }

    public static function codexPreferences(array $config, string $token): array
    {
        if ($token === '') {
            return ['model' => null, 'reasoningEffort' => null];
        }
        self::migrateLegacyCodexPreferences($config);
        $key = hash('sha256', $token);
        $stored = self::readCodexPreferences()[$key] ?? [];
        return [
            'model' => is_string($stored['model'] ?? null) && $stored['model'] !== ''
                ? $stored['model']
                : null,
            'reasoningEffort' => is_string($stored['reasoningEffort'] ?? null)
                && $stored['reasoningEffort'] !== ''
                ? $stored['reasoningEffort']
                : null,
        ];
    }

    public static function setCodexPreferences(string $token, ?string $model, ?string $reasoningEffort): array
    {
        if ($token === '') {
            throw new \InvalidArgumentException('Token ausente para salvar as preferências do Codex.');
        }
        $config = self::read();
        self::migrateLegacyCodexPreferences($config);
        $key = hash('sha256', $token);
        self::updateCodexPreferences(static function (array $preferences) use ($key, $model, $reasoningEffort): array {
            if ($model === null && $reasoningEffort === null) {
                unset($preferences[$key]);
                return $preferences;
            }
            $preferences[$key] = [
                'model' => $model,
                'reasoningEffort' => $reasoningEffort,
            ];
            return $preferences;
        });
        return self::read();
    }

    private static function readCodexPreferences(): array
    {
        if (!is_file(self::CODEX_PREFERENCES_FILE)) {
            return [];
        }
        $contents = @file_get_contents(self::CODEX_PREFERENCES_FILE);
        $preferences = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($preferences)) {
            throw new \RuntimeException('Não foi possível ler as preferências privadas do Codex.');
        }
        return $preferences;
    }

    private static function updateCodexPreferences(callable $mutator): array
    {
        $lockFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'lotus-filemanager-codex-preferences-'
            . hash('sha256', self::CODEX_PREFERENCES_FILE) . '.lock';
        $lock = @fopen($lockFile, 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('Não foi possível bloquear as preferências do Codex para escrita.');
        }

        try {
            $updated = $mutator(self::readCodexPreferences());
            if (!is_array($updated)) {
                throw new \RuntimeException('A atualização das preferências do Codex retornou um valor inválido.');
            }
            self::writeCodexPreferences($updated);
            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function writeCodexPreferences(array $preferences): void
    {
        $json = json_encode(
            $preferences,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new \RuntimeException('Não foi possível serializar as preferências do Codex.');
        }

        $directory = dirname(self::CODEX_PREFERENCES_FILE);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('O diretório privado de preferências do Codex não está disponível.');
        }
        $temporaryFile = tempnam($directory, '.codex-preferences.');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Não foi possível preparar as preferências privadas do Codex.');
        }

        try {
            if (file_put_contents($temporaryFile, $json . PHP_EOL, LOCK_EX) === false) {
                throw new \RuntimeException('Não foi possível salvar as preferências privadas do Codex.');
            }
            @chmod($temporaryFile, 0600);
            if (!@rename($temporaryFile, self::CODEX_PREFERENCES_FILE)) {
                throw new \RuntimeException('Não foi possível aplicar as preferências privadas do Codex.');
            }
            @chmod(self::CODEX_PREFERENCES_FILE, 0600);
        } finally {
            if (file_exists($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    private static function migrateLegacyCodexPreferences(array $config): void
    {
        $legacy = $config['fileManager']['tokenPreferences'] ?? null;
        if (!is_array($legacy)) {
            return;
        }

        self::updateCodexPreferences(static function (array $preferences) use ($legacy): array {
            foreach ($legacy as $key => $entry) {
                if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/', $key)) {
                    continue;
                }
                $stored = is_array($entry['codex'] ?? null) ? $entry['codex'] : [];
                if (!isset($preferences[$key]) && $stored !== []) {
                    $preferences[$key] = [
                        'model' => is_string($stored['model'] ?? null) ? $stored['model'] : null,
                        'reasoningEffort' => is_string($stored['reasoningEffort'] ?? null)
                            ? $stored['reasoningEffort']
                            : null,
                    ];
                }
            }
            return $preferences;
        });

        self::update(static function (array $current): array {
            unset($current['fileManager']['tokenPreferences']);
            return $current;
        });
    }
}
