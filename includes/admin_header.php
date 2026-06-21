<?php
/**
 * DiabSuivi — En-tête dédié à l'espace admin (thème distinct)
 */
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session.php';
}
require_once __DIR__ . '/fonctions.php';
$nomAdmin  = $_SESSION['user_nom']   ?? '';
$roleAdmin = $_SESSION['admin_role'] ?? 'moderateur';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle ?? 'Administration — SanteLink') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-body">

<nav class="admin-navbar">
    <a href="/admin/dashboard.php" class="admin-brand">
        <span style="font-size:20px">🛡️</span> SanteLink <span style="opacity:.6">Admin</span>
    </a>
    <div class="admin-nav-links">
        <a href="/admin/dashboard.php"    class="admin-nav-link">📊 Vue d'ensemble</a>
        <a href="/admin/utilisateurs.php" class="admin-nav-link">👥 Utilisateurs</a>
        <a href="/admin/audit.php"        class="admin-nav-link">📋 Logs d'audit</a>
    </div>
    <div class="admin-nav-user">
        <span class="admin-badge-role"><?= $roleAdmin === 'super_admin' ? '⭐ Super Admin' : 'Modérateur' ?></span>
        <span style="font-size:12px;opacity:.8">👤 <?= esc($nomAdmin) ?></span>
        <a href="/auth/logout.php" class="admin-nav-link" style="background:rgba(226,75,74,.2)">Déconnexion</a>
    </div>
</nav>

<?= flashMessage() ?>

<main class="admin-main">
