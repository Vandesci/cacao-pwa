<?php
/**
 * EXPORT.PHP - Export PDF des fiches
 * Utilise une librairie JS côté client (jsPDF) pour générer le PDF
 * Ce endpoint fournit les données JSON structurées pour l'export
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }

$db     = getDB();
$role   = $_SESSION['role'];
$userId = $_SESSION['user_id'];
$coopId = $_SESSION['cooperative_id'];

$type   = $_GET['type']   ?? 'profilage'; // profilage|arbres|engrais|all
$mode   = $_GET['mode']   ?? 'all';       // all|single
$id     = $_GET['id']     ?? null;
$statut = $_GET['statut'] ?? 'valide';

function getFicheProfilage($db, $coopId, $role, $id = null) {
    $sql = "SELECT fp.*, 
        p.nom as prod_nom, p.prenom as prod_prenom, p.code as prod_code,
        p.genre as prod_genre, p.age as prod_age,
        p.superficie_certifiee, p.nb_plantations,
        u.nom as insp_nom, u.prenom as insp_prenom, u.code_inspecteur,
        c.nom as coop_nom, c.localite as coop_localite
        FROM fiches_profilage fp
        LEFT JOIN producteurs p ON fp.producteur_id = p.id
        LEFT JOIN users u ON fp.inspecteur_id = u.id
        LEFT JOIN cooperatives c ON fp.cooperative_id = c.id
        WHERE fp.statut = 'valide'";
    $params = [];
    if ($role === 'admin' && $coopId) { $sql .= " AND fp.cooperative_id=?"; $params[] = $coopId; }
    if ($id) { $sql .= " AND fp.id=?"; $params[] = $id; }
    $sql .= " ORDER BY fp.created_at DESC";
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $fiches = $stmt->fetchAll();
    foreach ($fiches as &$f) {
        $s = $db->prepare("SELECT * FROM enfants_menage WHERE fiche_profilage_id=?");
        $s->execute([$f['id']]); $f['enfants'] = $s->fetchAll();
    }
    return $fiches;
}

function getFicheArbres($db, $coopId, $role, $id = null) {
    $sql = "SELECT fa.*,
        p.nom as prod_nom, p.prenom as prod_prenom, p.code as prod_code,
        p.superficie_certifiee,
        u.nom as insp_nom, u.prenom as insp_prenom,
        c.nom as coop_nom
        FROM fiches_arbres fa
        LEFT JOIN producteurs p ON fa.producteur_id = p.id
        LEFT JOIN users u ON fa.inspecteur_id = u.id
        LEFT JOIN cooperatives c ON p.cooperative_id = c.id
        WHERE fa.statut = 'valide'";
    $params = [];
    if ($role === 'admin' && $coopId) { $sql .= " AND p.cooperative_id=?"; $params[] = $coopId; }
    if ($id) { $sql .= " AND fa.id=?"; $params[] = $id; }
    $sql .= " ORDER BY fa.created_at DESC";
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $fiches = $stmt->fetchAll();
    foreach ($fiches as &$f) {
        $s = $db->prepare("SELECT * FROM especes_arbres WHERE fiche_arbre_id=?");
        $s->execute([$f['id']]); $f['especes'] = $s->fetchAll();
    }
    return $fiches;
}

function getFicheEngrais($db, $coopId, $role, $id = null) {
    $sql = "SELECT fe.*,
        p.nom as prod_nom, p.prenom as prod_prenom, p.code as prod_code,
        u.nom as insp_nom, u.prenom as insp_prenom,
        c.nom as coop_nom
        FROM fiches_engrais fe
        LEFT JOIN producteurs p ON fe.producteur_id = p.id
        LEFT JOIN users u ON fe.inspecteur_id = u.id
        LEFT JOIN cooperatives c ON p.cooperative_id = c.id
        WHERE fe.statut = 'valide'";
    $params = [];
    if ($role === 'admin' && $coopId) { $sql .= " AND p.cooperative_id=?"; $params[] = $coopId; }
    if ($id) { $sql .= " AND fe.id=?"; $params[] = $id; }
    $sql .= " ORDER BY fe.created_at DESC";
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $fiches = $stmt->fetchAll();
    foreach ($fiches as &$f) {
        $f['organiques']   = $db->query("SELECT * FROM engrais_organiques WHERE fiche_engrais_id={$f['id']}")->fetchAll();
        $f['inorganiques'] = $db->query("SELECT * FROM engrais_inorganiques WHERE fiche_engrais_id={$f['id']}")->fetchAll();
        $f['pesticides']   = $db->query("SELECT * FROM pesticides WHERE fiche_engrais_id={$f['id']}")->fetchAll();
    }
    return $fiches;
}

// Stats inspecteur
function getInspecteurStats($db, $inspId) {
    return [
        'fiches_profilage' => [
            'total'   => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId")->fetchColumn(),
            'soumis'  => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND statut='soumis'")->fetchColumn(),
            'valide'  => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND statut='valide'")->fetchColumn(),
            'rejete'  => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND statut='rejete'")->fetchColumn(),
        ],
        'fiches_arbres' => [
            'total'  => (int)$db->query("SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$inspId")->fetchColumn(),
            'valide' => (int)$db->query("SELECT COUNT(*) FROM fiches_arbres WHERE inspecteur_id=$inspId AND statut='valide'")->fetchColumn(),
        ],
        'fiches_engrais' => [
            'total'  => (int)$db->query("SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$inspId")->fetchColumn(),
            'valide' => (int)$db->query("SELECT COUNT(*) FROM fiches_engrais WHERE inspecteur_id=$inspId AND statut='valide'")->fetchColumn(),
        ],
        'ce_mois' => (int)$db->query("SELECT COUNT(*) FROM fiches_profilage WHERE inspecteur_id=$inspId AND MONTH(created_at)=MONTH(NOW())")->fetchColumn(),
    ];
}

// Construire le rapport complet
$rapport = [
    'generated_at' => date('Y-m-d H:i:s'),
    'generated_by' => $_SESSION['nom'] . ' ' . $_SESSION['prenom'],
    'role'         => $role,
];

switch ($type) {
    case 'profilage':
        $rapport['fiches'] = getFicheProfilage($db, $coopId, $role, $id);
        $rapport['type']   = 'Profilage des Ménages';
        break;
    case 'arbres':
        $rapport['fiches'] = getFicheArbres($db, $coopId, $role, $id);
        $rapport['type']   = "Arbres d'Ombrage";
        break;
    case 'engrais':
        $rapport['fiches'] = getFicheEngrais($db, $coopId, $role, $id);
        $rapport['type']   = 'Engrais & Pesticides';
        break;
    case 'all':
        $rapport['profilage'] = getFicheProfilage($db, $coopId, $role, null);
        $rapport['arbres']    = getFicheArbres($db, $coopId, $role, null);
        $rapport['engrais']   = getFicheEngrais($db, $coopId, $role, null);
        $rapport['type']      = 'Rapport Complet';
        break;
    case 'inspecteur-stats':
        $inspId = $id ?? $userId;
        $rapport['stats']      = getInspecteurStats($db, $inspId);
        $rapport['inspecteur'] = $db->query("SELECT nom,prenom,code_inspecteur FROM users WHERE id=$inspId")->fetch();
        break;
}

echo json_encode(['success' => true, 'rapport' => $rapport], JSON_UNESCAPED_UNICODE);
