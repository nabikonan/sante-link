<?php
// Inclure session si pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session.php';
}
require_once __DIR__ . '/fonctions.php';

$role    = $_SESSION['user_role'] ?? '';
$nomUser = $_SESSION['user_nom']  ?? '';
$nbAlertes  = 0;
$nbMessages = 0;

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../db/connexion.php';
    require_once __DIR__ . '/../includes/messagerie.php';
    if ($role === 'patient') {
        $nbAlertes  = getNbAlertesNonLues((int) $_SESSION['user_id']);
    }
    $nbMessages = getNbMessagesNonLus((int) $_SESSION['user_id'], $role);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle ?? 'SanteLink') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- Content Security Policy -->
    <meta http-equiv="Content-Security-Policy"
          content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';">
</head>
<body>

<?php if (estConnecte()): ?>
<nav class="navbar">
    <a href="/<?= $role ?>/dashboard.php" class="nav-brand">
        <span class="nav-logo">♥</span> SanteLink
    </a>
    <div class="nav-links">
        <?php if ($role === 'patient'): ?>
            <a href="/patient/dashboard.php"   class="nav-link">🏠 Accueil</a>
            <a href="/patient/mesure_form.php" class="nav-link">➕ Mesure</a>
            <a href="/patient/historique.php"  class="nav-link">📈 Historique</a>
            <a href="/patient/alertes.php"     class="nav-link">
                🔔 Alertes
                <?php if ($nbAlertes > 0): ?>
                    <span class="badge badge-danger badge-sm"><?= $nbAlertes ?></span>
                <?php endif; ?>
            </a>
            <a href="/patient/traitements.php" class="nav-link">💊 Traitements</a>
            <a href="/patient/ia_risque.php"   class="nav-link">🤖 IA Risque</a>
            <a href="/messagerie/boite.php"    class="nav-link">
                💬 Messages
                <?php if ($nbMessages > 0): ?>
                    <span class="badge badge-danger badge-sm"><?= $nbMessages ?></span>
                <?php endif; ?>
            </a>
            <a href="/patient/rapport.php"                   class="nav-link">📄 Rapport</a>
            <a href="/patient/rappels.php"                        class="nav-link">⏰ Rappels</a>
            <a href="/patient/preferences_notifications.php" class="nav-link">⚙️ Notifs</a>
            <a href="/patient/securite.php"                  class="nav-link">🔒 Sécurité</a>

        <?php elseif ($role === 'medecin'): ?>
            <a href="/medecin/dashboard.php"    class="nav-link">🏠 Accueil</a>
            <a href="/messagerie/boite.php"    class="nav-link">
                💬 Messages
                <?php if ($nbMessages > 0): ?>
                    <span class="badge badge-danger badge-sm"><?= $nbMessages ?></span>
                <?php endif; ?>
            </a>
            <a href="/medecin/alertes.php"      class="nav-link">🔔 Alertes</a>
            <a href="/medecin/ordonnances.php"  class="nav-link">📋 Ordonnances</a>
        <?php endif; ?>
    </div>
    <div class="nav-user">
        <span class="nav-username">👤 <?= esc($nomUser) ?></span>
        <a href="/auth/logout.php" class="nav-link nav-logout">Déconnexion</a>
    </div>
</nav>
<?php endif; ?>

<?= flashMessage() ?>

<main class="main-content">
