<?php
// ============================================================
// DÉPENSES - GESTION DES DÉPENSES (PRÉVUES ET RÉELLES)
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
// RÉCUPÉRATION DES CATÉGORIES
// ============================================================
$stmt = $pdo->prepare("SELECT * FROM categorie WHERE compte_id = ? ORDER BY nom");
$stmt->execute([$compte_id]);
$categories = $stmt->fetchAll();

$budget_total = getBudgetTotal($pdo, $mois_id);

// ============================================================
// FONCTION : RÉCUPÉRER LES DÉPENSES AVEC STATUT
// ============================================================
function getDepensesAvecStatut($pdo, $mois_id) {
    $stmt = $pdo->prepare("
        SELECT d.*, ld.nom as ligne_nom, c.nom as categorie_nom, c.montant_plafond,
               CASE WHEN d.date_paiement IS NOT NULL THEN 'effectuee' ELSE 'prevue' END as statut
        FROM depense d
        JOIN ligne_depense ld ON ld.id = d.ligne_depense_id
        JOIN categorie c ON c.id = ld.categorie_id
        WHERE d.mois_id = ?
        ORDER BY d.date_paiement DESC, d.date_creation DESC
    ");
    $stmt->execute([$mois_id]);
    return $stmt->fetchAll();
}

// ============================================================
// RÉCUPÉRATION DES DÉPENSES
// ============================================================
$depenses = getDepensesAvecStatut($pdo, $mois_id);

// ============================================================
// CALCUL DES TOTAUX
// ============================================================
function calculerTotaux($depenses) {
    $total_prevues = 0;
    $total_effectuees = 0;
    foreach ($depenses as $d) {
        $total_prevues += $d['montant_prevu'];
        if ($d['statut'] === 'effectuee' && $d['montant_reel'] !== null) {
            $total_effectuees += $d['montant_reel'];
        }
    }
    return ['prevues' => $total_prevues, 'effectuees' => $total_effectuees];
}

$totaux = calculerTotaux($depenses);
$total_prevues = $totaux['prevues'];
$total_effectuees = $totaux['effectuees'];

// Taux de réalisation global
$taux_realisation_global = 0;
if ($total_prevues > 0) {
    $taux_realisation_global = round(($total_effectuees / $total_prevues) * 100, 1);
}

// ============================================================
// TRAITEMENT : AJOUT D'UNE DÉPENSE PRÉVUE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_prevue') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $categorie_id = $_POST['categorie_id'] ?? '';
        $nom = nettoyer($_POST['nom'] ?? '');
        $montant_prevu = floatval($_POST['montant_prevu'] ?? 0);
        $priorite = $_POST['priorite'] ?? 'moyen';
        $commentaire = nettoyer($_POST['commentaire'] ?? '');
        
        if (empty($categorie_id) || empty($nom)) {
            $erreur = "Veuillez remplir tous les champs obligatoires.";
        } elseif ($montant_prevu <= 0) {
            $erreur = "Le montant prévu doit être supérieur à 0.";
        } else {
            $ligne_id = genererUUID();
            $stmt = $pdo->prepare("
                INSERT INTO ligne_depense (id, categorie_id, mois_id, nom, montant_prevu)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ligne_id, $categorie_id, $mois_id, $nom, $montant_prevu]);
            
            $depense_id = genererUUID();
            $stmt = $pdo->prepare("
                INSERT INTO depense (id, ligne_depense_id, mois_id, montant_prevu, priorite, commentaire)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$depense_id, $ligne_id, $mois_id, $montant_prevu, $priorite, $commentaire]);
            
            $_SESSION['message_succes'] = "✅ Dépense prévue ajoutée avec succès !";
            rediriger('depenses.php');
        }
    }
}

