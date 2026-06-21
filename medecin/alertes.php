<?php
$pageTitle = 'Alertes patients — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('medecin');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idMedecin = (int) $_SESSION['user_id'];
$pdo       = getDB();

// ── Marquer une alerte comme lue ─────────────────────────────
if (isset($_GET['lue']) && ctype_digit($_GET['lue'])) {
    // Vérifier que l'alerte concerne un patient de ce médecin
    $checkAlerte = $pdo->prepare("
        SELECT COUNT(*) FROM alerte a
        JOIN   suivi s ON s.id_patient = a.id_patient
        WHERE  a.id_alerte = :ia AND s.id_medecin = :im
    ");
    $checkAlerte->execute([':ia' => (int)$_GET['lue'], ':im' => $idMedecin]);
    if ($checkAlerte->fetchColumn()) {
        $pdo->prepare("UPDATE alerte SET statut = 'Lue' WHERE id_alerte = :id")
            ->execute([':id' => (int)$_GET['lue']]);
    }
    redirect('/medecin/alertes.php', 'Alerte marquée comme lue.');
}

// ── Toutes lire ───────────────────────────────────────────────
if (isset($_GET['tout_lire'])) {
    $pdo->prepare("
        UPDATE alerte a
        JOIN   suivi s ON s.id_patient = a.id_patient
        SET    a.statut = 'Lue'
        WHERE  s.id_medecin = :im AND a.statut = 'Non lue'
    ")->execute([':im' => $idMedecin]);
    redirect('/medecin/alertes.php', 'Toutes les alertes ont été marquées comme lues.');
}

// ── Filtres ───────────────────────────────────────────────────
$filtreStatut = in_array($_GET['statut'] ?? '', ['Non lue', 'Lue']) ? $_GET['statut'] : null;
$filtreType   = trim($_GET['type'] ?? '');

$whereClauses = ['s.id_medecin = :im'];
$params       = [':im' => $idMedecin];

if ($filtreStatut) {
    $whereClauses[] = 'a.statut = :statut';
    $params[':statut'] = $filtreStatut;
}
if ($filtreType) {
    $whereClauses[] = 'a.type_alerte = :type';
    $params[':type'] = $filtreType;
}

$where = implode(' AND ', $whereClauses);

$stmt = $pdo->prepare("
    SELECT a.id_alerte, a.type_alerte, a.message, a.statut, a.date_heure,
           p.id_patient, p.nom, p.prenom,
           m.valeur_glycemie, m.contexte
    FROM   alerte a
    JOIN   patient p ON p.id_patient = a.id_patient
    JOIN   suivi   s ON s.id_patient = a.id_patient
    LEFT   JOIN mesure_glycemie m ON m.id_mesure = a.id_mesure
    WHERE  $where
    ORDER  BY a.statut = 'Non lue' DESC, a.date_heure DESC
    LIMIT  100
");
$stmt->execute($params);
$alertes = $stmt->fetchAll();

// Compter les non lues
$stmtCount = $pdo->prepare("
    SELECT COUNT(*) FROM alerte a
    JOIN suivi s ON s.id_patient = a.id_patient
    WHERE s.id_medecin = :im AND a.statut = 'Non lue'
");
$stmtCount->execute([':im' => $idMedecin]);
$nbNonLues = (int) $stmtCount->fetchColumn();

// Types d'alertes pour le filtre
$typesAlertes = ['Hypoglycemie', 'Hyperglycemie', 'Hyperglycemie severe'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>🔔 Alertes patients</h1>
    <?php if ($nbNonLues > 0): ?>
        <a href="?tout_lire=1" class="btn-secondary"
           onclick="return confirm('Marquer toutes les alertes comme lues ?')">
            Tout marquer lu (<?= $nbNonLues ?>)
        </a>
    <?php endif; ?>
</div>

<?= flashMessage() ?>

<!-- Filtres -->
<div class="filter-bar">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <select name="statut" onchange="this.form.submit()">
            <option value="">Tous les statuts</option>
            <option value="Non lue" <?= $filtreStatut === 'Non lue' ? 'selected' : '' ?>>Non lues</option>
            <option value="Lue"     <?= $filtreStatut === 'Lue'     ? 'selected' : '' ?>>Lues</option>
        </select>
        <select name="type" onchange="this.form.submit()">
            <option value="">Tous les types</option>
            <?php foreach ($typesAlertes as $t): ?>
                <option value="<?= $t ?>" <?= $filtreType === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($filtreStatut || $filtreType): ?>
            <a href="/medecin/alertes.php" class="btn-secondary btn-sm">Réinitialiser</a>
        <?php endif; ?>
    </form>
</div>

<!-- Statistiques rapides -->
<div class="stats-row" style="margin-bottom:20px">
    <div class="stat-card <?= $nbNonLues > 0 ? 'stat-danger' : 'stat-success' ?>">
        <div class="stat-value"><?= $nbNonLues ?></div>
        <div class="stat-label">Non lue<?= $nbNonLues > 1 ? 's' : '' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= count($alertes) ?></div>
        <div class="stat-label">Total affiché<?= count($alertes) > 1 ? 's' : '' ?></div>
    </div>
</div>

<!-- Liste alertes -->
<div class="section-card">
    <?php if (empty($alertes)): ?>
        <div class="empty-state">
            <div style="font-size:3rem">✅</div>
            <p>Aucune alerte<?= $filtreStatut || $filtreType ? ' pour ce filtre' : '' ?>.</p>
        </div>
    <?php else: ?>
        <?php foreach ($alertes as $a):
            $isNew   = $a['statut'] === 'Non lue';
            $iconMap = [
                'Hypoglycemie'        => '🔴',
                'Hyperglycemie'       => '🟠',
                'Hyperglycemie severe'=> '🚨',
            ];
            $icon = $iconMap[$a['type_alerte']] ?? '⚠️';
        ?>
        <div class="alerte-card <?= $isNew ? 'alerte-new' : 'alerte-lue' ?>">
            <div class="alerte-icon"><?= $icon ?></div>
            <div class="alerte-body">
                <div class="alerte-patient">
                    <a href="/medecin/patient_detail.php?id=<?= $a['id_patient'] ?>">
                        <?= esc($a['prenom'] . ' ' . $a['nom']) ?>
                    </a>
                    <span class="badge <?= $isNew ? 'badge-danger' : 'badge-gray' ?>">
                        <?= $isNew ? 'Nouvelle' : 'Lue' ?>
                    </span>
                    <span class="badge badge-warning"><?= esc($a['type_alerte']) ?></span>
                </div>
                <div class="alerte-msg"><?= esc($a['message']) ?></div>
                <?php if ($a['valeur_glycemie']): ?>
                    <div class="alerte-meta">
                        Mesure : <strong><?= $a['valeur_glycemie'] ?> g/L</strong>
                        <?= $a['contexte'] ? '· ' . esc($a['contexte']) : '' ?>
                    </div>
                <?php endif; ?>
                <div class="alerte-date text-muted">
                    <?= dateFR($a['date_heure']) ?>
                </div>
            </div>
            <?php if ($isNew): ?>
            <div style="margin-left:auto;padding-left:12px">
                <a href="?lue=<?= $a['id_alerte'] ?><?= $filtreStatut ? '&statut=' . urlencode($filtreStatut) : '' ?>"
                   class="btn-secondary btn-sm">Marquer lu</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
