<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

$project_id = (int)($_GET['project_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? 'scan';

    if ($action === 'scan') {
        @set_time_limit(300);
        $result = scanProjectLinks($db, $project);
        Audit::log($db, 'link_scan', $project_id,
            "broken={$result['broken']} of {$result['total']}");
        echo json_encode(['ok' => true, 'run_id' => $result['run_id']] + $result);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// GET — latest run + its results
$run = $db->prepare('SELECT * FROM link_check_runs WHERE project_id = ?
                     ORDER BY id DESC LIMIT 1');
$run->execute([$project_id]);
$lastRun = $run->fetch();
if (!$lastRun) { echo json_encode(['run' => null, 'results' => []]); exit; }

$onlyBroken = !empty($_GET['broken_only']);
$where = 'run_id = ?';
$args  = [$lastRun['id']];
if ($onlyBroken) {
    $where .= ' AND (status_code IS NULL OR status_code >= 400)';
}
$res = $db->prepare("SELECT * FROM link_check_results WHERE $where ORDER BY status_code DESC, source");
$res->execute($args);
echo json_encode(['run' => $lastRun, 'results' => $res->fetchAll()]);


function scanProjectLinks(PDO $db, array $project): array {
    $base = realpath($project['path']);
    $siteUrl = rtrim((string)($project['url'] ?? ''), '/');
    $pid = (int)$project['id'];

    $db->prepare('INSERT INTO link_check_runs (project_id) VALUES (?)')->execute([$pid]);
    $runId = (int)$db->lastInsertId();

    // Collect markdown files
    $files = [];
    foreach (['source/_posts', 'source/_drafts', 'source'] as $sub) {
        $dir = $base . '/' . $sub;
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'md') {
                $files[] = ['abs' => $f->getPathname(),
                            'rel' => ltrim(str_replace($base, '', $f->getPathname()), '/')];
            }
        }
    }

    // Extract links per file
    $byUrl = []; // url => [ [source, ...] ]
    foreach ($files as $f) {
        $content = file_get_contents($f['abs']);
        if ($content === false) continue;
        $urls = extractLinks($content);
        foreach ($urls as $u) {
            $byUrl[$u][] = $f['rel'];
        }
    }

    // Resolve relative/internal URLs against project URL
    $jobs = []; // url => fetchUrl
    foreach (array_keys($byUrl) as $u) {
        $fetch = resolveLink($u, $siteUrl);
        if ($fetch !== null) $jobs[$u] = $fetch;
    }

    $statuses = parallelCheck($jobs);

    $insRes = $db->prepare(
        'INSERT INTO link_check_results (run_id, project_id, url, source, status_code, error)
         VALUES (?, ?, ?, ?, ?, ?)');
    $total = 0; $broken = 0;
    foreach ($byUrl as $url => $sources) {
        $st = $statuses[$url] ?? null;
        $status = $st['code'] ?? null;
        $error  = $st['error'] ?? null;
        $isBroken = $status === null || $status >= 400;
        foreach ($sources as $src) {
            $insRes->execute([$runId, $pid, $url, $src, $status, $error]);
            $total++;
            if ($isBroken) $broken++;
        }
    }
    $db->prepare('UPDATE link_check_runs SET finished_at = CURRENT_TIMESTAMP,
                  total_links = ?, broken = ? WHERE id = ?')
       ->execute([$total, $broken, $runId]);

    return ['run_id' => $runId, 'total' => $total, 'broken' => $broken];
}

function extractLinks(string $content): array {
    $urls = [];
    // Markdown links [text](url)
    if (preg_match_all('/\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $content, $m)) {
        foreach ($m[1] as $u) $urls[] = $u;
    }
    // HTML href="..."
    if (preg_match_all('/href=["\']([^"\']+)["\']/i', $content, $m)) {
        foreach ($m[1] as $u) $urls[] = $u;
    }
    // Bare URLs (markdown auto-link)
    if (preg_match_all('/<(https?:\/\/[^>]+)>/', $content, $m)) {
        foreach ($m[1] as $u) $urls[] = $u;
    }

    return array_values(array_unique(array_filter(array_map('trim', $urls), function ($u) {
        if ($u === '' || $u[0] === '#') return false;
        if (str_starts_with($u, 'mailto:'))  return false;
        if (str_starts_with($u, 'tel:'))     return false;
        if (str_starts_with($u, 'javascript:')) return false;
        if (str_starts_with($u, 'data:'))    return false;
        return true;
    })));
}

function resolveLink(string $url, string $siteUrl): ?string {
    if (preg_match('#^https?://#i', $url)) return $url;
    if ($url[0] === '/' && $siteUrl !== '') return $siteUrl . $url;
    // Pure relative refs (./foo, ../foo, foo) — can't resolve without post URL context
    return null;
}

function parallelCheck(array $jobs): array {
    if (!$jobs) return [];
    if (!function_exists('curl_multi_init')) {
        $out = [];
        foreach ($jobs as $key => $url) $out[$key] = singleCheck($url);
        return $out;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($jobs as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT      => 'HackmanCMS-LinkChecker/1.0',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.5); } while ($running > 0);

    $out = [];
    foreach ($handles as $key => $ch) {
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
        $err  = curl_error($ch) ?: null;
        // Some servers reject HEAD; retry with GET for non-2xx HEAD failures
        if (($code === null || $code === 0 || $code === 405 || $code === 403) && $err === '') {
            $code = singleCheck($jobs[$key])['code'] ?? $code;
        }
        $out[$key] = ['code' => $code ?: null, 'error' => $err ?: null];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function singleCheck(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => 'HackmanCMS-LinkChecker/1.0',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RANGE          => '0-1024',
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
    $err  = curl_error($ch) ?: null;
    curl_close($ch);
    return ['code' => $code ?: null, 'error' => $err ?: null];
}
