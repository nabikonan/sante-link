<?php
/**
 * DiabSuivi — Helpers d'audit et de navigation
 *
 * Regroupe deux responsabilités proches : tracer les actions sensibles
 * (audit_log) et gérer les redirections avec message flash. Séparé
 * des helpers de validation et d'alertes car ce sont des préoccupations
 * différentes (traçabilité système vs règles métier).
 */

/**
 * Enregistre une action dans le log d'audit (traçabilité plateforme).
 * Nécessite getDB() chargé en amont.
 */
function logAudit(
    int $acteurId,
    string $acteurRole,
    string $action,
    ?string $cibleType = null,
    ?int $cibleId = null,
    ?string $details = null
): void {
    try {
        getDB()->prepare("
            INSERT INTO audit_log
                (acteur_id, acteur_role, action, cible_type, cible_id, details, ip_adresse)
            VALUES (:aid, :arole, :act, :ctype, :cid, :det, :ip)
        ")->execute([
            ':aid'   => $acteurId,
            ':arole' => $acteurRole,
            ':act'   => $action,
            ':ctype' => $cibleType,
            ':cid'   => $cibleId,
            ':det'   => $details,
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) {
        error_log('[DiabSuivi] Erreur log audit : ' . $e->getMessage());
    }
}

/**
 * Rediriger avec un message flash optionnel.
 */
function redirect(string $url, string $msg = '', string $type = 'success'): void
{
    if ($msg) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    }
    header("Location: $url");
    exit;
}

/**
 * Afficher et vider le message flash.
 */
function flashMessage(): string
{
    if (!isset($_SESSION['flash'])) {
        return '';
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert alert-' . $f['type'] . '">' . esc($f['msg']) . '</div>';
}
