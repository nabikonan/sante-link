<?php
$pageTitle = 'Sécurité du compte — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';
require_once __DIR__ . '/../auth/otp_service.php';

$idPatient = (int) $_SESSION['user_id'];
$pdo       = getDB();
$erreur    = '';

// Récupérer état actuel
$stmt = $pdo->prepare(
    "SELECT email, deux_fa_actif, nom, prenom FROM patient WHERE id_patient = :id"
);
$stmt->execute([':id' => $idPatient]);
$patient  = $stmt->fetch();
$deuxFaOk = (bool) $patient['deux_fa_actif'];

// ── Activer / Désactiver le 2FA ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'activer') {
        // Envoyer un code de confirmation avant d'activer
        $result = OtpService::genererEtEnvoyer(
            $idPatient, 'patient',
            $patient['email'],
            $patient['prenom'] . ' ' . $patient['nom']
        );
        if ($result['ok']) {
            $_SESSION['2fa_activation_pending'] = $idPatient;
            redirect('/patient/securite.php?confirmer=1',
                'Code envoyé à ' . $patient['email'] . '. Entrez-le pour activer le 2FA.');
        } else {
            $erreur = $result['msg'];
        }

    } elseif ($action === 'confirmer_activation') {
        // Vérifier le code pour activer le 2FA
        $code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
        if (!isset($_SESSION['2fa_activation_pending'])) {
            $erreur = 'Session expirée. Recommencez.';
        } else {
            $result = OtpService::verifier($idPatient, 'patient', $code);
            if ($result['ok']) {
                $pdo->prepare("UPDATE patient SET deux_fa_actif = 1 WHERE id_patient = :id")
                    ->execute([':id' => $idPatient]);
                unset($_SESSION['2fa_activation_pending']);
                redirect('/patient/securite.php', '✅ Double authentification activée !');
            } else {
                $erreur = $result['msg'];
            }
        }

    } elseif ($action === 'desactiver') {
        $pdo->prepare("UPDATE patient SET deux_fa_actif = 0 WHERE id_patient = :id")
            ->execute([':id' => $idPatient]);
        redirect('/patient/securite.php', 'Double authentification désactivée.');
    }

    // Changer le mot de passe
    if ($action === 'changer_mdp') {
        $ancienMdp  = $_POST['ancien_mdp']  ?? '';
        $nouveauMdp = $_POST['nouveau_mdp'] ?? '';
        $confirmMdp = $_POST['confirm_mdp'] ?? '';

        $stmtMdp = $pdo->prepare("SELECT mot_de_passe FROM patient WHERE id_patient = :id");
        $stmtMdp->execute([':id' => $idPatient]);
        $hashActuel = $stmtMdp->fetchColumn();

        if (!password_verify($ancienMdp, $hashActuel)) {
            $erreur = 'Ancien mot de passe incorrect.';
        } elseif (strlen($nouveauMdp) < 8) {
            $erreur = 'Le nouveau mot de passe doit faire au moins 8 caractères.';
        } elseif ($nouveauMdp !== $confirmMdp) {
            $erreur = 'Les mots de passe ne correspondent pas.';
        } else {
            $pdo->prepare("UPDATE patient SET mot_de_passe = :mdp WHERE id_patient = :id")
                ->execute([':id' => $idPatient, ':mdp' => password_hash($nouveauMdp, PASSWORD_BCRYPT)]);
            redirect('/patient/securite.php', '✅ Mot de passe mis à jour.');
        }
    }
}

$confirmer = isset($_GET['confirmer']);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>🔒 Sécurité du compte</h1>
</div>

