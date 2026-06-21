<?php
$pageTitle = 'Gestion utilisateurs — Admin SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('admin');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$pdo       = getDB();
$adminId   = (int) $_SESSION['user_id'];
$adminRole = $_SESSION['admin_role'] ?? 'moderateur';
$onglet    = in_array($_GET['type'] ?? 'patients', ['patients', 'medecins'])
             ? $_GET['type'] : 'patients';

// ── Actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();
    $action = $_POST['action'] ?? '';
    $cibleId = (int) ($_POST['id'] ?? 0);
    $cibleType = $_POST['type'] ?? 'patient';

    // Seul un super_admin peut supprimer des comptes
    if ($action === 'supprimer' && $adminRole !== 'super_admin') {
        redirect('/admin/utilisateurs.php?type=' . $onglet, 'Seul un super admin peut supprimer un compte.', 'danger');
    }

    if ($action === 'supprimer' && $cibleId) {
        $table = $cibleType === 'medecin' ? 'medecin' : 'patient';
        $idCol = $cibleType === 'medecin' ? 'id_medecin' : 'id_patient';

        // Récupérer le nom avant suppression (pour le log)
        $s = $pdo->prepare("SELECT nom, prenom FROM {$table} WHERE {$idCol} = :id");
        $s->execute([':id' => $cibleId]);
        $u = $s->fetch();

        $pdo->prepare("DELETE FROM {$table} WHERE {$idCol} = :id")->execute([':id' => $cibleId]);

        logAudit($adminId, 'admin', 'suppression_compte', $cibleType, $cibleId,
            $u ? "Suppression de {$u['prenom']} {$u['nom']}" : null);

        redirect('/admin/utilisateurs.php?type=' . $onglet, 'Compte supprimé.');
    }

    if ($action === 'reset_2fa' && $cibleId) {
        $table = $cibleType === 'medecin' ? 'medecin' : 'patient';
        $idCol = $cibleType === 'medecin' ? 'id_medecin' : 'id_patient';
        $pdo->prepare("UPDATE {$table} SET deux_fa_actif = 0 WHERE {$idCol} = :id")
            ->execute([':id' => $cibleId]);
        logAudit($adminId, 'admin', 'reset_2fa', $cibleType, $cibleId);
        redirect('/admin/utilisateurs.php?type=' . $onglet, '2FA désactivé pour ce compte.');
    }
}

// ── Recherche / filtre ────────────────────────────────────────
$recherche = trim($_GET['q'] ?? '');

