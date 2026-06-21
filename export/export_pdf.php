<?php
/**
 * DiabSuivi — Export PDF d'un rapport glycémique
 *
 * Accessible par :
 *   - Patient : /export/export_pdf.php (son propre rapport)
 *   - Médecin  : /export/export_pdf.php?patient=X (rapport d'un de ses patients)
 *
 * Paramètres GET :
 *   periode  : 7 | 30 | 90 (défaut : 30)
 *   patient  : id_patient (médecin uniquement)
 */

require_once __DIR__ . '/../includes/session.php';
exigerConnexion();
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$role      = $_SESSION['user_role'];
$pdo       = getDB();
$periode   = in_array((int)($_GET['periode'] ?? 30), [7, 30, 90])
             ? (int)$_GET['periode'] : 30;

// ── Déterminer le patient cible ───────────────────────────────
if ($role === 'patient') {
    $idPatient = (int) $_SESSION['user_id'];

} elseif ($role === 'medecin') {
    $idPatient = (int) ($_GET['patient'] ?? 0);
    if (!$idPatient) {
        redirect('/medecin/dashboard.php', 'Patient non spécifié.', 'danger');
    }
    // Vérifier que ce patient est bien suivi par ce médecin
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM suivi
        WHERE id_medecin = :im AND id_patient = :ip AND actif = 1
    ");
    $check->execute([':im' => $_SESSION['user_id'], ':ip' => $idPatient]);
    if (!$check->fetchColumn()) {
        redirect('/medecin/dashboard.php', 'Accès non autorisé.', 'danger');
    }
} else {
    redirect('/index.php', 'Accès refusé.', 'danger');
}

// ── Générer le PDF via le script Python ───────────────────────
$tmpDir    = sys_get_temp_dir();
$filename  = "DiabSuivi_Rapport_P{$idPatient}_{$periode}j_" . date('Ymd_His') . '.pdf';
$outputPath = $tmpDir . DIRECTORY_SEPARATOR . $filename;

$scriptPath = escapeshellarg(__DIR__ . '/../export/generate_rapport.py');
$cmd = sprintf(
    'timeout 45 python3 %s --patient %d --periode %d --output %s 2>/dev/null',
    $scriptPath,
    $idPatient,
    $periode,
    escapeshellarg($outputPath)
);

$output     = [];
$returnCode = 0;
exec($cmd, $output, $returnCode);

$jsonOut = implode('', $output);
$result  = json_decode($jsonOut, true);

// ── Gestion des erreurs ───────────────────────────────────────
if ($returnCode === 124) {
    $errMsg = 'La génération du PDF a pris trop de temps. Réduisez la période ou réessayez.';
} elseif ($returnCode !== 0 || !$result) {
    $errMsg = 'Erreur lors de la génération du PDF. Vérifiez que Python et ReportLab sont installés.';
} elseif (isset($result['error'])) {
    $errMsg = 'Erreur : ' . $result['error'];
} elseif (!file_exists($outputPath)) {
    $errMsg = 'Le fichier PDF n\'a pas pu être créé.';
} else {
    $errMsg = null;
}

if ($errMsg) {
    $retour = $role === 'medecin'
        ? "/medecin/patient_detail.php?id={$idPatient}"
        : '/patient/dashboard.php';
    redirect($retour, $errMsg, 'danger');
}

// ── Récupérer le nom du patient pour le nom de fichier ───────
$stmtN = $pdo->prepare("SELECT nom, prenom FROM patient WHERE id_patient = :id");
$stmtN->execute([':id' => $idPatient]);
$pat   = $stmtN->fetch();
$nomFichier = 'DiabSuivi_' . ($pat
    ? preg_replace('/[^a-zA-Z0-9]/', '_', $pat['prenom'] . '_' . $pat['nom'])
    : 'Rapport') . "_{$periode}j.pdf";

// ── Envoyer le PDF au navigateur ──────────────────────────────
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('Content-Length: ' . filesize($outputPath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($outputPath);

// Nettoyer le fichier temporaire
@unlink($outputPath);
exit;
