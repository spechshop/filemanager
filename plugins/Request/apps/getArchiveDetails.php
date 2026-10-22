<?php

namespace plugins\Request;

use plugins\Extension\utilsFunction;
use Swoole\Http\Request;
use Swoole\Http\Response;

class getArchiveDetails
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response)) return true;
        $response->header('Content-Type', 'application/json');
        $data = $request->get;
        $fileAddress =  $data['path'];




        return $response->end(json_encode([
            'success' => true,
            'list' => utilsFunction::listCompressedFileContents($fileAddress)
        ]));
    }
}