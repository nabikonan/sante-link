<?php
/**
 * DiabSuivi — Helpers liés aux alertes glycémiques
 *
 * Logique métier autour du statut d'une mesure et de la création
 * automatique d'alertes. Dépend de db/connexion.php (getDB()) pour
 * creerAlerteAutomatique(), mais pas pour statutGlycemie() qui est
 * un calcul pur.
 */

/**
 * Détermine le statut clinique d'une mesure glycémique.
 * Fonction pure : aucun accès base de données, facilement testable.
 *
 * @return array{statut:string, couleur:string, message:string}
 */
function statutGlycemie(float $valeur, string $contexte): array
{
    if ($valeur < 0.70) {
        return [
            'statut'  => 'hypoglycemie',
            'couleur' => 'danger',
            'message' => 'Hypoglycémie — Valeur critique !',
        ];
    }
    if ($valeur > 2.00) {
        return [
            'statut'  => 'critique',
            'couleur' => 'danger',
            'message' => 'Hyperglycémie sévère — Consulter immédiatement',
        ];
    }
    if ($contexte === 'A jeun' && $valeur > 1.10) {
        return [
            'statut'  => 'eleve',
            'couleur' => 'warning',
            'message' => 'Glycémie élevée à jeun (cible : 0.70–1.10 g/L)',
        ];
    }
    if ($contexte === 'Post-repas' && $valeur > 1.40) {
        return [
            'statut'  => 'eleve',
            'couleur' => 'warning',
            'message' => 'Glycémie élevée post-repas (cible : < 1.40 g/L)',
        ];
    }
    return [
        'statut'  => 'normal',
        'couleur' => 'success',
        'message' => 'Valeur dans la cible',
    ];
}

/**
 * Détermine le type d'alerte et le message associé pour une mesure.
 * Retourne null si la mesure ne déclenche aucune alerte.
 * Fonction pure, séparée de l'écriture en base pour rester testable.
 *
 * @return array{type:string, message:string}|null
 */
function determinerAlerte(float $valeur, string $contexte): ?array
{
    if ($valeur < 0.70) {
        return [
            'type'    => 'Hypoglycemie',
            'message' => "Hypoglycémie détectée : {$valeur} g/L. Prenez du sucre immédiatement.",
        ];
    }
    if ($valeur > 2.00) {
        return [
            'type'    => 'Hyperglycemie severe',
            'message' => "Hyperglycémie sévère : {$valeur} g/L. Consultez votre médecin.",
        ];
    }
    if ($contexte === 'A jeun' && $valeur > 1.10) {
        return [
            'type'    => 'Hyperglycemie',
            'message' => "Glycémie élevée à jeun : {$valeur} g/L (cible : < 1.10 g/L).",
        ];
    }
    if ($contexte === 'Post-repas' && $valeur > 1.40) {
        return [
            'type'    => 'Hyperglycemie',
            'message' => "Glycémie élevée post-repas : {$valeur} g/L (cible : < 1.40 g/L).",
        ];
    }
    return null;
}

/**
 * Compter les alertes non lues d'un patient.
 * Nécessite getDB() (db/connexion.php) chargé en amont.
 */
function getNbAlertesNonLues(int $idPatient): int
{
    $stmt = getDB()->prepare(
        "SELECT COUNT(*) FROM alerte WHERE id_patient = :id AND statut = 'Non lue'"
    );
    $stmt->execute([':id' => $idPatient]);
    return (int) $stmt->fetchColumn();
}

/**
 * Crée automatiquement une alerte si la mesure dépasse les seuils,
 * et déclenche les notifications Email/SMS associées.
 * Appelée après chaque insertion de mesure_glycemie.
 *
 * Nécessite getDB() chargé en amont, et config/app.php pour vérifier
 * si les notifications sont activées avant de les déclencher.
 */
function creerAlerteAutomatique(int $idPatient, int $idMesure, float $valeur, string $contexte): void
{
    $alerte = determinerAlerte($valeur, $contexte);
    if ($alerte === null) {
        return;
    }

    $pdo = getDB();
    $pdo->prepare("
        INSERT INTO alerte (id_patient, id_mesure, type_alerte, message, statut, date_heure)
        VALUES (:ip, :im, :type, :msg, 'Non lue', NOW())
    ")->execute([
        ':ip'   => $idPatient,
        ':im'   => $idMesure,
        ':type' => $alerte['type'],
        ':msg'  => $alerte['message'],
    ]);

    // Notification Email/SMS, uniquement si la fonctionnalité est active
    // (vérifié via la config centralisée plutôt qu'en testant des
    // dépendances à la volée comme avant).
    if (class_exists('App') && App::featureEnabled('email')) {
        $notifPath = __DIR__ . '/../../notifications/NotificationService.php';
        if (file_exists($notifPath)) {
            require_once $notifPath;
            try {
                NotificationService::notifierMesure(
                    $idPatient, $valeur, $contexte, $alerte['type'], $alerte['message']
                );
            } catch (Throwable $e) {
                error_log('[DiabSuivi] Erreur notification : ' . $e->getMessage());
            }
        }
    }
}
