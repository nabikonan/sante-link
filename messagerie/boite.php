<?php
/**
 * DiabSuivi — Boîte de messagerie (patient + médecin)
 * URL : /messagerie/boite.php
 */
$pageTitle = 'Messagerie — SanteLink';
require_once __DIR__ . '/../includes/session.php';
exigerConnexion();
require_once __DIR__ . '/../db/connexion.php';
require_once __DIR__ . '/../includes/fonctions.php';
require_once __DIR__ . '/../includes/messagerie.php';

$monId   = (int) $_SESSION['user_id'];
$monRole = $_SESSION['user_role'];
$pdo     = getDB();

// ── Thread actif ──────────────────────────────────────────────
$idInterlo   = isset($_GET['avec']) ? (int)$_GET['avec'] : 0;
$roleInterlo = in_array($_GET['role'] ?? '', ['patient','medecin'])
               ? $_GET['role'] : '';
$messages    = [];
$nomInterlo  = '';
$infoInterlo = null;

if ($idInterlo && $roleInterlo) {
    // Vérifier que la conversation est autorisée
    $autorise = false;
    if ($monRole === 'patient' && $roleInterlo === 'medecin') {
        $s = $pdo->prepare("SELECT COUNT(*) FROM suivi
            WHERE id_patient = :p AND id_medecin = :m AND actif = 1");
        $s->execute([':p' => $monId, ':m' => $idInterlo]);
        $autorise = (bool)$s->fetchColumn();
    } elseif ($monRole === 'medecin' && $roleInterlo === 'patient') {
        $s = $pdo->prepare("SELECT COUNT(*) FROM suivi
            WHERE id_medecin = :m AND id_patient = :p AND actif = 1");
        $s->execute([':m' => $monId, ':p' => $idInterlo]);
        $autorise = (bool)$s->fetchColumn();
    }

    if ($autorise) {
        $messages = getThread($monId, $monRole, $idInterlo, $roleInterlo);

        // Nom de l'interlocuteur
        $table = $roleInterlo === 'medecin' ? 'medecin' : 'patient';
        $idCol = $roleInterlo === 'medecin' ? 'id_medecin' : 'id_patient';
        $s2 = $pdo->prepare("SELECT * FROM {$table} WHERE {$idCol} = :id");
        $s2->execute([':id' => $idInterlo]);
        $infoInterlo = $s2->fetch();
        $prefix    = $roleInterlo === 'medecin' ? 'Dr ' : '';
        $nomInterlo = $infoInterlo
            ? $prefix . $infoInterlo['prenom'] . ' ' . $infoInterlo['nom']
            : 'Interlocuteur';
    } else {
        $idInterlo = 0;
    }
}

// ── Envoyer un message ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $idInterlo && $roleInterlo) {
    verifierCsrf();
    $sujet   = trim($_POST['sujet']   ?? '');
    $contenu = trim($_POST['contenu'] ?? '');
    $refMesure = isset($_POST['id_mesure_ref']) && ctype_digit($_POST['id_mesure_ref'])
                 ? (int)$_POST['id_mesure_ref'] : null;

    if ($sujet && $contenu) {
        if (strlen($sujet) > 200 || strlen($contenu) > 5000) {
            $_SESSION['flash'] = ['msg' => 'Message trop long.', 'type' => 'danger'];
        } else {
            envoyerMessage($monId, $monRole, $idInterlo, $roleInterlo, $sujet, $contenu, $refMesure);
            // Notification email à l'interlocuteur
            if ($infoInterlo && file_exists(__DIR__ . '/../notifications/Mailer.php')) {
                require_once __DIR__ . '/../notifications/Mailer.php';
                $expediteurNom = $_SESSION['user_nom'];
                try {
                    $mailDest   = $infoInterlo['email'];
                    $nomDest    = $infoInterlo['prenom'] . ' ' . $infoInterlo['nom'];
                    $appUrl     = $_ENV['APP_URL'] ?? 'http://localhost/diabsuivi/project';
                    $urlBoite   = "{$appUrl}/messagerie/boite.php?avec={$monId}&role={$monRole}";
                    // Utiliser PHPMailer directement
                    if (class_exists('PHPMailer\PHPMailer\PHPMailer') ||
                        file_exists(__DIR__ . '/../vendor/phpmailer/src/PHPMailer.php')) {
                        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                            require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
                            require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
                            require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
                        }
                        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host     = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = $_ENV['MAIL_USER'] ?? '';
                        $mail->Password = $_ENV['MAIL_PASS'] ?? '';
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port     = (int)($_ENV['MAIL_PORT'] ?? 587);
                        $mail->CharSet  = 'UTF-8';
                        $mail->isHTML(true);
                        $mail->setFrom($_ENV['MAIL_FROM'] ?? 'noreply@santelink.sn', 'SanteLink');
                        $mail->addAddress($mailDest, $nomDest);
                        $mail->Subject = "💬 SanteLink — Nouveau message de {$expediteurNom}";
                        $mail->Body    = "
                        <div style='font-family:system-ui;max-width:500px;margin:0 auto;padding:20px'>
                            <div style='background:#185FA5;color:#fff;padding:20px;border-radius:10px 10px 0 0'>
                                <h2 style='margin:0'>💬 Nouveau message SanteLink</h2>
                            </div>
                            <div style='background:#fff;border:1px solid #E2E6EC;padding:20px;border-radius:0 0 10px 10px'>
                                <p>Bonjour <strong>{$nomDest}</strong>,</p>
                                <p><strong>{$expediteurNom}</strong> vous a envoyé un message.</p>
                                <div style='background:#F5F8F7;padding:14px;border-radius:8px;margin:16px 0'>
                                    <strong>{$sujet}</strong><br>
                                    <span style='color:#5A6272;font-size:13px'>" .
                                        htmlspecialchars(mb_substr($contenu, 0, 200)) .
                                        (strlen($contenu) > 200 ? '…' : '') .
                                    "</span>
                                </div>
                                <a href='{$urlBoite}'
                                   style='display:inline-block;background:#185FA5;color:#fff;
                                          padding:10px 20px;border-radius:8px;text-decoration:none;
                                          font-weight:600'>Répondre →</a>
                            </div>
                        </div>";
                        $mail->send();
                    }
                } catch (\Throwable $e) {
                    error_log('[SanteLink][Messagerie] Notif email : ' . $e->getMessage());
                }
            }
        }
        header("Location: /messagerie/boite.php?avec={$idInterlo}&role={$roleInterlo}");
        exit;
    }
}

