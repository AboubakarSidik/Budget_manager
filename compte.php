<?php
// ============================================================
// MON COMPTE - PROFIL + SECURITE + GUIDE
// ============================================================

require_once 'config.php';
require_once 'session_init.php';
require_once 'functions.php';

// Si pas connecté, rediriger vers la page de connexion
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

// Récupération des infos utilisateur
$utilisateur = getUtilisateur($pdo);

// Récupération des sources de revenus
$stmt = $pdo->prepare("SELECT * FROM source_revenu WHERE compte_id = ? ORDER BY type, libelle");
$stmt->execute([$compte_id]);
$sources = $stmt->fetchAll();

// ============================================================
// TRAITEMENT : MODIFICATION PROFIL
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'profil') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $nom = nettoyer($_POST['nom'] ?? '');
        $prenom = nettoyer($_POST['prenom'] ?? '');
        $situation = $_POST['situation'] ?? 'autre';
        
        if (empty($nom) || empty($prenom)) {
            $erreur = "Le nom et le prénom sont obligatoires.";
        } elseif (!preg_match('/^[a-zA-ZÀ-ÿ\s-]+$/', $nom) || !preg_match('/^[a-zA-ZÀ-ÿ\s-]+$/', $prenom)) {
            $erreur = "Le nom et le prénom ne doivent contenir que des lettres.";
        } else {
            $stmt = $pdo->prepare("UPDATE compte SET nom = ?, prenom = ?, situation_principale = ? WHERE id = ?");
            $stmt->execute([$nom, $prenom, $situation, $compte_id]);
            $_SESSION['utilisateur_nom'] = $prenom . ' ' . $nom;
            $_SESSION['message_succes'] = "✅ Profil mis à jour avec succès !";
            rediriger('compte.php?onglet=profil');
        }
    }
}

// ============================================================
// TRAITEMENT : AJOUT SOURCE DE REVENU
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_source') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $libelle = nettoyer($_POST['libelle'] ?? '');
        $type = $_POST['type'] ?? 'regulier';
        
        if (empty($libelle)) {
            $erreur = "Le libellé de la source est obligatoire.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO source_revenu (id, compte_id, libelle, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([genererUUID(), $compte_id, $libelle, $type]);
                $_SESSION['message_succes'] = "✅ Source de revenu ajoutée avec succès !";
                rediriger('compte.php?onglet=profil');
            } catch (PDOException $e) {
                if ($e->errorInfo[1] == 1062) {
                    $erreur = "Cette source existe déjà.";
                } else {
                    $erreur = "Erreur lors de l'ajout.";
                }
            }
        }
    }
}

// ============================================================
// TRAITEMENT : SUPPRESSION SOURCE
// ============================================================
if (isset($_GET['supprimer_source'])) {
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        rediriger('compte.php?onglet=profil');
    } else {
        $source_id = $_GET['supprimer_source'];
        
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $source_id)) {
            $_SESSION['message_info'] = "⚠️ Identifiant invalide.";
            rediriger('compte.php?onglet=profil');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM source_revenu WHERE id = ? AND compte_id = ?");
        $stmt->execute([$source_id, $compte_id]);
        $source = $stmt->fetch();
        
        if ($source) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM revenu WHERE source_revenu_id = ?");
            $stmt->execute([$source_id]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['message_info'] = "Cette source a des revenus associés. Vous ne pouvez pas la supprimer.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM source_revenu WHERE id = ? AND compte_id = ?");
                $stmt->execute([$source_id, $compte_id]);
                $_SESSION['message_succes'] = "✅ Source supprimée avec succès !";
            }
        }
        rediriger('compte.php?onglet=profil');
    }
}

// ============================================================
// TRAITEMENT : CHANGEMENT MOT DE PASSE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'securite') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $ancien = $_POST['ancien_mot_de_passe'] ?? '';
        $nouveau = $_POST['nouveau_mot_de_passe'] ?? '';
        $confirmation = $_POST['confirmation'] ?? '';
        
        if (empty($ancien) || empty($nouveau) || empty($confirmation)) {
            $erreur = "Tous les champs sont obligatoires.";
        } elseif (!password_verify($ancien, $utilisateur['mot_de_passe_hash'])) {
            $erreur = "L'ancien mot de passe est incorrect.";
        } elseif ($nouveau === $ancien) {
            $erreur = "Le nouveau mot de passe doit être différent de l'ancien.";
        } elseif (strlen($nouveau) < 8) {
            $erreur = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
        } elseif (!preg_match('/[A-Z]/', $nouveau)) {
            $erreur = "Le mot de passe doit contenir une majuscule.";
        } elseif (!preg_match('/[a-z]/', $nouveau)) {
            $erreur = "Le mot de passe doit contenir une minuscule.";
        } elseif (!preg_match('/[0-9]/', $nouveau)) {
            $erreur = "Le mot de passe doit contenir un chiffre.";
        } elseif (!preg_match('/[^a-zA-Z0-9]/', $nouveau)) {
            $erreur = "Le mot de passe doit contenir un caractère spécial.";
        } elseif ($nouveau !== $confirmation) {
            $erreur = "Les nouveaux mots de passe ne correspondent pas.";
        } else {
            $hash = password_hash($nouveau, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE compte SET mot_de_passe_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $compte_id]);
            
            // Envoyer un email de confirmation
            if (!DEBUG_MODE) {
                emailConfirmationChangement($utilisateur['email'], $utilisateur['prenom']);
            }
            
            $_SESSION['message_succes'] = "✅ Mot de passe changé avec succès ! Un email de confirmation vous a été envoyé.";
            rediriger('compte.php?onglet=securite');
        }
    }
}

