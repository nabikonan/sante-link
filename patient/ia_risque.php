<?php
$pageTitle = 'Prédiction IA — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idPatient = (int) $_SESSION['user_id'];

// ── Appeler le script Python avec timeout ────────────────────
$scriptPath = escapeshellarg(__DIR__ . '/../ia/prediction.py');
$patientArg = (int) $idPatient;

$cmd = "python $scriptPath --patient $patientArg 2>&1";
$output     = [];
$returnCode = 0;
exec($cmd, $output, $returnCode);
$resultat = null;
$erreurIa = '';
$jsonBrut = implode("\n", $output);
$jsonBrut = mb_convert_encoding($jsonBrut, 'UTF-8', 'auto');
$jsonBrut = trim($jsonBrut); // Supprimer les espaces et nouvelles lignes superflus

if ($returnCode === 124) {
    $erreurIa = 'Le module IA a mis trop de temps à répondre. Veuillez réessayer.';
} elseif ($returnCode !== 0 || empty($jsonBrut)) {
    $erreurIa = 'Le module IA est temporairement indisponible.';
} else {
    $resultat = json_decode($jsonBrut, true);
    if (!$resultat || isset($resultat['error'])) {
        $erreurIa = $resultat['error'] ?? 'Réponse IA invalide.';
        $resultat = null;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>🤖 Prédiction IA — Risque glycémique</h1>
</div>

<div class="ia-wrap">

    <div class="info-banner info-blue" style="margin-bottom:20px">
        🧠 Ce module analyse votre historique glycémique grâce à un modèle
        <strong>Random Forest</strong> pour estimer votre risque de déséquilibre.
        La prédiction n'est <strong>pas un avis médical</strong> — consultez toujours votre médecin.
    </div>

    <?php if ($erreurIa): ?>
        <div class="alert alert-danger"><?= esc($erreurIa) ?></div>

    <?php elseif ($resultat && $resultat['risque'] === 'Indéterminé'): ?>
        <div class="ia-card ia-card-neutral">
            <div class="ia-risque-badge badge-gray">❓ Données insuffisantes</div>
            <p><?= esc($resultat['message']) ?></p>
            <ul class="ia-recs">
                <?php foreach ($resultat['recommandations'] ?? [] as $rec): ?>
                    <li><?= esc($rec) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="ia-cta">
                <a href="/patient/mesure_form.php" class="btn-primary">➕ Saisir une mesure</a>
            </div>
        </div>

    <?php elseif ($resultat): ?>
        <?php
        $badgeClass = ['Élevé'=>'badge-danger','Modéré'=>'badge-warning','Faible'=>'badge-success'][$resultat['risque']] ?? 'badge-gray';
        $cardClass  = ['Élevé'=>'ia-card-danger','Modéré'=>'ia-card-warning','Faible'=>'ia-card-success'][$resultat['risque']] ?? '';
        $icone      = ['Élevé'=>'🚨','Modéré'=>'⚠️','Faible'=>'✅'][$resultat['risque']] ?? '📊';
        $probPct    = $resultat['probabilite'] !== null ? round($resultat['probabilite'] * 100, 1) : null;
        $stats      = $resultat['stats'] ?? [];
        $hba1c      = $resultat['hba1c'] ?? null;
        $courbe     = $resultat['courbe_probabilite'] ?? [];
        $tendance   = $resultat['tendance_risque'] ?? 'stable';
        ?>

        <!-- Carte principale risque -->
        <div class="ia-card <?= $cardClass ?>">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
                <div class="ia-risque-badge <?= $badgeClass ?>">
                    <?= $icone ?> Risque <?= esc($resultat['risque']) ?>
                </div>
                <?php if ($tendance !== 'stable'): ?>
                <span class="badge <?= $tendance === 'hausse' ? 'badge-danger' : 'badge-success' ?>">
                    <?= $tendance === 'hausse' ? '📈 Tendance en hausse' : '📉 Tendance en baisse' ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if ($probPct !== null): ?>
            <div class="ia-proba">
                <div class="proba-bar">
                    <div class="proba-fill"
                         style="width:<?= $probPct ?>%;background:<?=
                             $probPct >= 70 ? '#ef4444' : ($probPct >= 45 ? '#f59e0b' : '#22c55e')
                         ?>"></div>
                </div>
                <span class="proba-label"><?= $probPct ?>% de probabilité de déséquilibre</span>
            </div>
            <?php endif; ?>

            <p class="ia-message"><?= esc($resultat['message']) ?></p>

            <?php if (!empty($resultat['recommandations'])): ?>
            <div class="ia-recs-wrap">
                <h4>💡 Recommandations personnalisées</h4>
                <ul class="ia-recs">
                    <?php foreach ($resultat['recommandations'] as $rec): ?>
                        <li><?= esc($rec) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($stats)): ?>
            <div class="ia-stats">
                <div class="ia-stat"><span class="ia-stat-val"><?= $stats['moyenne'] ?></span><span class="ia-stat-lbl">Moy. g/L</span></div>
                <div class="ia-stat"><span class="ia-stat-val"><?= $stats['min'] ?></span><span class="ia-stat-lbl">Min g/L</span></div>
                <div class="ia-stat"><span class="ia-stat-val"><?= $stats['max'] ?></span><span class="ia-stat-lbl">Max g/L</span></div>
                <div class="ia-stat"><span class="ia-stat-val"><?= $stats['pct_hors_cible'] ?>%</span><span class="ia-stat-lbl">Hors cible</span></div>
                <div class="ia-stat"><span class="ia-stat-val"><?= $stats['nb_mesures'] ?></span><span class="ia-stat-lbl">Mesures</span></div>
            </div>
            <?php endif; ?>

            <div class="ia-footer">
                <span class="text-muted" style="font-size:12px">
                    Généré le <?= esc($resultat['generee_le'] ?? '') ?>
                    · Dernière valeur : <?= $resultat['derniere_valeur'] ?> g/L
                    (<?= esc($resultat['dernier_contexte'] ?? '') ?>)
                </span>
                <a href="/patient/ia_risque.php" class="btn-secondary btn-sm">🔄 Actualiser</a>
            </div>
        </div>

        <!-- ── Carte HbA1c estimée ───────────────────────────── -->
        <?php if ($hba1c): ?>
        <div class="ia-card" style="border-color:<?=
            $hba1c['niveau']==='danger' ? '#F09595' : ($hba1c['niveau']==='warning' ? '#FAC775' : '#5DCAA5')
        ?>">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
                <div style="display:flex;align-items:center;gap:16px">
                    <div style="text-align:center">
                        <div style="font-size:34px;font-weight:800;color:<?=
                            $hba1c['niveau']==='danger' ? 'var(--red-d)' : ($hba1c['niveau']==='warning' ? 'var(--amber-d)' : 'var(--green-d)')
                        ?>"><?= $hba1c['valeur'] ?>%</div>
                        <div class="text-muted" style="font-size:11px">HbA1c estimée</div>
                    </div>
                    <div style="border-left:1px solid var(--border);padding-left:16px">
                        <div style="font-weight:700;font-size:14px"><?= esc($hba1c['categorie']) ?></div>
                        <div class="text-muted" style="font-size:12px">
                            Basée sur <?= $hba1c['nb_mesures_90j'] ?> mesures (90 derniers jours)
                            · Moy. <?= $hba1c['moyenne_gL'] ?> g/L
                        </div>
                        <div class="text-muted" style="font-size:11px;margin-top:2px">
                            🎯 Objectif recommandé : <?= esc($hba1c['objectif']) ?>
                        </div>
                    </div>
                </div>
                <span class="badge badge-<?= $hba1c['niveau'] ?>" style="font-size:11px">
                    <?= $hba1c['niveau']==='success' ? '✅ Bon contrôle' : ($hba1c['niveau']==='warning' ? '⚠️ À surveiller' : '🚨 Action requise') ?>
                </span>
            </div>
            <div class="info-banner info-blue" style="margin-top:14px;margin-bottom:0;font-size:11px">
                ℹ️ L'HbA1c (hémoglobine glyquée) est une estimation basée sur la formule ADAG
                (glycémie moyenne → HbA1c). Elle ne remplace pas un dosage sanguin réel en laboratoire.
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Courbe de probabilité dans le temps ──────────── -->
        <?php if (!empty($courbe) && count($courbe) >= 3): ?>
        <div class="chart-card">
            <h3 style="font-size:14px;font-weight:600;margin-bottom:4px">
                📈 Évolution du risque — 10 dernières mesures
            </h3>
            <p class="text-muted" style="font-size:12px;margin-bottom:16px">
                Probabilité de déséquilibre prédite par le modèle pour chaque mesure récente
            </p>
            <canvas id="courbeRisqueChart" height="90"></canvas>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    <div class="ia-cta" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <a href="/patient/dashboard.php"  class="btn-secondary">← Tableau de bord</a>
        <a href="/patient/historique.php" class="btn-secondary">📈 Historique</a>
        <a href="/patient/rapport.php"    class="btn-secondary">📄 Exporter PDF</a>
    </div>

</div>

<?php if (!empty($courbe) && count($courbe) >= 3): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const courbeData  = <?= json_encode($courbe) ?>;
const labels      = courbeData.map(p => {
    const d = new Date(p.date.replace(' ', 'T'));
    return d.toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit'}) + ' ' +
           d.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
});
const probas    = courbeData.map(p => Math.round(p.probabilite * 100));
const valeurs   = courbeData.map(p => p.valeur);

Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
Chart.defaults.font.size = 11;

new Chart(document.getElementById('courbeRisqueChart'), {
    data: {
        labels,
        datasets: [
            {
                type: 'line',
                label: 'Probabilité de risque (%)',
                data: probas,
                borderColor: '#E24B4A',
                backgroundColor: 'rgba(226,75,74,.08)',
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: probas.map(p => p >= 70 ? '#E24B4A' : p >= 45 ? '#EF9F27' : '#1D9E75'),
                tension: 0.3,
                fill: true,
                yAxisID: 'y1',
            },
            {
                type: 'line',
                label: 'Glycémie (g/L)',
                data: valeurs,
                borderColor: '#185FA5',
                backgroundColor: 'transparent',
                borderWidth: 1.5,
                borderDash: [4,4],
                pointRadius: 2,
                yAxisID: 'y2',
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
                    label: c => c.dataset.label + ' : ' + c.parsed.y +
                        (c.dataset.yAxisID === 'y1' ? '%' : ' g/L')
                }
            }
        },
        scales: {
            y1: { position:'left',  min:0, max:100, ticks:{ callback:v=>v+'%' }, grid:{color:'#f0f0f0'} },
            y2: { position:'right', min:0.4, max:2.5, ticks:{ callback:v=>v+' g/L' }, grid:{display:false} },
            x:  { grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
