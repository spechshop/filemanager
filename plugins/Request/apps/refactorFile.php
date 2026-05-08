<?php

namespace plugins\Request;

use PhpParser\ParserFactory;
use Swoole\Http\Request;
use Swoole\Http\Response;

class refactorFile
{
    public static function api(Request $request, Response $response): bool
    {
        if (!security::verifyToken($request)) {
            return security::invalidToken($response);
        }

        $response->header('Content-Type', 'application/json');
        $data = json_decode($request->rawContent(), true);
        $code = $data['code'] ?? '';

        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($code);
        } catch (\PhpParser\Error $error) {
            return $response->end(json_encode(['error' => $error->getMessage()]));
        }

        $prettyPrinter = new CustomPrettyPrinter();
        $prettyPrinter->nameFile = $data['nameFile'] ?? '';
        $prettyPrinter->dataFileLines = explode(PHP_EOL, $code);

        return $response->end(json_encode(['prettyCode' => $prettyPrinter->prettyPrintFile($ast)]));
    }
}
