<?php
/**
 * DiabSuivi — Fonctions utilitaires globales
 */

/**
 * Sécuriser l'affichage d'une chaîne
 */
function esc(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

/**
 * Valide un datetime-local (format Y-m-d\TH:i ou Y-m-d H:i:s)
 */
function validerDatetime(string $str): ?DateTime {
    // Accepte le format HTML datetime-local (2025-01-15T08:30)
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $str);
    if ($dt) return $dt;
    // Accepte aussi le format SQL complet
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $str);
    return $dt ?: null;
}

/**
 * Détermine le statut d'une mesure glycémique
 * @return array ['statut'=>string, 'couleur'=>string, 'message'=>string]
 */
function statutGlycemie(float $valeur, string $contexte): array {
    if ($valeur < 0.70) {
        return ['statut' => 'hypoglycemie', 'couleur' => 'danger',
                'message' => 'Hypoglycémie — Valeur critique !'];
    }
    if ($valeur > 2.00) {
        return ['statut' => 'critique', 'couleur' => 'danger',
                'message' => 'Hyperglycémie sévère — Consulter immédiatement'];
    }
    if ($contexte === 'A jeun' && $valeur > 1.10) {
        return ['statut' => 'eleve', 'couleur' => 'warning',
                'message' => 'Glycémie élevée à jeun (cible : 0.70–1.10 g/L)'];
    }
    if ($contexte === 'Post-repas' && $valeur > 1.40) {
        return ['statut' => 'eleve', 'couleur' => 'warning',
                'message' => 'Glycémie élevée post-repas (cible : < 1.40 g/L)'];
    }
    return ['statut' => 'normal', 'couleur' => 'success', 'message' => 'Valeur dans la cible'];
}

/**
 * Retourner les N dernières mesures d'un patient
 */
function getMesures(int $idPatient, int $limit = 30): array {
    $pdo  = getDB();
    $stmt = $pdo->prepare("
        SELECT id_mesure, valeur_glycemie, date_heure, contexte, commentaire
        FROM   mesure_glycemie
        WHERE  id_patient = :id
        ORDER  BY date_heure DESC
        LIMIT  :lim
    ");
    $stmt->bindValue(':id',  $idPatient, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit,     PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Compter les alertes non lues d'un patient
 */
function getNbAlertesNonLues(int $idPatient): int {
    $stmt = getDB()->prepare("
        SELECT COUNT(*) FROM alerte
        WHERE  id_patient = :id AND statut = 'Non lue'
    ");
    $stmt->execute([':id' => $idPatient]);
    return (int) $stmt->fetchColumn();
}

/**
 * Formater une date FR
 */
function dateFR(string $datetime): string {
    return (new DateTime($datetime))->format('d/m/Y à H\hi');
}

/**
 * Rediriger avec un message flash
 */
function redirect(string $url, string $msg = '', string $type = 'success'): void {
    if ($msg) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    }
    header("Location: $url");
    exit;
}

/**
 * Enregistre une action dans le log d'audit (traçabilité plateforme).
 */
function logAudit(
    int    $acteurId,
    string $acteurRole,
    string $action,
    ?string $cibleType = null,
    ?int   $cibleId = null,
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
 * Afficher et vider le message flash
 */
function flashMessage(): string {
    if (!isset($_SESSION['flash'])) return '';
    $f   = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return "<div class=\"alert alert-{$f['type']}\">" . esc($f['msg']) . "</div>";
}

/**
 * Créer automatiquement une alerte si la mesure dépasse les seuils
 * et déclencher les notifications Email/SMS.
 * (Appelé après chaque insertion de mesure_glycemie)
 */
function creerAlerteAutomatique(int $idPatient, int $idMesure, float $valeur, string $contexte): void {
    $pdo = getDB();

    $typeAlerte = null;
    $message    = null;

    if ($valeur < 0.70) {
        $typeAlerte = 'Hypoglycemie';
        $message    = "Hypoglycémie détectée : {$valeur} g/L. Prenez du sucre immédiatement.";
    } elseif ($valeur > 2.00) {
        $typeAlerte = 'Hyperglycemie severe';
        $message    = "Hyperglycémie sévère : {$valeur} g/L. Consultez votre médecin.";
    } elseif ($contexte === 'A jeun' && $valeur > 1.10) {
        $typeAlerte = 'Hyperglycemie';
        $message    = "Glycémie élevée à jeun : {$valeur} g/L (cible : < 1.10 g/L).";
    } elseif ($contexte === 'Post-repas' && $valeur > 1.40) {
        $typeAlerte = 'Hyperglycemie';
        $message    = "Glycémie élevée post-repas : {$valeur} g/L (cible : < 1.40 g/L).";
    }

    if ($typeAlerte) {
        // 1. Enregistrer l'alerte en BDD
        $pdo->prepare("
            INSERT INTO alerte (id_patient, id_mesure, type_alerte, message, statut, date_heure)
            VALUES (:ip, :im, :type, :msg, 'Non lue', NOW())
        ")->execute([
            ':ip'   => $idPatient,
            ':im'   => $idMesure,
            ':type' => $typeAlerte,
            ':msg'  => $message,
        ]);

        // 2. Déclencher Email + SMS (de façon asynchrone si possible)
        //    On charge le service seulement si disponible pour ne pas bloquer
        $notifPath = __DIR__ . '/../notifications/NotificationService.php';
        if (file_exists($notifPath)) {
            require_once $notifPath;
            // Envoi en arrière-plan pour ne pas ralentir la réponse HTTP
            // Sur Windows/XAMPP : exec() non bloquant
            // En production : utiliser une queue (Redis, RabbitMQ)
            try {
                NotificationService::notifierMesure(
                    $idPatient, $valeur, $contexte, $typeAlerte, $message
                );
            } catch (Throwable $e) {
                error_log('[DiabSuivi] Erreur notification : ' . $e->getMessage());
                // Ne pas bloquer la saisie si la notif échoue
            }
        }
    }
}
