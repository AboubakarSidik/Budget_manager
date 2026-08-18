<?php
// ============================================================
// OBJECTIFS - GESTION DES OBJECTIFS D'ÉPARGNE
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
// RÉCUPÉRATION DE L'ÉPARGNE RÉELLE DU MOIS
// ============================================================
$budget_total = getBudgetTotal($pdo, $mois_id);
$depenses_reelles = getDepensesReelles($pdo, $mois_id);
$imprevus_utilises = getImprevusUtilises($pdo, $mois_id);
$epargne_reelle = $budget_total - $depenses_reelles - $imprevus_utilises;

// ============================================================
// RÉCUPÉRATION DES OBJECTIFS AVEC COLLECTÉ
// ============================================================
$stmt = $pdo->prepare("
    SELECT o.*, oe.cible, oe.pourcentage_allocation,
           COALESCE(SUM(a.montant_alloue), 0) AS montant_collecte
    FROM objectif o
    JOIN objectif_epargne oe ON oe.objectif_id = o.id
    LEFT JOIN allocation a ON a.objectif_epargne_id = oe.objectif_id
    WHERE o.compte_id = ? AND o.type = 'epargne'
    GROUP BY o.id
    ORDER BY o.date_fin ASC
");
$stmt->execute([$compte_id]);
$objectifs = $stmt->fetchAll();

// ============================================================
// CALCUL DE LA RÉPARTITION DE L'ÉPARGNE
// ============================================================
$total_allocation = 0;
$repartition = [];
$surplus_total = 0;

foreach ($objectifs as $obj) {
    $total_allocation += $obj['pourcentage_allocation'];
    $montant_alloue = $epargne_reelle * ($obj['pourcentage_allocation'] / 100);
    $repartition[] = [
        'nom' => $obj['nom'],
        'pourcentage' => $obj['pourcentage_allocation'],
        'montant' => $montant_alloue
    ];
    
    if ($obj['montant_collecte'] > $obj['cible']) {
        $surplus_total += ($obj['montant_collecte'] - $obj['cible']);
    }
}

$epargne_libre = $epargne_reelle * ((100 - min($total_allocation, 100)) / 100);

// ============================================================
// TRAITEMENT : AJOUT D'UN OBJECTIF
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $nom = nettoyer($_POST['nom'] ?? '');
        $cible = floatval($_POST['cible'] ?? 0);
        $date_debut = $_POST['date_debut'] ?? date('Y-m-d');
        $date_fin = $_POST['date_fin'] ?? date('Y-m-d', strtotime('+3 months'));
        $pourcentage_allocation = floatval($_POST['pourcentage_allocation'] ?? 0);
        
        if (empty($nom)) {
            $erreur = "Veuillez saisir un nom pour l'objectif.";
        } elseif ($cible <= 0) {
            $erreur = "Le montant cible doit être supérieur à 0.";
        } elseif ($pourcentage_allocation <= 0 || $pourcentage_allocation > 100) {
            $erreur = "Le pourcentage d'allocation doit être entre 1 et 100.";
        } elseif ($date_fin <= $date_debut) {
            $erreur = "La date de fin doit être après la date de début.";
        } elseif (($total_allocation + $pourcentage_allocation) > 100) {
            $erreur = "⚠️ Le total des allocations ne peut pas dépasser 100%. Actuellement : " . ($total_allocation + $pourcentage_allocation) . "%";
        } else {
            $objectif_id = genererUUID();
            $stmt = $pdo->prepare("
                INSERT INTO objectif (id, compte_id, type, nom, date_debut, date_fin, statut)
                VALUES (?, ?, 'epargne', ?, ?, ?, 'en_cours')
            ");
            $stmt->execute([$objectif_id, $compte_id, $nom, $date_debut, $date_fin]);
            
            $stmt = $pdo->prepare("
                INSERT INTO objectif_epargne (objectif_id, cible, pourcentage_allocation)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$objectif_id, $cible, $pourcentage_allocation]);
            
            $_SESSION['message_succes'] = "✅ Objectif créé avec succès !";
            rediriger('objectifs.php');
        }
    }
}

