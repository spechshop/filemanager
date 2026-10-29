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
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $listed = is_file('stubs-paths.json') ? json_decode(file_get_contents('stubs-paths.json'), true) : [securityPolicy::initialPath(securityPolicy::requestToken($request))];
        if (!is_array($listed)) return security::deny($response);
        foreach ($listed as $listedPath) securityPolicy::tree(securityPolicy::path($listedPath, securityPolicy::requestToken($request)), securityPolicy::requestToken($request));
        $owner = securityPolicy::requestToken($request);
        async(static function () use ($listed, $owner) {
            securityPolicy::requireAdmin($owner);
            $environment = getenv();
            $environment['FILEMANAGER_SCAN_ROOTS'] = json_encode(array_values($listed));
            $environment['FILEMANAGER_SCAN_TOKEN'] = $owner;
            $pipes = [];
            $process = proc_open([PHP_BINARY, appController::baseDir() . 'r.php'],
                [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, appController::baseDir(), $environment);
            if (is_resource($process)) proc_close($process);
        });
        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode([
            'success' => true,
            'list' => json_decode(file_get_contents('stubs-generated.json'), true)
        ]));
    }
}
