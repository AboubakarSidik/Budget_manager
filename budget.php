<?php
// ============================================================
// BUDGET - GESTION DU MOIS ET DES PRIORITÉS
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
    $_SESSION['message_info'] = "Veuillez d'abord enregistrer vos revenus.";
    rediriger('revenus.php');
}

$erreur = '';
$succes = '';
$info = '';

// Récupérer le nombre de notifications non lues
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE compte_id = ? AND etat = 'non_lue'");
$stmt->execute([$compte_id]);
$nb_non_lues = $stmt->fetchColumn();

// ============================================================
// TRAITEMENT : ENREGISTRER LE MOIS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enregistrer') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $pourcentage_critique = floatval($_POST['pourcentage_critique'] ?? 60);
        $pourcentage_moyen = floatval($_POST['pourcentage_moyen'] ?? 30);
        $pourcentage_leger = floatval($_POST['pourcentage_leger'] ?? 10);
        $montant_reserve_imprevus = floatval($_POST['montant_reserve_imprevus'] ?? 0);
        
        $budget_total = getBudgetTotal($pdo, $mois_courant['id']);
        
        $total = $pourcentage_critique + $pourcentage_moyen + $pourcentage_leger;
        if (abs($total - 100) > 0.01) {
            $erreur = "⚠️ Les pourcentages doivent totaliser 100%. Actuellement : $total%";
        } elseif ($montant_reserve_imprevus < 0) {
            $erreur = "⚠️ La réserve d'imprévus ne peut pas être négative.";
        } elseif ($montant_reserve_imprevus > $budget_total) {
            $erreur = "⚠️ La réserve d'imprévus (" . formatFCFA($montant_reserve_imprevus) . ") ne peut pas dépasser le budget total (" . formatFCFA($budget_total) . ").";
        } elseif ($montant_reserve_imprevus > ($budget_total * 0.5)) {
            $erreur = "⚠️ La réserve d'imprévus (" . formatFCFA($montant_reserve_imprevus) . ") dépasse 50% du budget total (" . formatFCFA($budget_total) . ").";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE mois 
                    SET pourcentage_critique = ?, 
                        pourcentage_moyen = ?, 
                        pourcentage_leger = ?, 
                        montant_reserve_imprevus = ?,
                        statut = 'en_cours'
                    WHERE id = ?
                ");
                $stmt->execute([$pourcentage_critique, $pourcentage_moyen, $pourcentage_leger, $montant_reserve_imprevus, $mois_courant['id']]);
                $_SESSION['message_succes'] = "✅ Mois enregistré avec succès !";
                rediriger('depenses.php');
            } catch (PDOException $e) {
                $erreur = "⚠️ Erreur : " . $e->getMessage();
            }
        }
    }
}

// ============================================================
// TRAITEMENT : CLÔTURE DU MOIS
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'cloturer') {
    
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $budget_total = getBudgetTotal($pdo, $mois_courant['id']);
        if ($budget_total == 0) {
            $erreur = "⚠️ Vous devez avoir des revenus pour clôturer le mois.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM depense WHERE mois_id = ?");
            $stmt->execute([$mois_courant['id']]);
            $nb_depenses = $stmt->fetchColumn();
            
            if ($nb_depenses == 0) {
                $erreur = "⚠️ Vous devez avoir au moins une dépense pour clôturer le mois.";
            } else {
                $stmt = $pdo->prepare("UPDATE mois SET statut = 'cloture' WHERE id = ? AND compte_id = ?");
                $stmt->execute([$mois_courant['id'], $compte_id]);
                $_SESSION['message_succes'] = "✅ Mois clôturé avec succès !";
                rediriger('dashboard.php');
            }
        }
    }
}

// ============================================================
// TRAITEMENT : ROUVRIR LE MOIS
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'rouvrir') {
    
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $stmt = $pdo->prepare("UPDATE mois SET statut = 'rouvert' WHERE id = ? AND compte_id = ?");
        $stmt->execute([$mois_courant['id'], $compte_id]);
        $_SESSION['message_succes'] = "✅ Mois rouvert avec succès !";
        rediriger('budget.php');
    }
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================

$budget_total = getBudgetTotal($pdo, $mois_courant['id']);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_prevu), 0) FROM depense WHERE mois_id = ?");
$stmt->execute([$mois_courant['id']]);
$total_depenses_prevues = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM depense WHERE mois_id = ?");
$stmt->execute([$mois_courant['id']]);
$nb_depenses = $stmt->fetchColumn();

