<?php
// ============================================================
// ROUTER PRINCIPAL - IndicatorDATA
// ============================================================
header('Content-Language: fr');
header('X-Content-Language: fr');

$uri      = $_SERVER['REQUEST_URI'] ?? '/';
$urlPath  = trim(parse_url($uri, PHP_URL_PATH), '/');
$segments = explode('/', $urlPath);

// Détecter si on est dans un sous-dossier (laragon) ou à la racine (railway)
$pos = array_search('cacao-pwa', $segments);
if ($pos !== false) {
    $local = implode('/', array_slice($segments, $pos + 1));
} else {
    $local = $urlPath;
}
$local = trim($local, '/');

// ── Fichiers spéciaux PHP ─────────────────────────────────
$phpFiles = ['setup.php', 'migrate.php', 'ping.php'];
foreach ($phpFiles as $f) {
    if ($local === $f && file_exists(__DIR__ . '/' . $f)) {
        include __DIR__ . '/' . $f;
        exit;
    }
}

// ── Ping rapide ───────────────────────────────────────────
if ($local === 'ping') {
    header('Content-Type: application/json');
    echo json_encode(['status'=>'ok','php'=>PHP_VERSION,'env'=>getenv('RAILWAY_ENVIRONMENT')?'railway':'local']);
    exit;
}

// ── API ───────────────────────────────────────────────────
if ($local === 'api' || strpos($local, 'api/') === 0) {
    $apiPath = trim(substr($local, strpos($local, 'api/') === 0 ? 4 : 3), '/');
    // Nettoyer query string
    $apiPath = explode('?', $apiPath)[0];
    $_GET['_path'] = $apiPath;
    header('Content-Type: application/json; charset=utf-8');
    include __DIR__ . '/api/index.php';
    exit;
}

// ── Fichiers statiques ────────────────────────────────────
if (!empty($local) && $local !== 'index.php') {
    $file = __DIR__ . '/' . $local;
    if (file_exists($file) && is_file($file)) {
        $ext   = strtolower(pathinfo($local, PATHINFO_EXTENSION));
        $mimes = ['js'=>'application/javascript','css'=>'text/css','png'=>'image/png',
                  'jpg'=>'image/jpeg','ico'=>'image/x-icon','json'=>'application/json',
                  'svg'=>'image/svg+xml','html'=>'text/html','webp'=>'image/webp'];
        if (isset($mimes[$ext])) {
            header('Content-Type: '.$mimes[$ext].'; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            readfile($file);
            exit;
        }
    }
}

// ── App HTML ──────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store');
readfile(__DIR__ . '/index.html');
exit;