// ============================================================
// TRAITEMENT : PRÉFÉRENCES
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preferences') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $theme = $_POST['theme'] ?? 'clair';
        $inactivite = intval($_POST['inactivite'] ?? 7);
        
        if ($inactivite < 0) {
            $erreur = "L'alerte inactivité ne peut pas être négative.";
        } else {
            $stmt = $pdo->prepare("UPDATE compte SET theme = ?, inactivite_alerte = ? WHERE id = ?");
            $stmt->execute([$theme, $inactivite, $compte_id]);
            
            $_SESSION['message_succes'] = "✅ Préférences mises à jour !";
            rediriger('compte.php?onglet=preferences');
        }
    }
}

// Déterminer l'onglet actif
$onglet_actif = isset($_GET['onglet']) ? $_GET['onglet'] : 'profil';

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
    <title>Budget Manager - Mon compte</title>
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
        
        .tabs {
            display: flex;
            gap: 4px;
            background: #f1f5f9;
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 24px;
        }
        .tabs a {
            flex: 1;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            color: #64748b;
            font-family: 'Inter', sans-serif;
        }
        .tabs a.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .tabs a:hover:not(.active) { color: #0f172a; }
        .tabs a i { margin-right: 6px; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .card { background: white; border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
        .card h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin-bottom: 16px; }
        .card h3 i { color: #2563eb; margin-right: 8px; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: 'Inter', sans-serif; transition: border-color 0.2s; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        
        .form-group .input-wrapper {
            position: relative;
        }
        .form-group .input-wrapper input {
            padding-right: 44px;
        }
        .form-group .input-wrapper .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 18px;
            padding: 4px 8px;
            transition: color 0.2s;
        }
        .form-group .input-wrapper .toggle-password:hover {
            color: #475569;
        }
        
        .btn-primary { padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary:hover { background: #1d4ed8; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(37,99,235,0.3); }
        .btn-secondary { padding: 10px 24px; background: #f1f5f9; color: #0f172a; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger { padding: 10px 24px; background: #ef4444; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-danger:hover { background: #dc2626; }
        
        .source-list { list-style: none; padding: 0; }
        .source-list li { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .source-list li:last-child { border-bottom: none; }
        .source-list .source-tag { font-size: 11px; font-weight: 600; padding: 2px 10px; border-radius: 12px; }
        .source-list .source-tag.regulier { background: #dcfce7; color: #16a34a; }
        .source-list .source-tag.variable { background: #fef3c7; color: #d97706; }
        .source-list .source-tag.ponctuel { background: #e0e7ff; color: #4f46e5; }
        .source-list .actions { display: flex; gap: 8px; }
        .source-list .actions a { color: #94a3b8; transition: color 0.2s; text-decoration: none; padding: 4px 8px; border-radius: 6px; }
        .source-list .actions a:hover { background: #f1f5f9; color: #2563eb; }
        .source-list .actions a.delete:hover { background: #fef2f2; color: #ef4444; }
        
        .danger-zone { border: 2px solid #fecaca; background: #fef2f2; }
        .danger-zone h3 { color: #dc2626; }
        
        .inline-form { display: flex; gap: 8px; flex-wrap: wrap; }
        .inline-form input { flex: 1; min-width: 150px; }
        .inline-form select { min-width: 120px; }
        
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
        .modal-box .modal-icon { text-align: center; font-size: 48px; color: #eab308; margin-bottom: 12px; }
        .modal-box h3 { text-align: center; font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .modal-box p { text-align: center; font-size: 14px; color: #475569; margin-bottom: 4px; }
        .modal-box .sub-text { font-size: 13px; color: #94a3b8; margin-bottom: 20px; }
        .modal-box .modal-actions { display: flex; gap: 12px; justify-content: center; }
        .modal-box .modal-actions button { padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .modal-box .modal-actions .btn-cancel { background: #f1f5f9; color: #0f172a; }
        .modal-box .modal-actions .btn-cancel:hover { background: #e2e8f0; }
        .modal-box .modal-actions .btn-confirm { background: #ef4444; color: white; }
        .modal-box .modal-actions .btn-confirm:hover { background: #dc2626; transform: scale(1.02); }
        
        .btn-supprimer-compte {
            display: inline-block;
            padding: 10px 24px;
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-supprimer-compte:hover { background: #b91c1c; transform: translateY(-2px); }
        
        /* --- Styles pour le guide --- */
        .guide-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 14px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .guide-item:last-child { border-bottom: none; }
        .guide-item .icon-box {
            min-width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            flex-shrink: 0;
        }
        .guide-item .icon-box.blue { background: #2563eb; }
        .guide-item .icon-box.green { background: #22c55e; }
        .guide-item .icon-box.orange { background: #eab308; }
        .guide-item .icon-box.purple { background: #8b5cf6; }
        .guide-item .icon-box.red { background: #ef4444; }
        .guide-item .icon-box.teal { background: #14b8a6; }
        .guide-item .icon-box.pink { background: #ec4899; }
        .guide-item .icon-box.indigo { background: #6366f1; }
        .guide-item .icon-box.gray { background: #64748b; }
        .guide-item .content { flex: 1; }
        .guide-item .content h4 { font-size: 15px; font-weight: 600; color: #0f172a; margin: 0 0 2px 0; }
        .guide-item .content p { font-size: 13px; color: #475569; margin: 0; line-height: 1.6; }
        .guide-item .content p strong { color: #0f172a; }
        
        @media (max-width: 768px) {
            .app-header .top-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .app-header .user-info { justify-content: space-between; }
            .app-nav { gap: 2px; justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .app-nav a { padding: 6px 12px; min-width: 44px; }
            .app-nav a i { font-size: 16px; }
            .app-nav a .nav-label { font-size: 8px; }
            .tabs { flex-direction: column; }
            .inline-form { flex-direction: column; }
            .inline-form input, .inline-form select { width: 100%; }
            .modal-box { margin: 16px; padding: 24px; }
            .guide-item { flex-direction: column; align-items: center; text-align: center; }
        }
        @media (max-width: 480px) {
            .app-container { padding: 10px 12px; }
            .app-header { padding: 10px 16px; }
            .card { padding: 16px; }
        }


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

body.theme-sombre .tabs {
    background: #334155;
}

body.theme-sombre .tabs a {
    color: #94a3b8;
}

body.theme-sombre .tabs a.active {
    background: #1e293b;
    color: #f1f5f9;
}

body.theme-sombre .tabs a:hover:not(.active) {
    color: #f1f5f9;
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

body.theme-sombre .form-group .input-wrapper .toggle-password:hover {
    color: #cbd5e1;
}

body.theme-sombre .btn-secondary {
    background: #334155;
    color: #f1f5f9;
    border: 1px solid #334155;
}

body.theme-sombre .source-list li {
    border-bottom: 1px solid #334155;
}

body.theme-sombre .source-list .source-tag.regulier {
    background: rgba(34, 197, 94, 0.15);
    color: #4ade80;
}

body.theme-sombre .source-list .source-tag.variable {
    background: rgba(217, 119, 6, 0.12);
    color: #fbbf24;
}

body.theme-sombre .source-list .source-tag.ponctuel {
    background: rgba(79, 70, 229, 0.18);
    color: #818cf8;
}

body.theme-sombre .source-list .actions a:hover {
    background: #334155;
    color: #60a5fa;
}

body.theme-sombre .source-list .actions a.delete:hover {
    background: rgba(239, 68, 68, 0.12);
    color: #f87171;
}

body.theme-sombre .danger-zone {
    border: 2px solid rgba(239, 68, 68, 0.3);
    background: rgba(239, 68, 68, 0.12);
}

body.theme-sombre .danger-zone h3 {
    color: #f87171;
}

body.theme-sombre .modal-box {
    background: #1e293b;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7);
}

body.theme-sombre .modal-box .modal-icon {
    color: #fbbf24;
}

body.theme-sombre .modal-box h3 {
    color: #f1f5f9;
}

body.theme-sombre .modal-box p {
    color: #cbd5e1;
}

body.theme-sombre .modal-box .modal-actions .btn-cancel {
    background: #334155;
    color: #f1f5f9;
}

body.theme-sombre /* --- Styles pour le guide --- */
        .guide-item {
    border-bottom: 1px solid #334155;
}

body.theme-sombre .guide-item .content h4 {
    color: #f1f5f9;
}

body.theme-sombre .guide-item .content p {
    color: #cbd5e1;
}

body.theme-sombre .guide-item .content p strong {
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
        <h2><i class="fas fa-cog"></i> Mon compte</h2>
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
         TABS
         ============================================================ -->
    <div class="tabs">
        <a href="?onglet=profil" class="<?= $onglet_actif === 'profil' ? 'active' : '' ?>">
            <i class="fas fa-user"></i> Profil
        </a>
        <a href="?onglet=securite" class="<?= $onglet_actif === 'securite' ? 'active' : '' ?>">
            <i class="fas fa-lock"></i> Sécurité
        </a>
        <a href="?onglet=guide" class="<?= $onglet_actif === 'guide' ? 'active' : '' ?>">
            <i class="fas fa-book"></i> Guide
        </a>
    </div>
    
    <!-- ============================================================
         TAB : PROFIL
         ============================================================ -->
    <div class="tab-content <?= $onglet_actif === 'profil' ? 'active' : '' ?>" id="tab-profil">
        
        <!-- === Informations personnelles === -->
        <div class="card">
            <h3><i class="fas fa-id-card"></i> Informations personnelles</h3>
            <form method="POST" action="?onglet=profil">
                <input type="hidden" name="action" value="profil">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="form-group">
                    <label for="nom">Nom</label>
                    <input type="text" id="nom" name="nom" value="<?= afficher($utilisateur['nom']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="prenom">Prénom</label>
                    <input type="text" id="prenom" name="prenom" value="<?= afficher($utilisateur['prenom']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" value="<?= afficher($utilisateur['email']) ?>" disabled style="background:#f1f5f9;">
                    <p style="font-size:12px; color:#94a3b8; margin-top:4px;">L'email ne peut pas être modifié pour l'instant.</p>
                </div>
                
                <div class="form-group">
                    <label for="situation">Situation principale</label>
                    <select name="situation" id="situation">
                        <option value="salarie" <?= $utilisateur['situation_principale'] === 'salarie' ? 'selected' : '' ?>>Salarié dans une entreprise</option>
                        <option value="etudiant" <?= $utilisateur['situation_principale'] === 'etudiant' ? 'selected' : '' ?>>Étudiant</option>
                        <option value="freelance" <?= $utilisateur['situation_principale'] === 'freelance' ? 'selected' : '' ?>>Freelance / Indépendant</option>
                        <option value="commercant" <?= $utilisateur['situation_principale'] === 'commercant' ? 'selected' : '' ?>>Commerçant</option>
                        <option value="sans_emploi" <?= $utilisateur['situation_principale'] === 'sans_emploi' ? 'selected' : '' ?>>Sans emploi</option>
                        <option value="retraite" <?= $utilisateur['situation_principale'] === 'retraite' ? 'selected' : '' ?>>Retraité</option>
                        <option value="autre" <?= $utilisateur['situation_principale'] === 'autre' ? 'selected' : '' ?>>Autre</option>
                    </select>
                </div>
                
                <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </form>
        </div>
        
        <!-- === Sources de revenus === -->
        <div class="card">
            <h3><i class="fas fa-coins"></i> Sources de revenus</h3>
            
            <?php if (count($sources) > 0): ?>
                <ul class="source-list">
                    <?php foreach ($sources as $s): ?>
                        <li>
                            <span>
                                <?= afficher($s['libelle']) ?>
                                <span class="source-tag <?= $s['type'] ?>"><?= ucfirst($s['type']) ?></span>
                            </span>
                            <div class="actions">
                                <a href="#" class="delete" 
                                   onclick="ouvrirModalSource('<?= $s['id'] ?>', '<?= afficher($s['libelle']) ?>', '<?= $s['type'] ?>', '<?= urlencode($_SESSION['csrf_token']) ?>')"
                                   title="Supprimer">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p style="color:#94a3b8; padding:10px 0;">Aucune source de revenu. Ajoutez-en une ci-dessous.</p>
            <?php endif; ?>
            
            <hr style="margin: 16px 0; border: none; border-top: 1px solid #e2e8f0;">
            
            <form method="POST" action="?onglet=profil" class="inline-form">
                <input type="hidden" name="action" value="ajouter_source">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <input type="text" name="libelle" placeholder="Ex: Loyer Appartement A" required>
                <select name="type">
                    <option value="regulier">Régulier</option>
                    <option value="variable">Variable</option>
                    <option value="ponctuel">Ponctuel</option>
                </select>
                <button type="submit" class="btn-primary" style="padding:10px 20px;">
                    <i class="fas fa-plus"></i> Ajouter
                </button>
            </form>
            <p style="font-size:12px; color:#94a3b8; margin-top:8px;">
                <i class="fas fa-info-circle"></i> 
                <strong>Régulier</strong> : Reçu chaque mois (salaire, loyer) &nbsp;|&nbsp;
                <strong>Variable</strong> : Montant différent chaque mois (commerce) &nbsp;|&nbsp;
                <strong>Ponctuel</strong> : Exceptionnel (prime, cadeau)
            </p>
        </div>
        
        <!-- === Zone dangereuse === -->
        <div class="card danger-zone">
            <h3><i class="fas fa-exclamation-triangle"></i> Zone dangereuse</h3>
            <p style="color:#64748b; font-size:14px; margin-bottom:12px;">
                La suppression de votre compte est définitive. Toutes vos données seront perdues.
            </p>
            <a href="supprimer_compte.php" class="btn-supprimer-compte">
                <i class="fas fa-trash"></i> Supprimer mon compte
            </a>
        </div>
    </div>
    
    <!-- ============================================================
         TAB : SÉCURITÉ
         ============================================================ -->
    <div class="tab-content <?= $onglet_actif === 'securite' ? 'active' : '' ?>" id="tab-securite">
        <div class="card">
            <h3><i class="fas fa-key"></i> Changer le mot de passe</h3>
            <form method="POST" action="?onglet=securite">
                <input type="hidden" name="action" value="securite">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="form-group">
                    <label for="ancien">Ancien mot de passe</label>
                    <div class="input-wrapper">
                        <input type="password" id="ancien" name="ancien_mot_de_passe" required>
                        <button type="button" class="toggle-password" onclick="togglePassword('ancien')">
                            <i class="fas fa-eye" id="ancien-icon"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="nouveau">Nouveau mot de passe</label>
                    <div class="input-wrapper">
                        <input type="password" id="nouveau" name="nouveau_mot_de_passe" required>
                        <button type="button" class="toggle-password" onclick="togglePassword('nouveau')">
                            <i class="fas fa-eye" id="nouveau-icon"></i>
                        </button>
                    </div>
                    <p style="font-size:12px; color:#94a3b8; margin-top:4px;">
                        8+ caractères, une majuscule, une minuscule, un chiffre, un caractère spécial
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="confirmation">Confirmer le nouveau mot de passe</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirmation" name="confirmation" required>
                        <button type="button" class="toggle-password" onclick="togglePassword('confirmation')">
                            <i class="fas fa-eye" id="confirmation-icon"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Changer le mot de passe</button>
            </form>
        </div>
    </div>
    
    <!-- ============================================================
         TAB : GUIDE D'UTILISATION
         ============================================================ -->
    <div class="tab-content <?= $onglet_actif === 'guide' ? 'active' : '' ?>" id="tab-guide">
        <div class="card">
            <h3><i class="fas fa-book" style="color:#2563eb;"></i> Guide d'utilisation</h3>
            <p style="color:#64748b; font-size:14px; margin-bottom:20px;">
                Bienvenue dans Budget Manager ! Voici un tour d'horizon complet de toutes les fonctionnalités pour vous aider à gérer vos finances efficacement.
            </p>

            <div style="background:#f8fafc; border-radius:12px; padding:20px; margin-bottom:20px; border-left:4px solid #2563eb;">
                <h4 style="font-size:15px; font-weight:600; color:#0f172a; margin-bottom:4px;">📌 Démarrage rapide</h4>
                <p style="font-size:13px; color:#475569; margin:0; line-height:1.6;">
                    Pour commencer à utiliser Budget Manager, suivez ces étapes simples :
                    <br><br>
                    1️⃣ <strong>Ajoutez vos sources de revenus</strong> (Mon compte → Profil → Sources de revenus)<br>
                    2️⃣ <strong>Enregistrez vos revenus</strong> pour le mois en cours (Revenus)<br>
                    3️⃣ <strong>Définissez vos dépenses prévues</strong> (Dépenses)<br>
                    4️⃣ <strong>Configurez votre budget</strong> (Budget : priorisez vos dépenses et définissez votre réserve d'imprévus)<br>
                    5️⃣ <strong>Suivez votre progression</strong> via le Dashboard et les Statistiques
                </p>
            </div>

            <!-- 1. DASHBOARD -->
            <div class="guide-item">
                <div class="icon-box blue"><i class="fas fa-tachometer-alt"></i></div>
                <div class="content">
                    <h4>📊 Dashboard</h4>
                    <p>
                        <strong>Vue d'ensemble de vos finances :</strong> Le Dashboard est votre page d'accueil après connexion. Il vous donne un aperçu instantané de votre situation financière.
                        <br><br>
                        <strong>Ce que vous y trouverez :</strong>
                        <br>• <strong>Budget total</strong> : Somme de tous vos revenus du mois
                        <br>• <strong>Dépenses</strong> : Total de vos dépenses réelles
                        <br>• <strong>Épargne</strong> = Budget total - Dépenses - Imprévus utilisés
                        <br>• <strong>Taux d'épargne</strong> : Pourcentage de votre budget que vous épargnez
                        <br>• <strong>État du mois</strong> : En cours ou clôturé
                        <br>• <strong>Actions rapides</strong> : Ajouter un revenu, une dépense, ou un objectif
                    </p>
                </div>
            </div>

            <!-- 2. REVENUS -->
            <div class="guide-item">
                <div class="icon-box green"><i class="fas fa-coins"></i></div>
                <div class="content">
                    <h4>💰 Revenus</h4>
                    <p>
                        <strong>Gestion de vos entrées d'argent :</strong> Cette page vous permet d'enregistrer tous vos revenus pour le mois en cours.
                        <br><br>
                        <strong>Comment ça fonctionne :</strong>
                        <br>• <strong>Ajouter un revenu</strong> : Sélectionnez une source (créée dans votre profil), entrez le montant et la date
                        <br>• <strong>Sources régulières</strong> : Revenus fixes chaque mois (salaire, loyer perçu) → automatiquement reportés au mois suivant
                        <br>• <strong>Sources variables</strong> : Revenus qui varient chaque mois (commerce, missions) → à saisir manuellement
                        <br>• <strong>Sources ponctuelles</strong> : Revenus exceptionnels (primes, cadeaux)
                        <br>• <strong>Budget total</strong> : Calculé automatiquement à partir de tous vos revenus
                        <br><br>
                        💡 <strong>Astuce :</strong> Si vous êtes commerçant, saisissez le montant que vous prélevez pour votre budget personnel, pas votre chiffre d'affaires.
                    </p>
                </div>
            </div>

            <!-- 3. DÉPENSES -->
            <div class="guide-item">
                <div class="icon-box orange"><i class="fas fa-receipt"></i></div>
                <div class="content">
                    <h4>🧾 Dépenses</h4>
                    <p>
                        <strong>Planification et suivi de vos dépenses :</strong> Cette page est le cœur de votre gestion budgétaire.
                        <br><br>
                        <strong>Deux types de dépenses :</strong>
                        <br>• <strong>Dépense prévue</strong> : Vous estimez un montant à dépenser dans une catégorie (ex: provision courses : 80 000 FCFA)
                        <br>• <strong>Dépense directe</strong> : Vous enregistrez une dépense déjà effectuée (ex: courses du 05/08 : 65 000 FCFA)
                        <br><br>
                        <strong>Les priorités :</strong>
                        <br>• <strong>Critique</strong> (🟢) : Dépenses essentielles (logement, nourriture)
                        <br>• <strong>Moyen</strong> (🟡) : Dépenses importantes mais ajustables (transport, santé)
                        <br>• <strong>Léger</strong> (🔵) : Dépenses plaisir (loisirs, sorties)
                        <br><br>
                        <strong>Statut des dépenses :</strong>
                        <br>• <strong>Prévue</strong> (⬜) : Dépense planifiée, pas encore payée
                        <br>• <strong>Effectuée</strong> (✅) : Dépense réalisée avec montant réel
                        <br><br>
                        💡 <strong>Astuce :</strong> Vous pouvez marquer une dépense prévue comme effectuée en cliquant sur ✓ et en saisissant le montant réel.
                    </p>
                </div>
            </div>

            <!-- 4. CATÉGORIES -->
            <div class="guide-item">
                <div class="icon-box purple"><i class="fas fa-tags"></i></div>
                <div class="content">
                    <h4>🏷️ Catégories</h4>
                    <p>
                        <strong>Organisation de vos dépenses :</strong> Les catégories vous permettent de classer vos dépenses et de définir des plafonds.
                        <br><br>
                        <strong>Fonctionnalités :</strong>
                        <br>• <strong>Catégories par défaut</strong> : Logement, Nourriture, Transport, Santé, Éducation, Communication, Loisirs, Dettes, et Autre
                        <br>• <strong>Ajouter une catégorie</strong> : Créez vos propres catégories (ex: Électricité, Eau, Internet)
                        <br>• <strong>Plafond</strong> : Définissez une limite de dépenses pour chaque catégorie (ex: Nourriture : 80 000 FCFA)
                        <br>• <strong>Alertes</strong> : Si vous dépassez le plafond d'une catégorie, une alerte est automatiquement générée
                        <br>• <strong>Modifier/Supprimer</strong> : Vous pouvez modifier le plafond ou supprimer une catégorie (sauf si elle a des dépenses associées)
                    </p>
                </div>
            </div>

            <!-- 5. BUDGET -->
            <div class="guide-item">
                <div class="icon-box indigo"><i class="fas fa-file-invoice"></i></div>
                <div class="content">
                    <h4>📋 Budget</h4>
                    <p>
                        <strong>Configuration de votre budget mensuel :</strong> C'est ici que vous définissez comment répartir votre budget entre les priorités.
                        <br><br>
                        <strong>Répartition par priorité :</strong>
                        <br>• <strong>Critique</strong> (🟢) : Pourcentage alloué aux dépenses essentielles (ex: 60%)
                        <br>• <strong>Moyen</strong> (🟡) : Pourcentage pour les dépenses importantes (ex: 30%)
                        <br>• <strong>Léger</strong> (🔵) : Pourcentage pour les dépenses plaisir (ex: 10%)
                        <br><br>
                        <strong>Réserve d'imprévus :</strong>
                        <br>• Définissez un montant à mettre de côté chaque mois pour les dépenses imprévues
                        <br>• Recommandation : 10-15% de votre budget total
                        <br>• Limite : 50% maximum du budget total
                        <br><br>
                        <strong>État du mois :</strong>
                        <br>• <strong>En cours</strong> : Vous pouvez modifier les données
                        <br>• <strong>Clôturé</strong> 🔒 : Le mois est verrouillé, plus aucune modification possible
                        <br><br>
                        💡 <strong>Astuce :</strong> Clôturez le mois une fois que toutes vos dépenses sont saisies pour un bilan précis.
                    </p>
                </div>
            </div>

            <!-- 6. IMPRÉVUS -->
            <div class="guide-item">
                <div class="icon-box red"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="content">
                    <h4>⚠️ Imprévus</h4>
                    <p>
                        <strong>Gestion de votre réserve et des dépenses imprévues :</strong> Cette page vous permet de suivre l'utilisation de votre réserve d'imprévus.
                        <br><br>
                        <strong>Comment ça fonctionne :</strong>
                        <br>• <strong>Réserve initiale</strong> : Définie dans le Budget (ex: 50 000 FCFA)
                        <br>• <strong>Ajouter un imprévu</strong> : Saisissez le libellé, le montant et la date (ex: Panne de moto : 25 000 FCFA)
                        <br>• <strong>État de la réserve</strong> : Visualisez en temps réel le reste de votre réserve
                        <br>• <strong>Indicateur d'état</strong> : 🟢 Confortable, 🟡 Réduite, 🔴 Épuisée
                        <br><br>
                        💡 <strong>Astuce :</strong> Si la réserve est épuisée, les imprévus seront déduits directement de votre épargne.
                    </p>
                </div>
            </div>

            <!-- 7. OBJECTIFS -->
            <div class="guide-item">
                <div class="icon-box pink"><i class="fas fa-bullseye"></i></div>
                <div class="content">
                    <h4>🎯 Objectifs</h4>
                    <p>
                        <strong>Définition et suivi de vos objectifs d'épargne :</strong> Visualisez votre progression vers vos objectifs financiers.
                        <br><br>
                        <strong>Créer un objectif :</strong>
                        <br>• <strong>Nom</strong> : Achat de voiture, Voyage, Épargne de sécurité, etc.
                        <br>• <strong>Montant cible</strong> : Objectif financier à atteindre (ex: 1 500 000 FCFA)
                        <br>• <strong>Date de fin</strong> : Date limite pour atteindre l'objectif
                        <br>• <strong>% Allocation</strong> : Pourcentage de votre épargne mensuelle alloué à cet objectif
                        <br><br>
                        <strong>Suivi :</strong>
                        <br>• <strong>Progression</strong> : Barre de progression visuelle (0% → 100%)
                        <br>• <strong>Montant collecté</strong> : Somme déjà épargnée pour cet objectif
                        <br>• <strong>Statut</strong> : En cours, À risque, Impossible, Atteint ✅
                        <br><br>
                        💡 <strong>Astuce :</strong> Le système alloue automatiquement votre épargne entre les objectifs selon les pourcentages définis. L'épargne non allouée reste libre.
                    </p>
                </div>
            </div>

            <!-- 8. ÉPARGNE -->
            <div class="guide-item">
                <div class="icon-box teal"><i class="fas fa-piggy-bank"></i></div>
                <div class="content">
                    <h4>🏦 Épargne</h4>
                    <p>
                        <strong>Calcul et visualisation de votre épargne réelle :</strong> Cette page vous montre combien vous avez réellement épargné.
                        <br><br>
                        <strong>Calcul :</strong>
                        <br>Épargne réelle = Budget total - Dépenses réelles - Imprévus utilisés
                        <br><br>
                        <strong>Ce que vous voyez :</strong>
                        <br>• <strong>Épargne réelle du mois</strong> : Montant que vous avez réellement épargné
                        <br>• <strong>Taux d'épargne</strong> : Pourcentage de votre budget que vous épargnez
                        <br>• <strong>Détail du calcul</strong> : Budget total, Dépenses réelles, Imprévus utilisés
                        <br><br>
                        💡 <strong>Astuce :</strong> Un taux d'épargne supérieur à 20% est considéré comme excellent !
                    </p>
                </div>
            </div>

            <!-- 9. STATISTIQUES -->
            <div class="guide-item">
                <div class="icon-box orange"><i class="fas fa-chart-pie"></i></div>
                <div class="content">
                    <h4>📈 Statistiques</h4>
                    <p>
                        <strong>Analyse visuelle de vos finances :</strong> Des graphiques pour comprendre où va votre argent.
                        <br><br>
                        <strong>Les graphiques disponibles :</strong>
                        <br>• <strong>Camembert Dépenses</strong> : Répartition de vos dépenses par catégorie
                        <br>• <strong>Camembert Revenus</strong> : Répartition de vos revenus par source
                        <br>• <strong>Évolution (6 mois)</strong> : Tendance de vos dépenses et de votre épargne sur les 6 derniers mois
                        <br>• <strong>Prévision vs Réel</strong> : Comparaison entre ce que vous aviez prévu et ce que vous avez réellement dépensé
                        <br><br>
                        <strong>Indicateurs clés :</strong>
                        <br>• Budget total, Dépenses, Épargne, Taux d'épargne
                        <br>• Nombre d'alertes, Objectifs atteints
                    </p>
                </div>
            </div>

            <!-- 10. ALERTES -->
            <div class="guide-item">
                <div class="icon-box red"><i class="fas fa-bell"></i></div>
                <div class="content">
                    <h4>🔔 Alertes</h4>
                    <p>
                        <strong>Notifications pour rester informé :</strong> Recevez des alertes sur votre situation financière.
                        <br><br>
                        <strong>Types d'alertes :</strong>
                        <br>• <strong>Critique</strong> 🔴 : Dépassement important, situation urgente
                        <br>• <strong>Attention</strong> 🟡 : Dépassement modéré, à surveiller
                        <br>• <strong>Information</strong> 🔵 : Information générale
                        <br>• <strong>Suggestion</strong> 💡 : Conseils pour améliorer votre gestion
                        <br><br>
                        <strong>Fonctionnalités :</strong>
                        <br>• <strong>Filtrer</strong> : Afficher uniquement les alertes d'un type spécifique
                        <br>• <strong>Marquer comme lue</strong> : Une fois l'alerte traitée
                        <br>• <strong>Supprimer</strong> : Supprimer une alerte individuelle ou toutes les alertes
                        <br><br>
                        💡 <strong>Astuce :</strong> Les alertes de dépassement sont automatiquement générées lorsque vous dépassez le plafond d'une catégorie sur plusieurs mois consécutifs.
                    </p>
                </div>
            </div>

            <!-- 11. COMPTE -->
            <div class="guide-item">
                <div class="icon-box gray"><i class="fas fa-cog"></i></div>
                <div class="content">
                    <h4>👤 Compte</h4>
                    <p>
                        <strong>Gestion de votre profil et sécurité :</strong> Cette page regroupe toutes les informations de votre compte.
                        <br><br>
                        <strong>Onglet Profil :</strong>
                        <br>• <strong>Informations personnelles</strong> : Nom, prénom, situation professionnelle
                        <br>• <strong>Sources de revenus</strong> : Ajoutez, modifiez ou supprimez vos sources de revenus
                        <br>• <strong>Zone dangereuse</strong> : Supprimer définitivement votre compte (irréversible !)
                        <br><br>
                        <strong>Onglet Sécurité :</strong>
                        <br>• <strong>Changer de mot de passe</strong> : Saisissez l'ancien, le nouveau et la confirmation
                        <br>• Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial
                        <br>• Un email de confirmation vous sera envoyé automatiquement
                        <br><br>
                        💡 <strong>Astuce :</strong> Mettez à jour régulièrement vos sources de revenus pour que le budget soit toujours précis.
                    </p>
                </div>
            </div>

            <!-- 12. HISTORIQUE -->
            <div class="guide-item">
                <div class="icon-box blue"><i class="fas fa-history"></i></div>
                <div class="content">
                    <h4>📜 Historique</h4>
                    <p>
                        <strong>Consultation des mois passés :</strong> Retrouvez tous vos mois précédents avec leurs données.
                        <br><br>
                        <strong>Ce que vous y trouverez :</strong>
                        <br>• <strong>Liste des mois</strong> : Tous les mois enregistrés, du plus récent au plus ancien
                        <br>• <strong>Résumé</strong> : Budget, Dépenses, Imprévus, Épargne pour chaque mois
                        <br>• <strong>Statut</strong> : En cours, Clôturé, Rouvert
                        <br><br>
                        <strong>Fonctionnalité "Réutiliser" :</strong>
                        <br>• Copiez les données d'un mois clôturé vers le mois en cours
                        <br>• Choisissez les éléments à copier : Dépenses, Revenus fixes, Priorités
                        <br>• Mode : Ajouter (conserve les données existantes) ou Remplacer (supprime les anciennes données)
                        <br><br>
                        💡 <strong>Astuce :</strong> Utilisez la fonction "Réutiliser" pour gagner du temps si vos dépenses sont similaires d'un mois à l'autre.
                    </p>
                </div>
            </div>

            <div style="background:#eff6ff; border-radius:12px; padding:16px 20px; margin-top:20px; border-left:4px solid #2563eb;">
                <p style="font-size:13px; color:#1e40af; margin:0;">
                    <i class="fas fa-lightbulb" style="margin-right:8px;"></i>
                    <strong>Un conseil :</strong> Prenez 5 minutes chaque jour pour saisir vos dépenses. Une gestion régulière est la clé d'un budget maîtrisé !
                </p>
            </div>
        </div>
    </div>
    
    <!-- ============================================================
         MODALE SUPPRESSION SOURCE
         ============================================================ -->
    <div class="modal-overlay" id="modalSource">
        <div class="modal-box">
            <div class="modal-icon warning"><i class="fas fa-exclamation-circle"></i></div>
            <h3>Confirmer la suppression</h3>
            <p id="modalSourceMessage">Êtes-vous sûr de vouloir supprimer cette source de revenus ?</p>
            <p class="sub-text" id="modalSourceDetail">Les revenus associés resteront en historique.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalSource()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienSuppressionSource" class="btn-confirm" style="text-decoration:none; padding:10px 28px; border-radius:10px; font-size:14px; font-weight:600; color:white; background:#ef4444; display:inline-block;">
                    <i class="fas fa-trash"></i> Confirmer
                </a>
            </div>
        </div>
    </div>
    
    <!-- ============================================================
         FOOTER
         ============================================================ -->
    <footer style="margin-top: 40px; padding: 16px 0; text-align: center; color: #94a3b8; font-size: 13px; border-top: 1px solid #e2e8f0;">
        &copy; <?= date('Y') ?> Budget Manager - Gérez votre budget personnel simplement
    </footer>
    
</div>

<script>
function ouvrirModalSource(id, libelle, type, token) {
    document.getElementById('modalSourceMessage').textContent = 'Êtes-vous sûr de vouloir supprimer la source "' + libelle + '" ?';
    document.getElementById('modalSourceDetail').textContent = 'Type : ' + type.charAt(0).toUpperCase() + type.slice(1) + ' - Les revenus associés resteront.';
    document.getElementById('lienSuppressionSource').href = '?supprimer_source=' + id + '&csrf_token=' + token + '&onglet=profil';
    document.getElementById('modalSource').classList.add('active');
}

function fermerModalSource() {
    document.getElementById('modalSource').classList.remove('active');
}

document.getElementById('modalSource').addEventListener('click', function(e) {
    if (e.target === this) fermerModalSource();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fermerModalSource();
});
</script>

<script src="js/app.js"></script>
</body>
</html>