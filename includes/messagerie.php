<?php
/**
 * DiabSuivi — Fonctions communes de messagerie
 */

/**
 * Compte les messages non lus pour un utilisateur.
 */
function getNbMessagesNonLus(int $id, string $role): int {
    $stmt = getDB()->prepare("
        SELECT COUNT(*) FROM message
        WHERE  id_destinataire    = :id
          AND  role_destinataire  = :role
          AND  lu = 0
    ");
    $stmt->execute([':id' => $id, ':role' => $role]);
    return (int) $stmt->fetchColumn();
}

/**
 * Récupère les conversations (threads) d'un utilisateur.
 * Un thread = échanges entre 2 personnes, trié par dernier message.
 */
function getConversations(int $id, string $role): array {
    $pdo = getDB();

    // Tous les interlocuteurs avec qui cet user a échangé
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN id_expediteur = ? AND role_expediteur = ?
                    THEN id_destinataire
                ELSE id_expediteur
            END AS interlocuteur_id,
            CASE
                WHEN id_expediteur = ? AND role_expediteur = ?
                    THEN role_destinataire
                ELSE role_expediteur
            END AS interlocuteur_role,
            MAX(envoye_le) AS dernier_message,
            SUM(CASE WHEN lu = 0 AND id_destinataire = ?
                     AND role_destinataire = ? THEN 1 ELSE 0 END) AS non_lus,
            COUNT(*) AS nb_messages
        FROM message
        WHERE (id_expediteur = ? AND role_expediteur = ?)
           OR (id_destinataire = ? AND role_destinataire = ?)
        GROUP BY interlocuteur_id, interlocuteur_role
        ORDER BY dernier_message DESC
    ");
    $stmt->execute([$id,$role, $id,$role, $id,$role, $id,$role, $id,$role]);
    $convs = $stmt->fetchAll();

    // Enrichir avec le nom de l'interlocuteur
    foreach ($convs as &$c) {
        $table = $c['interlocuteur_role'] === 'medecin' ? 'medecin' : 'patient';
        $idCol = $c['interlocuteur_role'] === 'medecin' ? 'id_medecin' : 'id_patient';
        $s = $pdo->prepare("SELECT nom, prenom FROM {$table} WHERE {$idCol} = :id");
        $s->execute([':id' => $c['interlocuteur_id']]);
        $info = $s->fetch();
        $c['interlocuteur_nom'] = $info
            ? ($c['interlocuteur_role'] === 'medecin' ? 'Dr ' : '') . $info['prenom'] . ' ' . $info['nom']
            : 'Utilisateur inconnu';
    }

    return $convs;
}

/**
 * Récupère tous les messages d'un thread entre 2 utilisateurs.
 * Marque automatiquement comme lus les messages reçus.
 */
function getThread(
    int $idA, string $roleA,
    int $idB, string $roleB
): array {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT m.*,
            CASE WHEN m.id_expediteur = ? AND m.role_expediteur = ?
                 THEN 'moi' ELSE 'eux' END AS sens
        FROM message m
        WHERE (
            (m.id_expediteur = ? AND m.role_expediteur = ?
             AND m.id_destinataire = ? AND m.role_destinataire = ?)
         OR (m.id_expediteur = ? AND m.role_expediteur = ?
             AND m.id_destinataire = ? AND m.role_destinataire = ?)
        )
        ORDER BY m.envoye_le ASC
        LIMIT 200
    ");
    $stmt->execute([
        $idA, $roleA,
        $idA, $roleA,
        $idB, $roleB,
        $idA, $roleA,
        $idB, $roleB
    ]);
    $messages = $stmt->fetchAll();

    // Marquer comme lus les messages reçus
    $pdo->prepare("
        UPDATE message SET lu = 1
        WHERE  id_expediteur = ? AND role_expediteur = ?
          AND  id_destinataire = ? AND role_destinataire = ?
          AND  lu = 0
    ")->execute([
        $idA, $roleA,
        $idB, $roleB,
    ]);

    return $messages;
}

/**
 * Envoie un message.
 */
function envoyerMessage(
    int    $idExp,   string $roleExp,
    int    $idDest,  string $roleDest,
    string $sujet,
    string $contenu,
    ?int   $idMesureRef = null
): int {
    $stmt = getDB()->prepare("
        INSERT INTO message
            (id_expediteur, role_expediteur, id_destinataire,
             role_destinataire, sujet, contenu, id_mesure_ref)
        VALUES (:ie, :re, :id, :rd, :su, :co, :mr)
    ");
    $stmt->execute([
        ':ie' => $idExp,
        ':re' => $roleExp,
        ':id' => $idDest,
        ':rd' => $roleDest,
        ':su' => $sujet,
        ':co' => $contenu,
        ':mr' => $idMesureRef
    ]);
    return (int) getDB()->lastInsertId();
}
