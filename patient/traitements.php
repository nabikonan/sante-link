<?php
$pageTitle = 'Mes traitements — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idPatient = (int) $_SESSION['user_id'];
$pdo = getDB();
$erreur = '';

// ── Ajouter un traitement ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter') {
    verifierCsrf();

    $nom       = trim($_POST['nom_medicament'] ?? '');
    $dosage    = trim($_POST['dosage']         ?? '');
    $frequence = trim($_POST['frequence']      ?? '');
    $heure     = trim($_POST['heure_prise']    ?? '');
    $debut     = $_POST['date_debut']           ?? '';
    $fin       = $_POST['date_fin']             ?? '';

    if (!$nom || !$dosage || !$frequence || !$debut) {
        $erreur = 'Veuillez remplir tous les champs obligatoires.';
    } elseif (!DateTime::createFromFormat('Y-m-d', $debut)) {
        $erreur = 'Date de début invalide.';
    } elseif ($fin && !DateTime::createFromFormat('Y-m-d', $fin)) {
        $erreur = 'Date de fin invalide.';
    } elseif (strlen($nom) > 100 || strlen($dosage) > 50 || strlen($frequence) > 100) {
        $erreur = 'Valeur trop longue dans un des champs.';
    } else {
        // Chercher ou créer le médicament
        $stmtT = $pdo->prepare(
            "SELECT id_traitement FROM traitement
             WHERE nom_medicament = :nom AND dosage = :dos LIMIT 1"
        );
        $stmtT->execute([':nom' => $nom, ':dos' => $dosage]);
        $exist = $stmtT->fetchColumn();

        if (!$exist) {
            $pdo->prepare("INSERT INTO traitement (nom_medicament, dosage) VALUES (:n,:d)")
                ->execute([':n' => $nom, ':d' => $dosage]);
            $idTrait = (int) $pdo->lastInsertId();
        } else {
            $idTrait = (int) $exist;
        }

        $pdo->prepare("
            INSERT INTO prescription
                (id_patient, id_traitement, frequence, heure_prise, date_debut, date_fin, actif)
            VALUES (:ip, :it, :fr, :hr, :db, :df, 1)
        ")->execute([
            ':ip' => $idPatient,
            ':it' => $idTrait,
            ':fr' => $frequence,
            ':hr' => $heure ?: null,
            ':db' => $debut,
            ':df' => $fin ?: null,
        ]);
        redirect('/patient/traitements.php', 'Traitement ajouté avec succès !');
    }
}

