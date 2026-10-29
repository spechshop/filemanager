#!/usr/bin/env php
<?php

/**
 * Script Interativo para Gerenciamento de Tokens
 * Gerencia o arquivo database/tokens.lotus
 */

declare(strict_types=1);

// Cores para output no terminal
const COLOR_RED = "\033[31m";
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_BLUE = "\033[34m";
const COLOR_CYAN = "\033[36m";
const COLOR_RESET = "\033[0m";

$projectRoot = __DIR__;
$tokensFile = getenv('FILEMANAGER_TOKENS_FILE') ?: $projectRoot . '/database/tokens.lotus';
require_once __DIR__ . '/plugins/Request/components/privateJson.php';

function printHeader(string $title): void
{
    echo "\n" . COLOR_BLUE . str_repeat("=", 60) . COLOR_RESET . "\n";
    echo COLOR_BLUE . " $title" . COLOR_RESET . "\n";
    echo COLOR_BLUE . str_repeat("=", 60) . COLOR_RESET . "\n\n";
}

function printSuccess(string $message): void
{
    echo COLOR_GREEN . "✓ " . COLOR_RESET . "$message\n";
}

function printError(string $message): void
{
    echo COLOR_RED . "✗ " . COLOR_RESET . "$message\n";
}

function printWarning(string $message): void
{
    echo COLOR_YELLOW . "⚠ " . COLOR_RESET . "$message\n";
}

function printInfo(string $message): void
{
    echo COLOR_CYAN . "ℹ " . COLOR_RESET . "$message\n";
}

function prompt(string $message): string
{
    echo COLOR_YELLOW . $message . COLOR_RESET;
    return trim(fgets(STDIN) ?? '');
}

function loadTokens(string $file): array
{
    if (!file_exists($file)) {
        $GLOBALS['loadedTokenSnapshots'][$file] = hash('sha256', '');
        return [];
    }

    $content = file_get_contents($file);
    $GLOBALS['loadedTokenSnapshots'][$file] = hash('sha256', $content);
    $tokens = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        printError("Erro ao ler tokens: " . json_last_error_msg());
        throw new RuntimeException('Token store is invalid; preserve it and repair offline.');
    }

    return $tokens ?? [];
}

function saveTokens(string $file, array $tokens): bool
{
    $json = json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        printError("Erro ao gerar JSON: " . json_last_error_msg());
        return false;
    }

    // Criar diretório se não existir
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    try {
        \plugins\Request\privateJson::update($file, static function (array $current) use ($file, $tokens): array {
            $currentBytes = is_file($file) ? file_get_contents($file) : '';
            if (($GLOBALS['loadedTokenSnapshots'][$file] ?? null) !== hash('sha256', $currentBytes)) throw new RuntimeException('Token store changed concurrently. Reload before saving.');
            return $tokens;
        });
        $GLOBALS['loadedTokenSnapshots'][$file] = hash('sha256', file_get_contents($file));
        return true;
    } catch (Throwable $error) {
        printError('Token store was not changed: ' . $error->getMessage());
        return false;
    }

}

function generateToken(): string
{
    return bin2hex(random_bytes(32));
}

function formatDate(int $timestamp): string
{
    if ($timestamp > 9999999999) { // Timestamp muito grande
        return date('Y-m-d H:i:s', (int)($timestamp / 1000)) . " (timestamp em ms)";
    }
    return date('Y-m-d H:i:s', $timestamp);
}

function isExpired(int $expire): bool
{
    $currentTime = time();
    if ($expire > 9999999999) {
        $expire = (int)($expire / 1000);
    }
    return $expire < $currentTime;
}

function listTokens(array $tokens): void
{
    if (empty($tokens)) {
        printWarning("Nenhum token cadastrado");
        return;
    }

    echo "\n" . COLOR_CYAN . "Lista de Tokens:" . COLOR_RESET . "\n";
    echo str_repeat("-", 80) . "\n";

    foreach ($tokens as $token => $data) {
        $nameClient = $data['nameClient'] ?? 'N/A';
        $expire = $data['expire'] ?? 0;
        $expireDate = formatDate($expire);
        $status = isExpired($expire) ? COLOR_RED . "EXPIRADO" . COLOR_RESET : COLOR_GREEN . "ATIVO" . COLOR_RESET;

        echo COLOR_YELLOW . "Token: " . COLOR_RESET . substr($token, 0, 16) . "..." . substr($token, -8) . "\n";
        echo "  Cliente: " . COLOR_CYAN . $nameClient . COLOR_RESET . "\n";
        echo "  Expira em: $expireDate\n";
        echo "  Status: $status\n";
        echo str_repeat("-", 80) . "\n";
    }
}

function addToken(array &$tokens): void
{
    printHeader("Adicionar Novo Token");

    $token = prompt("Digite o token (deixe vazio para gerar automaticamente): ");
    if (empty($token)) {
        $token = generateToken();
        printInfo("Token gerado: $token");
    }

    if (isset($tokens[$token])) {
        printError("Este token já existe!");
        return;
    }

    $nameClient = prompt("Nome do cliente: ");
    if (empty($nameClient)) {
        printError("Nome do cliente é obrigatório!");
        return;
    }

    $days = prompt("Dias até expirar (ex: 365): ");
    if (!is_numeric($days)) {
        printError("Número de dias inválido!");
        return;
    }

    $expire = time() + ((int)$days * 24 * 60 * 60);

    $tokens[$token] = [
        'expire' => $expire,
        'nameClient' => $nameClient
    ];

    printSuccess("Token adicionado com sucesso!");
    printInfo("Token: $token");
    printInfo("Expira em: " . formatDate($expire));
}

