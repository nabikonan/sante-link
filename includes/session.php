<?php
/**
 * DiabSuivi — Gestion des sessions (sécurisée)
 */
if (session_status() === PHP_SESSION_NONE) {
    // Renforcement des paramètres de session
    ini_set('session.cookie_httponly', '1');   // Inaccessible via JS
    ini_set('session.cookie_samesite', 'Lax'); // Protection CSRF basique
    // En production HTTPS, décommenter la ligne suivante :
    // ini_set('session.cookie_secure', '1');
    ini_set('session.use_strict_mode', '1');   // Rejette les IDs non initialisés
    session_start();
}

function estConnecte(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

function exigerConnexion(): void {
    if (!estConnecte()) {
        header('Location: /auth/login.php');
        exit;
    }
}

function exigerRole(string $role): void {
    exigerConnexion();
    // Whitelist des rôles valides
    $rolesValides = ['patient', 'medecin', 'admin'];
    if (!in_array($role, $rolesValides, true) || $_SESSION['user_role'] !== $role) {
        header('Location: /index.php?erreur=acces_refuse');
        exit;
    }
}

function deconnexion(): void {
    session_unset();
    session_destroy();
    header('Location: /auth/login.php');
    exit;
}

// ── Protection CSRF ───────────────────────────────────────────

/**
 * Génère (ou récupère) le token CSRF de la session.
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Retourne un champ <input> CSRF caché prêt à insérer dans un formulaire.
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . getCsrfToken() . '">';
}

/**
 * Vérifie le token CSRF soumis via POST.
 * Appeler en début de traitement de chaque formulaire POST.
 */
function verifierCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(getCsrfToken(), $token)) {
        http_response_code(403);
        die('Requête invalide (CSRF). Veuillez recharger la page et réessayer.');
    }
}
