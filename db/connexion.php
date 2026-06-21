<?php
/**
 * DiabSuivi — Connexion à la base de données
 * Les credentials sont lus depuis le fichier .env (chargé ci-dessous)
 * ou depuis les variables d'environnement système.
 * Ne jamais hardcoder des identifiants dans ce fichier.
 */

// ── Chargement automatique du fichier .env ────────────────────
$_envFile = dirname(__DIR__) . '/.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (str_starts_with(trim($_line), '#') || !str_contains($_line, '=')) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_ENV[trim($_k)] = trim($_v);
        putenv(trim($_k) . '=' . trim($_v));
    }
}
unset($_envFile, $_line, $_k, $_v);

define('DB_HOST',    $_ENV['DB_HOST']    ?? getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    $_ENV['DB_NAME']    ?? getenv('DB_NAME')    ?: 'diabsuivi');
define('DB_USER',    $_ENV['DB_USER']    ?? getenv('DB_USER')    ?: 'diabsuivi_user');
define('DB_PASS',    $_ENV['DB_PASS']    ?? getenv('DB_PASS')    ?: '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('Connexion BDD échouée : ' . $e->getMessage());
            http_response_code(500);
            die(json_encode(['error' => 'Service temporairement indisponible.']));
        }
    }
    return $pdo;
}
