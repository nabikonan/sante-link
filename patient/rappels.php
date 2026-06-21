<?php
$pageTitle = 'Mes rappels de mesure — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idPatient = (int) $_SESSION['user_id'];
$pdo       = getDB();

// ── Créer config si inexistante ───────────────────────────────
$pdo->prepare("
    INSERT IGNORE INTO rappel_config (id_patient) VALUES (:id)
")->execute([':id' => $idPatient]);

// ── Sauvegarder les préférences ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    $action = $_POST['action'] ?? 'sauvegarder';

    if ($action === 'pause') {
        $pdo->prepare("UPDATE rappel_config SET actif = 0 WHERE id_patient = :id")
            ->execute([':id' => $idPatient]);
        redirect('/patient/rappels.php', 'Rappels mis en pause.');
    }

    if ($action === 'reprendre') {
        $pdo->prepare("UPDATE rappel_config SET actif = 1 WHERE id_patient = :id")
            ->execute([':id' => $idPatient]);
        redirect('/patient/rappels.php', 'Rappels réactivés !');
    }

    // Récupérer les créneaux soumis
    $creneauxRaw = $_POST['creneaux'] ?? [];
    $creneaux    = [];
    foreach ($creneauxRaw as $c) {
        // Valider format HH:MM
        if (preg_match('/^\d{2}:\d{2}$/', $c)) {
            $creneaux[] = $c;
        }
    }
    // Dédup + tri
    $creneaux = array_unique($creneaux);
    sort($creneaux);
    // Limiter à 6 créneaux max
    $creneaux = array_slice($creneaux, 0, 6);

    if (empty($creneaux)) {
        $erreur = 'Ajoutez au moins un créneau de rappel.';
    } else {
        // Jours actifs
        $joursActifs = [];
        for ($j = 1; $j <= 7; $j++) {
            if (isset($_POST["jour_{$j}"])) $joursActifs[] = $j;
        }
        if (empty($joursActifs)) $joursActifs = [1,2,3,4,5,6,7];

        $canalEmail = isset($_POST['canal_email']) ? 1 : 0;
        $canalSms   = isset($_POST['canal_sms'])   ? 1 : 0;
        $tolerance  = max(15, min(180, (int)($_POST['tolerance'] ?? 90)));

        $pdo->prepare("
            UPDATE rappel_config
            SET    creneaux     = :cr,
                   jours_actifs = :ja,
                   canal_email  = :ce,
                   canal_sms    = :cs,
                   tolerance_min = :tol,
                   actif        = 1
            WHERE  id_patient   = :id
        ")->execute([
            ':cr'  => json_encode(array_values($creneaux)),
            ':ja'  => json_encode(array_values($joursActifs)),
            ':ce'  => $canalEmail,
            ':cs'  => $canalSms,
            ':tol' => $tolerance,
            ':id'  => $idPatient,
        ]);
        redirect('/patient/rappels.php', '✅ Rappels enregistrés !');
    }
}

// ── Charger la config actuelle ────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM rappel_config WHERE id_patient = :id");
$stmt->execute([':id' => $idPatient]);
$config = $stmt->fetch();

$creneaux    = json_decode($config['creneaux']    ?? '["08:00","12:00","20:00"]', true);
$joursActifs = json_decode($config['jours_actifs'] ?? '[1,2,3,4,5,6,7]',         true);
$actif       = (bool) ($config['actif'] ?? 1);

