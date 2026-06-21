<?php
/**
 * DiabSuivi — Service SMS via Twilio
 *
 * Installation :
 *   composer require twilio/sdk
 *
 * Config dans .env :
 *   TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM
 *
 * Compte gratuit Twilio : https://www.twilio.com/try-twilio
 * (15$ de crédit offert, SMS vers numéros vérifiés)
 */

class SMS {

    /**
     * Envoyer un SMS via l'API REST Twilio (sans SDK — appel HTTP direct).
     * Fonctionne sans composer, avec juste curl.
     */
    private static function envoyer(string $to, string $message): bool {
        $sid   = $_ENV['TWILIO_SID']   ?? getenv('TWILIO_SID')   ?: '';
        $token = $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
        $from  = $_ENV['TWILIO_FROM']  ?? getenv('TWILIO_FROM')  ?: '';

        if (!$sid || !$token || !$from) {
            error_log('[DiabSuivi][SMS] Variables TWILIO_SID/TOKEN/FROM manquantes.');
            return false;
        }

        // Nettoyer le numéro (garder + et chiffres)
        $to = preg_replace('/[^\d+]/', '', $to);
        if (!$to) {
            error_log('[DiabSuivi][SMS] Numéro de téléphone invalide.');
            return false;
        }

        $url  = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $data = http_build_query([
            'From' => $from,
            'To'   => $to,
            'Body' => mb_substr($message, 0, 160), // Limite 1 SMS = 160 caractères
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$sid}:{$token}",
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("[DiabSuivi][SMS] cURL erreur : {$curlErr}");
            return false;
        }

        $json = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($json['sid'])) {
            error_log("[DiabSuivi][SMS] Envoyé à {$to} — SID : {$json['sid']}");
            return true;
        }

        $errMsg = $json['message'] ?? $response;
        error_log("[DiabSuivi][SMS] Échec HTTP {$httpCode} : {$errMsg}");
        return false;
    }

    /**
     * SMS d'alerte glycémique au patient.
     */
    public static function alerteGlycemie(
        string $telephone,
        string $nomPatient,
        float  $valeur,
        string $typeAlerte
    ): bool {
        $prenom = explode(' ', $nomPatient)[0];

        if ($valeur < 0.70) {
            $msg = "DiabSuivi | {$prenom}, URGENCE : Hypoglycémie {$valeur} g/L. "
                 . "Prenez 15g de sucre rapide immédiatement et vérifiez dans 15min.";
        } elseif ($valeur > 2.00) {
            $msg = "DiabSuivi | {$prenom}, ALERTE : Hyperglycémie sévère {$valeur} g/L. "
                 . "Contactez votre médecin dès que possible.";
        } else {
            $msg = "DiabSuivi | {$prenom}, alerte : glycémie hors cible {$valeur} g/L "
                 . "({$typeAlerte}). Connectez-vous pour plus de détails.";
        }

        return self::envoyer($telephone, $msg);
    }

    /**
     * SMS de rappel d'inactivité.
     */
    public static function rappelInactivite(
        string $telephone,
        string $nomPatient,
        int    $heures
    ): bool {
        $prenom = explode(' ', $nomPatient)[0];
        $msg = "DiabSuivi | {$prenom}, rappel : aucune mesure depuis {$heures}h. "
             . "Pensez à saisir votre glycémie sur DiabSuivi.";
        return self::envoyer($telephone, $msg);
    }

    /**
     * SMS d'alerte au médecin (cas critique uniquement).
     */
    public static function alerteMedecin(
        string $telephone,
        string $nomMedecin,
        string $nomPatient,
        float  $valeur,
        string $typeAlerte
    ): bool {
        $msg = "DiabSuivi | Dr {$nomMedecin} : votre patient {$nomPatient} "
             . "a une alerte {$typeAlerte} ({$valeur} g/L). Consultez DiabSuivi.";
        return self::envoyer($telephone, $msg);
    }
}
