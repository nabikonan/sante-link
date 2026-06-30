<?php
/**
 * DiabSuivi — Point d'entrée des fonctions utilitaires
 *
 * Ce fichier ne contient plus de logique lui-même. Il agrège les
 * helpers spécialisés du dossier includes/helpers/, chacun avec une
 * seule responsabilité :
 *
 *   - helpers/validation.php  → valider les entrées utilisateur
 *   - helpers/dates.php       → formater les dates
 *   - helpers/alertes.php     → statuts glycémiques et alertes auto
 *   - helpers/audit.php       → traçabilité et navigation (redirect/flash)
 *
 * Pourquoi garder ce fichier plutôt que de tout inclure séparément
 * partout : tout le code existant fait déjà
 *   require_once __DIR__ . '/../includes/fonctions.php';
 * Ce point d'entrée unique évite de devoir modifier chaque fichier
 * du projet ; seul ce fichier sait comment les pièces s'assemblent.
 *
 * Avantage côté travail en équipe : si Alice modifie la validation et
 * Bob modifie les alertes, ils travaillent sur deux fichiers différents
 * (helpers/validation.php vs helpers/alertes.php) — donc plus aucun
 * conflit Git sur un fichier fonctions.php commun.
 */

require_once __DIR__ . '/helpers/validation.php';
require_once __DIR__ . '/helpers/dates.php';
require_once __DIR__ . '/helpers/alertes.php';
require_once __DIR__ . '/helpers/audit.php';
