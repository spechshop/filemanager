<?php

namespace plugins\Request;

use Plugin\Utils\cli;
use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class deleteMultipleFiles
{
    private const TRASH_DIR = './files/.trash';

    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $response->header('Content-Type', 'application/json');
        
        $owner = securityPolicy::requestToken($request);
        $trashDir = securityPolicy::path(securityPolicy::initialPath($owner) . '/.trash', $owner);
        // Garante que a pasta de lixeira existe
        if (!is_dir($trashDir)) {
            safeFiles::mkdir($trashDir, $owner);
        }

        $dirDelete = $request->get['path'];


        $movedFiles = [];

        foreach ($request->post ?? [] as $key => $value) {
            if ($key === 'tokenBrowser') continue;
            foreach ($value as $idFile => $del) {
                $nameFile = $del;
                securityPolicy::entryPath($nameFile, $owner);
                
                if (file_exists($nameFile)) {
                    // Cria nome único para evitar conflitos
                    $trashName = securityPolicy::path($trashDir . '/' . bin2hex(random_bytes(8)) . '_' . basename($nameFile), $owner, false);

                    
                    // Move para lixeira ao invés de deletar
                    if (!securityPolicy::validToken($owner)) return security::invalidToken($response);
                    if (safeFiles::move($nameFile, $trashName, $owner)) {
                        $movedFiles[] = $nameFile;
                    }
                }
            }
        }

        return $response->end(json_encode([
            'success' => true,
            'information' => 'ok',
            'movedToTrash' => count($movedFiles)
        ]));
    }

    /**
     * Limpa arquivos da lixeira com mais de X dias
     */
    public static function cleanTrash(int $daysOld = 30): int
    {
        $deleted = 0;
        $threshold = time() - ($daysOld * 86400);

        foreach (glob(self::TRASH_DIR . '/*') as $file) {
            if (filemtime($file) < $threshold) {
                if (is_dir($file)) {
                    cli::pcl("rm -rf " . escapeshellarg($file));
                } else {
                    unlink($file);
                }
                $deleted++;
            }
        }

        return $deleted;
    }
}