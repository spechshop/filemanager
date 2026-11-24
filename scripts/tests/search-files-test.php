<?php
declare(strict_types=1);

use plugins\Request\searchInFile;
use plugins\Request\securityPolicy;

$project = dirname(__DIR__, 2);
foreach (['appController', 'securityPolicy', 'safeFiles'] as $component) require $project . '/plugins/Request/components/' . $component . '.php';
require $project . '/plugins/Request/apps/searchInFile.php';

$tmp = sys_get_temp_dir() . '/fm-search-' . bin2hex(random_bytes(10));
mkdir($tmp, 0700);
mkdir($tmp . '/files');
mkdir($tmp . '/files/nested');
mkdir($tmp . '/outside');
$previousTokens = getenv('FILEMANAGER_TOKENS_FILE');
putenv('FILEMANAGER_TOKENS_FILE=' . $tmp . '/tokens.json');
$tokens = ['search-fixture' => ['expire' => time() + 60, 'allowedRoots' => [$tmp . '/files']]];
file_put_contents($tmp . '/tokens.json', json_encode($tokens));
$count = 0;
function checkSearch(bool $condition, string $message): void
{
    global $count;
    if (!$condition) throw new RuntimeException($message);
    $count++;
    echo "PASS $message\n";
}
function searchDenied(callable $operation): bool
{
    try { $operation(); } catch (DomainException $e) { return true; }
    return false;
}