// ── Liste des conversations ───────────────────────────────────
$conversations = getConversations($monId, $monRole);

// ── Liste des interlocuteurs disponibles (pour nouveau message) ─
$disponibles = [];
if ($monRole === 'patient') {
    $s = $pdo->prepare("
        SELECT m.id_medecin AS id, 'medecin' AS role,
               CONCAT('Dr ', m.prenom, ' ', m.nom) AS nom, m.specialite AS info
        FROM   suivi s JOIN medecin m ON m.id_medecin = s.id_medecin
        WHERE  s.id_patient = :id AND s.actif = 1
    ");
    $s->execute([':id' => $monId]);
    $disponibles = $s->fetchAll();
} elseif ($monRole === 'medecin') {
    $s = $pdo->prepare("
        SELECT p.id_patient AS id, 'patient' AS role,
               CONCAT(p.prenom, ' ', p.nom) AS nom, p.type_diabete AS info
        FROM   suivi s JOIN patient p ON p.id_patient = s.id_patient
        WHERE  s.id_medecin = :id AND s.actif = 1
        ORDER  BY p.nom
    ");
    $s->execute([':id' => $monId]);
    $disponibles = $s->fetchAll();
}

// Mesures récentes pour référencer (patient uniquement)
$mesuresRecentes = [];
if ($monRole === 'patient') {
    $sm = $pdo->prepare("
        SELECT id_mesure, valeur_glycemie, contexte, date_heure
        FROM   mesure_glycemie
        WHERE  id_patient = :id
        ORDER  BY date_heure DESC LIMIT 10
    ");
    $sm->execute([':id' => $monId]);
    $mesuresRecentes = $sm->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>💬 Messagerie</h1>
    <button class="btn-primary" onclick="toggleNouveauMsg()">✉️ Nouveau message</button>
</div>

<?= flashMessage() ?>

<!-- Formulaire nouveau message -->
<div id="nouveauMsgForm" style="display:none;margin-bottom:16px">
    <div class="form-card" style="max-width:100%">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:14px">Nouveau message</h3>
        <form method="POST"
              action="/messagerie/boite.php?avec=<?= $idInterlo ?>&role=<?= esc($roleInterlo) ?>">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Destinataire *</label>
                    <select name="_dest" id="destSelect" required
                            onchange="majActionForm(this)">
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($disponibles as $d): ?>
                            <option value="<?= $d['id'] ?>|<?= $d['role'] ?>"
                                <?= ($idInterlo == $d['id'] && $roleInterlo === $d['role']) ? 'selected' : '' ?>>
                                <?= esc($d['nom']) ?>
                                <?= $d['info'] ? '· ' . esc($d['info']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($mesuresRecentes)): ?>
                <div class="form-group">
                    <label>Référencer une mesure (optionnel)</label>
                    <select name="id_mesure_ref">
                        <option value="">Aucune</option>
                        <?php foreach ($mesuresRecentes as $m): ?>
                            <option value="<?= $m['id_mesure'] ?>">
                                <?= (new DateTime($m['date_heure']))->format('d/m H\hi') ?>
                                · <?= $m['valeur_glycemie'] ?> g/L
                                · <?= esc($m['contexte']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Sujet *</label>
                <input type="text" name="sujet" maxlength="200" required
                       placeholder="Ex : Question sur ma mesure du 15/06">
            </div>
            <div class="form-group">
                <label>Message *</label>
                <textarea name="contenu" rows="5" maxlength="5000" required
                          placeholder="Écrivez votre message ici…"></textarea>
                <div class="text-muted" style="font-size:11px;text-align:right;margin-top:3px">
                    Max 5000 caractères
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Envoyer ✉️</button>
                <button type="button" class="btn-secondary" onclick="toggleNouveauMsg()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- Layout : liste conversations + thread -->
<div class="messagerie-layout">

    <!-- Colonne gauche : conversations -->
    <div class="conv-liste">
        <div class="conv-header">
            <span style="font-size:13px;font-weight:600;color:var(--txt2)">
                Conversations (<?= count($conversations) ?>)
            </span>
        </div>
        <?php if (empty($conversations)): ?>
            <div class="empty-state" style="padding:30px 16px">
                <div style="font-size:2.5rem">💬</div>
                <p style="font-size:13px">Aucune conversation.<br>Envoyez un premier message !</p>
            </div>
        <?php else: ?>
            <?php foreach ($conversations as $c):
                $actif = ($c['interlocuteur_id'] == $idInterlo && $c['interlocuteur_role'] === $roleInterlo);
                $dt    = new DateTime($c['dernier_message']);
                $dateStr = $dt->format('d/m') === date('d/m') ? $dt->format('H\hi') : $dt->format('d/m');
                $initiale = strtoupper(substr(ltrim($c['interlocuteur_nom'], 'Dr '), 0, 1));
            ?>
            <a href="/messagerie/boite.php?avec=<?= $c['interlocuteur_id'] ?>&role=<?= $c['interlocuteur_role'] ?>"
               class="conv-item <?= $actif ? 'conv-active' : '' ?>">
                <div class="conv-avatar <?= $c['interlocuteur_role'] === 'medecin' ? 'conv-avatar-med' : '' ?>">
                    <?= $initiale ?>
                </div>
                <div class="conv-info">
                    <div class="conv-nom">
                        <?= esc($c['interlocuteur_nom']) ?>
                        <?php if ($c['non_lus'] > 0): ?>
                            <span class="badge badge-danger badge-sm"><?= $c['non_lus'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="conv-date text-muted"><?= $dateStr ?> · <?= $c['nb_messages'] ?> msg</div>
                </div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Colonne droite : thread actif -->
    <div class="thread-zone">
        <?php if (!$idInterlo): ?>
            <div class="empty-state" style="height:400px;display:flex;flex-direction:column;
                                            align-items:center;justify-content:center">
                <div style="font-size:4rem">💬</div>
                <p class="text-muted">Sélectionnez une conversation ou envoyez un nouveau message.</p>
            </div>

        <?php else: ?>
            <!-- En-tête du thread -->
            <div class="thread-header">
                <div class="conv-avatar <?= $roleInterlo === 'medecin' ? 'conv-avatar-med' : '' ?>">
                    <?= strtoupper(substr(ltrim($nomInterlo, 'Dr '), 0, 1)) ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:14px"><?= esc($nomInterlo) ?></div>
                    <div class="text-muted" style="font-size:12px">
                        <?= $roleInterlo === 'medecin'
                            ? esc($infoInterlo['specialite'] ?? 'Médecin')
                            : 'Diabète ' . esc($infoInterlo['type_diabete'] ?? '') ?>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <div class="thread-messages" id="threadMessages">
                <?php if (empty($messages)): ?>
                    <div class="empty-state" style="padding:40px">
                        <p class="text-muted">Aucun message. Soyez le premier à écrire !</p>
                    </div>
                <?php else: ?>
                    <?php
                    $lastDate = '';
                    foreach ($messages as $msg):
                        $dt      = new DateTime($msg['envoye_le']);
                        $dayStr  = $dt->format('d/m/Y');
                        $isMoi   = ($msg['sens'] === 'moi');
                    ?>
                        <?php if ($dayStr !== $lastDate): $lastDate = $dayStr; ?>
                            <div class="thread-date-sep"><?= $dayStr ?></div>
                        <?php endif; ?>

                        <div class="bubble-wrap <?= $isMoi ? 'bubble-moi' : 'bubble-eux' ?>">
                            <div class="bubble <?= $isMoi ? 'bubble-sent' : 'bubble-recv' ?>">
                                <?php if ($msg['sujet']): ?>
                                    <div class="bubble-sujet"><?= esc($msg['sujet']) ?></div>
                                <?php endif; ?>
                                <div class="bubble-text"><?= nl2br(esc($msg['contenu'])) ?></div>
                                <?php if ($msg['id_mesure_ref']): ?>
                                    <div class="bubble-ref">
                                        📊 Mesure référencée #<?= $msg['id_mesure_ref'] ?>
                                    </div>
                                <?php endif; ?>
                                <div class="bubble-time">
                                    <?= $dt->format('H\hi') ?>
                                    <?php if ($isMoi): ?>
                                        <?= $msg['lu'] ? ' · ✓✓' : ' · ✓' ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Formulaire de réponse rapide -->
            <div class="thread-reply">
                <form method="POST"
                      action="/messagerie/boite.php?avec=<?= $idInterlo ?>&role=<?= esc($roleInterlo) ?>"
                      id="replyForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="sujet" value="Re: conversation">
                    <div class="reply-row">
                        <textarea name="contenu" id="replyContent" rows="2"
                                  placeholder="Écrire un message… (Entrée pour envoyer, Maj+Entrée pour sauter une ligne)"
                                  maxlength="5000"
                                  style="resize:none;border:none;outline:none;
                                         flex:1;font-size:13px;background:transparent;
                                         font-family:inherit"></textarea>
                        <button type="submit" class="btn-primary btn-sm" style="align-self:flex-end">
                            ➤
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.messagerie-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 0;
    height: calc(100vh - 180px);
    min-height: 500px;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
}
/* ── Colonne conversations ── */
.conv-liste  { border-right: 1px solid var(--border); overflow-y: auto; background: var(--bg); }
.conv-header { padding: 14px 16px; border-bottom: 1px solid var(--border);
               background: var(--bg2); position: sticky; top: 0; z-index: 2; }
.conv-item   { display: flex; align-items: center; gap: 10px; padding: 12px 14px;
               border-bottom: 1px solid var(--border); text-decoration: none;
               color: var(--txt); transition: background .12s; }
.conv-item:hover  { background: var(--green-l); text-decoration: none; }
.conv-active      { background: var(--green-l) !important; border-left: 3px solid var(--green); }
.conv-avatar      { width: 38px; height: 38px; border-radius: 50%; background: var(--green-l);
                    color: var(--green-d); display: flex; align-items: center;
                    justify-content: center; font-weight: 700; font-size: 14px; flex-shrink: 0; }
.conv-avatar-med  { background: var(--blue-l); color: var(--blue-d); }
.conv-info        { flex: 1; min-width: 0; }
.conv-nom         { font-size: 13px; font-weight: 600; display: flex;
                    align-items: center; gap: 6px; }
.conv-date        { font-size: 11px; }

/* ── Thread ── */
.thread-zone    { display: flex; flex-direction: column; overflow: hidden; }
.thread-header  { display: flex; align-items: center; gap: 12px;
                  padding: 14px 20px; border-bottom: 1px solid var(--border);
                  background: var(--bg2); flex-shrink: 0; }
.thread-messages { flex: 1; overflow-y: auto; padding: 16px 20px;
                   display: flex; flex-direction: column; gap: 6px;
                   background: #F9FAFB; }
.thread-date-sep { text-align: center; font-size: 11px; color: var(--txt3);
                   margin: 10px 0; position: relative; }
.thread-date-sep::before,
.thread-date-sep::after { content: ''; position: absolute; top: 50%;
                          width: 35%; height: 1px; background: var(--border); }
.thread-date-sep::before { left: 0; }
.thread-date-sep::after  { right: 0; }

/* ── Bulles ── */
.bubble-wrap  { display: flex; }
.bubble-moi   { justify-content: flex-end; }
.bubble-eux   { justify-content: flex-start; }
.bubble       { max-width: 68%; padding: 10px 14px; border-radius: 16px;
                font-size: 13px; line-height: 1.5; }
.bubble-sent  { background: var(--green); color: #fff;
                border-bottom-right-radius: 4px; }
.bubble-recv  { background: var(--bg2); color: var(--txt);
                border: 1px solid var(--border); border-bottom-left-radius: 4px; }
.bubble-sujet { font-weight: 700; font-size: 12px; margin-bottom: 4px;
                opacity: .85; }
.bubble-text  { white-space: pre-wrap; word-break: break-word; }
.bubble-ref   { font-size: 11px; margin-top: 6px; opacity: .7;
                background: rgba(0,0,0,.08); padding: 3px 8px; border-radius: 4px; }
.bubble-time  { font-size: 10px; margin-top: 4px; opacity: .65; text-align: right; }

/* ── Réponse rapide ── */
.thread-reply  { border-top: 1px solid var(--border); padding: 10px 16px;
                 background: var(--bg2); flex-shrink: 0; }
.reply-row     { display: flex; align-items: flex-end; gap: 10px;
                 background: var(--bg); border: 1px solid var(--border);
                 border-radius: 10px; padding: 10px 14px; }

@media (max-width: 640px) {
    .messagerie-layout { grid-template-columns: 1fr; height: auto; }
    .conv-liste        { display: <?= $idInterlo ? 'none' : 'block' ?>; max-height: 200px; }
}
</style>

<script>
// Auto-scroll vers le bas du thread
const msgs = document.getElementById('threadMessages');
if (msgs) msgs.scrollTop = msgs.scrollHeight;

// Entrée = envoyer, Maj+Entrée = nouvelle ligne
const reply = document.getElementById('replyContent');
if (reply) {
    reply.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (reply.value.trim()) document.getElementById('replyForm').submit();
        }
    });
}

// Changer la cible du formulaire nouveau message
function majActionForm(sel) {
    if (!sel.value) return;
    const [id, role] = sel.value.split('|');
    document.querySelector('#nouveauMsgForm form').action =
        `/messagerie/boite.php?avec=${id}&role=${role}`;
}

function toggleNouveauMsg() {
    const f = document.getElementById('nouveauMsgForm');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

<?php if ($idInterlo): ?>
// Pré-remplir le sélecteur de destinataire pour la réponse rapide
const ds = document.getElementById('destSelect');
if (ds) {
    const val = `<?= $idInterlo ?>|<?= esc($roleInterlo) ?>`;
    for (const opt of ds.options) {
        if (opt.value === val) { opt.selected = true; break; }
    }
    majActionForm(ds);
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
