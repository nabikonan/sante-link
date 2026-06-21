<?php
require_once __DIR__ . '/includes/session.php';

// Rediriger vers le bon dashboard si déjà connecté
if (estConnecte()) {
    header('Location: /' . $_SESSION['user_role'] . '/dashboard.php');
    exit;
}

$pageTitle = 'SanteLink — Suivi glycémique intelligent';
require_once __DIR__ . '/includes/header.php';
?>

<div class="landing-wrap">
    <div class="landing-hero">
        <div class="landing-logo">♥</div>
        <h1 class="landing-title">SanteLink</h1>
        <p class="landing-sub">
            Suivez votre glycémie, anticipez les risques,<br>
            restez en contact avec votre médecin.
        </p>
        <div class="landing-btns">
            <a href="/auth/login.php"    class="btn-primary btn-lg">Se connecter</a>
            <a href="/auth/register.php" class="btn-secondary btn-lg">Créer un compte</a>
        </div>
    </div>

    <div class="landing-features">
        <div class="feature-card">
            <div class="feature-icon">📊</div>
            <h3>Suivi en temps réel</h3>
            <p>Enregistrez vos mesures, visualisez vos tendances, et recevez des alertes automatiques.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">🤖</div>
            <h3>Prédiction IA</h3>
            <p>Un modèle Random Forest analyse votre historique pour prédire votre risque de déséquilibre.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">🩺</div>
            <h3>Espace médecin</h3>
            <p>Vos médecins accèdent à vos données, émettent des ordonnances et surveillent vos alertes.</p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
