<?php

namespace plugins\Request;

use App\HttpController\libs\extFunctions;
use plugins\Extension\utilsFunction;
use plugins\lotus\Database;
use ReflectionException;
use ReflectionFunction;
use ReflectionClass;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Process;
use PhpParser\Error;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;


error_reporting(E_ALL);
ini_set('display_errors', 1);


class codeGenerate
{
    /**
     * @throws ReflectionException
     */
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::authorize($request, $response)) return true;
        $listed = is_file('stubs-paths.json') ? json_decode(file_get_contents('stubs-paths.json'), true) : securityPolicy::roots(securityPolicy::requestToken($request));
        if (!is_array($listed)) return security::deny($response);
        foreach ($listed as $listedPath) securityPolicy::tree(securityPolicy::path($listedPath, securityPolicy::requestToken($request)), securityPolicy::requestToken($request));
        $command = 'FILEMANAGER_SCAN_ROOTS=' . escapeshellarg(json_encode(array_values($listed))) . ' ' . escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(appController::baseDir() . 'r.php');
        $owner = securityPolicy::requestToken($request);
        async(static function () use ($command, $owner) { if (securityPolicy::validToken($owner)) shell_exec($command); });
        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode([
            'success' => true,
            'list' => json_decode(file_get_contents('stubs-generated.json'), true)
        ]));
    }
}
