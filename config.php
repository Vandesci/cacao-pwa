<?php
// ============================================================
// CONFIG - Fonctionne sur Railway ET Laragon automatiquement
// ============================================================

// Railway fournit les variables d'environnement automatiquement
// Laragon utilise les valeurs par défaut ci-dessous

define('DB_HOST',    getenv('MYSQLHOST')    ?: getenv('DB_HOST')    ?: '127.0.0.1');
define('DB_PORT',    getenv('MYSQLPORT')    ?: getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('MYSQLDATABASE')?:getenv('DB_NAME')    ?: 'cacao_collector');
define('DB_USER',    getenv('MYSQLUSER')    ?: getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('MYSQLPASSWORD')?:getenv('DB_PASS')    ?: 'bolaty');
define('DB_CHARSET', 'utf8mb4');

// Clé Mistral — Railway: variable d'env, Laragon: valeur directe
define('MISTRAL_API_KEY', getenv('MISTRAL_API_KEY') ?: 'gtgnyayNb7bKXdYSs9HhiOpgmijIG3JD');
define('MISTRAL_MODEL',   'mistral-large-latest'); // Modèle puissant illimité

define('APP_NAME',    'Cacao Data Collector');
define('APP_VERSION', '2.0.0');
define('IS_RAILWAY',  !!getenv('RAILWAY_ENVIRONMENT'));

date_default_timezone_set('Africa/Abidjan');

// ── CONNEXION PDO ──────────────────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=".DB_HOST.";port=".DB_PORT.";charset=".DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `".DB_NAME."`");
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'DB: '.$e->getMessage()]));
        }
    }
    return $pdo;
}

// ── SESSION ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // Configuration session compatible Railway
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', IS_RAILWAY ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_lifetime', '86400');
    ini_set('session.gc_maxlifetime', '86400');
    ini_set('session.cookie_path', '/');
    
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => IS_RAILWAY,
        'httponly' => true,
        'samesite' => IS_RAILWAY ? 'None' : 'Lax',
    ]);
    session_start();
}

function isLoggedIn() {
    // Check session first, then header fallback for Railway
    if (!empty($_SESSION['user_id'])) return true;
    // Header-based auth for stateless Railway deployments
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? '';
    if (!empty($userId) && is_numeric($userId)) {
        // Reload session from DB
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE id=? AND actif=1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if ($user) {
                $_SESSION['user_id']        = $user['id'];
                $_SESSION['nom']            = $user['nom'];
                $_SESSION['prenom']         = $user['prenom'];
                $_SESSION['role']           = $user['role'];
                $_SESSION['cooperative_id'] = $user['cooperative_id'];
                return true;
            }
        } catch(Exception $e) {}
    }
    return false;
}
function requireLogin() {
    if (!isLoggedIn()) jsonError('Non authentifié', 401);
}
function requireAdmin() {
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin','superadmin'])) jsonError('Accès refusé', 403);
}
function currentUser() {
    return [
        'id'             => $_SESSION['user_id']        ?? null,
        'nom'            => $_SESSION['nom']             ?? '',
        'prenom'         => $_SESSION['prenom']          ?? '',
        'role'           => $_SESSION['role']            ?? '',
        'cooperative_id' => $_SESSION['cooperative_id']  ?? null,
    ];
}
function jsonError($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonSuccess($data=[], $msg='OK') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success'=>true,'message'=>$msg], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
