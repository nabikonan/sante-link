<?php
/**
 * DiabSuivi — Liens de navigation pour le rôle médecin
 *
 * Extrait de l'ancien includes/header.php, séparé du fichier
 * patient.php pour permettre à deux personnes de modifier la nav
 * patient et la nav médecin sans se marcher dessus sur Git.
 * Variable attendue en entrée : $nbMessages.
 */
?>
<a href="/medecin/dashboard.php"    class="nav-link">🏠 Accueil</a>
<a href="/messagerie/boite.php"     class="nav-link">
    💬 Messages
    <?php if ($nbMessages > 0): ?>
        <span class="badge badge-danger badge-sm"><?= $nbMessages ?></span>
    <?php endif; ?>
</a>
<a href="/medecin/alertes.php"      class="nav-link">🔔 Alertes</a>
<a href="/medecin/ordonnances.php"  class="nav-link">📋 Ordonnances</a>
