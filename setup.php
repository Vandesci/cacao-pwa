<?php
/**
 * SETUP.PHP — Cacao PWA
 * http://localhost/cacao-pwa/setup.php
 */

// Connexion directe - valeurs configurées pour Laragon
$dbHost = getenv('MYSQLHOST')     ?: '127.0.0.1';
$dbPort = getenv('MYSQLPORT')     ?: '3306';
$dbUser = getenv('MYSQLUSER')     ?: 'root';
$dbPass = getenv('MYSQLPASSWORD') ?: 'bolaty';
$dbName = getenv('MYSQLDATABASE') ?: 'cacao_collector';

$results = [];
$errors  = [];
$pdo     = null;

// ── Connexion SANS base de données d'abord ──
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $results[] = "✅ Connexion MySQL OK — <code>{$dbHost}:{$dbPort}</code> / user: <code>{$dbUser}</code> / pass: <code>" . (empty($dbPass) ? '(vide)' : str_repeat('*', strlen($dbPass))) . "</code>";
} catch (Exception $e) {
    $errors[] = "❌ Connexion échouée: " . $e->getMessage();
    $errors[] = "ℹ️ Valeurs utilisées → host: <code>{$dbHost}</code> / pass: <code>{$dbPass}</code>";
    $errors[] = "ℹ️ Modifiez <code>config.php</code> avec le bon mot de passe MySQL.";
    render($results, $errors); exit;
}

// ── Créer la base ──
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");
    $results[] = "✅ Base de données <code>{$dbName}</code> prête";
} catch (Exception $e) {
    $errors[] = "❌ " . $e->getMessage();
    render($results, $errors); exit;
}

