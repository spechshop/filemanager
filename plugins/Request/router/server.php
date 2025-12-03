<?php

namespace plugins\Request;

use plugins\Start\cache;
class server
{
    public static function request($request, $response) {
        $path = $request->server['path_info'];
        $response->header('Content-Type', 'application/json');
        // liberar cors
        $response->header('Access-Control-Allow-Origin', '*');


        
        $assetsBuilder = loadRouter::view($path, $response);
        if ($assetsBuilder['break']) {
            return;
        }
        if ($path === '/') {
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->status(200);
            return $response->end(cache::global()['cachePages']['index']);
        }
        $pages = cache::global()['listRoutes'];
        $appReplace = str_replace('/', '', $path);
        foreach ($pages as $page) {
            $eRoute = explode('/', $page);
            $nameRoute = '/' . explode('.html', str_replace(['.php'], '', $eRoute[count($eRoute) - 1]))[0];
            if ($path == $nameRoute) {
                if (!file_exists($page)) {
                    $response->status(500, 'Internal Error Page');
                    return $response->end();
                } else {
                    $replace = str_replace('/', '', $nameRoute);
                    $response->header('Content-Type', 'text/html; charset=utf-8');
                    $response->status(200);
                    return $response->end(cache::global()['cachePages'][$replace]);
                }
            }
        }
        $response->status(200);
        if (!appController::call($request, $response, $appReplace)) {
            $response->header('Content-Type', 'text/html; charset=utf-8');
            return $response->end(cache::global()['cachePages']['404']);
        }
        return false;
    }
}