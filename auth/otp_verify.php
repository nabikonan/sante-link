<?php
/**
 * DiabSuivi — Page de vérification du code OTP (2FA)
 * Étape 2 du processus de connexion quand le 2FA est activé.
 */
$pageTitle = 'Vérification — SanteLink';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';
require_once __DIR__ . '/otp_service.php';

// Vérifier qu'on a bien une session 2FA en attente
if (empty($_SESSION['2fa_pending'])) {
    header('Location: /auth/login.php');
    exit;
}

$pending   = $_SESSION['2fa_pending'];
$userId    = (int)   $pending['user_id'];
$role      =         $pending['role'];
$nomUser   =         $pending['nom'];
$email     =         $pending['email'];
$erreur    = '';
$info      = '';
$bloque    = false;

// ── Renvoyer un code ──────────────────────────────────────────
if (isset($_GET['renvoyer'])) {
    $result = OtpService::genererEtEnvoyer($userId, $role, $email, $nomUser);
    if ($result['ok']) {
        $info = 'Nouveau code envoyé à ' . esc($email) . '.';
    } else {
        $erreur = $result['msg'];
    }
}

// ── Vérifier le code soumis ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    // Reconstituer le code depuis les 6 champs individuels
    $digits = '';
    for ($i = 1; $i <= 6; $i++) {
        $digits .= preg_replace('/[^0-9]/', '', $_POST["d{$i}"] ?? '');
    }
    // Fallback si champ unique
    if (strlen($digits) < 6) {
        $digits = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
    }

    if (strlen($digits) !== 6) {
        $erreur = 'Veuillez saisir les 6 chiffres du code.';
    } else {
        $result = OtpService::verifier($userId, $role, $digits);

        if ($result['ok']) {
            // 2FA validé → créer la session définitive
            session_regenerate_id(true);
            $_SESSION['user_id']   = $userId;
            $_SESSION['user_nom']  = $nomUser;
            $_SESSION['user_role'] = $role;
            unset($_SESSION['2fa_pending']);

            logAudit($userId, $role, 'login', null, null, 'Connexion réussie (2FA validé)');

            header("Location: /{$role}/dashboard.php");
            exit;
        } else {
            $erreur = $result['msg'];
            $bloque = $result['bloque'] ?? false;
            if ($bloque) unset($_SESSION['2fa_pending']);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card" style="max-width:400px">
        <div class="auth-logo">🔐</div>
        <h1 style="font-size:20px">Vérification en deux étapes</h1>
        <p class="auth-sub">
            Un code à 6 chiffres a été envoyé à<br>
            <strong><?= esc($email) ?></strong>
        </p>

        <?php if ($erreur): ?>
            <div class="alert alert-danger"><?= esc($erreur) ?></div>
        <?php endif; ?>
        <?php if ($info): ?>
            <div class="alert alert-success"><?= esc($info) ?></div>
        <?php endif; ?>

        <?php if ($bloque): ?>
            <p class="text-muted" style="font-size:13px;text-align:center;margin-top:8px">
                <a href="/auth/login.php">← Retour à la connexion</a>
            </p>
        <?php else: ?>

        <form method="POST" id="otpForm">
            <?= csrfField() ?>

            <!-- Saisie OTP en 6 cases individuelles -->
            <div class="otp-inputs">
                <?php for ($i = 1; $i <= 6; $i++): ?>
                <input type="text" name="d<?= $i ?>" id="d<?= $i ?>"
                       maxlength="1" inputmode="numeric" pattern="[0-9]"
                       autocomplete="one-time-code"
                       class="otp-digit"
                       <?= $i === 1 ? 'autofocus' : '' ?>>
                <?php endfor; ?>
            </div>

            <!-- Minuterie -->
            <div style="text-align:center;margin:12px 0;font-size:12px;color:var(--txt2)">
                ⏱️ Code valide encore <span id="countdown" style="font-weight:600;color:var(--green-d)">10:00</span>
            </div>

            <button type="submit" class="btn-primary btn-full">Vérifier le code</button>
        </form>

        <div style="text-align:center;margin-top:14px;font-size:12px">
            <a href="?renvoyer=1" id="renvoyerBtn">📧 Renvoyer un code</a>
            <span style="margin:0 8px;color:var(--txt3)">·</span>
            <a href="/auth/login.php">← Annuler</a>
        </div>

        <?php endif; ?>
    </div>
</div>

<style>
.otp-inputs {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin: 20px 0;
}
.otp-digit {
    width: 46px !important;
    height: 54px;
    text-align: center;
    font-size: 24px;
    font-weight: 700;
    border: 2px solid var(--border);
    border-radius: 10px;
    padding: 0 !important;
    transition: border-color .15s;
    background: var(--bg2);
    color: var(--txt);
    caret-color: var(--green);
}
.otp-digit:focus {
    outline: none;
    border-color: var(--green);
    box-shadow: 0 0 0 3px rgba(29,158,117,.15);
}
.otp-digit.filled { border-color: var(--green); background: var(--green-l); }
</style>

<script>
// ── Navigation entre les cases OTP ───────────────────────────
const inputs = document.querySelectorAll('.otp-digit');

inputs.forEach((inp, idx) => {
    inp.addEventListener('input', e => {
        const val = e.target.value.replace(/[^0-9]/g, '');
        e.target.value = val.slice(-1);
        e.target.classList.toggle('filled', val.length > 0);
        if (val && idx < inputs.length - 1) inputs[idx + 1].focus();
        // Auto-submit si toutes les cases remplies
        if ([...inputs].every(i => i.value.length === 1)) {
            setTimeout(() => document.getElementById('otpForm').submit(), 200);
        }
    });

    inp.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !e.target.value && idx > 0) {
            inputs[idx - 1].focus();
            inputs[idx - 1].value = '';
            inputs[idx - 1].classList.remove('filled');
        }
        // Permettre coller
        if ((e.ctrlKey || e.metaKey) && e.key === 'v') return;
    });

    // Gérer le collage d'un code complet
    inp.addEventListener('paste', e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData)
            .getData('text').replace(/[^0-9]/g, '').slice(0, 6);
        pasted.split('').forEach((ch, i) => {
            if (inputs[i]) {
                inputs[i].value = ch;
                inputs[i].classList.add('filled');
            }
        });
        inputs[Math.min(pasted.length, inputs.length - 1)].focus();
        if (pasted.length === 6) {
            setTimeout(() => document.getElementById('otpForm').submit(), 200);
        }
    });
});

