#!/usr/bin/env python3
"""
DiabSuivi — Génération de rapport PDF patient
Utilise ReportLab (déjà dans requirements IA).

Appelé depuis export_pdf.php :
  python3 generate_rapport.py --patient 1 --periode 30 --output /tmp/rapport_1.pdf
"""

import sys
import os
import json
import argparse
from datetime import datetime, timedelta
from pathlib import Path

# Charger .env si disponible
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

# ── Arguments ─────────────────────────────────────────────────
parser = argparse.ArgumentParser()
parser.add_argument('--patient', type=int, required=True)
parser.add_argument('--periode', type=int, default=30, choices=[7, 30, 90])
parser.add_argument('--output',  type=str, required=True)
args = parser.parse_args()

if args.patient <= 0:
    print(json.dumps({'error': 'ID patient invalide'}))
    sys.exit(1)

# ── Imports ───────────────────────────────────────────────────
try:
    import mysql.connector
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.units import cm
    from reportlab.lib import colors
    from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
    from reportlab.platypus import (
        SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
        HRFlowable, PageBreak
    )
    from reportlab.graphics.shapes import Drawing, Rect, Line, String
    from reportlab.graphics import renderPDF
    from reportlab.graphics.charts.lineplots import LinePlot
    from reportlab.graphics.widgets.markers import makeMarker
    import io
except ImportError as e:
    print(json.dumps({'error': f'Dépendance manquante : {e}'}))
    sys.exit(1)

# ── Connexion BDD ─────────────────────────────────────────────
try:
    conn   = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor(dictionary=True)
except mysql.connector.Error as e:
    print(json.dumps({'error': f'BDD : {e.errno}'}))
    sys.exit(1)

id_patient = args.patient
periode    = args.periode
date_debut = (datetime.now() - timedelta(days=periode)).strftime('%Y-%m-%d')

# ── Données patient ───────────────────────────────────────────
cursor.execute("SELECT * FROM patient WHERE id_patient = %s", (id_patient,))
patient = cursor.fetchone()
if not patient:
    print(json.dumps({'error': 'Patient introuvable'}))
    sys.exit(1)

# ── Médecin référent ─────────────────────────────────────────
cursor.execute("""
    SELECT m.nom, m.prenom, m.specialite, m.email
    FROM medecin m JOIN suivi s ON s.id_medecin = m.id_medecin
    WHERE s.id_patient = %s AND s.actif = 1 LIMIT 1
""", (id_patient,))
medecin = cursor.fetchone()

# ── Mesures ───────────────────────────────────────────────────
cursor.execute("""
    SELECT valeur_glycemie, date_heure, contexte, commentaire
    FROM   mesure_glycemie
    WHERE  id_patient = %s AND date_heure >= %s
    ORDER  BY date_heure ASC
""", (id_patient, date_debut))
mesures = cursor.fetchall()

# ── Stats ─────────────────────────────────────────────────────
cursor.execute("""
    SELECT
        ROUND(AVG(valeur_glycemie), 2) AS moyenne,
        MIN(valeur_glycemie)            AS min_val,
        MAX(valeur_glycemie)            AS max_val,
        COUNT(*)                        AS nb,
        ROUND(SUM(CASE
            WHEN valeur_glycemie < 0.70 OR valeur_glycemie > 2.00
              OR (contexte='A jeun'     AND valeur_glycemie > 1.10)
              OR (contexte='Post-repas' AND valeur_glycemie > 1.40)
            THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS pct_hors_cible
    FROM mesure_glycemie
    WHERE id_patient = %s AND date_heure >= %s
""", (id_patient, date_debut))
stats = cursor.fetchone()

# ── Stats par contexte ────────────────────────────────────────
cursor.execute("""
    SELECT contexte,
           COUNT(*) AS nb,
           ROUND(AVG(valeur_glycemie), 2) AS moy,
           MIN(valeur_glycemie) AS min_v,
           MAX(valeur_glycemie) AS max_v
    FROM   mesure_glycemie
    WHERE  id_patient = %s AND date_heure >= %s
    GROUP  BY contexte
    ORDER  BY nb DESC
""", (id_patient, date_debut))
par_contexte = cursor.fetchall()

