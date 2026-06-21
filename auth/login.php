<?php
$pageTitle = 'Connexion — SanteLink';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

if (estConnecte()) {
    header('Location: /' . $_SESSION['user_role'] . '/dashboard.php');
    exit;
}

$erreur = '';
// Rôles autorisés (whitelist stricte)
$rolesValides = ['patient', 'medecin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    $email = trim($_POST['email']    ?? '');
    $mdp   = trim($_POST['password'] ?? '');
    $role  = $_POST['role']          ?? 'patient';

    // Validation du rôle par whitelist
    if (!in_array($role, $rolesValides, true)) {
        $role = 'patient';
    }

    if (empty($email) || empty($mdp)) {
        $erreur = 'Veuillez remplir tous les champs.';
    } else {
        // Noms de table/colonne fixes selon rôle (whitelist, pas d'injection possible)
        $table = ($role === 'medecin') ? 'medecin' : 'patient';
        $idCol = ($role === 'medecin') ? 'id_medecin' : 'id_patient';

        $stmt = getDB()->prepare(
            "SELECT $idCol AS id, nom, prenom, mot_de_passe, email, deux_fa_actif
             FROM   $table
             WHERE  email = :email
             LIMIT  1"
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($mdp, $user['mot_de_passe'])) {
            $nomComplet = $user['prenom'] . ' ' . $user['nom'];

            // Vérifier si le 2FA est activé pour cet utilisateur
            if (!empty($user['deux_fa_actif'])) {
                // Stocker les infos en session temporaire (en attente du code OTP)
                session_regenerate_id(true);
                $_SESSION['2fa_pending'] = [
                    'user_id' => $user['id'],
                    'role'    => $role,
                    'nom'     => $nomComplet,
                    'email'   => $email,
                ];
                // Générer et envoyer le code OTP
                require_once __DIR__ . '/otp_service.php';
                OtpService::genererEtEnvoyer($user['id'], $role, $email, $nomComplet);
                header('Location: /auth/otp_verify.php');
            } else {
                // 2FA désactivé → connexion directe
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_nom']  = $nomComplet;
                $_SESSION['user_role'] = $role;
                logAudit($user['id'], $role, 'login', null, null, 'Connexion réussie (sans 2FA)');
                header("Location: /{$role}/dashboard.php");
            }
            exit;
        }
        // Message générique pour ne pas indiquer si l'email existe
        $erreur = 'Email ou mot de passe incorrect.';
    }
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo">♥</div>
        <h1>SanteLink</h1>
        <p class="auth-sub">Connexion à votre espace personnel</p>
        <?php if ($erreur): ?>
            <div class="alert alert-danger"><?= esc($erreur) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Je suis</label>
                <div class="role-btns">
                    <label class="role-btn <?= ($_POST['role'] ?? 'patient') === 'patient' ? 'active' : '' ?>">
                        <input type="radio" name="role" value="patient"
                            <?= ($_POST['role'] ?? 'patient') === 'patient' ? 'checked' : '' ?>>
                        👤 Patient
                    </label>
                    <label class="role-btn <?= ($_POST['role'] ?? '') === 'medecin' ? 'active' : '' ?>">
                        <input type="radio" name="role" value="medecin"
                            <?= ($_POST['role'] ?? '') === 'medecin' ? 'checked' : '' ?>>
                        🩺 Médecin
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label for="email">Adresse email</label>
                <input type="email" id="email" name="email"
                       value="<?= esc($_POST['email'] ?? '') ?>"
                       placeholder="votre@email.com" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password"
                       placeholder="••••••••" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-primary btn-full">Se connecter</button>
        </form>
        <p class="auth-link">Pas encore de compte ?
            <a href="/auth/register.php">S'inscrire</a>
        </p>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