try {
    file_put_contents($tmp . '/files/.env', 'TOKEN=root-fixture');
    file_put_contents($tmp . '/files/nested/.env', 'token=nested-fixture');
    file_put_contents($tmp . '/outside/secret', 'TOKEN=outside-fixture');
    symlink('missing', $tmp . '/files/broken');
    symlink($tmp . '/outside', $tmp . '/files/escape');
    symlink($tmp . '/files', $tmp . '/files/nested/loop');

    $results = searchInFile::searchString($tmp . '/files', 'TOKEN=', 100, 'search-fixture');
    sort($results);
    checkSearch($results === [$tmp . '/files/.env', $tmp . '/files/nested/.env'], 'read-only search skips broken and escaping links and terminates directory cycles');
    checkSearch(count(searchInFile::searchString($tmp . '/files', 'TOKEN=', 1, 'search-fixture')) === 1, 'search respects its result limit');
    checkSearch(searchDenied(static fn() => securityPolicy::tree($tmp . '/files', 'search-fixture')), 'mutation preflight remains strict');
    checkSearch(searchDenied(static fn() => searchInFile::searchString($tmp . '/outside', 'TOKEN=', 100, 'search-fixture')), 'a forbidden starting directory is still denied');
    checkSearch(searchDenied(static fn() => iterator_to_array(searchInFile::walk($tmp . '/files/missing', 'search-fixture'))), 'a missing starting directory reports failure');
    checkSearch(!searchInFile::fileContainsString($tmp . '/outside/secret', 'TOKEN=', 'search-fixture'), 'per-file reads cannot expose outside content');

    mkdir($tmp . '/files/vanished-directory');
    file_put_contents($tmp . '/files/vanished-file', 'TOKEN=vanished');
    $removedDirectory = $removedFile = false;
    foreach (searchInFile::walk($tmp . '/files', 'search-fixture') as $entry) {
        if ($entry['path'] === $tmp . '/files/vanished-directory') {
            rmdir($entry['path']);
            $removedDirectory = true;
        }
        if ($entry['path'] === $tmp . '/files/vanished-file') {
            unlink($entry['path']);
            checkSearch(!searchInFile::fileContainsString($entry['path'], 'TOKEN=', 'search-fixture'), 'a file removed after listing does not abort the search');
            $removedFile = true;
        }
    }
    checkSearch($removedDirectory && $removedFile, 'traversal continues after a listed child directory disappears');

    // Batch search must keep descriptor confinement and literal semantics.
    $batchFiles = [];
    for ($i = 0; $i < 70; $i++) {
        $name = $tmp . '/files/batch-' . $i . '.txt';
        file_put_contents($name, $i === 3 || $i === 68 ? 'needle=' . $i : 'unrelated');
        $batchFiles[] = $name;
    }
    $batchMatches = [];
    foreach (array_chunk($batchFiles, 64) as $group) {
        foreach (\plugins\Request\safeFiles::searchFiles($group, 'NEEDLE=', 'search-fixture') as $index) {
            $batchMatches[] = $group[$index];
        }
    }
    sort($batchMatches);
    checkSearch($batchMatches === [$batchFiles[3], $batchFiles[68]], 'batched search spans groups of 64 and preserves case-insensitive matches');
    checkSearch(\plugins\Request\safeFiles::searchFiles([$tmp . '/outside/secret', $tmp . '/files/batch-3.txt'], 'needle=', 'search-fixture') === [1], 'batch search cannot expose outside content');
    checkSearch(\plugins\Request\safeFiles::searchFiles([$tmp . '/files/broken', $tmp . '/files/batch-3.txt'], 'needle=', 'search-fixture') === [1], 'broken links do not abort batch search');
    checkSearch(searchDenied(static fn() => \plugins\Request\safeFiles::searchFiles(array_fill(0, 65, $batchFiles[0]), 'needle=', 'search-fixture')), 'oversized search batches are rejected');

    // Exercise multi-directory batching, confinement and cycles independently.
    mkdir($tmp . '/files/folders');
    $expected = [];
    for ($i = 0; $i < 75; $i++) {
        $folder = $tmp . '/files/folders/dir-' . $i;
        mkdir($folder);
        file_put_contents($folder . '/item.txt', $i % 17 === 0 ? 'DIR-NEEDLE' : 'no match');
        if ($i % 17 === 0) $expected[] = $folder . '/item.txt';
    }
    sort($expected);
    $actual = searchInFile::searchString($tmp . '/files/folders', 'DIR-NEEDLE', 100, 'search-fixture');
    sort($actual);
    checkSearch($actual === $expected, 'batched directory traversal finds files in more than 64 directories');
    $directories = array_map(static fn($i) => $tmp . '/files/folders/dir-' . $i, range(0, 74));
    $groups = [];
    foreach (array_chunk($directories, 64) as $items) $groups = array_merge($groups, \plugins\Request\safeFiles::listingMany($items, 'search-fixture'));
    checkSearch(count($groups) === 75 && count(array_filter($groups, 'is_array')) === 75, 'directory broker handles more than one batch');
    checkSearch(searchDenied(static fn() => \plugins\Request\safeFiles::listingMany(array_fill(0, 65, $tmp . '/files'), 'search-fixture')), 'directory broker refuses oversized batches');
    $groups = \plugins\Request\safeFiles::listingMany([$tmp . '/outside', $tmp . '/files/folders/dir-0'], 'search-fixture');
    checkSearch($groups[0] === null && count($groups[1]) === 1, 'batched directory listing cannot expose outside root');

    $chunk = searchInFile::getSearchChunkSize();
    file_put_contents($tmp . '/files/boundary', str_repeat('x', $chunk - 3) . 'TOKEN=boundary');
    checkSearch(searchInFile::fileContainsString($tmp . '/files/boundary', 'TOKEN=', 'search-fixture'), 'literal matches spanning read chunks are found');
    checkSearch(searchDenied(static fn() => \plugins\Request\safeFiles::searchFiles([$tmp . '/files/batch-3.txt'], str_repeat('n', 4097), 'search-fixture')), 'oversized search term is rejected');
    $tokens['search-fixture']['revoked'] = true;
    file_put_contents($tmp . '/tokens.json', json_encode($tokens));
    checkSearch(searchDenied(static fn() => searchInFile::fileContainsString($tmp . '/files/.env', 'TOKEN=', 'search-fixture')), 'revocation is not swallowed as an unreadable file');
    checkSearch(searchDenied(static fn() => \plugins\Request\safeFiles::searchFiles([$tmp . '/files/batch-3.txt'], 'needle=', 'search-fixture')), 'batch search rejects revoked token');
    checkSearch(searchDenied(static fn() => iterator_to_array(searchInFile::walk($tmp . '/files', 'search-fixture'))), 'revocation stops traversal');
    echo "$count assertions passed\n";
} finally {
    putenv($previousTokens === false ? 'FILEMANAGER_TOKENS_FILE' : 'FILEMANAGER_TOKENS_FILE=' . $previousTokens);
    $clean = function (string $path) use (&$clean): void {
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $clean($path . '/' . $name);
        rmdir($path);
    };
    $clean($tmp);
}
