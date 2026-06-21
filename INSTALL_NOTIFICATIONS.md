# DiabSuivi — Guide d'installation des Notifications

## Vue d'ensemble

Le système de notifications envoie automatiquement des **emails** (PHPMailer)
et des **SMS** (Twilio) quand :
- Une mesure glycémique dépasse les seuils (hors cible)
- Un patient n'a pas saisi de mesure depuis 24h

---

## Étape 1 — Installer PHPMailer

### Option A : avec Composer (recommandé)
```bash
# Dans le dossier project/
composer require phpmailer/phpmailer
```

### Option B : sans Composer (XAMPP Windows)
1. Télécharger PHPMailer : https://github.com/PHPMailer/PHPMailer/releases
2. Extraire le dossier `src/` dans `project/vendor/phpmailer/src/`
3. Structure attendue :
```
project/vendor/phpmailer/src/
    ├── PHPMailer.php
    ├── SMTP.php
    └── Exception.php
```

---

## Étape 2 — Configurer Gmail (SMTP gratuit)

1. Connectez-vous à votre compte Google
2. Allez dans **Sécurité → Validation en 2 étapes** (activez-la si ce n'est pas fait)
3. Allez dans **Sécurité → Mots de passe des applications**
4. Créez un mot de passe pour "Application : Autre" → nommez-le "DiabSuivi"
5. Copiez le mot de passe à 16 caractères généré

Dans votre fichier `.env` :
```env
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USER=votre.email@gmail.com
MAIL_PASS=xxxx xxxx xxxx xxxx   ← le mot de passe à 16 caractères
MAIL_FROM=votre.email@gmail.com
MAIL_FROM_NAME=DiabSuivi
```

---

## Étape 3 — Configurer Twilio (SMS)

1. Créez un compte sur https://www.twilio.com/try-twilio
   (15$ de crédit offert = ~150 SMS)
2. Dans la console Twilio, notez :
   - **Account SID** (commence par AC...)
   - **Auth Token**
3. Achetez un numéro Twilio (ou utilisez le numéro de test gratuit)
4. Vérifiez votre numéro de téléphone personnel dans la console Twilio
   (compte gratuit = SMS uniquement vers numéros vérifiés)

Dans votre fichier `.env` :
```env
TWILIO_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_TOKEN=your_auth_token_here
TWILIO_FROM=+12025551234   ← votre numéro Twilio
```

---

## Étape 4 — Mettre à jour la base de données

Exécutez ces requêtes dans phpMyAdmin (onglet SQL) :

```sql
ALTER TABLE patient
    ADD COLUMN IF NOT EXISTS notif_email TINYINT(1) DEFAULT 1,
    ADD COLUMN IF NOT EXISTS notif_sms   TINYINT(1) DEFAULT 0;

CREATE TABLE IF NOT EXISTS notification_log (
    id_log      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_patient  INT UNSIGNED NOT NULL,
    type_notif  ENUM('alerte_glycemie','rappel_inactivite') NOT NULL,
    envoye_le   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_patient) REFERENCES patient(id_patient) ON DELETE CASCADE,
    INDEX idx_patient_type_date (id_patient, type_notif, envoye_le)
) ENGINE=InnoDB;
```

---

## Étape 5 — Charger le fichier .env au démarrage (XAMPP)

Ajoutez ces lignes au début de `db/connexion.php` pour charger automatiquement `.env` :

```php
// Charger .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
        putenv(trim($key) . '=' . trim($val));
    }
}
```

---

## Étape 6 — Configurer le CRON pour les rappels d'inactivité

### Sur Windows (Planificateur de tâches)
1. Ouvrir le **Planificateur de tâches Windows** (chercher dans le menu Démarrer)
2. Cliquer **Créer une tâche de base**
3. Nom : `DiabSuivi - Rappels inactivité`
4. Déclencheur : **Quotidiennement**, répéter toutes les **1 heure**
5. Action : **Démarrer un programme**
   - Programme : `C:\xampp\php\php.exe`
   - Arguments : `C:\xampp\htdocs\diabsuivi\project\cron\rappels_inactivite.php`
6. Terminer → OK

### Sur Linux/Mac (crontab)
```bash
# Ouvrir le crontab
crontab -e

# Ajouter cette ligne (exécution toutes les heures)
0 * * * * php /var/www/html/diabsuivi/project/cron/rappels_inactivite.php >> /var/log/diabsuivi_cron.log 2>&1
```

### Test manuel (vérifier que tout fonctionne)
```bash
php project/cron/rappels_inactivite.php
```

---

## Tester les notifications

1. Connectez-vous en tant que patient
2. Allez dans **⚙️ Notifs** dans la navbar
3. Activez Email et/ou SMS, enregistrez votre numéro
4. Saisissez une mesure hors cible (ex: `0.50 g/L` à jeun)
5. Vous devriez recevoir email + SMS en quelques secondes

---

## Résolution de problèmes courants

| Problème | Solution |
|---|---|
| Email non reçu | Vérifiez les spams · Vérifiez MAIL_USER/PASS dans .env |
| Erreur SMTP 535 | Le mot de passe Gmail est incorrect · Recréez un "mot de passe d'application" |
| SMS non reçu | Sur compte Twilio gratuit, votre numéro doit être vérifié dans la console |
| Erreur cURL Twilio | Vérifiez TWILIO_SID et TWILIO_TOKEN · Activez curl dans php.ini |
| Notifications doublées | Normal si cooldown dépassé · Délai par défaut : 60 min entre deux alertes |

---

## Rappels de mesure intelligents (Point 6)

### Deux scripts CRON à configurer

| Script | Fréquence | Rôle |
|---|---|---|
| `cron/rappels_inactivite.php` | Toutes les heures | Patients sans mesure depuis 24h |
| `cron/rappels_mesure.php` | Toutes les 15 min | Rappels aux créneaux configurés |

### Windows — Planificateur de tâches (rappels_mesure)
1. Nouvelle tâche → **Répéter toutes les 15 minutes**
2. Programme : `C:\xampp\php\php.exe`
3. Arguments : `C:\xampp\htdocs\diabsuivi\project\cron\rappels_mesure.php`

### Linux/Mac (crontab -e)
```bash
# Rappels inactivité (toutes les heures)
0 * * * * php /var/www/html/diabsuivi/project/cron/rappels_inactivite.php >> /var/log/diabsuivi_cron.log 2>&1

# Rappels créneaux (toutes les 15 minutes)
*/15 * * * * php /var/www/html/diabsuivi/project/cron/rappels_mesure.php >> /var/log/diabsuivi_rappels.log 2>&1
```

### Test manuel
```bash
# Simulation sans envoi
php cron/rappels_mesure.php --dry-run

# Envoi réel
php cron/rappels_mesure.php
```
