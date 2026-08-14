<?php
// ============================================================
// DASHBOARD - PAGE D'ACCUEIL APRÈS CONNEXION
// ============================================================

require_once 'config.php';
require_once 'session_init.php';
require_once 'functions.php';


if (!estConnecte()) {
    rediriger('auth.php');
}

$utilisateur = getUtilisateur($pdo);
$mois_courant = getMoisEnCours($pdo, $_SESSION['utilisateur_id']);

$budget_total = 0;
$depenses_reelles = 0;
$epargne_reelle = 0;
$taux_epargne = 0;
$total_prevues = 0;
$total_effectuees = 0;
$mois_cloture = false;

if ($mois_courant) {
    $budget_total = getBudgetTotal($pdo, $mois_courant['id']);
    $depenses_reelles = getDepensesReelles($pdo, $mois_courant['id']);
    $imprevus_utilises = getImprevusUtilises($pdo, $mois_courant['id']);
    $epargne_reelle = $budget_total - $depenses_reelles - $imprevus_utilises;
    $taux_epargne = ($budget_total > 0) ? round(($epargne_reelle / $budget_total) * 100, 1) : 0;
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_prevu), 0) FROM depense WHERE mois_id = ?");
    $stmt->execute([$mois_courant['id']]);
    $total_prevues = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_reel), 0) FROM depense WHERE mois_id = ? AND montant_reel IS NOT NULL");
    $stmt->execute([$mois_courant['id']]);
    $total_effectuees = $stmt->fetchColumn();
    
    if ($mois_courant['statut'] === 'cloture') {
        $mois_cloture = true;
    }
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM source_revenu WHERE compte_id = ?");
$stmt->execute([$_SESSION['utilisateur_id']]);
$nb_sources = $stmt->fetchColumn();
$profil_complet = ($nb_sources > 0);