<?= flashMessage() ?>
<?php if ($erreur): ?>
    <div class="alert alert-danger"><?= esc($erreur) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:760px">

    <!-- Bloc 2FA -->
    <div class="section-card">
        <h3 style="font-size:15px;font-weight:700;margin-bottom:4px">
            🔐 Double authentification (2FA)
        </h3>
        <p class="text-muted" style="font-size:12px;margin-bottom:16px">
            À chaque connexion, un code à 6 chiffres est envoyé à votre email.
        </p>

        <!-- Statut actuel -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;
                    padding:12px;border-radius:8px;
                    background:<?= $deuxFaOk ? 'var(--green-l)' : 'var(--bg)' ?>;
                    border:1px solid <?= $deuxFaOk ? '#5DCAA5' : 'var(--border)' ?>">
            <span style="font-size:22px"><?= $deuxFaOk ? '✅' : '⭕' ?></span>
            <div>
                <div style="font-weight:600;font-size:13px">
                    <?= $deuxFaOk ? 'Activée' : 'Désactivée' ?>
                </div>
                <div class="text-muted" style="font-size:11px">
                    <?= $deuxFaOk
                        ? 'Votre compte est protégé par le 2FA'
                        : 'Recommandé pour les données de santé' ?>
                </div>
            </div>
        </div>

        <?php if ($confirmer && !$deuxFaOk): ?>
            <!-- Formulaire de confirmation du code -->
            <div class="info-banner info-blue" style="margin-bottom:12px">
                Code envoyé à <strong><?= esc($patient['email']) ?></strong>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="confirmer_activation">
                <div class="form-group">
                    <label>Code de vérification (6 chiffres)</label>
                    <input type="text" name="code" maxlength="6"
                           inputmode="numeric" pattern="[0-9]{6}"
                           placeholder="000000" autofocus
                           style="letter-spacing:8px;font-size:20px;
                                  font-weight:700;text-align:center">
                </div>
                <button type="submit" class="btn-primary btn-full">Confirmer et activer</button>
            </form>

        <?php elseif ($deuxFaOk): ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="desactiver">
                <button type="submit" class="btn-secondary btn-full"
                        onclick="return confirm('Désactiver le 2FA réduit la sécurité. Confirmer ?')">
                    Désactiver le 2FA
                </button>
            </form>

        <?php else: ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="activer">
                <button type="submit" class="btn-primary btn-full">Activer le 2FA</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Bloc changement de mot de passe -->
    <div class="section-card">
        <h3 style="font-size:15px;font-weight:700;margin-bottom:4px">🔑 Changer le mot de passe</h3>
        <p class="text-muted" style="font-size:12px;margin-bottom:16px">
            Minimum 8 caractères.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="changer_mdp">
            <div class="form-group">
                <label>Mot de passe actuel</label>
                <input type="password" name="ancien_mdp" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label>Nouveau mot de passe</label>
                <input type="password" name="nouveau_mdp" required minlength="8"
                       autocomplete="new-password" oninput="majForce(this.value)">
                <div id="forceBar" style="height:4px;border-radius:2px;margin-top:5px;
                                          background:var(--border);overflow:hidden">
                    <div id="forceFill" style="height:100%;width:0;transition:all .3s"></div>
                </div>
                <div id="forceLabel" class="text-muted" style="font-size:11px;margin-top:3px"></div>
            </div>
            <div class="form-group">
                <label>Confirmer le nouveau mot de passe</label>
                <input type="password" name="confirm_mdp" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn-primary btn-full">Mettre à jour</button>
        </form>
    </div>
</div>

<script>
function majForce(val) {
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const fill  = document.getElementById('forceFill');
    const label = document.getElementById('forceLabel');
    const levels = [
        { pct: '20%', color: '#ef4444', txt: 'Très faible' },
        { pct: '40%', color: '#f97316', txt: 'Faible' },
        { pct: '60%', color: '#eab308', txt: 'Moyen' },
        { pct: '80%', color: '#22c55e', txt: 'Fort' },
        { pct: '100%',color: '#16a34a', txt: 'Très fort' },
    ];
    const lvl = levels[Math.min(score, 4)];
    fill.style.width       = val.length ? lvl.pct  : '0';
    fill.style.background  = lvl.color;
    label.textContent      = val.length ? lvl.txt  : '';
    label.style.color      = lvl.color;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
