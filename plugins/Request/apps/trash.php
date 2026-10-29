<?php
declare(strict_types=1);
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;

class trash
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $response->header('Content-Type', 'application/json');
        $owner = securityPolicy::requestToken($request);
        $data = ($request->post ?? []) ?: json_decode($request->rawContent() ?: '{}', true);
        $data = is_array($data) ? $data : [];
        $action = $data['action'] ?? $request->get['action'] ?? 'list';
        if ($action === 'list') return $response->end(json_encode(safeFiles::trash('list', $owner), JSON_UNESCAPED_UNICODE));
        if (($request->server['request_method'] ?? '') !== 'POST') {
            $response->status(405); $response->header('Allow', 'GET, POST');
            return $response->end(json_encode(['success' => false, 'message' => 'Use POST para alterar a lixeira.']));
        }
        if ($action === 'move') $result = recycleBin::move($data['files'] ?? [], $owner);
        elseif (in_array($action, ['restore', 'purge', 'empty'], true)) {
            $ids = $action === 'empty' ? array_column(safeFiles::trash('list', $owner)['entries'] ?? [], 'id') : ($data['ids'] ?? []);
            if (!is_array($ids) || ($action !== 'empty' && (count($ids) > 500 || !$ids))) throw new \DomainException('trash selection');
            foreach ($ids as $id) if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \DomainException('trash id');
            $result = recycleBin::batch($action === 'empty' ? 'purge' : $action, array_values(array_unique($ids)), $owner, ($data['conflict'] ?? '') === 'rename');
        } else {
            $response->status(400);
            $result = ['success' => false, 'message' => 'Operação de lixeira inválida.'];
        }
        return $response->end(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}
