<?php
$pageTitle = 'Logs d\'audit — Admin SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerRole('admin');
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';

$pdo = getDB();

// ── Filtres ────────────────────────────────────────────────────
$filtreRole   = in_array($_GET['role'] ?? '', ['patient','medecin','admin']) ? $_GET['role'] : '';
$filtreAction = trim($_GET['action'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$parPage      = 50;
$offset       = ($page - 1) * $parPage;

$where  = [];
$params = [];

if ($filtreRole) {
    $where[] = 'acteur_role = :role';
    $params[':role'] = $filtreRole;
}
if ($filtreAction) {
    $where[] = 'action LIKE :action';
    $params[':action'] = "%{$filtreAction}%";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total pour pagination
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM audit_log {$whereSql}");
$stmtCount->execute($params);
$total = (int) $stmtCount->fetchColumn();
$totalPages = max(1, ceil($total / $parPage));

// Logs paginés
$stmt = $pdo->prepare("
    SELECT * FROM audit_log
    {$whereSql}
    ORDER BY cree_le DESC
    LIMIT {$parPage} OFFSET {$offset}
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Actions distinctes pour le filtre
$stmtActions = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action");
$actionsDisponibles = $stmtActions->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="adm-section-title">📋 Logs d'audit</div>
<div class="adm-section-sub">
    Historique de toutes les actions sensibles · <?= $total ?> entrée<?= $total > 1 ? 's' : '' ?>
</div>

<!-- Filtres -->
<div class="adm-filter-bar">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
        <select name="role" class="adm-select" onchange="this.form.submit()">
            <option value="">Tous les rôles</option>
            <option value="patient" <?= $filtreRole === 'patient' ? 'selected' : '' ?>>Patient</option>
            <option value="medecin" <?= $filtreRole === 'medecin' ? 'selected' : '' ?>>Médecin</option>
            <option value="admin"   <?= $filtreRole === 'admin'   ? 'selected' : '' ?>>Admin</option>
        </select>
        <select name="action" class="adm-select" onchange="this.form.submit()">
            <option value="">Toutes les actions</option>
            <?php foreach ($actionsDisponibles as $a): ?>
                <option value="<?= esc($a) ?>" <?= $filtreAction === $a ? 'selected' : '' ?>>
                    <?= esc($a) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($filtreRole || $filtreAction): ?>
            <a href="/admin/audit.php" class="adm-btn adm-btn-secondary adm-btn-sm">Réinitialiser</a>
        <?php endif; ?>
    </form>
</div>

<!-- Table -->
<div class="adm-card">
    <?php if (empty($logs)): ?>
        <p style="color:var(--adm-txt2);text-align:center;padding:30px">Aucun log trouvé.</p>
    <?php else: ?>
        <table class="adm-table">
            <tr>
                <th>Date</th>
                <th>Rôle</th>
                <th>Acteur ID</th>
                <th>Action</th>
                <th>Cible</th>
                <th>Détails</th>
                <th>IP</th>
            </tr>
            <?php foreach ($logs as $l): ?>
            <tr>
                <td style="font-size:12px;white-space:nowrap">
                    <?= (new DateTime($l['cree_le']))->format('d/m/Y H:i:s') ?>
                </td>
                <td>
                    <span class="adm-badge adm-badge-<?=
                        $l['acteur_role']==='admin' ? 'blue' : ($l['acteur_role']==='medecin' ? 'green' : 'gray')
                    ?>"><?= esc($l['acteur_role']) ?></span>
                </td>
                <td>#<?= $l['acteur_id'] ?></td>
                <td style="font-weight:600"><?= esc($l['action']) ?></td>
                <td style="font-size:12px;color:var(--adm-txt2)">
                    <?= $l['cible_type'] ? esc($l['cible_type']) . ' #' . $l['cible_id'] : '—' ?>
                </td>
                <td style="font-size:12px;color:var(--adm-txt2);max-width:240px">
                    <?= esc($l['details'] ?? '—') ?>
                </td>
                <td style="font-size:11px;color:var(--adm-txt2)"><?= esc($l['ip_adresse'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div style="display:flex;justify-content:center;gap:6px;margin-top:18px">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="?page=<?= $p ?>&role=<?= esc($filtreRole) ?>&action=<?= esc($filtreAction) ?>"
                   class="adm-btn adm-btn-sm <?= $p === $page ? 'adm-btn-primary' : 'adm-btn-secondary' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
