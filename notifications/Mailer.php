<?php
/**
 * DiabSuivi — Service Email via PHPMailer
 *
 * Depuis la Phase 1, PHPMailer est une vraie dépendance Composer
 * (voir composer.json). Installation :
 *   composer install
 *
 * Le fallback manuel (vendor/phpmailer/src/ copié à la main) est
 * conservé uniquement pour les machines où Composer n'a pas encore
 * été installé, mais composer install est désormais la voie
 * recommandée et documentée.
 *
 * Config dans .env :
 *   MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS, MAIL_FROM, MAIL_FROM_NAME
 */

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    // Fallback : ancienne méthode d'installation manuelle.
    $phpmailerPath = __DIR__ . '/../vendor/phpmailer/src/';
    if (is_dir($phpmailerPath)) {
        require_once $phpmailerPath . 'Exception.php';
        require_once $phpmailerPath . 'PHPMailer.php';
        require_once $phpmailerPath . 'SMTP.php';
    } else {
        error_log(
            '[DiabSuivi][Mailer] PHPMailer introuvable. '
            . 'Lancez "composer install" à la racine du projet.'
        );
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private static function buildMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST']      ?? getenv('MAIL_HOST')      ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USER']      ?? getenv('MAIL_USER')      ?: '';
        $mail->Password   = $_ENV['MAIL_PASS']      ?? getenv('MAIL_PASS')      ?: '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?: 587);
        $mail->CharSet    = 'UTF-8';
        $mail->isHTML(true);

        $fromEmail = $_ENV['MAIL_FROM']      ?? getenv('MAIL_FROM')      ?: 'noreply@diabsuivi.sn';
        $fromName  = $_ENV['MAIL_FROM_NAME'] ?? getenv('MAIL_FROM_NAME') ?: 'DiabSuivi';
        $mail->setFrom($fromEmail, $fromName);

        return $mail;
    }

    public static function envoyerAlerteGlycemie(
        string $destinataire,
        string $nomPatient,
        float $valeur,
        string $contexte,
        string $typeAlerte,
        string $message
    ): bool {
        try {
            $mail = self::buildMailer();
            $mail->addAddress($destinataire, $nomPatient);
            $mail->Subject = '⚠️ DiabSuivi — Alerte glycémique : ' . $typeAlerte;
            $mail->Body    = self::templateAlerteGlycemie($nomPatient, $valeur, $contexte, $typeAlerte, $message);
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $mail->Body));
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('[DiabSuivi][Mailer] Erreur envoi alerte glycémie : ' . $e->getMessage());
            return false;
        }
    }

    public static function envoyerRappelInactivite(
        string $destinataire,
        string $nomPatient,
        int $heuresInactivite
    ): bool {
        try {
            $mail = self::buildMailer();
            $mail->addAddress($destinataire, $nomPatient);
            $mail->Subject = '🔔 DiabSuivi — Rappel : pensez à saisir votre glycémie';
            $mail->Body    = self::templateRappelInactivite($nomPatient, $heuresInactivite);
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $mail->Body));
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('[DiabSuivi][Mailer] Erreur envoi rappel inactivité : ' . $e->getMessage());
            return false;
        }
    }

    public static function envoyerAlerteMedecin(
        string $emailMedecin,
        string $nomMedecin,
        string $nomPatient,
        float $valeur,
        string $contexte,
        string $typeAlerte
    ): bool {
        try {
            $mail = self::buildMailer();
            $mail->addAddress($emailMedecin, 'Dr ' . $nomMedecin);
            $mail->Subject = '🚨 DiabSuivi — Patient en alerte : ' . $nomPatient;
            $mail->Body    = self::templateAlerteMedecin($nomMedecin, $nomPatient, $valeur, $contexte, $typeAlerte);
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $mail->Body));
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('[DiabSuivi][Mailer] Erreur envoi alerte médecin : ' . $e->getMessage());
            return false;
        }
    }

    // ── Templates HTML (inchangés depuis la version précédente) ────

    private static function templateAlerteGlycemie(
        string $nom, float $valeur, string $contexte, string $type, string $message
    ): string {
        $couleur = ($valeur < 0.70 || $valeur > 2.00) ? '#E24B4A' : '#EF9F27';
        $icone   = ($valeur < 0.70 || $valeur > 2.00) ? '🚨' : '⚠️';
        $url     = class_exists('App') ? App::url() : ($_ENV['APP_URL'] ?? 'http://localhost/diabsuivi/project');

        return "
        <div style='font-family:DM Sans,system-ui,sans-serif;max-width:520px;margin:0 auto;background:#f5f8f7;padding:24px'>
            <div style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
                <div style='background:{$couleur};padding:24px;text-align:center'>
                    <div style='font-size:40px'>{$icone}</div>
                    <h1 style='color:#fff;font-size:18px;margin:8px 0 0'>Alerte glycémique</h1>
                </div>
                <div style='padding:28px'>
                    <p style='color:#1A1A1A;font-size:15px'>Bonjour <strong>{$nom}</strong>,</p>
                    <p style='color:#5A6272;font-size:14px;line-height:1.6'>{$message}</p>
                    <div style='background:#f5f8f7;border:1px solid #E2E6EC;border-radius:10px;padding:16px;margin:20px 0;text-align:center'>
                        <div style='font-size:36px;font-weight:800;color:{$couleur}'>{$valeur} g/L</div>
                        <div style='font-size:13px;color:#5A6272;margin-top:4px'>{$type} · {$contexte}</div>
                    </div>
                    <a href='{$url}/patient/dashboard.php'
                       style='display:inline-block;background:#1D9E75;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px'>
                        Voir mon tableau de bord →
                    </a>
                </div>
                <div style='padding:16px 28px;border-top:1px solid #E2E6EC;font-size:11px;color:#9AA0AC'>
                    DiabSuivi — Application académique de suivi glycémique. Ce message ne remplace pas un avis médical.
                </div>
            </div>
        </div>";
    }

    private static function templateRappelInactivite(string $nom, int $heures): string
    {
        $url = class_exists('App') ? App::url() : ($_ENV['APP_URL'] ?? 'http://localhost/diabsuivi/project');
        return "
        <div style='font-family:DM Sans,system-ui,sans-serif;max-width:520px;margin:0 auto;background:#f5f8f7;padding:24px'>
            <div style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
                <div style='background:#185FA5;padding:24px;text-align:center'>
                    <div style='font-size:40px'>🔔</div>
                    <h1 style='color:#fff;font-size:18px;margin:8px 0 0'>Rappel de mesure</h1>
                </div>
                <div style='padding:28px'>
                    <p style='color:#1A1A1A;font-size:15px'>Bonjour <strong>{$nom}</strong>,</p>
                    <p style='color:#5A6272;font-size:14px;line-height:1.6'>
                        Vous n'avez pas saisi de mesure glycémique depuis <strong>{$heures} heures</strong>.<br>
                        Un suivi régulier est essentiel pour votre santé.
                    </p>
                    <a href='{$url}/patient/mesure_form.php'
                       style='display:inline-block;background:#185FA5;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px'>
                        ➕ Saisir ma mesure maintenant →
                    </a>
                </div>
                <div style='padding:16px 28px;border-top:1px solid #E2E6EC;font-size:11px;color:#9AA0AC'>
                    DiabSuivi — Pour ne plus recevoir ces rappels, modifiez vos préférences.
                </div>
            </div>
        </div>";
    }

    private static function templateAlerteMedecin(
        string $nomMed, string $nomPat, float $valeur, string $contexte, string $type
    ): string {
        $url     = class_exists('App') ? App::url() : ($_ENV['APP_URL'] ?? 'http://localhost/diabsuivi/project');
        $couleur = ($valeur < 0.70 || $valeur > 2.00) ? '#E24B4A' : '#EF9F27';
        return "
        <div style='font-family:DM Sans,system-ui,sans-serif;max-width:520px;margin:0 auto;background:#f5f8f7;padding:24px'>
            <div style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
                <div style='background:{$couleur};padding:24px;text-align:center'>
                    <div style='font-size:40px'>🚨</div>
                    <h1 style='color:#fff;font-size:18px;margin:8px 0 0'>Alerte patient — Action requise</h1>
                </div>
                <div style='padding:28px'>
                    <p style='color:#1A1A1A;font-size:15px'>Bonjour <strong>Dr {$nomMed}</strong>,</p>
                    <p style='color:#5A6272;font-size:14px;line-height:1.6'>
                        Votre patient <strong>{$nomPat}</strong> a enregistré une mesure glycémique
                        anormale nécessitant votre attention.
                    </p>
                    <div style='background:#f5f8f7;border:1px solid #E2E6EC;border-radius:10px;padding:16px;margin:20px 0;text-align:center'>
                        <div style='font-size:36px;font-weight:800;color:{$couleur}'>{$valeur} g/L</div>
                        <div style='font-size:13px;color:#5A6272;margin-top:4px'>{$type} · {$contexte}</div>
                    </div>
                    <a href='{$url}/medecin/alertes.php'
                       style='display:inline-block;background:{$couleur};color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px'>
                        Voir toutes les alertes →
                    </a>
                </div>
                <div style='padding:16px 28px;border-top:1px solid #E2E6EC;font-size:11px;color:#9AA0AC'>
                    DiabSuivi — Notification automatique du système de suivi.
                </div>
            </div>
        </div>";
    }
}
