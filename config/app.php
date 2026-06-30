<?php
/**
 * DiabSuivi — Configuration centralisée de l'application
 *
 * Ce fichier remplace les anciennes détections "à la volée" de
 * l'environnement (ex: tester exec() directement dans ia_risque.php).
 * Désormais, l'environnement est déclaré explicitement une seule fois,
 * et tout le reste du code consulte cette configuration au lieu de
 * deviner.
 *
 * Usage dans le reste du code :
 *   require_once __DIR__ . '/../config/app.php';
 *   if (App::featureEnabled('ia')) { ... }
 */

final class App
{
    /** @var array<string,mixed>|null */
    private static ?array $config = null;

    /**
     * Charge la configuration une seule fois (singleton léger).
     */
    private static function load(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        // L'environnement est déterminé par la variable APP_ENV du .env.
        // Valeurs possibles : 'local' (XAMPP) ou 'production' (hébergement distant).
        // Si absent, on suppose 'local' par sécurité (comportement le plus permissif
        // pour le développement, mais le plus restrictif serait 'production' —
        // ici on privilégie le confort de dev par défaut).
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
        $env = in_array($env, ['local', 'production'], true) ? $env : 'local';

        // Détection réelle de la disponibilité d'exec() — UNE SEULE FOIS,
        // ici, et nulle part ailleurs dans le code.
        $execAvailable = function_exists('exec')
            && !in_array(
                'exec',
                array_map('trim', explode(',', (string) ini_get('disable_functions'))),
                true
            );

        self::$config = [
            'env' => $env,

            // Fonctionnalités activables/désactivables explicitement.
            // En production sur un hébergement mutualisé (ex: Hostinger),
            // exec() est désactivé par l'hébergeur lui-même : ia/pdf
            // seront donc automatiquement à false, sans config manuelle.
            'features' => [
                'ia'              => $execAvailable,
                'export_pdf'      => $execAvailable,
                'sms'             => true,
                'email'           => true,
                '2fa'             => true,
                'messagerie'      => true,
                'rappels_intel'   => true,
            ],

            // Chemins utiles, calculés une fois.
            'paths' => [
                'root'    => dirname(__DIR__),
                'uploads' => dirname(__DIR__) . '/storage/uploads',
                'tmp'     => sys_get_temp_dir(),
            ],

            // URL de base de l'application (utile pour les liens dans les emails).
            'url' => $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost/diabsuivi/project',
        ];

        return self::$config;
    }

    /**
     * Retourne l'environnement courant : 'local' ou 'production'.
     */
    public static function env(): string
    {
        return self::load()['env'];
    }

    public static function isLocal(): bool
    {
        return self::env() === 'local';
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }

    /**
     * Vérifie si une fonctionnalité est activée.
     * Exemple : App::featureEnabled('ia')
     */
    public static function featureEnabled(string $feature): bool
    {
        return self::load()['features'][$feature] ?? false;
    }

    /**
     * Retourne un chemin configuré (root, uploads, tmp...).
     */
    public static function path(string $key): string
    {
        return self::load()['paths'][$key] ?? '';
    }

    public static function url(): string
    {
        return self::load()['url'];
    }

    /**
     * Accès générique pour debug ou cas particuliers.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::load()[$key] ?? $default;
    }
}
