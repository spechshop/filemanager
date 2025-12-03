<?php

namespace plugins\Request;

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;

class uploadFile
{
    const DATE_FORMAT = 'd/m/Y H:i:s';

    public static function api(Request $request, Response $response)
    {
        self::initCoroutines();
        if (!self::verifyToken($request, $response)) return;
        var_dump(date(self::DATE_FORMAT) . ' - ' . $request->get['path']);


        self::prepareResponse($response);
        self::sendResponse($response);


        $files = $request->files['files'];

        $targetPath = self::getTargetPath($request->get['path']);
        var_dump($targetPath);
        self::asyncMoveUploadedFiles($files, $targetPath);
    }

    protected static function initCoroutines()
    {
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_FILE]);
    }

    protected static function verifyToken($request, $response)
    {
        if (!security::verifyToken($request)) {
            security::invalidToken($response);
            return false;
        }
        return true;
    }

    protected static function prepareResponse($response)
    {
        $response->header('Content-Type', 'application/json');
    }

    protected static function getTargetPath($relativePath)
    {
        return substr($relativePath, 0);
    }

    protected static function logRequest($targetPath)
    {
        var_dump(date(self::DATE_FORMAT) . ' - ' . $targetPath);
    }

    protected static function asyncMoveUploadedFiles($files, $targetPath)
    {
            foreach ($files as $file) {
                self::moveFile($file, $targetPath);
            }
 
    }

    protected static function moveFile($file, $targetPath)
    {
        move_uploaded_file($file['tmp_name'], $targetPath . '/' . $file['name']);
    }

    protected static function sendResponse($response)
    {
        $response->end(json_encode([
            'success' => true,
            'information' => 'ok'
        ]));
    }
}