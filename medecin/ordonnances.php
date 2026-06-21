<?php
$pageTitle = 'Ordonnances — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('medecin');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idMedecin = (int) $_SESSION['user_id'];
$pdo       = getDB();
$erreur    = '';

// ── Créer une ordonnance ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    $idPatient  = (int) ($_POST['id_patient']  ?? 0);
    $contenu    = trim($_POST['contenu']        ?? '');
    $date_ordo  = $_POST['date_ordonnance']     ?? '';

    // Vérifier que le patient appartient bien à ce médecin
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM patient p
        JOIN   suivi s ON s.id_patient = p.id_patient
        WHERE  p.id_patient = :pid AND s.id_medecin = :mid
    ");
    $check->execute([':pid' => $idPatient, ':mid' => $idMedecin]);

    if (!$idPatient || !$contenu || !$date_ordo) {
        $erreur = 'Veuillez remplir tous les champs.';
    } elseif (!DateTime::createFromFormat('Y-m-d', $date_ordo)) {
        $erreur = 'Date invalide.';
    } elseif (!$check->fetchColumn()) {
        $erreur = 'Patient non autorisé.';
    } elseif (strlen($contenu) > 5000) {
        $erreur = 'Contenu trop long (max 5000 caractères).';
    } else {
        $pdo->prepare("
            INSERT INTO ordonnance (id_medecin, id_patient, contenu, date_ordonnance)
            VALUES (:im, :ip, :co, :do)
        ")->execute([
            ':im' => $idMedecin,
            ':ip' => $idPatient,
            ':co' => $contenu,
            ':do' => $date_ordo,
        ]);
        redirect('/medecin/ordonnances.php', 'Ordonnance créée avec succès !');
    }
}

// ── Supprimer une ordonnance (appartenant au médecin) ─────────
if (isset($_GET['supprimer']) && ctype_digit($_GET['supprimer'])) {
    $stmtDel = $pdo->prepare("
        DELETE FROM ordonnance
        WHERE  id_ordonnance = :id AND id_medecin = :mid
    ");
    $stmtDel->execute([':id' => (int)$_GET['supprimer'], ':mid' => $idMedecin]);
    redirect('/medecin/ordonnances.php', 'Ordonnance supprimée.');
}

// ── Liste des patients suivis ─────────────────────────────────
$stmtPats = $pdo->prepare("
    SELECT p.id_patient, p.nom, p.prenom
    FROM   patient p
    JOIN   suivi s ON s.id_patient = p.id_patient
    WHERE  s.id_medecin = :mid
    ORDER  BY p.nom, p.prenom
");
$stmtPats->execute([':mid' => $idMedecin]);
$patients = $stmtPats->fetchAll();

// ── Ordonnances existantes ────────────────────────────────────
$stmtOrdos = $pdo->prepare("
    SELECT o.id_ordonnance, o.contenu, o.date_ordonnance,
           p.nom, p.prenom, p.id_patient
    FROM   ordonnance o
    JOIN   patient p ON p.id_patient = o.id_patient
    WHERE  o.id_medecin = :mid
    ORDER  BY o.date_ordonnance DESC
    LIMIT  50
");
$stmtOrdos->execute([':mid' => $idMedecin]);
$ordonnances = $stmtOrdos->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>📋 Ordonnances</h1>
    <button class="btn-primary" onclick="toggleForm()">+ Nouvelle ordonnance</button>
</div>

<!-- Formulaire -->
<div id="formOrdo" style="display:none;margin-bottom:20px">
    <div class="form-card" style="max-width:100%">
        <h3 style="margin-bottom:16px;font-size:15px">Nouvelle ordonnance</h3>
        <?php if ($erreur): ?>
            <div class="alert alert-danger"><?= esc($erreur) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Patient *</label>
                    <select name="id_patient" required>
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= $p['id_patient'] ?>"
                                <?= (int)($_POST['id_patient'] ?? 0) === (int)$p['id_patient'] ? 'selected' : '' ?>>
                                <?= esc($p['prenom'] . ' ' . $p['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date de l'ordonnance *</label>
                    <input type="date" name="date_ordonnance"
                           value="<?= esc($_POST['date_ordonnance'] ?? date('Y-m-d')) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Contenu de l'ordonnance *</label>
                <textarea name="contenu" rows="6" maxlength="5000" required
                    placeholder="Médicaments, dosages, instructions..."
                    ><?= esc($_POST['contenu'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Enregistrer</button>
                <button type="button" class="btn-secondary" onclick="toggleForm()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- Liste des ordonnances -->
<div class="section-card">
    <h3 class="section-title">Ordonnances émises (<?= count($ordonnances) ?>)</h3>
    <?php if (empty($ordonnances)): ?>
        <p class="text-muted text-center">Aucune ordonnance enregistrée.</p>
    <?php else: ?>
        <?php foreach ($ordonnances as $o): ?>
        <div class="ordo-card">
            <div class="ordo-header">
                <div>
                    <strong><?= esc($o['prenom'] . ' ' . $o['nom']) ?></strong>
                    <span class="text-muted">· <?= (new DateTime($o['date_ordonnance']))->format('d/m/Y') ?></span>
                </div>
                <div style="display:flex;gap:8px">
                    <a href="/medecin/patient_detail.php?id=<?= $o['id_patient'] ?>"
                       class="btn-secondary btn-sm">Voir patient</a>
                    <a href="?supprimer=<?= $o['id_ordonnance'] ?>"
                       class="btn-danger btn-sm"
                       onclick="return confirm('Supprimer cette ordonnance définitivement ?')">
                        Supprimer
                    </a>
                </div>
            </div>
            <div class="ordo-contenu"><?= esc($o['contenu']) ?></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function toggleForm() {
    const f = document.getElementById('formOrdo');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
<?php if ($erreur): ?>
document.getElementById('formOrdo').style.display = 'block';
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
