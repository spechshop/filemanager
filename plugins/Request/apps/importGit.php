<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
class importGit
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response)) return true;
        $url = $request->get['url'] ?? '';
        // An explicit host allowlist prevents SSRF, redirects and custom Git transports.
        $hosts = array_filter(explode(',', getenv('FILEMANAGER_GIT_HOSTS') ?: 'github.com,gitlab.com,bitbucket.org'));
        $parts = is_string($url) ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', $hosts, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('~^/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+(?:\.git)?$~D', $parts['path'] ?? '')) return security::deny($response);
        $owner = securityPolicy::requestToken($request);
        $root = securityPolicy::roots($owner)[0];
        $destination = securityPolicy::path($root . '/' . preg_replace('/\.git$/', '', basename($parts['path'])), $owner, false);
        // argv form bypasses the shell; never accept Git options from the client.
        $pipes = [];
        $process = proc_open(['git', '-c', 'http.followRedirects=false', '-c', 'protocol.file.allow=never', '-c', 'protocol.ext.allow=never', 'clone', '--', $url, $destination], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $root, ['PATH' => getenv('PATH'), 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0']);
        if (isset($pipes[0])) fclose($pipes[0]);
        $ok = is_resource($process) && proc_close($process) === 0;
        return $response->end(json_encode(['success' => $ok, 'information' => $ok ? 'ok' : 'Clone failed']));
    }
}