// ============================================================
// TRAITEMENT : SUPPRESSION D'UN OBJECTIF
// ============================================================
if (isset($_GET['supprimer'])) {
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        rediriger('objectifs.php');
    } else {
        $objectif_id = $_GET['supprimer'];
        
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $objectif_id)) {
            $_SESSION['message_info'] = "⚠️ Identifiant invalide.";
            rediriger('objectifs.php');
        }
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_alloue), 0) FROM allocation WHERE objectif_epargne_id = ?");
        $stmt->execute([$objectif_id]);
        $total_alloue = $stmt->fetchColumn();
        
        if ($total_alloue > 0) {
            $_SESSION['message_info'] = "💡 Les montants alloués (" . formatFCFA($total_alloue) . ") ont été reversés à l'épargne libre.";
        }
        
        $stmt = $pdo->prepare("DELETE FROM objectif WHERE id = ? AND compte_id = ?");
        $stmt->execute([$objectif_id, $compte_id]);
        $_SESSION['message_succes'] = "✅ Objectif supprimé avec succès !";
        rediriger('objectifs.php');
    }
}

// ============================================================
// TRAITEMENT : MODIFICATION D'UN OBJECTIF
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $objectif_id = $_POST['objectif_id'] ?? '';
        $pourcentage_allocation = floatval($_POST['pourcentage_allocation'] ?? 0);
        
        if ($pourcentage_allocation < 0 || $pourcentage_allocation > 100) {
            $erreur = "Le pourcentage d'allocation doit être entre 0 et 100.";
        } else {
            $stmt = $pdo->prepare("
                UPDATE objectif_epargne 
                SET pourcentage_allocation = ?
                WHERE objectif_id = ?
            ");
            $stmt->execute([$pourcentage_allocation, $objectif_id]);
            $_SESSION['message_succes'] = "✅ Objectif mis à jour avec succès !";
            rediriger('objectifs.php');
        }
    }
}

