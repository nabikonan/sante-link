<?php
$pageTitle = 'Saisir une mesure — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('patient');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$idPatient = (int) $_SESSION['user_id'];
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    $valeur      = (float) ($_POST['valeur_glycemie'] ?? 0);
    $date_heure  = trim($_POST['date_heure'] ?? '');
    $contexte    = $_POST['contexte'] ?? '';
    $commentaire = trim($_POST['commentaire'] ?? '');

    $contextes_valides = ['A jeun','Post-repas','Avant sport','Apres sport','Autre'];

    if ($valeur < 0.10 || $valeur > 6.00) {
        $erreur = 'Valeur glycémique invalide (entre 0.10 et 6.00 g/L).';
    } elseif (!$date_heure) {
        $erreur = 'Veuillez indiquer la date et l\'heure.';
    } elseif (!validerDatetime($date_heure)) {
        $erreur = 'Format de date/heure invalide.';
    } elseif (!in_array($contexte, $contextes_valides, true)) {
        $erreur = 'Contexte invalide.';
    } else {
        // Normaliser le format datetime pour MySQL
        $dt = validerDatetime($date_heure);
        $dateSQL = $dt->format('Y-m-d H:i:s');

        $stmt = getDB()->prepare("
            INSERT INTO mesure_glycemie
                (id_patient, valeur_glycemie, date_heure, contexte, commentaire)
            VALUES (:id, :val, :dh, :ctx, :com)
        ");
        $stmt->execute([
            ':id'  => $idPatient,
            ':val' => $valeur,
            ':dh'  => $dateSQL,
            ':ctx' => $contexte,
            ':com' => $commentaire ?: null,
        ]);
        $idMesure = (int) getDB()->lastInsertId();

        // Créer automatiquement une alerte si la valeur dépasse les seuils
        creerAlerteAutomatique($idPatient, $idMesure, $valeur, $contexte);

        redirect('/patient/dashboard.php', 'Mesure enregistrée avec succès !');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>➕ Nouvelle mesure glycémique</h1>
</div>

<div class="form-card">
    <div class="info-banner info-green">
        ℹ️ Seuils normaux : <strong>0.70–1.10 g/L à jeûn</strong>
        · <strong>&lt;1.40 g/L post-repas</strong>
        · Critique : &lt;0.70 ou &gt;2.00 g/L
    </div>

    <?php if ($erreur): ?>
        <div class="alert alert-danger"><?= esc($erreur) ?></div>
    <?php endif; ?>

    <form method="POST" id="formMesure">
        <?= csrfField() ?>
        <div class="form-group">
            <label for="valeur_glycemie">Valeur glycémique (g/L) *</label>
            <input type="number" id="valeur_glycemie" name="valeur_glycemie"
                   step="0.01" min="0.10" max="6.00"
                   value="<?= esc($_POST['valeur_glycemie'] ?? '') ?>"
                   placeholder="Ex : 1.12" required oninput="updatePreview()">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="date_heure">Date et heure *</label>
                <input type="datetime-local" id="date_heure" name="date_heure"
                       value="<?= esc($_POST['date_heure'] ?? date('Y-m-d\TH:i')) ?>"
                       max="<?= date('Y-m-d\TH:i') ?>"
                       required>
            </div>
            <div class="form-group">
                <label for="contexte">Contexte *</label>
                <select id="contexte" name="contexte" required onchange="updatePreview()">
                    <?php foreach (['A jeun','Post-repas','Avant sport','Apres sport','Autre'] as $ctx): ?>
                        <option value="<?= $ctx ?>"
                            <?= ($_POST['contexte'] ?? 'A jeun') === $ctx ? 'selected' : '' ?>>
                            <?= $ctx ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="commentaire">Commentaire (optionnel)</label>
            <textarea id="commentaire" name="commentaire" rows="3"
                      placeholder="Ex : après sport, repas copieux, stress..."
                      maxlength="500"
                      ><?= esc($_POST['commentaire'] ?? '') ?></textarea>
        </div>

        <!-- Preview statut -->
        <div id="statutPreview" class="statut-preview" style="display:none"></div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Enregistrer la mesure</button>
            <a href="/patient/dashboard.php" class="btn-secondary">Annuler</a>
        </div>
    </form>
</div>

<script>
function updatePreview() {
    const val = parseFloat(document.getElementById('valeur_glycemie').value);
    const ctx = document.getElementById('contexte').value;
    const box = document.getElementById('statutPreview');
    if (!val || val < 0.1 || val > 6) { box.style.display='none'; return; }

    let statut, msg, cls;
    if (val < 0.70) {
        statut='🚨'; msg='Hypoglycémie — Valeur critique !'; cls='danger';
    } else if (val > 2.00) {
        statut='🚨'; msg='Hyperglycémie sévère — Consultez immédiatement'; cls='danger';
    } else if (ctx==='A jeun' && val > 1.10) {
        statut='⚠️'; msg='Glycémie élevée à jeun (cible : 0.70–1.10 g/L)'; cls='warning';
    } else if (ctx==='Post-repas' && val > 1.40) {
        statut='⚠️'; msg='Glycémie élevée post-repas (cible : < 1.40 g/L)'; cls='warning';
    } else {
        statut='✅'; msg='Valeur dans la cible'; cls='success';
    }
    box.className = 'statut-preview preview-' + cls;
    box.innerHTML = statut + ' <strong>' + val + ' g/L</strong> — ' + msg;
    box.style.display = 'flex';
}
updatePreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
