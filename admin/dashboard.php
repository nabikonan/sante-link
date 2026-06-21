<?php
$pageTitle = 'Vue d\'ensemble — Admin SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('admin');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$pdo = getDB();

// ── Statistiques globales ─────────────────────────────────────
$nbPatients  = (int) $pdo->query("SELECT COUNT(*) FROM patient")->fetchColumn();
$nbMedecins  = (int) $pdo->query("SELECT COUNT(*) FROM medecin")->fetchColumn();
$nbMesures   = (int) $pdo->query("SELECT COUNT(*) FROM mesure_glycemie")->fetchColumn();
$nbAlertes   = (int) $pdo->query("SELECT COUNT(*) FROM alerte WHERE statut='Non lue'")->fetchColumn();
$nbMessages  = (int) $pdo->query("SELECT COUNT(*) FROM message")->fetchColumn();
$nbOrdos     = (int) $pdo->query("SELECT COUNT(*) FROM ordonnance")->fetchColumn();

// Nouveaux patients ce mois
$nbNouveauxPatients = (int) $pdo->query("
    SELECT COUNT(*) FROM patient
    WHERE date_inscription >= DATE_FORMAT(NOW(), '%Y-%m-01')
")->fetchColumn();

// Patients actifs (mesure dans les 7 derniers jours)
$nbActifs7j = (int) $pdo->query("
    SELECT COUNT(DISTINCT id_patient) FROM mesure_glycemie
    WHERE date_heure >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetchColumn();

// ── Activité des 30 derniers jours (inscriptions + mesures) ───
$stmtInscriptions = $pdo->query("
    SELECT DATE(date_inscription) AS jour, COUNT(*) AS nb
    FROM   patient
    WHERE  date_inscription >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP  BY DATE(date_inscription)
    ORDER  BY jour
");
$inscriptions = $stmtInscriptions->fetchAll();

$stmtActiviteMesures = $pdo->query("
    SELECT DATE(date_heure) AS jour, COUNT(*) AS nb
    FROM   mesure_glycemie
    WHERE  date_heure >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP  BY DATE(date_heure)
    ORDER  BY jour
");
$activiteMesures = $stmtActiviteMesures->fetchAll();

// ── Répartition type de diabète ───────────────────────────────
$stmtTypes = $pdo->query("
    SELECT type_diabete, COUNT(*) AS nb FROM patient GROUP BY type_diabete
");
$typesDiabete = $stmtTypes->fetchAll();

// ── Top médecins par nombre de patients ───────────────────────
$stmtTopMed = $pdo->query("
    SELECT m.nom, m.prenom, COUNT(s.id_patient) AS nb_patients
    FROM   medecin m
    LEFT   JOIN suivi s ON s.id_medecin = m.id_medecin AND s.actif = 1
    GROUP  BY m.id_medecin
    ORDER  BY nb_patients DESC
    LIMIT  5
");
$topMedecins = $stmtTopMed->fetchAll();

// ── Dernières activités (audit) ────────────────────────────────
$stmtAudit = $pdo->query("
    SELECT * FROM audit_log ORDER BY cree_le DESC LIMIT 8
");
$dernieresActivites = $stmtAudit->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="adm-section-title">📊 Vue d'ensemble de la plateforme</div>
<div class="adm-section-sub">Statistiques globales en temps réel</div>

<!-- Stats principales -->
<div class="adm-stat-grid">
    <div class="adm-stat accent-blue">
        <div class="adm-stat-label">Patients inscrits</div>
        <div class="adm-stat-value"><?= $nbPatients ?></div>
        <div class="adm-stat-sub">+<?= $nbNouveauxPatients ?> ce mois</div>
    </div>
    <div class="adm-stat accent-blue">
        <div class="adm-stat-label">Médecins</div>
        <div class="adm-stat-value"><?= $nbMedecins ?></div>
        <div class="adm-stat-sub">comptes actifs</div>
    </div>
    <div class="adm-stat accent-green">
        <div class="adm-stat-label">Patients actifs (7j)</div>
        <div class="adm-stat-value"><?= $nbActifs7j ?></div>
        <div class="adm-stat-sub">
            <?= $nbPatients > 0 ? round($nbActifs7j / $nbPatients * 100) : 0 ?>% de la base
        </div>
    </div>
    <div class="adm-stat accent-amber">
        <div class="adm-stat-label">Mesures totales</div>
        <div class="adm-stat-value"><?= number_format($nbMesures, 0, ',', ' ') ?></div>
        <div class="adm-stat-sub">depuis le lancement</div>
    </div>
    <div class="adm-stat <?= $nbAlertes > 0 ? 'accent-red' : 'accent-green' ?>">
        <div class="adm-stat-label">Alertes non lues</div>
        <div class="adm-stat-value"><?= $nbAlertes ?></div>
        <div class="adm-stat-sub">tous patients confondus</div>
    </div>
    <div class="adm-stat">
        <div class="adm-stat-label">Messages échangés</div>
        <div class="adm-stat-value"><?= $nbMessages ?></div>
        <div class="adm-stat-sub"><?= $nbOrdos ?> ordonnances émises</div>
    </div>
</div>

<!-- Graphique activité -->
<div class="adm-card" style="margin-bottom:20px">
    <h3 style="font-size:14px;font-weight:600;margin-bottom:4px;color:var(--adm-txt)">
        📈 Activité plateforme — 30 derniers jours
    </h3>
    <p style="font-size:12px;color:var(--adm-txt2);margin-bottom:16px">
        Nouvelles inscriptions et mesures saisies par jour
    </p>
    <canvas id="activiteChart" height="80"></canvas>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">

    <!-- Répartition type diabète -->
    <div class="adm-card">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:4px;color:var(--adm-txt)">
            🥧 Répartition par type de diabète
        </h3>
        <canvas id="typesChart" height="200" style="margin-top:14px"></canvas>
    </div>

    <!-- Top médecins -->
    <div class="adm-card">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:14px;color:var(--adm-txt)">
            🏆 Top médecins par patients suivis
        </h3>
        <?php if (empty($topMedecins)): ?>
            <p style="color:var(--adm-txt2);font-size:13px">Aucun médecin enregistré.</p>
        <?php else: ?>
            <?php foreach ($topMedecins as $i => $m): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:9px 0;
                        border-bottom:1px solid var(--adm-border)">
                <span style="font-size:13px;font-weight:700;color:var(--adm-accent);width:20px">
                    #<?= $i + 1 ?>
                </span>
                <span style="flex:1;font-size:13px;color:var(--adm-txt)">
                    Dr <?= esc($m['prenom'] . ' ' . $m['nom']) ?>
                </span>
                <span class="adm-badge adm-badge-blue"><?= $m['nb_patients'] ?> patients</span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Dernières activités -->
<div class="adm-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <h3 style="font-size:14px;font-weight:600;color:var(--adm-txt)">🕐 Dernières activités</h3>
        <a href="/admin/audit.php" class="adm-btn adm-btn-secondary adm-btn-sm">Voir tout →</a>
    </div>
    <?php if (empty($dernieresActivites)): ?>
        <p style="color:var(--adm-txt2);font-size:13px">Aucune activité enregistrée.</p>
    <?php else: ?>
        <table class="adm-table">
            <?php foreach ($dernieresActivites as $a): ?>
            <tr>
                <td style="width:90px">
                    <span class="adm-badge adm-badge-<?=
                        $a['acteur_role']==='admin' ? 'blue' : ($a['acteur_role']==='medecin' ? 'green' : 'gray')
                    ?>"><?= esc($a['acteur_role']) ?></span>
                </td>
                <td><?= esc($a['action']) ?></td>
                <td style="color:var(--adm-txt2)"><?= esc($a['details'] ?? '—') ?></td>
                <td style="width:120px;color:var(--adm-txt2);text-align:right">
                    <?= (new DateTime($a['cree_le']))->format('d/m H\hi') ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#9CA3B5';
Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";

// ── Activité (inscriptions + mesures) ─────────────────────────
const joursInscr = <?= json_encode(array_column($inscriptions, 'jour')) ?>;
const nbInscr    = <?= json_encode(array_column($inscriptions, 'nb')) ?>;
const joursMes   = <?= json_encode(array_column($activiteMesures, 'jour')) ?>;
const nbMes      = <?= json_encode(array_column($activiteMesures, 'nb')) ?>;

// Fusionner les dates des deux séries
const allDates = [...new Set([...joursInscr, ...joursMes])].sort();
const mapInscr  = Object.fromEntries(joursInscr.map((j,i) => [j, nbInscr[i]]));
const mapMes    = Object.fromEntries(joursMes.map((j,i) => [j, nbMes[i]]));

new Chart(document.getElementById('activiteChart'), {
    data: {
        labels: allDates.map(d => new Date(d).toLocaleDateString('fr-FR', {day:'2-digit',month:'2-digit'})),
        datasets: [
            {
                type: 'bar', label: 'Mesures',
                data: allDates.map(d => mapMes[d] || 0),
                backgroundColor: 'rgba(91,141,239,.4)', borderRadius: 3, yAxisID: 'y1',
            },
            {
                type: 'line', label: 'Inscriptions',
                data: allDates.map(d => mapInscr[d] || 0),
                borderColor: '#2DD4A7', backgroundColor: 'transparent',
                borderWidth: 2, pointRadius: 3, tension: .3, yAxisID: 'y2',
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { labels: { boxWidth: 10 } } },
        scales: {
            y1: { position:'left',  grid:{color:'#2D3142'}, ticks:{color:'#9CA3B5'} },
            y2: { position:'right', grid:{display:false}, ticks:{color:'#9CA3B5',stepSize:1} },
            x:  { grid:{display:false}, ticks:{color:'#9CA3B5'} }
        }
    }
});

// ── Répartition types diabète ─────────────────────────────────
new Chart(document.getElementById('typesChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($typesDiabete, 'type_diabete')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($typesDiabete, 'nb')) ?>,
            backgroundColor: ['#5B8DEF','#2DD4A7','#FBBF24','#A78BFA','#F25C5C'],
            borderWidth: 0,
        }]
    },
    options: {
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, color: '#9CA3B5' } } },
        cutout: '60%'
    }
});
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
