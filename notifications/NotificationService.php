<?php
/**
 * DiabSuivi — Service central de notifications
 *
 * Orchestre Email (Mailer) + SMS selon les préférences patient
 * et les règles métier (évite les doublons, cooldown, etc.)
 */

require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/SMS.php';
require_once __DIR__ . '/../db/connexion.php';

class NotificationService {

    // Délai minimum entre deux notifications du même type pour le même patient
    // (évite le spam si plusieurs mesures hors cible consécutives)
    const COOLDOWN_MINUTES = 60;

    /**
     * Déclencher les notifications suite à une nouvelle mesure glycémique.
     * Appelé automatiquement dans fonctions.php après chaque insert.
     */
    public static function notifierMesure(
        int    $idPatient,
        float  $valeur,
        string $contexte,
        string $typeAlerte,
        string $messageAlerte
    ): void {
        $pdo = getDB();

        // Récupérer infos patient
        $stmt = $pdo->prepare("
            SELECT nom, prenom, email, telephone,
                   notif_email, notif_sms
            FROM   patient WHERE id_patient = :id
        ");
        $stmt->execute([':id' => $idPatient]);
        $patient = $stmt->fetch();
        if (!$patient) return;

        $nomComplet = $patient['prenom'] . ' ' . $patient['nom'];

        // Vérifier cooldown (pas de notif si déjà envoyé il y a moins de 60min)
        if (self::enCooldown($idPatient, 'alerte_glycemie')) return;

        // ── Notifier le patient ───────────────────────────────
        if ($patient['notif_email'] ?? true) {
            Mailer::envoyerAlerteGlycemie(
                $patient['email'],
                $nomComplet,
                $valeur,
                $contexte,
                $typeAlerte,
                $messageAlerte
            );
        }

        if (($patient['notif_sms'] ?? false) && $patient['telephone']) {
            SMS::alerteGlycemie(
                $patient['telephone'],
                $nomComplet,
                $valeur,
                $typeAlerte
            );
        }

        // ── Notifier le médecin si critique (hypo ou hyper sévère) ─
        if ($valeur < 0.70 || $valeur > 2.00) {
            $stmtMed = $pdo->prepare("
                SELECT m.email, m.nom, m.prenom, m.telephone
                FROM   medecin m
                JOIN   suivi s ON s.id_medecin = m.id_medecin
                WHERE  s.id_patient = :id AND s.actif = 1
                LIMIT  1
            ");
            $stmtMed->execute([':id' => $idPatient]);
            $medecin = $stmtMed->fetch();

            if ($medecin) {
                $nomMed = $medecin['prenom'] . ' ' . $medecin['nom'];

                Mailer::envoyerAlerteMedecin(
                    $medecin['email'],
                    $nomMed,
                    $nomComplet,
                    $valeur,
                    $contexte,
                    $typeAlerte
                );

                if ($medecin['telephone']) {
                    SMS::alerteMedecin(
                        $medecin['telephone'],
                        $nomMed,
                        $nomComplet,
                        $valeur,
                        $typeAlerte
                    );
                }
            }
        }

        // Enregistrer l'envoi pour le cooldown
        self::enregistrerEnvoi($idPatient, 'alerte_glycemie');
    }

    /**
     * Envoyer les rappels d'inactivité.
     * Appelé par le CRON toutes les heures.
     * Notifie les patients sans mesure depuis 24h.
     */
    public static function envoyerRappelsInactivite(): array {
        $pdo = getDB();

        // Patients sans mesure depuis >= 24h, avec notif activée
        $stmt = $pdo->prepare("
            SELECT p.id_patient, p.nom, p.prenom, p.email, p.telephone,
                   p.notif_email, p.notif_sms,
                   MAX(m.date_heure) AS derniere_mesure,
                   TIMESTAMPDIFF(HOUR, MAX(m.date_heure), NOW()) AS heures_inactif
            FROM   patient p
            LEFT   JOIN mesure_glycemie m ON m.id_patient = p.id_patient
            GROUP  BY p.id_patient
            HAVING derniere_mesure IS NULL
                OR heures_inactif >= 24
        ");
        $stmt->execute();
        $inactifs = $stmt->fetchAll();

        $resultats = ['envoyes' => 0, 'ignores' => 0, 'erreurs' => 0];

        foreach ($inactifs as $p) {
            // Cooldown : pas de rappel si déjà envoyé il y a moins de 24h
            if (self::enCooldown($p['id_patient'], 'rappel_inactivite', 1440)) {
                $resultats['ignores']++;
                continue;
            }

            $nomComplet = $p['prenom'] . ' ' . $p['nom'];
            $heures     = (int)($p['heures_inactif'] ?? 25);
            $ok         = false;

            if ($p['notif_email'] ?? true) {
                $ok = Mailer::envoyerRappelInactivite($p['email'], $nomComplet, $heures);
            }

            if (($p['notif_sms'] ?? false) && $p['telephone']) {
                SMS::rappelInactivite($p['telephone'], $nomComplet, $heures);
                $ok = true;
            }

            if ($ok) {
                self::enregistrerEnvoi($p['id_patient'], 'rappel_inactivite');
                $resultats['envoyes']++;
            } else {
                $resultats['erreurs']++;
            }
        }

        return $resultats;
    }

    // ── Gestion du cooldown ────────────────────────────────────

    private static function enCooldown(
        int    $idPatient,
        string $typeNotif,
        int    $minutesCooldown = self::COOLDOWN_MINUTES
    ): bool {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notification_log
            WHERE  id_patient  = :id
              AND  type_notif  = :type
              AND  envoye_le   >= DATE_SUB(NOW(), INTERVAL :min MINUTE)
        ");
        $stmt->bindValue(':id',   $idPatient,      PDO::PARAM_INT);
        $stmt->bindValue(':type', $typeNotif,      PDO::PARAM_STR);
        $stmt->bindValue(':min',  $minutesCooldown, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function enregistrerEnvoi(int $idPatient, string $typeNotif): void {
        try {
            getDB()->prepare("
                INSERT INTO notification_log (id_patient, type_notif, envoye_le)
                VALUES (:id, :type, NOW())
            ")->execute([':id' => $idPatient, ':type' => $typeNotif]);
        } catch (PDOException $e) {
            error_log('[DiabSuivi][NotificationService] Log impossible : ' . $e->getMessage());
        }
    }
}
