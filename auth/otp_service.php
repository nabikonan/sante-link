<?php
/**
 * DiabSuivi — Service OTP (One-Time Password) pour le 2FA
 *
 * Flux :
 *   1. L'utilisateur saisit email + mot de passe → login.php vérifie les credentials
 *   2. Si 2FA activé → génère un code à 6 chiffres, l'envoie par email, redirige vers otp_verify.php
 *   3. L'utilisateur saisit le code → OtpService::verifier() valide
 *   4. Si valide → session créée normalement
 *
 * Sécurité :
 *   - Code à 6 chiffres aléatoire cryptographiquement sûr
 *   - Expiration : 10 minutes
 *   - Max 3 tentatives (puis code invalidé)
 *   - Un seul code valide à la fois par utilisateur
 *   - Délai minimum de 60s entre deux demandes (anti-spam)
 */

require_once __DIR__ . '/../db/connexion.php';

class OtpService {

    const EXPIRATION_MINUTES = 10;
    const MAX_TENTATIVES     = 3;
    const COOLDOWN_SECONDES  = 60;

    /**
     * Génère un code OTP, l'enregistre en BDD et l'envoie par email.
     * Retourne true si envoyé, false si cooldown actif ou erreur.
     */
    public static function genererEtEnvoyer(
        int    $userId,
        string $role,
        string $email,
        string $nomComplet
    ): array {
        $pdo = getDB();

        // Vérifier cooldown (évite le spam de codes)
        $stmtCooldown = $pdo->prepare("
            SELECT MAX(cree_le) FROM otp_code
            WHERE  user_id = :id AND user_role = :role AND used = 0
        ");
        $stmtCooldown->execute([':id' => $userId, ':role' => $role]);
        $dernierEnvoi = $stmtCooldown->fetchColumn();

        if ($dernierEnvoi) {
            $secondes = (time() - strtotime($dernierEnvoi));
            if ($secondes < self::COOLDOWN_SECONDES) {
                return [
                    'ok'      => false,
                    'msg'     => 'Attendez ' . (self::COOLDOWN_SECONDES - $secondes) . ' secondes avant de redemander un code.',
                    'cooldown' => self::COOLDOWN_SECONDES - $secondes,
                ];
            }
        }

        // Invalider les anciens codes non utilisés
        $pdo->prepare("
            UPDATE otp_code SET used = 1
            WHERE  user_id = :id AND user_role = :role AND used = 0
        ")->execute([':id' => $userId, ':role' => $role]);

        // Générer un code à 6 chiffres sécurisé
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Insérer en BDD
        $expiration = date('Y-m-d H:i:s', time() + self::EXPIRATION_MINUTES * 60);
        $pdo->prepare("
            INSERT INTO otp_code (user_id, user_role, code, expires_at)
            VALUES (:id, :role, :code, :exp)
        ")->execute([
            ':id'   => $userId,
            ':role' => $role,
            ':code' => $code,
            ':exp'  => $expiration,
        ]);

        // Envoyer par email
        $emailOk = self::envoyerCodeParEmail($email, $nomComplet, $code);

        if (!$emailOk) {
            // En dev sans SMTP, logger le code dans error_log
            error_log("[DiabSuivi][2FA] CODE OTP pour {$email} : {$code} (expire {$expiration})");
        }

        return [
            'ok'  => true,
            'msg' => "Code envoyé à {$email}. Valable " . self::EXPIRATION_MINUTES . " minutes.",
            'dev_code' => (getenv('APP_ENV') === 'dev') ? $code : null, // Visible en dev uniquement
        ];
    }

    /**
     * Vérifie le code OTP soumis par l'utilisateur.
     */
    public static function verifier(int $userId, string $role, string $codeSaisi): array {
        $pdo = getDB();

        // Récupérer le code valide le plus récent
        $stmt = $pdo->prepare("
            SELECT id_otp, code, expires_at, tentatives
            FROM   otp_code
            WHERE  user_id = :id AND user_role = :role
              AND  used = 0
              AND  expires_at > NOW()
            ORDER  BY cree_le DESC
            LIMIT  1
        ");
        $stmt->execute([':id' => $userId, ':role' => $role]);
        $otp = $stmt->fetch();

        if (!$otp) {
            return ['ok' => false, 'msg' => 'Code expiré ou introuvable. Demandez un nouveau code.'];
        }

        // Vérifier les tentatives
        if ((int)$otp['tentatives'] >= self::MAX_TENTATIVES) {
            $pdo->prepare("UPDATE otp_code SET used = 1 WHERE id_otp = :id")
                ->execute([':id' => $otp['id_otp']]);
            return ['ok' => false, 'msg' => 'Trop de tentatives. Demandez un nouveau code.', 'bloque' => true];
        }

        // Incrémenter tentatives
        $pdo->prepare("UPDATE otp_code SET tentatives = tentatives + 1 WHERE id_otp = :id")
            ->execute([':id' => $otp['id_otp']]);

        // Comparer le code (hash_equals pour éviter les timing attacks)
        if (!hash_equals($otp['code'], trim($codeSaisi))) {
            $restantes = self::MAX_TENTATIVES - ((int)$otp['tentatives'] + 1);
            return [
                'ok'  => false,
                'msg' => "Code incorrect. {$restantes} tentative" . ($restantes > 1 ? 's' : '') . " restante" . ($restantes > 1 ? 's' : '') . ".",
            ];
        }

        // Code correct → invalider
        $pdo->prepare("UPDATE otp_code SET used = 1 WHERE id_otp = :id")
            ->execute([':id' => $otp['id_otp']]);

        return ['ok' => true, 'msg' => 'Authentification réussie.'];
    }

    /**
     * Envoyer le code OTP par email.
     */
    private static function envoyerCodeParEmail(
        string $email,
        string $nom,
        string $code
    ): bool {
        // Charger Mailer si disponible
        $mailerPath = __DIR__ . '/../notifications/Mailer.php';
        if (!file_exists($mailerPath)) return false;

        try {
            require_once $mailerPath;

            // Utiliser PHPMailer directement
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                $phpmailerPath = __DIR__ . '/../vendor/phpmailer/src/';
                if (!is_dir($phpmailerPath)) return false;
                require_once $phpmailerPath . 'Exception.php';
                require_once $phpmailerPath . 'PHPMailer.php';
                require_once $phpmailerPath . 'SMTP.php';
            }

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $_ENV['MAIL_HOST']  ?? getenv('MAIL_HOST')  ?: 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['MAIL_USER']  ?? getenv('MAIL_USER')  ?: '';
            $mail->Password   = $_ENV['MAIL_PASS']  ?? getenv('MAIL_PASS')  ?: '';
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?: 587);
            $mail->CharSet    = 'UTF-8';
            $mail->isHTML(true);

            $from = $_ENV['MAIL_FROM'] ?? getenv('MAIL_FROM') ?: 'noreply@diabsuivi.sn';
            $mail->setFrom($from, 'DiabSuivi');
            $mail->addAddress($email, $nom);
            $mail->Subject = '🔐 DiabSuivi — Votre code de connexion : ' . $code;
            $mail->Body    = self::templateOtp($nom, $code);
            $mail->AltBody = "DiabSuivi — Votre code de vérification : {$code}\nValable " . self::EXPIRATION_MINUTES . " minutes.\nNe le partagez jamais.";
            $mail->send();
            return true;

        } catch (\Throwable $e) {
            error_log('[DiabSuivi][2FA] Erreur envoi OTP : ' . $e->getMessage());
            return false;
        }
    }