// ============================================================
// ALLOCATION AUTOMATIQUE DE L'ÉPARGNE
// ============================================================
if ($epargne_reelle > 0 && count($objectifs) > 0) {
    foreach ($objectifs as $obj) {
        $montant_alloue = $epargne_reelle * ($obj['pourcentage_allocation'] / 100);
        
        $stmt = $pdo->prepare("
            SELECT id FROM allocation 
            WHERE objectif_epargne_id = ? AND mois_id = ?
        ");
        $stmt->execute([$obj['id'], $mois_id]);
        $existant = $stmt->fetch();
        
        if ($existant) {
            $stmt = $pdo->prepare("
                UPDATE allocation 
                SET montant_alloue = ?, date_allocation = ?
                WHERE objectif_epargne_id = ? AND mois_id = ?
            ");
            $stmt->execute([$montant_alloue, date('Y-m-d'), $obj['id'], $mois_id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO allocation (id, objectif_epargne_id, mois_id, montant_alloue, date_allocation)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([genererUUID(), $obj['id'], $mois_id, $montant_alloue, date('Y-m-d')]);
        }
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
    <title>Budget Manager - Objectifs</title>
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
        .btn-sm-primary { background: #2563eb; color: white; }
        .btn-sm-primary:hover { background: #1d4ed8; }
        .btn-sm-danger { background: #ef4444; color: white; }
        .btn-sm-danger:hover { background: #dc2626; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        
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
        
        .statut-badge { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .statut-badge.en-cours { background: #dcfce7; color: #16a34a; }
        .statut-badge.a-risque { background: #fef3c7; color: #d97706; }
        .statut-badge.impossible { background: #fef2f2; color: #dc2626; }
        .statut-badge.atteint { background: #e0e7ff; color: #4f46e5; }
        .statut-badge.abandonne { background: #f1f5f9; color: #94a3b8; }
        
        .epargne-dispo {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            border-radius: 16px;
            padding: 16px 24px;
            border: 1px solid #86efac;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .epargne-dispo .label { font-size: 14px; color: #166534; }
        .epargne-dispo .value { font-size: 24px; font-weight: 700; color: #14532d; }
        
        .repartition-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        .repartition-card .item { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; border-bottom: 1px solid #f1f5f9; }
        .repartition-card .item:last-child { border-bottom: none; font-weight: 600; }
        .repartition-card .item .name { color: #475569; }
        .repartition-card .item .amount { font-weight: 500; color: #0f172a; }
        
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
        .modal-box .modal-icon.edit { color: #2563eb; }
        .modal-box .modal-icon.danger { color: #ef4444; }
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
            .grid-2 { grid-template-columns: 1fr; }
            .grid-3 { grid-template-columns: 1fr; }
            .table-container { overflow-x: auto; }
            .modal-box { margin: 16px; padding: 24px; }
        }
        @media (max-width: 480px) { .app-container { padding: 10px 12px; } .app-header { padding: 10px 16px; } .card { padding: 16px; } }


        /* ============================================================
           MODE SOMBRE (genere automatiquement)
           ============================================================ */

body.theme-sombre {
    background: #334155;
}

body.theme-sombre .app-header {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .app-header .logo h1 i {
    -webkit-text-fill-color: #60a5fa;
}

body.theme-sombre .app-header .user-info .user-name {
    color: #f1f5f9;
}

body.theme-sombre .app-header .user-info .logout-link {
    color: #f87171;
}

body.theme-sombre .app-header .user-info .logout-link:hover {
    background: rgba(239, 68, 68, 0.12);
}

body.theme-sombre .app-nav {
    border-top: 1px solid #334155;
}

body.theme-sombre .app-nav a .nav-label {
    color: #94a3b8;
}

body.theme-sombre .app-nav a:hover {
    color: #60a5fa;
}

body.theme-sombre .app-nav a.active {
    color: #f1f5f9;
}

body.theme-sombre .app-nav a.active .nav-label {
    color: #f1f5f9;
}

body.theme-sombre .app-nav a.active i {
    color: #f1f5f9;
}

body.theme-sombre .page-header h2 {
    color: #f1f5f9;
}

body.theme-sombre .page-header h2 i {
    color: #60a5fa;
}

body.theme-sombre .message.error {
    background: rgba(239, 68, 68, 0.12);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.3);
}

body.theme-sombre .message.success {
    background: rgba(34, 197, 94, 0.12);
    color: #4ade80;
    border: 1px solid rgba(34, 197, 94, 0.3);
}

body.theme-sombre .message.info {
    background: rgba(37, 99, 235, 0.12);
    color: #60a5fa;
    border: 1px solid rgba(37, 99, 235, 0.3);
}

body.theme-sombre .card {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .card h3 {
    color: #f1f5f9;
}

body.theme-sombre .card h3 i {
    color: #60a5fa;
}

body.theme-sombre .form-group label {
    color: #e2e8f0;
}

body.theme-sombre .form-group input, body.theme-sombre .form-group select {
    border: 2px solid #334155;
}

body.theme-sombre .table-container {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .table-container th {
    background: #0f172a;
    color: #94a3b8;
    border-bottom: 1px solid #334155;
}

body.theme-sombre .table-container td {
    color: #f1f5f9;
    border-bottom: 1px solid #334155;
}

body.theme-sombre .table-container tr:hover td {
    background: #0f172a;
}

body.theme-sombre .table-container .total-row td {
    background: #0f172a;
    border-top: 2px solid #334155;
}

body.theme-sombre .table-container .actions a:hover {
    background: #334155;
    color: #60a5fa;
}

body.theme-sombre .table-container .actions a.delete:hover {
    background: rgba(239, 68, 68, 0.12);
    color: #f87171;
}

body.theme-sombre .statut-badge.en-cours {
    background: rgba(34, 197, 94, 0.15);
    color: #4ade80;
}

body.theme-sombre .statut-badge.a-risque {
    background: rgba(217, 119, 6, 0.12);
    color: #fbbf24;
}

body.theme-sombre .statut-badge.impossible {
    background: rgba(239, 68, 68, 0.12);
    color: #f87171;
}

body.theme-sombre .statut-badge.atteint {
    background: rgba(79, 70, 229, 0.18);
    color: #818cf8;
}

body.theme-sombre .statut-badge.abandonne {
    background: #334155;
}

body.theme-sombre .epargne-dispo {
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(34, 197, 94, 0.3));
}

body.theme-sombre .epargne-dispo .label {
    color: #4ade80;
}

body.theme-sombre .repartition-card {
    background: #0f172a;
    border: 1px solid #334155;
}

body.theme-sombre .repartition-card .item {
    border-bottom: 1px solid #334155;
}

body.theme-sombre .repartition-card .item .name {
    color: #cbd5e1;
}

body.theme-sombre .repartition-card .item .amount {
    color: #f1f5f9;
}

body.theme-sombre .modal-box {
    background: #1e293b;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7);
}

body.theme-sombre .modal-box .modal-icon.edit {
    color: #60a5fa;
}

body.theme-sombre .modal-box .modal-icon.danger {
    color: #f87171;
}

body.theme-sombre .modal-box h3 {
    color: #f1f5f9;
}

body.theme-sombre .modal-box h3.danger {
    color: #f87171;
}

body.theme-sombre .modal-box p {
    color: #cbd5e1;
}

body.theme-sombre .modal-box .modal-actions .btn-cancel {
    background: #334155;
    color: #f1f5f9;
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
        <h2><i class="fas fa-bullseye"></i> Objectifs</h2>
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
         ÉPARGNE DISPONIBLE
         ============================================================ -->
    <div class="epargne-dispo">
        <div>
            <div class="label">🏦 Épargne réelle du mois</div>
            <div class="value"><?= formatFCFA($epargne_reelle) ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:13px; color:#166534;">
                <?php if ($total_allocation > 0): ?>
                    Allouée automatiquement à <?= count($objectifs) ?> objectif(s)
                <?php else: ?>
                    Aucun objectif défini
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- ============================================================
         RÉPARTITION DE L'ÉPARGNE
         ============================================================ -->
    <?php if ($epargne_reelle > 0 && count($objectifs) > 0): ?>
        <div class="repartition-card">
            <div style="font-weight:600; color:#0f172a; margin-bottom:8px;">📊 Répartition automatique de l'épargne</div>
            <?php foreach ($repartition as $item): ?>
                <div class="item">
                    <span class="name"><?= afficher($item['nom']) ?> (<?= $item['pourcentage'] ?>%)</span>
                    <span class="amount"><?= formatFCFA($item['montant']) ?></span>
                </div>
            <?php endforeach; ?>
            <div class="item">
                <span class="name">📦 Épargne libre (<?= 100 - min($total_allocation, 100) ?>%)</span>
                <span class="amount"><?= formatFCFA($epargne_libre) ?></span>
            </div>
            <?php if ($surplus_total > 0): ?>
                <div class="item" style="color:#eab308;">
                    <span class="name">⚠️ Surplus total des objectifs atteints</span>
                    <span class="amount"><?= formatFCFA($surplus_total) ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- ============================================================
         AJOUTER UN OBJECTIF
         ============================================================ -->
    <div class="card">
        <h3><i class="fas fa-plus-circle"></i> Créer un objectif d'épargne</h3>
        
        <?php if ($epargne_reelle <= 0): ?>
            <div class="message info">
                <i class="fas fa-info-circle"></i> 
                Votre épargne est nulle. Vous pouvez quand même créer des objectifs, mais ils ne seront pas alimentés tant que vous n'aurez pas d'épargne.
            </div>
        <?php endif; ?>
        
        <?php if ($total_allocation >= 100): ?>
            <div class="message error">
                <i class="fas fa-exclamation-circle"></i> 
                ⚠️ Le total des allocations est déjà à 100%. Vous ne pouvez pas créer de nouvel objectif sans réduire les allocations existantes.
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="ajouter">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="grid-2">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="nom">📝 Nom de l'objectif</label>
                    <input type="text" name="nom" id="nom" placeholder="Ex: Achat téléphone" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="cible">🎯 Montant cible (FCFA)</label>
                    <input type="number" name="cible" id="cible" placeholder="150000" min="1" step="1" required>
                </div>
            </div>
            
            <div class="grid-3" style="margin-top:12px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="date_debut">📅 Date de début</label>
                    <input type="date" name="date_debut" id="date_debut" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="date_fin">📅 Date de fin</label>
                    <input type="date" name="date_fin" id="date_fin" value="<?= date('Y-m-d', strtotime('+3 months')) ?>" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="pourcentage_allocation">📊 % Allocation</label>
                    <input type="number" name="pourcentage_allocation" id="pourcentage_allocation" placeholder="30" min="1" max="100" step="1" required>
                </div>
            </div>
            
            <div style="margin-top:12px; font-size:12px; color:#94a3b8;">
                💡 Le pourcentage d'allocation est appliqué à l'épargne réelle de chaque mois.
                <?php if ($epargne_reelle > 0): ?>
                    Actuellement, 1% = <?= formatFCFA($epargne_reelle * 0.01) ?>
                <?php endif; ?>
                <?php if ($total_allocation > 0): ?>
                    &nbsp;|&nbsp; Total alloué : <strong><?= $total_allocation ?>%</strong>
                <?php endif; ?>
            </div>
            
            <button type="submit" class="btn-primary" style="margin-top:16px;" <?= $total_allocation >= 100 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                <i class="fas fa-plus"></i> Créer l'objectif
            </button>
        </form>
    </div>
    
    <!-- ============================================================
         LISTE DES OBJECTIFS
         ============================================================ -->
    <div style="margin-top:16px;">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Objectif</th>
                        <th>Cible</th>
                        <th>Collecté</th>
                        <th>Progression</th>
                        <th>% Alloc.</th>
                        <th>Statut</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($objectifs) > 0): ?>
                        <?php foreach ($objectifs as $obj): 
                            $progression = ($obj['cible'] > 0) ? round(($obj['montant_collecte'] / $obj['cible']) * 100, 1) : 0;
                            $statut_class = $obj['statut'];
                            if ($progression >= 100) $statut_class = 'atteint';
                            $surplus = ($obj['montant_collecte'] > $obj['cible']) ? ($obj['montant_collecte'] - $obj['cible']) : 0;
                        ?>
                            <tr>
                                <td><?= afficher($obj['nom']) ?></td>
                                <td><?= formatFCFA($obj['cible']) ?></td>
                                <td><?= formatFCFA($obj['montant_collecte']) ?></td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <div style="flex:1; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                                            <div style="height:100%; width:<?= min($progression, 100) ?>%; background:<?= $progression >= 100 ? '#22c55e' : ($progression >= 50 ? '#eab308' : '#2563eb') ?>; border-radius:3px;"></div>
                                        </div>
                                        <span style="font-weight:600; font-size:13px; min-width:45px;"><?= $progression ?>%</span>
                                    </div>
                                    <?php if ($surplus > 0): ?>
                                        <span style="font-size:11px; color:#eab308;">(+<?= formatFCFA($surplus) ?> surplus)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $obj['pourcentage_allocation'] ?>%</td>
                                <td>
                                    <span class="statut-badge <?= $statut_class ?>">
                                        <?= $statut_class === 'atteint' ? '✅ Atteint' : ucfirst(str_replace('_', ' ', $obj['statut'])) ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div class="actions">
                                        <button class="btn-sm btn-sm-primary" onclick="ouvrirModalModifierObjectif('<?= $obj['id'] ?>', <?= $obj['pourcentage_allocation'] ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-sm btn-sm-danger" onclick="ouvrirModalObjectif('<?= $obj['id'] ?>', '<?= addslashes(afficher($obj['nom'])) ?>', '<?= formatFCFA($obj['montant_collecte']) ?>', '<?= urlencode($_SESSION['csrf_token']) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:30px 0; color:#94a3b8;">
                                <i class="fas fa-inbox" style="font-size:28px; display:block; margin-bottom:8px;"></i>
                                Aucun objectif d'épargne défini.
                                <br>
                                Créez votre premier objectif ci-dessus.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- ============================================================
         MODALE MODIFIER ALLOCATION
         ============================================================ -->
    <div class="modal-overlay" id="modalModifierObjectif">
        <div class="modal-box">
            <div class="modal-icon edit"><i class="fas fa-edit"></i></div>
            <h3>Modifier l'allocation</h3>
            <p style="font-size:14px; color:#475569; margin-bottom:4px;">
                Modifier le pourcentage d'allocation de l'épargne vers cet objectif.
            </p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="objectif_id" id="modif_objectif_id">
                
                <div class="form-group">
                    <label for="modif_pourcentage_allocation">📊 % Allocation</label>
                    <input type="number" name="pourcentage_allocation" id="modif_pourcentage_allocation" min="0" max="100" step="1" required>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="fermerModalModifierObjectif()">
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
         MODALE SUPPRESSION OBJECTIF
         ============================================================ -->
    <div class="modal-overlay" id="modalSuppressionObjectif">
        <div class="modal-box">
            <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
            <h3 class="danger">Confirmer la suppression</h3>
            <p id="modalObjectifMessage">Êtes-vous sûr de vouloir supprimer cet objectif ?</p>
            <p class="sub-text" id="modalObjectifDetail">Les montants alloués seront reversés en épargne libre.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalObjectif()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienSuppressionObjectif" class="btn-confirm-danger">
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
function ouvrirModalModifierObjectif(id, allocation) {
    document.getElementById('modif_objectif_id').value = id;
    document.getElementById('modif_pourcentage_allocation').value = allocation;
    document.getElementById('modalModifierObjectif').classList.add('active');
}

function fermerModalModifierObjectif() {
    document.getElementById('modalModifierObjectif').classList.remove('active');
}

function ouvrirModalObjectif(id, nom, collecte, token) {
    document.getElementById('modalObjectifMessage').textContent = 'Êtes-vous sûr de vouloir supprimer l\'objectif "' + nom + '" ?';
    document.getElementById('modalObjectifDetail').textContent = 'Montant collecté : ' + collecte + ' - Les montants alloués seront reversés en épargne libre.';
    document.getElementById('lienSuppressionObjectif').href = '?supprimer=' + id + '&csrf_token=' + token;
    document.getElementById('modalSuppressionObjectif').classList.add('active');
}

function fermerModalObjectif() {
    document.getElementById('modalSuppressionObjectif').classList.remove('active');
}

document.getElementById('modalModifierObjectif').addEventListener('click', function(e) {
    if (e.target === this) fermerModalModifierObjectif();
});

document.getElementById('modalSuppressionObjectif').addEventListener('click', function(e) {
    if (e.target === this) fermerModalObjectif();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fermerModalModifierObjectif();
        fermerModalObjectif();
    }
});
</script>

</body>
</html>