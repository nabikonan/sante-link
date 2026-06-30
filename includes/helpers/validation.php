<?php
/**
 * DiabSuivi — Helpers de validation
 *
 * Extrait de l'ancien includes/fonctions.php pour isoler la
 * responsabilité "valider une entrée utilisateur" du reste.
 * Aucune fonction ici ne touche à la base de données.
 */

/**
 * Sécuriser l'affichage d'une chaîne (échappement HTML).
 */
function esc(string $val): string
{
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

/**
 * Valide un datetime-local (format Y-m-d\TH:i ou Y-m-d H:i:s).
 */
function validerDatetime(string $str): ?DateTime
{
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $str);
    if ($dt) {
        return $dt;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $str);
    return $dt ?: null;
}

/**
 * Valide une date simple (Y-m-d), utilisée pour les dates de naissance,
 * débuts/fins de traitement, etc.
 */
function validerDate(string $str): ?DateTime
{
    $dt = DateTime::createFromFormat('Y-m-d', $str);
    return $dt ?: null;
}

/**
 * Valide une valeur glycémique (en g/L). Bornes cohérentes avec la
 * réalité clinique : en dessous de 0.10 ou au-dessus de 6.00, la valeur
 * est presque certainement une erreur de saisie.
 */
function validerValeurGlycemie(float $valeur): bool
{
    return $valeur >= 0.10 && $valeur <= 6.00;
}

/**
 * Valide un contexte de mesure contre la liste autorisée.
 */
function validerContexte(string $contexte): bool
{
    return in_array($contexte, [
        'A jeun', 'Post-repas', 'Avant sport', 'Apres sport', 'Autre',
    ], true);
}

/**
 * Valide un numéro de téléphone au format international simplifié.
 */
function validerTelephone(string $tel): bool
{
    return (bool) preg_match('/^\+?[\d\s\-]{8,15}$/', $tel);
}

/**
 * Valide la force d'un mot de passe (minimum 8 caractères).
 * Retourne un tableau d'erreurs vide si valide.
 *
 * @return string[]
 */
function validerMotDePasse(string $mdp): array
{
    $erreurs = [];
    if (strlen($mdp) < 8) {
        $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    }
    return $erreurs;
}