# ── Traitements actifs ────────────────────────────────────────
cursor.execute("""
    SELECT t.nom_medicament, t.dosage, p.frequence, p.heure_prise, p.date_debut
    FROM prescription p JOIN traitement t ON t.id_traitement = p.id_traitement
    WHERE p.id_patient = %s AND p.actif = 1
""", (id_patient,))
traitements = cursor.fetchall()

cursor.close()
conn.close()

# ── Couleurs DiabSuivi ────────────────────────────────────────
VERT       = colors.HexColor('#1D9E75')
VERT_L     = colors.HexColor('#E1F5EE')
ROUGE      = colors.HexColor('#E24B4A')
ROUGE_L    = colors.HexColor('#FCEBEB')
AMBER      = colors.HexColor('#EF9F27')
AMBER_L    = colors.HexColor('#FAEEDA')
BLEU       = colors.HexColor('#185FA5')
BLEU_L     = colors.HexColor('#E6F1FB')
GRIS_L     = colors.HexColor('#F5F8F7')
GRIS_BORD  = colors.HexColor('#E2E6EC')
GRIS_TXT   = colors.HexColor('#5A6272')
NOIR       = colors.HexColor('#1A1A1A')

# ── Styles ────────────────────────────────────────────────────
styles = getSampleStyleSheet()

def style(name, **kw):
    return ParagraphStyle(name, **kw)

TITLE    = style('Title2',    fontName='Helvetica-Bold',   fontSize=20, textColor=NOIR,   spaceAfter=4)
H1       = style('H1_',       fontName='Helvetica-Bold',   fontSize=13, textColor=VERT,   spaceAfter=6,  spaceBefore=14)
H2       = style('H2_',       fontName='Helvetica-Bold',   fontSize=11, textColor=NOIR,   spaceAfter=4,  spaceBefore=10)
NORMAL   = style('Normal_',   fontName='Helvetica',        fontSize=9,  textColor=NOIR,   spaceAfter=4,  leading=14)
SMALL    = style('Small_',    fontName='Helvetica',        fontSize=8,  textColor=GRIS_TXT, spaceAfter=2)
BOLD     = style('Bold_',     fontName='Helvetica-Bold',   fontSize=9,  textColor=NOIR)
CENTER   = style('Center_',   fontName='Helvetica',        fontSize=9,  textColor=NOIR,   alignment=1)
CAPTION  = style('Caption_',  fontName='Helvetica-Oblique',fontSize=8,  textColor=GRIS_TXT, alignment=1, spaceAfter=10)

def couleur_valeur(val, ctx=''):
    if val < 0.70 or val > 2.00: return ROUGE
    if ctx == 'A jeun'    and val > 1.10: return AMBER
    if ctx == 'Post-repas' and val > 1.40: return AMBER
    return VERT

