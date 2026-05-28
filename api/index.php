<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User-Id, X-User-Role, X-Coop-Id');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// ── Routing ───────────────────────────────────────────────
$apiPath = trim($_GET['_path'] ?? '', '/');
$apiPath = explode('?', $apiPath)[0]; // Enlever query string
$parts   = $apiPath ? explode('/', $apiPath) : [];
$resource = $parts[0] ?? '';
$p1       = $parts[1] ?? ''; // action ou id
$p2       = $parts[2] ?? ''; // id si p1 est une action

// Déterminer action et id
if (is_numeric($p1)) {
    $id     = (int)$p1;
    $action = '';
} else {
    $action = $p1;
    $id     = is_numeric($p2) ? (int)$p2 : null;
}

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = null; // Lazy load

function db() {
    global $db;
    if (!$db) $db = getDB();
    return $db;
}

// ════════════════════════════════════════════════════════
// AUTH (PUBLIC)
// ════════════════════════════════════════════════════════
if ($resource === 'auth') {
    if ($action === 'login' && $method === 'POST') {
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';
        if (!$email || !$pass) jsonError('Email et mot de passe requis');
        $stmt = db()->prepare("SELECT u.*, c.nom as coop_nom FROM users u LEFT JOIN cooperatives c ON u.cooperative_id=c.id WHERE u.email=? AND u.actif=1 LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($pass, $u['password'])) jsonError('Identifiants incorrects', 401);
        $_SESSION['user_id']        = $u['id'];
        $_SESSION['nom']            = $u['nom'];
        $_SESSION['prenom']         = $u['prenom'];
        $_SESSION['role']           = $u['role'];
        $_SESSION['cooperative_id'] = $u['cooperative_id'];
        jsonSuccess(['user' => [
            'id'             => $u['id'],
            'nom'            => $u['nom'],
            'prenom'         => $u['prenom'],
            'email'          => $u['email'],
            'role'           => $u['role'],
            'cooperative_id' => $u['cooperative_id'],
            'coop_nom'       => $u['coop_nom'],
            'code_inspecteur'=> $u['code_inspecteur'],
        ]], 'Connexion réussie');
    }
    if ($action === 'logout') { session_destroy(); jsonSuccess([], 'OK'); }
    if ($action === 'me') {
        requireLogin();
        $stmt = db()->prepare("SELECT u.*, c.nom as coop_nom FROM users u LEFT JOIN cooperatives c ON u.cooperative_id=c.id WHERE u.id=?");
        $stmt->execute([$_SESSION['user_id']]);
        $u = $stmt->fetch();
        jsonSuccess(['user' => $u]);
    }
    jsonError('Auth inconnu: '.$action);
}

// ════════════════════════════════════════════════════════
// INSCRIPTION COOPÉRATIVE (PUBLIC - SANS AUTH)
// ════════════════════════════════════════════════════════
if ($resource === 'cooperative-requests' && $method === 'POST' && !$id && !$action) {
    $nom  = trim($body['nom'] ?? '');
    $email= trim($body['email'] ?? '');
    $pass = $body['password'] ?? '';
    $tel  = $body['telephone'] ?? '';
    $loc  = $body['localite'] ?? '';
    if (!$nom || !$email || !$pass) jsonError('Nom, email et mot de passe requis');
    if (strlen($pass) < 6) jsonError('Mot de passe minimum 6 caractères');
    $exist = db()->prepare("SELECT id FROM cooperative_requests WHERE email=? UNION SELECT id FROM users WHERE email=?");
    $exist->execute([$email, $email]);
    if ($exist->fetch()) jsonError('Cet email est déjà utilisé');
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    db()->prepare("INSERT INTO cooperative_requests (nom,email,telephone,localite,password_hash,statut) VALUES (?,?,?,?,?,'en_attente')")->execute([$nom,$email,$tel,$loc,$hash]);
    // Notifier superadmins
    foreach (db()->query("SELECT id FROM users WHERE role='superadmin'")->fetchAll() as $a) {
        db()->prepare("INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)")->execute([$a['id'],'new_request',"Nouvelle demande: $nom ($email)"]);
    }
    jsonSuccess([], 'Demande envoyée avec succès');
}

// ════════════════════════════════════════════════════════
// ROUTES AUTHENTIFIÉES
// ════════════════════════════════════════════════════════
requireLogin();
$user = currentUser();

// ── COOPERATIVE REQUESTS (SUPERADMIN) ─────────────────
if ($resource === 'cooperative-requests') {
    requireSuperAdmin();
    if ($method === 'GET') {
        $statut = $_GET['statut'] ?? '';
        $sql = "SELECT * FROM cooperative_requests WHERE 1=1";
        $p = [];
        if ($statut) { $sql .= " AND statut=?"; $p[] = $statut; }
        $sql .= " ORDER BY created_at DESC";
        $stmt = db()->prepare($sql); $stmt->execute($p);
        jsonSuccess(['data' => $stmt->fetchAll()]);
    }
    if ($method === 'PUT' && $id) {
        $statut = $body['statut'] ?? '';
        db()->prepare("UPDATE cooperative_requests SET statut=?,validated_at=NOW(),validated_by=? WHERE id=?")->execute([$statut,$user['id'],$id]);
        if ($statut === 'valide') {
            $req = db()->prepare("SELECT * FROM cooperative_requests WHERE id=?"); $req->execute([$id]); $r=$req->fetch();
            $code = 'COOP-'.strtoupper(substr(md5($r['email'].time()),0,6));
            db()->prepare("INSERT INTO cooperatives (nom,localite,code,email,telephone) VALUES (?,?,?,?,?)")->execute([$r['nom'],$r['localite']??'',$code,$r['email'],$r['telephone']??'']);
            $coopId = db()->lastInsertId();
            // Le password_hash est déjà hashé lors de l'inscription - on l'utilise directement
            db()->prepare("INSERT INTO users (nom,prenom,email,password,role,cooperative_id,is_coop_admin,actif) VALUES (?,?,?,?,'admin',?,1,1)")
               ->execute([$r['nom'], 'Admin', $r['email'], $r['password_hash'], $coopId]);
            jsonSuccess(['coop_id'=>$coopId], 'Coopérative validée et compte admin créé');
        }
        jsonSuccess([], 'Demande mise à jour');
    }
}

// ── SUPERADMIN STATS ──────────────────────────────────
if ($resource === 'sa-stats') {
    requireSuperAdmin();
    jsonSuccess(['stats' => [
        'pending_requests' => (int)db()->query("SELECT COUNT(*) FROM cooperative_requests WHERE statut='en_attente'")->fetchColumn(),
        'total_coops'      => (int)db()->query("SELECT COUNT(*) FROM cooperatives")->fetchColumn(),
        'total_users'      => (int)db()->query("SELECT COUNT(*) FROM users WHERE actif=1")->fetchColumn(),
        'total_fiches'     => (int)db()->query("SELECT COUNT(*) FROM fiches_profilage")->fetchColumn() + (int)db()->query("SELECT COUNT(*) FROM fiches_arbres")->fetchColumn() + (int)db()->query("SELECT COUNT(*) FROM fiches_engrais")->fetchColumn(),
    ]]);
}

// ── COOPERATIVES ──────────────────────────────────────
if ($resource === 'cooperatives') {
    if ($method === 'GET') {
        $rows = db()->query("SELECT c.*,(SELECT COUNT(*) FROM users WHERE cooperative_id=c.id AND role='inspecteur') as nb_inspecteurs,(SELECT COUNT(*) FROM producteurs WHERE cooperative_id=c.id) as nb_producteurs FROM cooperatives c ORDER BY c.nom")->fetchAll();
        jsonSuccess(['data' => $rows]);
    }
    if ($method === 'POST') {
        $nom = trim($body['nom']??''); if(!$nom) jsonError('Nom requis');
        $code='COOP-'.strtoupper(substr(md5(time()),0,6));
        db()->prepare("INSERT INTO cooperatives (nom,localite,code) VALUES (?,?,?)")->execute([$nom,$body['localite']??'',$code]);
        jsonSuccess(['id'=>db()->lastInsertId()],'Coopérative créée');
    }
}

// ── USERS / INSPECTEURS ───────────────────────────────
if ($resource === 'users') {
    if ($method === 'GET') {
        $coopId = $user['role']==='admin' ? $user['cooperative_id'] : null;
        $sql = "SELECT u.id,u.nom,u.prenom,u.email,u.role,u.actif,u.code_inspecteur,u.cooperative_id,c.nom as coop_nom FROM users u LEFT JOIN cooperatives c ON u.cooperative_id=c.id WHERE u.role='inspecteur'";
        $p = [];
        if ($coopId) { $sql .= " AND u.cooperative_id=?"; $p[]=$coopId; }
        $sql .= " ORDER BY u.nom";
        $stmt = db()->prepare($sql); $stmt->execute($p);
        jsonSuccess(['data'=>$stmt->fetchAll()]);
    }
    if ($method === 'POST') {
        foreach(['nom','prenom','email','password','cooperative_id'] as $f) if(empty($body[$f])) jsonError("Requis: $f");
        $code='INS-'.strtoupper(substr(md5($body['email'].time()),0,8));
        $hash=password_hash($body['password'],PASSWORD_BCRYPT);
        try {
            db()->prepare("INSERT INTO users (nom,prenom,email,password,role,cooperative_id,code_inspecteur,actif) VALUES (?,?,?,?,'inspecteur',?,?,1)")->execute([$body['nom'],$body['prenom'],$body['email'],$hash,$body['cooperative_id'],$code]);
            $newId=db()->lastInsertId();
            db()->prepare("INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)")->execute([$newId,'bienvenue','Bienvenue sur IndicatorDATA!']);
            jsonSuccess(['id'=>$newId,'code'=>$code],'Inspecteur créé');
        } catch(PDOException $e){
            if($e->getCode()==23000) jsonError('Email déjà utilisé');
            throw $e;
        }
    }
    if ($method==='PUT' && $id) {
        $sets=[]; $vals=[];
        if(isset($body['actif'])){$sets[]='actif=?';$vals[]=$body['actif'];}
        if(!empty($body['password'])){$sets[]='password=?';$vals[]=password_hash($body['password'],PASSWORD_BCRYPT);}
        if(!empty($body['nom'])){$sets[]='nom=?';$vals[]=$body['nom'];}
        if(!$sets) jsonError('Rien à modifier');
        $vals[]=$id;
        db()->prepare("UPDATE users SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
        jsonSuccess([],'OK');
    }
}

// ── PRODUCTEURS ───────────────────────────────────────
if ($resource === 'producteurs') {
    if ($method === 'GET') {
        $coopId = $user['role']==='admin' ? $user['cooperative_id'] : ($user['cooperative_id'] ?? null);
        $sql = "SELECT p.*,c.nom as coop_nom FROM producteurs p LEFT JOIN cooperatives c ON p.cooperative_id=c.id WHERE 1=1";
        $p=[];
        if($coopId){$sql.=" AND p.cooperative_id=?";$p[]=$coopId;}
        $sql.=" ORDER BY p.nom";
        $stmt=db()->prepare($sql);$stmt->execute($p);
        jsonSuccess(['data'=>$stmt->fetchAll()]);
    }
    if ($method==='POST') {
        if(empty($body['nom'])||empty($body['genre'])||empty($body['cooperative_id'])) jsonError('Nom, genre et coopérative requis');
        $code='PROD-'.strtoupper(substr(md5($body['nom'].time()),0,8));
        db()->prepare("INSERT INTO producteurs (code,nom,prenom,genre,age,cooperative_id,localite,section,nb_plantations,superficie_certifiee) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$code,$body['nom'],$body['prenom']??'',$body['genre'],$body['age']??null,$body['cooperative_id'],$body['localite']??'',$body['section']??'',$body['nb_plantations']??0,$body['superficie_certifiee']??0]);
        jsonSuccess(['id'=>db()->lastInsertId(),'code'=>$code],'Producteur créé');
    }
}

// ── STATS ADMIN COOP ──────────────────────────────────
if ($resource === 'coop-stats') {
    $coopId = $user['cooperative_id'];
    if (!$coopId) jsonError('Pas de coopérative');
    $inspecteurs = db()->query("SELECT u.id,u.nom,u.prenom,u.code_inspecteur FROM users u WHERE u.cooperative_id=$coopId AND u.role='inspecteur' AND u.actif=1")->fetchAll();
    foreach($inspecteurs as &$insp){
        $i=$insp['id'];
        $insp['fiches']=['profilage'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$i")->fetchColumn(),'arbres'=>(int)db()->query("SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$i")->fetchColumn(),'engrais'=>(int)db()->query("SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$i")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$i AND statut='valide'")->fetchColumn()];
    }
    jsonSuccess(['stats'=>['inspecteurs'=>$inspecteurs,'total_producteurs'=>(int)db()->query("SELECT COUNT(*) FROM producteurs WHERE cooperative_id=$coopId")->fetchColumn(),'fiches_profilage'=>['total'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE cooperative_id=$coopId")->fetchColumn(),'soumis'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE cooperative_id=$coopId AND statut='soumis'")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE cooperative_id=$coopId AND statut='valide'")->fetchColumn()],'fiches_arbres'=>['total'=>(int)db()->query("SELECT COUNT(*) FROM fiches_arbres fa JOIN producteurs p ON fa.producteur_id=p.id WHERE p.cooperative_id=$coopId")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_arbres fa JOIN producteurs p ON fa.producteur_id=p.id WHERE p.cooperative_id=$coopId AND fa.statut='valide'")->fetchColumn()],'fiches_engrais'=>['total'=>(int)db()->query("SELECT COUNT(*) FROM fiches_engrais fe JOIN producteurs p ON fe.producteur_id=p.id WHERE p.cooperative_id=$coopId")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_engrais fe JOIN producteurs p ON fe.producteur_id=p.id WHERE p.cooperative_id=$coopId AND fe.statut='valide'")->fetchColumn()],'enfants_risque'=>(int)db()->query("SELECT COUNT(*) FROM enfants_menage em JOIN fiches_profilage fp ON em.fiche_profilage_id=fp.id WHERE fp.cooperative_id=$coopId AND JSON_LENGTH(em.travaux_effectues)>0")->fetchColumn()]]);
}

// ── STATS INSPECTEUR ──────────────────────────────────
if ($resource === 'inspecteur-stats') {
    $inspId = $id ?? $user['id'];
    jsonSuccess(['stats'=>['profilage'=>['total'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId")->fetchColumn(),'soumis'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND statut='soumis'")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND statut='valide'")->fetchColumn(),'rejete'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND statut='rejete'")->fetchColumn()],'arbres'=>['total'=>(int)db()->query("SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$inspId")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$inspId AND statut='valide'")->fetchColumn()],'engrais'=>['total'=>(int)db()->query("SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$inspId")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$inspId AND statut='valide'")->fetchColumn()],'ce_mois'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn(),'total_all'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId")->fetchColumn()+(int)db()->query("SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$inspId")->fetchColumn()+(int)db()->query("SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$inspId")->fetchColumn()]]);
}

// ── STATS ADMIN ───────────────────────────────────────
if ($resource === 'stats') {
    requireAdmin();
    jsonSuccess(['stats'=>['cooperatives'=>(int)db()->query("SELECT COUNT(*) FROM cooperatives")->fetchColumn(),'inspecteurs'=>(int)db()->query("SELECT COUNT(*) FROM users WHERE role='inspecteur' AND actif=1")->fetchColumn(),'producteurs'=>(int)db()->query("SELECT COUNT(*) FROM producteurs")->fetchColumn(),'fiches_profilage'=>['total'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage")->fetchColumn(),'soumis'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE statut='soumis'")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_profilage WHERE statut='valide'")->fetchColumn()],'fiches_arbres'=>['total'=>(int)db()->query("SELECT COUNT(*) FROM fiches_arbres")->fetchColumn(),'soumis'=>(int)db()->query("SELECT COUNT(*) FROM fiches_arbres WHERE statut='soumis'")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_arbres WHERE statut='valide'")->fetchColumn()],'fiches_engrais'=>['total'=>(int)db()->query("SELECT COUNT(*) FROM fiches_engrais")->fetchColumn(),'soumis'=>(int)db()->query("SELECT COUNT(*) FROM fiches_engrais WHERE statut='soumis'")->fetchColumn(),'valide'=>(int)db()->query("SELECT COUNT(*) FROM fiches_engrais WHERE statut='valide'")->fetchColumn()],'enfants_risque'=>(int)db()->query("SELECT COUNT(*) FROM enfants_menage WHERE JSON_LENGTH(travaux_effectues)>0")->fetchColumn()]]);
}

// ── NOTIFICATIONS ─────────────────────────────────────
if ($resource === 'notifications') {
    if ($method==='GET'){
        $stmt=db()->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");$stmt->execute([$user['id']]);
        $unread=db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND lu=0");$unread->execute([$user['id']]);
        jsonSuccess(['data'=>$stmt->fetchAll(),'unread'=>(int)$unread->fetchColumn()]);
    }
    if($method==='PUT'){db()->prepare("UPDATE notifications SET lu=1 WHERE user_id=?")->execute([$user['id']]);jsonSuccess([],'OK');}
}

// ── FICHES PROFILAGE ──────────────────────────────────
if ($resource === 'fiches-profilage') {
    if ($method==='GET') {
        $sql="SELECT fp.*,p.nom as prod_nom,p.prenom as prod_prenom,p.code as prod_code,u.nom as insp_nom,u.prenom as insp_prenom,c.nom as coop_nom FROM fiches_profilage fp LEFT JOIN producteurs p ON fp.producteur_id=p.id LEFT JOIN users u ON fp.inspecteur_id=u.id LEFT JOIN cooperatives c ON fp.cooperative_id=c.id WHERE 1=1";
        $p=[];
        if($user['role']==='inspecteur'){$sql.=" AND fp.inspecteur_id=?";$p[]=$user['id'];}
        elseif($user['role']==='admin'&&$user['cooperative_id']){$sql.=" AND fp.cooperative_id=?";$p[]=$user['cooperative_id'];}
        if(!empty($_GET['statut'])){$sql.=" AND fp.statut=?";$p[]=$_GET['statut'];}
        $sql.=" ORDER BY fp.created_at DESC";
        $stmt=db()->prepare($sql);$stmt->execute($p);
        $fiches=$stmt->fetchAll();
        foreach($fiches as &$f){$s=db()->prepare("SELECT * FROM enfants_menage WHERE fiche_profilage_id=?");$s->execute([$f['id']]);$f['enfants']=$s->fetchAll();}
        jsonSuccess(['data'=>$fiches]);
    }
    if ($method==='POST') {
        db()->beginTransaction();
        try {
            db()->prepare("INSERT INTO fiches_profilage (inspecteur_id,cooperative_id,producteur_id,date_profilage,nom_communaute,nb_membres_hommes,nb_membres_femmes,nb_membres_total,nb_travailleurs_hommes,nb_travailleurs_femmes,nb_travailleurs_total,statut) VALUES (?,?,?,?,?,?,?,?,?,?,?,'soumis')")->execute([$user['id'],$body['cooperative_id']??$user['cooperative_id'],$body['producteur_id'],$body['date_profilage'],$body['nom_communaute']??'',$body['nb_membres_hommes']??0,$body['nb_membres_femmes']??0,$body['nb_membres_total']??0,$body['nb_travailleurs_hommes']??0,$body['nb_travailleurs_femmes']??0,$body['nb_travailleurs_total']??0]);
            $fid=db()->lastInsertId();
            if(!empty($body['enfants'])){$s=db()->prepare("INSERT INTO enfants_menage (fiche_profilage_id,nom_prenom,lien_parente,genre,age,extrait_naissance,etat_scolarisation,travaux_effectues,solution_pour_arreter) VALUES (?,?,?,?,?,?,?,?,?)");foreach($body['enfants'] as $e)$s->execute([$fid,$e['nom_prenom'],$e['lien_parente']??'A',$e['genre']??'Garçon',$e['age']??0,$e['extrait_naissance']??null,$e['etat_scolarisation']??null,json_encode($e['travaux_effectues']??[]),$e['solution_pour_arreter']??null]);}
            db()->commit();
            jsonSuccess(['id'=>$fid],'Fiche soumise');
        } catch(Exception $e){db()->rollBack();jsonError($e->getMessage());}
    }
    if ($method==='PUT' && $id) {
        $fq=db()->prepare("SELECT * FROM fiches_profilage WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        if(!empty($body['statut'])&&in_array($body['statut'],['valide','rejete'])){
            requireAdmin();
            db()->prepare("UPDATE fiches_profilage SET statut=?,commentaire_admin=? WHERE id=?")->execute([$body['statut'],$body['commentaire_admin']??'',$id]);
            $msg=($body['statut']==='valide')?"Fiche profilage #$id validée ✓":"Fiche profilage #$id rejetée. ".($body['commentaire_admin']??'');
            db()->prepare("INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)")->execute([$fi['inspecteur_id'],'fiche_'.$body['statut'],$msg]);
            jsonSuccess([],'OK');
        }
        $canEdit=in_array($user['role'],['admin','superadmin'])||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
        if(!$canEdit) jsonError('Non autorisé',403);
        db()->prepare("UPDATE fiches_profilage SET date_profilage=?,nom_communaute=?,nb_membres_hommes=?,nb_membres_femmes=?,nb_membres_total=?,nb_travailleurs_hommes=?,nb_travailleurs_femmes=?,nb_travailleurs_total=?,statut='soumis' WHERE id=?")->execute([$body['date_profilage']??$fi['date_profilage'],$body['nom_communaute']??$fi['nom_communaute'],$body['nb_membres_hommes']??0,$body['nb_membres_femmes']??0,$body['nb_membres_total']??0,$body['nb_travailleurs_hommes']??0,$body['nb_travailleurs_femmes']??0,$body['nb_travailleurs_total']??0,$id]);
        jsonSuccess([],'Modifiée');
    }
    if ($method==='DELETE' && $id) {
        $fq=db()->prepare("SELECT * FROM fiches_profilage WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        $canDel=in_array($user['role'],['admin','superadmin'])||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
        if(!$canDel) jsonError('Non autorisé',403);
        db()->prepare("DELETE FROM enfants_menage WHERE fiche_profilage_id=?")->execute([$id]);
        db()->prepare("DELETE FROM fiches_profilage WHERE id=?")->execute([$id]);
        jsonSuccess([],'Supprimée');
    }
}

// ── FICHES ARBRES ─────────────────────────────────────
if ($resource === 'fiches-arbres') {
    if ($method==='GET'){
        $sql="SELECT fa.*,p.nom as prod_nom,u.nom as insp_nom,u.prenom as insp_prenom FROM fiches_arbres fa LEFT JOIN producteurs p ON fa.producteur_id=p.id LEFT JOIN users u ON fa.inspecteur_id=u.id WHERE 1=1";
        $p=[];
        if($user['role']==='inspecteur'){$sql.=" AND fa.inspecteur_id=?";$p[]=$user['id'];}
        elseif($user['role']==='admin'&&$user['cooperative_id']){$sql.=" AND p.cooperative_id=?";$p[]=$user['cooperative_id'];}
        if(!empty($_GET['statut'])){$sql.=" AND fa.statut=?";$p[]=$_GET['statut'];}
        $sql.=" ORDER BY fa.created_at DESC";
        $stmt=db()->prepare($sql);$stmt->execute($p);
        $fiches=$stmt->fetchAll();
        foreach($fiches as &$f){$s=db()->prepare("SELECT * FROM especes_arbres WHERE fiche_arbre_id=?");$s->execute([$f['id']]);$f['especes']=$s->fetchAll();}
        jsonSuccess(['data'=>$fiches]);
    }
    if ($method==='POST'){
        db()->beginTransaction();
        try{
            db()->prepare("INSERT INTO fiches_arbres (inspecteur_id,producteur_id,date_collecte,nb_arbres_ombrage,densite_par_hectare,nb_arbres_deficitaires,statut) VALUES (?,?,?,?,?,?,'soumis')")->execute([$user['id'],$body['producteur_id'],$body['date_collecte'],$body['nb_arbres_ombrage']??0,$body['densite_par_hectare']??0,$body['nb_arbres_deficitaires']??0]);
            $fid=db()->lastInsertId();
            if(!empty($body['especes'])){$s=db()->prepare("INSERT INTO especes_arbres (fiche_arbre_id,nom_local,nom_botanique,origine,non_ombrage,nombre_total) VALUES (?,?,?,?,?,?)");foreach($body['especes'] as $e)$s->execute([$fid,$e['nom_local']??'',$e['nom_botanique']??'',$e['origine']??'1',$e['non_ombrage']??0,$e['nombre_total']??0]);}
            db()->commit();jsonSuccess(['id'=>$fid],'OK');
        }catch(Exception $e){db()->rollBack();jsonError($e->getMessage());}
    }
    if ($method==='PUT'&&$id){
        $fq=db()->prepare("SELECT * FROM fiches_arbres WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        if(!empty($body['statut'])&&in_array($body['statut'],['valide','rejete'])){requireAdmin();db()->prepare("UPDATE fiches_arbres SET statut=?,commentaire_admin=? WHERE id=?")->execute([$body['statut'],$body['commentaire_admin']??'',$id]);jsonSuccess([],'OK');}
        $canEdit=in_array($user['role'],['admin','superadmin'])||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
        if(!$canEdit) jsonError('Non autorisé',403);
        db()->prepare("UPDATE fiches_arbres SET date_collecte=?,nb_arbres_ombrage=?,densite_par_hectare=?,nb_arbres_deficitaires=?,statut='soumis' WHERE id=?")->execute([$body['date_collecte']??$fi['date_collecte'],$body['nb_arbres_ombrage']??0,$body['densite_par_hectare']??0,$body['nb_arbres_deficitaires']??0,$id]);
        jsonSuccess([],'Modifiée');
    }
    if ($method==='DELETE'&&$id){
        $fq=db()->prepare("SELECT * FROM fiches_arbres WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        $canDel=in_array($user['role'],['admin','superadmin'])||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
        if(!$canDel) jsonError('Non autorisé',403);
        db()->prepare("DELETE FROM especes_arbres WHERE fiche_arbre_id=?")->execute([$id]);
        db()->prepare("DELETE FROM fiches_arbres WHERE id=?")->execute([$id]);
        jsonSuccess([],'Supprimée');
    }
}

// ── FICHES ENGRAIS ────────────────────────────────────
if ($resource === 'fiches-engrais') {
    if ($method==='GET'){
        $sql="SELECT fe.*,p.nom as prod_nom,u.nom as insp_nom,u.prenom as insp_prenom FROM fiches_engrais fe LEFT JOIN producteurs p ON fe.producteur_id=p.id LEFT JOIN users u ON fe.inspecteur_id=u.id WHERE 1=1";
        $p=[];
        if($user['role']==='inspecteur'){$sql.=" AND fe.inspecteur_id=?";$p[]=$user['id'];}
        elseif($user['role']==='admin'&&$user['cooperative_id']){$sql.=" AND p.cooperative_id=?";$p[]=$user['cooperative_id'];}
        if(!empty($_GET['statut'])){$sql.=" AND fe.statut=?";$p[]=$_GET['statut'];}
        $sql.=" ORDER BY fe.created_at DESC";
        $stmt=db()->prepare($sql);$stmt->execute($p);
        jsonSuccess(['data'=>$stmt->fetchAll()]);
    }
    if ($method==='POST'){
        db()->beginTransaction();
        try{
            db()->prepare("INSERT INTO fiches_engrais (inspecteur_id,producteur_id,date_collecte,statut) VALUES (?,?,?,'soumis')")->execute([$user['id'],$body['producteur_id'],$body['date_collecte']]);
            $fid=db()->lastInsertId();
            if(!empty($body['organiques'])){$s=db()->prepare("INSERT INTO engrais_organiques (fiche_engrais_id,source,periode_application,frequence_an,quantite_par_ha) VALUES (?,?,?,?,?)");foreach($body['organiques'] as $o)$s->execute([$fid,$o['source']??'VEGETALE',$o['periode_application']??'',$o['frequence_an']??0,$o['quantite_par_ha']??0]);}
            if(!empty($body['inorganiques'])){$s=db()->prepare("INSERT INTO engrais_inorganiques (fiche_engrais_id,nom_commercial,formulation_npk,superficie_appliquee,nb_sacs_periode) VALUES (?,?,?,?,?)");foreach($body['inorganiques'] as $i)$s->execute([$fid,$i['nom_commercial']??'',$i['formulation_npk']??'',$i['superficie_appliquee']??0,$i['nb_sacs_periode']??0]);}
            if(!empty($body['pesticides'])){$s=db()->prepare("INSERT INTO pesticides (fiche_engrais_id,type_pesticide,nom_commercial,ingredients_actifs,superficie_appliquee,periode_traitement) VALUES (?,?,?,?,?,?)");foreach($body['pesticides'] as $p)$s->execute([$fid,$p['type_pesticide']??'4',$p['nom_commercial']??'',$p['ingredients_actifs']??'',$p['superficie_appliquee']??0,$p['periode_traitement']??'']);}
            db()->commit();jsonSuccess(['id'=>$fid],'OK');
        }catch(Exception $e){db()->rollBack();jsonError($e->getMessage());}
    }
    if ($method==='PUT'&&$id){
        $fq=db()->prepare("SELECT * FROM fiches_engrais WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        if(!empty($body['statut'])&&in_array($body['statut'],['valide','rejete'])){requireAdmin();db()->prepare("UPDATE fiches_engrais SET statut=?,commentaire_admin=? WHERE id=?")->execute([$body['statut'],$body['commentaire_admin']??'',$id]);jsonSuccess([],'OK');}
        $canEdit=in_array($user['role'],['admin','superadmin'])||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
        if(!$canEdit) jsonError('Non autorisé',403);
        db()->prepare("UPDATE fiches_engrais SET date_collecte=?,statut='soumis' WHERE id=?")->execute([$body['date_collecte']??$fi['date_collecte'],$id]);
        jsonSuccess([],'Modifiée');
    }
    if ($method==='DELETE'&&$id){
        $fq=db()->prepare("SELECT * FROM fiches_engrais WHERE id=?");$fq->execute([$id]);$fi=$fq->fetch();
        if(!$fi) jsonError('Non trouvée',404);
        $canDel=in_array($user['role'],['admin','superadmin'])||($fi['inspecteur_id']==$user['id']&&in_array($fi['statut'],['brouillon','rejete']));
        if(!$canDel) jsonError('Non autorisé',403);
        db()->prepare("DELETE FROM engrais_organiques WHERE fiche_engrais_id=?")->execute([$id]);
        db()->prepare("DELETE FROM engrais_inorganiques WHERE fiche_engrais_id=?")->execute([$id]);
        db()->prepare("DELETE FROM pesticides WHERE fiche_engrais_id=?")->execute([$id]);
        db()->prepare("DELETE FROM fiches_engrais WHERE id=?")->execute([$id]);
        jsonSuccess([],'Supprimée');
    }
}

// ── AI CHAT ───────────────────────────────────────────
if ($resource === 'ai-chat' && $method==='POST') {
    $msg=$body['message']??'';if(!$msg)jsonError('Message requis');
    $sys="Tu es CACAO-AI, expert en agriculture cacao en Côte d'Ivoire. Réponds en français.";
    $ch=curl_init('https://api.mistral.ai/v1/chat/completions');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.MISTRAL_API_KEY],CURLOPT_POSTFIELDS=>json_encode(['model'=>MISTRAL_MODEL,'messages'=>[['role'=>'system','content'=>$sys],['role'=>'user','content'=>$msg]],'max_tokens'=>800])]);
    $res=curl_exec($ch);curl_close($ch);
    $d=json_decode($res,true);
    jsonSuccess(['reply'=>$d['choices'][0]['message']['content']??'Service indisponible.']);
}

jsonError('Ressource non trouvée: '.$resource, 404);