$reste_disponible = $budget_total - $total_depenses_prevues - $mois_courant['montant_reserve_imprevus'];

// Récupérer les messages de session
if (isset($_SESSION['message_succes'])) {
    $succes = $_SESSION['message_succes'];
    unset($_SESSION['message_succes']);
}
if (isset($_SESSION['message_info'])) {
    $info = $_SESSION['message_info'];
    unset($_SESSION['message_info']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Manager - Budget</title>
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
        
        .message { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
        .message.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .message.success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .message.info { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .message.info a { color: #2563eb; font-weight: 600; text-decoration: none; }
        .message.info a:hover { text-decoration: underline; }
        
        .card { background: white; border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
        .card h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin-bottom: 16px; }
        .card h3 i { color: #2563eb; margin-right: 8px; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: 'Inter', sans-serif; transition: border-color 0.2s; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        .form-group .input-with-icon { position: relative; }
        .form-group .input-with-icon input { padding-left: 36px; }
        .form-group .input-with-icon i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        
        .btn-success { padding: 10px 24px; background: #22c55e; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-success:hover { background: #16a34a; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(34,197,94,0.3); }
        .btn-danger { padding: 10px 24px; background: #ef4444; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { padding: 10px 24px; background: #eab308; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-warning:hover { background: #d97706; }
        
        .priorite-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }
        .priorite-item {
            padding: 16px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            text-align: center;
            transition: all 0.2s;
        }
        .priorite-item:hover { border-color: #2563eb; }
        .priorite-item .color-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-bottom: 4px; }
        .priorite-item .color-dot.critique { background: #22c55e; }
        .priorite-item .color-dot.moyen { background: #eab308; }
        .priorite-item .color-dot.leger { background: #3b82f6; }
        .priorite-item input { width: 80px; text-align: center; font-size: 18px; font-weight: 700; border: 2px solid #e2e8f0; border-radius: 8px; padding: 6px; margin: 4px auto; display: block; }
        .priorite-item input:focus { border-color: #2563eb; outline: none; }
        .priorite-item .label { font-size: 13px; font-weight: 600; color: #0f172a; }
        .priorite-item .montant { font-size: 12px; color: #64748b; margin-top: 4px; }
        
        .resume-budget {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        .resume-item { background: #f8fafc; padding: 12px 16px; border-radius: 10px; text-align: center; }
        .resume-item .label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .resume-item .value { font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 2px; }
        .resume-item .value.green { color: #22c55e; }
        .resume-item .value.blue { color: #2563eb; }
        .resume-item .value.orange { color: #eab308; }
        .resume-item .value.red { color: #ef4444; }
        
        .indicator-hint {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }
        
        .btn-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
        }
        
        .cloture-card {
            border: 2px solid #fef3c7;
            background: #fefce8;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: white;
            border-radius: 20px;
            padding: 32px;
            max-width: 450px;
            width: 100%;
            animation: modalIn 0.3s ease;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-box .modal-icon { text-align: center; font-size: 48px; margin-bottom: 12px; }
        .modal-box .modal-icon.warning { color: #eab308; }
        .modal-box .modal-icon.danger { color: #dc2626; }
        .modal-box h3 { text-align: center; font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .modal-box h3.danger { color: #dc2626; }
        .modal-box p { text-align: center; font-size: 14px; color: #475569; margin-bottom: 4px; }
        .modal-box .sub-text { font-size: 13px; color: #94a3b8; margin-bottom: 20px; }
        .modal-box .modal-actions { display: flex; gap: 12px; justify-content: center; }
        .modal-box .modal-actions .btn-cancel { background: #f1f5f9; color: #0f172a; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .modal-box .modal-actions .btn-cancel:hover { background: #e2e8f0; }
        .modal-box .modal-actions .btn-confirm { background: #2563eb; color: white; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; }
        .modal-box .modal-actions .btn-confirm:hover { background: #1d4ed8; transform: scale(1.02); }
        .modal-box .modal-actions .btn-confirm-danger { background: #dc2626; color: white; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; }
        .modal-box .modal-actions .btn-confirm-danger:hover { background: #b91c1c; transform: scale(1.02); }
        
        @media (max-width: 768px) {
            .app-header .top-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .app-header .user-info { justify-content: space-between; }
            .app-nav { gap: 2px; justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .app-nav a { padding: 6px 12px; min-width: 44px; }
            .app-nav a i { font-size: 16px; }
            .app-nav a .nav-label { font-size: 8px; }
            .priorite-grid { grid-template-columns: 1fr; }
            .resume-budget { grid-template-columns: 1fr 1fr; }
            .btn-row { flex-direction: column; align-items: stretch; }
            .modal-box { margin: 16px; padding: 24px; }
        }
        @media (max-width: 480px) { .app-container { padding: 10px 12px; } .app-header { padding: 10px 16px; } .card { padding: 16px; } }
    </style>
</head>
<body class="<?= ($_SESSION['theme'] ?? 'clair') === 'sombre' ? 'theme-sombre' : '' ?>">

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
            <a href="budget.php" class="active"><i class="fas fa-file-invoice"></i><span class="nav-label">Budget</span></a>
            <a href="revenus.php"><i class="fas fa-coins"></i><span class="nav-label">Revenus</span></a>
            <a href="depenses.php"><i class="fas fa-receipt"></i><span class="nav-label">Dépenses</span></a>
            <a href="categories.php"><i class="fas fa-tags"></i><span class="nav-label">Catégories</span></a>
            <a href="imprevus.php"><i class="fas fa-exclamation-triangle"></i><span class="nav-label">Imprévus</span></a>
            <a href="epargne.php"><i class="fas fa-piggy-bank"></i><span class="nav-label">Épargne</span></a>
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
        <h2><i class="fas fa-file-invoice"></i> Budget</h2>
        <span style="font-size:14px; color:#64748b;">
            <?= date('F Y', strtotime($mois_courant['periode'] . '-01')) ?>
            <?php if ($mois_courant['statut'] === 'cloture'): ?>
                <span style="background:#fef3c7; color:#d97706; padding:2px 12px; border-radius:12px; font-size:12px; font-weight:600;">🔒 Clôturé</span>
            <?php else: ?>
                <span style="background:#dcfce7; color:#16a34a; padding:2px 12px; border-radius:12px; font-size:12px; font-weight:600;">📌 En cours</span>
            <?php endif; ?>
        </span>
    </div>
    
    <!-- ===== MESSAGES ===== -->
    <?php if (!empty($erreur)): ?>
        <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= afficher($erreur) ?></div>
    <?php endif; ?>
    <?php if (!empty($succes)): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= afficher($succes) ?></div>
    <?php endif; ?>
    <?php if (!empty($info)): ?>
        <div class="message info"><i class="fas fa-info-circle"></i> <?= afficher($info) ?></div>
    <?php endif; ?>
    
    <!-- ============================================================
         RÉSUMÉ DU BUDGET
         ============================================================ -->
    <div class="card">
        <h3><i class="fas fa-chart-pie"></i> Résumé du budget</h3>
        
        <div class="resume-budget">
            <div class="resume-item">
                <div class="label">💰 Budget total</div>
                <div class="value blue"><?= formatFCFA($budget_total) ?></div>
            </div>
            <div class="resume-item">
                <div class="label">🧾 Dépenses prévues</div>
                <div class="value orange"><?= formatFCFA($total_depenses_prevues) ?></div>
            </div>
            <div class="resume-item">
                <div class="label">🏦 Réserve imprévus</div>
                <div class="value orange"><?= formatFCFA($mois_courant['montant_reserve_imprevus']) ?></div>
            </div>
            <div class="resume-item">
                <div class="label">📊 Reste disponible</div>
                <div class="value <?= $reste_disponible >= 0 ? 'green' : 'red' ?>">
                    <?= formatFCFA($reste_disponible) ?>
                </div>
            </div>
        </div>
        
        <div style="margin-top:12px; font-size:13px; color:#64748b;">
            <i class="fas fa-info-circle"></i> 
            <?php if ($budget_total == 0): ?>
                Aucun revenu enregistré pour ce mois.
                <a href="revenus.php" style="color:#2563eb; text-decoration:none;">Ajoutez vos revenus</a>.
            <?php elseif ($nb_depenses == 0): ?>
                Aucune dépense enregistrée.
                <a href="depenses.php" style="color:#2563eb; text-decoration:none;">Ajoutez vos dépenses prévues</a>.
            <?php else: ?>
                Le reste disponible est une estimation basée sur vos dépenses prévues.
                <?php if ($reste_disponible < 0): ?>
                    ⚠️ Vos dépenses prévues dépassent votre budget total.
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ============================================================
         FORMULAIRE BUDGET (si mois non clôturé)
         ============================================================ -->
    <?php if ($mois_courant['statut'] !== 'cloture'): ?>
        <div class="card">
            <h3><i class="fas fa-sliders-h"></i> Définir mes priorités</h3>
            
            <?php if ($nb_depenses == 0): ?>
                <div class="message info">
                    <i class="fas fa-info-circle"></i> 
                    Vous n'avez pas encore enregistré de dépenses.
                    <a href="depenses.php">Ajoutez vos dépenses prévues</a> pour définir votre budget.
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="enregistrer">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="form-group">
                    <label><i class="fas fa-layer-group"></i> Répartition par priorité (total = 100%)</label>
                    <div class="priorite-grid">
                        <div class="priorite-item">
                            <div class="color-dot critique"></div>
                            <div class="label">Critique</div>
                            <input type="number" name="pourcentage_critique" 
                                   value="<?= $mois_courant['pourcentage_critique'] ?? 60 ?>" 
                                   min="0" max="100" step="1" required>
                            <div class="montant">
                                <?php if ($budget_total > 0): ?>
                                    <?= formatFCFA($budget_total * ($mois_courant['pourcentage_critique'] / 100)) ?>
                                <?php else: ?>
                                    0 FCFA
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="priorite-item">
                            <div class="color-dot moyen"></div>
                            <div class="label">Moyen</div>
                            <input type="number" name="pourcentage_moyen" 
                                   value="<?= $mois_courant['pourcentage_moyen'] ?? 30 ?>" 
                                   min="0" max="100" step="1" required>
                            <div class="montant">
                                <?php if ($budget_total > 0): ?>
                                    <?= formatFCFA($budget_total * ($mois_courant['pourcentage_moyen'] / 100)) ?>
                                <?php else: ?>
                                    0 FCFA
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="priorite-item">
                            <div class="color-dot leger"></div>
                            <div class="label">Léger</div>
                            <input type="number" name="pourcentage_leger" 
                                   value="<?= $mois_courant['pourcentage_leger'] ?? 10 ?>" 
                                   min="0" max="100" step="1" required>
                            <div class="montant">
                                <?php if ($budget_total > 0): ?>
                                    <?= formatFCFA($budget_total * ($mois_courant['pourcentage_leger'] / 100)) ?>
                                <?php else: ?>
                                    0 FCFA
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="indicator-hint">
                        <i class="fas fa-info-circle"></i>
                        Actuellement : <strong><?= ($mois_courant['pourcentage_critique'] ?? 60) + ($mois_courant['pourcentage_moyen'] ?? 30) + ($mois_courant['pourcentage_leger'] ?? 10) ?>%</strong>
                        <?php if (($mois_courant['pourcentage_critique'] ?? 60) + ($mois_courant['pourcentage_moyen'] ?? 30) + ($mois_courant['pourcentage_leger'] ?? 10) == 100): ?>
                            ✅ Total OK
                        <?php else: ?>
                            ⚠️ Le total doit être 100%
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="montant_reserve_imprevus"><i class="fas fa-shield-alt"></i> Réserve d'imprévus</label>
                    <div class="input-with-icon">
                        <i class="fas fa-coins"></i>
                        <input type="number" id="montant_reserve_imprevus" name="montant_reserve_imprevus" 
                               value="<?= $mois_courant['montant_reserve_imprevus'] ?? 0 ?>" 
                               min="0" step="1" placeholder="0">
                    </div>
                    <?php if ($budget_total > 0): ?>
                        <div class="indicator-hint">
                            <i class="fas fa-info-circle"></i>
                            Budget total : <strong><?= formatFCFA($budget_total) ?></strong>
                            &nbsp;•&nbsp; Réserve recommandée : <strong><?= formatFCFA($budget_total * 0.15) ?></strong> (15%)
                            &nbsp;•&nbsp; Réserve max : <strong><?= formatFCFA($budget_total * 0.5) ?></strong> (50%)
                        </div>
                    <?php else: ?>
                        <div class="indicator-hint">
                            <i class="fas fa-info-circle"></i>
                            Ajoutez des revenus pour voir les recommandations.
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="btn-row">
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <button type="submit" class="btn-success" <?= $nb_depenses == 0 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                            <i class="fas fa-save"></i> Enregistrer le mois
                        </button>
                    </div>
                    
                    <?php if ($mois_courant['statut'] !== 'cloture' && $budget_total > 0 && $nb_depenses > 0): ?>
                        <a href="#" class="btn-danger" onclick="ouvrirModalCloture('<?= urlencode($_SESSION['csrf_token']) ?>')">
                            <i class="fas fa-lock"></i> Clôturer le mois
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- ============================================================
             MOIS CLÔTURÉ (avec bouton Rouvrir)
             ============================================================ -->
        <div class="card cloture-card">
            <h3><i class="fas fa-lock" style="color:#d97706;"></i> Mois clôturé</h3>
            <p style="color:#64748b; font-size:14px; margin-bottom:12px;">
                Ce mois est clôturé. Vous ne pouvez plus modifier les données.
                <br>
                <a href="historique.php" style="color:#2563eb; text-decoration:none;">Voir dans l'historique</a>
            </p>
            <a href="#" class="btn-warning" onclick="ouvrirModalRouvrir('<?= urlencode($_SESSION['csrf_token']) ?>')">
                <i class="fas fa-unlock"></i> Rouvrir le mois
            </a>
        </div>
    <?php endif; ?>
    
    <!-- ============================================================
         MODALES PERSONNALISÉES
         ============================================================ -->
    
    <!-- MODALE : CLÔTURE DU MOIS -->
    <div class="modal-overlay" id="modalCloture">
        <div class="modal-box">
            <div class="modal-icon warning"><i class="fas fa-lock" style="color:#eab308;"></i></div>
            <h3>⚠️ Clôturer le mois</h3>
            <p>Êtes-vous sûr de vouloir clôturer ce mois ?</p>
            <p class="sub-text" style="color:#dc2626; font-weight:600;">Cette action est irréversible. Vous ne pourrez plus modifier les données de ce mois.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalCloture()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienCloture" class="btn-confirm-danger">
                    <i class="fas fa-lock"></i> Confirmer la clôture
                </a>
            </div>
        </div>
    </div>

    <!-- MODALE : ROUVRIR LE MOIS -->
    <div class="modal-overlay" id="modalRouvrir">
        <div class="modal-box">
            <div class="modal-icon warning"><i class="fas fa-unlock" style="color:#eab308;"></i></div>
            <h3>🔄 Rouvrir le mois</h3>
            <p>Êtes-vous sûr de vouloir rouvrir ce mois ?</p>
            <p class="sub-text">Vous pourrez à nouveau modifier les données de ce mois.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalRouvrir()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienRouvrir" class="btn-confirm" style="background:#eab308; color:white;">
                    <i class="fas fa-unlock"></i> Confirmer la réouverture
                </a>
            </div>
        </div>
    </div>
    
    <!-- ============================================================
         FOOTER
         ============================================================ -->
    <footer style="margin-top:40px; padding:16px 0; text-align:center; color:#94a3b8; font-size:13px; border-top:1px solid #e2e8f0;">
        &copy; <?= date('Y') ?> Budget Manager - Gérez votre budget personnel simplement
    </footer>
    
</div>

<script>
function ouvrirModalCloture(token) {
    document.getElementById('lienCloture').href = '?action=cloturer&csrf_token=' + token;
    document.getElementById('modalCloture').classList.add('active');
}

function fermerModalCloture() {
    document.getElementById('modalCloture').classList.remove('active');
}

function ouvrirModalRouvrir(token) {
    document.getElementById('lienRouvrir').href = '?action=rouvrir&csrf_token=' + token;
    document.getElementById('modalRouvrir').classList.add('active');
}

function fermerModalRouvrir() {
    document.getElementById('modalRouvrir').classList.remove('active');
}

document.getElementById('modalCloture').addEventListener('click', function(e) {
    if (e.target === this) fermerModalCloture();
});

document.getElementById('modalRouvrir').addEventListener('click', function(e) {
    if (e.target === this) fermerModalRouvrir();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fermerModalCloture();
        fermerModalRouvrir();
    }
});
</script>

</body>
</html>