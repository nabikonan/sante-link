<?php
$pageTitle = 'Mon dashboard — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idPatient = (int) $_SESSION['user_id'];
$pdo       = getDB();

// ── Infos patient ─────────────────────────────────────────────
$stmtP = $pdo->prepare("SELECT * FROM patient WHERE id_patient = :id");
$stmtP->execute([':id' => $idPatient]);
$patient = $stmtP->fetch();

// ── Dernière mesure ───────────────────────────────────────────
$stmtM = $pdo->prepare("
    SELECT * FROM mesure_glycemie
    WHERE  id_patient = :id
    ORDER  BY date_heure DESC LIMIT 1
");
$stmtM->execute([':id' => $idPatient]);
$derniereMesure = $stmtM->fetch();

// ── Stats globales (30 jours) ─────────────────────────────────
$stmtStats = $pdo->prepare("
    SELECT
        ROUND(AVG(valeur_glycemie), 2)  AS moyenne,
        COUNT(*)                         AS nb_mesures,
        MIN(valeur_glycemie)             AS min_val,
        MAX(valeur_glycemie)             AS max_val,
        ROUND(SUM(CASE WHEN valeur_glycemie < 0.70 OR valeur_glycemie > 2.00
              OR (contexte='A jeun'    AND valeur_glycemie > 1.10)
              OR (contexte='Post-repas' AND valeur_glycemie > 1.40)
              THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS pct_hors_cible
    FROM mesure_glycemie
    WHERE id_patient = :id
      AND date_heure >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmtStats->execute([':id' => $idPatient]);
$stats = $stmtStats->fetch();

// ── Mesures par contexte (30j) pour graphiques ────────────────
$contextes = ['A jeun', 'Post-repas', 'Avant sport', 'Apres sport', 'Autre'];
$chartData = [];
foreach ($contextes as $ctx) {
    $s = $pdo->prepare("
        SELECT DATE(date_heure) AS jour,
               ROUND(AVG(valeur_glycemie), 2) AS moy
        FROM   mesure_glycemie
        WHERE  id_patient = :id
          AND  contexte   = :ctx
          AND  date_heure >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP  BY DATE(date_heure)
        ORDER  BY jour ASC
        LIMIT  30
    ");
    $s->execute([':id' => $idPatient, ':ctx' => $ctx]);
    $rows = $s->fetchAll();
    if (!empty($rows)) {
        $chartData[$ctx] = $rows;
    }
}

// ── Distribution par contexte (camembert) ─────────────────────
$stmtDist = $pdo->prepare("
    SELECT contexte, COUNT(*) AS nb
    FROM   mesure_glycemie
    WHERE  id_patient = :id
      AND  date_heure >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP  BY contexte
");
$stmtDist->execute([':id' => $idPatient]);
$distribution = $stmtDist->fetchAll();

// ── Tendance horaire (heatmap data) ──────────────────────────
$stmtHeure = $pdo->prepare("
    SELECT HOUR(date_heure) AS heure,
           ROUND(AVG(valeur_glycemie), 2) AS moy,
           COUNT(*) AS nb
    FROM   mesure_glycemie
    WHERE  id_patient = :id
      AND  date_heure >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP  BY HOUR(date_heure)
    ORDER  BY heure
");
$stmtHeure->execute([':id' => $idPatient]);
$parHeure = $stmtHeure->fetchAll();

// ── Alertes non lues ─────────────────────────────────────────
$nbAlertes = getNbAlertesNonLues($idPatient);

// ── Traitements actifs ────────────────────────────────────────
$stmtT = $pdo->prepare("
    SELECT t.nom_medicament, t.dosage, p.frequence, p.heure_prise
    FROM   prescription p
    JOIN   traitement t ON t.id_traitement = p.id_traitement
    WHERE  p.id_patient = :id AND p.actif = 1
    ORDER  BY t.nom_medicament
");
$stmtT->execute([':id' => $idPatient]);
$traitements = $stmtT->fetchAll();

// ── Statut dernière mesure ────────────────────────────────────
$statut = $derniereMesure
    ? statutGlycemie((float)$derniereMesure['valeur_glycemie'], $derniereMesure['contexte'])
    : null;

// ── Préparer données JSON pour Chart.js ──────────────────────
$couleursCTX = [
    'A jeun'      => ['border' => '#185FA5', 'bg' => 'rgba(24,95,165,.10)'],
    'Post-repas'  => ['border' => '#1D9E75', 'bg' => 'rgba(29,158,117,.10)'],
    'Avant sport' => ['border' => '#EF9F27', 'bg' => 'rgba(239,159,39,.10)'],
    'Apres sport' => ['border' => '#8B5CF6', 'bg' => 'rgba(139,92,246,.10)'],
    'Autre'       => ['border' => '#9AA0AC', 'bg' => 'rgba(154,160,172,.10)'],
];

$datasetsLine = [];
$allJours     = [];
foreach ($chartData as $ctx => $rows) {
    foreach ($rows as $r) $allJours[$r['jour']] = true;
}
ksort($allJours);
$allJours = array_keys($allJours);

foreach ($chartData as $ctx => $rows) {
    $map = array_column($rows, 'moy', 'jour');
    $vals = array_map(fn($j) => $map[$j] ?? null, $allJours);
    $c = $couleursCTX[$ctx] ?? ['border' => '#ccc', 'bg' => 'rgba(200,200,200,.1)'];
    $datasetsLine[] = [
        'label'           => $ctx,
        'data'            => $vals,
        'borderColor'     => $c['border'],
        'backgroundColor' => $c['bg'],
        'borderWidth'     => 2,
        'pointRadius'     => 3,
        'tension'         => 0.3,
        'spanGaps'        => true,
        'fill'            => false,
    ];
}

$distLabels = array_column($distribution, 'contexte');
$distValues = array_column($distribution, 'nb');
$distColors = array_map(fn($l) => $couleursCTX[$l]['border'] ?? '#ccc', $distLabels);

$heureLabels = array_map(fn($r) => str_pad($r['heure'],2,'0',STR_PAD_LEFT).'h', $parHeure);
$heureMoy    = array_column($parHeure, 'moy');

require_once __DIR__ . '/../includes/header.php';
?>

<!-- En-tête patient -->
<div class="dash-header">
    <div class="dash-hello">
        <div class="dash-avatar"><?= strtoupper(substr($patient['prenom'],0,1)) ?></div>
        <div>
            <h1>Bonjour, <?= esc($patient['prenom']) ?> 👋</h1>
            <p class="text-muted" style="font-size:13px">
                Diabète <?= esc($patient['type_diabete']) ?>
                · Suivi sur 30 jours
            </p>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($statut): ?>
            <span class="badge badge-<?= $statut['couleur'] ?>" style="font-size:12px">
                <?= esc($statut['message']) ?>
            </span>
        <?php endif; ?>
        <?php if ($nbAlertes > 0): ?>
            <a href="/patient/alertes.php" class="badge badge-danger" style="font-size:12px">
                🔔 <?= $nbAlertes ?> alerte<?= $nbAlertes > 1 ? 's' : '' ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Cartes résumé -->
<div class="cards-grid" style="margin-bottom:20px">
    <div class="stat-card <?= $derniereMesure && $statut['couleur'] === 'danger' ? 'stat-danger' : ($statut && $statut['couleur'] === 'success' ? 'stat-success' : '') ?>">
        <div class="stat-label">Dernière mesure</div>
        <?php if ($derniereMesure): ?>
            <div class="stat-value text-<?= $statut['couleur'] ?>">
                <?= $derniereMesure['valeur_glycemie'] ?> <small style="font-size:14px">g/L</small>
            </div>
            <div class="stat-sub">
                <?= esc($derniereMesure['contexte']) ?>
                · <?= (new DateTime($derniereMesure['date_heure']))->format('d/m à H\hi') ?>
            </div>
        <?php else: ?>
            <div class="stat-value">—</div>
            <div class="stat-sub">Aucune mesure</div>
        <?php endif; ?>
    </div>

    <div class="stat-card">
        <div class="stat-label">Moyenne 30 jours</div>
        <div class="stat-value"><?= $stats['moyenne'] ?? '—' ?> <small style="font-size:14px"><?= $stats['moyenne'] ? 'g/L' : '' ?></small></div>
        <div class="stat-sub"><?= $stats['nb_mesures'] ?> mesure<?= $stats['nb_mesures'] > 1 ? 's' : '' ?> enregistrée<?= $stats['nb_mesures'] > 1 ? 's' : '' ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Min / Max 30j</div>
        <div class="stat-value" style="font-size:20px">
            <?= $stats['min_val'] ?? '—' ?> / <?= $stats['max_val'] ?? '—' ?>
        </div>
        <div class="stat-sub">g/L sur 30 jours</div>
    </div>

    <div class="stat-card <?= ($stats['pct_hors_cible'] ?? 0) > 30 ? 'stat-danger' : 'stat-success' ?>">
        <div class="stat-label">Hors cible 30j</div>
        <div class="stat-value"><?= $stats['pct_hors_cible'] ?? '0' ?> <small style="font-size:14px">%</small></div>
        <div class="stat-sub">
            <?= ($stats['pct_hors_cible'] ?? 0) > 30
                ? '⚠️ Trop de mesures hors cible'
                : '✅ Bon contrôle glycémique' ?>
        </div>
    </div>
</div>

<!-- Bouton action rapide -->
<div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap">
    <a href="/patient/mesure_form.php" class="btn-primary">➕ Nouvelle mesure</a>
    <a href="/patient/ia_risque.php"   class="btn-secondary">🤖 Prédiction IA</a>
    <a href="/patient/historique.php"  class="btn-secondary">📈 Historique complet</a>
</div>

<!-- Graphique 1 : Courbes par contexte -->
<div class="chart-card" style="margin-bottom:16px">
    <div class="chart-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div>
            <h3 style="font-size:14px;font-weight:600;margin-bottom:2px">📈 Évolution par contexte — 30 derniers jours</h3>
            <p class="text-muted" style="font-size:12px">Moyenne journalière par contexte de mesure</p>
        </div>
        <div style="display:flex;gap:6px">
            <button class="btn-secondary btn-sm" onclick="togglePeriod(7)">7j</button>
            <button class="btn-secondary btn-sm active-period" onclick="togglePeriod(30)" id="btn30">30j</button>
        </div>
    </div>

    <?php if (empty($chartData)): ?>
        <div class="empty-state" style="padding:40px">
            <div style="font-size:3rem">📊</div>
            <p>Pas encore assez de données. Saisissez vos premières mesures !</p>
            <a href="/patient/mesure_form.php" class="btn-primary" style="margin-top:12px">➕ Ajouter une mesure</a>
        </div>
    <?php else: ?>
        <!-- Zones de référence -->
        <div class="chart-legend-zones" style="display:flex;gap:12px;margin-bottom:10px;flex-wrap:wrap;font-size:11px">
            <span style="display:flex;align-items:center;gap:4px">
                <span style="width:24px;height:3px;background:#22c55e;display:inline-block;border-radius:2px"></span>
                Zone normale (0.70–1.10 g/L à jeun)
            </span>
            <span style="display:flex;align-items:center;gap:4px">
                <span style="width:24px;height:3px;background:#ef4444;display:inline-block;border-radius:2px;border-top:2px dashed #ef4444"></span>
                Seuil critique (&lt;0.70 ou &gt;2.00)
            </span>
        </div>
        <canvas id="ctxChart" height="100"></canvas>
    <?php endif; ?>
</div>

<?php if (!empty($distribution)): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">

    <!-- Graphique 2 : Répartition par contexte -->
    <div class="chart-card">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:4px">🥧 Répartition par contexte</h3>
        <p class="text-muted" style="font-size:12px;margin-bottom:16px">30 derniers jours</p>
        <canvas id="distChart" height="180"></canvas>
    </div>

    <!-- Graphique 3 : Moyenne par heure -->
    <div class="chart-card">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:4px">🕐 Moyenne par heure de la journée</h3>
        <p class="text-muted" style="font-size:12px;margin-bottom:16px">Détection des pics horaires</p>
        <canvas id="heureChart" height="180"></canvas>
    </div>

</div>
<?php endif; ?>

<!-- Traitements actifs -->
<?php if ($traitements): ?>
<div class="section-card">
    <h3 class="section-title">💊 Traitements en cours</h3>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($traitements as $t): ?>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;
                    padding:10px 14px;flex:1;min-width:200px">
            <div style="font-weight:600;font-size:13px">
                <?= esc($t['nom_medicament']) ?>
                <span class="badge badge-blue" style="margin-left:4px"><?= esc($t['dosage']) ?></span>
            </div>
            <div class="text-muted" style="font-size:12px;margin-top:3px">
                <?= esc($t['frequence']) ?>
                <?= $t['heure_prise'] ? '· 🕐 ' . esc($t['heure_prise']) : '' ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
// ── Données PHP → JS ─────────────────────────────────────────
const allLabels   = <?= json_encode(array_map(fn($j) =>
    (new DateTime($j))->format('d/m'), $allJours)) ?>;
const datasets    = <?= json_encode($datasetsLine) ?>;
const distLabels  = <?= json_encode($distLabels) ?>;
const distValues  = <?= json_encode($distValues) ?>;
const distColors  = <?= json_encode($distColors) ?>;
const heureLabels = <?= json_encode($heureLabels) ?>;
const heureMoy    = <?= json_encode($heureMoy) ?>;

Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
Chart.defaults.font.size   = 12;

// ── Graphique 1 : Lignes par contexte ────────────────────────
<?php if (!empty($chartData)): ?>
const ctxChart = new Chart(document.getElementById('ctxChart'), {
    type: 'line',
    data: { labels: allLabels, datasets },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'top',
                labels: { boxWidth: 12, padding: 16, font: { size: 12 } }
            },
            tooltip: {
                callbacks: {
                    label: c => c.dataset.label + ' : ' + (c.parsed.y ?? '—') + ' g/L'
                }
            }
        },
        scales: {
            y: {
                min: 0.40, max: 2.60,
                grid: { color: '#f0f0f0' },
                ticks: { callback: v => v + ' g/L' },
                // Zones colorées
            },
            x: { grid: { display: false } }
        },
        // Annotations zones normales
        plugins: undefined
    },
    plugins: [{
        id: 'zones',
        beforeDraw(chart) {
            const { ctx, chartArea: { top, bottom, left, right }, scales: { y } } = chart;
            // Zone normale jeun (vert pâle)
            ctx.save();
            ctx.fillStyle = 'rgba(34,197,94,.06)';
            const y70  = y.getPixelForValue(0.70);
            const y110 = y.getPixelForValue(1.10);
            ctx.fillRect(left, y110, right - left, y70 - y110);
            // Lignes de seuil
            [{ v: 0.70, c: '#ef4444' }, { v: 1.10, c: '#22c55e' },
             { v: 1.40, c: '#f59e0b' }, { v: 2.00, c: '#ef4444' }]
            .forEach(({ v, c }) => {
                ctx.strokeStyle = c;
                ctx.lineWidth   = 1;
                ctx.setLineDash([4, 4]);
                ctx.beginPath();
                ctx.moveTo(left,  y.getPixelForValue(v));
                ctx.lineTo(right, y.getPixelForValue(v));
                ctx.stroke();
            });
            ctx.restore();
        }
    }]
});

// Filtre par période
function togglePeriod(days) {
    const n = Math.min(days, allLabels.length);
    const slicedLabels = allLabels.slice(-n);
    ctxChart.data.labels = slicedLabels;
    ctxChart.data.datasets.forEach((ds, i) => {
        ds.data = datasets[i].data.slice(-n);
    });
    ctxChart.update();
    document.querySelectorAll('.active-period').forEach(b => b.classList.remove('active-period'));
}
<?php endif; ?>

// ── Graphique 2 : Répartition (doughnut) ─────────────────────
<?php if (!empty($distribution)): ?>
new Chart(document.getElementById('distChart'), {
    type: 'doughnut',
    data: {
        labels: distLabels,
        datasets: [{ data: distValues, backgroundColor: distColors, borderWidth: 2 }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } },
            tooltip: {
                callbacks: {
                    label: c => c.label + ' : ' + c.parsed + ' mesure' + (c.parsed > 1 ? 's' : '')
                }
            }
        },
        cutout: '60%'
    }
});

// ── Graphique 3 : Moyenne par heure (barres) ─────────────────
new Chart(document.getElementById('heureChart'), {
    type: 'bar',
    data: {
        labels: heureLabels,
        datasets: [{
            label: 'Moyenne g/L',
            data: heureMoy,
            backgroundColor: heureMoy.map(v =>
                v < 0.70 ? '#ef4444' :
                v > 1.40 ? '#f59e0b' :
                           '#1D9E75'
            ),
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: c => c.parsed.y + ' g/L' } }
        },
        scales: {
            y: {
                min: 0.40, max: 2.20,
                grid: { color: '#f0f0f0' },
                ticks: { callback: v => v + ' g/L' }
            },
            x: { grid: { display: false } }
        }
    }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
