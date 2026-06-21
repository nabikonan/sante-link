#!/usr/bin/env python3
"""
DiabSuivi — Module IA : prédiction du risque glycémique v3
Améliorations Point 7 :
  - HbA1c estimé (formule ADAG, basée sur la glycémie moyenne 90j)
  - Courbe de probabilité de risque dans le temps (évolution sur 10 derniers points)
  - Recommandations personnalisées selon le type de diabète du patient

Utilise Random Forest avec feature engineering sur l'historique patient.
"""

import sys
import os
import json
import argparse
from datetime import datetime
from pathlib import Path

try:
    from dotenv import load_dotenv
    load_dotenv(Path(__file__).parent.parent / '.env')
except ImportError:
    pass

DB_CONFIG = {
    'host':     os.environ.get('DB_HOST',    'localhost'),
    'database': os.environ.get('DB_NAME',    'diabsuivi'),
    'user':     os.environ.get('DB_USER',    'diabsuivi_user'),
    'password': os.environ.get('DB_PASS',    ''),
    'charset':  'utf8mb4',
}

MODEL_DIR = Path(__file__).parent / 'models'
MODEL_DIR.mkdir(exist_ok=True)

parser = argparse.ArgumentParser(description='Prédiction risque diabétique')
parser.add_argument('--patient', type=int, required=True, help='ID patient')
args = parser.parse_args()

if args.patient <= 0:
    print(json.dumps({'error': 'ID patient invalide'}))
    sys.exit(1)

id_patient = args.patient

try:
    import numpy as np
    import pandas as pd
    from sklearn.ensemble import RandomForestClassifier
    from sklearn.model_selection import train_test_split
    import joblib
    import mysql.connector
except ImportError as e:
    print(json.dumps({'error': f'Dépendance manquante : {e}'}))
    sys.exit(1)

# ── Connexion BDD ─────────────────────────────────────────────
try:
    conn   = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor(dictionary=True)
except mysql.connector.Error as e:
    print(json.dumps({'error': f'Connexion BDD échouée : {e.errno}'}))
    sys.exit(1)

# ── Récupérer infos patient (pour personnalisation) ───────────
cursor.execute("""
    SELECT type_diabete, date_naissance, sexe
    FROM   patient WHERE id_patient = %s
""", (id_patient,))
infos_patient = cursor.fetchone() or {}
type_diabete  = infos_patient.get('type_diabete', 'Type 2')

# ── Récupérer les mesures (200 dernières, + 90j pour HbA1c) ───
cursor.execute("""
    SELECT valeur_glycemie, date_heure, contexte
    FROM   mesure_glycemie
    WHERE  id_patient = %s
    ORDER  BY date_heure DESC
    LIMIT  300
""", (id_patient,))
rows = cursor.fetchall()
cursor.close()
conn.close()

if len(rows) < 10:
    print(json.dumps({
        'risque':         'Indéterminé',
        'probabilite':    None,
        'message':        'Données insuffisantes (minimum 10 mesures requises).',
        'nb_mesures':     len(rows),
        'type_diabete':   type_diabete,
        'recommandations': [
            'Continuez à enregistrer vos mesures régulièrement.',
            'La prédiction sera disponible après 10 mesures.',
        ],
    }))
    sys.exit(0)

# ── Feature engineering ───────────────────────────────────────
df = pd.DataFrame(rows)
df['date_heure'] = pd.to_datetime(df['date_heure'])
df = df.sort_values('date_heure').reset_index(drop=True)

df['heure']      = df['date_heure'].dt.hour
df['jour_sem']   = df['date_heure'].dt.dayofweek
df['mois']       = df['date_heure'].dt.month
df['est_matin']  = (df['heure'].between(6, 10)).astype(int)
df['est_soir']   = (df['heure'].between(18, 22)).astype(int)

contexte_map = {'A jeun': 0, 'Post-repas': 1, 'Avant sport': 2, 'Apres sport': 3, 'Autre': 4}
df['contexte_enc'] = df['contexte'].map(contexte_map).fillna(4).astype(int)

def est_hors_cible(row):
    v, c = row['valeur_glycemie'], row['contexte']
    if v < 0.70 or v > 2.00:          return 1
    if c == 'A jeun'     and v > 1.10: return 1
    if c == 'Post-repas' and v > 1.40: return 1
    return 0

df['hors_cible'] = df.apply(est_hors_cible, axis=1)
df['moy_5']      = df['valeur_glycemie'].rolling(5, min_periods=1).mean()
df['std_5']      = df['valeur_glycemie'].rolling(5, min_periods=1).std().fillna(0)
df['tendance']   = df['valeur_glycemie'].diff().fillna(0)

FEATURES = ['valeur_glycemie','heure','jour_sem','mois',
            'est_matin','est_soir','contexte_enc',
            'moy_5','std_5','tendance']

X = df[FEATURES].values
y = df['hors_cible'].values

