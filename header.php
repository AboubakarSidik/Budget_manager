<?php
// ============================================================
// HEADER - BUDGET MANAGER
// ============================================================

// Récupérer le nombre de notifications non lues (si connecté)
$nb_non_lues = 0;
if (isset($_SESSION['utilisateur_id'])) {
    global $pdo;
    if (isset($pdo)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE compte_id = ? AND etat = 'non_lue'");
        $stmt->execute([$_SESSION['utilisateur_id']]);
        $nb_non_lues = $stmt->fetchColumn();
    }
}

// Récupérer le nom de la page actuelle pour le menu actif
$page_actuelle = basename($_SERVER['PHP_SELF']);
?>
<header class="app-header">
    <div class="top-row">
        <div class="logo"><h1><i class="fas fa-wallet"></i> Budget Manager</h1></div>
        <div class="user-info">
            <button onclick="toggleTheme()" class="theme-toggle" title="Changer le thème (Clair/Sombre)">
                <i class="fas fa-sun" id="themeIcon"></i>
                <span class="theme-label" id="themeLabel">Clair</span>
            </button>
            <span class="user-name"><i class="fas fa-user"></i> <?= afficher($_SESSION['utilisateur_nom'] ?? 'Utilisateur') ?></span>
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
        </div>
    </div>
    <nav class="app-nav">
        <a href="dashboard.php" class="<?= $page_actuelle === 'dashboard.php' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i><span class="nav-label">Dashboard</span>
        </a>
        <a href="budget.php" class="<?= $page_actuelle === 'budget.php' ? 'active' : '' ?>">
            <i class="fas fa-file-invoice"></i><span class="nav-label">Budget</span>
        </a>
        <a href="revenus.php" class="<?= $page_actuelle === 'revenus.php' ? 'active' : '' ?>">
            <i class="fas fa-coins"></i><span class="nav-label">Revenus</span>
        </a>
        <a href="depenses.php" class="<?= $page_actuelle === 'depenses.php' ? 'active' : '' ?>">
            <i class="fas fa-receipt"></i><span class="nav-label">Dépenses</span>
        </a>
        <a href="categories.php" class="<?= $page_actuelle === 'categories.php' ? 'active' : '' ?>">
            <i class="fas fa-tags"></i><span class="nav-label">Catégories</span>
        </a>
        <a href="imprevus.php" class="<?= $page_actuelle === 'imprevus.php' ? 'active' : '' ?>">
            <i class="fas fa-exclamation-triangle"></i><span class="nav-label">Imprévus</span>
        </a>
        <a href="epargne.php" class="<?= $page_actuelle === 'epargne.php' ? 'active' : '' ?>">
            <i class="fas fa-piggy-bank"></i><span class="nav-label">Épargne</span>
        </a>
        <a href="objectifs.php" class="<?= $page_actuelle === 'objectifs.php' ? 'active' : '' ?>">
            <i class="fas fa-bullseye"></i><span class="nav-label">Objectifs</span>
        </a>
        <a href="historique.php" class="<?= $page_actuelle === 'historique.php' ? 'active' : '' ?>">
            <i class="fas fa-history"></i><span class="nav-label">Historique</span>
        </a>
        <a href="statistiques.php" class="<?= $page_actuelle === 'statistiques.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-pie"></i><span class="nav-label">Statistiques</span>
        </a>
        <a href="alertes.php" class="<?= $page_actuelle === 'alertes.php' ? 'active' : '' ?>">
            <i class="fas fa-bell"></i><span class="nav-label">Alertes</span>
            <?php if ($nb_non_lues > 0): ?>
                <span class="badge"><?= $nb_non_lues ?></span>
            <?php endif; ?>
        </a>
        <a href="compte.php" class="<?= $page_actuelle === 'compte.php' ? 'active' : '' ?>">
            <i class="fas fa-cog"></i><span class="nav-label">Compte</span>
        </a>
    </nav>
</header>