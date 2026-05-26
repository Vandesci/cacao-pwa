<?php
/**
 * MIGRATE.PHP — Mise à jour base de données pour superadmin
 * http://localhost/cacao-pwa/migrate.php ou railway.app/migrate.php
 * SUPPRIMEZ après exécution
 */

$dbHost = getenv('MYSQLHOST')     ?: '127.0.0.1';
$dbPort = getenv('MYSQLPORT')     ?: '3306';
$dbUser = getenv('MYSQLUSER')     ?: 'root';
$dbPass = getenv('MYSQLPASSWORD') ?: 'bolaty';
$dbName = getenv('MYSQLDATABASE') ?: 'cacao_collector';

$results = [];
$errors  = [];

try {
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;charset=utf8mb4", $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("USE `$dbName`");
    $results[] = "✅ Connexion OK";
} catch (Exception $e) {
    die("❌ Connexion échouée: " . $e->getMessage());
}

$migrations = [

// 1. Ajouter rôle superadmin
"ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','inspecteur') DEFAULT 'inspecteur'",

// 2. Table demandes d'inscription coopératives
"CREATE TABLE IF NOT EXISTS cooperative_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    telephone VARCHAR(50),
    localite VARCHAR(255),
    password_hash VARCHAR(255) NOT NULL,
    statut ENUM('en_attente','valide','rejete') DEFAULT 'en_attente',
    message_rejet TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    validated_at TIMESTAMP NULL,
    validated_by INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// 3. Lier cooperative à son admin
"ALTER TABLE cooperatives ADD COLUMN IF NOT EXISTS admin_id INT NULL",
"ALTER TABLE cooperatives ADD COLUMN IF NOT EXISTS statut ENUM('active','inactive') DEFAULT 'active'",
"ALTER TABLE cooperatives ADD COLUMN IF NOT EXISTS email VARCHAR(255)",
"ALTER TABLE cooperatives ADD COLUMN IF NOT EXISTS telephone VARCHAR(50)",

// 4. Ajouter cooperative_id obligatoire pour les admins
"ALTER TABLE users ADD COLUMN IF NOT EXISTS is_coop_admin TINYINT(1) DEFAULT 0",
];

foreach ($migrations as $sql) {
    try {
        $pdo->exec($sql);
        $results[] = "✅ " . substr($sql, 0, 60) . "...";
    } catch (Exception $e) {
        // Ignorer les erreurs "déjà existe"
        if (strpos($e->getMessage(), 'Duplicate') !== false ||
            strpos($e->getMessage(), 'already exists') !== false ||
            strpos($e->getMessage(), 'exist') !== false) {
            $results[] = "⏭️ Déjà fait: " . substr($sql, 0, 50);
        } else {
            $errors[] = "⚠️ " . $e->getMessage();
        }
    }
}

// Créer le superadmin si pas encore fait
try {
    $exist = $pdo->query("SELECT id FROM users WHERE role='superadmin'")->fetch();
    if (!$exist) {
        $hash = password_hash('superadmin123', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO users (nom,prenom,email,password,role,actif) VALUES ('Super','Admin','superadmin@cacao.ci',?,'superadmin',1)")
            ->execute([$hash]);
        $results[] = "✅ Superadmin créé: superadmin@cacao.ci / superadmin123";
    } else {
        $results[] = "✅ Superadmin déjà existant";
    }
} catch (Exception $e) {
    $errors[] = "⚠️ Superadmin: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Migration</title>
<style>body{font-family:Arial;max-width:700px;margin:40px auto;padding:20px;background:#f0f7e6}
.ok{background:#e8f5d8;border-left:4px solid #4e8827;padding:10px;margin:4px 0;border-radius:4px;font-size:13px}
.err{background:#fee2e2;border-left:4px solid #ef4444;padding:10px;margin:4px 0;border-radius:4px;font-size:13px}
.box{background:#fff;padding:20px;border-radius:12px;margin-top:20px;border:1px solid #d1d5db}
a.btn{display:inline-block;background:#2D5016;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:16px}
</style></head>
<body>
<h2>🌿 Migration — IndicatorDATA</h2>
<?php foreach($results as $r) echo "<div class='ok'>$r</div>"; ?>
<?php foreach($errors as $e) echo "<div class='err'>$e</div>"; ?>
<?php if (empty($errors)): ?>
<div class="box">
  <strong>🎉 Migration réussie!</strong><br><br>
  <strong>Superadmin:</strong> superadmin@cacao.ci / superadmin123<br><br>
  <a class="btn" href="/cacao-pwa/index.php">Accéder à l'app →</a>
</div>
<p style="color:red;margin-top:16px">⚠️ Supprimez migrate.php après utilisation!</p>
<?php endif; ?>
</body></html>
