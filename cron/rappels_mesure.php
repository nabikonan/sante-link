<?php
/**
 * DiabSuivi — CRON : Rappels de mesure intelligents
 *
 * Exécuté toutes les 15 minutes (idéalement).
 * Pour chaque patient actif :
 *   1. Vérifie si l'heure actuelle correspond à un créneau configuré
 *   2. Vérifie qu'aucune mesure n'a été saisie dans la fenêtre de tolérance
 *   3. Vérifie qu'aucun rappel n'a déjà été envoyé pour ce créneau aujourd'hui
 *   4. Envoie Email et/ou SMS selon les préférences
 *
 * ── Configuration CRON (toutes les 15 min) ───────────────────
 * Windows (Planificateur de tâches) :
 *   Répéter toutes les 15 minutes indéfiniment
 *   php C:\xampp\htdocs\diabsuivi\project\cron\rappels_mesure.php
 *
 * Linux/Mac :
 *   *\/15 * * * * php /var/www/html/diabsuivi/project/cron/rappels_mesure.php >> /var/log/diabsuivi_rappels.log 2>&1
 *
 * Test manuel :
 *   php cron/rappels_mesure.php
 *   php cron/rappels_mesure.php --dry-run   (simulation sans envoi)
 */

if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Accès refusé.');
    }
}

define('DIABSUIVI_ROOT', dirname(__DIR__));

// Charger .env
$envFile = DIABSUIVI_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

require_once DIABSUIVI_ROOT . '/db/connexion.php';
require_once DIABSUIVI_ROOT . '/includes/fonctions.php';
require_once DIABSUIVI_ROOT . '/notifications/Mailer.php';
require_once DIABSUIVI_ROOT . '/notifications/SMS.php';

$dryRun = in_array('--dry-run', $argv ?? []);
$now    = new DateTime();
$heure  = $now->format('H:i');
$jour   = (int) $now->format('N'); // 1=Lundi … 7=Dimanche
$date   = $now->format('Y-m-d');

$debut  = microtime(true);
echo "[{$now->format('Y-m-d H:i:s')}] DiabSuivi — Rappels mesure" . ($dryRun ? ' (DRY-RUN)' : '') . "\n";
echo str_repeat('─', 56) . "\n";

$pdo = getDB();