# ════════════════════════════════════════════════════════════
# AMÉLIORATION 1 — HbA1c estimé (formule ADAG)
# HbA1c (%) = (glycémie_moyenne_mg/dL + 46.7) / 28.7
# Conversion g/L → mg/dL : x100
# Basé sur les mesures des 90 derniers jours (norme clinique).
# ════════════════════════════════════════════════════════════
def calculer_hba1c_estime(df):
    limite_90j = pd.Timestamp.now() - pd.Timedelta(days=90)
    df_90j = df[df['date_heure'] >= limite_90j]

    if len(df_90j) < 5:
        return None  # Pas assez de données sur 90j

    moyenne_gL    = df_90j['valeur_glycemie'].mean()
    moyenne_mgdL  = moyenne_gL * 100
    hba1c         = (moyenne_mgdL + 46.7) / 28.7

    # Catégorisation clinique standard
    if hba1c < 5.7:
        categorie, niveau = 'Normal', 'success'
    elif hba1c < 6.5:
        categorie, niveau = 'Pré-diabète', 'warning'
    elif hba1c < 7.0:
        categorie, niveau = 'Diabète bien contrôlé', 'success'
    elif hba1c < 8.0:
        categorie, niveau = 'Diabète modérément contrôlé', 'warning'
    else:
        categorie, niveau = 'Diabète mal contrôlé', 'danger'

    return {
        'valeur':       round(hba1c, 1),
        'categorie':    categorie,
        'niveau':       niveau,
        'nb_mesures_90j': len(df_90j),
        'moyenne_gL':   round(moyenne_gL, 2),
        'objectif':     '< 7.0%' if type_diabete in ('Type 1', 'Type 2') else '< 6.5%',
    }

hba1c_info = calculer_hba1c_estime(df)

# ── Cas : une seule classe ─────────────────────────────────────
if len(set(y)) < 2:
    unique_class = int(y[0])
    print(json.dumps({
        'risque':         'Élevé' if unique_class == 1 else 'Faible',
        'probabilite':    1.0     if unique_class == 1 else 0.0,
        'message':        'Toutes vos mesures sont hors cible.' if unique_class == 1
                          else 'Toutes vos mesures sont dans la cible.',
        'nb_mesures':     len(df),
        'type_diabete':   type_diabete,
        'hba1c':          hba1c_info,
        'recommandations': ['Consultez votre médecin.'] if unique_class == 1 else [],
    }, ensure_ascii=False))
    sys.exit(0)

# ── Entraînement ou chargement du modèle ─────────────────────
MODEL_PATH = MODEL_DIR / f'patient_{id_patient}.pkl'
RETRAIN_THRESHOLD = 5

def train_model(X, y):
    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=42, stratify=y
    )
    clf = RandomForestClassifier(
        n_estimators=100, class_weight='balanced',
        random_state=42, n_jobs=-1,
    )
    clf.fit(X_train, y_train)
    return clf

if len(df) < 50 or not MODEL_PATH.exists():
    model = train_model(X, y)
    joblib.dump({'model': model, 'n': len(df)}, MODEL_PATH)
else:
    cached = joblib.load(MODEL_PATH)
    if len(df) - cached.get('n', 0) >= RETRAIN_THRESHOLD:
        model = train_model(X, y)
        joblib.dump({'model': model, 'n': len(df)}, MODEL_PATH)
    else:
        model = cached['model']

classes = model.classes_
idx_risque = list(classes).index(1) if 1 in classes else 0

# ════════════════════════════════════════════════════════════
# AMÉLIORATION 2 — Courbe de probabilité dans le temps
# Calcule la probabilité de risque pour chacune des 10 dernières
# mesures (pas seulement la dernière) pour visualiser la tendance.
# ════════════════════════════════════════════════════════════
def calculer_courbe_probabilite(model, X, df, n_points=10):
    n_points = min(n_points, len(X))
    indices  = range(len(X) - n_points, len(X))
    courbe   = []
    for i in indices:
        proba = model.predict_proba(X[i].reshape(1, -1))[0]
        prob_risque = float(proba[idx_risque]) if idx_risque < len(proba) else 0.0
        courbe.append({
            'date':        df.iloc[i]['date_heure'].strftime('%Y-%m-%d %H:%M'),
            'valeur':      float(df.iloc[i]['valeur_glycemie']),
            'probabilite': round(prob_risque, 3),
        })
    return courbe

courbe_probabilite = calculer_courbe_probabilite(model, X, df, n_points=10)

# ── Prédiction sur la dernière mesure ────────────────────────
derniere = X[-1].reshape(1, -1)
proba    = model.predict_proba(derniere)[0]
prob_risque = float(proba[idx_risque])

if prob_risque >= 0.70:
    niveau, badge = 'Élevé',  'danger'
elif prob_risque >= 0.45:
    niveau, badge = 'Modéré', 'warning'
else:
    niveau, badge = 'Faible', 'success'

