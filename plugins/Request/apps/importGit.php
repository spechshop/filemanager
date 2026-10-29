<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
require_once __DIR__ . '/../components/safeProcess.php';
class importGit
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $url = $request->get['url'] ?? '';
        // An explicit host allowlist prevents SSRF, redirects and custom Git transports.
        $hosts = array_filter(explode(',', getenv('FILEMANAGER_GIT_HOSTS') ?: 'github.com,gitlab.com,bitbucket.org'));
        $parts = is_string($url) ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', $hosts, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('~^/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+(?:\.git)?$~D', $parts['path'] ?? '')) return security::deny($response);
        $owner = securityPolicy::requestToken($request);
        $root = securityPolicy::path($request->get['path'] ?? securityPolicy::initialPath($owner), $owner);
        $destination = securityPolicy::path($root . '/' . preg_replace('/\.git$/', '', basename($parts['path'])), $owner, false);
        // Clone into a private tree first. Git never receives an authorized live directory.
        $staging = securityPolicy::temporaryDirectory() . '/fm-git-' . bin2hex(random_bytes(16));
        if (!mkdir($staging, 0700)) throw new \RuntimeException('git staging');
        $checkout = $staging . '/checkout';
        $ok = false;
        try {
            if (!securityPolicy::validToken($owner)) throw new \DomainException('revoked');
            $result = safeProcess::run(['git', '-c', 'http.sslVerify=true', '-c', 'http.followRedirects=false', '-c', 'protocol.file.allow=never', '-c', 'protocol.ext.allow=never', '-c', 'core.hooksPath=/dev/null', 'clone', '--', $url, $checkout],
                $owner, false, $staging,
                ['PATH' => getenv('PATH'), 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0']);
            $ok = $result['exit'] === 0;
            if ($ok) safeFiles::publishGit($checkout, $destination, $owner);
        } finally {
            $clean = function (string $path) use (&$clean): void {
                if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
                foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $clean($path . '/' . $name);
                rmdir($path);
            };
            $clean($staging);
        }
        return $response->end(json_encode(['success' => $ok, 'information' => $ok ? 'ok' : 'Clone failed']));
    }
}
