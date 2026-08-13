<?php
// ============================================================
// ÉPARGNE - CALCUL ET AFFICHAGE DE L'ÉPARGNE RÉELLE
// ============================================================

require_once 'config.php';
require_once 'session_init.php';
require_once 'functions.php';

if (!estConnecte()) {
    rediriger('auth.php');
}

$compte_id = $_SESSION['utilisateur_id'];
$mois_courant = getMoisEnCours($pdo, $compte_id);

if (!$mois_courant) {
    $_SESSION['message_info'] = "Veuillez d'abord créer votre budget.";
    rediriger('budget.php');
}

$mois_id = $mois_courant['id'];

$budget_total = getBudgetTotal($pdo, $mois_id);
$depenses_reelles = getDepensesReelles($pdo, $mois_id);
$imprevus_utilises = getImprevusUtilises($pdo, $mois_id);
$epargne_reelle = $budget_total - $depenses_reelles - $imprevus_utilises;
$taux_epargne = ($budget_total > 0) ? round(($epargne_reelle / $budget_total) * 100, 1) : 0;

// Récupérer le nombre de notifications non lues
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE compte_id = ? AND etat = 'non_lue'");
$stmt->execute([$compte_id]);
$nb_non_lues = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Manager - Épargne</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; }
        .app-container { max-width: 1200px; margin: 0 auto; padding: 16px 20px; }
        
        .app-header {
            background: #ffffff;
            border-radius: 16px;
            padding: 12px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
        }
        .app-header .top-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .app-header .logo h1 { font-size: 20px; font-weight: 700; background: linear-gradient(135deg, #2563eb, #0d9488); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .app-header .logo h1 i { background: none; -webkit-text-fill-color: #2563eb; }
        .app-header .user-info { display: flex; align-items: center; gap: 16px; }
        .app-header .user-info .user-name { font-weight: 500; color: #0f172a; font-size: 14px; }
        .app-header .user-info .logout-link { color: #ef4444; text-decoration: none; font-weight: 500; font-size: 13px; padding: 6px 12px; border-radius: 8px; transition: all 0.2s; }
        .app-header .user-info .logout-link:hover { background: #fef2f2; }
        
        .app-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 8px 0 4px 0;
            border-top: 1px solid #f1f5f9;
            margin-top: 10px;
        }
        .app-nav a {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 16px;
            border-radius: 12px;
            color: #94a3b8;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            gap: 2px;
            min-width: 56px;
        }
        .app-nav a i { font-size: 20px; transition: all 0.3s ease; }
        .app-nav a .nav-label { font-size: 10px; font-weight: 500; opacity: 0; transform: translateY(-4px); transition: all 0.3s ease; color: #64748b; }
        .app-nav a:hover .nav-label { opacity: 1; transform: translateY(0); }
        .app-nav a:hover { background: rgba(37,99,235,0.08); color: #2563eb; }
        .app-nav a:hover i { transform: translateY(-2px) scale(1.05); }
        .app-nav a.active { background: #2563eb; color: #ffffff; box-shadow: 0 4px 16px rgba(37,99,235,0.3); }
        .app-nav a.active .nav-label { opacity: 1; transform: translateY(0); color: #ffffff; }
        .app-nav a.active i { color: #ffffff; }
        .app-nav a .badge { position: absolute; top: 0px; right: 4px; background: #ef4444; color: white; font-size: 10px; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(239,68,68,0.4); }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin: 20px 0 16px 0; flex-wrap: wrap; gap: 12px; }
        .page-header h2 { font-size: 22px; font-weight: 700; color: #0f172a; }
        .page-header h2 i { color: #2563eb; margin-right: 10px; }
        
        .card { background: white; border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
        .card h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin-bottom: 16px; }
        .card h3 i { color: #2563eb; margin-right: 8px; }
        
        .epargne-card {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #86efac;
            text-align: center;
        }
        .epargne-card .label { font-size: 14px; color: #166534; }
        .epargne-card .value { font-size: 36px; font-weight: 700; color: #14532d; margin-top: 4px; }
        .epargne-card .sub { font-size: 14px; color: #166534; margin-top: 4px; }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        .detail-item { background: #f8fafc; padding: 12px 16px; border-radius: 10px; text-align: center; }
        .detail-item .label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .detail-item .value { font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 2px; }
        .detail-item .value.green { color: #22c55e; }
        .detail-item .value.blue { color: #2563eb; }
        .detail-item .value.orange { color: #eab308; }
        .detail-item .value.red { color: #ef4444; }
        
        @media (max-width: 768px) {
            .app-header .top-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .app-header .user-info { justify-content: space-between; }
            .app-nav { gap: 2px; justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .app-nav a { padding: 6px 12px; min-width: 44px; }
            .app-nav a i { font-size: 16px; }
            .app-nav a .nav-label { font-size: 8px; }
        }
        @media (max-width: 480px) { .app-container { padding: 10px 12px; } .app-header { padding: 10px 16px; } .epargne-card .value { font-size: 28px; } }
    </style>
</head>
<body>
<div class="app-container">
    
    <!-- ============================================================
         HEADER
         ============================================================ -->
    <header class="app-header">
        <div class="top-row">
            <div class="logo"><h1><i class="fas fa-wallet"></i> Budget Manager</h1></div>
            <div class="user-info">
                <span class="user-name"><i class="fas fa-user"></i> <?= afficher($_SESSION['utilisateur_nom']) ?></span>
                <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
            </div>
        </div>
        <nav class="app-nav">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i><span class="nav-label">Dashboard</span></a>
            <a href="budget.php"><i class="fas fa-file-invoice"></i><span class="nav-label">Budget</span></a>
            <a href="revenus.php"><i class="fas fa-coins"></i><span class="nav-label">Revenus</span></a>
            <a href="depenses.php"><i class="fas fa-receipt"></i><span class="nav-label">Dépenses</span></a>
            <a href="categories.php"><i class="fas fa-tags"></i><span class="nav-label">Catégories</span></a>
            <a href="imprevus.php"><i class="fas fa-exclamation-triangle"></i><span class="nav-label">Imprévus</span></a>
            <a href="epargne.php" class="active"><i class="fas fa-piggy-bank"></i><span class="nav-label">Épargne</span></a>
            <a href="objectifs.php"><i class="fas fa-bullseye"></i><span class="nav-label">Objectifs</span></a>
            <a href="historique.php"><i class="fas fa-history"></i><span class="nav-label">Historique</span></a>
            <a href="statistiques.php"><i class="fas fa-chart-pie"></i><span class="nav-label">Statistiques</span></a>
            <a href="alertes.php"><i class="fas fa-bell"></i><span class="nav-label">Alertes</span>
                <?php if ($nb_non_lues > 0): ?>
                    <span class="badge"><?= $nb_non_lues ?></span>
                <?php endif; ?>
            </a>
            <a href="compte.php"><i class="fas fa-cog"></i><span class="nav-label">Compte</span></a>
        </nav>
    </header>
    
    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div class="page-header">
        <h2><i class="fas fa-piggy-bank"></i> Épargne</h2>
        <span style="font-size:14px; color:#64748b;">
            <?= date('F Y', strtotime($mois_courant['periode'] . '-01')) ?>
            <?php if ($mois_courant['statut'] === 'cloture'): ?>
                <span style="background:#fef3c7; color:#d97706; padding:2px 12px; border-radius:12px; font-size:12px; font-weight:600;">🔒 Clôturé</span>
            <?php else: ?>
                <span style="background:#dcfce7; color:#16a34a; padding:2px 12px; border-radius:12px; font-size:12px; font-weight:600;">📌 En cours</span>
            <?php endif; ?>
        </span>
    </div>
    
    <!-- ============================================================
         ÉPARGNE
         ============================================================ -->
    <div class="epargne-card">
        <div class="label">🏦 Épargne réelle du mois</div>
        <div class="value"><?= formatFCFA($epargne_reelle) ?></div>
        <div class="sub">Taux d'épargne : <strong><?= $taux_epargne ?>%</strong></div>
    </div>
    
    <!-- ============================================================
         DÉTAILS
         ============================================================ -->
    <div class="card">
        <h3><i class="fas fa-calculator"></i> Calcul de l'épargne</h3>
        
        <div class="detail-grid">
            <div class="detail-item">
                <div class="label">💰 Budget total</div>
                <div class="value blue"><?= formatFCFA($budget_total) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">📊 Dépenses réelles</div>
                <div class="value orange"><?= formatFCFA($depenses_reelles) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">⚠️ Imprévus utilisés</div>
                <div class="value red"><?= formatFCFA($imprevus_utilises) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">🏦 Épargne réelle</div>
                <div class="value green"><?= formatFCFA($epargne_reelle) ?></div>
            </div>
        </div>
        
        <div style="margin-top:16px; font-size:13px; color:#64748b;">
            <i class="fas fa-info-circle"></i> 
            Épargne réelle = Budget total - Dépenses réelles - Imprévus utilisés
        </div>
    </div>
    
    <!-- ============================================================
         FOOTER
         ============================================================ -->
    <footer style="margin-top:40px; padding:16px 0; text-align:center; color:#94a3b8; font-size:13px; border-top:1px solid #e2e8f0;">
        &copy; <?= date('Y') ?> Budget Manager - Gérez votre budget personnel simplement
    </footer>
    
</div>

</body>
</html>