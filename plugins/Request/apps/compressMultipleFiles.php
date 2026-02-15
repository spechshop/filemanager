<?php

namespace plugins\Request;

use plugins\Extension\utilsFunction;
use ZipArchive;

class compressMultipleFiles
{
    public static function api($request, $response)
    {
        if (!security::verifyToken($request)) {
            return security::invalidToken($response);
        }

        $response->header('Content-Type', 'application/json');

        $path = rtrim(str_replace('//', '/', $request->get['path']), '/');
        
        


        
        $destination = $path . '/' . date('d-m-Y_H_i_s') . '.zip';


        
        $rawList = $request->post['files'] ?? null;

        if (!$rawList || !is_array($rawList)) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'No files provided'
            ]));
        }


        $files = $rawList;

        if (empty($files)) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'No valid files found to compress'
            ]));
        }
        //var_dump($files);
        if (!utilsFunction::createZipWithFolders($files, $destination)) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'Failed to create zip'
            ]));
        }

        return $response->end(json_encode([
            'success' => true,
            'message' => 'File created successfully',
            'path' => $destination
        ]));
    }
}
