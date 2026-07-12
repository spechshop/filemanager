<?php

namespace plugins\Request;

use PhpParser\ParserFactory;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Endpoint de formatação de código (estilo "csfix") para o editor.
 *
 * Formata o PHP recebido usando, na ordem de preferência:
 *   1) php-cs-fixer (regras @PSR12) quando o binário estiver disponível;
 *   2) fallback com o pretty-printer do nikic/php-parser (CustomPrettyPrinter),
 *      já usado em refactorFile, garantindo formatação mesmo sem o csfix.
 *
 * Resposta: { success, formatter, formatted } ou { success:false, message }.
 */
class formatCode
{
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::verifyToken($request)) {
            return security::invalidToken($response);
        }

        $response->header('Content-Type', 'application/json');
        $data     = json_decode($request->rawContent(), true) ?: [];
        $code     = $data['code'] ?? '';
        $nameFile = $data['nameFile'] ?? '';

        if ($code === '' && $nameFile !== '' && is_file($nameFile)) {
            $code = (string) file_get_contents($nameFile);
        }
        if ($code === '') {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'Nenhum código para formatar.',
            ]));
        }

        // 1) php-cs-fixer (ferramenta recomendada), se instalada.
        $fixer = self::findPhpCsFixer();
        if ($fixer !== null) {
            $formatted = self::runPhpCsFixer($fixer, $code);
            if ($formatted !== null && $formatted !== '') {
                return $response->end(json_encode([
                    'success'   => true,
                    'formatter' => 'php-cs-fixer',
                    'formatted' => $formatted,
                ]));
            }
        }

        // 2) Fallback: pretty-printer do nikic/php-parser.
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code);
        } catch (\PhpParser\Error $e) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'Erro de sintaxe: ' . $e->getMessage(),
            ]));
        }
        if ($ast === null) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'Não foi possível analisar o código.',
            ]));
        }

        $printer                = new CustomPrettyPrinter();
        $printer->nameFile      = $nameFile;
        $printer->dataFileLines = explode(PHP_EOL, $code);

        return $response->end(json_encode([
            'success'   => true,
            'formatter' => 'php-parser',
            'formatted' => $printer->prettyPrintFile($ast),
        ]));
    }

    /**
     * Localiza o binário do php-cs-fixer (vendor/bin, raiz do projeto ou PATH).
     */
    private static function findPhpCsFixer(): ?string
    {
        $baseDir    = appController::baseDir();
        $candidates = [
            $baseDir . 'vendor/bin/php-cs-fixer',
            $baseDir . 'php-cs-fixer',
            $baseDir . 'php-cs-fixer.phar',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        $which = trim((string) @shell_exec('command -v php-cs-fixer 2>/dev/null'));
        if ($which !== '' && is_file($which)) {
            return $which;
        }
        return null;
    }

    /**
     * Roda o php-cs-fixer sobre um arquivo temporário e devolve o conteúdo formatado.
     */
    private static function runPhpCsFixer(string $fixer, string $code): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csfix_') . '.php';
        file_put_contents($tmp, $code);

        $cmd = 'php ' . escapeshellarg($fixer) . ' fix ' . escapeshellarg($tmp)
            . ' --using-cache=no --quiet --rules=@PSR12 2>/dev/null';
        @shell_exec($cmd);

        $out = @file_get_contents($tmp);
        @unlink($tmp);

        return $out !== false ? $out : null;
    }
}