// ── Créer les tables ──
$tables = [
"CREATE TABLE IF NOT EXISTS cooperatives (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(255) NOT NULL, localite VARCHAR(255), code VARCHAR(50) UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(255) NOT NULL, prenom VARCHAR(255) NOT NULL, email VARCHAR(255) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, role ENUM('admin','inspecteur') DEFAULT 'inspecteur', cooperative_id INT NULL, code_inspecteur VARCHAR(50) UNIQUE, actif TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS producteurs (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) UNIQUE NOT NULL, nom VARCHAR(255) NOT NULL, prenom VARCHAR(255), genre ENUM('Homme','Femme') NOT NULL, age INT, cooperative_id INT, localite VARCHAR(255), section VARCHAR(255), nb_plantations INT DEFAULT 0, superficie_certifiee DECIMAL(10,2) DEFAULT 0, annee_creation INT, nb_unites_certifiees INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS fiches_profilage (id INT AUTO_INCREMENT PRIMARY KEY, inspecteur_id INT NOT NULL, cooperative_id INT, producteur_id INT NOT NULL, date_profilage DATE NOT NULL, nom_communaute VARCHAR(255), nb_membres_hommes INT DEFAULT 0, nb_membres_femmes INT DEFAULT 0, nb_membres_total INT DEFAULT 0, nb_travailleurs_hommes INT DEFAULT 0, nb_travailleurs_femmes INT DEFAULT 0, nb_travailleurs_total INT DEFAULT 0, statut ENUM('brouillon','soumis','valide','rejete') DEFAULT 'brouillon', commentaire_admin TEXT, sync_status ENUM('local','synced') DEFAULT 'local', local_id VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS enfants_menage (id INT AUTO_INCREMENT PRIMARY KEY, fiche_profilage_id INT NOT NULL, nom_prenom VARCHAR(255) NOT NULL, lien_parente ENUM('A','B','C','D','E') NOT NULL, lien_parente_autre VARCHAR(255), genre ENUM('Garçon','Fille') NOT NULL, age INT NOT NULL, extrait_naissance ENUM('Oui','Non'), etat_scolarisation ENUM('Scolarisé','Déscolarisé','Jamais scolarisé'), niveau_scolaire ENUM('A','B','C','D','E','F','G'), raisons_non_scolarise JSON, travaux_effectues JSON, solution_pour_arreter TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS fiches_arbres (id INT AUTO_INCREMENT PRIMARY KEY, inspecteur_id INT NOT NULL, producteur_id INT NOT NULL, date_collecte DATE NOT NULL, nb_arbres_ombrage INT DEFAULT 0, densite_par_hectare DECIMAL(10,2) DEFAULT 0, nb_arbres_deficitaires DECIMAL(10,2) DEFAULT 0, statut ENUM('brouillon','soumis','valide','rejete') DEFAULT 'brouillon', commentaire_admin TEXT, sync_status ENUM('local','synced') DEFAULT 'local', local_id VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS especes_arbres (id INT AUTO_INCREMENT PRIMARY KEY, fiche_arbre_id INT NOT NULL, nom_local VARCHAR(255), nom_botanique VARCHAR(255), origine ENUM('1','2'), non_ombrage TINYINT(1) DEFAULT 0, nombre_total INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS fiches_engrais (id INT AUTO_INCREMENT PRIMARY KEY, inspecteur_id INT NOT NULL, producteur_id INT NOT NULL, date_collecte DATE NOT NULL, statut ENUM('brouillon','soumis','valide','rejete') DEFAULT 'brouillon', commentaire_admin TEXT, sync_status ENUM('local','synced') DEFAULT 'local', local_id VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS engrais_organiques (id INT AUTO_INCREMENT PRIMARY KEY, fiche_engrais_id INT NOT NULL, source ENUM('VEGETALE','ANIMALE','COMPOSTE','COMMERCIAL') NOT NULL, periode_application VARCHAR(255), frequence_an INT, quantite_periode DECIMAL(10,2), quantite_par_ha DECIMAL(10,2), quantite_annuelle DECIMAL(10,2), unite VARCHAR(20) DEFAULT 'kg') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS engrais_inorganiques (id INT AUTO_INCREMENT PRIMARY KEY, fiche_engrais_id INT NOT NULL, nom_commercial VARCHAR(255), formulation_npk VARCHAR(100), superficie_appliquee DECIMAL(10,2), periode_application VARCHAR(255), frequence_an INT, nb_sacs_periode INT, volume_n_an DECIMAL(10,2), volume_p_an DECIMAL(10,2), volume_k_an DECIMAL(10,2)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS pesticides (id INT AUTO_INCREMENT PRIMARY KEY, fiche_engrais_id INT NOT NULL, type_pesticide ENUM('1','2','3','4'), nom_commercial VARCHAR(255), ingredients_actifs TEXT, frequence_application INT, quantite_traitee_an DECIMAL(10,2), superficie_appliquee DECIMAL(10,2), periode_traitement VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50), message TEXT, lu TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$ok = 0;
foreach ($tables as $sql) {
    try { $pdo->exec($sql); $ok++; }
    catch (Exception $e) { $errors[] = "⚠️ " . $e->getMessage(); }
}
$results[] = "✅ {$ok} tables créées / vérifiées";

// ── Coopérative par défaut ──
try {
    if ($pdo->query("SELECT COUNT(*) FROM cooperatives")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO cooperatives (nom, localite, code) VALUES ('Coopérative Pilote Cacao','Abidjan','COOP-001')");
        $results[] = "✅ Coopérative pilote créée";
    } else {
        $results[] = "✅ Coopérative déjà existante";
    }
} catch (Exception $e) { $errors[] = "⚠️ " . $e->getMessage(); }

// ── Compte admin ──
try {
    $hash   = password_hash('admin123', PASSWORD_BCRYPT);
    $coopId = $pdo->query("SELECT id FROM cooperatives LIMIT 1")->fetchColumn();
    $exist  = $pdo->query("SELECT id FROM users WHERE email='admin@cacao.ci'")->fetch();
    if ($exist) {
        $pdo->prepare("UPDATE users SET password=?, actif=1, role='admin' WHERE email='admin@cacao.ci'")->execute([$hash]);
        $results[] = "✅ Mot de passe admin réinitialisé";
    } else {
        $pdo->prepare("INSERT INTO users (nom,prenom,email,password,role,cooperative_id) VALUES ('Administrateur','Super','admin@cacao.ci',?,'admin',?)")->execute([$hash, $coopId]);
        $results[] = "✅ Compte admin créé";
    }
    $u  = $pdo->query("SELECT password FROM users WHERE email='admin@cacao.ci'")->fetch();
    $ok = password_verify('admin123', $u['password']);
    $results[] = $ok ? "✅ Vérification login <strong>réussie</strong> — tout est prêt!" : "❌ Erreur hash";
} catch (Exception $e) { $errors[] = "❌ " . $e->getMessage(); }

render($results, $errors);

function render($results, $errors) { ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup — Cacao PWA</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:40px auto;padding:20px;background:#f0f7e6;color:#1a3009}
h2{color:#2D5016;margin-bottom:4px}
.sub{color:#6b7280;font-size:14px;margin-bottom:20px}
.ok{background:#e8f5d8;border-left:4px solid #4e8827;padding:11px 14px;margin:6px 0;border-radius:6px;font-size:14px}
.err{background:#fee2e2;border-left:4px solid #ef4444;padding:11px 14px;margin:6px 0;border-radius:6px;font-size:14px}
.box{background:#fff;border:1px solid #d1d5db;border-radius:12px;padding:24px;margin-top:20px}
code{background:#f3f4f6;padding:2px 7px;border-radius:4px;font-size:13px}
a.btn{display:inline-block;background:#2D5016;color:#fff;padding:13px 28px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:16px;font-size:15px}
table{width:100%;border-collapse:collapse;margin-top:14px;font-size:14px}
td,th{padding:9px 13px;border:1px solid #e5e7eb}
th{background:#f3f4f6;font-weight:600;text-align:left}
.warn{color:#92400e;background:#fef3c7;border:1px solid #fde68a;padding:12px 16px;border-radius:8px;margin-top:14px;font-size:13px}
</style>
</head>
<body>
<h2>🌿 Cacao PWA — Setup</h2>
<div class="sub">Configuration automatique</div>
<?php foreach ($results as $r) echo "<div class='ok'>$r</div>"; ?>
<?php foreach ($errors  as $e) echo "<div class='err'>$e</div>"; ?>
<?php if (empty($errors)): ?>
<div class="box">
  <strong style="font-size:16px">🎉 Installation réussie!</strong>
  <table>
    <tr><th>URL</th><td><a href="/cacao-pwa/index.php">http://localhost/cacao-pwa/index.php</a></td></tr>
    <tr><th>Email</th><td><code>admin@cacao.ci</code></td></tr>
    <tr><th>Mot de passe</th><td><code>admin123</code></td></tr>
  </table>
  <a class="btn" href="/cacao-pwa/index.php">Ouvrir l'application →</a>
</div>
<div class="warn">⚠️ <strong>Supprimez <code>setup.php</code></strong> après connexion!</div>
<?php endif; ?>
</body>
</html>
<?php } ?>