    private static function templateOtp(string $nom, string $code): string {
        // Séparer les chiffres pour l'affichage
        $digits = implode('</span><span style="margin:0 4px;font-size:32px;font-weight:800;
                           color:#185FA5;background:#E6F1FB;padding:8px 12px;
                           border-radius:8px;display:inline-block">',
            str_split($code)
        );

        return "
        <div style='font-family:DM Sans,system-ui,sans-serif;max-width:480px;margin:0 auto;
                    background:#f5f8f7;padding:24px'>
            <div style='background:#fff;border-radius:12px;overflow:hidden;
                        box-shadow:0 2px 8px rgba(0,0,0,.08)'>
                <div style='background:#185FA5;padding:24px;text-align:center'>
                    <div style='font-size:40px'>🔐</div>
                    <h1 style='color:#fff;font-size:18px;margin:8px 0 0'>
                        Code de vérification DiabSuivi
                    </h1>
                </div>
                <div style='padding:32px;text-align:center'>
                    <p style='color:#5A6272;font-size:14px;margin-bottom:24px'>
                        Bonjour <strong>{$nom}</strong>,<br>
                        Voici votre code de connexion à usage unique :
                    </p>
                    <div style='margin:0 auto 24px;letter-spacing:4px'>
                        <span style='margin:0 4px;font-size:32px;font-weight:800;
                              color:#185FA5;background:#E6F1FB;padding:8px 12px;
                              border-radius:8px;display:inline-block'>{$digits}</span>
                    </div>
                    <div style='background:#FAEEDA;border-radius:8px;padding:12px;
                                font-size:12px;color:#633806;margin-bottom:20px'>
                        ⏱️ Ce code expire dans <strong>" . self::EXPIRATION_MINUTES . " minutes</strong>.
                        Ne le partagez avec personne.
                    </div>
                    <p style='color:#9AA0AC;font-size:11px'>
                        Si vous n'avez pas demandé ce code, ignorez cet email.
                        Votre compte reste sécurisé.
                    </p>
                </div>
                <div style='padding:14px 28px;border-top:1px solid #E2E6EC;
                            font-size:11px;color:#9AA0AC;text-align:center'>
                    DiabSuivi — Ne répondez pas à cet email automatique.
                </div>
            </div>
        </div>";
    }
}