// ── Désactiver un traitement ──────────────────────────────────
if (isset($_GET['stop']) && ctype_digit($_GET['stop'])) {
    // Vérifier que la prescription appartient bien à ce patient avant de la modifier
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM prescription
        WHERE id_prescription = :id AND id_patient = :pid AND actif = 1
    ");
    $stmtCheck->execute([':id' => (int)$_GET['stop'], ':pid' => $idPatient]);
    if ($stmtCheck->fetchColumn()) {
        $pdo->prepare("
            UPDATE prescription SET actif = 0, date_fin = CURDATE()
            WHERE id_prescription = :id AND id_patient = :pid
        ")->execute([':id' => (int)$_GET['stop'], ':pid' => $idPatient]);
        redirect('/patient/traitements.php', 'Traitement arrêté.');
    } else {
        redirect('/patient/traitements.php', 'Prescription introuvable.', 'danger');
    }
}

// ── Récupérer les traitements ─────────────────────────────────
$stmtActifs = $pdo->prepare("
    SELECT p.id_prescription, t.nom_medicament, t.dosage,
           p.frequence, p.heure_prise, p.date_debut, p.date_fin
    FROM   prescription p
    JOIN   traitement t ON t.id_traitement = p.id_traitement
    WHERE  p.id_patient = :id AND p.actif = 1
    ORDER  BY t.nom_medicament
");
$stmtActifs->execute([':id' => $idPatient]);
$actifs = $stmtActifs->fetchAll();

$stmtTermines = $pdo->prepare("
    SELECT p.id_prescription, t.nom_medicament, t.dosage,
           p.frequence, p.date_debut, p.date_fin
    FROM   prescription p
    JOIN   traitement t ON t.id_traitement = p.id_traitement
    WHERE  p.id_patient = :id AND p.actif = 0
    ORDER  BY p.date_fin DESC
    LIMIT 10
");
$stmtTermines->execute([':id' => $idPatient]);
$termines = $stmtTermines->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>💊 Mes traitements</h1>
    <button class="btn-primary" onclick="toggleForm()">+ Ajouter un traitement</button>
</div>

<!-- Formulaire ajout (masqué par défaut) -->
<div id="formAjout" style="display:none;margin-bottom:20px">
    <div class="form-card" style="max-width:100%">
        <h3 style="margin-bottom:16px;font-size:15px">Nouveau traitement</h3>
        <?php if ($erreur): ?>
            <div class="alert alert-danger"><?= esc($erreur) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter">
            <div class="form-row">
                <div class="form-group">
                    <label>Nom du médicament *</label>
                    <input type="text" name="nom_medicament"
                           value="<?= esc($_POST['nom_medicament'] ?? '') ?>"
                           placeholder="Ex : Metformine" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label>Dosage *</label>
                    <input type="text" name="dosage"
                           value="<?= esc($_POST['dosage'] ?? '') ?>"
                           placeholder="Ex : 500mg" maxlength="50" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Fréquence *</label>
                    <input type="text" name="frequence"
                           value="<?= esc($_POST['frequence'] ?? '') ?>"
                           placeholder="Ex : 2x/jour" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label>Heure(s) de prise</label>
                    <input type="text" name="heure_prise"
                           value="<?= esc($_POST['heure_prise'] ?? '') ?>"
                           placeholder="Ex : 08:00, 20:00" maxlength="100">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Date de début *</label>
                    <input type="date" name="date_debut"
                           value="<?= esc($_POST['date_debut'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label>Date de fin (optionnel)</label>
                    <input type="date" name="date_fin"
                           value="<?= esc($_POST['date_fin'] ?? '') ?>">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Enregistrer</button>
                <button type="button" class="btn-secondary" onclick="toggleForm()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- Traitements actifs -->
<div class="section-card">
    <h3 class="section-title">Actifs (<?= count($actifs) ?>)</h3>
    <?php if (empty($actifs)): ?>
        <p class="text-muted text-center">Aucun traitement actif enregistré.</p>
    <?php else: ?>
        <?php foreach ($actifs as $t): ?>
        <div class="traitement-card">
            <div class="trait-icon">💊</div>
            <div class="trait-body">
                <div class="trait-nom">
                    <?= esc($t['nom_medicament']) ?>
                    <span class="trait-dosage"><?= esc($t['dosage']) ?></span>
                </div>
                <div class="trait-detail">
                    📅 <?= esc($t['frequence']) ?>
                    <?= $t['heure_prise'] ? '· 🕐 ' . esc($t['heure_prise']) : '' ?>
                    · Depuis le <?= (new DateTime($t['date_debut']))->format('d/m/Y') ?>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <span class="badge badge-success">Actif</span>
                <a href="?stop=<?= $t['id_prescription'] ?>"
                   class="btn-secondary btn-sm"
                   onclick="return confirm('Arrêter ce traitement ?')">Arrêter</a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Traitements terminés -->
<?php if (!empty($termines)): ?>
<div class="section-card">
    <h3 class="section-title">Historique des traitements</h3>
    <?php foreach ($termines as $t): ?>
    <div class="traitement-card traitement-termine">
        <div class="trait-icon" style="opacity:.5">💊</div>
        <div class="trait-body">
            <div class="trait-nom text-muted">
                <?= esc($t['nom_medicament']) ?>
                <span class="trait-dosage"><?= esc($t['dosage']) ?></span>
            </div>
            <div class="trait-detail text-muted">
                Du <?= (new DateTime($t['date_debut']))->format('d/m/Y') ?>
                au <?= $t['date_fin']
                    ? (new DateTime($t['date_fin']))->format('d/m/Y')
                    : '—' ?>
                · <?= esc($t['frequence']) ?>
            </div>
        </div>
        <span class="badge badge-gray">Terminé</span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function toggleForm() {
    const f = document.getElementById('formAjout');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
<?php if ($erreur): ?>
document.getElementById('formAjout').style.display = 'block';
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
