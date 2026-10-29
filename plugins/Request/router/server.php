<?php

namespace plugins\Request;

use plugins\Start\cache;
class server
{
    public static function request($request, $response) {
        // Worker globals are not shared with the master process. Refresh on use.
        $GLOBALS['dataKeys'] = \plugins\Database\call::data() ?? [];
        $GLOBALS['listRoutes'] = controller::listPages();
        $path = $request->server['path_info'];
        $uploadPort = (int) (getenv('FILEMANAGER_UPLOAD_PORT') ?: 0);
        if ($uploadPort > 0 && (int) ($request->server['server_port'] ?? 0) === $uploadPort) {
            try { $uploadOperation = securityPolicy::operation($path); } catch (\DomainException $e) { $uploadOperation = ''; }
            if ($uploadOperation !== 'uploadFile') { $response->status(404); return $response->end(); }
        }
        $response->header('Content-Type', 'application/json');
        // liberar cors
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Referrer-Policy', 'no-referrer');
        $response->header('X-Frame-Options', 'DENY');
        $response->header('Content-Security-Policy', "object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
        // Observe stricter script/style requirements before migrating legacy inline handlers.
        $response->header('Content-Security-Policy-Report-Only', "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com; style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com https://use.fontawesome.com; img-src 'self' data: blob:; font-src 'self' https://fonts.gstatic.com https://use.fontawesome.com; connect-src 'self'; worker-src 'self' blob:");
        if (!securityPolicy::originAllowed($request)) return security::deny($response);


        
        $assetsBuilder = loadRouter::view($path, $response);
        if ($assetsBuilder['break']) {
            return;
        }
        if ($path === '/') {
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->status(200);
            $indexNeedFull = template::prepare(file_get_contents(
                'plugins/Request/pages/index.html'
            ));



            \libspech\Cache\cache::subDefine('cachePages', 'index', $indexNeedFull);


            return $response->end(cache::global()['cachePages']['index']);
        }
        $pages = cache::global()['listRoutes'];
        $appReplace = $path;
        foreach ($pages as $page) {
            $eRoute = explode('/', $page);
            $nameRoute = '/' . explode('.html', str_replace(['.php'], '', $eRoute[count($eRoute) - 1]))[0];
            if ($path == $nameRoute) {
                if (!file_exists($page)) {
                    $response->status(500, 'Internal Error Page');
                    return $response->end();
                } else {
                    $response->header('Content-Type', 'text/html; charset=utf-8');
                    $response->status(200);
                    // As páginas e seus @imports podem mudar com o autorestart
                    // desligado. Renderizar a fonte atual aqui impede que o
                    // cache em memória da inicialização devolva HTML antigo.
                    return $response->end(template::prepare(file_get_contents($page)));
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
