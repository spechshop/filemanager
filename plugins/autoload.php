
<?php
$interface = json_decode(file_get_contents(getenv('FILEMANAGER_CONFIG_FILE') ?: __DIR__ . '/configInterface.json'), true);
$paths = $interface['autoload'];
$allowObservable = $interface['allowObservable'];
$nameFiles = [];
$cachePages = [];
foreach ($paths as $path) {
    $directory = new DirectoryIterator(__DIR__ . "/{$path}");
    foreach ($directory as $fileInfo) {
        $nameFile = $fileInfo->getFilename();
        if ($fileInfo->isFile() && !$fileInfo->isLink() && $fileInfo->getExtension() === 'php') {
            $nameFiles[] = strlen($path) > 1 ? __DIR__ . '/' . $path . "/" . $nameFile : $nameFile;
        }
    }
}

foreach ($nameFiles as $key => $file) {
    require_once $file;
}

$dataKeys = \plugins\Database\call::data();
$listRoutes = \plugins\Request\controller::listPages();
foreach ($listRoutes as $listRoute) {
    $e = explode('/', $listRoute);
    $idKey = explode('.', $e[count($e) - 1])[0];
    $cachePages[$idKey] = \plugins\Utils\cache\bufferPages::get($idKey, __DIR__);
}

