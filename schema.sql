-- ============================================================
-- DiabSuivi — Schéma de base de données
-- MySQL 8.0+ / MariaDB 10.5+
-- ============================================================
-- Utilisation :
--   mysql -u root -p < schema.sql
-- Ou dans MySQL Workbench / phpMyAdmin : importer ce fichier.
-- ============================================================

CREATE DATABASE IF NOT EXISTS diabsuivi
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE diabsuivi;

-- Créer un utilisateur dédié (NE PAS utiliser root en production)
-- Adapter le mot de passe avant d'exécuter
-- CREATE USER IF NOT EXISTS 'diabsuivi_user'@'localhost' IDENTIFIED BY 'MotDePasseSecurise!';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON diabsuivi.* TO 'diabsuivi_user'@'localhost';
-- FLUSH PRIVILEGES;

-- ── Tables ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS medecin (
    id_medecin      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(80)  NOT NULL,
    prenom          VARCHAR(80)  NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    mot_de_passe    VARCHAR(255) NOT NULL,           -- bcrypt
    specialite      VARCHAR(100) DEFAULT 'Diabétologie',
    telephone       VARCHAR(20)  DEFAULT NULL,
    date_inscription DATETIME    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patient (
    id_patient      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(80)  NOT NULL,
    prenom          VARCHAR(80)  NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    mot_de_passe    VARCHAR(255) NOT NULL,           -- bcrypt
    date_naissance  DATE         NOT NULL,
    sexe            ENUM('M','F') NOT NULL,
    type_diabete    ENUM('Type 1','Type 2','Gestationnel','LADA','Autre') DEFAULT 'Type 2',
    telephone       VARCHAR(20)  DEFAULT NULL,
    date_inscription DATETIME    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Relation médecin-patient (un médecin peut suivre plusieurs patients)
CREATE TABLE IF NOT EXISTS suivi (
    id_suivi        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_medecin      INT UNSIGNED NOT NULL,
    id_patient      INT UNSIGNED NOT NULL,
    date_debut      DATE         DEFAULT (CURDATE()),
    actif           TINYINT(1)   DEFAULT 1,
    FOREIGN KEY (id_medecin) REFERENCES medecin(id_medecin) ON DELETE CASCADE,
    FOREIGN KEY (id_patient) REFERENCES patient(id_patient) ON DELETE CASCADE,
    UNIQUE KEY uq_suivi (id_medecin, id_patient)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mesure_glycemie (
    id_mesure       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_patient      INT UNSIGNED NOT NULL,
    valeur_glycemie DECIMAL(5,2) NOT NULL
                    CHECK (valeur_glycemie BETWEEN 0.10 AND 6.00),
    date_heure      DATETIME     NOT NULL,
    contexte        ENUM('A jeun','Post-repas','Avant sport','Apres sport','Autre')
                    NOT NULL DEFAULT 'A jeun',
    commentaire     TEXT         DEFAULT NULL,
    FOREIGN KEY (id_patient) REFERENCES patient(id_patient) ON DELETE CASCADE,
    INDEX idx_patient_date (id_patient, date_heure)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alerte (
    id_alerte       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_patient      INT UNSIGNED NOT NULL,
    id_mesure       INT UNSIGNED DEFAULT NULL,
    type_alerte     ENUM('Hypoglycemie','Hyperglycemie','Hyperglycemie severe') NOT NULL,
    message         TEXT         NOT NULL,
    statut          ENUM('Non lue','Lue') DEFAULT 'Non lue',
    date_heure      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_patient) REFERENCES patient(id_patient) ON DELETE CASCADE,
    FOREIGN KEY (id_mesure)  REFERENCES mesure_glycemie(id_mesure) ON DELETE SET NULL,
    INDEX idx_patient_statut (id_patient, statut)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS traitement (
    id_traitement   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom_medicament  VARCHAR(100) NOT NULL,
    dosage          VARCHAR(50)  NOT NULL,
    description     TEXT         DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescription (
    id_prescription INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_patient      INT UNSIGNED NOT NULL,
    id_traitement   INT UNSIGNED NOT NULL,
    frequence       VARCHAR(100) NOT NULL,
    heure_prise     VARCHAR(100) DEFAULT NULL,
    date_debut      DATE         NOT NULL,
    date_fin        DATE         DEFAULT NULL,
    actif           TINYINT(1)   DEFAULT 1,
    FOREIGN KEY (id_patient)    REFERENCES patient(id_patient)       ON DELETE CASCADE,
    FOREIGN KEY (id_traitement) REFERENCES traitement(id_traitement) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ordonnance (
    id_ordonnance   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_medecin      INT UNSIGNED NOT NULL,
    id_patient      INT UNSIGNED NOT NULL,
    contenu         TEXT         NOT NULL,
    date_ordonnance DATE         NOT NULL,
    date_creation   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_medecin) REFERENCES medecin(id_medecin) ON DELETE CASCADE,
    FOREIGN KEY (id_patient) REFERENCES patient(id_patient) ON DELETE CASCADE,
    INDEX idx_medecin_patient (id_medecin, id_patient)
) ENGINE=InnoDB;

-- ── Données de démonstration ───────────────────────────────────
-- Mot de passe de test pour tous les comptes : "Diabsuivi2025!"
-- Hash bcrypt généré avec password_hash('Diabsuivi2025!', PASSWORD_BCRYPT)

INSERT IGNORE INTO medecin (nom, prenom, email, mot_de_passe, specialite, telephone) VALUES
(
    'Diallo', 'Moussa',
    'dr.diallo@diabsuivi.sn',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Diabétologie',
    '+221 77 000 00 01'
);

INSERT IGNORE INTO patient (nom, prenom, email, mot_de_passe, date_naissance, sexe, type_diabete, telephone) VALUES
(
    'Sow', 'Aminata',
    'aminata.sow@email.sn',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '1980-03-15', 'F', 'Type 2', '+221 77 000 00 02'
),
(
    'Ndiaye', 'Ibrahima',
    'ibrahima.ndiaye@email.sn',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '1972-07-22', 'M', 'Type 1', '+221 76 000 00 03'
);

INSERT IGNORE INTO suivi (id_medecin, id_patient) VALUES (1, 1), (1, 2);

INSERT IGNORE INTO mesure_glycemie (id_patient, valeur_glycemie, date_heure, contexte) VALUES
(1, 1.25, '2025-06-01 07:30:00', 'A jeun'),
(1, 1.55, '2025-06-01 12:30:00', 'Post-repas'),
(1, 0.68, '2025-06-02 07:00:00', 'A jeun'),
(1, 1.08, '2025-06-02 13:00:00', 'Post-repas'),
(1, 1.42, '2025-06-03 08:00:00', 'A jeun'),
(1, 2.10, '2025-06-04 10:00:00', 'Autre'),
(1, 0.95, '2025-06-05 07:45:00', 'A jeun'),
(1, 1.30, '2025-06-05 14:00:00', 'Post-repas'),
(1, 1.12, '2025-06-06 08:00:00', 'A jeun'),
(1, 1.60, '2025-06-07 13:00:00', 'Post-repas'),
(2, 0.82, '2025-06-01 07:00:00', 'A jeun'),
(2, 1.48, '2025-06-02 12:30:00', 'Post-repas'),
(2, 1.05, '2025-06-03 08:00:00', 'A jeun');

-- Alertes générées par les mesures hors cible
INSERT IGNORE INTO alerte (id_patient, id_mesure, type_alerte, message, statut) VALUES
(1, 3, 'Hypoglycemie',   'Hypoglycémie détectée : 0.68 g/L. Prenez du sucre immédiatement.', 'Non lue'),
(1, 6, 'Hyperglycemie severe', 'Hyperglycémie sévère : 2.10 g/L. Consultez votre médecin.', 'Non lue');

INSERT IGNORE INTO traitement (nom_medicament, dosage, description) VALUES
('Metformine', '850mg', 'Antidiabétique oral de première intention'),
('Insuline Glargine', '10 UI', 'Insuline basale longue durée\'d\'action');

INSERT IGNORE INTO prescription (id_patient, id_traitement, frequence, heure_prise, date_debut, actif) VALUES
(1, 1, '2x/jour', '08:00, 20:00', '2025-01-15', 1),
(2, 2, '1x/jour', '22:00',        '2024-09-01', 1);

-- ── Tables notifications (ajoutées v2) ────────────────────────
ALTER TABLE patient
    ADD COLUMN IF NOT EXISTS notif_email TINYINT(1) DEFAULT 1 COMMENT 'Notifications email activées',
    ADD COLUMN IF NOT EXISTS notif_sms   TINYINT(1) DEFAULT 0 COMMENT 'Notifications SMS activées';

CREATE TABLE IF NOT EXISTS notification_log (
    id_log      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_patient  INT UNSIGNED NOT NULL,
    type_notif  ENUM('alerte_glycemie','rappel_inactivite') NOT NULL,
    envoye_le   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_patient) REFERENCES patient(id_patient) ON DELETE CASCADE,
    INDEX idx_patient_type_date (id_patient, type_notif, envoye_le)
) ENGINE=InnoDB;

-- ── Ajouts pour les notifications (Point 2) ──────────────────

-- Colonnes préférences notifications dans patient
ALTER TABLE patient
    ADD COLUMN IF NOT EXISTS notif_email TINYINT(1) DEFAULT 1,
    ADD COLUMN IF NOT EXISTS notif_sms   TINYINT(1) DEFAULT 0;

-- Log des notifications envoyées (cooldown anti-spam)
CREATE TABLE IF NOT EXISTS notification_log (
    id_log      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_patient  INT UNSIGNED NOT NULL,
    type_notif  ENUM('alerte_glycemie','rappel_inactivite') NOT NULL,
    envoye_le   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_patient) REFERENCES patient(id_patient) ON DELETE CASCADE,
    INDEX idx_patient_type_date (id_patient, type_notif, envoye_le)
) ENGINE=InnoDB;

-- ── Ajouts pour le 2FA (Point 4) ─────────────────────────────

-- Table des codes OTP temporaires
CREATE TABLE IF NOT EXISTS otp_code (
    id_otp      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    user_role   ENUM('patient','medecin') NOT NULL,
    code        CHAR(6)      NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used        TINYINT(1)   DEFAULT 0,
    tentatives  TINYINT      DEFAULT 0,
    cree_le     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, user_role),
    INDEX idx_expire (expires_at)
) ENGINE=InnoDB;

-- Colonne 2FA activé/désactivé dans chaque table utilisateur
ALTER TABLE patient  ADD COLUMN IF NOT EXISTS deux_fa_actif TINYINT(1) DEFAULT 0;
ALTER TABLE medecin  ADD COLUMN IF NOT EXISTS deux_fa_actif TINYINT(1) DEFAULT 0;

-- ── Messagerie interne (Point 5) ──────────────────────────────

CREATE TABLE IF NOT EXISTS message (
    id_message    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_expediteur INT UNSIGNED NOT NULL,
    role_expediteur ENUM('patient','medecin') NOT NULL,
    id_destinataire INT UNSIGNED NOT NULL,
    role_destinataire ENUM('patient','medecin') NOT NULL,
    sujet         VARCHAR(200) NOT NULL,
    contenu       TEXT         NOT NULL,
    lu            TINYINT(1)   DEFAULT 0,
    id_mesure_ref INT UNSIGNED DEFAULT NULL,   -- lien optionnel vers une mesure
    envoye_le     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_mesure_ref) REFERENCES mesure_glycemie(id_mesure) ON DELETE SET NULL,
    INDEX idx_dest (id_destinataire, role_destinataire, lu),
    INDEX idx_expe (id_expediteur, role_expediteur),
    INDEX idx_date (envoye_le)
) ENGINE=InnoDB;

-- ── Rappels de mesure intelligents (Point 6) ─────────────────

CREATE TABLE IF NOT EXISTS rappel_config (
    id_config       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_patient      INT UNSIGNED NOT NULL UNIQUE,
    -- Créneaux horaires souhaités (JSON: ["08:00","12:00","20:00"])
    creneaux        JSON         NOT NULL DEFAULT ('["08:00","12:00","20:00"]'),
    -- Jours actifs (JSON: [1,2,3,4,5,6,7] = tous les jours)
    jours_actifs    JSON         NOT NULL DEFAULT ('[1,2,3,4,5,6,7]'),
    -- Canal préféré
    canal_email     TINYINT(1)   DEFAULT 1,
    canal_sms       TINYINT(1)   DEFAULT 0,
    -- Tolérance : si mesure saisie dans les X min autour du créneau, pas de rappel
    tolerance_min   SMALLINT     DEFAULT 90,
    -- Actif / en pause
    actif           TINYINT(1)   DEFAULT 1,
    modifie_le      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_patient) REFERENCES patient(id_patient) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Log des rappels envoyés (évite les doublons)
CREATE TABLE IF NOT EXISTS rappel_log (
    id_log      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_patient  INT UNSIGNED NOT NULL,
    creneau     TIME         NOT NULL,
    canal       ENUM('email','sms') NOT NULL,
    envoye_le   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_patient) REFERENCES patient(id_patient) ON DELETE CASCADE,
    INDEX idx_patient_creneau (id_patient, creneau, envoye_le)
) ENGINE=InnoDB;

