<?php
declare(strict_types=1);
// A dedicated test server. Never include middleware.php, installers or live configuration.
if (!extension_loaded('swoole')) { fwrite(STDERR, "REQUIRED: Swoole; no test executed\n"); exit(1); }
$source = dirname(__DIR__, 3);
foreach (['Request/components/appController.php', 'Request/components/securityPolicy.php', 'Request/components/security.php', 'Request/components/safeFiles.php', 'Request/components/checkRoute.php', 'Request/views/controller.php', 'Request/views/loadRouter.php', 'Request/router/server.php', 'Start/server/cache.php', 'Database/call/call.php', 'Extension/plugins/utilsFunction.php', 'OpenConnection/websocket/OpenConnection.php', 'Message/server/server.php'] as $file) require $source . '/plugins/' . $file;
foreach (['checkToken', 'accessTicket', 'deleteFile', 'downloadFile', 'uploadFile', 'newFile', 'freeRam', 'chat4o', 'treeDetails', 'syncPath'] as $name) require $source . '/plugins/Request/apps/' . $name . '.php';
$GLOBALS['interface'] = ['allowExtensions' => ['json' => 'application/json', 'js' => 'text/javascript']];
$GLOBALS['cachePages']['404'] = 'Not found';
$server = new Swoole\WebSocket\Server('127.0.0.1', (int) getenv('SECURITY_HTTP_PORT'));
$temporaryUploads = \plugins\Request\securityPolicy::uploadDirectory();
mkdir($temporaryUploads, 0700, true);
$server->set(['upload_tmp_dir' => $temporaryUploads, 'worker_num' => 1, 'log_file' => '/dev/null', 'package_max_length' => 8388608, 'upload_max_filesize' => 2147483648]);
$uploadPort = (int) getenv('FILEMANAGER_UPLOAD_PORT');
if ($uploadPort > 0) {
    $listener = $server->listen('127.0.0.1', $uploadPort, SWOOLE_SOCK_TCP);
    $listener->set(['open_http_protocol' => true, 'open_websocket_protocol' => false, 'package_max_length' => 2147483648]);
}
$server->on('Request', '\plugins\Request\server::request');
$server->on('open', '\plugins\server::open');
$server->on('message', '\plugins\server::message');
$server->on('close', '\plugins\server::close');
$server->on('start', static function () { echo "SECURITY_FIXTURE_READY\n"; });
$server->start();