// ============================================================
// TRAITEMENT : AJOUT D'UNE DÉPENSE DIRECTE (EFFECTUÉE)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_directe') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $categorie_id = $_POST['categorie_id'] ?? '';
        $nom = nettoyer($_POST['nom'] ?? '');
        $montant_reel = floatval($_POST['montant_reel'] ?? 0);
        $date_paiement = $_POST['date_paiement'] ?? date('Y-m-d');
        $priorite = $_POST['priorite'] ?? 'moyen';
        $commentaire = nettoyer($_POST['commentaire'] ?? '');
        
        if (empty($categorie_id) || empty($nom)) {
            $erreur = "Veuillez remplir tous les champs obligatoires.";
        } elseif ($montant_reel <= 0) {
            $erreur = "Le montant réel doit être supérieur à 0.";
        } elseif (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date_paiement) || $date_paiement > date('Y-m-d')) {
            $erreur = "La date ne peut pas être dans le futur.";
        } else {
            $montant_prevu = $montant_reel;
            
            $ligne_id = genererUUID();
            $stmt = $pdo->prepare("
                INSERT INTO ligne_depense (id, categorie_id, mois_id, nom, montant_prevu)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ligne_id, $categorie_id, $mois_id, $nom, $montant_prevu]);
            
            $depense_id = genererUUID();
            $stmt = $pdo->prepare("
                INSERT INTO depense (id, ligne_depense_id, mois_id, montant_prevu, montant_reel, date_paiement, priorite, commentaire)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$depense_id, $ligne_id, $mois_id, $montant_prevu, $montant_reel, $date_paiement, $priorite, $commentaire]);
            
            $_SESSION['message_succes'] = "✅ Dépense effectuée ajoutée avec succès !";
            rediriger('depenses.php');
        }
    }
}

// ============================================================
// TRAITEMENT : MARQUER COMME EFFECTUÉ
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'marquer_effectue') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $depense_id = $_POST['depense_id'] ?? '';
        $montant_reel = floatval($_POST['montant_reel'] ?? 0);
        $date_paiement = $_POST['date_paiement'] ?? date('Y-m-d');
        
        if ($montant_reel <= 0) {
            $erreur = "Le montant réel doit être supérieur à 0.";
        } elseif (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date_paiement) || $date_paiement > date('Y-m-d')) {
            $erreur = "La date ne peut pas être dans le futur.";
        } else {
            $stmt = $pdo->prepare("
                UPDATE depense 
                SET montant_reel = ?, date_paiement = ?
                WHERE id = ? AND mois_id = ?
            ");
            $stmt->execute([$montant_reel, $date_paiement, $depense_id, $mois_id]);
            $_SESSION['message_succes'] = "✅ Dépense marquée comme effectuée !";
            rediriger('depenses.php');
        }
    }
}

// ============================================================
// TRAITEMENT : SUPPRESSION
// ============================================================
if (isset($_GET['supprimer'])) {
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        rediriger('depenses.php');
    } else {
        $depense_id = $_GET['supprimer'];
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $depense_id)) {
            $stmt = $pdo->prepare("DELETE FROM depense WHERE id = ? AND mois_id = ?");
            $stmt->execute([$depense_id, $mois_id]);
            $_SESSION['message_succes'] = "✅ Dépense supprimée avec succès !";
        } else {
            $_SESSION['message_info'] = "⚠️ Identifiant invalide.";
        }
        rediriger('depenses.php');
    }
}

