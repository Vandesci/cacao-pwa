<?php
// Reset mot de passe pour un compte admin de coopérative validé
$dbHost = getenv('MYSQLHOST')     ?: '127.0.0.1';
$dbPort = getenv('MYSQLPORT')     ?: '3306';
$dbUser = getenv('MYSQLUSER')     ?: 'root';
$dbPass = getenv('MYSQLPASSWORD') ?: 'bolaty';
$dbName = getenv('MYSQLDATABASE') ?: 'cacao_collector';

try {
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(Exception $e) { die("DB Error: ".$e->getMessage()); }

$results = [];

// Ajouter colonnes manquantes dans cooperatives
$cols = ['email VARCHAR(255)', 'telephone VARCHAR(50)', 'statut ENUM(\'active\',\'inactive\') DEFAULT \'active\'', 'is_coop_admin TINYINT(1) DEFAULT 0'];
foreach ($cols as $col) {
    $colName = explode(' ', $col)[0];
    try {
        $pdo->exec("ALTER TABLE cooperatives ADD COLUMN $col");
        $results[] = "✅ Colonne cooperatives.$colName ajoutée";
    } catch(Exception $e) {
        $results[] = "⏭️ cooperatives.$colName déjà existante";
    }
}

// Ajouter is_coop_admin dans users
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN is_coop_admin TINYINT(1) DEFAULT 0");
    $results[] = "✅ Colonne users.is_coop_admin ajoutée";
} catch(Exception $e) {
    $results[] = "⏭️ users.is_coop_admin déjà existante";
}

// Lister tous les comptes admin de coopérative
$admins = $pdo->query("SELECT id,nom,email,role,cooperative_id FROM users WHERE role='admin' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$results[] = "📋 Comptes admin trouvés: ".count($admins);

// Réinitialiser le mot de passe de TOUS les admins de coopérative
// avec leur email comme base (ou un mot de passe par défaut)
foreach ($admins as $a) {
    // Nouveau mot de passe = "cacao" + les 4 premiers chars de l'email
    $newPass = 'Cacao2024!';
    $hash = password_hash($newPass, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $a['id']]);
    $results[] = "🔑 Mot de passe réinitialisé pour {$a['email']} → Cacao2024!";
}

// Lister les demandes validées et leurs comptes
$reqs = $pdo->query("SELECT cr.*, u.id as user_id, u.email as user_email FROM cooperative_requests cr LEFT JOIN users u ON u.email=cr.email WHERE cr.statut='valide'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($reqs as $r) {
    if ($r['user_id']) {
        $results[] = "✅ {$r['nom']} → compte créé: {$r['user_email']} / Cacao2024!";
    } else {
        $results[] = "❌ {$r['nom']} → PAS de compte trouvé!";
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="google" content="notranslate">
<title>Reset Passwords</title>
<style>body{font-family:Arial;max-width:700px;margin:40px auto;padding:20px;background:#f0f7e6}
.r{background:#fff;border-left:4px solid #4e8827;padding:10px;margin:4px 0;border-radius:4px;font-size:13px}
.box{background:#fff;padding:20px;border-radius:12px;margin-top:20px}
a.btn{display:inline-block;background:#2D5016;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:16px}
</style></head>
<body>
<h2>🔑 Réinitialisation des mots de passe</h2>
<?php foreach($results as $r) echo "<div class='r'>$r</div>"; ?>
<div class="box">
  <strong>✅ Tous les admins de coopérative ont maintenant le mot de passe:</strong>
  <div style="font-size:24px;font-weight:900;color:#2D5016;margin:12px 0">Cacao2024!</div>
  <a class="btn" href="/">Se connecter →</a>
  <p style="color:red;margin-top:12px;font-size:12px">⚠️ Supprimez reset_pass.php après utilisation!</p>
</div>
</body></html>
