<?php
header('Content-Type: application/json');

$project_id = (int)($_GET['project_id'] ?? 0);
$method     = $_SERVER['REQUEST_METHOD'];

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$base = realpath($project['path']);
if (!$base) { echo json_encode(['error' => 'Project path not accessible']); exit; }

$posts_dir  = $base . '/source/_posts';
$drafts_dir = $base . '/source/_drafts';
$pages_dir  = $base . '/source';

// ── LIST ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $type = $_GET['type'] ?? 'post';

    if ($type === 'draft') {
        $dir = $drafts_dir;
    } elseif ($type === 'page') {
        $dir = $pages_dir;
    } else {
        $dir = $posts_dir;
    }

    if (!is_dir($dir)) {
        echo json_encode(['items' => [], 'missing_dir' => true]);
        exit;
    }

    $items = [];
    if ($type === 'post' || $type === 'draft') {
        $srcDir  = ($type === 'draft') ? $drafts_dir : $posts_dir;
        $srcPfx  = ($type === 'draft') ? 'source/_drafts/' : 'source/_posts/';
        // Recursive scan — posts/drafts may live in subdirs
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'md') continue;
            $abs    = $file->getPathname();
            $relp   = ltrim(str_replace($srcDir, '', $abs), '/');
            $fm     = parseFrontMatter(file_get_contents($abs));
            $items[] = [
                'filename' => $file->getFilename(),
                'relpath'  => $relp,
                'path'     => $srcPfx . $relp,
                'folder'   => ltrim(dirname($relp), '.'),
                'title'      => $fm['title'] ?? basename($abs, '.md'),
                'date'       => $fm['date'] ?? null,
                'modified'   => filemtime($abs),
                'tags'       => $fm['tags'] ?? [],
                'categories' => $fm['categories'] ?? [],
            ];
        }
    } else {
        // Pages: source/*.md + configured subdirectories (default: p, pages)
        $pdStmt = $db->prepare('SELECT value FROM project_settings WHERE project_id = ? AND key = ?');
        $pdStmt->execute([$project_id, 'page_dirs']);
        $pdVal     = $pdStmt->fetchColumn();
        $extraDirs = $pdVal !== false
            ? array_filter(array_map('trim', explode("\n", $pdVal)))
            : ['p', 'pages'];

        // Top-level pages
        foreach (glob($pages_dir . '/*.md') as $file) {
            $fm      = parseFrontMatter(file_get_contents($file));
            $items[] = [
                'filename'   => basename($file),
                'relpath'    => basename($file),
                'path'       => 'source/' . basename($file),
                'folder'     => '',
                'title'      => $fm['title'] ?? basename($file, '.md'),
                'date'       => $fm['date'] ?? null,
                'modified'   => filemtime($file),
                'tags'       => $fm['tags'] ?? [],
                'categories' => $fm['categories'] ?? [],
            ];
        }

        // Subdirectory pages
        foreach ($extraDirs as $pd) {
            $subdir = $pages_dir . '/' . $pd;
            if (!is_dir($subdir)) continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($subdir,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS)
            );
            foreach ($it as $file) {
                if ($file->getExtension() !== 'md') continue;
                $abs    = $file->getPathname();
                $relp   = ltrim(str_replace($pages_dir, '', $abs), '/');
                $fm     = parseFrontMatter(file_get_contents($abs));
                $folder = ltrim(dirname($relp), '.');
                $items[] = [
                    'filename' => $file->getFilename(),
                    'relpath'  => $relp,
                    'path'     => 'source/' . $relp,
                    'folder'   => $folder,
                    'title'    => $fm['title'] ?? basename($abs, '.md'),
                    'date'     => $fm['date'] ?? null,
                    'modified' => filemtime($abs),
                ];
            }
        }
    }

    usort($items, fn($a, $b) => strcmp($b['date'] ?? '0', $a['date'] ?? '0'));
    echo json_encode(['items' => $items]);
    exit;
}