// ── Historique des 10 derniers rappels ────────────────────────
$stmtLog = $pdo->prepare("
    SELECT creneau, canal, envoye_le
    FROM   rappel_log
    WHERE  id_patient = :id
    ORDER  BY envoye_le DESC
    LIMIT  10
");
$stmtLog->execute([':id' => $idPatient]);
$historique = $stmtLog->fetchAll();

// Noms des jours
$nomsJours = [1=>'Lun', 2=>'Mar', 3=>'Mer', 4=>'Jeu', 5=>'Ven', 6=>'Sam', 7=>'Dim'];
$nomsJoursFull = [1=>'Lundi', 2=>'Mardi', 3=>'Mercredi', 4=>'Jeudi',
                  5=>'Vendredi', 6=>'Samedi', 7=>'Dimanche'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>⏰ Mes rappels de mesure</h1>
    <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $actif ? 'pause' : 'reprendre' ?>">
        <button type="submit" class="btn-secondary">
            <?= $actif ? '⏸️ Mettre en pause' : '▶️ Réactiver les rappels' ?>
        </button>
    </form>
</div>

<?= flashMessage() ?>
<?php if (isset($erreur)): ?>
    <div class="alert alert-danger"><?= esc($erreur) ?></div>
<?php endif; ?>

<!-- Statut actuel -->
<div class="info-banner <?= $actif ? 'info-green' : 'info-amber' ?>" style="max-width:680px;margin-bottom:20px">
    <?php if ($actif): ?>
        ✅ <strong>Rappels actifs</strong> — Vous recevrez des notifications aux créneaux configurés
        si aucune mesure n'a été saisie dans la fenêtre de tolérance.
    <?php else: ?>
        ⏸️ <strong>Rappels en pause</strong> — Aucune notification ne sera envoyée.
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;max-width:900px">

    <!-- Formulaire de configuration -->
    <div class="section-card">
        <h3 style="font-size:15px;font-weight:700;margin-bottom:16px">⚙️ Configuration</h3>

        <form method="POST" id="rappelForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sauvegarder">

            <!-- Créneaux horaires -->
            <div class="form-group">
                <label style="font-size:13px;font-weight:600">
                    Créneaux de rappel <span class="text-muted">(max 6)</span>
                </label>
                <div id="creneauxList" style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0">
                    <?php foreach ($creneaux as $c): ?>
                    <div class="creneau-tag">
                        <span>🕐 <?= esc($c) ?></span>
                        <input type="hidden" name="creneaux[]" value="<?= esc($c) ?>">
                        <button type="button" onclick="supprimerCreneau(this)"
                                style="background:none;border:none;cursor:pointer;
                                       color:var(--red-d);font-size:14px;padding:0 0 0 4px">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:8px;align-items:center;margin-top:6px">
                    <input type="time" id="nouveauCreneau"
                           style="padding:7px 10px;border:1px solid var(--border);
                                  border-radius:7px;font-size:13px">
                    <button type="button" class="btn-secondary btn-sm"
                            onclick="ajouterCreneau()">+ Ajouter</button>
                </div>
            </div>

            <!-- Jours actifs -->
            <div class="form-group">
                <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px">
                    Jours actifs
                </label>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <?php foreach ($nomsJours as $num => $nom): ?>
                    <label class="jour-btn <?= in_array($num, $joursActifs) ? 'jour-actif' : '' ?>"
                           onclick="toggleJour(this)">
                        <input type="checkbox" name="jour_<?= $num ?>"
                               <?= in_array($num, $joursActifs) ? 'checked' : '' ?>
                               style="display:none">
                        <?= $nom ?>
                    </label>
                    <?php endforeach; ?>
                    <button type="button" class="btn-secondary btn-sm"
                            onclick="toggleTousJours()">Tous</button>
                </div>
            </div>

            <!-- Canal de rappel -->
            <div class="form-group">
                <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px">
                    Canal de notification
                </label>
                <div style="display:flex;gap:12px">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                        <input type="checkbox" name="canal_email" value="1"
                               <?= ($config['canal_email'] ?? 1) ? 'checked' : '' ?>>
                        📧 Email
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                        <input type="checkbox" name="canal_sms" value="1"
                               <?= ($config['canal_sms'] ?? 0) ? 'checked' : '' ?>>
                        📱 SMS
                    </label>
                </div>
            </div>

            <!-- Tolérance -->
            <div class="form-group">
                <label style="font-size:13px;font-weight:600">
                    Fenêtre de tolérance :
                    <span id="tolLabel"><?= $config['tolerance_min'] ?? 90 ?> minutes</span>
                </label>
                <input type="range" name="tolerance" id="tolRange"
                       min="15" max="180" step="15"
                       value="<?= $config['tolerance_min'] ?? 90 ?>"
                       oninput="document.getElementById('tolLabel').textContent=this.value+' min'"
                       style="width:100%;margin-top:6px">
                <div style="display:flex;justify-content:space-between;
                            font-size:11px;color:var(--txt3);margin-top:2px">
                    <span>15 min (strict)</span>
                    <span>180 min (souple)</span>
                </div>
                <p class="text-muted" style="font-size:12px;margin-top:6px">
                    Si vous saisissez une mesure dans les ±<?= $config['tolerance_min'] ?? 90 ?> min
                    autour d'un créneau, le rappel n'est pas envoyé.
                </p>
            </div>

            <button type="submit" class="btn-primary">💾 Enregistrer les rappels</button>
        </form>
    </div>

    <!-- Panneau droit : aperçu + historique -->
    <div>
        <!-- Aperçu des prochains rappels -->
        <div class="section-card" style="margin-bottom:16px">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">
                📅 Prochains rappels aujourd'hui
            </h3>
            <?php
            $maintenant = new DateTime();
            $jourdHui   = (int) $maintenant->format('N');
            $prochains  = [];
            if ($actif && in_array($jourdHui, $joursActifs)) {
                foreach ($creneaux as $c) {
                    $dt = DateTime::createFromFormat('H:i', $c);
                    if ($dt && $dt > $maintenant) {
                        $prochains[] = $c;
                    }
                }
            }
            ?>
            <?php if (empty($prochains)): ?>
                <p class="text-muted" style="font-size:13px">
                    <?= $actif ? 'Aucun rappel restant aujourd\'hui.' : 'Rappels en pause.' ?>
                </p>
            <?php else: ?>
                <?php foreach ($prochains as $c): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:8px 0;
                            border-bottom:1px solid var(--border)">
                    <span style="font-size:20px">⏰</span>
                    <div>
                        <div style="font-weight:600;font-size:14px"><?= esc($c) ?></div>
                        <div class="text-muted" style="font-size:11px">
                            <?php
                            $h = (int) explode(':', $c)[0];
                            if ($h >= 5 && $h < 10)       echo 'Glycémie à jeun';
                            elseif ($h >= 10 && $h < 14)  echo 'Post-repas midi';
                            elseif ($h >= 14 && $h < 18)  echo 'Mesure après-midi';
                            elseif ($h >= 18 && $h < 22)  echo 'Post-repas soir';
                            else                           echo 'Mesure nocturne';
                            ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Historique des rappels -->
        <?php if (!empty($historique)): ?>
        <div class="section-card">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">
                📋 Derniers rappels envoyés
            </h3>
            <?php foreach ($historique as $h): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;
                        padding:7px 0;border-bottom:1px solid var(--border);font-size:12px">
                <span>
                    <?= $h['canal'] === 'email' ? '📧' : '📱' ?>
                    <?= substr($h['creneau'], 0, 5) ?>
                </span>
                <span class="text-muted">
                    <?= (new DateTime($h['envoye_le']))->format('d/m à H\hi') ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.creneau-tag {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--green-l); color: var(--green-d);
    border: 1px solid #5DCAA5; border-radius: 20px;
    padding: 4px 10px; font-size: 13px; font-weight: 600;
}
.jour-btn {
    padding: 6px 12px; border: 1px solid var(--border);
    border-radius: 8px; cursor: pointer; font-size: 13px;
    font-weight: 600; color: var(--txt2); user-select: none;
    transition: all .15s;
}
.jour-actif {
    background: var(--green-l); border-color: var(--green);
    color: var(--green-d);
}
</style>

<script>
// ── Gestion des créneaux ──────────────────────────────────────
function ajouterCreneau() {
    const inp   = document.getElementById('nouveauCreneau');
    const val   = inp.value;
    if (!val) return;

    // Vérifier doublon
    const existing = [...document.querySelectorAll('#creneauxList input[name="creneaux[]"]')]
                     .map(i => i.value);
    if (existing.includes(val)) { inp.value = ''; return; }
    if (existing.length >= 6) {
        alert('Maximum 6 créneaux autorisés.');
        return;
    }

    const div = document.createElement('div');
    div.className = 'creneau-tag';
    div.innerHTML = `<span>🕐 ${val}</span>
        <input type="hidden" name="creneaux[]" value="${val}">
        <button type="button" onclick="supprimerCreneau(this)"
                style="background:none;border:none;cursor:pointer;
                       color:var(--red-d);font-size:14px;padding:0 0 0 4px">×</button>`;
    document.getElementById('creneauxList').appendChild(div);
    inp.value = '';
}

function supprimerCreneau(btn) {
    const list = document.getElementById('creneauxList');
    if (list.querySelectorAll('.creneau-tag').length <= 1) {
        alert('Conservez au moins un créneau.');
        return;
    }
    btn.closest('.creneau-tag').remove();
}

// ── Gestion des jours ─────────────────────────────────────────
function toggleJour(label) {
    const cb = label.querySelector('input[type="checkbox"]');
    cb.checked = !cb.checked;
    label.classList.toggle('jour-actif', cb.checked);
}

function toggleTousJours() {
    const labels = document.querySelectorAll('.jour-btn');
    const tousCoches = [...labels].every(l =>
        l.querySelector('input').checked);
    labels.forEach(l => {
        const cb    = l.querySelector('input');
        cb.checked  = !tousCoches;
        l.classList.toggle('jour-actif', !tousCoches);
    });
}

// Mettre à jour label tolérance dynamiquement
document.getElementById('tolRange').addEventListener('input', function() {
    document.querySelector('.text-muted[style*="margin-top:6px"]').textContent =
        `Si vous saisissez une mesure dans les ±${this.value} min autour d'un créneau, le rappel n'est pas envoyé.`;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
