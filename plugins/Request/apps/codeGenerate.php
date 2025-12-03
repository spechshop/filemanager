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
        if (!security::verifyToken($request)) return security::invalidToken($response);
        async(fn() => shell_exec('php stubGen.php'));
        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode([
            'success' => true,
            'list' => json_decode(file_get_contents('stubs-generated.json'), true)
        ]));
    }
}
