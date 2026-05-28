<?php
$dbHost = getenv('MYSQLHOST')     ?: '127.0.0.1';
$dbPort = getenv('MYSQLPORT')     ?: '3306';
$dbUser = getenv('MYSQLUSER')     ?: 'root';
$dbPass = getenv('MYSQLPASSWORD') ?: 'bolaty';
$dbName = getenv('MYSQLDATABASE') ?: 'cacao_collector';

try {
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");
} catch(Exception $e) { die("❌ DB: ".$e->getMessage()); }

$ok = []; $warn = [];

$sqls = [
"CREATE TABLE IF NOT EXISTS cooperatives (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(255) NOT NULL, localite VARCHAR(255), code VARCHAR(50) UNIQUE, email VARCHAR(255), telephone VARCHAR(50), statut ENUM('active','inactive') DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(255) NOT NULL, prenom VARCHAR(255), email VARCHAR(255) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, role ENUM('superadmin','admin','inspecteur') DEFAULT 'inspecteur', cooperative_id INT NULL, code_inspecteur VARCHAR(50), is_coop_admin TINYINT(1) DEFAULT 0, actif TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS cooperative_requests (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(255) NOT NULL, email VARCHAR(255) UNIQUE NOT NULL, telephone VARCHAR(50), localite VARCHAR(255), password_hash VARCHAR(255) NOT NULL, statut ENUM('en_attente','valide','rejete') DEFAULT 'en_attente', validated_at TIMESTAMP NULL, validated_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS producteurs (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) UNIQUE, nom VARCHAR(255) NOT NULL, prenom VARCHAR(255), genre ENUM('Homme','Femme') NOT NULL, age INT, cooperative_id INT, localite VARCHAR(255), section VARCHAR(255), nb_plantations INT DEFAULT 0, superficie_certifiee DECIMAL(10,2) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS fiches_profilage (id INT AUTO_INCREMENT PRIMARY KEY, inspecteur_id INT, cooperative_id INT, producteur_id INT, date_profilage DATE, nom_communaute VARCHAR(255), nb_membres_hommes INT DEFAULT 0, nb_membres_femmes INT DEFAULT 0, nb_membres_total INT DEFAULT 0, nb_travailleurs_hommes INT DEFAULT 0, nb_travailleurs_femmes INT DEFAULT 0, nb_travailleurs_total INT DEFAULT 0, statut ENUM('brouillon','soumis','valide','rejete') DEFAULT 'soumis', commentaire_admin TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS enfants_menage (id INT AUTO_INCREMENT PRIMARY KEY, fiche_profilage_id INT, nom_prenom VARCHAR(255), lien_parente VARCHAR(10), genre VARCHAR(20), age INT, extrait_naissance VARCHAR(10), etat_scolarisation VARCHAR(50), travaux_effectues JSON, solution_pour_arreter TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS fiches_arbres (id INT AUTO_INCREMENT PRIMARY KEY, inspecteur_id INT, producteur_id INT, date_collecte DATE, nb_arbres_ombrage INT DEFAULT 0, densite_par_hectare DECIMAL(10,2) DEFAULT 0, nb_arbres_deficitaires DECIMAL(10,2) DEFAULT 0, statut ENUM('brouillon','soumis','valide','rejete') DEFAULT 'soumis', commentaire_admin TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS especes_arbres (id INT AUTO_INCREMENT PRIMARY KEY, fiche_arbre_id INT, nom_local VARCHAR(255), nom_botanique VARCHAR(255), origine VARCHAR(10), non_ombrage TINYINT(1) DEFAULT 0, nombre_total INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS fiches_engrais (id INT AUTO_INCREMENT PRIMARY KEY, inspecteur_id INT, producteur_id INT, date_collecte DATE, statut ENUM('brouillon','soumis','valide','rejete') DEFAULT 'soumis', commentaire_admin TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS engrais_organiques (id INT AUTO_INCREMENT PRIMARY KEY, fiche_engrais_id INT, source VARCHAR(50), periode_application VARCHAR(100), frequence_an INT DEFAULT 0, quantite_par_ha DECIMAL(10,2) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS engrais_inorganiques (id INT AUTO_INCREMENT PRIMARY KEY, fiche_engrais_id INT, nom_commercial VARCHAR(255), formulation_npk VARCHAR(100), superficie_appliquee DECIMAL(10,2) DEFAULT 0, nb_sacs_periode INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS pesticides (id INT AUTO_INCREMENT PRIMARY KEY, fiche_engrais_id INT, type_pesticide VARCHAR(10), nom_commercial VARCHAR(255), ingredients_actifs TEXT, superficie_appliquee DECIMAL(10,2) DEFAULT 0, periode_traitement VARCHAR(100)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, type VARCHAR(50), message TEXT, lu TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($sqls as $sql) {
    try { $pdo->exec($sql); $ok[] = "✅ ".substr($sql,0,60)."..."; }
    catch(Exception $e) { $warn[] = "⚠️ ".$e->getMessage(); }
}

