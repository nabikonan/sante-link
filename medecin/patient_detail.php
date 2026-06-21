<?php
$pageTitle = 'Dossier patient — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('medecin');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idMedecin = (int) $_SESSION['user_id'];
$idPatient  = (int) ($_GET['id'] ?? 0);
$pdo = getDB();

// Vérifier que ce patient appartient bien au médecin
$stmtCheck = $pdo->prepare("
    SELECT COUNT(*) FROM suivi
    WHERE id_medecin = :m AND id_patient = :p AND actif = 1
");
$stmtCheck->execute([':m' => $idMedecin, ':p' => $idPatient]);
if (!$stmtCheck->fetchColumn()) {
    redirect('/medecin/dashboard.php', 'Accès refusé : patient non trouvé.', 'danger');
}

// Infos patient
$stmtP = $pdo->prepare("SELECT * FROM patient WHERE id_patient = :id");
$stmtP->execute([':id' => $idPatient]);
$patient = $stmtP->fetch();
if (!$patient) redirect('/medecin/dashboard.php', 'Patient introuvable.', 'danger');

$pageTitle = 'Dossier de ' . $patient['prenom'] . ' ' . $patient['nom'] . ' — SanteLink';

// Statistiques 30 jours
$stmtStats = $pdo->prepare("
    SELECT
        ROUND(AVG(valeur_glycemie), 2) AS moyenne,
        ROUND(MIN(valeur_glycemie), 2) AS min_val,
        ROUND(MAX(valeur_glycemie), 2) AS max_val,
        COUNT(*)                        AS nb_mesures,
        SUM(valeur_glycemie < 0.70)     AS nb_hypo,
        SUM(valeur_glycemie > 1.80)     AS nb_hyper
    FROM mesure_glycemie
    WHERE id_patient = :id
      AND date_heure >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmtStats->execute([':id' => $idPatient]);
$stats = $stmtStats->fetch();

// Mesures 30 derniers jours pour graphique
$stmtM = $pdo->prepare("
    SELECT valeur_glycemie, date_heure, contexte
    FROM   mesure_glycemie
    WHERE  id_patient = :id
      AND  date_heure >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER  BY date_heure ASC
");
$stmtM->execute([':id' => $idPatient]);
$mesures = $stmtM->fetchAll();
$chartLabels  = json_encode(array_column($mesures, 'date_heure'));
$chartValeurs = json_encode(array_column($mesures, 'valeur_glycemie'));

// Alertes récentes non traitées
$stmtA = $pdo->prepare("
    SELECT a.type_alerte, a.message, a.date_heure, m.valeur_glycemie
    FROM   alerte a
    JOIN   mesure_glycemie m ON m.id_mesure = a.id_mesure
    WHERE  a.id_patient = :id AND a.statut = 'Non lue'
    ORDER  BY a.date_heure DESC LIMIT 5
");
$stmtA->execute([':id' => $idPatient]);
$alertes = $stmtA->fetchAll();

// Traitements actifs
$stmtT = $pdo->prepare("
    SELECT t.nom_medicament, t.dosage, p.frequence, p.heure_prise, p.date_debut
    FROM   prescription p
    JOIN   traitement t ON t.id_traitement = p.id_traitement
    WHERE  p.id_patient = :id AND p.actif = 1
");
$stmtT->execute([':id' => $idPatient]);
$traitements = $stmtT->fetchAll();

// Ordonnances
$stmtO = $pdo->prepare("
    SELECT o.id_ordonnance, o.date_emission, o.observations,
           GROUP_CONCAT(CONCAT(t.nom_medicament,' ',t.dosage,' — ',ol.posologie)
                        SEPARATOR ' | ') AS medicaments
    FROM   ordonnance o
    LEFT   JOIN ordonnance_ligne ol ON ol.id_ordonnance = o.id_ordonnance
    LEFT   JOIN traitement t        ON t.id_traitement  = ol.id_traitement
    WHERE  o.id_patient = :id AND o.id_medecin = :mid
    GROUP  BY o.id_ordonnance
    ORDER  BY o.date_emission DESC
    LIMIT 5
");
$stmtO->execute([':id' => $idPatient, ':mid' => $idMedecin]);
$ordonnances = $stmtO->fetchAll();

// Dernière mesure + statut
$derniere = $mesures ? end($mesures) : null;
$statutDernier = $derniere
    ? statutGlycemie((float)$derniere['valeur_glycemie'], $derniere['contexte'])
    : null;

require_once __DIR__ . '/../includes/header.php';
?>

<!-- En-tête dossier -->
<div style="display:flex;align-items:center;gap:16px;margin-bottom:24px">
    <a href="/medecin/dashboard.php" class="btn-secondary btn-sm">← Retour</a>
    <div style="width:52px;height:52px;border-radius:50%;background:var(--green-l);
                color:var(--green-d);display:flex;align-items:center;justify-content:center;
                font-size:18px;font-weight:700;flex-shrink:0">
        <?= strtoupper(substr($patient['prenom'],0,1) . substr($patient['nom'],0,1)) ?>
    </div>
    <div>
        <h1 style="font-size:22px;font-weight:700">
            <?= esc($patient['prenom'] . ' ' . $patient['nom']) ?>
        </h1>
        <div class="text-muted" style="font-size:13px">
            <?= esc($patient['type_diabete']) ?>
            · Né(e) le <?= (new DateTime($patient['date_naissance']))->format('d/m/Y') ?>
            · <?= esc($patient['telephone'] ?? 'Pas de téléphone') ?>
        </div>
    </div>
    <?php if ($statutDernier): ?>
        <span class="badge badge-<?= $statutDernier['couleur'] ?>" style="margin-left:auto">
            <?= esc($statutDernier['message']) ?>
        </span>
    <?php endif; ?>
    <a href="/medecin/ordonnances.php?id_patient=<?= $idPatient ?>"
       class="btn-primary" style="margin-left:8px">📄 Nouvelle ordonnance</a>
    <!-- Export PDF -->
    <div style="position:relative;display:inline-block;margin-left:8px" id="exportMenu">
        <button class="btn-secondary"
                onclick="document.getElementById('exportDropdown').classList.toggle('show')">
            ⬇️ Exporter PDF ▾
        </button>
        <div id="exportDropdown" class="dropdown-menu" style="display:none">
            <a href="/export/export_pdf.php?patient=<?= $idPatient ?>&periode=7"
               class="dropdown-item" onclick="startExport(this)">📄 Rapport 7 jours</a>
            <a href="/export/export_pdf.php?patient=<?= $idPatient ?>&periode=30"
               class="dropdown-item" onclick="startExport(this)">📄 Rapport 30 jours</a>
            <a href="/export/export_pdf.php?patient=<?= $idPatient ?>&periode=90"
               class="dropdown-item" onclick="startExport(this)">📄 Rapport 90 jours</a>
        </div>
    </div>
</div>

<style>
.dropdown-menu   { position:absolute;right:0;top:calc(100% + 4px);background:#fff;
                   border:1px solid var(--border);border-radius:8px;
                   box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:50;min-width:180px; }
.dropdown-menu.show { display:block !important; }
.dropdown-item   { display:block;padding:9px 14px;font-size:13px;color:var(--txt);
                   text-decoration:none;border-bottom:1px solid var(--border); }
.dropdown-item:last-child { border-bottom:none; }
.dropdown-item:hover { background:var(--green-l);color:var(--green-d); }
</style>
<script>
function startExport(el) {
    el.textContent = '⏳ Génération…';
    setTimeout(() => location.reload(), 25000);
}
// Fermer dropdown en cliquant ailleurs
document.addEventListener('click', e => {
    if (!document.getElementById('exportMenu').contains(e.target)) {
        document.getElementById('exportDropdown').classList.remove('show');
    }
});
</script>

<!-- Stats 30 jours -->
<div class="cards-grid">
    <div class="stat-card">
        <div class="stat-label">Moyenne 30j</div>
        <div class="stat-value"><?= $stats['moyenne'] ?? '—' ?> <?= $stats['moyenne'] ? 'g/L':'' ?></div>
        <div class="stat-sub"><?= $stats['nb_mesures'] ?> mesures enregistrées</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Min / Max 30j</div>
        <div class="stat-value"><?= $stats['min_val'] ?? '—' ?> / <?= $stats['max_val'] ?? '—' ?></div>
        <div class="stat-sub">g/L sur 30 jours</div>
    </div>
    <div class="stat-card <?= ($stats['nb_hypo'] ?? 0) > 0 ? 'card-alert' : '' ?>">
        <div class="stat-label">Épisodes hypoglycémie</div>
        <div class="stat-value <?= ($stats['nb_hypo'] ?? 0) > 0 ? 'text-danger' : '' ?>">
            <?= $stats['nb_hypo'] ?? 0 ?>
        </div>
        <div class="stat-sub">sur 30 jours</div>
    </div>
    <div class="stat-card <?= ($stats['nb_hyper'] ?? 0) > 2 ? 'card-alert' : '' ?>">
        <div class="stat-label">Épisodes hyperglycémie</div>
        <div class="stat-value <?= ($stats['nb_hyper'] ?? 0) > 2 ? 'text-warning' : '' ?>">
            <?= $stats['nb_hyper'] ?? 0 ?>
        </div>
        <div class="stat-sub">sur 30 jours</div>
    </div>
</div>

<!-- Graphique -->
<div class="chart-card">
    <div class="chart-header">
        <h3>Évolution glycémique — 30 derniers jours</h3>
    </div>
    <?php if (empty($mesures)): ?>
        <p class="text-muted text-center">Aucune mesure sur cette période.</p>
    <?php else: ?>
        <canvas id="glycemieChart" height="70"></canvas>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

    <!-- Alertes récentes -->
    <div class="section-card">
        <h3 style="font-size:14px;font-weight:600;color:var(--txt2);
                   text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">
            🔔 Alertes récentes
        </h3>
        <?php if (empty($alertes)): ?>
            <p class="text-muted">✅ Aucune alerte active.</p>
        <?php else: ?>
            <?php foreach ($alertes as $a):
                $cls = in_array($a['type_alerte'],['Hypoglycemie','Hyperglycemie severe'])
                     ? 'danger' : 'warning';
            ?>
            <div class="alerte-card alerte-<?= $cls ?>" style="margin-bottom:8px">
                <div class="alerte-icon">
                    <?= $cls === 'danger' ? '🚨' : '⚠️' ?>
                </div>
                <div class="alerte-body">
                    <div class="alerte-titre"><?= esc($a['type_alerte']) ?></div>
                    <div class="alerte-msg"><?= esc($a['message']) ?></div>
                    <div class="alerte-meta text-muted"><?= dateFR($a['date_heure']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Traitements actifs -->
    <div class="section-card">
        <h3 style="font-size:14px;font-weight:600;color:var(--txt2);
                   text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">
            💊 Traitements actifs
        </h3>
        <?php if (empty($traitements)): ?>
            <p class="text-muted">Aucun traitement enregistré.</p>
        <?php else: ?>
            <?php foreach ($traitements as $t): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;
                        padding:9px 12px;background:var(--bg);border-radius:8px;
                        margin-bottom:7px;border:1px solid var(--border)">
                <div>
                    <div style="font-size:13px;font-weight:600">
                        <?= esc($t['nom_medicament']) ?>
                        <span style="font-size:11px;background:var(--blue-l);color:var(--blue-d);
                                     padding:1px 7px;border-radius:10px;margin-left:5px">
                            <?= esc($t['dosage']) ?>
                        </span>
                    </div>
                    <div class="text-muted" style="font-size:11px;margin-top:2px">
                        <?= esc($t['frequence']) ?>
                        <?= $t['heure_prise'] ? '· ' . esc($t['heure_prise']) : '' ?>
                    </div>
                </div>
                <span class="badge badge-success">Actif</span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Ordonnances -->
<div class="section-card">
    <h3 style="font-size:14px;font-weight:600;color:var(--txt2);
               text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">
        📄 Dernières ordonnances
    </h3>
    <?php if (empty($ordonnances)): ?>
        <p class="text-muted">Aucune ordonnance émise pour ce patient.</p>
    <?php else: ?>
        <?php foreach ($ordonnances as $o): ?>
        <div style="padding:12px 14px;background:var(--bg);border-radius:9px;
                    border:1px solid var(--border);margin-bottom:8px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <strong style="font-size:13px">
                    Ordonnance du <?= (new DateTime($o['date_emission']))->format('d/m/Y') ?>
                </strong>
                <span class="badge badge-gray">#<?= $o['id_ordonnance'] ?></span>
            </div>
            <?php if ($o['medicaments']): ?>
                <div class="text-muted" style="font-size:12px;margin-bottom:5px">
                    💊 <?= esc($o['medicaments']) ?>
                </div>
            <?php endif; ?>
            <?php if ($o['observations']): ?>
                <div style="font-size:12px;color:var(--txt2);font-style:italic">
                    📝 <?= esc($o['observations']) ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!empty($mesures)): ?>
<script>
const ctx = document.getElementById('glycemieChart').getContext('2d');
const rawLabels = <?= $chartLabels ?>;
const labels = rawLabels.map(d =>
    new Date(d).toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit'})
);
new Chart(ctx, {
    type: 'line',
    data: {
        labels,
        datasets: [{
            label: 'Glycémie (g/L)',
            data: <?= $chartValeurs ?>,
            borderColor: '#1D9E75',
            backgroundColor: 'rgba(29,158,117,.07)',
            borderWidth: 2.5,
            pointRadius: 3,
            pointBackgroundColor: '#1D9E75',
            tension: 0.3,
            fill: true,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: {
                min: 0.4, max: 2.5,
                ticks: { callback: v => v + ' g/L' },
                grid: { color: '#f0f0f0' }
            },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