# ── Mini-graphique linéaire ReportLab ─────────────────────────
def mini_graph(mesures_list, width=14*cm, height=5*cm):
    if len(mesures_list) < 2:
        return None

    d = Drawing(width, height)

    # Fond
    d.add(Rect(0, 0, width, height, fillColor=GRIS_L, strokeColor=GRIS_BORD, strokeWidth=0.5))

    vals  = [float(m['valeur_glycemie']) for m in mesures_list]
    n     = len(vals)
    ymin  = max(0.3, min(vals) - 0.2)
    ymax  = min(3.0, max(vals) + 0.3)
    yrange = ymax - ymin or 1

    pad_l, pad_r, pad_t, pad_b = 30, 10, 10, 20

    def px(i):  return pad_l + (i / (n-1)) * (width - pad_l - pad_r)
    def py(v):  return pad_b + ((v - ymin) / yrange) * (height - pad_t - pad_b)

    # Lignes de référence
    for ref, col in [(0.70, ROUGE), (1.10, VERT), (1.40, AMBER), (2.00, ROUGE)]:
        if ymin <= ref <= ymax:
            y = py(ref)
            l = Line(pad_l, y, width - pad_r, y)
            l.strokeColor = col
            l.strokeDashArray = [3, 3]
            l.strokeWidth = 0.7
            d.add(l)
            lbl = String(pad_l - 2, y + 1, f'{ref}', fontSize=6, fillColor=col, textAnchor='end')
            d.add(lbl)

    # Courbe
    for i in range(n - 1):
        x1, y1 = px(i),   py(vals[i])
        x2, y2 = px(i+1), py(vals[i+1])
        seg = Line(x1, y1, x2, y2)
        seg.strokeColor = BLEU
        seg.strokeWidth = 1.2
        d.add(seg)

    # Points
    for i, v in enumerate(vals):
        x, y = px(i), py(v)
        col  = couleur_valeur(v, mesures_list[i].get('contexte', ''))
        dot  = Rect(x-2.5, y-2.5, 5, 5, fillColor=col, strokeColor=colors.white, strokeWidth=0.5)
        d.add(dot)

    # Labels axe X (dates)
    step = max(1, n // 6)
    for i in range(0, n, step):
        dt  = mesures_list[i]['date_heure']
        lbl_txt = dt.strftime('%d/%m') if hasattr(dt, 'strftime') else str(dt)[:5]
        lbl = String(px(i), pad_b - 14, lbl_txt, fontSize=6, fillColor=GRIS_TXT, textAnchor='middle')
        d.add(lbl)

    return d

# ── Construction du PDF ───────────────────────────────────────
output_path = args.output
doc = SimpleDocTemplate(
    output_path,
    pagesize=A4,
    leftMargin=2*cm, rightMargin=2*cm,
    topMargin=2.5*cm, bottomMargin=2*cm,
    title=f'Rapport DiabSuivi — {patient["prenom"]} {patient["nom"]}',
    author='DiabSuivi',
)

story = []
W = A4[0] - 4*cm   # largeur utile

# ── Page de garde ─────────────────────────────────────────────
# Bandeau titre
story.append(Table(
    [[Paragraph('♥ DiabSuivi', style('Logo', fontName='Helvetica-Bold', fontSize=22,
                                      textColor=colors.white)),
      Paragraph(f'Rapport glycémique<br/><font size="10">{periode} derniers jours</font>',
                style('Sub', fontName='Helvetica', fontSize=13, textColor=colors.white, alignment=2))]],
    colWidths=[W*0.6, W*0.4],
    style=TableStyle([
        ('BACKGROUND',   (0,0), (-1,-1), VERT),
        ('TOPPADDING',   (0,0), (-1,-1), 14),
        ('BOTTOMPADDING',(0,0), (-1,-1), 14),
        ('LEFTPADDING',  (0,0), (-1,-1), 14),
        ('RIGHTPADDING', (0,0), (-1,-1), 14),
        ('VALIGN',       (0,0), (-1,-1), 'MIDDLE'),
    ])
))
story.append(Spacer(1, 0.4*cm))

# Infos patient + médecin côte à côte
now_str   = datetime.now().strftime('%d/%m/%Y à %Hh%M')
naissance = patient.get('date_naissance')
age_str   = ''
if naissance:
    try:
        nb = naissance if hasattr(naissance, 'year') else datetime.strptime(str(naissance), '%Y-%m-%d')
        age_str = f'{(datetime.now() - nb).days // 365} ans'
    except Exception:
        age_str = ''

pat_lines = [
    f"<b>Patient :</b> {patient['prenom']} {patient['nom']}",
    f"<b>Né(e) le :</b> {naissance} {('· ' + age_str) if age_str else ''}",
    f"<b>Sexe :</b> {'Masculin' if patient.get('sexe') == 'M' else 'Féminin'}",
    f"<b>Diabète :</b> {patient.get('type_diabete', 'N/A')}",
    f"<b>Email :</b> {patient.get('email', '')}",
]
med_lines = [
    f"<b>Médecin référent</b>",
    f"Dr {medecin['prenom']} {medecin['nom']}" if medecin else "Non renseigné",
    medecin.get('specialite', '') if medecin else '',
    medecin.get('email', '')      if medecin else '',
    '',
    f"<b>Généré le :</b> {now_str}",
    f"<b>Période :</b> {date_debut} → {datetime.now().strftime('%Y-%m-%d')}",
]

def info_cell(lines):
    return [Paragraph(l, NORMAL) for l in lines if l is not None]

info_table = Table(
    [[info_cell(pat_lines), info_cell(med_lines)]],
    colWidths=[W*0.55, W*0.45],
    style=TableStyle([
        ('BACKGROUND',    (0,0), (0,-1), BLEU_L),
        ('BACKGROUND',    (1,0), (1,-1), VERT_L),
        ('TOPPADDING',    (0,0), (-1,-1), 12),
        ('BOTTOMPADDING', (0,0), (-1,-1), 12),
        ('LEFTPADDING',   (0,0), (-1,-1), 12),
        ('RIGHTPADDING',  (0,0), (-1,-1), 12),
        ('VALIGN',        (0,0), (-1,-1), 'TOP'),
        ('BOX',           (0,0), (-1,-1), 0.5, GRIS_BORD),
        ('LINEAFTER',     (0,0), (0,-1), 0.5, GRIS_BORD),
    ])
)
story.append(info_table)
story.append(Spacer(1, 0.5*cm))

# ── Section 1 : Résumé statistique ───────────────────────────
story.append(Paragraph('1. Résumé statistique', H1))
story.append(HRFlowable(width=W, color=VERT, thickness=1.5, spaceAfter=10))

if not mesures:
    story.append(Paragraph('Aucune mesure enregistrée sur cette période.', NORMAL))
else:
    pct = float(stats['pct_hors_cible'] or 0)
    pct_col = ROUGE if pct > 30 else (AMBER if pct > 15 else VERT)

    stat_data = [
        [Paragraph('<b>Indicateur</b>', BOLD), Paragraph('<b>Valeur</b>', BOLD),
         Paragraph('<b>Référence</b>', BOLD), Paragraph('<b>Statut</b>', BOLD)],
        ['Moyenne glycémique',
         Paragraph(f'<b>{stats["moyenne"]} g/L</b>', BOLD),
         '0.70 – 1.10 g/L (à jeun)',
         Paragraph('✅ Normale' if 0.70 <= float(stats["moyenne"] or 0) <= 1.40 else '⚠️ Élevée', NORMAL)],
        ['Valeur minimale',    f'{stats["min_val"]} g/L',  '> 0.70 g/L',
         Paragraph('✅ OK' if float(stats["min_val"] or 0) >= 0.70 else '🚨 Hypoglycémie', NORMAL)],
        ['Valeur maximale',    f'{stats["max_val"]} g/L',  '< 2.00 g/L',
         Paragraph('✅ OK' if float(stats["max_val"] or 0) <= 2.00 else '🚨 Élevée', NORMAL)],
        ['Nombre de mesures',  str(stats['nb']),            f'/ {periode} jours', ''],
        ['% mesures hors cible',
         Paragraph(f'<b>{pct}%</b>', style('PctB', fontName='Helvetica-Bold', fontSize=9, textColor=pct_col)),
         'Cible : < 15%',
         Paragraph('✅ Bon contrôle' if pct <= 15 else ('⚠️ À surveiller' if pct <= 30 else '🚨 Déséquilibré'), NORMAL)],
    ]

    stat_table = Table(stat_data, colWidths=[W*0.28, W*0.20, W*0.30, W*0.22])
    stat_table.setStyle(TableStyle([
        ('BACKGROUND',    (0,0), (-1,0),  GRIS_L),
        ('FONTNAME',      (0,0), (-1,0),  'Helvetica-Bold'),
        ('FONTSIZE',      (0,0), (-1,-1), 9),
        ('GRID',          (0,0), (-1,-1), 0.4, GRIS_BORD),
        ('ROWBACKGROUNDS',(0,1), (-1,-1), [colors.white, GRIS_L]),
        ('VALIGN',        (0,0), (-1,-1), 'MIDDLE'),
        ('TOPPADDING',    (0,0), (-1,-1), 6),
        ('BOTTOMPADDING', (0,0), (-1,-1), 6),
        ('LEFTPADDING',   (0,0), (-1,-1), 8),
    ]))
    story.append(stat_table)

# ── Section 2 : Graphique évolution ───────────────────────────
story.append(Paragraph('2. Évolution de la glycémie', H1))
story.append(HRFlowable(width=W, color=VERT, thickness=1.5, spaceAfter=10))

if mesures and len(mesures) >= 2:
    graph = mini_graph(mesures, width=W, height=5.5*cm)
    if graph:
        story.append(graph)
        story.append(Paragraph(
            'Figure 1 — Évolution de la glycémie (g/L) sur la période. '
            'Lignes de référence : rouge = seuil critique (0.70 / 2.00), '
            'vert = jeun (1.10), orange = post-repas (1.40).',
            CAPTION
        ))
else:
    story.append(Paragraph('Données insuffisantes pour le graphique (minimum 2 mesures).', SMALL))

# ── Section 3 : Statistiques par contexte ────────────────────
if par_contexte:
    story.append(Paragraph('3. Statistiques par contexte de mesure', H1))
    story.append(HRFlowable(width=W, color=VERT, thickness=1.5, spaceAfter=10))

    ctx_data = [[
        Paragraph('<b>Contexte</b>', BOLD),
        Paragraph('<b>Nb</b>', BOLD),
        Paragraph('<b>Moyenne</b>', BOLD),
        Paragraph('<b>Min</b>', BOLD),
        Paragraph('<b>Max</b>', BOLD),
    ]]
    for row in par_contexte:
        moy = float(row['moy'] or 0)
        col = couleur_valeur(moy, row['contexte'])
        ctx_data.append([
            row['contexte'],
            str(row['nb']),
            Paragraph(f'<b>{row["moy"]} g/L</b>',
                      style('MoyCtx', fontName='Helvetica-Bold', fontSize=9, textColor=col)),
            f'{row["min_v"]} g/L',
            f'{row["max_v"]} g/L',
        ])

    ctx_table = Table(ctx_data, colWidths=[W*0.30, W*0.10, W*0.22, W*0.18, W*0.18])
    ctx_table.setStyle(TableStyle([
        ('BACKGROUND',    (0,0), (-1,0),  GRIS_L),
        ('FONTSIZE',      (0,0), (-1,-1), 9),
        ('GRID',          (0,0), (-1,-1), 0.4, GRIS_BORD),
        ('ROWBACKGROUNDS',(0,1), (-1,-1), [colors.white, GRIS_L]),
        ('VALIGN',        (0,0), (-1,-1), 'MIDDLE'),
        ('TOPPADDING',    (0,0), (-1,-1), 5),
        ('BOTTOMPADDING', (0,0), (-1,-1), 5),
        ('LEFTPADDING',   (0,0), (-1,-1), 8),
        ('ALIGN',         (1,0), (-1,-1), 'CENTER'),
    ]))
    story.append(ctx_table)

# ── Section 4 : Dernières mesures ─────────────────────────────
story.append(Paragraph('4. Détail des mesures (30 dernières)', H1))
story.append(HRFlowable(width=W, color=VERT, thickness=1.5, spaceAfter=10))

dernières = list(reversed(mesures))[:30]
if dernières:
    mes_data = [[
        Paragraph('<b>Date / Heure</b>', BOLD),
        Paragraph('<b>Valeur</b>', BOLD),
        Paragraph('<b>Contexte</b>', BOLD),
        Paragraph('<b>Statut</b>', BOLD),
        Paragraph('<b>Commentaire</b>', BOLD),
    ]]
    for m in dernières:
        val  = float(m['valeur_glycemie'])
        col  = couleur_valeur(val, m['contexte'])
        dt   = m['date_heure']
        dt_s = dt.strftime('%d/%m/%Y %H:%M') if hasattr(dt, 'strftime') else str(dt)[:16]

        if val < 0.70:            statut = '🚨 Hypo'
        elif val > 2.00:          statut = '🚨 Hyper sévère'
        elif m['contexte'] == 'A jeun'     and val > 1.10: statut = '⚠️ Élevée'
        elif m['contexte'] == 'Post-repas' and val > 1.40: statut = '⚠️ Élevée'
        else:                     statut = '✅ Normale'

        mes_data.append([
            Paragraph(dt_s, SMALL),
            Paragraph(f'<b>{val} g/L</b>',
                      style(f'V{val}', fontName='Helvetica-Bold', fontSize=9, textColor=col)),
            Paragraph(m['contexte'], SMALL),
            Paragraph(statut, SMALL),
            Paragraph((m['commentaire'] or '')[:60], SMALL),
        ])

    mes_table = Table(mes_data, colWidths=[W*0.20, W*0.14, W*0.18, W*0.18, W*0.30])
    mes_table.setStyle(TableStyle([
        ('BACKGROUND',    (0,0), (-1,0),  GRIS_L),
        ('FONTSIZE',      (0,0), (-1,-1), 8),
        ('GRID',          (0,0), (-1,-1), 0.3, GRIS_BORD),
        ('ROWBACKGROUNDS',(0,1), (-1,-1), [colors.white, GRIS_L]),
        ('VALIGN',        (0,0), (-1,-1), 'MIDDLE'),
        ('TOPPADDING',    (0,0), (-1,-1), 4),
        ('BOTTOMPADDING', (0,0), (-1,-1), 4),
        ('LEFTPADDING',   (0,0), (-1,-1), 6),
    ]))
    story.append(mes_table)

# ── Section 5 : Traitements ───────────────────────────────────
if traitements:
    story.append(Paragraph('5. Traitements en cours', H1))
    story.append(HRFlowable(width=W, color=VERT, thickness=1.5, spaceAfter=10))

    tr_data = [[
        Paragraph('<b>Médicament</b>', BOLD),
        Paragraph('<b>Dosage</b>', BOLD),
        Paragraph('<b>Fréquence</b>', BOLD),
        Paragraph('<b>Heure(s)</b>', BOLD),
        Paragraph('<b>Depuis</b>', BOLD),
    ]]
    for t in traitements:
        debut = t['date_debut']
        debut_s = debut.strftime('%d/%m/%Y') if hasattr(debut, 'strftime') else str(debut)[:10]
        tr_data.append([
            Paragraph(t['nom_medicament'], NORMAL),
            t['dosage'],
            t['frequence'],
            t.get('heure_prise') or '—',
            debut_s,
        ])

    tr_table = Table(tr_data, colWidths=[W*0.28, W*0.14, W*0.24, W*0.18, W*0.16])
    tr_table.setStyle(TableStyle([
        ('BACKGROUND',    (0,0), (-1,0),  VERT_L),
        ('FONTSIZE',      (0,0), (-1,-1), 9),
        ('GRID',          (0,0), (-1,-1), 0.4, GRIS_BORD),
        ('ROWBACKGROUNDS',(0,1), (-1,-1), [colors.white, GRIS_L]),
        ('VALIGN',        (0,0), (-1,-1), 'MIDDLE'),
        ('TOPPADDING',    (0,0), (-1,-1), 5),
        ('BOTTOMPADDING', (0,0), (-1,-1), 5),
        ('LEFTPADDING',   (0,0), (-1,-1), 8),
    ]))
    story.append(tr_table)

# ── Pied de page / Mention légale ─────────────────────────────
story.append(Spacer(1, 0.8*cm))
story.append(HRFlowable(width=W, color=GRIS_BORD, thickness=0.5))
story.append(Spacer(1, 0.2*cm))
story.append(Paragraph(
    'Ce rapport est généré automatiquement par DiabSuivi à titre indicatif. '
    'Il ne constitue pas un avis médical et ne remplace pas une consultation '
    'avec un professionnel de santé qualifié.',
    style('Legal', fontName='Helvetica-Oblique', fontSize=7, textColor=GRIS_TXT, alignment=1)
))

# ── Numérotation des pages ────────────────────────────────────
def footer(canvas, doc):
    canvas.saveState()
    canvas.setFont('Helvetica', 7)
    canvas.setFillColor(GRIS_TXT)
    canvas.drawString(2*cm, 1.2*cm, f'DiabSuivi — {patient["prenom"]} {patient["nom"]} — {now_str}')
    canvas.drawRightString(A4[0] - 2*cm, 1.2*cm, f'Page {doc.page}')
    canvas.restoreState()

doc.build(story, onFirstPage=footer, onLaterPages=footer)

print(json.dumps({
    'success': True,
    'output':  output_path,
    'pages':   len(mesures),
    'nb_mesures': stats['nb'] if mesures else 0,
}))
