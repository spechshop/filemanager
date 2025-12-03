<?php
$paths = $libs;
$nameFiles = [];
foreach ($paths as $path) {
    $directory = new DirectoryIterator($path);
    foreach ($directory as $fileInfo) {
        $nameFile = $fileInfo->getFilename();
        if (strlen($nameFile) > 2) {
            $nameFiles[] = (strlen($path) > 1) ? $path . "/" . $nameFile : $nameFile;
        }
    }
}
foreach ($nameFiles as $key => $file) {
    include $file;
}
