<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class codex
{
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $data = $request->rawContent();
        $datax = json_decode($data, true);
        if (!empty($datax['type'])) {
            if ($datax['type'] === 'refactor') {
                $endPoint = 'http://localhost:3090/api';
                $client = new \Swoole\Coroutine\Http\Client('localhost', 3090);
                $client->set([
                    'timeout' => 120,
                    'ssl_verify_peer' => false,
                    'ssl_verify_peer_name' => false,
                    'keep_alive' => true,
                    'enable_keep_alive' => true,
                ]);
                $client->setHeaders([
                    'Host' => 'localhost',
                    'Content-Type' => 'application/json',
                ]);
                $client->post('/api', json_encode([
                    'text' => $datax['prompt'],
                ]));
                $recv = $client->getBody();
                $client->close();
                $obj = json_decode($recv, true);
                $obj = $obj['parts'][0];

                var_dump($obj);
                // remover a primeira linha
                $obj = explode("\n", $obj);
                array_shift($obj);
                $obj = implode("\n", $obj);
                $obj = str_replace('```', '', $obj);

                return $response->end($obj);
            }
        }

        if (empty($GLOBALS['codexKey'])) self::makeToken();
        $data = json_decode($data, true);
        $client = new \Swoole\Coroutine\Http\Client('copilot-proxy.githubusercontent.com', 443, true);
        $client->set([
            'timeout' => 10,
            'ssl_verify_peer' => false,
            'ssl_verify_peer_name' => false,
            'keep_alive' => true,
            'enable_keep_alive' => true,
        ]);
        $client->setHeaders([
            'Host' => 'copilot-proxy.githubusercontent.com',
            'authorization' => 'Bearer ' . $GLOBALS['codexKey'],
            'x-request-id' => 'e3688f16-de24-49e0-a066-a30162f38e85',
            'openai-organization' => 'github-copilot',
            'vscode-sessionid' => '4013bd90-1a49-426b-b5ce-2e6f1ba6a4d01720456843509',
            'vscode-machineid' => '969057a4c7338ab2465725c3737555dd972d40cac26cd358b1c226679a62a8d2',
            'editor-version' => 'JetBrains-PS/242.19890.20',
            'editor-plugin-version' => 'copilot-intellij/1.5.44-243',
            'openai-intent' => 'copilot-ghost',
            'content-type' => 'application/json',
            'user-agent' => 'GithubCopilot/1.211.0',
            'accept' => '*/*',
        ]);


        $postData = [
            "prompt" => $data['prompt'],
            "max_tokens" => $data['max_tokens'] ?? 1000,
            "temperature" => $data['temperature'] ?? 0.2,
            "top_p" => $data['top_p'] ?? 0.95,
            "n" => $data['n'] ?? 3,
            "stop" => !empty($data['stop']) ? $data['stop'] : ["\n\n\n", "<?php", "class ", "function ", "namespace "],
            "stream" => true,
            "extra" => [
                "language" => $data['language'] ?? "php",
                "trim_by_indentation" => true,
                "next_indent" => $data['next_indent'] ?? 0,
                "prompt_tokens" => $data['prompt_tokens'] ?? null,
                "suffix_tokens" => $data['suffix_tokens'] ?? null
            ]
        ];
        if (!empty($data['suffix'])) $postData['suffix'] = $data['suffix'];


        $postData = json_encode($postData);
        $client->post('/v1/engines/copilot-codex/completions', $postData);
        $recv = $client->getBody();
        var_dump($recv);
        $client->close();
        if (empty($recv)) {
            self::makeToken();
            return $response->end('Token expired');
        } elseif (str_contains($recv, 'unauthorized: token expired')) {
            self::makeToken();
            return $response->end('Token expired');
        }

        $parts = explode('data: ', $recv);

        $suggestions = [];
        for ($i = 1; $i < count($parts); $i++) {
            $chunk = trim($parts[$i]);
            if ($chunk === '[DONE]') break;

            $data = json_decode($chunk, true);
            if (!empty($data['choices'])) {
                foreach ($data['choices'] as $choice) {
                    $index = $choice['index'] ?? 0;
                    if (!isset($suggestions[$index])) {
                        $suggestions[$index] = '';
                    }
                    $suggestions[$index] .= $choice['text'] ?? '';
                }
            }
        }

        // Retorna todas as sugestões ou apenas a primeira
        // Compatibilidade: se n=1, retorna texto simples, senão JSON
        if (count($suggestions) === 1) {
            $response->header('Content-Type', 'text/plain');
            return $response->end($suggestions[0] ?? '');
        } else {
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode([
                'suggestions' => array_values($suggestions),
                'count' => count($suggestions)
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private static function makeToken(): void
    {
        $client = new \Swoole\Coroutine\Http\Client('api.github.com', 443, true);
        $client->set([
            'timeout' => 10,
            'ssl_verify_peer' => false,
            'ssl_verify_peer_name' => false,
            'keep_alive' => true,
            'enable_keep_alive' => true,
        ]);
        $client->setHeaders([
            'Host' => 'api.github.com',
            //'authorization' => 'token OS CARA NAO DEIXOU COMPARTILHAR A PARADA',
            'editor-version' => 'JetBrains-PS/242.19890.20',
            'editor-plugin-version' => 'copilot-intellij/1.5.44-243',
            'user-agent' => 'GithubCopilot/1.211.0',
            'accept' => '*/*',
        ]);
        $client->get('/copilot_internal/v2/token');
        $recv = $client->getBody();
        $client->close();
        $parts = explode('"token":"', $recv);
        $GLOBALS['codexKey'] = explode('"', $parts[1])[0];
    }


}