function removeToken(array &$tokens): void
{
    printHeader("Remover Token");

    if (empty($tokens)) {
        printWarning("Nenhum token para remover");
        return;
    }

    listTokens($tokens);

    $tokenToRemove = prompt("\nDigite o token completo para remover: ");

    if (!isset($tokens[$tokenToRemove])) {
        printError("Token não encontrado!");
        return;
    }

    $confirm = prompt("Tem certeza que deseja remover o token de '{$tokens[$tokenToRemove]['nameClient']}'? (s/n): ");

    if (strtolower($confirm) === 's') {
        unset($tokens[$tokenToRemove]);
        printSuccess("Token removido com sucesso!");
    } else {
        printInfo("Operação cancelada");
    }
}

function updateToken(array &$tokens): void
{
    printHeader("Atualizar Token");

    if (empty($tokens)) {
        printWarning("Nenhum token para atualizar");
        return;
    }

    listTokens($tokens);

    $tokenToUpdate = prompt("\nDigite o token completo para atualizar: ");

    if (!isset($tokens[$tokenToUpdate])) {
        printError("Token não encontrado!");
        return;
    }

    echo "\n" . COLOR_CYAN . "Token selecionado: " . $tokens[$tokenToUpdate]['nameClient'] . COLOR_RESET . "\n";
    echo "1. Atualizar nome do cliente\n";
    echo "2. Estender validade\n";
    echo "3. Definir nova data de expiração\n";

    $option = prompt("\nEscolha uma opção: ");

    switch ($option) {
        case '1':
            $newName = prompt("Novo nome do cliente: ");
            if (!empty($newName)) {
                $tokens[$tokenToUpdate]['nameClient'] = $newName;
                printSuccess("Nome atualizado com sucesso!");
            }
            break;

        case '2':
            $days = prompt("Adicionar quantos dias? ");
            if (is_numeric($days)) {
                $tokens[$tokenToUpdate]['expire'] += ((int)$days * 24 * 60 * 60);
                printSuccess("Validade estendida por $days dias!");
                printInfo("Nova data de expiração: " . formatDate($tokens[$tokenToUpdate]['expire']));
            }
            break;

        case '3':
            $days = prompt("Dias a partir de hoje: ");
            if (is_numeric($days)) {
                $tokens[$tokenToUpdate]['expire'] = time() + ((int)$days * 24 * 60 * 60);
                printSuccess("Nova data de expiração definida!");
                printInfo("Expira em: " . formatDate($tokens[$tokenToUpdate]['expire']));
            }
            break;

        default:
            printError("Opção inválida!");
    }
}

function cleanExpiredTokens(array &$tokens): void
{
    printHeader("Limpar Tokens Expirados");

    $expired = [];
    foreach ($tokens as $token => $data) {
        if (isExpired($data['expire'] ?? 0)) {
            $expired[$token] = $data;
        }
    }

    if (empty($expired)) {
        printInfo("Nenhum token expirado encontrado");
        return;
    }

    echo "\n" . COLOR_YELLOW . "Tokens expirados encontrados: " . count($expired) . COLOR_RESET . "\n\n";

    foreach ($expired as $token => $data) {
        echo "- " . ($data['nameClient'] ?? 'N/A') . " (expirou em " . formatDate($data['expire']) . ")\n";
    }

    $confirm = prompt("\nDeseja remover todos os tokens expirados? (s/n): ");

    if (strtolower($confirm) === 's') {
        foreach (array_keys($expired) as $token) {
            unset($tokens[$token]);
        }
        printSuccess(count($expired) . " token(s) removido(s)!");
    } else {
        printInfo("Operação cancelada");
    }
}

function showStatistics(array $tokens): void
{
    printHeader("Estatísticas");

    $total = count($tokens);
    $active = 0;
    $expired = 0;

    foreach ($tokens as $data) {
        if (isExpired($data['expire'] ?? 0)) {
            $expired++;
        } else {
            $active++;
        }
    }

    echo COLOR_CYAN . "Total de tokens: " . COLOR_RESET . "$total\n";
    echo COLOR_GREEN . "Tokens ativos: " . COLOR_RESET . "$active\n";
    echo COLOR_RED . "Tokens expirados: " . COLOR_RESET . "$expired\n";
}

// =============================================================================
// PROGRAMA PRINCIPAL
// =============================================================================

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

printHeader("Gerenciador de Tokens - LIPC");

while (true) {
    $tokens = loadTokens($tokensFile);

    echo "\n" . COLOR_CYAN . "Menu Principal:" . COLOR_RESET . "\n";
    echo "1. Listar tokens\n";
    echo "2. Adicionar token\n";
    echo "3. Remover token\n";
    echo "4. Atualizar token\n";
    echo "5. Limpar tokens expirados\n";
    echo "6. Estatísticas\n";
    echo "7. Sair\n";

    $option = prompt("\nEscolha uma opção: ");

    switch ($option) {
        case '1':
            printHeader("Listar Tokens");
            listTokens($tokens);
            break;

        case '2':
            addToken($tokens);
            if (saveTokens($tokensFile, $tokens)) {
                printSuccess("Alterações salvas!");
            }
            break;

        case '3':
            removeToken($tokens);
            if (saveTokens($tokensFile, $tokens)) {
                printSuccess("Alterações salvas!");
            }
            break;

        case '4':
            updateToken($tokens);
            if (saveTokens($tokensFile, $tokens)) {
                printSuccess("Alterações salvas!");
            }
            break;

        case '5':
            cleanExpiredTokens($tokens);
            if (saveTokens($tokensFile, $tokens)) {
                printSuccess("Alterações salvas!");
            }
            break;

        case '6':
            showStatistics($tokens);
            break;

        case '7':
            printInfo("Saindo...");
            exit(0);

        default:
            printError("Opção inválida!");
    }

    prompt("\nPressione ENTER para continuar...");
}
