<?php
$pageTitle = 'Préférences notifications — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idPatient = (int) $_SESSION['user_id'];
$pdo       = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    $notifEmail = isset($_POST['notif_email']) ? 1 : 0;
    $notifSms   = isset($_POST['notif_sms'])   ? 1 : 0;
    $telephone  = trim($_POST['telephone'] ?? '');

    // Valider le téléphone si SMS activé
    if ($notifSms && !preg_match('/^\+?[\d\s\-]{8,15}$/', $telephone)) {
        $erreur = 'Numéro de téléphone invalide pour les SMS.';
    } else {
        $pdo->prepare("
            UPDATE patient
            SET    notif_email = :ne,
                   notif_sms   = :ns,
                   telephone   = :tel
            WHERE  id_patient  = :id
        ")->execute([
            ':ne'  => $notifEmail,
            ':ns'  => $notifSms,
            ':tel' => $telephone ?: null,
            ':id'  => $idPatient,
        ]);
        redirect('/patient/preferences_notifications.php', 'Préférences sauvegardées !');
    }
}

// Récupérer préférences actuelles
$stmt = $pdo->prepare(
    "SELECT notif_email, notif_sms, telephone FROM patient WHERE id_patient = :id"
);
$stmt->execute([':id' => $idPatient]);
$prefs = $stmt->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>🔔 Préférences de notifications</h1>
</div>

<?php if (isset($erreur)): ?>
    <div class="alert alert-danger"><?= esc($erreur) ?></div>
<?php endif; ?>

<div class="form-card" style="max-width:520px">
    <p class="text-muted" style="font-size:13px;margin-bottom:20px">
        Choisissez comment vous souhaitez être alerté en cas de glycémie hors cible
        ou d'inactivité prolongée.
    </p>

    <form method="POST">
        <?= csrfField() ?>

        <!-- Notifications Email -->
        <div style="border:1px solid var(--border);border-radius:10px;
                    padding:16px;margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <div style="font-weight:600;font-size:14px">📧 Notifications par email</div>
                    <div class="text-muted" style="font-size:12px;margin-top:2px">
                        Alertes glycémiques + rappels d'inactivité
                    </div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="notif_email" value="1"
                           <?= ($prefs['notif_email'] ?? 1) ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>

        <!-- Notifications SMS -->
        <div style="border:1px solid var(--border);border-radius:10px;
                    padding:16px;margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:center;
                        margin-bottom:12px">
                <div>
                    <div style="font-weight:600;font-size:14px">📱 Notifications par SMS</div>
                    <div class="text-muted" style="font-size:12px;margin-top:2px">
                        Alertes urgentes uniquement (hypoglycémie, hyperglycémie sévère)
                    </div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="notif_sms" value="1" id="toggleSms"
                           <?= ($prefs['notif_sms'] ?? 0) ? 'checked' : '' ?>
                           onchange="document.getElementById('telGroup').style.display=this.checked?'block':'none'">
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div id="telGroup" style="display:<?= ($prefs['notif_sms'] ?? 0) ? 'block' : 'none' ?>">
                <label style="font-size:12px;font-weight:600;color:var(--txt2)">
                    Numéro de téléphone *
                </label>
                <input type="tel" name="telephone"
                       value="<?= esc($prefs['telephone'] ?? '') ?>"
                       placeholder="+221 77 000 00 00"
                       style="margin-top:5px;width:100%;padding:9px 12px;
                              border:1px solid var(--border);border-radius:7px;font-size:13px">
                <p class="text-muted" style="font-size:11px;margin-top:5px">
                    Format international requis : +221 77 XXX XX XX
                </p>
            </div>
        </div>

        <!-- Résumé des déclencheurs -->
        <div class="info-banner info-blue" style="margin-bottom:20px">
            <strong>Quand serez-vous notifié ?</strong><br>
            · Glycémie hors cible (toute mesure dépassant les seuils)<br>
            · Aucune mesure enregistrée depuis 24 heures<br>
            · Votre médecin reçoit une copie pour les cas critiques
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Sauvegarder</button>
            <a href="/patient/dashboard.php" class="btn-secondary">Annuler</a>
        </div>
    </form>
</div>

<style>
.toggle-switch         { position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0 }
.toggle-switch input   { opacity:0;width:0;height:0 }
.toggle-slider         { position:absolute;cursor:pointer;inset:0;background:#E2E6EC;
                         border-radius:24px;transition:.2s }
.toggle-slider::before { content:'';position:absolute;height:18px;width:18px;left:3px;bottom:3px;
                         background:#fff;border-radius:50%;transition:.2s }
input:checked + .toggle-slider            { background:var(--green) }
input:checked + .toggle-slider::before   { transform:translateX(20px) }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>