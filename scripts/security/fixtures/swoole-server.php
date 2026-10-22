<?php
declare(strict_types=1);
// A dedicated test server. Never include middleware.php, installers or live configuration.
if (!extension_loaded('swoole')) { fwrite(STDERR, "REQUIRED: Swoole; no test executed\n"); exit(1); }
$source = dirname(__DIR__, 3);
foreach (['Request/components/appController.php', 'Request/components/securityPolicy.php', 'Request/components/security.php', 'Request/components/safeFiles.php', 'Request/components/checkRoute.php', 'Request/views/controller.php', 'Request/views/loadRouter.php', 'Request/router/server.php', 'Start/server/cache.php', 'Database/call/call.php', 'Extension/plugins/utilsFunction.php', 'OpenConnection/websocket/OpenConnection.php', 'Message/server/server.php'] as $file) require $source . '/plugins/' . $file;
foreach (['deleteFile', 'downloadFile', 'uploadFile', 'newFile', 'freeRam', 'chat4o', 'treeDetails', 'syncPath'] as $name) require $source . '/plugins/Request/apps/' . $name . '.php';
$GLOBALS['interface'] = ['allowExtensions' => ['json' => 'application/json', 'js' => 'text/javascript']];
$GLOBALS['cachePages']['404'] = 'Not found';
$server = new Swoole\WebSocket\Server('127.0.0.1', (int) getenv('SECURITY_HTTP_PORT'));
$server->set(['worker_num' => 1, 'log_file' => '/dev/null']);
$server->on('Request', '\plugins\Request\server::request');
$server->on('open', '\plugins\server::open');
$server->on('message', '\plugins\server::message');
$server->on('close', '\plugins\server::close');
$server->on('start', static function () { echo "SECURITY_FIXTURE_READY\n"; });
$server->start();
