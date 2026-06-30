<?php
/**
 * DiabSuivi — En-tête commun (HTML head + navbar)
 *
 * Ce fichier prépare les données nécessaires (compteurs d'alertes et
 * de messages) puis délègue l'affichage des liens de navigation au
 * fichier dédié au rôle courant (includes/nav/patient.php ou
 * includes/nav/medecin.php). Avant cette séparation, tous les liens
 * des deux rôles vivaient ici avec des conditions if/else imbriquées.
 */
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session.php';
}
require_once __DIR__ . '/fonctions.php';

$role      = $_SESSION['user_role'] ?? '';
$nomUser   = $_SESSION['user_nom']  ?? '';
$nbAlertes  = 0;
$nbMessages = 0;

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../db/connexion.php';
    require_once __DIR__ . '/messagerie.php';
    if ($role === 'patient') {
        $nbAlertes = getNbAlertesNonLues((int) $_SESSION['user_id']);
    }
    $nbMessages = getNbMessagesNonLus((int) $_SESSION['user_id'], $role);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= esc($pageTitle ?? 'DiabSuivi') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- PWA : manifest et icônes -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#1D9E75">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="DiabSuivi">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/icon-192.png">

    <!-- Content Security Policy -->
    <meta http-equiv="Content-Security-Policy"
          content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self';">
</head>
<body>

<?php if (estConnecte()): ?>
<nav class="navbar">
    <a href="/<?= $role ?>/dashboard.php" class="nav-brand">
        <span class="nav-logo">♥</span> DiabSuivi
    </a>
    <div class="nav-links">
        <?php
        // Délégation au fichier de nav dédié au rôle courant.
        // $nbAlertes et $nbMessages sont disponibles dans le scope inclus.
        $navFile = __DIR__ . "/nav/{$role}.php";
        if (file_exists($navFile)) {
            require $navFile;
        }
        ?>
    </div>
    <div class="nav-user">
        <span class="nav-username">👤 <?= esc($nomUser) ?></span>
        <a href="/auth/logout.php" class="nav-link nav-logout">Déconnexion</a>
    </div>
</nav>
<?php endif; ?>

<?= flashMessage() ?>

<main class="main-content">
