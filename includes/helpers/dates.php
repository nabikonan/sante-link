<?php
/**
 * DiabSuivi — Helpers de formatage de dates
 *
 * Isolé séparément car utilisé absolument partout (dashboards,
 * historique, messagerie, audit...) et n'a aucune dépendance avec
 * la validation ou la base de données.
 */

/**
 * Formater une date au format français complet.
 * Exemple : "15/06/2026 à 08h30"
 */
function dateFR(string $datetime): string
{
    return (new DateTime($datetime))->format('d/m/Y à H\hi');
}

/**
 * Formater une date courte (jour/mois uniquement).
 * Utile pour les libellés de graphiques où l'espace est limité.
 */
function dateCourte(string $datetime): string
{
    return (new DateTime($datetime))->format('d/m');
}

/**
 * Affichage relatif simple : "Aujourd'hui", "Hier", ou la date.
 * Utilisé dans la messagerie pour les séparateurs de jour.
 */
function dateRelative(string $datetime): string
{
    $dt   = new DateTime($datetime);
    $today = new DateTime('today');
    $yesterday = (clone $today)->modify('-1 day');

    if ($dt->format('Y-m-d') === $today->format('Y-m-d')) {
        return "Aujourd'hui";
    }
    if ($dt->format('Y-m-d') === $yesterday->format('Y-m-d')) {
        return 'Hier';
    }
    return $dt->format('d/m/Y');
}
