<?php
// ============================================================
// ROUTER PRINCIPAL - Compatible Railway + Laragon
// ============================================================

$uri      = $_SERVER['REQUEST_URI'] ?? '/';
$urlPath  = trim(parse_url($uri, PHP_URL_PATH), '/');
$segments = explode('/', $urlPath);

// Détecter si on est dans un sous-dossier (laragon) ou à la racine (railway)
$pos = array_search('cacao-pwa', $segments);
if ($pos !== false) {
    $local = implode('/', array_slice($segments, $pos + 1));
} elseif ($pos = array_search('cacao-railway', $segments)) {
    $local = implode('/', array_slice($segments, $pos + 1));
} else {
    $local = $urlPath;
}
$local = trim($local, '/');

// ── API ────────────────────────────────────────────────────
if ($local === 'api' || strpos($local, 'api/') === 0) {
    $apiPath = trim(substr($local, 3), '/');
    $_GET['_path'] = $apiPath;
    include __DIR__ . '/api/index.php';
    exit;
}

// ── Setup ──────────────────────────────────────────────────
if ($local === 'setup.php') {
    include __DIR__ . '/setup.php';
    exit;
}

// ── Ping ───────────────────────────────────────────────────
if ($local === 'ping.php' || $local === 'ping') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'php'    => PHP_VERSION,
        'env'    => getenv('RAILWAY_ENVIRONMENT') ? 'railway' : 'local',
        'local'  => $local,
    ]);
    exit;
}

// ── Fichiers statiques ─────────────────────────────────────
if (!empty($local) && $local !== 'index.php') {
    $file = __DIR__ . '/' . $local;
    if (file_exists($file) && is_file($file)) {
        $ext   = strtolower(pathinfo($local, PATHINFO_EXTENSION));
        $mimes = [
            'js'   => 'application/javascript',
            'css'  => 'text/css',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'ico'  => 'image/x-icon',
            'json' => 'application/json',
            'svg'  => 'image/svg+xml',
            'html' => 'text/html',
        ];
        if (isset($mimes[$ext])) {
            header('Content-Type: ' . $mimes[$ext] . '; charset=utf-8');
            header('Cache-Control: public, max-age=86400');
            readfile($file);
            exit;
        }
    }
}

// ── App HTML ───────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
readfile(__DIR__ . '/index.html');
exit;
