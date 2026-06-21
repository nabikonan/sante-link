<?php
$pageTitle = 'Dashboard médecin — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('medecin');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idMedecin = (int) $_SESSION['user_id'];
$pdo       = getDB();

// ── Infos médecin ─────────────────────────────────────────────
$stmtM = $pdo->prepare("SELECT * FROM medecin WHERE id_medecin = :id");
$stmtM->execute([':id' => $idMedecin]);
$medecin = $stmtM->fetch();

// ── Patients avec dernière mesure + nb alertes ────────────────
$stmtPats = $pdo->prepare("
    SELECT
        p.id_patient,
        CONCAT(p.prenom, ' ', p.nom) AS patient_nom,
        p.prenom, p.nom,
        p.type_diabete,
        m.valeur_glycemie  AS derniere_val,
        m.contexte         AS dernier_ctx,
        m.date_heure       AS derniere_date,
        (SELECT COUNT(*) FROM alerte a
         WHERE  a.id_patient = p.id_patient AND a.statut = 'Non lue') AS nb_alertes,
        (SELECT COUNT(*) FROM mesure_glycemie mg
         WHERE  mg.id_patient = p.id_patient
           AND  mg.date_heure >= DATE_SUB(NOW(), INTERVAL 7 DAY))     AS mesures_7j
    FROM suivi s
    JOIN patient p ON p.id_patient = s.id_patient
    LEFT JOIN mesure_glycemie m ON m.id_mesure = (
        SELECT id_mesure FROM mesure_glycemie
        WHERE  id_patient = p.id_patient
        ORDER  BY date_heure DESC LIMIT 1
    )
    WHERE s.id_medecin = :id AND s.actif = 1
    ORDER BY nb_alertes DESC, m.date_heure DESC
");
$stmtPats->execute([':id' => $idMedecin]);
$patients = $stmtPats->fetchAll();

// ── Stats globales du médecin ─────────────────────────────────
$nbPatients    = count($patients);
$nbAlertesTotal = array_sum(array_column($patients, 'nb_alertes'));
$nbSansRecente  = count(array_filter($patients, fn($p) => $p['mesures_7j'] == 0));

// ── Graphique : activité globale 30j (toutes mesures) ─────────
$stmtActivite = $pdo->prepare("
    SELECT DATE(mg.date_heure) AS jour, COUNT(*) AS nb_mesures,
           ROUND(AVG(mg.valeur_glycemie), 2) AS moy
    FROM   mesure_glycemie mg
    JOIN   suivi s ON s.id_patient = mg.id_patient
    WHERE  s.id_medecin = :id
      AND  mg.date_heure >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP  BY DATE(mg.date_heure)
    ORDER  BY jour ASC
");
$stmtActivite->execute([':id' => $idMedecin]);
$activite = $stmtActivite->fetchAll();

// ── Distribution des statuts des patients ─────────────────────
$critiques = $normaux = $sans_mesure = 0;
foreach ($patients as $p) {
    if (!$p['derniere_val']) { $sans_mesure++; continue; }
    $s = statutGlycemie((float)$p['derniere_val'], $p['dernier_ctx']);
    if ($s['couleur'] === 'danger')  $critiques++;
    elseif ($s['couleur'] === 'success') $normaux++;
}
$attention = $nbPatients - $critiques - $normaux - $sans_mesure;

require_once __DIR__ . '/../includes/header.php';
?>

<!-- En-tête médecin -->
<div class="dash-header">
    <div class="dash-hello">
        <div class="dash-avatar dash-avatar-med">🩺</div>
        <div>
            <h1>Bonjour, Dr <?= esc($medecin['prenom'] . ' ' . $medecin['nom']) ?> 👋</h1>
            <p class="text-muted" style="font-size:13px"><?= esc($medecin['specialite'] ?? 'Médecin') ?></p>
        </div>
    </div>
    <?php if ($nbAlertesTotal > 0): ?>
    <a href="/medecin/alertes.php" class="badge badge-danger" style="font-size:13px;padding:8px 14px">
        🔔 <?= $nbAlertesTotal ?> alerte<?= $nbAlertesTotal > 1 ? 's' : '' ?> non lue<?= $nbAlertesTotal > 1 ? 's' : '' ?>
    </a>
    <?php endif; ?>
</div>

<!-- Stats résumé -->
<div class="cards-grid" style="margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-label">Patients suivis</div>
        <div class="stat-value"><?= $nbPatients ?></div>
        <div class="stat-sub">patients actifs</div>
    </div>
    <div class="stat-card <?= $nbAlertesTotal > 0 ? 'stat-danger' : 'stat-success' ?>">
        <div class="stat-label">Alertes en attente</div>
        <div class="stat-value"><?= $nbAlertesTotal ?></div>
        <div class="stat-sub">
            <?= $nbAlertesTotal > 0
                ? '<a href="/medecin/alertes.php">Voir les alertes →</a>'
                : 'Aucune alerte' ?>
        </div>
    </div>
    <div class="stat-card <?= $critiques > 0 ? 'stat-danger' : '' ?>">
        <div class="stat-label">Patients critiques</div>
        <div class="stat-value"><?= $critiques ?></div>
        <div class="stat-sub">dernière mesure hors seuil</div>
    </div>
    <div class="stat-card <?= $nbSansRecente > 0 ? '' : 'stat-success' ?>">
        <div class="stat-label">Inactifs (7j)</div>
        <div class="stat-value"><?= $nbSansRecente ?></div>
        <div class="stat-sub">aucune mesure cette semaine</div>
    </div>
</div>

<!-- Actions rapides -->
<div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap">
    <a href="/medecin/alertes.php"     class="btn-primary">🔔 Alertes patients</a>
    <a href="/medecin/ordonnances.php" class="btn-secondary">📋 Ordonnances</a>
</div>

<!-- Graphique activité globale -->
<?php if (!empty($activite)): ?>
<div class="chart-card" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">
        <div>
            <h3 style="font-size:14px;font-weight:600;margin-bottom:2px">📈 Activité globale de mes patients — 30 jours</h3>
            <p class="text-muted" style="font-size:12px">Nombre de mesures et moyenne glycémique quotidienne</p>
        </div>
    </div>
    <canvas id="activiteChart" height="80"></canvas>
</div>
<?php endif; ?>

<!-- Répartition statuts + graphique -->
<?php if ($nbPatients > 0): ?>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px">

    <!-- Tableau patients -->
    <div class="section-card" style="margin-bottom:0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 class="section-title" style="margin-bottom:0">
                👥 Mes patients (<?= $nbPatients ?>)
            </h3>
            <input type="text" id="searchPatient" placeholder="🔍 Rechercher..."
                   style="padding:6px 10px;border:1px solid var(--border);border-radius:7px;
                          font-size:12px;width:160px" oninput="filtrerPatients()">
        </div>

        <div id="patientsList">
        <?php foreach ($patients as $p):
            $hasVal = !empty($p['derniere_val']);
            $s = $hasVal
                ? statutGlycemie((float)$p['derniere_val'], $p['dernier_ctx'])
                : ['statut' => 'Aucune mesure', 'couleur' => 'gray', 'message' => 'Aucune mesure'];
            $initiales = strtoupper(substr($p['prenom'],0,1) . substr($p['nom'],0,1));
        ?>
        <div class="patient-row" data-nom="<?= strtolower(esc($p['patient_nom'])) ?>">
            <div class="pat-avatar"><?= $initiales ?></div>
            <div class="pat-info">
                <div class="pat-nom">
                    <?= esc($p['patient_nom']) ?>
                    <?php if ($p['nb_alertes'] > 0): ?>
                        <span class="badge badge-danger badge-sm">
                            <?= $p['nb_alertes'] ?> alerte<?= $p['nb_alertes'] > 1 ? 's' : '' ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($p['mesures_7j'] == 0): ?>
                        <span class="badge badge-gray badge-sm">Inactif 7j</span>
                    <?php endif; ?>
                </div>
                <div class="pat-detail text-muted">
                    <?= esc($p['type_diabete']) ?>
                    <?php if ($hasVal): ?>
                        · <strong><?= $p['derniere_val'] ?> g/L</strong>
                        · <?= esc($p['dernier_ctx']) ?>
                        · <?= (new DateTime($p['derniere_date']))->format('d/m à H\hi') ?>
                    <?php else: ?>
                        · Aucune mesure enregistrée
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                <span class="badge badge-<?= $s['couleur'] ?>"><?= esc($s['statut']) ?></span>
                <a href="/medecin/patient_detail.php?id=<?= $p['id_patient'] ?>"
                   class="btn-secondary btn-sm">Dossier →</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <p id="noResult" style="display:none;text-align:center;color:var(--txt2);padding:20px;font-size:13px">
            Aucun patient trouvé.
        </p>
    </div>

    <!-- Camembert statuts -->
    <div class="chart-card" style="margin-bottom:0">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:4px">🥧 Statut des patients</h3>
        <p class="text-muted" style="font-size:12px;margin-bottom:16px">Dernière mesure connue</p>
        <canvas id="statutChart" height="200"></canvas>
        <div style="margin-top:14px;font-size:12px">
            <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border)">
                <span>✅ Normaux</span><strong><?= $normaux ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border)">
                <span>⚠️ Attention</span><strong><?= $attention ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border)">
                <span>🚨 Critiques</span><strong><?= $critiques ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:4px 0">
                <span>❓ Sans mesure</span><strong><?= $sans_mesure ?></strong>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
