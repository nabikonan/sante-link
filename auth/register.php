<?php
$pageTitle = 'Inscription — SanteLink';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    $nom       = trim($_POST['nom']          ?? '');
    $prenom    = trim($_POST['prenom']       ?? '');
    $email     = trim($_POST['email']        ?? '');
    $mdp       = $_POST['password']           ?? '';
    $naissance = $_POST['date_naissance']     ?? '';
    $sexe      = $_POST['sexe']               ?? '';
    $type_diab = $_POST['type_diabete']       ?? 'Type 2';
    $telephone = trim($_POST['telephone']    ?? '');

    // Whitelist type diabète
    $typesValides = ['Type 1', 'Type 2', 'Gestationnel', 'LADA', 'Autre'];
    if (!in_array($type_diab, $typesValides, true)) $type_diab = 'Type 2';

    // Whitelist sexe
    if (!in_array($sexe, ['M', 'F'], true)) $sexe = '';

    if (!$nom || !$prenom || !$email || !$mdp || !$naissance || !$sexe) {
        $erreur = 'Veuillez remplir tous les champs obligatoires.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = 'Adresse email invalide.';
    } elseif (strlen($mdp) < 8) {
        $erreur = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif (!DateTime::createFromFormat('Y-m-d', $naissance)) {
        $erreur = 'Date de naissance invalide.';
    } else {
        $hash = password_hash($mdp, PASSWORD_BCRYPT);
        try {
            $stmt = getDB()->prepare("
                INSERT INTO patient
                    (nom, prenom, email, mot_de_passe, date_naissance,
                     sexe, type_diabete, telephone)
                VALUES
                    (:nom, :prenom, :email, :mdp, :naissance,
                     :sexe, :type, :tel)
            ");
            $stmt->execute([
                ':nom'       => $nom,
                ':prenom'    => $prenom,
                ':email'     => $email,
                ':mdp'       => $hash,
                ':naissance' => $naissance,
                ':sexe'      => $sexe,
                ':type'      => $type_diab,
                ':tel'       => $telephone ?: null,
            ]);
            // Créer automatiquement la config de rappels par défaut
            $newId = (int) getDB()->lastInsertId();
            getDB()->prepare("INSERT IGNORE INTO rappel_config (id_patient) VALUES (:id)")
                   ->execute([':id' => $newId]);
            redirect('/auth/login.php', 'Compte créé avec succès ! Connectez-vous.');
        } catch (PDOException $e) {
            $erreur = str_contains($e->getMessage(), 'Duplicate')
                ? 'Cet email est déjà utilisé.'
                : 'Erreur lors de l\'inscription. Veuillez réessayer.';
        }
    }
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <div class="auth-card" style="max-width:480px">
        <div class="auth-logo">♥</div>
        <h1>Créer un compte</h1>
        <p class="auth-sub">Inscription patient SanteLink</p>
        <?php if ($erreur): ?>
            <div class="alert alert-danger"><?= esc($erreur) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Prénom *</label>
                    <input type="text" name="prenom"
                           value="<?= esc($_POST['prenom'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom"
                           value="<?= esc($_POST['nom'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email"
                       value="<?= esc($_POST['email'] ?? '') ?>" required autocomplete="email">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Date de naissance *</label>
                    <input type="date" name="date_naissance"
                           value="<?= esc($_POST['date_naissance'] ?? '') ?>"
                           max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Sexe *</label>
                    <select name="sexe" required>
                        <option value="">— Choisir —</option>
                        <option value="M" <?= ($_POST['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>Masculin</option>
                        <option value="F" <?= ($_POST['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>Féminin</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Type de diabète *</label>
                <select name="type_diabete">
                    <?php foreach (['Type 1','Type 2','Gestationnel','LADA','Autre'] as $t): ?>
                        <option value="<?= $t ?>"
                            <?= ($_POST['type_diabete'] ?? 'Type 2') === $t ? 'selected' : '' ?>>
                            <?= $t ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Téléphone</label>
                <input type="tel" name="telephone"
                       value="<?= esc($_POST['telephone'] ?? '') ?>"
                       placeholder="+221 77 000 00 00">
            </div>
            <div class="form-group">
                <label>Mot de passe * (min. 8 caractères)</label>
                <input type="password" name="password" required minlength="8"
                       autocomplete="new-password">
            </div>
            <button type="submit" class="btn-primary btn-full">Créer mon compte</button>
        </form>
        <p class="auth-link">Déjà un compte ?
            <a href="/auth/login.php">Se connecter</a>
        </p>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