// ============================================================
// TRAITEMENT : ENREGISTRER LES DÉPENSES → VERS BUDGET
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'enregistrer_depenses') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM depense WHERE mois_id = ?");
        $stmt->execute([$mois_id]);
        $nb_depenses = $stmt->fetchColumn();
        
        if ($nb_depenses == 0) {
            $erreur = "⚠️ Vous devez ajouter au moins une dépense avant de continuer.";
        } else {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_prevu), 0) FROM depense WHERE mois_id = ?");
            $stmt->execute([$mois_id]);
            $total_depenses = $stmt->fetchColumn();
            
            $_SESSION['message_succes'] = "✅ Dépenses enregistrées ! Total des dépenses prévues : " . formatFCFA($total_depenses);
            rediriger('budget.php');
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
    <title>Budget Manager - Dépenses</title>
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
        
        .card { background: white; border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
        .card h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin-bottom: 16px; }
        .card h3 i { color: #2563eb; margin-right: 8px; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: 'Inter', sans-serif; transition: border-color 0.2s; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        
        .btn-primary { padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary:hover { background: #1d4ed8; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(37,99,235,0.3); }
        .btn-success { padding: 10px 24px; background: #22c55e; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-success:hover { background: #16a34a; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(34,197,94,0.3); }
        .btn-secondary { padding: 10px 24px; background: #f1f5f9; color: #0f172a; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-sm { padding: 4px 12px; font-size: 12px; border-radius: 6px; cursor: pointer; border: none; transition: all 0.2s; }
        .btn-sm-primary { background: #2563eb; color: white; }
        .btn-sm-primary:hover { background: #1d4ed8; }
        .btn-sm-success { background: #22c55e; color: white; }
        .btn-sm-success:hover { background: #16a34a; }
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
        .table-container .statut { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
        .table-container .statut.effectuee { background: #dcfce7; color: #16a34a; }
        .table-container .statut.prevue { background: #fef3c7; color: #d97706; }
        
        .priorite-badge { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .priorite-badge.critique { background: #dcfce7; color: #16a34a; }
        .priorite-badge.moyen { background: #fef3c7; color: #d97706; }
        .priorite-badge.leger { background: #e0e7ff; color: #4f46e5; }
        
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
        .budget-summary .value { font-size: 20px; font-weight: 700; }
        
        .form-row-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .btn-row { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .btn-toggle-group { display: flex; gap: 8px; margin-bottom: 16px; }
        .btn-toggle-group .btn { flex: 1; padding: 10px; border-radius: 10px; border: 2px solid #e2e8f0; background: white; color: #64748b; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-toggle-group .btn.active { border-color: #2563eb; background: #eff6ff; color: #2563eb; }
        .btn-toggle-group .btn:hover { border-color: #2563eb; }
        .form-toggle { display: none; }
        .form-toggle.active { display: block; }
        
        .indicator-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .indicator-item { background: white; border-radius: 12px; padding: 12px 16px; border: 1px solid #e2e8f0; text-align: center; }
        .indicator-item .label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .indicator-item .value { font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 2px; }
        .indicator-item .value.orange { color: #eab308; }
        .indicator-item .value.green { color: #22c55e; }
        .indicator-item .value.blue { color: #2563eb; }
        
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
        .modal-box .modal-icon.success { color: #22c55e; }
        .modal-box h3 { text-align: center; font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .modal-box p { text-align: center; font-size: 14px; color: #475569; margin-bottom: 4px; }
        .modal-box .sub-text { font-size: 13px; color: #94a3b8; margin-bottom: 20px; }
        .modal-box .modal-actions { display: flex; gap: 12px; justify-content: center; }
        .modal-box .modal-actions .btn-cancel { background: #f1f5f9; color: #0f172a; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .modal-box .modal-actions .btn-cancel:hover { background: #e2e8f0; }
        .modal-box .modal-actions .btn-confirm { background: #2563eb; color: white; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; }
        .modal-box .modal-actions .btn-confirm:hover { background: #1d4ed8; transform: scale(1.02); }
        .modal-box .modal-actions .btn-success-action { background: #22c55e; color: white; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; }
        .modal-box .modal-actions .btn-success-action:hover { background: #16a34a; transform: scale(1.02); }
        .modal-box .modal-actions .btn-confirm-danger { background: #dc2626; color: white; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; }
        .modal-box .modal-actions .btn-confirm-danger:hover { background: #b91c1c; transform: scale(1.02); }
        
        @media (max-width: 768px) {
            .app-header .top-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .app-header .user-info { justify-content: space-between; }
            .app-nav { gap: 2px; justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .app-nav a { padding: 6px 12px; min-width: 44px; }
            .app-nav a i { font-size: 16px; }
            .app-nav a .nav-label { font-size: 8px; }
            .form-row-3 { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .indicator-grid { grid-template-columns: repeat(2, 1fr); }
            .table-container { overflow-x: auto; }
            .budget-summary { flex-direction: column; text-align: center; }
            .modal-box { margin: 16px; padding: 24px; }
            .btn-toggle-group { flex-direction: column; }
        }
        @media (max-width: 480px) { .app-container { padding: 10px 12px; } .app-header { padding: 10px 16px; } .card { padding: 16px; } }
    </style>
</head>
<body class="<?= ($_SESSION['theme'] ?? 'clair') === 'sombre' ? 'theme-sombre' : '' ?>">

<div class="app-container">
    
    <?php require_once 'header.php'; ?>
    
    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div class="page-header">
        <h2><i class="fas fa-receipt"></i> Dépenses</h2>
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
            <div class="label">💰 Budget total</div>
            <div class="value"><?= formatFCFA($budget_total) ?></div>
        </div>
        <div>
            <div class="label">📊 Dépenses prévues</div>
            <div class="value"><?= formatFCFA($total_prevues) ?></div>
        </div>
        <div>
            <div class="label">✅ Dépenses effectuées</div>
            <div class="value"><?= formatFCFA($total_effectuees) ?></div>
        </div>
        <div>
            <div class="label">📈 Taux de réalisation</div>
            <div class="value <?= $taux_realisation_global <= 100 ? 'green' : ($taux_realisation_global <= 120 ? 'orange' : 'red') ?>">
                <?= $taux_realisation_global ?>%
            </div>
        </div>
        <div>
            <div class="label">📈 Reste disponible</div>
            <div class="value"><?= formatFCFA($budget_total - $total_prevues) ?></div>
        </div>
    </div>
    
    <!-- ============================================================
         INDICATEURS
         ============================================================ -->
    <div class="indicator-grid">
        <div class="indicator-item">
            <div class="label">Total prévu</div>
            <div class="value orange"><?= formatFCFA($total_prevues) ?></div>
        </div>
        <div class="indicator-item">
            <div class="label">Total effectué</div>
            <div class="value green"><?= formatFCFA($total_effectuees) ?></div>
        </div>
        <div class="indicator-item">
            <div class="label">Écart</div>
            <div class="value blue"><?= formatFCFA($total_prevues - $total_effectuees) ?></div>
        </div>
        <div class="indicator-item">
            <div class="label">Taux de réalisation</div>
            <div class="value <?= $taux_realisation_global <= 100 ? 'green' : ($taux_realisation_global <= 120 ? 'orange' : 'red') ?>">
                <?= $taux_realisation_global ?>%
            </div>
        </div>
    </div>
    
    <!-- ============================================================
         AJOUTER UNE DÉPENSE
         ============================================================ -->
    <div class="card">
        <h3><i class="fas fa-plus-circle"></i> Ajouter une dépense</h3>
        
        <?php if ($budget_total == 0): ?>
            <div class="message info">
                <i class="fas fa-info-circle"></i> 
                Vous n'avez pas encore de revenus enregistrés.
                <a href="revenus.php">Ajoutez des revenus</a> avant de créer des dépenses.
            </div>
        <?php endif; ?>
        
        <div class="btn-toggle-group">
            <button class="btn active" id="btnPrevue" onclick="toggleForm('prevue')">
                📝 Dépense prévue
            </button>
            <button class="btn" id="btnDirecte" onclick="toggleForm('directe')">
                ⚡ Dépense directe (effectuée)
            </button>
        </div>
        
        <div class="form-toggle active" id="formPrevue">
            <form method="POST" action="">
                <input type="hidden" name="action" value="ajouter_prevue">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="form-row-3">
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="categorie_id_prevue">📂 Catégorie</label>
                        <select name="categorie_id" id="categorie_id_prevue" required <?= $budget_total == 0 ? 'disabled' : '' ?>>
                            <option value="">-- Sélectionner --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= afficher($c['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="nom_prevue">📝 Nom</label>
                        <input type="text" name="nom" id="nom_prevue" placeholder="Ex: Courses" required <?= $budget_total == 0 ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="montant_prevu">💰 Montant prévu</label>
                        <input type="number" name="montant_prevu" id="montant_prevu" placeholder="Estimation" min="1" step="1" required <?= $budget_total == 0 ? 'disabled' : '' ?>>
                    </div>
                </div>
                
                <div class="form-row" style="margin-top:12px;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="priorite_prevue">🔵 Priorité</label>
                        <select name="priorite" id="priorite_prevue" <?= $budget_total == 0 ? 'disabled' : '' ?>>
                            <option value="critique">Critique 🟢</option>
                            <option value="moyen" selected>Moyen 🟡</option>
                            <option value="leger">Léger 🔵</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="commentaire_prevue">💬 Commentaire (optionnel)</label>
                        <input type="text" name="commentaire" id="commentaire_prevue" placeholder="Précision" <?= $budget_total == 0 ? 'disabled' : '' ?>>
                    </div>
                </div>
                
                <button type="submit" class="btn-primary" style="margin-top:16px;" <?= $budget_total == 0 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                    <i class="fas fa-plus"></i> Ajouter comme prévue
                </button>
            </form>
        </div>
        
        <div class="form-toggle" id="formDirecte">
            <form method="POST" action="">
                <input type="hidden" name="action" value="ajouter_directe">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="form-row-3">
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="categorie_id_directe">📂 Catégorie</label>
                        <select name="categorie_id" id="categorie_id_directe" required <?= $budget_total == 0 ? 'disabled' : '' ?>>
                            <option value="">-- Sélectionner --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= afficher($c['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="nom_directe">📝 Nom</label>
                        <input type="text" name="nom" id="nom_directe" placeholder="Ex: Uber" required <?= $budget_total == 0 ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="montant_reel">💰 Montant réel</label>
                        <input type="number" name="montant_reel" id="montant_reel" placeholder="Montant payé" min="1" step="1" required <?= $budget_total == 0 ? 'disabled' : '' ?>>
                    </div>
                </div>
                
                <div class="form-row" style="margin-top:12px;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="date_paiement_directe">📅 Date de paiement</label>
                        <input type="date" name="date_paiement" id="date_paiement_directe" value="<?= date('Y-m-d') ?>" required <?= $budget_total == 0 ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="priorite_directe">🔵 Priorité</label>
                        <select name="priorite" id="priorite_directe" <?= $budget_total == 0 ? 'disabled' : '' ?>>
                            <option value="critique">Critique 🟢</option>
                            <option value="moyen" selected>Moyen 🟡</option>
                            <option value="leger">Léger 🔵</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group" style="margin-top:12px; margin-bottom:0;">
                    <label for="commentaire_directe">💬 Commentaire (optionnel)</label>
                    <input type="text" name="commentaire" id="commentaire_directe" placeholder="Précision" <?= $budget_total == 0 ? 'disabled' : '' ?>>
                </div>
                
                <button type="submit" class="btn-success" style="margin-top:16px;" <?= $budget_total == 0 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                    <i class="fas fa-check"></i> Ajouter comme effectuée
                </button>
            </form>
        </div>
    </div>
    
    <!-- ============================================================
         LISTE DES DÉPENSES
         ============================================================ -->
    <div style="margin-top:16px;">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Dépense</th>
                        <th>Catégorie</th>
                        <th>Priorité</th>
                        <th>Prévu</th>
                        <th>Réel</th>
                        <th>Taux</th>
                        <th>Plafond</th>
                        <th>Statut</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($depenses) > 0): ?>
                        <?php foreach ($depenses as $d): ?>
                            <tr>
                                <td><?= afficher($d['ligne_nom']) ?></td>
                                <td><?= afficher($d['categorie_nom']) ?></td>
                                <td><span class="priorite-badge <?= $d['priorite'] ?>"><?= ucfirst($d['priorite']) ?></span></td>
                                <td><?= formatFCFA($d['montant_prevu']) ?></td>
                                <td><?= $d['montant_reel'] !== null ? formatFCFA($d['montant_reel']) : '-' ?></td>
                                <td>
                                    <?php if ($d['montant_reel'] !== null && $d['montant_prevu'] > 0): 
                                        $taux = round(($d['montant_reel'] / $d['montant_prevu']) * 100, 1);
                                        $couleur_taux = $taux <= 100 ? '#22c55e' : ($taux <= 120 ? '#eab308' : '#ef4444');
                                    ?>
                                        <span style="font-weight:600; color:<?= $couleur_taux ?>;">
                                            <?= $taux ?>%
                                        </span>
                                        <?php if ($taux > 100): ?>
                                            <span style="font-size:11px; color:#ef4444;">⬆️ +<?= round($taux - 100, 1) ?>%</span>
                                        <?php elseif ($taux < 100): ?>
                                            <span style="font-size:11px; color:#22c55e;">⬇️ -<?= round(100 - $taux, 1) ?>%</span>
                                        <?php else: ?>
                                            <span style="font-size:11px; color:#22c55e;">✅ Parfait</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($d['montant_plafond'] > 0): ?>
                                        <?= formatFCFA($d['montant_plafond']) ?>
                                        <?php if ($d['montant_prevu'] > $d['montant_plafond']): ?>
                                            <span style="background:#fef2f2; color:#dc2626; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:600;">⚠️ Dépasse</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Pas de limite
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="statut <?= $d['statut'] ?>">
                                        <?= $d['statut'] === 'effectuee' ? '✅ Effectuée' : '⬜ Prévue' ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div class="actions">
                                        <?php if ($d['statut'] === 'prevue'): ?>
                                            <button class="btn-sm btn-sm-success" onclick="ouvrirModalEffectuer('<?= $d['id'] ?>', '<?= addslashes(afficher($d['ligne_nom'])) ?>', <?= $d['montant_prevu'] ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-size:12px;">✓</span>
                                        <?php endif; ?>
                                        <button class="btn-sm btn-sm-danger" onclick="ouvrirModalSuppression('<?= $d['id'] ?>', '<?= addslashes(afficher($d['ligne_nom'])) ?>', '<?= formatFCFA($d['montant_prevu']) ?>', '<?= urlencode($_SESSION['csrf_token']) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="3" style="text-align:right;">Total</td>
                            <td><?= formatFCFA($total_prevues) ?></td>
                            <td><?= formatFCFA($total_effectuees) ?></td>
                            <td><?= $taux_realisation_global ?>%</td>
                            <td colspan="3"></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:30px 0; color:#94a3b8;">
                                <i class="fas fa-inbox" style="font-size:28px; display:block; margin-bottom:8px;"></i>
                                Aucune dépense enregistrée pour ce mois.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- ============================================================
         BOUTON : ENREGISTRER LES DÉPENSES → VERS BUDGET
         ============================================================ -->
    <div class="btn-row">
        <form method="POST" action="">
            <input type="hidden" name="action" value="enregistrer_depenses">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" class="btn-success" <?= count($depenses) == 0 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                <i class="fas fa-check-circle"></i> Enregistrer les dépenses et continuer
            </button>
        </form>
        <?php if (count($depenses) > 0): ?>
            <span style="font-size:13px; color:#64748b; display:flex; align-items:center;">
                <i class="fas fa-arrow-right" style="margin-right:6px;"></i> 
                Vous allez être redirigé vers la configuration du budget
            </span>
        <?php else: ?>
            <span style="font-size:13px; color:#94a3b8; display:flex; align-items:center;">
                <i class="fas fa-info-circle" style="margin-right:6px;"></i> 
                Ajoutez au moins une dépense pour continuer
            </span>
        <?php endif; ?>
    </div>
    
    <!-- ============================================================
         MODALES PERSONNALISÉES
         ============================================================ -->
    
    <!-- MODALE : MARQUER COMME EFFECTUÉ -->
    <div class="modal-overlay" id="modalEffectuer">
        <div class="modal-box">
            <div class="modal-icon success"><i class="fas fa-check-circle"></i></div>
            <h3>Marquer comme effectuée</h3>
            <p id="modalEffectuerMessage">Saisissez le montant réellement dépensé</p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="marquer_effectue">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="depense_id" id="effectuer_depense_id">
                
                <div class="form-group">
                    <label for="effectuer_montant_reel">💰 Montant réel (FCFA)</label>
                    <input type="number" name="montant_reel" id="effectuer_montant_reel" placeholder="Ex: 95000" min="1" step="1" required>
                </div>
                <div class="form-group">
                    <label for="effectuer_date_paiement">📅 Date de paiement</label>
                    <input type="date" name="date_paiement" id="effectuer_date_paiement" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="fermerModalEffectuer()">
                        <i class="fas fa-times"></i> Annuler
                    </button>
                    <button type="submit" class="btn-success-action">
                        <i class="fas fa-check"></i> Confirmer
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- MODALE : SUPPRESSION -->
    <div class="modal-overlay" id="modalSuppression">
        <div class="modal-box">
            <div class="modal-icon warning"><i class="fas fa-exclamation-circle"></i></div>
            <h3>Confirmer la suppression</h3>
            <p id="modalSuppressionMessage">Êtes-vous sûr de vouloir supprimer cette dépense ?</p>
            <p class="sub-text" id="modalSuppressionDetail">Cette action est irréversible.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalSuppression()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienSuppression" class="btn-confirm-danger">
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
function toggleForm(type) {
    document.getElementById('formPrevue').classList.remove('active');
    document.getElementById('formDirecte').classList.remove('active');
    document.getElementById('btnPrevue').classList.remove('active');
    document.getElementById('btnDirecte').classList.remove('active');
    
    if (type === 'prevue') {
        document.getElementById('formPrevue').classList.add('active');
        document.getElementById('btnPrevue').classList.add('active');
    } else {
        document.getElementById('formDirecte').classList.add('active');
        document.getElementById('btnDirecte').classList.add('active');
    }
}

function ouvrirModalEffectuer(id, nom, montantPrevu) {
    document.getElementById('effectuer_depense_id').value = id;
    document.getElementById('effectuer_montant_reel').value = montantPrevu;
    document.getElementById('effectuer_montant_reel').placeholder = 'Ex: ' + montantPrevu;
    document.getElementById('modalEffectuerMessage').textContent = 'Saisissez le montant réel pour "' + nom + '"';
    document.getElementById('modalEffectuer').classList.add('active');
}

function fermerModalEffectuer() {
    document.getElementById('modalEffectuer').classList.remove('active');
}

function ouvrirModalSuppression(id, nom, detail, token) {
    document.getElementById('modalSuppressionMessage').textContent = 'Êtes-vous sûr de vouloir supprimer "' + nom + '" ?';
    document.getElementById('modalSuppressionDetail').textContent = detail || 'Cette action est irréversible.';
    document.getElementById('lienSuppression').href = '?supprimer=' + id + '&csrf_token=' + token;
    document.getElementById('modalSuppression').classList.add('active');
}

function fermerModalSuppression() {
    document.getElementById('modalSuppression').classList.remove('active');
}

document.getElementById('modalEffectuer').addEventListener('click', function(e) {
    if (e.target === this) fermerModalEffectuer();
});

document.getElementById('modalSuppression').addEventListener('click', function(e) {
    if (e.target === this) fermerModalSuppression();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fermerModalEffectuer();
        fermerModalSuppression();
    }
});
</script>

</body>
</html>