<?php

namespace plugins\Request;

use plugins\Extension\utilsFunction;
use Swoole\Http\Request;
use Swoole\Http\Response;

class getArchiveDetails
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $response->header('Content-Type', 'application/json');
        $data = $request->get;
        $original = $data['path'];
        $snapshot = safeFiles::snapshot($original, securityPolicy::requestToken($request));
        $fileAddress = $snapshot . '.' . pathinfo($original, PATHINFO_EXTENSION);
        rename($snapshot, $fileAddress);
        try {




        return $response->end(json_encode([
            'success' => true,
            'list' => utilsFunction::listCompressedFileContents($fileAddress)
        ]));
        } finally { @unlink($fileAddress); }
    }
}