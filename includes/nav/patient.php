<?php
/**
 * DiabSuivi — Liens de navigation pour le rôle patient
 *
 * Extrait de l'ancien includes/header.php qui mélangeait les liens
 * patient ET médecin dans un seul bloc avec des conditions if/else.
 * Variables attendues en entrée : $nbAlertes, $nbMessages (définies
 * dans includes/header.php avant l'inclusion de ce fichier).
 */
?>
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
<a href="/patient/rapport.php"                    class="nav-link">📄 Rapport</a>
<a href="/patient/rappels.php"                    class="nav-link">⏰ Rappels</a>
<a href="/patient/preferences_notifications.php"  class="nav-link">⚙️ Notifs</a>
<a href="/patient/securite.php"                   class="nav-link">🔒 Sécurité</a>
