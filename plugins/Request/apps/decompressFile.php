<?php

namespace plugins\Request;

use plugins\Extension\utilsFunction;
use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class decompressFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $response->header('Content-Type', 'application/json');
        $data = count($request->post) > 0 ? $request->post : json_decode($request->rawContent(), true);
        $realPath = $data['path'];
        $realPath = str_replace(['//', './'], '', $realPath);
        $realPathToExtract = $realPath;
        $type = $data['type'];
        if ($type === 'yes') {
            $split = explode('.', $data['namefile']);
            unset($split[count($split) - 1]);
            $nameOfMakeFolder = implode('.', $split);
            $nameOfMakeFolder = str_replace(['//', './'], '', $nameOfMakeFolder);
            if (!is_dir($realPath . '/' . $nameOfMakeFolder)) {
                mkdir($realPath . '/' . $nameOfMakeFolder);
            }
            $realPath = $realPath . '/' . $nameOfMakeFolder;
            return $response->end(json_encode(utilsFunction::extractCompressedFile($realPathToExtract . '/' . $data['namefile'], $realPath)));
        } else {
            return $response->end(json_encode(utilsFunction::extractCompressedFile($realPathToExtract . '/' . $data['namefile'], $realPath)));
        }
    }
}