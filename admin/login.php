<?php
/**
 * DiabSuivi — Connexion administrateur
 * Page séparée du login patient/médecin pour des raisons de sécurité
 * (pas de sélecteur de rôle visible publiquement).
 */
$pageTitle = 'Administration — SanteLink';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

if (estConnecte() && $_SESSION['user_role'] === 'admin') {
    header('Location: /admin/dashboard.php');
    exit;
}

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    $email = trim($_POST['email']    ?? '');
    $mdp   = trim($_POST['password'] ?? '');

    if (empty($email) || empty($mdp)) {
        $erreur = 'Veuillez remplir tous les champs.';
    } else {
        $stmt = getDB()->prepare(
            "SELECT id_admin AS id, nom, prenom, mot_de_passe, role_admin
             FROM   admin WHERE email = :email LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch();
         // Debugging line to check the fetched admin data
        if ($admin && password_verify($mdp, $admin['mot_de_passe'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = $admin['id'];
            $_SESSION['user_nom']   = $admin['prenom'] . ' ' . $admin['nom'];
            $_SESSION['user_role']  = 'admin';
            $_SESSION['admin_role'] = $admin['role_admin'];

            getDB()->prepare("UPDATE admin SET derniere_connexion = NOW() WHERE id_admin = :id")
                   ->execute([':id' => $admin['id']]);

            logAudit($admin['id'], 'admin', 'login', null, null, "Connexion réussie");

            header('Location: /admin/dashboard.php');
            exit;
        }
        $erreur = 'Email ou mot de passe incorrect.';
        // Log des tentatives échouées pour la sécurité
        if (!empty($email)) {
            error_log("[SanteLink][Admin] Tentative de connexion échouée : {$email} depuis " . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        }
    }
}
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap" style="background:linear-gradient(135deg,#1A1A2E,#16213E)">
    <div class="auth-card" style="border:1px solid #2A2A3E">
        <div class="auth-logo" style="color:#185FA5">🛡️</div>
        <h1>Administration</h1>
        <p class="auth-sub">Accès réservé au personnel autorisé</p>

        <?php if ($erreur): ?>
            <div class="alert alert-danger"><?= esc($erreur) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Email administrateur</label>
                <input type="email" name="email" required autocomplete="email" autofocus>
            </div>
            <div class="form-group">
                <label>Mot de passe</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-primary btn-full">Se connecter</button>
        </form>

        <p class="auth-link" style="margin-top:18px">
            <a href="/auth/login.php">← Retour à la connexion patient/médecin</a>
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