Chart.defaults.font.size   = 12;

<?php if (!empty($activite)): ?>
// Graphique activité
const actLabels = <?= json_encode(array_map(fn($r) =>
    (new DateTime($r['jour']))->format('d/m'), $activite)) ?>;
const actNb     = <?= json_encode(array_column($activite, 'nb_mesures')) ?>;
const actMoy    = <?= json_encode(array_column($activite, 'moy')) ?>;

new Chart(document.getElementById('activiteChart'), {
    data: {
        labels: actLabels,
        datasets: [
            {
                type: 'bar',
                label: 'Nb mesures',
                data: actNb,
                backgroundColor: 'rgba(24,95,165,.20)',
                borderColor: '#185FA5',
                borderWidth: 1,
                borderRadius: 3,
                yAxisID: 'y2',
            },
            {
                type: 'line',
                label: 'Moy. glycémie (g/L)',
                data: actMoy,
                borderColor: '#1D9E75',
                backgroundColor: 'transparent',
                borderWidth: 2.5,
                pointRadius: 3,
                tension: 0.3,
                yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { boxWidth: 12 } },
            tooltip: {
                callbacks: {
                    label: c => c.dataset.label + ' : ' +
                        c.parsed.y + (c.dataset.yAxisID === 'y1' ? ' g/L' : '')
                }
            }
        },
        scales: {
            y1: { position: 'left',  min: 0.4, max: 2.5,
                  ticks: { callback: v => v + ' g/L' }, grid: { color: '#f0f0f0' } },
            y2: { position: 'right', min: 0,
                  ticks: { stepSize: 1 }, grid: { display: false } },
            x:  { grid: { display: false } }
        }
    }
});
<?php endif; ?>