// Superadmin
try {
    $exist = $pdo->query("SELECT id FROM users WHERE role='superadmin'")->fetch();
    if (!$exist) {
        $hash = password_hash('superadmin123', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO users (nom,prenom,email,password,role,actif) VALUES ('Super','Admin','superadmin@cacao.ci',?,'superadmin',1)")->execute([$hash]);
        $ok[] = "✅ Superadmin créé: superadmin@cacao.ci / superadmin123";
    } else {
        // Reset password
        $hash = password_hash('superadmin123', PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password=? WHERE role='superadmin'")->execute([$hash]);
        $ok[] = "✅ Superadmin déjà existant (mot de passe réinitialisé: superadmin123)";
    }
} catch(Exception $e) { $warn[] = "⚠️ Superadmin: ".$e->getMessage(); }

// Coopérative pilote
try {
    $exist = $pdo->query("SELECT id FROM cooperatives LIMIT 1")->fetch();
    if (!$exist) {
        $pdo->exec("INSERT INTO cooperatives (nom,localite,code) VALUES ('Coopérative Pilote Cacao','Abidjan','COOP-PILOT')");
        $coopId = $pdo->lastInsertId();
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO users (nom,prenom,email,password,role,cooperative_id,is_coop_admin,actif) VALUES ('Admin','Demo','admin@cacao.ci',?,'admin',?,1,1)")->execute([$hash,$coopId]);
        $ok[] = "✅ Coopérative pilote créée + admin@cacao.ci / admin123";
    } else {
        // Reset admin password
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password=? WHERE email='admin@cacao.ci'")->execute([$hash]);
        $ok[] = "✅ Coopérative déjà existante (admin@cacao.ci / admin123 réinitialisé)";
    }
} catch(Exception $e) { $warn[] = "⚠️ Coop: ".$e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="google" content="notranslate"><title>Migration IndicatorDATA</title>
<style>body{font-family:Arial,sans-serif;max-width:700px;margin:40px auto;padding:20px;background:#f0f7e6}
h2{color:#2D5016}.ok{background:#e8f5d8;border-left:4px solid #4e8827;padding:10px;margin:4px 0;border-radius:4px;font-size:13px}
.warn{background:#fff8e1;border-left:4px solid #f59e0b;padding:10px;margin:4px 0;border-radius:4px;font-size:13px}
.box{background:#fff;padding:24px;border-radius:12px;margin-top:20px;border:1px solid #d1d5db}
table{width:100%;border-collapse:collapse}td{padding:8px 12px;border-bottom:1px solid #e5e7eb}
td:first-child{font-weight:600;color:#6b7280;font-size:12px;text-transform:uppercase}
a.btn{display:inline-block;background:#2D5016;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:16px}
.warn-note{color:#d97706;font-size:12px;margin-top:16px}</style></head>
<body>
<h2>🌿 Migration — IndicatorDATA</h2>
<?php foreach($ok as $r) echo "<div class='ok'>$r</div>"; ?>
<?php foreach($warn as $w) echo "<div class='warn'>$w</div>"; ?>
<div class="box">
  <strong>🎉 Installation réussie!</strong>
  <table style="margin-top:16px">
    <tr><td>Superadmin</td><td>superadmin@cacao.ci</td><td>superadmin123</td></tr>
    <tr><td>Admin Demo</td><td>admin@cacao.ci</td><td>admin123</td></tr>
  </table>
  <a class="btn" href="/">Ouvrir l'application →</a>
  <div class="warn-note">⚠️ Supprimez migrate.php après utilisation!</div>
</div>
</body></html>
