<?php
// ============================================================
// HISTORIQUE - CONSULTATION DES MOIS PASSÉS
// ============================================================

require_once 'config.php';
require_once 'session_init.php';
require_once 'functions.php';

if (!estConnecte()) {
    rediriger('auth.php');
}

$compte_id = $_SESSION['utilisateur_id'];
$mois_courant = getMoisEnCours($pdo, $compte_id);

// Générer un token CSRF si inexistant
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$erreur = '';
$succes = '';
$info = '';

// Récupérer le nombre de notifications non lues
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE compte_id = ? AND etat = 'non_lue'");
$stmt->execute([$compte_id]);
$nb_non_lues = $stmt->fetchColumn();

// ============================================================
// RÉCUPÉRATION DE LA LISTE DES MOIS
// ============================================================
$stmt = $pdo->prepare("
    SELECT m.*,
           (SELECT COALESCE(SUM(r.montant), 0) FROM revenu r WHERE r.mois_id = m.id) as budget_total,
           (SELECT COALESCE(SUM(d.montant_reel), 0) FROM depense d WHERE d.mois_id = m.id AND d.montant_reel IS NOT NULL) as depenses_reelles,
           (SELECT COALESCE(SUM(ui.montant), 0) FROM utilisation_imprevu ui WHERE ui.mois_id = m.id) as imprevus_utilises
    FROM mois m
    WHERE m.compte_id = ?
    ORDER BY m.periode DESC
");
$stmt->execute([$compte_id]);
$mois_liste = $stmt->fetchAll();

// ============================================================
// TRAITEMENT : RÉUTILISER UN MOIS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reutiliser') {
    
    // Vérification CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $source_mois_id = $_POST['source_mois_id'] ?? '';
        $mois_cible_id = $_POST['mois_cible'] ?? '';
        $mode_copie = $_POST['mode_copie'] ?? 'ajouter';
        $copier_depenses = isset($_POST['copier_depenses']);
        $copier_revenus = isset($_POST['copier_revenus']);
        $copier_priorites = isset($_POST['copier_priorites']);
        
        if (empty($source_mois_id) || empty($mois_cible_id)) {
            $erreur = "Veuillez sélectionner un mois source et un mois cible.";
        } else {
            // Vérifier que le mois cible existe
            $stmt = $pdo->prepare("SELECT * FROM mois WHERE id = ? AND compte_id = ?");
            $stmt->execute([$mois_cible_id, $compte_id]);
            $mois_cible = $stmt->fetch();
            
            if (!$mois_cible) {
                $erreur = "Le mois cible n'existe pas.";
            } else {
                $nb_copie = 0;
                
                // MODE REMPLACER : supprimer les données existantes
                if ($mode_copie === 'remplacer') {
                    if ($copier_depenses) {
                        $stmt = $pdo->prepare("DELETE FROM depense WHERE mois_id = ?");
                        $stmt->execute([$mois_cible_id]);
                        $stmt = $pdo->prepare("DELETE FROM ligne_depense WHERE mois_id = ?");
                        $stmt->execute([$mois_cible_id]);
                    }
                    if ($copier_revenus) {
                        $stmt = $pdo->prepare("DELETE FROM revenu WHERE mois_id = ?");
                        $stmt->execute([$mois_cible_id]);
                    }
                    if ($copier_priorites) {
                        $stmt = $pdo->prepare("
                            UPDATE mois 
                            SET pourcentage_critique = 60, 
                                pourcentage_moyen = 30, 
                                pourcentage_leger = 10, 
                                montant_reserve_imprevus = 0
                            WHERE id = ?
                        ");
                        $stmt->execute([$mois_cible_id]);
                    }
                }
                
                // COPIER LES DÉPENSES (sans doublons)
                if ($copier_depenses) {
                    $stmt = $pdo->prepare("
                        SELECT ld.*, d.priorite, d.commentaire 
                        FROM ligne_depense ld
                        JOIN depense d ON d.ligne_depense_id = ld.id
                        WHERE d.mois_id = ?
                        GROUP BY ld.id
                    ");
                    $stmt->execute([$source_mois_id]);
                    $lignes = $stmt->fetchAll();
                    
                    foreach ($lignes as $ligne) {
                        // Vérifier si la dépense existe déjà
                        $stmt = $pdo->prepare("
                            SELECT ld.id FROM ligne_depense ld
                            JOIN depense d ON d.ligne_depense_id = ld.id
                            WHERE ld.mois_id = ? AND ld.nom = ? AND ld.categorie_id = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$mois_cible_id, $ligne['nom'], $ligne['categorie_id']]);
                        $existant = $stmt->fetch();
                        
                        if ($existant) {
                            $stmt = $pdo->prepare("UPDATE ligne_depense SET montant_prevu = ? WHERE id = ?");
                            $stmt->execute([$ligne['montant_prevu'], $existant['id']]);
                            $nb_copie++;
                        } else {
                            $nouvelle_ligne_id = genererUUID();
                            $stmt = $pdo->prepare("
                                INSERT INTO ligne_depense (id, categorie_id, mois_id, nom, montant_prevu)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $nouvelle_ligne_id,
                                $ligne['categorie_id'],
                                $mois_cible_id,
                                $ligne['nom'],
                                $ligne['montant_prevu']
                            ]);
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO depense (id, ligne_depense_id, mois_id, montant_prevu, priorite, commentaire)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                genererUUID(),
                                $nouvelle_ligne_id,
                                $mois_cible_id,
                                $ligne['montant_prevu'],
                                $ligne['priorite'],
                                $ligne['commentaire']
                            ]);
                            $nb_copie++;
                        }
                    }
                }
                
                // COPIER LES REVENUS FIXES
                if ($copier_revenus) {
                    $stmt = $pdo->prepare("
                        SELECT r.* FROM revenu r
                        JOIN source_revenu s ON s.id = r.source_revenu_id
                        WHERE r.mois_id = ? AND s.type = 'regulier'
                    ");
                    $stmt->execute([$source_mois_id]);
                    $revenus = $stmt->fetchAll();
                    
                    foreach ($revenus as $rv) {
                        $stmt = $pdo->prepare("
                            SELECT id FROM revenu 
                            WHERE mois_id = ? AND source_revenu_id = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$mois_cible_id, $rv['source_revenu_id']]);
                        $existant = $stmt->fetch();
                        
                        if ($existant) {
                            $stmt = $pdo->prepare("UPDATE revenu SET montant = ? WHERE id = ?");
                            $stmt->execute([$rv['montant'], $existant['id']]);
                            $nb_copie++;
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO revenu (id, source_revenu_id, mois_id, montant, date_reception, commentaire)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                genererUUID(),
                                $rv['source_revenu_id'],
                                $mois_cible_id,
                                $rv['montant'],
                                date('Y-m-d'),
                                $rv['commentaire']
                            ]);
                            $nb_copie++;
                        }
                    }
                }
                
                // COPIER LES PRIORITÉS
                if ($copier_priorites) {
                    $stmt = $pdo->prepare("
                        SELECT pourcentage_critique, pourcentage_moyen, pourcentage_leger, montant_reserve_imprevus
                        FROM mois WHERE id = ?
                    ");
                    $stmt->execute([$source_mois_id]);
                    $source = $stmt->fetch();
                    
                    if ($source) {
                        $stmt = $pdo->prepare("
                            UPDATE mois 
                            SET pourcentage_critique = ?, 
                                pourcentage_moyen = ?, 
                                pourcentage_leger = ?, 
                                montant_reserve_imprevus = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $source['pourcentage_critique'],
                            $source['pourcentage_moyen'],
                            $source['pourcentage_leger'],
                            $source['montant_reserve_imprevus'],
                            $mois_cible_id
                        ]);
                    }
                }
                
                $succes = "✅ $nb_copie éléments copiés avec succès vers " . date('F Y', strtotime($mois_cible['periode'] . '-01'));
                rediriger('historique.php');
            }
        }
    }
}