// Récupérer tous les patients avec rappels actifs
$stmt = $pdo->query("
    SELECT
        p.id_patient, p.prenom, p.nom, p.email, p.telephone,
        r.creneaux, r.jours_actifs, r.canal_email, r.canal_sms, r.tolerance_min
    FROM rappel_config r
    JOIN patient p ON p.id_patient = r.id_patient
    WHERE r.actif = 1
");
$patients = $stmt->fetchAll();

$stats = ['envoyes' => 0, 'skips' => 0, 'erreurs' => 0];

foreach ($patients as $pat) {
    $id           = (int) $pat['id_patient'];
    $nom          = $pat['prenom'] . ' ' . $pat['nom'];
    $creneaux     = json_decode($pat['creneaux'],    true) ?? [];
    $joursActifs  = json_decode($pat['jours_actifs'], true) ?? [1,2,3,4,5,6,7];
    $toleranceMin = (int) $pat['tolerance_min'];

    // Vérifier si aujourd'hui est un jour actif (JSON stocke 1-7, ISO 8601)
    if (!in_array($jour, $joursActifs, true)) {
        $stats['skips']++;
        continue;
    }

    // Vérifier si l'heure actuelle est proche d'un créneau (± tolerance/2)
    $creneauCible = null;
    foreach ($creneaux as $c) {
        $dtC      = DateTime::createFromFormat('H:i', $c);
        if (!$dtC) continue;
        $diffMin  = abs(($now->getTimestamp() - $dtC->setDate(
            (int)$now->format('Y'),
            (int)$now->format('m'),
            (int)$now->format('d')
        )->getTimestamp()) / 60);
        if ($diffMin <= 7) { // fenêtre de 7 minutes autour du créneau
            $creneauCible = $c;
            break;
        }
    }

    if (!$creneauCible) {
        // Pas de créneau correspondant à cette exécution
        continue;
    }

    // Vérifier si une mesure a déjà été saisie dans la fenêtre de tolérance
    $stmtMes = $pdo->prepare("
        SELECT COUNT(*) FROM mesure_glycemie
        WHERE  id_patient  = :id
          AND  date_heure >= DATE_SUB(NOW(), INTERVAL :tol MINUTE)
          AND  date_heure <= DATE_ADD(NOW(), INTERVAL :tol MINUTE)
    ");
    $stmtMes->bindValue(':id',  $id,           PDO::PARAM_INT);
    $stmtMes->bindValue(':tol', $toleranceMin, PDO::PARAM_INT);
    $stmtMes->execute();
    if ($stmtMes->fetchColumn() > 0) {
        echo "  ⏭️  {$nom} — mesure déjà saisie autour de {$creneauCible}\n";
        $stats['skips']++;
        continue;
    }

    // Vérifier qu'aucun rappel n'a déjà été envoyé pour ce créneau aujourd'hui
    $stmtLog = $pdo->prepare("
        SELECT COUNT(*) FROM rappel_log
        WHERE  id_patient = :id
          AND  creneau    = :cr
          AND  DATE(envoye_le) = :date
    ");
    $stmtLog->execute([':id' => $id, ':cr' => $creneauCible . ':00', ':date' => $date]);
    if ($stmtLog->fetchColumn() > 0) {
        echo "  ⏭️  {$nom} — rappel déjà envoyé pour {$creneauCible} aujourd'hui\n";
        $stats['skips']++;
        continue;
    }

    // ── Construire le message de rappel ──────────────────────
    // Personnaliser selon le contexte horaire
    $hInt = (int) $now->format('H');
    if ($hInt >= 5 && $hInt < 10)       $ctx = 'à jeun ce matin';
    elseif ($hInt >= 10 && $hInt < 14)  $ctx = 'après votre repas de midi';
    elseif ($hInt >= 14 && $hInt < 18)  $ctx = 'cet après-midi';
    elseif ($hInt >= 18 && $hInt < 22)  $ctx = 'après votre dîner';
    else                                 $ctx = 'ce soir';

    $sujetEmail  = "🩺 DiabSuivi — Rappel : mesurez votre glycémie {$ctx}";
    $corpsEmail  = "Il est {$heure}. Pensez à mesurer votre glycémie {$ctx} et à l'enregistrer dans DiabSuivi.";
    $corpsSms    = "DiabSuivi | {$pat['prenom']}, il est {$heure}. Pensez à mesurer votre glycémie {$ctx}.";

    if ($dryRun) {
        echo "  [DRY-RUN] {$nom} → Email: {$pat['email']} | Créneau: {$creneauCible}\n";
        $stats['envoyes']++;
        continue;
    }

    // ── Envoyer ───────────────────────────────────────────────
    $ok = false;

    if ($pat['canal_email'] && $pat['email']) {
        $sent = Mailer::envoyerRappelInactivite($pat['email'], $nom, 0);
        // Réutilise la méthode (0h = rappel créneau, pas inactivité)
        // On préfère appeler une version dédiée :
        $sent = self_envoyerRappelCreneau($pat['email'], $nom, $heure, $ctx);
        if ($sent) $ok = true;
    }

    if ($pat['canal_sms'] && $pat['telephone']) {
        $sent = SMS::rappelInactivite($pat['telephone'], $nom, 0);
        if ($sent) $ok = true;
    }

    // ── Logger ────────────────────────────────────────────────
    if ($ok) {
        $pdo->prepare("
            INSERT INTO rappel_log (id_patient, creneau, canal)
            VALUES (:id, :cr, :canal)
        ")->execute([
            ':id'    => $id,
            ':cr'    => $creneauCible . ':00',
            ':canal' => ($pat['canal_email'] ? 'email' : 'sms'),
        ]);
        echo "  ✅ {$nom} — rappel {$creneauCible} envoyé\n";
        $stats['envoyes']++;
    } else {
        echo "  ❌ {$nom} — échec d'envoi\n";
        $stats['erreurs']++;
    }
}

/**
 * Envoi email rappel créneau (template dédié).
 */
function self_envoyerRappelCreneau(
    string $email, string $nom, string $heure, string $ctx
): bool {
    try {
        $phpmailerPath = DIABSUIVI_ROOT . '/vendor/phpmailer/src/';
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            if (!is_dir($phpmailerPath)) return false;
            require_once $phpmailerPath . 'Exception.php';
            require_once $phpmailerPath . 'PHPMailer.php';
            require_once $phpmailerPath . 'SMTP.php';
        }
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USER'] ?? '';
        $mail->Password   = $_ENV['MAIL_PASS'] ?? '';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);
        $mail->CharSet    = 'UTF-8';
        $mail->isHTML(true);
        $mail->setFrom($_ENV['MAIL_FROM'] ?? 'noreply@diabsuivi.sn', 'SanteLink');
        $mail->addAddress($email, $nom);
        $mail->Subject = "🩺 SanteLink — Rappel {$heure} : mesurez votre glycémie";

        $prenom  = explode(' ', $nom)[0];
        $appUrl  = $_ENV['APP_URL'] ?? 'http://localhost/SanteLink/project';

        $mail->Body = "
        <div style='font-family:DM Sans,system-ui,sans-serif;max-width:480px;margin:0 auto;
                    background:#f5f8f7;padding:24px'>
            <div style='background:#fff;border-radius:12px;overflow:hidden;
                        box-shadow:0 2px 8px rgba(0,0,0,.08)'>
                <div style='background:#1D9E75;padding:20px;text-align:center'>
                    <div style='font-size:36px'>🩺</div>
                    <h1 style='color:#fff;font-size:17px;margin:8px 0 0'>
                        Rappel de mesure — {$heure}
                    </h1>
                </div>
                <div style='padding:24px'>
                    <p style='font-size:15px;color:#1A1A1A'>
                        Bonjour <strong>{$prenom}</strong> 👋
                    </p>
                    <p style='font-size:14px;color:#5A6272;line-height:1.6'>
                        Il est <strong>{$heure}</strong>. Pensez à mesurer votre glycémie
                        <strong>{$ctx}</strong> et à l'enregistrer dans DiabSuivi.
                    </p>
                    <div style='background:#E1F5EE;border-radius:8px;padding:12px;
                                font-size:12px;color:#0F6E56;margin:16px 0'>
                        💡 Un suivi régulier vous aide à mieux contrôler votre diabète
                        et permet à votre médecin de mieux adapter votre traitement.
                    </div>
                    <a href='{$appUrl}/patient/mesure_form.php'
                       style='display:inline-block;background:#1D9E75;color:#fff;
                              text-decoration:none;padding:12px 24px;border-radius:8px;
                              font-weight:600;font-size:14px'>
                        ➕ Saisir ma mesure →
                    </a>
                </div>
                <div style='padding:12px 24px;border-top:1px solid #E2E6EC;
                            font-size:11px;color:#9AA0AC'>
                    Pour modifier vos créneaux de rappel :
                    <a href='{$appUrl}/patient/rappels.php'>Gérer mes rappels</a>
                </div>
            </div>
        </div>";
        $mail->AltBody = "SanteLink — Il est {$heure}. Pensez à saisir votre glycémie {$ctx}.";
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('[SanteLink][CRON-Rappel] ' . $e->getMessage());
        return false;
    }
}

$duree = round(microtime(true) - $debut, 2);
echo str_repeat('─', 56) . "\n";
echo "✅ Envoyés : {$stats['envoyes']} | ⏭️  Skips : {$stats['skips']} | ❌ Erreurs : {$stats['erreurs']}\n";
echo "Terminé en {$duree}s\n";
