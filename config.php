<?php
// ============================================================
// CONFIG - IndicatorDATA / Cacao Collector
// ============================================================
define('DB_HOST',    getenv('MYSQLHOST')     ?: '127.0.0.1');
define('DB_PORT',    getenv('MYSQLPORT')     ?: '3306');
define('DB_NAME',    getenv('MYSQLDATABASE') ?: 'cacao_collector');
define('DB_USER',    getenv('MYSQLUSER')     ?: 'root');
define('DB_PASS',    getenv('MYSQLPASSWORD') ?: 'bolaty');
define('DB_CHARSET', 'utf8mb4');
define('MISTRAL_API_KEY', getenv('MISTRAL_API_KEY') ?: 'gtgnyayNb7bKXdYSs9HhiOpgmijIG3JD');
define('MISTRAL_MODEL',   'mistral-large-latest');
define('IS_RAILWAY', !!getenv('RAILWAY_ENVIRONMENT'));
date_default_timezone_set('Africa/Abidjan');

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

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>86400,'path'=>'/','secure'=>IS_RAILWAY,'httponly'=>true,'samesite'=>'None']);
    session_start();
}

// Auth functions
function isLoggedIn() {
    if (!empty($_SESSION['user_id'])) return true;
    // Fallback header auth pour Railway (stateless)
    $uid = $_SERVER['HTTP_X_USER_ID'] ?? '';
    if ($uid && is_numeric($uid)) {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE id=? AND actif=1 LIMIT 1");
            $stmt->execute([(int)$uid]);
            $u = $stmt->fetch();
            if ($u) {
                $_SESSION['user_id']        = $u['id'];
                $_SESSION['nom']            = $u['nom'];
                $_SESSION['prenom']         = $u['prenom'];
                $_SESSION['role']           = $u['role'];
                $_SESSION['cooperative_id'] = $u['cooperative_id'];
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

function requireSuperAdmin() {
    requireLogin();
    if ($_SESSION['role'] !== 'superadmin') jsonError('Accès refusé', 403);
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
    echo json_encode(['success'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonSuccess($data=[], $msg='OK') {
    echo json_encode(array_merge(['success'=>true,'message'=>$msg], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
