<?php
// ============================================================
// CATÉGORIES - GESTION DES CATÉGORIES ET PLAFONDS
// ============================================================

require_once 'config.php';
require_once 'session_init.php';
require_once 'functions.php';

if (!estConnecte()) {
    rediriger('auth.php');
}

$compte_id = $_SESSION['utilisateur_id'];
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
$stmt = $pdo->prepare("
    SELECT c.*, 
           (SELECT COUNT(*) FROM ligne_depense ld WHERE ld.categorie_id = c.id) as nb_depenses
    FROM categorie c
    WHERE c.compte_id = ?
    ORDER BY c.nom
");
$stmt->execute([$compte_id]);
$categories = $stmt->fetchAll();

// ============================================================
// TRAITEMENT : AJOUT D'UNE CATÉGORIE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $nom = nettoyer($_POST['nom'] ?? '');
        $montant_plafond = floatval($_POST['montant_plafond'] ?? 0);
        
        if (empty($nom)) {
            $erreur = "Le nom de la catégorie est obligatoire.";
        } elseif ($montant_plafond < 0) {
            $erreur = "Le plafond ne peut pas être négatif.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO categorie (id, compte_id, nom, montant_plafond)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([genererUUID(), $compte_id, $nom, $montant_plafond]);
                $_SESSION['message_succes'] = "✅ Catégorie ajoutée avec succès !";
                rediriger('categories.php');
            } catch (PDOException $e) {
                if ($e->errorInfo[1] == 1062) {
                    $erreur = "Cette catégorie existe déjà.";
                } else {
                    $erreur = "Erreur lors de l'ajout.";
                }
            }
        }
    }
}

// ============================================================
// TRAITEMENT : MODIFICATION D'UNE CATÉGORIE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $categorie_id = $_POST['categorie_id'] ?? '';
        $nom = nettoyer($_POST['nom'] ?? '');
        $montant_plafond = floatval($_POST['montant_plafond'] ?? 0);
        
        if (empty($nom)) {
            $erreur = "Le nom de la catégorie est obligatoire.";
        } elseif ($montant_plafond < 0) {
            $erreur = "Le plafond ne peut pas être négatif.";
        } else {
            $stmt = $pdo->prepare("
                UPDATE categorie 
                SET nom = ?, montant_plafond = ?
                WHERE id = ? AND compte_id = ?
            ");
            $stmt->execute([$nom, $montant_plafond, $categorie_id, $compte_id]);
            $_SESSION['message_succes'] = "✅ Catégorie modifiée avec succès !";
            rediriger('categories.php');
        }
    }
}