// ── CREATE ────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $type   = $input['type'] ?? 'post';
    $title  = trim($input['title'] ?? 'Untitled');
    $slug   = trim($input['slug'] ?? '') ?: slugify($title);
    $folder = trim($input['folder'] ?? '');   // e.g. "2026" or "2026/travel"
    $body   = $input['body'] ?? '';
    $fm     = trim($input['frontmatter'] ?? '');

    $date = date('Y-m-d H:i:s');
    if (!$fm) {
        $fm = "---\ntitle: \"" . addslashes($title) . "\"\ndate: $date\ntags: []\n---";
    }

    // Handle draft→post publish action
    if (($input['action'] ?? '') === 'publish') {
        $relpath = trim($input['relpath'] ?? '');
        if (!$relpath || !str_ends_with($relpath, '.md')) {
            echo json_encode(['error' => 'Invalid relpath']); exit;
        }
        $src = realpath($drafts_dir . '/' . $relpath);
        if (!$src || !str_starts_with($src . '/', $drafts_dir . '/')) {
            http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
        }
        $folder = ltrim(dirname($relpath), '.');
        $dest   = $folder ? $posts_dir . '/' . $folder : $posts_dir;
        if (!is_dir($dest)) mkdir($dest, 0755, true);
        rename($src, $dest . '/' . basename($relpath));
        Audit::log($db, 'post_publish', $project_id, 'source/_posts/' . $relpath);
        echo json_encode(['ok' => true, 'path' => 'source/_posts/' . $relpath]);
        exit;
    }

    // Handle duplicate action
    if (($input['action'] ?? '') === 'duplicate') {
        $relpath = trim($input['relpath'] ?? '');
        $srcType = $input['type'] ?? 'post';
        if (!$relpath || !str_ends_with($relpath, '.md')) {
            echo json_encode(['error' => 'Invalid relpath']); exit;
        }
        $srcBase = ($srcType === 'draft') ? $drafts_dir : $posts_dir;
        $src     = realpath($srcBase . '/' . $relpath);
        if (!$src || !str_starts_with($src . '/', $srcBase . '/')) {
            http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
        }
        $info     = pathinfo($src);
        $newName  = $info['filename'] . '-copy.' . $info['extension'];
        $dest     = $info['dirname'] . '/' . $newName;
        // Avoid collision
        $i = 2;
        while (file_exists($dest)) {
            $dest = $info['dirname'] . '/' . $info['filename'] . '-copy' . $i . '.' . $info['extension'];
            $i++;
        }
        copy($src, $dest);
        $newRelpath = ltrim(str_replace($srcBase, '', $dest), '/');
        $srcPfx     = ($srcType === 'draft') ? 'source/_drafts/' : 'source/_posts/';
        Audit::log($db, 'post_duplicate', $project_id, $srcPfx . $newRelpath);
        echo json_encode(['ok' => true, 'relpath' => $newRelpath, 'path' => $srcPfx . $newRelpath]);
        exit;
    }

    if ($type === 'page') {
        $dir = $pages_dir;
    } elseif ($type === 'draft') {
        $dir = $folder ? $drafts_dir . '/' . ltrim($folder, '/') : $drafts_dir;
        $real = realpath($dir) ?: $dir;
        if (realpath($drafts_dir) && !str_starts_with($real . '/', realpath($drafts_dir) . '/')) {
            http_response_code(403); echo json_encode(['error' => 'Invalid folder']); exit;
        }
    } else {
        $dir = $folder ? $posts_dir . '/' . ltrim($folder, '/') : $posts_dir;
        // Security: ensure target stays inside posts_dir
        $real = realpath($dir) ?: $dir;
        if (realpath($posts_dir) && !str_starts_with($real . '/', realpath($posts_dir) . '/')) {
            http_response_code(403); echo json_encode(['error' => 'Invalid folder']); exit;
        }
    }
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = $slug . '.md';
    $filepath = $dir . '/' . $filename;
    if (file_exists($filepath) && !($input['overwrite'] ?? false)) {
        echo json_encode(['error' => 'File already exists', 'filename' => $filename]);
        exit;
    }

    file_put_contents($filepath, $fm . "\n\n" . $body);
    $pfx     = match($type) {
        'page'  => 'source/',
        'draft' => 'source/_drafts/' . ($folder ? $folder . '/' : ''),
        default => 'source/_posts/' . ($folder ? $folder . '/' : ''),
    };
    $relPost = $pfx . $filename;
    Audit::log($db, 'post_create', $project_id, $relPost);
    echo json_encode(['ok' => true, 'filename' => $filename, 'path' => $relPost]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $relpath = trim($input['relpath'] ?? '');  // relative to posts_dir or pages_dir
    $type    = $input['type'] ?? 'post';

    if (!$relpath || !str_ends_with($relpath, '.md')) {
        echo json_encode(['error' => 'Invalid path']); exit;
    }
    $dir  = match($type) {
        'page'  => $pages_dir,
        'draft' => $drafts_dir,
        default => $posts_dir,
    };
    $path = realpath($dir . '/' . $relpath);
    if (!$path || !str_starts_with($path . '/', $dir . '/')) {
        http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
    }
    unlink($path);
    $pfx = match($type) { 'page' => 'source/', 'draft' => 'source/_drafts/', default => 'source/_posts/' };
    Audit::log($db, 'post_delete', $project_id, $pfx . $relpath);
    echo json_encode(['ok' => true]);
    exit;
}

