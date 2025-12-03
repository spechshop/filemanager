<?php

namespace plugins\Request;

use Swoole\Coroutine\Http\Client;
use Swoole\Http\Response;
use Swoole\Http\Request;

class chat4o
{
    public static function api(Request $request, Response $response): ?bool
    {
        // if (!security::verifyToken($request)) return security::invalidToken($response);
        $data = json_decode($request->rawContent(), true);

        $path = $request->server["request_uri"] ?? "";
        $conversationFile = appController::baseDir() . "/4o.json";
        $conversation = loadConversation($conversationFile);

        if ($data["action"] === "getConversation") {
            $conversation = loadConversation($conversationFile);
            $response->header("Content-Type", "application/json");
            return $response->end(
                json_encode([
                    "status" => "success",
                    "conversation" => $conversation,
                ])
            );
        }

        $message = $data["message"];
        $conversationFile = appController::baseDir() . "/4o.json";
        $endPoint = "http://localhost:3090/api";
        $client = new \Swoole\Coroutine\Http\Client("localhost", 3090);
        $client->set([
            "timeout" => 120,
            "ssl_verify_peer" => false,
            "ssl_verify_peer_name" => false,
            "keep_alive" => true,
            "enable_keep_alive" => true,
        ]);
        $client->setHeaders([
            "Host" => "localhost",
            "Content-Type" => "application/json",
        ]);
        $client->post(
            "/api",
            json_encode([
                "text" => $message,
            ])
        );
        $recv = $client->getBody();
        var_dump($recv);
        $client->close();
        $obj = json_decode($recv, true);
        $reply = $obj["parts"][0];

        // Adicione a resposta da OpenAI à conversa
        $conversation[] = [
            "role" => "user",
            "content" => [["type" => "text", "text" => $message]],
        ];
        $conversation[] = [
            "role" => "assistant",
            "content" => [["type" => "text", "text" => $reply]],
        ];

        // Salve a conversa atualizada
        saveConversation($conversationFile, $conversation);

        // Envie a resposta de volta para o cliente
        $response->header("Content-Type", "application/json");
        return $response->end(
            json_encode([
                "status" => "success",
                "message" => $reply,
            ])
        );
    }
}

function loadConversation($file)
{
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true) ?? [];
    }
    return [];
}

function saveConversation($file, $conversation)
{
    file_put_contents($file, json_encode($conversation, JSON_PRETTY_PRINT));
}
