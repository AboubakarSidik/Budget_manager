<?php
// ============================================================
// REVENUS - GESTION DES REVENUS DU MOIS
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
    $mois_courant = creerNouveauMois($pdo, $compte_id);
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
// TRAITEMENT : AJOUT D'UN REVENU
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $source_revenu_id = $_POST['source_revenu_id'] ?? '';
        $montant = floatval($_POST['montant'] ?? 0);
        $date_reception = $_POST['date_reception'] ?? date('Y-m-d');
        $commentaire = nettoyer($_POST['commentaire'] ?? '');
        
        if (empty($source_revenu_id)) {
            $erreur = "Veuillez sélectionner une source de revenu.";
        } elseif ($montant <= 0) {
            $erreur = "Le montant doit être supérieur à 0.";
        } elseif (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date_reception) || $date_reception > date('Y-m-d')) {
            $erreur = "La date ne peut pas être dans le futur.";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO revenu (id, source_revenu_id, mois_id, montant, date_reception, commentaire)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([genererUUID(), $source_revenu_id, $mois_id, $montant, $date_reception, $commentaire]);
            $_SESSION['message_succes'] = "✅ Revenu ajouté avec succès !";
            rediriger('revenus.php');
        }
    }
}

// ============================================================
// TRAITEMENT : MODIFIER UN REVENU
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $revenu_id = $_POST['revenu_id'] ?? '';
        $montant = floatval($_POST['montant'] ?? 0);
        $date_reception = $_POST['date_reception'] ?? date('Y-m-d');
        $commentaire = nettoyer($_POST['commentaire'] ?? '');
        
        if ($montant <= 0) {
            $erreur = "Le montant doit être supérieur à 0.";
        } elseif (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date_reception) || $date_reception > date('Y-m-d')) {
            $erreur = "La date ne peut pas être dans le futur.";
        } else {
            $stmt = $pdo->prepare("UPDATE revenu SET montant = ?, date_reception = ?, commentaire = ? WHERE id = ? AND mois_id = ?");
            $stmt->execute([$montant, $date_reception, $commentaire, $revenu_id, $mois_id]);
            $_SESSION['message_succes'] = "✅ Revenu modifié avec succès !";
            rediriger('revenus.php');
        }
    }
}

// ============================================================
// TRAITEMENT : SUPPRESSION D'UN REVENU
// ============================================================
if (isset($_GET['supprimer'])) {
    // Vérification CSRF
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        rediriger('revenus.php');
    } else {
        $revenu_id = $_GET['supprimer'];
        // Validation de l'UUID
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $revenu_id)) {
            $stmt = $pdo->prepare("DELETE FROM revenu WHERE id = ? AND mois_id = ?");
            $stmt->execute([$revenu_id, $mois_id]);
            $_SESSION['message_succes'] = "✅ Revenu supprimé avec succès !";
        } else {
            $_SESSION['message_info'] = "⚠️ Identifiant invalide.";
        }
        rediriger('revenus.php');
    }
}

// ============================================================
// TRAITEMENT : ENREGISTRER LES REVENUS ET PASSER AU BUDGET
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'enregistrer_revenus') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM revenu WHERE mois_id = ?");
        $stmt->execute([$mois_id]);
        $nb_revenus = $stmt->fetchColumn();
        
        if ($nb_revenus == 0) {
            $erreur = "⚠️ Vous devez ajouter au moins un revenu avant de continuer.";
        } else {
            $_SESSION['message_succes'] = "✅ Revenus enregistrés ! Définissez maintenant vos priorités et votre réserve.";
            rediriger('budget.php');
        }
    }
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================

// Récupérer les messages de session
if (isset($_SESSION['message_succes'])) {
    $succes = $_SESSION['message_succes'];
    unset($_SESSION['message_succes']);
}
if (isset($_SESSION['message_info'])) {
    $info = $_SESSION['message_info'];
    unset($_SESSION['message_info']);
}

$stmt = $pdo->prepare("SELECT * FROM source_revenu WHERE compte_id = ? ORDER BY type, libelle");
$stmt->execute([$compte_id]);
$sources = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT r.*, s.libelle AS source_libelle, s.type AS source_type
    FROM revenu r
    JOIN source_revenu s ON s.id = r.source_revenu_id
    WHERE r.mois_id = ?
    ORDER BY r.date_reception DESC
");
$stmt->execute([$mois_id]);
$revenus = $stmt->fetchAll();

$budget_total = 0;
foreach ($revenus as $r) {
    $budget_total += $r['montant'];
}

