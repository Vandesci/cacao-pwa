<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mistral.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// ── Routing ────────────────────────────────────────────────
// Priorité: GET _path > PATH_INFO > REQUEST_URI
$apiPath = '';
if (!empty($_GET['_path'])) {
    $apiPath = trim(urldecode($_GET['_path']), '/');
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $apiPath = trim(urldecode($_SERVER['PATH_INFO']), '/');
} else {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/api/([^?]*)#', $uri, $m)) {
        $apiPath = trim(urldecode($m[1]), '/');
    }
}

// Fix: utilise REQUEST_URI directement sur Railway
if (empty($apiPath)) {
    $uri   = urldecode($_SERVER['REQUEST_URI'] ?? '');
    $parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
    $apiIdx = array_search('api', $parts);
    if ($apiIdx !== false) {
        $apiPath = implode('/', array_slice($parts, $apiIdx + 1));
    }
}

$parts    = $apiPath ? explode('/', $apiPath) : [];
$resource = $parts[0] ?? '';
$action   = $parts[1] ?? '';
$id       = isset($parts[2]) ? explode('?', $parts[2])[0] : null;
if (!$id && !empty($parts[1]) && is_numeric($parts[1])) {
    $id     = $parts[1];
    $action = '';
}

// Normaliser resource et action (anti-traduction navigateur)
$resourceMap = [
    'auth'             => 'auth',
    'authentification' => 'auth',
    'stats'            => 'stats',
    'statistiques'     => 'stats',
    'notifications'    => 'notifications',
    'users'            => 'users',
    'utilisateurs'     => 'users',
    'cooperatives'     => 'cooperatives',
    'producteurs'      => 'producteurs',
    'fiches-profilage' => 'fiches-profilage',
    'fiches-arbres'    => 'fiches-arbres',
    'fiches-engrais'   => 'fiches-engrais',
    'ai-chat'          => 'ai-chat',
    'ai-analyze'       => 'ai-analyze',
    'ai-report'        => 'ai-report',
    'ai-anomalies'     => 'ai-anomalies',
];
$resource = $resourceMap[strtolower($resource)] ?? $resource;

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── AUTH ───────────────────────────────────────────────────
if ($resource === 'auth') {
    // Normaliser l'action pour éviter les problèmes de traduction
    $action = strtolower($action);
    $actionMap = [
        'login' => 'login', 'connexion' => 'login', 'signin' => 'login',
        'logout' => 'logout', 'deconnexion' => 'logout',
        'me' => 'me', 'moi' => 'me',
    ];
    $action = $actionMap[$action] ?? $action;
    
    if (($action === 'login' || $action === 'me' || $action === 'logout')) {
        if ($action === 'login' && $method === 'POST') {
            $email    = trim($body['email'] ?? '');
            $password = $body['password'] ?? '';
            if (!$email || !$password) jsonError('Email et mot de passe requis');
            $db   = getDB();
            $stmt = $db->prepare("SELECT u.*, c.nom as coop_nom FROM users u LEFT JOIN cooperatives c ON u.cooperative_id=c.id WHERE u.email=? AND u.actif=1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password'])) jsonError('Identifiants incorrects', 401);
            $_SESSION['user_id']        = $user['id'];
            $_SESSION['nom']            = $user['nom'];
            $_SESSION['prenom']         = $user['prenom'];
            $_SESSION['role']           = $user['role'];
            $_SESSION['cooperative_id'] = $user['cooperative_id'];
            jsonSuccess(['user' => [
                'id'             => $user['id'],
                'nom'            => $user['nom'],
                'prenom'         => $user['prenom'],
                'email'          => $user['email'],
                'role'           => $user['role'],
                'cooperative_id' => $user['cooperative_id'],
                'coop_nom'       => $user['coop_nom'],
                'code_inspecteur'=> $user['code_inspecteur'],
            ]], 'Connexion réussie');
        }
        if ($action === 'logout') { session_destroy(); jsonSuccess([], 'OK'); }
        if ($action === 'me') {
            requireLogin();
            jsonSuccess(['user' => currentUser()]);
        }
    }
    jsonError('Auth endpoint inconnu: ' . $action);
}

// ── DEMANDE INSCRIPTION (public - pas besoin d'auth) ──────
if ($resource === 'cooperative-requests' && $method === 'POST' && empty($action)) {
    $db       = getDB();
    $nom      = trim($body['nom'] ?? '');
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $tel      = $body['telephone'] ?? '';
    $loc      = $body['localite'] ?? '';

    if (!$nom || !$email || !$password) jsonError('Nom, email et mot de passe requis');
    if (strlen($password) < 6) jsonError('Mot de passe: minimum 6 caractères');

    $exist = $db->prepare("SELECT id FROM cooperative_requests WHERE email=?");
    $exist->execute([$email]);
    if ($exist->fetch()) jsonError('Cet email est déjà utilisé pour une demande');

    $exist2 = $db->prepare("SELECT id FROM users WHERE email=?");
    $exist2->execute([$email]);
    if ($exist2->fetch()) jsonError('Cet email est déjà associé à un compte');

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO cooperative_requests (nom,email,telephone,localite,password_hash) VALUES (?,?,?,?,?)")
       ->execute([$nom, $email, $tel, $loc, $hash]);

    // Notifier les superadmins
    try {
        $admins = $db->query("SELECT id FROM users WHERE role='superadmin'")->fetchAll();
        foreach ($admins as $a) {
            $db->prepare("INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)")
               ->execute([$a['id'], 'new_request', "Nouvelle demande d'inscription: $nom ($email)"]);
        }
    } catch(Exception $e) {}

    jsonSuccess([], 'Demande envoyée avec succès');
}

requireLogin();
$user = currentUser();

// ── COOPERATIVES ───────────────────────────────────────────
if ($resource === 'cooperatives') {
    $db = getDB();
    if ($method === 'GET') {
        $rows = $db->query("SELECT c.*, (SELECT COUNT(*) FROM users WHERE cooperative_id=c.id AND role='inspecteur') as nb_inspecteurs, (SELECT COUNT(*) FROM producteurs WHERE cooperative_id=c.id) as nb_producteurs FROM cooperatives c ORDER BY c.nom")->fetchAll();
        jsonSuccess(['data' => $rows]);
    }
    if ($method === 'POST') {
        $nom = trim($body['nom'] ?? '');
        if (!$nom) jsonError('Nom requis');
        $code = 'COOP-' . strtoupper(substr(md5(time()), 0, 6));
        $db->prepare("INSERT INTO cooperatives (nom,localite,code) VALUES (?,?,?)")->execute([$nom,$body['localite']??'',$code]);
        jsonSuccess(['id' => $db->lastInsertId()], 'Coopérative créée');
    }
    if ($method === 'PUT' && $id) {
        $db->prepare("UPDATE cooperatives SET nom=?,localite=? WHERE id=?")->execute([$body['nom'],$body['localite']??'',$id]);
        jsonSuccess([], 'OK');
    }
    if ($method === 'DELETE' && $id) {
        $db->prepare("DELETE FROM cooperatives WHERE id=?")->execute([$id]);
        jsonSuccess([], 'Supprimée');
    }
}

// ── USERS ──────────────────────────────────────────────────
if ($resource === 'users') {
    requireAdmin();
    $db = getDB();
    if ($method === 'GET') {
        $sql = "SELECT u.id,u.nom,u.prenom,u.email,u.role,u.actif,u.code_inspecteur,u.cooperative_id,c.nom as coop_nom FROM users u LEFT JOIN cooperatives c ON u.cooperative_id=c.id ORDER BY u.nom";
        jsonSuccess(['data' => $db->query($sql)->fetchAll()]);
    }
    if ($method === 'POST') {
        foreach (['nom','prenom','email','password','cooperative_id'] as $f)
            if (empty($body[$f])) jsonError("Champ requis: $f");
        $code = 'INS-' . strtoupper(substr(md5($body['email'].time()), 0, 8));
        $hash = password_hash($body['password'], PASSWORD_BCRYPT);
        try {
            $db->prepare("INSERT INTO users (nom,prenom,email,password,role,cooperative_id,code_inspecteur) VALUES (?,?,?,?,'inspecteur',?,?)")
               ->execute([$body['nom'],$body['prenom'],$body['email'],$hash,$body['cooperative_id'],$code]);
            $newId = $db->lastInsertId();
            $db->prepare("INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)")->execute([$newId,'bienvenue','Bienvenue! Votre compte a été créé.']);
            jsonSuccess(['id'=>$newId,'code'=>$code], 'Inspecteur créé');
        } catch (PDOException $e) {
            if ($e->getCode()==23000) jsonError('Email déjà utilisé');
            throw $e;
        }
    }
    if ($method === 'PUT' && $id) {
        $sets = ['actif=?']; $vals = [$body['actif']??1];
        if (!empty($body['password'])) { $sets[]='password=?'; $vals[]=password_hash($body['password'],PASSWORD_BCRYPT); }
        $vals[] = $id;
        $db->prepare("UPDATE users SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
        jsonSuccess([], 'OK');
    }
}

// ── PRODUCTEURS ────────────────────────────────────────────
if ($resource === 'producteurs') {
    $db = getDB();
    if ($method === 'GET') {
        $coopId = ($user['role']==='admin') ? ($_GET['cooperative_id']??null) : $user['cooperative_id'];
        $sql = "SELECT p.*,c.nom as coop_nom FROM producteurs p LEFT JOIN cooperatives c ON p.cooperative_id=c.id WHERE 1=1";
        $params = [];
        if ($coopId) { $sql.=" AND p.cooperative_id=?"; $params[]=$coopId; }
        $sql .= " ORDER BY p.nom";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        jsonSuccess(['data'=>$stmt->fetchAll()]);
    }
    if ($method === 'POST') {
        foreach (['nom','genre','cooperative_id'] as $f) if (empty($body[$f])) jsonError("Requis: $f");
        $code = 'PROD-'.strtoupper(substr(md5($body['nom'].time()),0,8));
        $db->prepare("INSERT INTO producteurs (code,nom,prenom,genre,age,cooperative_id,localite,section,nb_plantations,superficie_certifiee) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$code,$body['nom'],$body['prenom']??'',$body['genre'],$body['age']??null,$body['cooperative_id'],$body['localite']??'',$body['section']??'',$body['nb_plantations']??0,$body['superficie_certifiee']??0]);
        jsonSuccess(['id'=>$db->lastInsertId(),'code'=>$code],'Producteur créé');
    }
}

// ── FICHES PROFILAGE ───────────────────────────────────────
if ($resource === 'fiches-profilage') {
    $db = getDB();
    if ($method === 'GET') {
        $sql = "SELECT fp.*,p.nom as prod_nom,p.code as prod_code,u.nom as insp_nom,u.prenom as insp_prenom FROM fiches_profilage fp LEFT JOIN producteurs p ON fp.producteur_id=p.id LEFT JOIN users u ON fp.inspecteur_id=u.id WHERE 1=1";
        $params=[];
        if ($user['role']==='inspecteur'){$sql.=" AND fp.inspecteur_id=?";$params[]=$user['id'];}
        if (!empty($_GET['statut'])){$sql.=" AND fp.statut=?";$params[]=$_GET['statut'];}
        $sql.=" ORDER BY fp.created_at DESC";
        $stmt=$db->prepare($sql);$stmt->execute($params);
        $fiches=$stmt->fetchAll();
        foreach($fiches as &$f){$s=$db->prepare("SELECT * FROM enfants_menage WHERE fiche_profilage_id=?");$s->execute([$f['id']]);$f['enfants']=$s->fetchAll();}
        jsonSuccess(['data'=>$fiches]);
    }
    if ($method === 'POST') {
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO fiches_profilage (inspecteur_id,cooperative_id,producteur_id,date_profilage,nom_communaute,nb_membres_hommes,nb_membres_femmes,nb_membres_total,nb_travailleurs_hommes,nb_travailleurs_femmes,nb_travailleurs_total,statut,sync_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'soumis','synced')")
               ->execute([$user['id'],$body['cooperative_id']??null,$body['producteur_id'],$body['date_profilage'],$body['nom_communaute']??'',$body['nb_membres_hommes']??0,$body['nb_membres_femmes']??0,$body['nb_membres_total']??0,$body['nb_travailleurs_hommes']??0,$body['nb_travailleurs_femmes']??0,$body['nb_travailleurs_total']??0]);
            $fid=$db->lastInsertId();
            if(!empty($body['enfants'])){
                $s=$db->prepare("INSERT INTO enfants_menage (fiche_profilage_id,nom_prenom,lien_parente,genre,age,extrait_naissance,etat_scolarisation,travaux_effectues,solution_pour_arreter) VALUES (?,?,?,?,?,?,?,?,?)");
                foreach($body['enfants'] as $e) $s->execute([$fid,$e['nom_prenom'],$e['lien_parente']??'A',$e['genre']??'Garçon',$e['age']??0,$e['extrait_naissance']??null,$e['etat_scolarisation']??null,json_encode($e['travaux_effectues']??[]),$e['solution_pour_arreter']??null]);
            }
            foreach($db->query("SELECT id FROM users WHERE role='admin'")->fetchAll() as $a)
                $db->prepare("INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)")->execute([$a['id'],'nouvelle_fiche','Nouvelle fiche profilage soumise.']);
            $db->commit();
            jsonSuccess(['id'=>$fid],'Fiche soumise');
        } catch(Exception $e){$db->rollBack();jsonError($e->getMessage());}
    }
    if ($method === 'PUT' && $id) {
        $fiche_q = $db->prepare("SELECT * FROM fiches_profilage WHERE id=?");
        $fiche_q->execute([$id]); $fi = $fiche_q->fetch();
        if (!$fi) jsonError('Fiche non trouvée', 404);

        if (!empty($body['statut']) && in_array($body['statut'], ['valide','rejete'])) {
            requireAdmin();
            $db->prepare("UPDATE fiches_profilage SET statut=?,commentaire_admin=? WHERE id=?")
               ->execute([$body['statut'],$body['commentaire_admin']??'',$id]);
            $msg = ($body['statut']==='valide') ? "Fiche profilage #$id validée ✓" : "Fiche profilage #$id rejetée.";
            $db->prepare("INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)")
               ->execute([$fi['inspecteur_id'],'fiche_'.$body['statut'],$msg]);
            jsonSuccess([],'OK');
        } else {
            $canEdit = ($user['role']==='admin') || ($user['role']==='superadmin') ||
                       ($fi['inspecteur_id']==$user['id'] && in_array($fi['statut'],['brouillon','rejete']));
            if (!$canEdit) jsonError('Non autorisé', 403);
            $db->prepare("UPDATE fiches_profilage SET date_profilage=?,nom_communaute=?,nb_membres_hommes=?,nb_membres_femmes=?,nb_membres_total=?,nb_travailleurs_hommes=?,nb_travailleurs_femmes=?,nb_travailleurs_total=?,statut='soumis' WHERE id=?")
               ->execute([$body['date_profilage']??$fi['date_profilage'],$body['nom_communaute']??$fi['nom_communaute'],$body['nb_membres_hommes']??0,$body['nb_membres_femmes']??0,$body['nb_membres_total']??0,$body['nb_travailleurs_hommes']??0,$body['nb_travailleurs_femmes']??0,$body['nb_travailleurs_total']??0,$id]);
            jsonSuccess([],'Modifiée');
        }
    }
    if ($method === 'DELETE' && $id) {
        $fiche_q = $db->prepare("SELECT * FROM fiches_profilage WHERE id=?");
        $fiche_q->execute([$id]); $fi = $fiche_q->fetch();
        if (!$fi) jsonError('Non trouvée', 404);
        $canDel = ($user['role']==='admin') || ($user['role']==='superadmin') ||
                  ($fi['inspecteur_id']==$user['id'] && in_array($fi['statut'],['brouillon','rejete']));
        if (!$canDel) jsonError('Non autorisé', 403);
        $db->prepare("DELETE FROM enfants_menage WHERE fiche_profilage_id=?")->execute([$id]);
        $db->prepare("DELETE FROM fiches_profilage WHERE id=?")->execute([$id]);
        jsonSuccess([],'Supprimée');
    }
    jsonError('Méthode non supportée', 405);
}

// ── FICHES ARBRES ──────────────────────────────────────────
if ($resource === 'fiches-arbres') {
    $db = getDB();
    if ($method === 'GET') {
        $sql="SELECT fa.*,p.nom as prod_nom,u.nom as insp_nom,u.prenom as insp_prenom FROM fiches_arbres fa LEFT JOIN producteurs p ON fa.producteur_id=p.id LEFT JOIN users u ON fa.inspecteur_id=u.id WHERE 1=1";
        $params=[];
        if($user['role']==='inspecteur'){$sql.=" AND fa.inspecteur_id=?";$params[]=$user['id'];}
        $sql.=" ORDER BY fa.created_at DESC";
        $stmt=$db->prepare($sql);$stmt->execute($params);
        $fiches=$stmt->fetchAll();
        foreach($fiches as &$f){$s=$db->prepare("SELECT * FROM especes_arbres WHERE fiche_arbre_id=?");$s->execute([$f['id']]);$f['especes']=$s->fetchAll();}
        jsonSuccess(['data'=>$fiches]);
    }
    if ($method === 'POST') {
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO fiches_arbres (inspecteur_id,producteur_id,date_collecte,nb_arbres_ombrage,densite_par_hectare,nb_arbres_deficitaires,statut,sync_status) VALUES (?,?,?,?,?,?,'soumis','synced')")
               ->execute([$user['id'],$body['producteur_id'],$body['date_collecte'],$body['nb_arbres_ombrage']??0,$body['densite_par_hectare']??0,$body['nb_arbres_deficitaires']??0]);
            $fid=$db->lastInsertId();
            if(!empty($body['especes'])){$s=$db->prepare("INSERT INTO especes_arbres (fiche_arbre_id,nom_local,nom_botanique,origine,non_ombrage,nombre_total) VALUES (?,?,?,?,?,?)");foreach($body['especes'] as $e)$s->execute([$fid,$e['nom_local']??'',$e['nom_botanique']??'',$e['origine']??'1',$e['non_ombrage']??0,$e['nombre_total']??0]);}
            foreach($db->query("SELECT id FROM users WHERE role='admin'")->fetchAll() as $a)$db->prepare("INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)")->execute([$a['id'],'nouvelle_fiche','Nouvelle fiche arbres soumise.']);
            $db->commit();jsonSuccess(['id'=>$fid],'OK');
        }catch(Exception $e){$db->rollBack();jsonError($e->getMessage());}
    }
    if ($method==='PUT' && $id) {
        $fq=$db->prepare("SELECT * FROM fiches_arbres WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        if(!empty($body['statut'])&&in_array($body['statut'],['valide','rejete'])){
            requireAdmin();
            $db->prepare("UPDATE fiches_arbres SET statut=?,commentaire_admin=? WHERE id=?")->execute([$body['statut'],$body['commentaire_admin']??'',$id]);
            jsonSuccess([],'OK');
        } else {
            $canEdit=($user['role']==='admin')||($user['role']==='superadmin')||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
            if(!$canEdit) jsonError('Non autorisé',403);
            $db->prepare("UPDATE fiches_arbres SET date_collecte=?,nb_arbres_ombrage=?,densite_par_hectare=?,nb_arbres_deficitaires=?,statut='soumis' WHERE id=?")
               ->execute([$body['date_collecte']??$fi['date_collecte'],$body['nb_arbres_ombrage']??0,$body['densite_par_hectare']??0,$body['nb_arbres_deficitaires']??0,$id]);
            jsonSuccess([],'Modifiée');
        }
    }
    if ($method==='DELETE' && $id) {
        $fq=$db->prepare("SELECT * FROM fiches_arbres WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        $canDel=($user['role']==='admin')||($user['role']==='superadmin')||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
        if(!$canDel) jsonError('Non autorisé',403);
        $db->prepare("DELETE FROM especes_arbres WHERE fiche_arbre_id=?")->execute([$id]);
        $db->prepare("DELETE FROM fiches_arbres WHERE id=?")->execute([$id]);
        jsonSuccess([],'Supprimée');
    }
    jsonError('Méthode non supportée',405);
}

// ── FICHES ENGRAIS ─────────────────────────────────────────
if ($resource === 'fiches-engrais') {
    $db = getDB();
    if ($method === 'GET') {
        $sql="SELECT fe.*,p.nom as prod_nom,u.nom as insp_nom,u.prenom as insp_prenom FROM fiches_engrais fe LEFT JOIN producteurs p ON fe.producteur_id=p.id LEFT JOIN users u ON fe.inspecteur_id=u.id WHERE 1=1";
        $params=[];
        if($user['role']==='inspecteur'){$sql.=" AND fe.inspecteur_id=?";$params[]=$user['id'];}
        $sql.=" ORDER BY fe.created_at DESC";
        $stmt=$db->prepare($sql);$stmt->execute($params);
        jsonSuccess(['data'=>$stmt->fetchAll()]);
    }
    if ($method==='POST'){
        $db->beginTransaction();
        try{
            $db->prepare("INSERT INTO fiches_engrais (inspecteur_id,producteur_id,date_collecte,statut,sync_status) VALUES (?,?,?,'soumis','synced')")->execute([$user['id'],$body['producteur_id'],$body['date_collecte']]);
            $fid=$db->lastInsertId();
            if(!empty($body['organiques'])){$s=$db->prepare("INSERT INTO engrais_organiques (fiche_engrais_id,source,periode_application,frequence_an,quantite_par_ha) VALUES (?,?,?,?,?)");foreach($body['organiques'] as $o)$s->execute([$fid,$o['source']??'VEGETALE',$o['periode_application']??'',$o['frequence_an']??0,$o['quantite_par_ha']??0]);}
            if(!empty($body['inorganiques'])){$s=$db->prepare("INSERT INTO engrais_inorganiques (fiche_engrais_id,nom_commercial,formulation_npk,superficie_appliquee,nb_sacs_periode) VALUES (?,?,?,?,?)");foreach($body['inorganiques'] as $i)$s->execute([$fid,$i['nom_commercial']??'',$i['formulation_npk']??'',$i['superficie_appliquee']??0,$i['nb_sacs_periode']??0]);}
            if(!empty($body['pesticides'])){$s=$db->prepare("INSERT INTO pesticides (fiche_engrais_id,type_pesticide,nom_commercial,ingredients_actifs,superficie_appliquee,periode_traitement) VALUES (?,?,?,?,?,?)");foreach($body['pesticides'] as $p)$s->execute([$fid,$p['type_pesticide']??'4',$p['nom_commercial']??'',$p['ingredients_actifs']??'',$p['superficie_appliquee']??0,$p['periode_traitement']??'']);}
            $db->commit();jsonSuccess(['id'=>$fid],'OK');
        }catch(Exception $e){$db->rollBack();jsonError($e->getMessage());}
    }
    if ($method==='PUT' && $id) {
        $fq=$db->prepare("SELECT * FROM fiches_engrais WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        if(!empty($body['statut'])&&in_array($body['statut'],['valide','rejete'])){
            requireAdmin();
            $db->prepare("UPDATE fiches_engrais SET statut=?,commentaire_admin=? WHERE id=?")->execute([$body['statut'],$body['commentaire_admin']??'',$id]);
            jsonSuccess([],'OK');
        } else {
            $canEdit=($user['role']==='admin')||($user['role']==='superadmin')||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
            if(!$canEdit) jsonError('Non autorisé',403);
            $db->prepare("UPDATE fiches_engrais SET date_collecte=?,statut='soumis' WHERE id=?")->execute([$body['date_collecte']??$fi['date_collecte'],$id]);
            jsonSuccess([],'Modifiée');
        }
    }
    if ($method==='DELETE' && $id) {
        $fq=$db->prepare("SELECT * FROM fiches_engrais WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        $canDel=($user['role']==='admin')||($user['role']==='superadmin')||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
        if(!$canDel) jsonError('Non autorisé',403);
        $db->prepare("DELETE FROM engrais_organiques WHERE fiche_engrais_id=?")->execute([$id]);
        $db->prepare("DELETE FROM engrais_inorganiques WHERE fiche_engrais_id=?")->execute([$id]);
        $db->prepare("DELETE FROM pesticides WHERE fiche_engrais_id=?")->execute([$id]);
        $db->prepare("DELETE FROM fiches_engrais WHERE id=?")->execute([$id]);
        jsonSuccess([],'Supprimée');
    }
    jsonError('Méthode non supportée',405);
}

// ── STATS ──────────────────────────────────────────────────
if ($resource === 'stats') {
    requireAdmin();
    $db = getDB();
    jsonSuccess(['stats' => [
        'cooperatives'     => (int)$db->query("SELECT COUNT(*) FROM cooperatives")->fetchColumn(),
        'inspecteurs'      => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='inspecteur' AND actif=1")->fetchColumn(),
        'producteurs'      => (int)$db->query("SELECT COUNT(*) FROM producteurs")->fetchColumn(),
        'fiches_profilage' => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_profilage")->fetchColumn(),'soumis'=>(int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE statut='soumis'")->fetchColumn(),'valide'=>(int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE statut='valide'")->fetchColumn()],
        'fiches_arbres'    => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_arbres")->fetchColumn(),'soumis'=>(int)$db->query("SELECT COUNT(*) FROM fiches_arbres WHERE statut='soumis'")->fetchColumn(),'valide'=>(int)$db->query("SELECT COUNT(*) FROM fiches_arbres WHERE statut='valide'")->fetchColumn()],
        'fiches_engrais'   => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_engrais")->fetchColumn(),'soumis'=>(int)$db->query("SELECT COUNT(*) FROM fiches_engrais WHERE statut='soumis'")->fetchColumn(),'valide'=>(int)$db->query("SELECT COUNT(*) FROM fiches_engrais WHERE statut='valide'")->fetchColumn()],
        'enfants_risque'   => (int)$db->query("SELECT COUNT(*) FROM enfants_menage WHERE JSON_LENGTH(travaux_effectues)>0")->fetchColumn(),
    ]]);
}

// ── NOTIFICATIONS ──────────────────────────────────────────
if ($resource === 'notifications') {
    $db = getDB();
    if ($method==='GET'){
        $stmt=$db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");$stmt->execute([$user['id']]);
        $unread=$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND lu=0");$unread->execute([$user['id']]);
        jsonSuccess(['data'=>$stmt->fetchAll(),'unread'=>(int)$unread->fetchColumn()]);
    }
    if($method==='PUT'){$db->prepare("UPDATE notifications SET lu=1 WHERE user_id=?")->execute([$user['id']]);jsonSuccess([],'OK');}
}

// ── AI CHAT ────────────────────────────────────────────────
if ($resource === 'ai-chat') {
    if ($method === 'POST') {
        $msg      = $body['message'] ?? '';
        $history  = $body['history'] ?? [];
        $context  = $body['context'] ?? [];
        if (!$msg) jsonError('Message requis');
        try {
            // Construire l'historique de conversation
            $messages = [['role'=>'system','content'=>getSystemPrompt($context)]];
            foreach ($history as $h) {
                if (!empty($h['role']) && !empty($h['content'])) {
                    $messages[] = ['role'=>$h['role'],'content'=>$h['content']];
                }
            }
            $messages[] = ['role'=>'user','content'=>$msg];
            $reply = callMistral($messages, 1000, 0.7);
            jsonSuccess(['reply'=>$reply,'model'=>MISTRAL_MODEL]);
        } catch(Exception $e) {
            jsonError('IA indisponible: '.$e->getMessage());
        }
    }
}

// ── AI ANALYSE FICHE ───────────────────────────────────────
if ($resource === 'ai-analyze') {
    requireLogin();
    if ($method === 'POST') {
        $type = $body['type'] ?? '';
        $data = $body['data'] ?? [];
        if (!$type || !$data) jsonError('Type et données requis');
        try {
            $analysis = analyzeFiche($type, $data);
            jsonSuccess(['analysis'=>$analysis,'type'=>$type]);
        } catch(Exception $e) {
            jsonError('Analyse impossible: '.$e->getMessage());
        }
    }
}

// ── AI RAPPORT ─────────────────────────────────────────────
if ($resource === 'ai-report') {
    requireAdmin();
    if ($method === 'POST') {
        $db      = getDB();
        $stats   = [
            'cooperatives'     => (int)$db->query("SELECT COUNT(*) FROM cooperatives")->fetchColumn(),
            'inspecteurs'      => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='inspecteur' AND actif=1")->fetchColumn(),
            'producteurs'      => (int)$db->query("SELECT COUNT(*) FROM producteurs")->fetchColumn(),
            'fiches_profilage' => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_profilage")->fetchColumn(),'soumis'=>(int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE statut='soumis'")->fetchColumn(),'valide'=>(int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE statut='valide'")->fetchColumn()],
            'fiches_arbres'    => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_arbres")->fetchColumn(),'soumis'=>(int)$db->query("SELECT COUNT(*) FROM fiches_arbres WHERE statut='soumis'")->fetchColumn()],
            'fiches_engrais'   => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_engrais")->fetchColumn(),'soumis'=>(int)$db->query("SELECT COUNT(*) FROM fiches_engrais WHERE statut='soumis'")->fetchColumn()],
            'enfants_risque'   => (int)$db->query("SELECT COUNT(*) FROM enfants_menage WHERE JSON_LENGTH(travaux_effectues)>0")->fetchColumn(),
        ];
        try {
            $report = generateReport($stats, $body['period']??'mensuel');
            jsonSuccess(['report'=>$report,'stats'=>$stats]);
        } catch(Exception $e) {
            jsonError('Rapport impossible: '.$e->getMessage());
        }
    }
}

// ── AI ANOMALIES ───────────────────────────────────────────
if ($resource === 'ai-anomalies') {
    requireAdmin();
    $db        = getDB();
    $anomalies = detectAnomalies($db);
    if (!empty($anomalies)) {
        try {
            $msg     = "Voici les anomalies détectées dans les données: " . implode(', ', $anomalies) . ". Explique brièvement chaque risque et donne les actions prioritaires.";
            $conseil = callMistral([
                ['role'=>'system','content'=>getSystemPrompt()],
                ['role'=>'user','content'=>$msg],
            ], 600, 0.3);
            jsonSuccess(['anomalies'=>$anomalies,'conseil'=>$conseil]);
        } catch(Exception $e) {
            jsonSuccess(['anomalies'=>$anomalies,'conseil'=>'']);
        }
    } else {
        jsonSuccess(['anomalies'=>[],'conseil'=>'Aucune anomalie détectée. Toutes les données semblent conformes.']);
    }
}

jsonError('Ressource non trouvée: ' . $resource, 404);

// ── DEMANDES INSCRIPTION COOPERATIVE ──────────────────────
if ($resource === 'cooperative-requests') {
    $db = getDB();

    // Liste (superadmin) ou soumettre (public via POST sans auth)
    if ($method === 'GET') {
        requireLogin();
        if ($_SESSION['role'] !== 'superadmin') jsonError('Accès refusé', 403);
        $statut = $_GET['statut'] ?? '';
        $sql = "SELECT * FROM cooperative_requests WHERE 1=1";
        $params = [];
        if ($statut) { $sql .= " AND statut=?"; $params[] = $statut; }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        jsonSuccess(['data' => $stmt->fetchAll()]);
    }

    // Soumettre une demande (public - pas besoin d'être connecté)
    if ($method === 'POST' && $action === '') {
        $nom      = trim($body['nom'] ?? '');
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $tel      = $body['telephone'] ?? '';
        $loc      = $body['localite'] ?? '';

        if (!$nom || !$email || !$password) jsonError('Nom, email et mot de passe requis');
        if (strlen($password) < 6) jsonError('Mot de passe: minimum 6 caractères');

        // Vérifier email unique
        $exist = $db->prepare("SELECT id FROM cooperative_requests WHERE email=?");
        $exist->execute([$email]);
        if ($exist->fetch()) jsonError('Cet email est déjà utilisé pour une demande');

        $exist2 = $db->prepare("SELECT id FROM users WHERE email=?");
        $exist2->execute([$email]);
        if ($exist2->fetch()) jsonError('Cet email est déjà associé à un compte');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare("INSERT INTO cooperative_requests (nom,email,telephone,localite,password_hash) VALUES (?,?,?,?,?)")
           ->execute([$nom, $email, $tel, $loc, $hash]);

        // Notifier les superadmins
        $admins = $db->query("SELECT id FROM users WHERE role='superadmin'")->fetchAll();
        foreach ($admins as $a) {
            $db->prepare("INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)")
               ->execute([$a['id'], 'new_request', "Nouvelle demande d'inscription: $nom ($email)"]);
        }

        jsonSuccess([], 'Demande envoyée avec succès');
    }

    // Valider ou rejeter (superadmin)
    if ($method === 'PUT' && $id) {
        requireLogin();
        if ($_SESSION['role'] !== 'superadmin') jsonError('Accès refusé', 403);

        $statut = $body['statut'] ?? '';
        $db->prepare("UPDATE cooperative_requests SET statut=?, validated_at=NOW(), validated_by=? WHERE id=?")
           ->execute([$statut, $_SESSION['user_id'], $id]);

        if ($statut === 'valide') {
            // Créer la coopérative
            $req = $db->prepare("SELECT * FROM cooperative_requests WHERE id=?");
            $req->execute([$id]);
            $r = $req->fetch();

            $code = 'COOP-' . strtoupper(substr(md5($r['email'].time()), 0, 6));
            $db->prepare("INSERT INTO cooperatives (nom,email,telephone,localite,code,statut) VALUES (?,?,?,?,?,'active')")
               ->execute([$r['nom'], $r['email'], $r['telephone'], $r['localite'], $code]);
            $coopId = $db->lastInsertId();

            // Créer le compte admin de la coopérative
            $db->prepare("INSERT INTO users (nom,prenom,email,password,role,cooperative_id,is_coop_admin,actif) VALUES (?,?,?,?,'admin',?,1,1)")
               ->execute([$r['nom'], 'Admin', $r['email'], $r['password_hash'], $coopId]);

            jsonSuccess(['coop_id' => $coopId], 'Coopérative validée et compte créé');
        } else {
            jsonSuccess([], 'Demande rejetée');
        }
    }
}

// ── SUPERADMIN STATS ───────────────────────────────────────
if ($resource === 'sa-stats') {
    requireLogin();
    if ($_SESSION['role'] !== 'superadmin') jsonError('Accès refusé', 403);
    $db = getDB();
    jsonSuccess(['stats' => [
        'pending_requests' => (int)$db->query("SELECT COUNT(*) FROM cooperative_requests WHERE statut='en_attente'")->fetchColumn(),
        'total_coops'      => (int)$db->query("SELECT COUNT(*) FROM cooperatives")->fetchColumn(),
        'total_users'      => (int)$db->query("SELECT COUNT(*) FROM users WHERE actif=1")->fetchColumn(),
        'total_fiches'     => (int)$db->query("SELECT (SELECT COUNT(*) FROM fiches_profilage) + (SELECT COUNT(*) FROM fiches_arbres) + (SELECT COUNT(*) FROM fiches_engrais) as total")->fetchColumn(),
    ]]);
}

// ── MODIFIER FICHE ─────────────────────────────────────────
if ($resource === 'fiche-delete') {
    requireLogin();
    $db   = getDB();
    $type = $parts[$apiIdx + 2] ?? '';
    $fid  = $parts[$apiIdx + 3] ?? null;
    if (!$fid) jsonError('ID requis');

    if ($method === 'DELETE') {
        $tables = ['profilage'=>'fiches_profilage','arbres'=>'fiches_arbres','engrais'=>'fiches_engrais'];
        $table  = $tables[$type] ?? null;
        if (!$table) jsonError('Type invalide');
        $db->prepare("DELETE FROM $table WHERE id=?")->execute([$fid]);
        jsonSuccess([], 'Fiche supprimée');
    }
}

// ── STATS INSPECTEUR ───────────────────────────────────────
if ($resource === 'inspecteur-stats') {
    requireLogin();
    $db     = getDB();
    $inspId = $id ?? $user['id'];
    jsonSuccess(['stats' => [
        'profilage' => [
            'total'  => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId")->fetchColumn(),
            'soumis' => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND statut='soumis'")->fetchColumn(),
            'valide' => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND statut='valide'")->fetchColumn(),
            'rejete' => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND statut='rejete'")->fetchColumn(),
        ],
        'arbres'  => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$inspId")->fetchColumn(),'valide'=>(int)$db->query("SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$inspId AND statut='valide'")->fetchColumn()],
        'engrais' => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$inspId")->fetchColumn(),'valide'=>(int)$db->query("SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$inspId AND statut='valide'")->fetchColumn()],
        'ce_mois' => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn(),
        'total_all'=> (int)$db->query("SELECT (SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId)+(SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$inspId)+(SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$inspId) as t")->fetchColumn(),
    ]]);
}

// ── STATS ADMIN COOP ───────────────────────────────────────
if ($resource === 'coop-stats') {
    requireLogin();
    $db     = getDB();
    $coopId = $user['cooperative_id'];
    if (!$coopId) jsonError('Pas de coopérative associée');

    // Stats par inspecteur
    $inspecteurs = $db->query("SELECT u.id,u.nom,u.prenom,u.code_inspecteur FROM users u WHERE u.cooperative_id=$coopId AND u.role='inspecteur' AND u.actif=1")->fetchAll();
    foreach ($inspecteurs as &$insp) {
        $i = $insp['id'];
        $insp['fiches'] = [
            'profilage' => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$i")->fetchColumn(),
            'arbres'    => (int)$db->query("SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$i")->fetchColumn(),
            'engrais'   => (int)$db->query("SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$i")->fetchColumn(),
            'valide'    => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$i AND statut='valide'")->fetchColumn(),
        ];
    }

    jsonSuccess(['stats' => [
        'inspecteurs'      => $inspecteurs,
        'total_producteurs'=> (int)$db->query("SELECT COUNT(*) FROM producteurs WHERE cooperative_id=$coopId")->fetchColumn(),
        'fiches_profilage' => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE cooperative_id=$coopId")->fetchColumn(),'soumis'=>(int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE cooperative_id=$coopId AND statut='soumis'")->fetchColumn(),'valide'=>(int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE cooperative_id=$coopId AND statut='valide'")->fetchColumn()],
        'fiches_arbres'    => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_arbres fa JOIN producteurs p ON fa.producteur_id=p.id WHERE p.cooperative_id=$coopId")->fetchColumn(),'valide'=>(int)$db->query("SELECT COUNT(*) FROM fiches_arbres fa JOIN producteurs p ON fa.producteur_id=p.id WHERE p.cooperative_id=$coopId AND fa.statut='valide'")->fetchColumn()],
        'fiches_engrais'   => ['total'=>(int)$db->query("SELECT COUNT(*) FROM fiches_engrais fe JOIN producteurs p ON fe.producteur_id=p.id WHERE p.cooperative_id=$coopId")->fetchColumn(),'valide'=>(int)$db->query("SELECT COUNT(*) FROM fiches_engrais fe JOIN producteurs p ON fe.producteur_id=p.id WHERE p.cooperative_id=$coopId AND fe.statut='valide'")->fetchColumn()],
        'enfants_risque'   => (int)$db->query("SELECT COUNT(*) FROM enfants_menage em JOIN fiches_profilage fp ON em.fiche_profilage_id=fp.id WHERE fp.cooperative_id=$coopId AND JSON_LENGTH(em.travaux_effectues)>0")->fetchColumn(),
    ]]);
}
