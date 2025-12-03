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
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $response->header('Content-Type', 'application/json');
        
        // Garante que a pasta de lixeira existe
        if (!is_dir(self::TRASH_DIR)) {
            mkdir(self::TRASH_DIR, 0755, true);
        }

        $dirDelete = $request->get['path'];
        $movedFiles = [];

        foreach ($request->post as $key => $value) {
            foreach ($value as $idFile => $del) {
                $nameFile = str_replace(['"', "'"], '', explode(' (', $del)[0]);
                
                if (file_exists($nameFile)) {
                    // Cria nome único para evitar conflitos
                    $trashName = self::TRASH_DIR . '/' . time() . '_' . basename($nameFile);
                    
                    // Move para lixeira ao invés de deletar
                    if (rename($nameFile, $trashName)) {
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