if ($onglet === 'patients') {
    $sql = "
        SELECT p.*,
            (SELECT COUNT(*) FROM mesure_glycemie m WHERE m.id_patient = p.id_patient) AS nb_mesures,
            (SELECT MAX(date_heure) FROM mesure_glycemie m WHERE m.id_patient = p.id_patient) AS derniere_mesure,
            (SELECT m2.nom FROM suivi s JOIN medecin m2 ON m2.id_medecin = s.id_medecin
             WHERE s.id_patient = p.id_patient AND s.actif=1 LIMIT 1) AS medecin_nom
        FROM patient p
        WHERE 1=1
    ";
    $params = [];
    if ($recherche) {
        $sql .= " AND (p.nom LIKE :q OR p.prenom LIKE :q OR p.email LIKE :q)";
        $params[':q'] = "%{$recherche}%";
    }
    $sql .= " ORDER BY p.date_inscription DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $utilisateurs = $stmt->fetchAll();

} else {
    $sql = "
        SELECT m.*,
            (SELECT COUNT(*) FROM suivi s WHERE s.id_medecin = m.id_medecin AND s.actif=1) AS nb_patients
        FROM medecin m
        WHERE 1=1
    ";
    $params = [];
    if ($recherche) {
        $sql .= " AND (m.nom LIKE :q OR m.prenom LIKE :q OR m.email LIKE :q)";
        $params[':q'] = "%{$recherche}%";
    }
    $sql .= " ORDER BY m.date_inscription DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $utilisateurs = $stmt->fetchAll();
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="adm-section-title">👥 Gestion des utilisateurs</div>
<div class="adm-section-sub">Patients et médecins inscrits sur la plateforme</div>

<!-- Onglets -->
<div class="adm-tabs">
    <a href="?type=patients" class="adm-tab <?= $onglet === 'patients' ? 'active' : '' ?>">
        Patients (<?= $onglet === 'patients' ? count($utilisateurs) : '' ?>)
    </a>
    <a href="?type=medecins" class="adm-tab <?= $onglet === 'medecins' ? 'active' : '' ?>">
        Médecins (<?= $onglet === 'medecins' ? count($utilisateurs) : '' ?>)
    </a>
</div>

<!-- Recherche -->
<div class="adm-filter-bar">
    <form method="GET" style="display:flex;gap:8px">
        <input type="hidden" name="type" value="<?= esc($onglet) ?>">
        <input type="text" name="q" class="adm-input" placeholder="🔍 Rechercher par nom/email…"
               value="<?= esc($recherche) ?>" style="width:280px">
        <button type="submit" class="adm-btn adm-btn-primary adm-btn-sm">Rechercher</button>
        <?php if ($recherche): ?>
            <a href="?type=<?= $onglet ?>" class="adm-btn adm-btn-secondary adm-btn-sm">Réinitialiser</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tableau -->
<div class="adm-card">
    <?php if (empty($utilisateurs)): ?>
        <p style="color:var(--adm-txt2);text-align:center;padding:30px">
            Aucun <?= $onglet === 'patients' ? 'patient' : 'médecin' ?> trouvé.
        </p>
    <?php else: ?>
        <table class="adm-table">
            <tr>
                <th>Nom</th>
                <th>Email</th>
                <?php if ($onglet === 'patients'): ?>
                    <th>Type</th>
                    <th>Médecin</th>
                    <th>Mesures</th>
                    <th>Dernière activité</th>
                <?php else: ?>
                    <th>Spécialité</th>
                    <th>Patients suivis</th>
                <?php endif; ?>
                <th>2FA</th>
                <th>Inscrit le</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($utilisateurs as $u): ?>
            <tr>
                <td style="font-weight:600"><?= esc($u['prenom'] . ' ' . $u['nom']) ?></td>
                <td style="color:var(--adm-txt2);font-size:12px"><?= esc($u['email']) ?></td>

                <?php if ($onglet === 'patients'): ?>
                    <td><span class="adm-badge adm-badge-blue"><?= esc($u['type_diabete']) ?></span></td>
                    <td style="font-size:12px;color:var(--adm-txt2)">
                        <?= $u['medecin_nom'] ? 'Dr ' . esc($u['medecin_nom']) : '— Non suivi' ?>
                    </td>
                    <td><?= $u['nb_mesures'] ?></td>
                    <td style="font-size:12px;color:var(--adm-txt2)">
                        <?= $u['derniere_mesure'] ? (new DateTime($u['derniere_mesure']))->format('d/m/Y') : '—' ?>
                    </td>
                <?php else: ?>
                    <td><?= esc($u['specialite'] ?? '—') ?></td>
                    <td><span class="adm-badge adm-badge-green"><?= $u['nb_patients'] ?> patients</span></td>
                <?php endif; ?>

                <td>
                    <?= !empty($u['deux_fa_actif'])
                        ? '<span class="adm-badge adm-badge-green">Activé</span>'
                        : '<span class="adm-badge adm-badge-gray">Désactivé</span>' ?>
                </td>
                <td style="font-size:12px;color:var(--adm-txt2)">
                    <?= (new DateTime($u['date_inscription']))->format('d/m/Y') ?>
                </td>
                <td>
                    <div style="display:flex;gap:6px">
                        <?php if (!empty($u['deux_fa_actif'])): ?>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reset_2fa">
                            <input type="hidden" name="id" value="<?= $u[$onglet === 'patients' ? 'id_patient' : 'id_medecin'] ?>">
                            <input type="hidden" name="type" value="<?= $onglet === 'patients' ? 'patient' : 'medecin' ?>">
                            <button type="submit" class="adm-btn adm-btn-secondary adm-btn-sm"
                                    onclick="return confirm('Désactiver le 2FA de ce compte ?')">
                                🔓 Reset 2FA
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($adminRole === 'super_admin'): ?>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="id" value="<?= $u[$onglet === 'patients' ? 'id_patient' : 'id_medecin'] ?>">
                            <input type="hidden" name="type" value="<?= $onglet === 'patients' ? 'patient' : 'medecin' ?>">
                            <button type="submit" class="adm-btn adm-btn-danger adm-btn-sm"
                                    onclick="return confirm('⚠️ Supprimer définitivement ce compte et toutes ses données ?')">
                                🗑️ Supprimer
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<?php if ($adminRole !== 'super_admin'): ?>
<div style="margin-top:14px;font-size:12px;color:var(--adm-txt2)">
    ℹ️ Seul un super administrateur peut supprimer des comptes.
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