-- Insérer config par défaut pour les patients existants
INSERT IGNORE INTO rappel_config (id_patient)
SELECT id_patient FROM patient;

-- ── Espace administrateur (Point 8) ──────────────────────────

CREATE TABLE IF NOT EXISTS admin (
    id_admin        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(80)  NOT NULL,
    prenom          VARCHAR(80)  NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    mot_de_passe    VARCHAR(255) NOT NULL,
    role_admin      ENUM('super_admin','moderateur') DEFAULT 'moderateur',
    date_creation   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME DEFAULT NULL
) ENGINE=InnoDB;

-- Logs d'audit : trace toutes les actions sensibles de la plateforme
CREATE TABLE IF NOT EXISTS audit_log (
    id_audit        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    acteur_id       INT UNSIGNED NOT NULL,
    acteur_role     ENUM('patient','medecin','admin') NOT NULL,
    action          VARCHAR(100) NOT NULL,      -- ex: 'login', 'suppression_compte', 'modification_role'
    cible_type      VARCHAR(50)  DEFAULT NULL,  -- ex: 'patient', 'medecin', 'ordonnance'
    cible_id        INT UNSIGNED DEFAULT NULL,
    details         TEXT         DEFAULT NULL,
    ip_adresse      VARCHAR(45)  DEFAULT NULL,
    cree_le         DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acteur (acteur_id, acteur_role),
    INDEX idx_action (action),
    INDEX idx_date (cree_le)
) ENGINE=InnoDB;

-- Compte admin de test (mot de passe : Diabsuivi2025!)
INSERT IGNORE INTO admin (nom, prenom, email, mot_de_passe, role_admin) VALUES
(
    'Admin', 'Système',
    'admin@diabsuivi.sn',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'super_admin'
);
