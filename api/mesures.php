<?php
/**
 * DiabSuivi — API REST mesures glycémiques
 * Accès : patients uniquement
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

// ── Authentification et rôle ───────────────────────────────────
if (!estConnecte()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié.']);
    exit;
}

// Seuls les patients peuvent utiliser cette API
if ($_SESSION['user_role'] !== 'patient') {
    http_response_code(403);
    echo json_encode(['error' => 'Accès réservé aux patients.']);
    exit;
}

$idPatient = (int) $_SESSION['user_id'];
$method    = $_SERVER['REQUEST_METHOD'];

// ── GET : récupérer les mesures ───────────────────────────────
if ($method === 'GET') {
    $limit  = min((int) ($_GET['limit'] ?? 30), 100); // max 100
    $offset = max((int) ($_GET['offset'] ?? 0), 0);
    $contexte = $_GET['contexte'] ?? null;
    $contextes_valides = ['A jeun','Post-repas','Avant sport','Apres sport','Autre'];

    $where  = 'WHERE id_patient = :id';
    $params = [':id' => $idPatient];

    if ($contexte && in_array($contexte, $contextes_valides, true)) {
        $where .= ' AND contexte = :ctx';
        $params[':ctx'] = $contexte;
    }

    $stmt = getDB()->prepare("
        SELECT id_mesure, valeur_glycemie, date_heure, contexte, commentaire
        FROM   mesure_glycemie
        $where
        ORDER  BY date_heure DESC
        LIMIT  :lim OFFSET :off
    ");
    $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        if ($k !== ':lim' && $k !== ':off') $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $mesures = $stmt->fetchAll();

    echo json_encode([
        'data'   => $mesures,
        'limit'  => $limit,
        'offset' => $offset,
        'count'  => count($mesures),
    ]);
    exit;
}

// ── POST : ajouter une mesure ─────────────────────────────────
if ($method === 'POST') {
    // Vérification CSRF via en-tête pour les appels AJAX
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(getCsrfToken(), $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token CSRF invalide.']);
        exit;
    }

    $data      = json_decode(file_get_contents('php://input'), true) ?? [];
    $valeur    = (float) ($data['valeur_glycemie'] ?? 0);
    $date      = trim($data['date_heure'] ?? '');
    $contexte  = $data['contexte'] ?? '';
    $commentaire = trim($data['commentaire'] ?? '');

    $contextes_valides = ['A jeun','Post-repas','Avant sport','Apres sport','Autre'];

    if ($valeur < 0.10 || $valeur > 6.00) {
        http_response_code(422);
        echo json_encode(['error' => 'Valeur glycémique invalide (0.10–6.00 g/L).']);
        exit;
    }
    if (!validerDatetime($date)) {
        http_response_code(422);
        echo json_encode(['error' => 'Date/heure invalide.']);
        exit;
    }
    if (!in_array($contexte, $contextes_valides, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Contexte invalide.']);
        exit;
    }

    $dt    = validerDatetime($date);
    $dateSQL = $dt->format('Y-m-d H:i:s');

    $stmt = getDB()->prepare("
        INSERT INTO mesure_glycemie
            (id_patient, valeur_glycemie, date_heure, contexte, commentaire)
        VALUES (:id, :val, :dh, :ctx, :com)
    ");
    $stmt->execute([
        ':id'  => $idPatient,
        ':val' => $valeur,
        ':dh'  => $dateSQL,
        ':ctx' => $contexte,
        ':com' => $commentaire ?: null,
    ]);
    $idMesure = (int) getDB()->lastInsertId();
    creerAlerteAutomatique($idPatient, $idMesure, $valeur, $contexte);

    http_response_code(201);
    echo json_encode([
        'success'   => true,
        'id_mesure' => $idMesure,
        'statut'    => statutGlycemie($valeur, $contexte),
    ]);
    exit;
}

// ── Méthode non supportée ─────────────────────────────────────
http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée.']);