// Graphique statuts
new Chart(document.getElementById('statutChart'), {
    type: 'doughnut',
    data: {
        labels: ['Normaux', 'Attention', 'Critiques', 'Sans mesure'],
        datasets: [{
            data: [<?= $normaux ?>, <?= $attention ?>, <?= $critiques ?>, <?= $sans_mesure ?>],
            backgroundColor: ['#1D9E75','#EF9F27','#E24B4A','#9AA0AC'],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: c => c.label + ' : ' + c.parsed } }
        },
        cutout: '55%'
    }
});

// Recherche patients
function filtrerPatients() {
    const q    = document.getElementById('searchPatient').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.patient-row');
    let vis    = 0;
    rows.forEach(r => {
        const match = r.dataset.nom.includes(q);
        r.style.display = match ? '' : 'none';
        if (match) vis++;
    });
    document.getElementById('noResult').style.display = vis === 0 ? '' : 'none';
}
</script>

<style>
.dash-header    { display:flex;justify-content:space-between;align-items:center;
                  margin-bottom:20px;flex-wrap:wrap;gap:12px; }
.dash-hello     { display:flex;align-items:center;gap:14px; }
.dash-avatar    { width:50px;height:50px;border-radius:50%;background:var(--green-l);
                  color:var(--green-d);display:flex;align-items:center;
                  justify-content:center;font-size:22px;font-weight:700;flex-shrink:0; }
.dash-avatar-med { background:var(--blue-l);color:var(--blue-d); }
.dash-hello h1  { font-size:20px;margin-bottom:2px; }

.patient-row    { display:flex;align-items:center;gap:12px;padding:12px;
                  border-radius:8px;border:1px solid var(--border);
                  background:var(--bg2);margin-bottom:8px;transition:background .15s; }
.patient-row:hover { background:var(--bg); }
.pat-avatar     { width:38px;height:38px;border-radius:50%;background:var(--green-l);
                  color:var(--green-d);display:flex;align-items:center;
                  justify-content:center;font-weight:700;font-size:13px;flex-shrink:0; }
.pat-info       { flex:1;min-width:0; }
.pat-nom        { font-size:13px;font-weight:600;display:flex;align-items:center;
                  gap:6px;flex-wrap:wrap; }
.pat-detail     { font-size:12px;margin-top:2px; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