# Tendance de la courbe de probabilité (régression linéaire simple)
if len(courbe_probabilite) >= 3:
    probs = [p['probabilite'] for p in courbe_probabilite]
    x_idx = list(range(len(probs)))
    pente = np.polyfit(x_idx, probs, 1)[0]
    if pente > 0.03:
        tendance_risque = 'hausse'
    elif pente < -0.03:
        tendance_risque = 'baisse'
    else:
        tendance_risque = 'stable'
else:
    tendance_risque = 'stable'

# ════════════════════════════════════════════════════════════
# AMÉLIORATION 3 — Recommandations personnalisées par type
# ════════════════════════════════════════════════════════════
def generer_recommandations(type_diabete, last, hba1c_info, tendance_risque, pct_hors_cible):
    recs = []
    v   = float(last['valeur_glycemie'])
    ctx = last['contexte']

    # Recommandations génériques sur la dernière mesure
    if v < 0.70:
        recs.append('Hypoglycémie : prenez 15g de sucre rapide immédiatement, recontrôlez dans 15 min.')
    elif v > 2.00:
        recs.append('Hyperglycémie sévère : contactez votre médecin dès que possible.')
    elif ctx == 'A jeun' and v > 1.10:
        recs.append('Glycémie à jeun élevée : évitez les glucides le soir.')
    elif ctx == 'Post-repas' and v > 1.40:
        recs.append('Glycémie post-prandiale élevée : réduisez les portions de glucides au prochain repas.')

    # ── Personnalisation par type de diabète ──────────────────
    if type_diabete == 'Type 1':
        recs.append('Type 1 : vérifiez le calcul de vos doses d\'insuline rapide en fonction des glucides ingérés.')
        if tendance_risque == 'hausse':
            recs.append('Tendance à la hausse : un ajustement de votre ratio insuline/glucides peut être nécessaire avec votre diabétologue.')
        if v < 0.70:
            recs.append('Resucrage Type 1 : privilégiez un sucre à absorption rapide (jus de fruit, sucre en morceaux) puis une collation lente.')

    elif type_diabete == 'Type 2':
        recs.append('Type 2 : l\'activité physique régulière (30 min/jour) améliore la sensibilité à l\'insuline.')
        if pct_hors_cible > 30:
            recs.append('Plus de 30% de mesures hors cible : discutez d\'un ajustement de votre traitement oral avec votre médecin.')

    elif type_diabete == 'Gestationnel':
        recs.append('Diabète gestationnel : un contrôle strict est essentiel pour la santé du bébé. Privilégiez les repas fractionnés.')
        recs.append('Continuez le suivi rapproché avec votre gynécologue-obstétricien.')

    elif type_diabete == 'LADA':
        recs.append('LADA : la progression peut être plus lente que le Type 1 classique, mais une surveillance régulière de la fonction des cellules bêta est recommandée.')

    else:
        recs.append('Maintenez une alimentation équilibrée et une activité physique régulière.')

    # ── Recommandations basées sur HbA1c ───────────────────────
    if hba1c_info:
        if hba1c_info['niveau'] == 'danger':
            recs.append(f"HbA1c estimée à {hba1c_info['valeur']}% : objectif {hba1c_info['objectif']} non atteint, un point avec votre médecin est recommandé.")
        elif hba1c_info['niveau'] == 'success' and hba1c_info['categorie'] != 'Normal':
            recs.append(f"HbA1c estimée à {hba1c_info['valeur']}% : bon contrôle, continuez ainsi !")

    # ── Tendance ─────────────────────────────────────────────
    if tendance_risque == 'hausse':
        recs.append('📈 Le risque de déséquilibre augmente sur vos dernières mesures : restez vigilant.')
    elif tendance_risque == 'baisse':
        recs.append('📉 Le risque diminue sur vos dernières mesures : bonne évolution, continuez !')

    if not recs:
        recs.append('Continuez à surveiller régulièrement votre glycémie.')

    return recs

last = df.iloc[-1]
pct_hors_cible = int(y.mean() * 100)

recs = generer_recommandations(type_diabete, last, hba1c_info, tendance_risque, pct_hors_cible)

stats = {
    'moyenne':        round(float(df['valeur_glycemie'].mean()), 2),
    'min':            round(float(df['valeur_glycemie'].min()),  2),
    'max':            round(float(df['valeur_glycemie'].max()),  2),
    'pct_hors_cible': pct_hors_cible,
    'nb_mesures':     len(df),
}

print(json.dumps({
    'risque':              niveau,
    'badge':               badge,
    'probabilite':          round(prob_risque, 3),
    'tendance_risque':      tendance_risque,
    'message':              f'Risque {niveau.lower()} de déséquilibre glycémique.',
    'recommandations':      recs,
    'stats':                stats,
    'hba1c':                hba1c_info,
    'courbe_probabilite':   courbe_probabilite,
    'type_diabete':         type_diabete,
    'derniere_valeur':      float(last['valeur_glycemie']),
    'dernier_contexte':     last['contexte'],
    'generee_le':           datetime.now().strftime('%d/%m/%Y à %Hh%M'),
}, ensure_ascii=False))
