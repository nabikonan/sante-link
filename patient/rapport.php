<?php
$pageTitle = 'Exporter mon rapport — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idPatient = (int) $_SESSION['user_id'];
$pdo       = getDB();

// Récupérer quelques stats pour l'aperçu
$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*) AS nb_total,
        MIN(date_heure) AS premiere,
        MAX(date_heure) AS derniere
    FROM mesure_glycemie
    WHERE id_patient = :id
");
$stmtStats->execute([':id' => $idPatient]);
$stats = $stmtStats->fetch();

// Vérifier que Python + ReportLab sont disponibles
$pythonOk  = !empty(shell_exec('python3 --version 2>/dev/null'));
$reportOk  = !empty(shell_exec('python3 -c "import reportlab" 2>/dev/null') !== false);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>📄 Exporter mon rapport PDF</h1>
</div>

<?= flashMessage() ?>

<!-- Aperçu des données disponibles -->
<div class="section-card" style="max-width:580px;margin-bottom:20px">
    <h3 class="section-title">📊 Données disponibles</h3>
    <?php if ($stats['nb_total'] == 0): ?>
        <div class="alert alert-warning">
            Aucune mesure enregistrée. Commencez par saisir quelques mesures pour générer un rapport.
        </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px">
        <div class="stat-card">
            <div class="stat-label">Total mesures</div>
            <div class="stat-value" style="font-size:22px"><?= $stats['nb_total'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Première mesure</div>
            <div class="stat-value" style="font-size:14px;line-height:1.3">
                <?= (new DateTime($stats['premiere']))->format('d/m/Y') ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Dernière mesure</div>
            <div class="stat-value" style="font-size:14px;line-height:1.3">
                <?= (new DateTime($stats['derniere']))->format('d/m/Y') ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Formulaire d'export -->
<div class="form-card" style="max-width:580px">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:4px">Générer le rapport PDF</h3>
    <p class="text-muted" style="font-size:12px;margin-bottom:20px">
        Le rapport inclut : résumé statistique, graphique d'évolution,
        statistiques par contexte, détail des mesures et traitements en cours.
    </p>

    <?php if (!$pythonOk): ?>
        <div class="alert alert-danger">
            ⚠️ Python 3 n'est pas installé ou introuvable. Le PDF ne peut pas être généré.<br>
            <a href="https://www.python.org/downloads/" target="_blank">Télécharger Python →</a>
        </div>
    <?php endif; ?>

    <!-- Sélection de la période -->
    <div style="margin-bottom:20px">
        <label style="font-size:12px;font-weight:600;color:var(--txt2);display:block;margin-bottom:8px">
            Période du rapport
        </label>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px" id="periodeSelector">
            <?php foreach ([
                7  => ['label' => '7 jours',  'sub' => 'Semaine courante'],
                30 => ['label' => '30 jours', 'sub' => 'Mois courant'],
                90 => ['label' => '90 jours', 'sub' => 'Trimestre'],
            ] as $jours => $info): ?>
            <label class="periode-card <?= $jours === 30 ? 'selected' : '' ?>"
                   onclick="selectPeriode(<?= $jours ?>)">
                <input type="radio" name="periode" value="<?= $jours ?>"
                       <?= $jours === 30 ? 'checked' : '' ?> style="display:none">
                <div class="periode-jours"><?= $info['label'] ?></div>
                <div class="periode-sub"><?= $info['sub'] ?></div>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Ce que contient le rapport -->
    <div class="info-banner info-green" style="margin-bottom:20px">
        <strong>📋 Contenu du rapport :</strong><br>
        ✅ Page de garde avec infos patient et médecin référent<br>
        ✅ Tableau de statistiques (moyenne, min, max, % hors cible)<br>
        ✅ Graphique d'évolution de la glycémie<br>
        ✅ Statistiques détaillées par contexte (à jeun, post-repas…)<br>
        ✅ Tableau des 30 dernières mesures avec statut<br>
        ✅ Liste des traitements actifs
    </div>

    <!-- Bouton télécharger -->
    <div id="downloadSection">
        <a id="downloadBtn"
           href="/export/export_pdf.php?periode=30"
           class="btn-primary btn-full"
           style="display:flex;align-items:center;justify-content:center;gap:8px;padding:13px"
           onclick="return confirmerExport(this)">
            📥 Télécharger le rapport PDF
        </a>
        <p class="text-muted" style="font-size:11px;text-align:center;margin-top:8px">
            La génération prend environ 5 à 15 secondes selon le nombre de mesures.
        </p>
    </div>
</div>

<!-- Liens vers historique et dashboard -->
<div style="margin-top:16px;display:flex;gap:10px">
    <a href="/patient/dashboard.php"  class="btn-secondary">← Dashboard</a>
    <a href="/patient/historique.php" class="btn-secondary">📈 Historique</a>
</div>

<style>
.periode-card {
    border: 2px solid var(--border);
    border-radius: 10px;
    padding: 14px 10px;
    text-align: center;
    cursor: pointer;
    transition: all .15s;
    background: var(--bg2);
}
.periode-card:hover { border-color: var(--green); background: var(--green-l); }
.periode-card.selected { border-color: var(--green); background: var(--green-l); }
.periode-jours { font-size: 18px; font-weight: 700; color: var(--green-d); }
.periode-sub   { font-size: 11px; color: var(--txt2); margin-top: 3px; }
</style>

<script>
function selectPeriode(jours) {
    document.querySelectorAll('.periode-card').forEach(c => c.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    document.querySelector(`input[value="${jours}"]`).checked = true;
    document.getElementById('downloadBtn').href = `/export/export_pdf.php?periode=${jours}`;
}

function confirmerExport(btn) {
    btn.textContent = '⏳ Génération en cours…';
    btn.style.opacity = '0.7';
    btn.style.pointerEvents = 'none';
    // Réactiver après 20s (au cas où)
    setTimeout(() => {
        btn.innerHTML = '📥 Télécharger le rapport PDF';
        btn.style.opacity = '1';
        btn.style.pointerEvents = '';
    }, 20000);
    return true;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