// ── Minuterie 10 minutes ──────────────────────────────────────
let secondesRestantes = <?= OtpService::EXPIRATION_MINUTES ?> * 60;
const countdown = document.getElementById('countdown');
const timer = setInterval(() => {
    secondesRestantes--;
    if (secondesRestantes <= 0) {
        clearInterval(timer);
        countdown.textContent = 'Expiré';
        countdown.style.color = 'var(--red-d)';
        document.querySelector('button[type="submit"]').disabled = true;
        document.querySelector('button[type="submit"]').textContent = 'Code expiré — Renvoyez un code';
        return;
    }
    const m = Math.floor(secondesRestantes / 60).toString().padStart(2,'0');
    const s = (secondesRestantes % 60).toString().padStart(2,'0');
    countdown.textContent = `${m}:${s}`;
    if (secondesRestantes <= 60) countdown.style.color = 'var(--red-d)';
}, 1000);

// Cooldown bouton "Renvoyer"
const renvoyerBtn = document.getElementById('renvoyerBtn');
<?php if (isset($_GET['renvoyer']) && !isset($erreur)): ?>
let cooldown = <?= OtpService::COOLDOWN_SECONDES ?>;
renvoyerBtn.style.pointerEvents = 'none';
renvoyerBtn.style.opacity = '.4';
const cdTimer = setInterval(() => {
    cooldown--;
    renvoyerBtn.textContent = `📧 Renvoyer (${cooldown}s)`;
    if (cooldown <= 0) {
        clearInterval(cdTimer);
        renvoyerBtn.textContent = '📧 Renvoyer un code';
        renvoyerBtn.style.pointerEvents = '';
        renvoyerBtn.style.opacity = '1';
    }
}, 1000);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
