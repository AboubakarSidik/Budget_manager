<?php
// ============================================================
// IMPRÉVUS - GESTION DE LA RÉSERVE ET DES DÉPENSES IMPRÉVUES
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
$erreur = '';
$succes = '';
$info = '';

// Récupérer le nombre de notifications non lues
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE compte_id = ? AND etat = 'non_lue'");
$stmt->execute([$compte_id]);
$nb_non_lues = $stmt->fetchColumn();

// ============================================================
// RÉCUPÉRATION DE LA RÉSERVE ET DES IMPRÉVUS
// ============================================================

$reserve_initiale = $mois_courant['montant_reserve_imprevus'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM utilisation_imprevu WHERE mois_id = ?");
$stmt->execute([$mois_id]);
$total_utilise = $stmt->fetchColumn();

$reste_reserve = $reserve_initiale - $total_utilise;

$stmt = $pdo->prepare("SELECT * FROM utilisation_imprevu WHERE mois_id = ? ORDER BY date DESC");
$stmt->execute([$mois_id]);
$imprevus = $stmt->fetchAll();

// ============================================================
// TRAITEMENT : AJOUT D'UN IMPRÉVU
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $libelle = nettoyer($_POST['libelle'] ?? '');
        $montant = floatval($_POST['montant'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        
        if (empty($libelle)) {
            $erreur = "Veuillez saisir un libellé.";
        } elseif ($montant <= 0) {
            $erreur = "Le montant doit être supérieur à 0.";
        } elseif (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date) || $date > date('Y-m-d')) {
            $erreur = "La date ne peut pas être dans le futur.";
        } else {
            if ($reste_reserve <= 0) {
                $info = "⚠️ Votre réserve est épuisée. Cet imprévu sera déduit directement de votre épargne.";
            } elseif ($montant > $reste_reserve) {
                $info = "⚠️ Cet imprévu dépasse le reste de votre réserve (" . formatFCFA($reste_reserve) . "). Le surplus sera déduit de votre épargne.";
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO utilisation_imprevu (id, mois_id, montant, libelle, date)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([genererUUID(), $mois_id, $montant, $libelle, $date]);
            $_SESSION['message_succes'] = "✅ Imprévu enregistré avec succès !";
            rediriger('imprevus.php');
        }
    }
}

// ============================================================
// TRAITEMENT : SUPPRESSION D'UN IMPRÉVU
// ============================================================
if (isset($_GET['supprimer'])) {
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        rediriger('imprevus.php');
    } else {
        $imprevu_id = $_GET['supprimer'];
        
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $imprevu_id)) {
            $stmt = $pdo->prepare("DELETE FROM utilisation_imprevu WHERE id = ? AND mois_id = ?");
            $stmt->execute([$imprevu_id, $mois_id]);
            $_SESSION['message_succes'] = "✅ Imprévu supprimé avec succès !";
        } else {
            $_SESSION['message_info'] = "⚠️ Identifiant invalide.";
        }
        rediriger('imprevus.php');
    }
}

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
    <title>Budget Manager - Imprévus</title>
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
        
        .card { background: white; border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
        .card h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin-bottom: 16px; }
        .card h3 i { color: #2563eb; margin-right: 8px; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: 'Inter', sans-serif; transition: border-color 0.2s; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        
        .btn-primary { padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary:hover { background: #1d4ed8; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(37,99,235,0.3); }
        .btn-sm { padding: 4px 12px; font-size: 12px; border-radius: 6px; cursor: pointer; border: none; transition: all 0.2s; }
        .btn-sm-danger { background: #ef4444; color: white; }
        .btn-sm-danger:hover { background: #dc2626; }
        
        .table-container { background: white; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; }
        .table-container table { width: 100%; border-collapse: collapse; }
        .table-container th { background: #f8fafc; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; }
        .table-container td { padding: 12px 16px; font-size: 14px; color: #0f172a; border-bottom: 1px solid #f1f5f9; }
        .table-container tr:last-child td { border-bottom: none; }
        .table-container tr:hover td { background: #f8fafc; }
        .table-container .total-row td { font-weight: 700; background: #f8fafc; border-top: 2px solid #e2e8f0; }
        .table-container .actions { display: flex; gap: 6px; justify-content: flex-end; }
        .table-container .actions a { color: #94a3b8; transition: color 0.2s; text-decoration: none; padding: 4px 8px; border-radius: 6px; }
        .table-container .actions a:hover { background: #f1f5f9; color: #2563eb; }
        .table-container .actions a.delete:hover { background: #fef2f2; color: #ef4444; }
        
        .reserve-card {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid #fcd34d;
        }
        .reserve-card .label { font-size: 13px; color: #92400e; }
        .reserve-card .value { font-size: 28px; font-weight: 700; color: #78350f; }
        .reserve-card .sub { font-size: 14px; color: #92400e; margin-top: 4px; }
        
        .form-row { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px; }
        
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
        .modal-box .modal-icon.danger { color: #ef4444; }
        .modal-box h3 { text-align: center; font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .modal-box h3.danger { color: #dc2626; }
        .modal-box p { text-align: center; font-size: 14px; color: #475569; margin-bottom: 4px; }
        .modal-box .sub-text { font-size: 13px; color: #94a3b8; margin-bottom: 20px; }
        .modal-box .modal-actions { display: flex; gap: 12px; justify-content: center; }
        .modal-box .modal-actions .btn-cancel { background: #f1f5f9; color: #0f172a; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .modal-box .modal-actions .btn-cancel:hover { background: #e2e8f0; }
        .modal-box .modal-actions .btn-confirm-danger { background: #dc2626; color: white; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; }
        .modal-box .modal-actions .btn-confirm-danger:hover { background: #b91c1c; transform: scale(1.02); }
        
        @media (max-width: 768px) {
            .app-header .top-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .app-header .user-info { justify-content: space-between; }
            .app-nav { gap: 2px; justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .app-nav a { padding: 6px 12px; min-width: 44px; }
            .app-nav a i { font-size: 16px; }
            .app-nav a .nav-label { font-size: 8px; }
            .form-row { grid-template-columns: 1fr; }
            .table-container { overflow-x: auto; }
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
            <a href="budget.php"><i class="fas fa-file-invoice"></i><span class="nav-label">Budget</span></a>
            <a href="revenus.php"><i class="fas fa-coins"></i><span class="nav-label">Revenus</span></a>
            <a href="depenses.php"><i class="fas fa-receipt"></i><span class="nav-label">Dépenses</span></a>
            <a href="categories.php"><i class="fas fa-tags"></i><span class="nav-label">Catégories</span></a>
            <a href="imprevus.php" class="active"><i class="fas fa-exclamation-triangle"></i><span class="nav-label">Imprévus</span></a>
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
        <h2><i class="fas fa-exclamation-triangle"></i> Imprévus</h2>
        <span style="font-size:14px; color:#64748b;">
            <?= date('F Y', strtotime($mois_courant['periode'] . '-01')) ?>
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
         RÉSERVE
         ============================================================ -->
    <div class="reserve-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>
                <div class="label">🏦 Réserve d'imprévus</div>
                <div class="value"><?= formatFCFA($reserve_initiale) ?></div>
                <div class="sub">
                    <?php if ($total_utilise > 0): ?>
                        Utilisé : <?= formatFCFA($total_utilise) ?> 
                        <span style="color:<?= $reste_reserve >= 0 ? '#16a34a' : '#dc2626' ?>;">
                            | Reste : <?= formatFCFA($reste_reserve) ?>
                        </span>
                    <?php else: ?>
                        Aucun imprévu utilisé
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:13px; color:#92400e;">État</div>
                <div style="font-size:18px; font-weight:700; color:<?= $reste_reserve >= $reserve_initiale * 0.5 ? '#16a34a' : ($reste_reserve > 0 ? '#eab308' : '#dc2626') ?>;">
                    <?php
                    if ($reste_reserve >= $reserve_initiale * 0.5) {
                        echo '🟢 Réserve confortable';
                    } elseif ($reste_reserve > 0) {
                        echo '🟡 Réserve réduite';
                    } else {
                        echo '🔴 Réserve épuisée';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================================
         AJOUTER UN IMPRÉVU
         ============================================================ -->
    <div class="card">
        <h3><i class="fas fa-plus-circle"></i> Ajouter un imprévu</h3>
        
        <?php if ($reste_reserve <= 0 && $reserve_initiale > 0): ?>
            <div class="message info">
                <i class="fas fa-info-circle"></i> 
                ⚠️ Votre réserve est épuisée. Vous pouvez quand même ajouter des imprévus, mais ils réduiront votre épargne.
            </div>
        <?php endif; ?>
        
        <?php if ($reserve_initiale == 0): ?>
            <div class="message info">
                <i class="fas fa-info-circle"></i> 
                Vous n'avez pas défini de réserve d'imprévus. 
                <a href="budget.php">Définissez votre réserve</a> dans le budget.
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="ajouter">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="form-row">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="libelle">📝 Libellé</label>
                    <input type="text" name="libelle" id="libelle" placeholder="Ex: Panne de moto" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="montant">💰 Montant (FCFA)</label>
                    <input type="number" name="montant" id="montant" placeholder="0" min="1" step="1" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="date">📅 Date</label>
                    <input type="date" name="date" id="date" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            
            <button type="submit" class="btn-primary" style="margin-top:16px;">
                <i class="fas fa-plus"></i> Ajouter un imprévu
            </button>
        </form>
    </div>
    
    <!-- ============================================================
         LISTE DES IMPRÉVUS
         ============================================================ -->
    <div style="margin-top:16px;">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Libellé</th>
                        <th>Montant</th>
                        <th>Date</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($imprevus) > 0): ?>
                        <?php foreach ($imprevus as $i): ?>
                            <tr>
                                <td><?= afficher($i['libelle']) ?></td>
                                <td><?= formatFCFA($i['montant']) ?></td>
                                <td><?= date('d/m/Y', strtotime($i['date'])) ?></td>
                                <td style="text-align:right;">
                                    <div class="actions">
                                        <button class="btn-sm btn-sm-danger" onclick="ouvrirModalImprevu('<?= $i['id'] ?>', '<?= addslashes(afficher($i['libelle'])) ?>', '<?= formatFCFA($i['montant']) ?>', '<?= urlencode($_SESSION['csrf_token']) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="4" style="text-align:right;">
                                Total utilisé : <?= formatFCFA($total_utilise) ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:30px 0; color:#94a3b8;">
                                <i class="fas fa-inbox" style="font-size:28px; display:block; margin-bottom:8px;"></i>
                                Aucun imprévu enregistré pour ce mois.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- ============================================================
         MODALE SUPPRESSION IMPRÉVU
         ============================================================ -->
    <div class="modal-overlay" id="modalSuppressionImprevu">
        <div class="modal-box">
            <div class="modal-icon danger"><i class="fas fa-exclamation-circle"></i></div>
            <h3 class="danger">Confirmer la suppression</h3>
            <p id="modalImprevuMessage">Êtes-vous sûr de vouloir supprimer cet imprévu ?</p>
            <p class="sub-text" id="modalImprevuDetail">Cette action est irréversible.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalImprevu()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienSuppressionImprevu" class="btn-confirm-danger">
                    <i class="fas fa-trash"></i> Confirmer
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

<script src="js/app.js"></script>

<script>
function ouvrirModalImprevu(id, libelle, montant, token) {
    document.getElementById('modalImprevuMessage').textContent = 'Êtes-vous sûr de vouloir supprimer l\'imprévu "' + libelle + '" ?';
    document.getElementById('modalImprevuDetail').textContent = 'Montant : ' + montant + ' - Cette action est irréversible.';
    document.getElementById('lienSuppressionImprevu').href = '?supprimer=' + id + '&csrf_token=' + token;
    document.getElementById('modalSuppressionImprevu').classList.add('active');
}

function fermerModalImprevu() {
    document.getElementById('modalSuppressionImprevu').classList.remove('active');
}

document.getElementById('modalSuppressionImprevu').addEventListener('click', function(e) {
    if (e.target === this) fermerModalImprevu();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fermerModalImprevu();
});
</script>

</body>
</html>