// ============================================================
// RÉCUPÉRATION DU MOIS EN COURS POUR LE SÉLECTEUR
// ============================================================
$mois_en_cours = $mois_courant;

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
    <title>Budget Manager - Historique</title>
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
        
        .mois-item {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            border: 1px solid #e2e8f0;
            margin-bottom: 12px;
            transition: all 0.2s;
        }
        .mois-item:hover { border-color: #2563eb; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .mois-item .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .mois-item .header .periode { font-size: 18px; font-weight: 700; color: #0f172a; }
        .mois-item .header .statut { font-size: 12px; font-weight: 600; padding: 2px 12px; border-radius: 12px; }
        .mois-item .header .statut.cloture { background: #fef3c7; color: #d97706; }
        .mois-item .header .statut.en-cours { background: #dcfce7; color: #16a34a; }
        .mois-item .header .statut.rouvert { background: #e0e7ff; color: #4f46e5; }
        
        .mois-item .details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
        }
        .mois-item .details .d { font-size: 13px; color: #64748b; }
        .mois-item .details .d .v { font-weight: 600; color: #0f172a; }
        .mois-item .details .d .v.green { color: #22c55e; }
        .mois-item .details .d .v.blue { color: #2563eb; }
        .mois-item .details .d .v.orange { color: #eab308; }
        .mois-item .details .d .v.red { color: #ef4444; }
        
        .mois-item .actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
            flex-wrap: wrap;
        }
        .mois-item .actions .btn-sm { padding: 4px 14px; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; font-weight: 600; }
        .mois-item .actions .btn-sm-primary { background: #2563eb; color: white; }
        .mois-item .actions .btn-sm-primary:hover { background: #1d4ed8; }
        .mois-item .actions .btn-sm-success { background: #22c55e; color: white; }
        .mois-item .actions .btn-sm-success:hover { background: #16a34a; }
        .mois-item .actions .btn-sm-secondary { background: #f1f5f9; color: #0f172a; }
        .mois-item .actions .btn-sm-secondary:hover { background: #e2e8f0; }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        .empty-state i { font-size: 48px; display: block; margin-bottom: 16px; opacity: 0.5; }
        .empty-state h4 { font-size: 18px; color: #0f172a; margin-bottom: 8px; }
        
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
            max-width: 500px;
            width: 100%;
            animation: modalIn 0.3s ease;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-box .modal-icon { text-align: center; font-size: 48px; color: #2563eb; margin-bottom: 12px; }
        .modal-box h3 { text-align: center; font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .modal-box p { text-align: center; font-size: 14px; color: #475569; margin-bottom: 4px; }
        .modal-box .sub-text { font-size: 13px; color: #94a3b8; margin-bottom: 20px; }
        .modal-box .form-group { margin-bottom: 16px; }
        .modal-box .form-group label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; }
        .modal-box .form-group input, .modal-box .form-group select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: 'Inter', sans-serif; transition: border-color 0.2s; }
        .modal-box .form-group input:focus, .modal-box .form-group select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        .modal-box .checkbox-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 4px; }
        .modal-box .checkbox-grid label { font-size: 14px; color: #0f172a; cursor: pointer; display: flex; align-items: center; gap: 6px; }
        .modal-box .radio-grid { display: flex; gap: 16px; margin-top: 4px; }
        .modal-box .radio-grid label { font-size: 14px; color: #0f172a; cursor: pointer; display: flex; align-items: center; gap: 6px; }
        .modal-box .modal-actions { display: flex; gap: 12px; justify-content: center; margin-top: 20px; }
        .modal-box .modal-actions button { padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .modal-box .modal-actions .btn-cancel { background: #f1f5f9; color: #0f172a; }
        .modal-box .modal-actions .btn-cancel:hover { background: #e2e8f0; }
        .modal-box .modal-actions .btn-confirm { background: #2563eb; color: white; }
        .modal-box .modal-actions .btn-confirm:hover { background: #1d4ed8; transform: scale(1.02); }
        
        @media (max-width: 768px) {
            .app-header .top-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .app-header .user-info { justify-content: space-between; }
            .app-nav { gap: 2px; justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .app-nav a { padding: 6px 12px; min-width: 44px; }
            .app-nav a i { font-size: 16px; }
            .app-nav a .nav-label { font-size: 8px; }
            .mois-item .details { grid-template-columns: 1fr 1fr; }
            .checkbox-grid { grid-template-columns: 1fr; }
            .radio-grid { flex-direction: column; }
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

body.theme-sombre .message.info a {
    color: #60a5fa;
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

body.theme-sombre .mois-item {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .mois-item .header .periode {
    color: #f1f5f9;
}

body.theme-sombre .mois-item .header .statut.cloture {
    background: rgba(217, 119, 6, 0.12);
    color: #fbbf24;
}

body.theme-sombre .mois-item .header .statut.en-cours {
    background: rgba(34, 197, 94, 0.15);
    color: #4ade80;
}

body.theme-sombre .mois-item .header .statut.rouvert {
    background: rgba(79, 70, 229, 0.18);
    color: #818cf8;
}

body.theme-sombre .mois-item .details {
    border-top: 1px solid #334155;
}

body.theme-sombre .mois-item .details .d {
    color: #94a3b8;
}

body.theme-sombre .mois-item .details .d .v {
    color: #f1f5f9;
}

body.theme-sombre .mois-item .details .d .v.green {
    color: #4ade80;
}

body.theme-sombre .mois-item .details .d .v.blue {
    color: #60a5fa;
}

body.theme-sombre .mois-item .details .d .v.orange {
    color: #fbbf24;
}

body.theme-sombre .mois-item .details .d .v.red {
    color: #f87171;
}

body.theme-sombre .mois-item .actions {
    border-top: 1px solid #334155;
}

body.theme-sombre .mois-item .actions .btn-sm-secondary {
    background: #334155;
    color: #f1f5f9;
}

body.theme-sombre .empty-state h4 {
    color: #f1f5f9;
}

body.theme-sombre .modal-box {
    background: #1e293b;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7);
}

body.theme-sombre .modal-box .modal-icon {
    color: #60a5fa;
}

body.theme-sombre .modal-box h3 {
    color: #f1f5f9;
}

body.theme-sombre .modal-box p {
    color: #cbd5e1;
}

body.theme-sombre .modal-box .form-group label {
    color: #e2e8f0;
}

body.theme-sombre .modal-box .form-group input, body.theme-sombre .modal-box .form-group select {
    border: 2px solid #334155;
}

body.theme-sombre .modal-box .checkbox-grid label {
    color: #f1f5f9;
}

body.theme-sombre .modal-box .radio-grid label {
    color: #f1f5f9;
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
        <h2><i class="fas fa-history"></i> Historique</h2>
        <span style="font-size:14px; color:#64748b;">
            <?= count($mois_liste) ?> mois enregistrés
        </span>
    </div>
    
    <!-- ===== MESSAGES ===== -->
    <?php if (!empty($erreur)): ?>
        <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= $erreur ?></div>
    <?php endif; ?>
    <?php if (!empty($succes)): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= $succes ?></div>
    <?php endif; ?>
    <?php if (!empty($info)): ?>
        <div class="message info"><i class="fas fa-info-circle"></i> <?= $info ?></div>
    <?php endif; ?>
    
    <!-- ============================================================
         LISTE DES MOIS
         ============================================================ -->
    <?php if (count($mois_liste) > 0): ?>
        <?php foreach ($mois_liste as $m): 
            $epargne = $m['budget_total'] - $m['depenses_reelles'] - $m['imprevus_utilises'];
        ?>
            <div class="mois-item">
                <div class="header">
                    <span class="periode"><?= afficher(date('F Y', strtotime($m['periode'] . '-01'))) ?></span>
                    <span class="statut <?= $m['statut'] ?>">
                        <?= $m['statut'] === 'cloture' ? '🔒 Clôturé' : ($m['statut'] === 'rouvert' ? '🔄 Rouvert' : '📌 En cours') ?>
                    </span>
                </div>
                
                <div class="details">
                    <div class="d">💰 Budget <span class="v blue"><?= formatFCFA($m['budget_total']) ?></span></div>
                    <div class="d">📊 Dépenses <span class="v orange"><?= formatFCFA($m['depenses_reelles']) ?></span></div>
                    <div class="d">⚠️ Imprévus <span class="v red"><?= formatFCFA($m['imprevus_utilises']) ?></span></div>
                    <div class="d">🏦 Épargne <span class="v <?= $epargne >= 0 ? 'green' : 'red' ?>"><?= formatFCFA($epargne) ?></span></div>
                </div>
                
                <div class="actions">
                    <button class="btn-sm btn-sm-primary" onclick="window.location.href='dashboard.php?mois=<?= $m['id'] ?>'">
                        <i class="fas fa-eye"></i> Voir
                    </button>
                    
                    <?php if ($m['statut'] === 'cloture' && $mois_courant && $mois_courant['statut'] === 'en_cours'): ?>
                        <button class="btn-sm btn-sm-success" onclick="ouvrirModalReutiliser('<?= $m['id'] ?>', '<?= afficher(date('F Y', strtotime($m['periode'] . '-01'))) ?>')">
                            <i class="fas fa-copy"></i> Réutiliser
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h4>Aucun mois enregistré</h4>
            <p>Commencez par créer votre premier mois dans la section Budget.</p>
            <a href="budget.php" class="btn-sm btn-sm-primary" style="display:inline-block; margin-top:12px; padding:8px 20px; text-decoration:none; border-radius:8px;">
                Créer mon premier mois
            </a>
        </div>
    <?php endif; ?>
    
    <!-- ============================================================
         MODALE RÉUTILISATION
         ============================================================ -->
    <div class="modal-overlay" id="modalReutiliser">
        <div class="modal-box">
            <div class="modal-icon"><i class="fas fa-copy"></i></div>
            <h3>🔄 Réutiliser un mois</h3>
            <p style="font-size:14px; color:#475569; margin-bottom:4px;">
                Copier les données du mois <strong id="sourceMoisLabel"></strong> vers :
            </p>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="reutiliser">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="source_mois_id" id="source_mois_id">
                
                <div class="form-group">
                    <label for="mois_cible">📅 Mois cible</label>
                    <select name="mois_cible" id="mois_cible" required>
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($mois_liste as $m): ?>
                            <?php if ($m['statut'] === 'en_cours'): ?>
                                <option value="<?= $m['id'] ?>"><?= afficher(date('F Y', strtotime($m['periode'] . '-01'))) ?> (en cours)</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>📌 Éléments à copier</label>
                    <div class="checkbox-grid">
                        <label><input type="checkbox" name="copier_depenses" value="1" checked> Dépenses</label>
                        <label><input type="checkbox" name="copier_revenus" value="1"> Revenus fixes</label>
                        <label><input type="checkbox" name="copier_priorites" value="1"> Priorités</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>📌 Mode de copie</label>
                    <div class="radio-grid">
                        <label><input type="radio" name="mode_copie" value="ajouter" checked> Ajouter</label>
                        <label><input type="radio" name="mode_copie" value="remplacer"> Remplacer</label>
                    </div>
                    <p style="font-size:12px; color:#94a3b8; margin-top:4px;">
                        💡 <strong>Ajouter</strong> : conserve les données existantes + ajoute les nouvelles<br>
                        💡 <strong>Remplacer</strong> : supprime les données existantes + copie les nouvelles
                    </p>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="fermerModalReutiliser()">
                        <i class="fas fa-times"></i> Annuler
                    </button>
                    <button type="submit" class="btn-confirm">
                        <i class="fas fa-copy"></i> Copier
                    </button>
                </div>
            </form>
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
function ouvrirModalReutiliser(id, label) {
    document.getElementById('source_mois_id').value = id;
    document.getElementById('sourceMoisLabel').textContent = label;
    document.getElementById('modalReutiliser').classList.add('active');
}

function fermerModalReutiliser() {
    document.getElementById('modalReutiliser').classList.remove('active');
}

document.getElementById('modalReutiliser').addEventListener('click', function(e) {
    if (e.target === this) fermerModalReutiliser();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fermerModalReutiliser();
});
</script>

<script src="js/app.js"></script>
</body>
</html>