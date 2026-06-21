<?php
$pageTitle = 'Historique — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idPatient = (int) $_SESSION['user_id'];
$pdo = getDB();

// Filtres
$mois = $_GET['mois'] ?? date('Y-m');
$debut = $mois . '-01';
$fin   = date('Y-m-t', strtotime($debut));

$stmt = $pdo->prepare("
    SELECT id_mesure, valeur_glycemie, date_heure, contexte, commentaire
    FROM   mesure_glycemie
    WHERE  id_patient = :id
      AND  DATE(date_heure) BETWEEN :debut AND :fin
    ORDER  BY date_heure DESC
");
$stmt->execute([':id' => $idPatient, ':debut' => $debut, ':fin' => $fin]);
$mesures = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>📋 Historique des mesures</h1>
    <form method="GET" class="filter-form">
        <input type="month" name="mois" value="<?= esc($mois) ?>">
        <button type="submit" class="btn-secondary">Filtrer</button>
    </form>
</div>

<div class="section-card">
    <?php if (empty($mesures)): ?>
        <p class="text-muted text-center">Aucune mesure pour cette période.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Heure</th>
                    <th>Valeur</th>
                    <th>Contexte</th>
                    <th>Statut</th>
                    <th>Commentaire</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mesures as $m):
                    $s = statutGlycemie((float)$m['valeur_glycemie'], $m['contexte']);
                    $dt = new DateTime($m['date_heure']);
                ?>
                <tr>
                    <td><?= $dt->format('d/m/Y') ?></td>
                    <td><?= $dt->format('H:i') ?></td>
                    <td><strong><?= $m['valeur_glycemie'] ?> g/L</strong></td>
                    <td><?= esc($m['contexte']) ?></td>
                    <td><span class="badge badge-<?= $s['couleur'] ?>"><?= esc($s['statut']) ?></span></td>
                    <td class="text-muted"><?= esc($m['commentaire'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
