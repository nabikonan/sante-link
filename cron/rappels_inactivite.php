<?php
/**
 * DiabSuivi — Script CRON : rappels d'inactivité
 *
 * Ce script est exécuté automatiquement toutes les heures par le planificateur.
 * Il envoie des notifications aux patients inactifs depuis >= 24h.
 *
 * ── Configuration sur Windows (XAMPP) ────────────────────────
 * Ouvrir le Planificateur de tâches Windows :
 *   - Nouvelle tâche → Déclencher : "Toutes les heures"
 *   - Action : php C:\xampp\htdocs\diabsuivi\project\cron\rappels_inactivite.php
 *
 * ── Configuration sur Linux/Mac ──────────────────────────────
 * Ajouter dans crontab (crontab -e) :
 *   0 * * * * php /var/www/html/diabsuivi/project/cron/rappels_inactivite.php >> /var/log/diabsuivi_cron.log 2>&1
 *
 * ── Test manuel ──────────────────────────────────────────────
 * php cron/rappels_inactivite.php
 */

// Sécurité : ce script ne doit s'exécuter qu'en CLI ou depuis localhost
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Accès refusé.');
    }
}

define('DIABSUIVI_ROOT', dirname(__DIR__));

// Charger .env si disponible
$envFile = DIABSUIVI_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

require_once DIABSUIVI_ROOT . '/db/connexion.php';
require_once DIABSUIVI_ROOT . '/includes/fonctions.php';
require_once DIABSUIVI_ROOT . '/notifications/NotificationService.php';

$debut = microtime(true);
$date  = date('Y-m-d H:i:s');

echo "[{$date}] SanteLink CRON — Rappels inactivité\n";
echo str_repeat('─', 50) . "\n";

try {
    $resultats = NotificationService::envoyerRappelsInactivite();

    echo "✅ Envoyés  : {$resultats['envoyes']}\n";
    echo "⏭️  Ignorés  : {$resultats['ignores']} (cooldown)\n";
    echo "❌ Erreurs  : {$resultats['erreurs']}\n";
} catch (Throwable $e) {
    echo "❌ Erreur fatale : " . $e->getMessage() . "\n";
    error_log('[SanteLink][CRON] Erreur : ' . $e->getMessage());
}

$duree = round(microtime(true) - $debut, 2);
echo str_repeat('─', 50) . "\n";
echo "Terminé en {$duree}s\n";