$stmt = $pdo->prepare("SELECT * FROM source_revenu WHERE compte_id = ? AND type = 'regulier' ORDER BY libelle");
$stmt->execute([$compte_id]);
$sources_regulieres = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM source_revenu WHERE compte_id = ? AND type IN ('variable', 'ponctuel') ORDER BY type, libelle");
$stmt->execute([$compte_id]);
$sources_variables = $stmt->fetchAll();

$nb_revenus = count($revenus);
$nb_sources = count($sources);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Manager - Revenus</title>
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
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: 'Inter', sans-serif; transition: border-color 0.2s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        .form-group textarea { resize: vertical; min-height: 60px; }
        
        .btn-primary { padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary:hover { background: #1d4ed8; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(37,99,235,0.3); }
        .btn-success { padding: 10px 24px; background: #22c55e; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-success:hover { background: #16a34a; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(34,197,94,0.3); }
        .btn-sm { padding: 4px 12px; font-size: 12px; border-radius: 6px; cursor: pointer; border: none; transition: all 0.2s; }
        .btn-sm-primary { background: #2563eb; color: white; }
        .btn-sm-primary:hover { background: #1d4ed8; }
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
        
        .source-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-left: 6px; }
        .source-badge.regulier { background: #dcfce7; color: #16a34a; }
        .source-badge.variable { background: #fef3c7; color: #d97706; }
        .source-badge.ponctuel { background: #e0e7ff; color: #4f46e5; }
        .source-badge .info-icon { margin-left: 4px; cursor: help; font-size: 11px; }
        
        .budget-summary {
            background: linear-gradient(135deg, #2563eb, #0d9488);
            color: white;
            border-radius: 16px;
            padding: 16px 24px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .budget-summary .label { font-size: 13px; opacity: 0.8; }
        .budget-summary .value { font-size: 22px; font-weight: 700; }
        .budget-summary .info { font-size: 13px; opacity: 0.7; }
        
        .sources-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 16px;
        }
        .sources-box {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            border: 1px solid #e2e8f0;
        }
        .sources-box h4 { font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px; }
        .sources-box h4 i { margin-right: 6px; }
        .sources-box ul { list-style: none; padding: 0; }
        .sources-box ul li { padding: 4px 0; font-size: 14px; color: #0f172a; display: flex; justify-content: space-between; border-bottom: 1px solid #f1f5f9; }
        .sources-box ul li:last-child { border-bottom: none; }
        .source-tag { font-size: 11px; font-weight: 600; padding: 1px 10px; border-radius: 12px; }
        .source-tag.regulier { background: #dcfce7; color: #16a34a; }
        .source-tag.variable { background: #fef3c7; color: #d97706; }
        .source-tag.ponctuel { background: #e0e7ff; color: #4f46e5; }
        
        .btn-row { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        
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
            max-width: 480px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn 0.3s ease;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-box .modal-icon { text-align: center; font-size: 48px; margin-bottom: 12px; }
        .modal-box .modal-icon.edit { color: #2563eb; }
        .modal-box .modal-icon.danger { color: #ef4444; }
        .modal-box .modal-actions { display: flex; gap: 12px; justify-content: center; margin-top: 20px; }
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
            .sources-section { grid-template-columns: 1fr; }
            .table-container { overflow-x: auto; }
            .budget-summary { flex-direction: column; text-align: center; }
            .modal-box { margin: 16px; padding: 20px; }
        }
        @media (max-width: 480px) { .app-container { padding: 10px 12px; } .app-header { padding: 10px 16px; } .card { padding: 16px; } }
    </style>
</head>
<body class="<?= ($_SESSION['theme'] ?? 'clair') === 'sombre' ? 'theme-sombre' : '' ?>">

<div class="app-container">
    
    <!-- ============================================================
         HEADER
         ============================================================ -->
  <?php require_once 'header.php'; ?> 
    
    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div class="page-header">
        <h2><i class="fas fa-coins"></i> Revenus</h2>
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
         BUDGET SUMMARY
         ============================================================ -->
    <div class="budget-summary">
        <div>
            <div class="label">💰 Budget total du mois</div>
            <div class="value"><?= formatFCFA($budget_total) ?></div>
        </div>
        <div>
            <div class="label">📊 Revenus enregistrés</div>
            <div class="value" style="font-size:18px;"><?= $nb_revenus ?></div>
        </div>
        <div class="info">
            <i class="fas fa-info-circle"></i> Le budget est calculé automatiquement
        </div>
    </div>
    
    <!-- ============================================================
         SOURCES
         ============================================================ -->
    <div class="sources-section">
        <div class="sources-box">
            <h4><i class="fas fa-clock" style="color:#16a34a;"></i> Sources régulières</h4>
            <ul>
                <?php if (count($sources_regulieres) > 0): ?>
                    <?php foreach ($sources_regulieres as $s): ?>
                        <li>
                            <?= afficher($s['libelle']) ?>
                            <span class="source-tag regulier">Régulier</span>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li style="color:#94a3b8; font-size:13px;">Aucune source régulière</li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="sources-box">
            <h4><i class="fas fa-bolt" style="color:#d97706;"></i> Sources variables / ponctuelles</h4>
            <ul>
                <?php if (count($sources_variables) > 0): ?>
                    <?php foreach ($sources_variables as $s): ?>
                        <li>
                            <?= afficher($s['libelle']) ?>
                            <span class="source-tag <?= $s['type'] ?>"><?= ucfirst($s['type']) ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li style="color:#94a3b8; font-size:13px;">Aucune source variable ou ponctuelle</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    
    <!-- ============================================================
         AJOUTER UN REVENU
         ============================================================ -->
    <div class="card" style="margin-top:16px;">
        <h3><i class="fas fa-plus-circle"></i> Ajouter un revenu</h3>
        
        <?php if ($nb_sources == 0): ?>
            <div class="message info">
                <i class="fas fa-info-circle"></i> 
                Vous n'avez pas encore de sources de revenus. 
                <a href="compte.php?onglet=profil">Ajoutez-en dans votre profil</a>.
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="ajouter">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div style="display:grid; grid-template-columns:2fr 1fr 1.5fr; gap:12px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="source_revenu_id">Source</label>
                    <select name="source_revenu_id" id="source_revenu_id" required>
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($sources as $s): ?>
                            <option value="<?= $s['id'] ?>" data-type="<?= $s['type'] ?>">
                                <?= afficher($s['libelle']) ?> (<?= ucfirst($s['type']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="montant">Montant (FCFA)</label>
                    <input type="number" name="montant" id="montant" placeholder="150000" min="1" step="1" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="date_reception">Date</label>
                    <input type="date" name="date_reception" id="date_reception" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="commentaire">Commentaire (optionnel)</label>
                    <input type="text" name="commentaire" id="commentaire" placeholder="Précision sur ce revenu">
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <button type="submit" class="btn-primary" style="width:100%;">
                        <i class="fas fa-plus"></i> Ajouter
                    </button>
                </div>
            </div>
            
            <div id="messageCommercant" style="display:none; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px 16px; margin-top:12px;">
                <p style="font-size:13px; color:#1e40af; margin:0;">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Si vous êtes commerçant ou indépendant :</strong>
                    Saisissez le montant que vous avez <strong>prélevé</strong> de votre activité pour votre budget personnel, pas le chiffre d'affaires.
                </p>
            </div>
        </form>
    </div>
    
    <!-- ============================================================
         LISTE DES REVENUS
         ============================================================ -->
    <div style="margin-top:16px;">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Montant</th>
                        <th>Date</th>
                        <th>Commentaire</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($nb_revenus > 0): ?>
                        <?php foreach ($revenus as $r): ?>
                            <tr>
                                <td>
                                    <?= afficher($r['source_libelle']) ?>
                                    <span class="source-badge <?= $r['source_type'] ?>">
                                        <?= ucfirst($r['source_type']) ?>
                                        <?php if ($r['source_type'] === 'variable'): ?>
                                            <i class="fas fa-info-circle info-icon" 
                                               title="Revenu variable : saisissez le montant prélevé pour votre budget personnel"></i>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td><?= formatFCFA($r['montant']) ?></td>
                                <td><?= date('d/m/Y', strtotime($r['date_reception'])) ?></td>
                                <td><?= afficher($r['commentaire'] ?? '-') ?></td>
                                <td style="text-align:right;">
                                    <div class="actions">
                                        <button class="btn-sm btn-sm-primary" onclick="ouvrirModalModifier('<?= $r['id'] ?>', <?= $r['montant'] ?>, '<?= $r['date_reception'] ?>', '<?= addslashes(afficher($r['commentaire'])) ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-sm btn-sm-danger" onclick="ouvrirModalRevenu('<?= $r['id'] ?>', '<?= addslashes(afficher($r['source_libelle'])) ?>', '<?= formatFCFA($r['montant']) ?>', '<?= urlencode($_SESSION['csrf_token']) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="4" style="text-align:right;">Total</td>
                            <td style="text-align:right;"><?= formatFCFA($budget_total) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:30px 0; color:#94a3b8;">
                                <i class="fas fa-inbox" style="font-size:28px; display:block; margin-bottom:8px;"></i>
                                Aucun revenu enregistré pour ce mois.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- ============================================================
         BOUTON : ENREGISTRER LES REVENUS → VERS BUDGET
         ============================================================ -->
    <div class="btn-row">
        <form method="POST" action="">
            <input type="hidden" name="action" value="enregistrer_revenus">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" class="btn-success" <?= $nb_revenus == 0 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                <i class="fas fa-check-circle"></i> Enregistrer les revenus et continuer
            </button>
        </form>
        <?php if ($nb_revenus > 0): ?>
            <span style="font-size:13px; color:#64748b; display:flex; align-items:center;">
                <i class="fas fa-arrow-right" style="margin-right:6px;"></i> 
                Vous allez être redirigé vers la configuration du budget
            </span>
        <?php else: ?>
            <span style="font-size:13px; color:#94a3b8; display:flex; align-items:center;">
                <i class="fas fa-info-circle" style="margin-right:6px;"></i> 
                Ajoutez au moins un revenu pour continuer
            </span>
        <?php endif; ?>
    </div>
    
    <!-- ============================================================
         MODALE MODIFIER REVENU
         ============================================================ -->
    <div class="modal-overlay" id="modalModifierRevenu">
        <div class="modal-box">
            <div class="modal-icon edit"><i class="fas fa-edit"></i></div>
            <h3>Modifier le revenu</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="revenu_id" id="modif_revenu_id">
                
                <div class="form-group">
                    <label for="modif_montant">💰 Montant (FCFA)</label>
                    <input type="number" name="montant" id="modif_montant" min="1" step="1" required>
                </div>
                <div class="form-group">
                    <label for="modif_date_reception">📅 Date</label>
                    <input type="date" name="date_reception" id="modif_date_reception" required>
                </div>
                <div class="form-group">
                    <label for="modif_commentaire">💬 Commentaire</label>
                    <input type="text" name="commentaire" id="modif_commentaire" placeholder="Optionnel">
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="fermerModalModifierRevenu()">
                        <i class="fas fa-times"></i> Annuler
                    </button>
                    <button type="submit" class="btn-confirm">
                        <i class="fas fa-save"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- ============================================================
         MODALE SUPPRESSION REVENU
         ============================================================ -->
    <div class="modal-overlay" id="modalSuppressionRevenu">
        <div class="modal-box">
            <div class="modal-icon danger"><i class="fas fa-exclamation-circle"></i></div>
            <h3 class="danger">Confirmer la suppression</h3>
            <p id="modalRevenuMessage">Êtes-vous sûr de vouloir supprimer ce revenu ?</p>
            <p class="sub-text" id="modalRevenuDetail">Cette action est irréversible.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalRevenu()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienSuppressionRevenu" class="btn-confirm-danger">
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
document.addEventListener('DOMContentLoaded', function() {
    const sourceSelect = document.getElementById('source_revenu_id');
    const messageDiv = document.getElementById('messageCommercant');
    
    if (sourceSelect && messageDiv) {
        sourceSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const type = selectedOption.getAttribute('data-type');
            
            if (type === 'variable') {
                messageDiv.style.display = 'block';
            } else {
                messageDiv.style.display = 'none';
            }
        });
    }
});

function ouvrirModalModifier(id, montant, date, commentaire) {
    document.getElementById('modif_revenu_id').value = id;
    document.getElementById('modif_montant').value = montant;
    document.getElementById('modif_date_reception').value = date;
    document.getElementById('modif_commentaire').value = commentaire || '';
    document.getElementById('modalModifierRevenu').classList.add('active');
}

function fermerModalModifierRevenu() {
    document.getElementById('modalModifierRevenu').classList.remove('active');
}

function ouvrirModalRevenu(id, nom, montant, token) {
    document.getElementById('modalRevenuMessage').textContent = 'Êtes-vous sûr de vouloir supprimer le revenu "' + nom + '" ?';
    document.getElementById('modalRevenuDetail').textContent = 'Montant : ' + montant + ' - Cette action est irréversible.';
    document.getElementById('lienSuppressionRevenu').href = '?supprimer=' + id + '&csrf_token=' + token;
    document.getElementById('modalSuppressionRevenu').classList.add('active');
}

function fermerModalRevenu() {
    document.getElementById('modalSuppressionRevenu').classList.remove('active');
}

document.getElementById('modalModifierRevenu').addEventListener('click', function(e) {
    if (e.target === this) fermerModalModifierRevenu();
});

document.getElementById('modalSuppressionRevenu').addEventListener('click', function(e) {
    if (e.target === this) fermerModalRevenu();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fermerModalModifierRevenu();
        fermerModalRevenu();
    }
});
</script>

</body>
</html>