// Récupérer le nombre de notifications non lues
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE compte_id = ? AND etat = 'non_lue'");
$stmt->execute([$_SESSION['utilisateur_id']]);
$nb_non_lues = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Manager - Dashboard</title>
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
        .page-header .badge-cloture { background: #fef3c7; color: #d97706; padding: 2px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .page-header .badge-en-cours { background: #dcfce7; color: #16a34a; padding: 2px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        
        .message { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
        .message.info { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .message.info a { color: #2563eb; font-weight: 600; text-decoration: none; }
        
        .indicator-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 24px 0 20px 0;
        }
        .indicator-card {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            transition: all 0.3s ease;
        }
        .indicator-card:hover { transform: translateY(-3px); box-shadow: 0 8px 30px rgba(0,0,0,0.08); }
        .indicator-card .label { font-size: 13px; color: #64748b; font-weight: 500; }
        .indicator-card .value { font-size: 24px; font-weight: 700; color: #0f172a; margin-top: 4px; }
        .indicator-card .value.green { color: #22c55e; }
        .indicator-card .value.blue { color: #2563eb; }
        .indicator-card .value.orange { color: #eab308; }
        .indicator-card .value.red { color: #ef4444; }
        .indicator-card .icon { float: right; font-size: 28px; opacity: 0.2; }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            border: 1px solid #e2e8f0;
        }
        .content-card h3 { font-size: 15px; font-weight: 600; color: #0f172a; margin-bottom: 14px; }
        .content-card h3 i { color: #2563eb; margin-right: 8px; }
        
        .mois-info p {
            font-size: 14px;
            color: #475569;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
        }
        .mois-info p:last-child { border-bottom: none; }
        .mois-info .btn-gestion {
            display: inline-block;
            margin-top: 14px;
            padding: 8px 20px;
            background: #2563eb;
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .mois-info .btn-gestion:hover { background: #1d4ed8; transform: translateY(-1px); }
        .mois-info .btn-gestion.desactive { background: #94a3b8; cursor: not-allowed; }
        
        .actions-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .actions-list a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            background: #f8fafc;
            border-radius: 10px;
            text-decoration: none;
            color: #0f172a;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .actions-list a:hover { background: #f1f5f9; transform: translateX(4px); }
        .actions-list a i { font-size: 16px; width: 24px; text-align: center; }
        .actions-list a .fa-plus-circle { color: #22c55e; }
        .actions-list a .fa-chart-line { color: #eab308; }
        .actions-list a .fa-bullseye { color: #8b5cf6; }
        .actions-list a .fa-history { color: #2563eb; }
        
        .profile-banner {
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .profile-banner p { font-size: 14px; color: #92400e; margin: 0; }
        .profile-banner p i { margin-right: 8px; color: #d97706; }
        .profile-banner .btn-completer {
            padding: 6px 20px;
            background: #d97706;
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .profile-banner .btn-completer:hover { background: #b45309; }
        
        .welcome-banner {
            background: linear-gradient(135deg, #2563eb, #0d9488);
            border-radius: 12px;
            padding: 16px 24px;
            margin-bottom: 20px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .welcome-banner h2 { font-size: 20px; font-weight: 700; }
        .welcome-banner p { font-size: 14px; opacity: 0.9; }
        
        .app-footer {
            margin-top: 40px;
            padding: 16px 0;
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
            border-top: 1px solid #e2e8f0;
        }
        
        @media (max-width: 768px) {
            .content-grid { grid-template-columns: 1fr; }
            .app-header .top-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .app-header .user-info { justify-content: space-between; }
            .app-nav { gap: 2px; justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .app-nav a { padding: 6px 12px; min-width: 44px; }
            .app-nav a i { font-size: 16px; }
            .app-nav a .nav-label { font-size: 8px; }
            .indicator-grid { grid-template-columns: repeat(2, 1fr); }
            .profile-banner { flex-direction: column; text-align: center; }
        }
        @media (max-width: 480px) {
            .app-container { padding: 10px 12px; }
            .app-header { padding: 10px 16px; }
            .indicator-grid { grid-template-columns: 1fr; }
            .indicator-card { padding: 14px 18px; }
            .indicator-card .value { font-size: 20px; }
        }
    </style>
</head>
<body>
<div class="app-container">
    
    <!-- ============================================================
         HEADER
         ============================================================ -->
   <?php require_once 'header.php'; ?>
    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div class="page-header">
        <h2><i class="fas fa-tachometer-alt"></i> Dashboard</h2>
        <?php if ($mois_courant): ?>
            <span class="<?= $mois_cloture ? 'badge-cloture' : 'badge-en-cours' ?>">
                <?= $mois_cloture ? '🔒 Clôturé' : '📌 En cours' ?>
            </span>
        <?php endif; ?>
    </div>
    
    <!-- ============================================================
         BANNIÈRES
         ============================================================ -->
    <div class="welcome-banner">
        <div>
            <h2>Bonjour <?= afficher($_SESSION['utilisateur_nom']) ?> 👋</h2>
            <p>Bienvenue sur votre tableau de bord. Gérez vos finances en toute simplicité.</p>
        </div>
        <?php if ($mois_courant): ?>
            <span style="background:rgba(255,255,255,0.2); padding:4px 16px; border-radius:20px; font-size:14px;">
                📅 <?= date('F Y', strtotime($mois_courant['periode'] . '-01')) ?>
            </span>
        <?php endif; ?>
    </div>
    
    <?php if (!$profil_complet): ?>
        <div class="profile-banner">
            <p><i class="fas fa-exclamation-triangle"></i> <strong>Profil incomplet :</strong> Ajoutez vos sources de revenus pour commencer.</p>
            <a href="compte.php?onglet=profil" class="btn-completer"><i class="fas fa-plus"></i> Compléter mon profil</a>
        </div>
    <?php endif; ?>
    
    <?php if ($mois_cloture): ?>
        <div class="message info">
            <i class="fas fa-lock"></i> Ce mois est clôturé. Vous ne pouvez plus modifier les données.
            <a href="historique.php">Voir dans l'historique</a>
        </div>
    <?php endif; ?>
    
    <!-- ============================================================
         INDICATEURS
         ============================================================ -->
    <div class="indicator-grid">
        <div class="indicator-card">
            <span class="icon">💰</span>
            <div class="label">Budget total</div>
            <div class="value blue"><?= formatFCFA($budget_total) ?></div>
        </div>
        <div class="indicator-card">
            <span class="icon">📊</span>
            <div class="label">Dépenses</div>
            <div class="value orange"><?= formatFCFA($depenses_reelles) ?></div>
        </div>
        <div class="indicator-card">
            <span class="icon">🏦</span>
            <div class="label">Épargne</div>
            <div class="value green"><?= formatFCFA($epargne_reelle) ?></div>
        </div>
        <div class="indicator-card">
            <span class="icon">📈</span>
            <div class="label">Taux d'épargne</div>
            <div class="value <?= $taux_epargne >= 20 ? 'green' : ($taux_epargne >= 10 ? 'orange' : 'red') ?>">
                <?= $taux_epargne ?>%
            </div>
        </div>
    </div>
    
    <!-- ============================================================
         CONTENU
         ============================================================ -->
    <div class="content-grid">
        
        <div class="content-card">
            <h3><i class="fas fa-calendar-alt"></i> Mois en cours</h3>
            <?php if ($mois_courant): ?>
                <div class="mois-info">
                    <p><span style="color:#64748b;">Période</span><span style="font-weight:600;"><?= date('F Y', strtotime($mois_courant['periode'] . '-01')) ?></span></p>
                    <p><span style="color:#64748b;">Statut</span><span><?= ucfirst(str_replace('_', ' ', $mois_courant['statut'])) ?></span></p>
                    <p><span style="color:#64748b;">Réserve imprévus</span><span><?= formatFCFA($mois_courant['montant_reserve_imprevus']) ?></span></p>
                    <p><span style="color:#64748b;">Priorités</span><span>
                        <span style="color:#22c55e; font-weight:600;"><?= $mois_courant['pourcentage_critique'] ?>%</span>
                        <span style="color:#94a3b8;">/</span>
                        <span style="color:#eab308; font-weight:600;"><?= $mois_courant['pourcentage_moyen'] ?>%</span>
                        <span style="color:#94a3b8;">/</span>
                        <span style="color:#3b82f6; font-weight:600;"><?= $mois_courant['pourcentage_leger'] ?>%</span>
                    </span></p>
                    <a href="budget.php" class="btn-gestion <?= $mois_cloture ? 'desactive' : '' ?>">
                        <i class="fas fa-edit"></i> <?= $mois_cloture ? 'Mois clôturé' : 'Gérer le mois' ?>
                    </a>
                </div>
            <?php else: ?>
                <p style="color: #64748b; text-align: center; padding: 20px 0;"><i class="fas fa-info-circle"></i> Aucun mois en cours.</p>
                <a href="budget.php" style="display:block; text-align:center; color:#2563eb; text-decoration:none; font-weight:500;">Créer mon premier mois</a>
            <?php endif; ?>
        </div>
        
        <div class="content-card">
            <h3><i class="fas fa-bolt"></i> Actions rapides</h3>
            <div class="actions-list">
                <?php if (!$mois_cloture): ?>
                    <a href="revenus.php"><i class="fas fa-plus-circle"></i> Ajouter un revenu</a>
                    <a href="depenses.php"><i class="fas fa-plus-circle" style="color:#2563eb;"></i> Ajouter une dépense</a>
                    <a href="objectifs.php"><i class="fas fa-plus-circle" style="color:#8b5cf6;"></i> Créer un objectif</a>
                <?php else: ?>
                    <span style="padding:10px 16px; background:#f1f5f9; border-radius:10px; color:#94a3b8; font-size:14px;">
                        <i class="fas fa-lock"></i> Mois clôturé — aucune modification possible
                    </span>
                <?php endif; ?>
                <a href="statistiques.php"><i class="fas fa-chart-line" style="color:#eab308;"></i> Voir statistiques</a>
                <a href="historique.php"><i class="fas fa-history" style="color:#2563eb;"></i> Voir historique</a>
                <?php if (!$profil_complet): ?>
                    <a href="compte.php?onglet=profil"><i class="fas fa-user-cog" style="color:#d97706;"></i> Compléter mon profil</a>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
    
    <!-- ============================================================
         FOOTER
         ============================================================ -->
    <footer class="app-footer">
        &copy; <?= date('Y') ?> Budget Manager - Gérez votre budget personnel simplement
    </footer>
    
</div>

<script src="js/app.js"></script>
</body>
</html>