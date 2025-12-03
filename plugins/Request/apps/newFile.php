<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class newFile
{
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $response->header('Content-Type', 'application/json');
        $data = count($request->post) > 0 ? $request->post : json_decode($request->rawContent(), true);
        if ($data['name']) {
            // caso tenha sido enviado a pasta completa com o nome do arquivo, pegamos só o nome do arquivo


            
            $data['name'] = basename($data['name']);
        }
        if ($data['type'] == 'file') {
            $data['path'] = substr($data['path'], 0);
            $newPath = $data['path'] . '/' . $data['name'];
            $newPath = str_replace(['//', './'], '', $newPath);
            @file_put_contents($newPath, !empty($data['content']) ? $data['content'] : '');
        } elseif ($data['type'] == 'dir') {
            $data['path'] = substr($data['path'], 0);
            $newPath = $data['path'] . '/' . $data['name'];
            $newPath = str_replace(['//', './'], '', $newPath);
            @mkdir($newPath);
        }
        return $response->end(json_encode([
            'success' => true,
            'information' => 'ok'
        ]));
    }
}