<?php
$pageTitle = 'Alertes — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idPatient = (int) $_SESSION['user_id'];
$pdo = getDB();

// Marquer comme lu si demandé
if (isset($_GET['lire']) && is_numeric($_GET['lire'])) {
    $pdo->prepare("UPDATE alerte SET statut='Lue' WHERE id_alerte=:id AND id_patient=:pid")
        ->execute([':id' => (int)$_GET['lire'], ':pid' => $idPatient]);
    redirect('/patient/alertes.php', 'Alerte marquée comme lue.');
}

// Récupérer toutes les alertes
$stmt = $pdo->prepare("
    SELECT a.*, m.valeur_glycemie, m.date_heure AS date_mesure
    FROM   alerte a
    JOIN   mesure_glycemie m ON m.id_mesure = a.id_mesure
    WHERE  a.id_patient = :id
    ORDER  BY a.date_heure DESC
    LIMIT 50
");
$stmt->execute([':id' => $idPatient]);
$alertes = $stmt->fetchAll();

$icones = [
    'Hypoglycemie'        => '🩸',
    'Hyperglycemie'       => '⚠️',
    'Hyperglycemie severe'=> '🚨',
    'Tendance anormale'   => '📈',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>🔔 Mes alertes</h1>
</div>

<?php if (empty($alertes)): ?>
    <div class="empty-state">✅ Aucune alerte — tout va bien !</div>
<?php else: ?>
    <?php foreach ($alertes as $a):
        $cls = in_array($a['type_alerte'], ['Hypoglycemie','Hyperglycemie severe'])
             ? 'danger' : 'warning';
        $lue = $a['statut'] !== 'Non lue';
    ?>
    <div class="alerte-card <?= $lue ? 'alerte-lue' : 'alerte-' . $cls ?>">
        <div class="alerte-icon"><?= $icones[$a['type_alerte']] ?? '⚠️' ?></div>
        <div class="alerte-body">
            <div class="alerte-titre"><?= esc($a['type_alerte']) ?></div>
            <div class="alerte-msg"><?= esc($a['message']) ?></div>
            <div class="alerte-meta text-muted">
                <?= dateFR($a['date_heure']) ?>
                · Mesure : <?= $a['valeur_glycemie'] ?> g/L
            </div>
        </div>
        <div class="alerte-actions">
            <?php if (!$lue): ?>
                <a href="?lire=<?= $a['id_alerte'] ?>"
                   class="btn-sm btn-secondary">Marquer lu</a>
            <?php else: ?>
                <span class="badge badge-gray">Lue</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