function parseFrontMatter(string $content): array {
    if (!str_starts_with($content, '---')) return [];
    $end = strpos($content, '---', 3);
    if (!$end) return [];
    $yaml = substr($content, 3, $end - 3);
    preg_match('/^title:\s*["\']?(.+?)["\']?\s*$/m', $yaml, $tm);
    preg_match('/^date:\s*(.+)$/m', $yaml, $dm);
    return [
        'title'      => $tm[1] ?? null,
        'date'       => $dm[1] ?? null,
        'tags'       => extractYamlList($yaml, 'tags'),
        'categories' => extractYamlList($yaml, 'categories', 'category'),
    ];
}

function extractYamlList(string $yaml, string $key, ?string $altKey = null): array {
    $kq = preg_quote($key, '/');

    // 1. Inline array on same line:  key: [a, b, c]
    if (preg_match('/^' . $kq . ':[ \t]*\[(.+?)\]\s*$/m', $yaml, $m)) {
        return array_values(array_filter(array_map(
            fn($s) => trim($s, "\"' "), explode(',', $m[1])
        )));
    }

    // 2. Block list (any leading indent, incl. none):  key:\n- a\n- b
    //    Checked BEFORE single-value form so that `\s*` in the single-value
    //    pattern can't cannibalise the dash-prefixed lines below the key.
    if (preg_match('/^' . $kq . ':[ \t]*\n((?:[ \t]*-\s*.+\n?)+)/m', $yaml, $m)) {
        preg_match_all('/^[ \t]*-\s*(.+?)\s*$/m', $m[1], $items);
        $out = [];
        foreach ($items[1] as $item) {
            $item = trim($item, "\"' ");
            // Hexo nested category form  - [Foo, Bar]  → take first element
            if ($item !== '' && $item[0] === '[') {
                $inner = trim($item, "[]");
                $first = trim(explode(',', $inner)[0] ?? '', "\"' ");
                if ($first !== '') $out[] = $first;
            } elseif ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values($out);
    }

    // 3. Single value on the same line as the key:  key: foo
    //    Whitespace must be tab/space (not newline) so this can't hop into a
    //    block list on the next line.
    if (preg_match('/^' . $kq . ':[ \t]+([^\s\[].*?)\s*$/m', $yaml, $m)) {
        return [trim($m[1], "\"' ")];
    }

    // 4. Alt-key fallback (e.g. `category: foo` for `categories`)
    if ($altKey && preg_match('/^' . preg_quote($altKey, '/') . ':[ \t]+(.+?)\s*$/m', $yaml, $m)) {
        return [trim($m[1], "\"' ")];
    }

    return [];
}

function slugify(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'post-' . time();
}