// ============================================================
// TRAITEMENT : SUPPRESSION D'UNE CATÉGORIE
// ============================================================
if (isset($_GET['supprimer'])) {
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        rediriger('categories.php');
    } else {
        $categorie_id = $_GET['supprimer'];
        
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $categorie_id)) {
            $_SESSION['message_info'] = "⚠️ Identifiant invalide.";
            rediriger('categories.php');
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ligne_depense WHERE categorie_id = ?");
        $stmt->execute([$categorie_id]);
        $nb_depenses = $stmt->fetchColumn();
        
        if ($nb_depenses > 0) {
            $_SESSION['message_info'] = "⚠️ Cette catégorie a des dépenses associées. Vous ne pouvez pas la supprimer.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM categorie WHERE id = ? AND compte_id = ?");
            $stmt->execute([$categorie_id, $compte_id]);
            $_SESSION['message_succes'] = "✅ Catégorie supprimée avec succès !";
        }
        rediriger('categories.php');
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
    <title>Budget Manager - Catégories</title>
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
        .btn-secondary { padding: 10px 24px; background: #f1f5f9; color: #0f172a; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-secondary:hover { background: #e2e8f0; }
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
        
        .plafond-badge { padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .plafond-badge.defini { background: #dcfce7; color: #16a34a; }
        .plafond-badge.non-defini { background: #fef3c7; color: #d97706; }
        
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
            <a href="categories.php" class="active"><i class="fas fa-tags"></i><span class="nav-label">Catégories</span></a>
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
        <h2><i class="fas fa-tags"></i> Catégories</h2>
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
         AJOUTER UNE CATÉGORIE
         ============================================================ -->
    <div class="card">
        <h3><i class="fas fa-plus-circle"></i> Ajouter une catégorie</h3>
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="ajouter">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="form-row">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="nom">📝 Nom de la catégorie</label>
                    <input type="text" name="nom" id="nom" placeholder="Ex: Électricité" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="montant_plafond">📊 Plafond (FCFA)</label>
                    <input type="number" name="montant_plafond" id="montant_plafond" placeholder="0" min="0" step="1">
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <button type="submit" class="btn-primary" style="width:100%;">
                        <i class="fas fa-plus"></i> Ajouter
                    </button>
                </div>
            </div>
            <p style="font-size:12px; color:#94a3b8; margin-top:8px;">
                💡 Le plafond est une limite max pour cette catégorie (0 = pas de limite)
            </p>
        </form>
    </div>
    
    <!-- ============================================================
         LISTE DES CATÉGORIES
         ============================================================ -->
    <div style="margin-top:16px;">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <th>Plafond</th>
                        <th>Dépenses associées</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($categories) > 0): ?>
                        <?php foreach ($categories as $c): ?>
                            <tr>
                                <td><?= afficher($c['nom']) ?></td>
                                <td>
                                    <?php if ($c['montant_plafond'] > 0): ?>
                                        <span class="plafond-badge defini"><?= formatFCFA($c['montant_plafond']) ?></span>
                                    <?php else: ?>
                                        <span class="plafond-badge non-defini">Pas de limite</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $c['nb_depenses'] ?></td>
                                <td style="text-align:right;">
                                    <div style="display:flex; gap:6px; justify-content:flex-end;">
                                        <button class="btn-sm btn-sm-primary" onclick="ouvrirModalModifierCategorie('<?= $c['id'] ?>', '<?= addslashes(afficher($c['nom'])) ?>', <?= $c['montant_plafond'] ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($c['nb_depenses'] == 0): ?>
                                            <button class="btn-sm btn-sm-danger" onclick="ouvrirModalCategorie('<?= $c['id'] ?>', '<?= addslashes(afficher($c['nom'])) ?>', '<?= urlencode($_SESSION['csrf_token']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="btn-sm btn-sm-danger" style="opacity:0.3; cursor:not-allowed;" title="Des dépenses sont associées">
                                                <i class="fas fa-trash"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:30px 0; color:#94a3b8;">
                                <i class="fas fa-inbox" style="font-size:28px; display:block; margin-bottom:8px;"></i>
                                Aucune catégorie enregistrée.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- ============================================================
         MODALE MODIFIER CATÉGORIE
         ============================================================ -->
    <div class="modal-overlay" id="modalModifierCategorie">
        <div class="modal-box">
            <div class="modal-icon edit"><i class="fas fa-edit"></i></div>
            <h3>Modifier la catégorie</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="categorie_id" id="modif_categorie_id">
                
                <div class="form-group">
                    <label for="modif_nom">📝 Nom</label>
                    <input type="text" name="nom" id="modif_nom" required>
                </div>
                <div class="form-group">
                    <label for="modif_montant_plafond">📊 Plafond (FCFA)</label>
                    <input type="number" name="montant_plafond" id="modif_montant_plafond" min="0" step="1">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="fermerModalModifierCategorie()">
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
         MODALE SUPPRESSION CATÉGORIE
         ============================================================ -->
    <div class="modal-overlay" id="modalCategorie">
        <div class="modal-box">
            <div class="modal-icon danger"><i class="fas fa-exclamation-circle"></i></div>
            <h3 class="danger">Confirmer la suppression</h3>
            <p id="modalCategorieMessage">Êtes-vous sûr de vouloir supprimer cette catégorie ?</p>
            <p class="sub-text">Cette action est irréversible.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalCategorie()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienSuppressionCategorie" class="btn-confirm-danger">
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
function ouvrirModalModifierCategorie(id, nom, plafond) {
    document.getElementById('modif_categorie_id').value = id;
    document.getElementById('modif_nom').value = nom;
    document.getElementById('modif_montant_plafond').value = plafond;
    document.getElementById('modalModifierCategorie').classList.add('active');
}

function fermerModalModifierCategorie() {
    document.getElementById('modalModifierCategorie').classList.remove('active');
}

function ouvrirModalCategorie(id, nom, token) {
    document.getElementById('modalCategorieMessage').textContent = 'Êtes-vous sûr de vouloir supprimer la catégorie "' + nom + '" ?';
    document.getElementById('lienSuppressionCategorie').href = '?supprimer=' + id + '&csrf_token=' + token;
    document.getElementById('modalCategorie').classList.add('active');
}

function fermerModalCategorie() {
    document.getElementById('modalCategorie').classList.remove('active');
}

document.getElementById('modalModifierCategorie').addEventListener('click', function(e) {
    if (e.target === this) fermerModalModifierCategorie();
});

document.getElementById('modalCategorie').addEventListener('click', function(e) {
    if (e.target === this) fermerModalCategorie();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fermerModalModifierCategorie();
        fermerModalCategorie();
    }
});
</script>

</body>
</html>