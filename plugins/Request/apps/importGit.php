<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine\System;
class importGit
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) {
            return security::invalidToken($response);
        }
        $response->header('Content-Type', 'application/json');
        //$data = count($request->post) > 0 ? $request->post : json_decode($request->rawContent(), true);
        $url = $request->get['url'];
        $regex = '/^git\\s+clone\\s+((https?|git|ssh|ftps?|rsync|file):\\/\\/[^\\s;|&<>]*|[^\\s;|&<>]+@[^:]+:[^\\s;|&<>]+)(\\s+--[^\\s;|&<>]+(\\s+[^\\s;|&<>]+)*)?\\s*$/';
        $filter = preg_match($regex, 'git clone ' . $url) === 1;
        if (!$filter) {
            return $response->end(gzencode(json_encode([
                'success' => false,
                'information' => 'Invalid URL'])));
        }
        System::exec('cd ' . escapeshellarg(appController::baseDir()) . '/files && git clone ' . $url);
        return $response->end(json_encode([
            'success' => true,
            'information' => 'ok']));
    }
}
