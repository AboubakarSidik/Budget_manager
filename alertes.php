<?php
// ============================================================
// ALERTES - GESTION DES NOTIFICATIONS ET SUGGESTIONS
// ============================================================

require_once 'session_init.php';
require_once 'config.php';
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
// LISTES DE MESSAGES VARIÉS (pour les suggestions)
// ============================================================

$messages_suggestion = [
    'Dépassement récurrent', 'Dépassement persistant', 'Excès réguliers',
    'Dépassement répété', 'Dépassement systématique', 'Dépassement chronique',
    'Excès constatés à plusieurs reprises', 'Dépassement régulier',
    'Dépassement récurrent constaté', 'Excès répétés', 'Dépassement habituel',
    'Dépassement fréquent', 'Excès structurels', 'Dépassement cyclique',
    'Dépassement récurrent sur cette catégorie', 'Dépassement persistant depuis plusieurs mois',
    'Excès réguliers sur cette catégorie', 'Dépassement habituel constaté',
    'Dépassement récurrent sur plusieurs mois', 'Dépassement systématique constaté'
];

$messages_bravo = [
    'Bonne gestion !', 'Excellent contrôle !', 'Maîtrise parfaite !',
    'Bravo ! Continue comme ça !', 'Gestion exemplaire !',
    'Vous gérez parfaitement votre budget !', 'Très bonne gestion !',
    'Parfait ! Vous êtes sur la bonne voie !', 'Excellent travail !',
    'Gestion remarquable !', 'Bravo pour votre discipline !',
    'Vous êtes un as du budget !', 'Continuez sur cette lancée !',
    'Impeccable !', 'Vous maîtrisez vos dépenses !', 'Gestion irréprochable !',
    'Super ! Vous gérez comme un pro !', 'Bravo pour votre rigueur !',
    'Vous êtes sur la bonne voie !', 'Gestion exemplaire, continuez !'
];

// ============================================================
// GÉNÉRATION AUTOMATIQUE DES ALERTES
// ============================================================

// 1. Suggestions - Dépassement répété (3 mois)
$stmt = $pdo->prepare("
    SELECT c.id, c.nom, c.montant_plafond,
           COUNT(DISTINCT m.periode) as nb_mois,
           COALESCE(SUM(d.montant_reel), 0) as total_reel
    FROM categorie c
    LEFT JOIN ligne_depense ld ON ld.categorie_id = c.id
    LEFT JOIN depense d ON d.ligne_depense_id = ld.id
    LEFT JOIN mois m ON m.id = d.mois_id
    WHERE c.compte_id = ? AND m.periode >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
    GROUP BY c.id
    HAVING total_reel > c.montant_plafond AND nb_mois >= 2 AND c.montant_plafond > 0
");
$stmt->execute([$compte_id]);
$suggestions = $stmt->fetchAll();

foreach ($suggestions as $sug) {
    $message = $messages_suggestion[array_rand($messages_suggestion)] . ' : ' . $sug['nom'] . ' (dépassé ' . $sug['nb_mois'] . ' mois consécutifs)';
    $stmt = $pdo->prepare("
        INSERT INTO notification (id, compte_id, type, priorite, message, date, etat, categorie_id)
        VALUES (?, ?, 'depassement', 'suggestion', ?, NOW(), 'non_lue', ?)
    ");
    $stmt->execute([genererUUID(), $compte_id, $message, $sug['id']]);
}

// ============================================================
// RÉCUPÉRATION DES NOTIFICATIONS
// ============================================================
$filtre = isset($_GET['filtre']) ? $_GET['filtre'] : 'toutes';
$filtres_autorises = ['toutes', 'critique', 'attention', 'information', 'suggestion'];
if (!in_array($filtre, $filtres_autorises)) {
    $filtre = 'toutes';
}

$requete = "
    SELECT n.*, c.nom as categorie_nom, o.nom as objectif_nom
    FROM notification n
    LEFT JOIN categorie c ON c.id = n.categorie_id
    LEFT JOIN objectif o ON o.id = n.objectif_id
    WHERE n.compte_id = ?
";

if ($filtre !== 'toutes') {
    $requete .= " AND n.priorite = ?";
}

$requete .= " ORDER BY n.date DESC";

$stmt = $pdo->prepare($requete);
if ($filtre !== 'toutes') {
    $stmt->execute([$compte_id, $filtre]);
} else {
    $stmt->execute([$compte_id]);
}
$notifications = $stmt->fetchAll();

// Compter les non lues
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE compte_id = ? AND etat = 'non_lue'");
$stmt->execute([$compte_id]);
$nb_non_lues = $stmt->fetchColumn();

// Compter par priorité
$stmt = $pdo->prepare("SELECT priorite, COUNT(*) as total FROM notification WHERE compte_id = ? AND etat = 'non_lue' GROUP BY priorite");
$stmt->execute([$compte_id]);
$compteurs_priorite = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ============================================================
// TRAITEMENT : MARQUER COMME LUE
// ============================================================
if (isset($_GET['lire'])) {
    $notification_id = $_GET['lire'];
    $stmt = $pdo->prepare("UPDATE notification SET etat = 'lue' WHERE id = ? AND compte_id = ?");
    $stmt->execute([$notification_id, $compte_id]);
    $succes = "✅ Notification marquée comme lue !";
    rediriger('alertes.php');
}

// ============================================================
// TRAITEMENT : SUPPRESSION D'UNE NOTIFICATION
// ============================================================
if (isset($_GET['supprimer'])) {
    // Vérification CSRF
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        rediriger('alertes.php');
    } else {
        $notification_id = $_GET['supprimer'];
        $stmt = $pdo->prepare("DELETE FROM notification WHERE id = ? AND compte_id = ?");
        $stmt->execute([$notification_id, $compte_id]);
        $succes = "✅ Notification supprimée !";
        rediriger('alertes.php');
    }
}

// ============================================================
// TRAITEMENT : SUPPRESSION DE TOUTES LES NOTIFICATIONS
// ============================================================
if (isset($_GET['supprimer_toutes'])) {
    // Vérification CSRF
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        rediriger('alertes.php');
    } else {
        $stmt = $pdo->prepare("DELETE FROM notification WHERE compte_id = ?");
        $stmt->execute([$compte_id]);
        $succes = "✅ Toutes les notifications ont été supprimées !";
        rediriger('alertes.php');
    }
}

// ============================================================
// TRAITEMENT : SUPPRESSION AUTOMATIQUE (après délai)
// ============================================================
$stmt = $pdo->prepare("
    DELETE FROM notification 
    WHERE compte_id = ? AND (
        (priorite = 'critique' AND date < DATE_SUB(NOW(), INTERVAL 45 DAY)) OR
        (priorite = 'attention' AND date < DATE_SUB(NOW(), INTERVAL 30 DAY)) OR
        (priorite = 'information' AND date < DATE_SUB(NOW(), INTERVAL 14 DAY)) OR
        (priorite = 'suggestion' AND date < DATE_SUB(NOW(), INTERVAL 45 DAY))
    )
");
$stmt->execute([$compte_id]);

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
    <title>Budget Manager - Alertes</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ===== STYLES ALERTES ===== */
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
        .app-nav a .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: white;
            font-size: 9px;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(239,68,68,0.4);
            padding: 0 4px;
        }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin: 20px 0 16px 0; flex-wrap: wrap; gap: 12px; }
        .page-header h2 { font-size: 22px; font-weight: 700; color: #0f172a; }
        .page-header h2 i { color: #2563eb; margin-right: 10px; }
        
        .message { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
        .message.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .message.success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .message.info { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        
        .filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .filters a {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            background: #f1f5f9;
            color: #64748b;
        }
        .filters a.active { background: #2563eb; color: white; }
        .filters a:hover:not(.active) { background: #e2e8f0; }
        .filters a .count {
            display: inline-block;
            background: rgba(255,255,255,0.3);
            padding: 0 6px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 4px;
        }
        .filters a.active .count { background: rgba(255,255,255,0.2); }
        
        .notification-item {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            border: 1px solid #e2e8f0;
            margin-bottom: 10px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transition: all 0.2s;
        }
        .notification-item:hover { border-color: #2563eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .notification-item.non-lue { border-left: 4px solid #2563eb; background: #f8fafc; }
        .notification-item.lue { opacity: 0.7; }
        .notification-item .icon {
            font-size: 20px;
            min-width: 32px;
            text-align: center;
            margin-top: 2px;
        }
        .notification-item .icon.critique { color: #ef4444; }
        .notification-item .icon.attention { color: #eab308; }
        .notification-item .icon.information { color: #2563eb; }
        .notification-item .icon.suggestion { color: #8b5cf6; }
        
        .notification-item .content { flex: 1; }
        .notification-item .content .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .notification-item .content .header .date { font-size: 12px; color: #94a3b8; }
        .notification-item .content .message { font-size: 14px; color: #0f172a; margin: 4px 0; }
        .notification-item .content .detail { font-size: 13px; color: #64748b; margin-top: 2px; }
        .notification-item .content .actions { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
        .notification-item .content .actions a { font-size: 12px; font-weight: 500; text-decoration: none; transition: all 0.2s; padding: 2px 10px; border-radius: 12px; }
        .notification-item .content .actions .btn-lire { color: #2563eb; background: #eff6ff; }
        .notification-item .content .actions .btn-lire:hover { background: #bfdbfe; }
        .notification-item .content .actions .btn-supprimer { color: #ef4444; background: #fef2f2; }
        .notification-item .content .actions .btn-supprimer:hover { background: #fecaca; }
        
        .badge-priorite {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-priorite.critique { background: #fef2f2; color: #dc2626; }
        .badge-priorite.attention { background: #fef3c7; color: #d97706; }
        .badge-priorite.information { background: #eff6ff; color: #2563eb; }
        .badge-priorite.suggestion { background: #f3e8ff; color: #7c3aed; }
        
        .actions-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
        }
        .actions-bar a { font-size: 14px; font-weight: 500; text-decoration: none; transition: all 0.2s; padding: 6px 16px; border-radius: 8px; }
        .actions-bar .btn-supprimer-toutes { color: #ef4444; background: #fef2f2; }
        .actions-bar .btn-supprimer-toutes:hover { background: #fecaca; }
        
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
            background: rgba(0,0,0,0.5);
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
            .filters { gap: 4px; }
            .filters a { font-size: 12px; padding: 4px 12px; }
            .notification-item { flex-direction: column; gap: 8px; }
            .modal-box { margin: 16px; padding: 24px; }
        }
        @media (max-width: 480px) { .app-container { padding: 10px 12px; } .app-header { padding: 10px 16px; } }


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

body.theme-sombre .filters a {
    background: #334155;
    color: #94a3b8;
}

body.theme-sombre .notification-item {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .notification-item.non-lue {
    background: #0f172a;
}

body.theme-sombre .notification-item .icon.critique {
    color: #f87171;
}

body.theme-sombre .notification-item .icon.attention {
    color: #fbbf24;
}

body.theme-sombre .notification-item .icon.information {
    color: #60a5fa;
}

body.theme-sombre .notification-item .icon.suggestion {
    color: #a78bfa;
}

body.theme-sombre .notification-item .content .message {
    color: #f1f5f9;
}

body.theme-sombre .notification-item .content .detail {
    color: #94a3b8;
}

body.theme-sombre .notification-item .content .actions .btn-lire {
    color: #60a5fa;
    background: rgba(37, 99, 235, 0.12);
}

body.theme-sombre .notification-item .content .actions .btn-lire:hover {
    background: rgba(37, 99, 235, 0.3);
}

body.theme-sombre .notification-item .content .actions .btn-supprimer {
    color: #f87171;
    background: rgba(239, 68, 68, 0.12);
}

body.theme-sombre .notification-item .content .actions .btn-supprimer:hover {
    background: rgba(239, 68, 68, 0.3);
}

body.theme-sombre .badge-priorite.critique {
    background: rgba(239, 68, 68, 0.12);
    color: #f87171;
}

body.theme-sombre .badge-priorite.attention {
    background: rgba(217, 119, 6, 0.12);
    color: #fbbf24;
}

body.theme-sombre .badge-priorite.information {
    background: rgba(37, 99, 235, 0.12);
    color: #60a5fa;
}

body.theme-sombre .badge-priorite.suggestion {
    background: rgba(139, 92, 246, 0.18);
}

body.theme-sombre .actions-bar {
    border-top: 1px solid #334155;
}

body.theme-sombre .actions-bar .btn-supprimer-toutes {
    color: #f87171;
    background: rgba(239, 68, 68, 0.12);
}

body.theme-sombre .actions-bar .btn-supprimer-toutes:hover {
    background: rgba(239, 68, 68, 0.3);
}

body.theme-sombre .empty-state h4 {
    color: #f1f5f9;
}

body.theme-sombre .modal-box {
    background: #1e293b;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7);
}

body.theme-sombre .modal-box .modal-icon.warning {
    color: #fbbf24;
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
        <h2><i class="fas fa-bell"></i> Alertes et notifications</h2>
        <span style="font-size:14px; color:#64748b;">
            <?= $nb_non_lues ?> non lues | <?= count($notifications) ?> total
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
         FILTRES
         ============================================================ -->
    <div class="filters">
        <a href="?filtre=toutes" class="<?= $filtre === 'toutes' ? 'active' : '' ?>">
            📋 Toutes <span class="count"><?= count($notifications) ?></span>
        </a>
        <a href="?filtre=critique" class="<?= $filtre === 'critique' ? 'active' : '' ?>">
            🔴 Critique <span class="count"><?= $compteurs_priorite['critique'] ?? 0 ?></span>
        </a>
        <a href="?filtre=attention" class="<?= $filtre === 'attention' ? 'active' : '' ?>">
            🟡 Attention <span class="count"><?= $compteurs_priorite['attention'] ?? 0 ?></span>
        </a>
        <a href="?filtre=information" class="<?= $filtre === 'information' ? 'active' : '' ?>">
            🔵 Information <span class="count"><?= $compteurs_priorite['information'] ?? 0 ?></span>
        </a>
        <a href="?filtre=suggestion" class="<?= $filtre === 'suggestion' ? 'active' : '' ?>">
            💡 Suggestions <span class="count"><?= $compteurs_priorite['suggestion'] ?? 0 ?></span>
        </a>
    </div>
    
    <!-- ============================================================
         LISTE DES NOTIFICATIONS
         ============================================================ -->
    <?php if (count($notifications) > 0): ?>
        <?php foreach ($notifications as $notif): ?>
            <div class="notification-item <?= $notif['etat'] ?>">
                <div class="icon <?= $notif['priorite'] ?>">
                    <?php if ($notif['priorite'] === 'critique'): ?>
                        <i class="fas fa-exclamation-circle"></i>
                    <?php elseif ($notif['priorite'] === 'attention'): ?>
                        <i class="fas fa-exclamation-triangle"></i>
                    <?php elseif ($notif['priorite'] === 'information'): ?>
                        <i class="fas fa-info-circle"></i>
                    <?php else: ?>
                        <i class="fas fa-lightbulb"></i>
                    <?php endif; ?>
                </div>
                <div class="content">
                    <div class="header">
                        <div>
                            <span class="badge-priorite <?= $notif['priorite'] ?>">
                                <?= ucfirst($notif['priorite']) ?>
                            </span>
                            <?php if ($notif['etat'] === 'non_lue'): ?>
                                <span style="font-size:11px; color:#2563eb; font-weight:600;">● Nouveau</span>
                            <?php endif; ?>
                        </div>
                        <span class="date"><?= date('d/m/Y H:i', strtotime($notif['date'])) ?></span>
                    </div>
                    <div class="message"><?= afficher($notif['message']) ?></div>
                    <?php if ($notif['categorie_nom']): ?>
                        <div class="detail">📂 Catégorie : <?= afficher($notif['categorie_nom']) ?></div>
                    <?php endif; ?>
                    <?php if ($notif['objectif_nom']): ?>
                        <div class="detail">🎯 Objectif : <?= afficher($notif['objectif_nom']) ?></div>
                    <?php endif; ?>
                    <div class="actions">
                        <?php if ($notif['etat'] === 'non_lue'): ?>
                            <a href="?lire=<?= $notif['id'] ?>&filtre=<?= $filtre ?>" class="btn-lire">
                                <i class="fas fa-check"></i> Marquer comme lue
                            </a>
                        <?php endif; ?>
                        <a href="#" class="btn-supprimer" onclick="ouvrirModalNotif('<?= $notif['id'] ?>', '<?= addslashes($notif['message']) ?>', '<?= urlencode($_SESSION['csrf_token']) ?>')">
                            <i class="fas fa-trash"></i> Supprimer
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- ============================================================
             ACTIONS
             ============================================================ -->
        <div class="actions-bar">
            <a href="#" class="btn-supprimer-toutes" onclick="ouvrirModalSupprimerToutes('<?= urlencode($_SESSION['csrf_token']) ?>')">
                <i class="fas fa-trash"></i> Supprimer toutes les notifications
            </a>
        </div>
        
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-bell-slash"></i>
            <h4>Aucune notification</h4>
            <p>Vous n'avez pas encore de notifications. Continuez à bien gérer votre budget !</p>
        </div>
    <?php endif; ?>
    
    <!-- ============================================================
         MODALES PERSONNALISÉES
         ============================================================ -->
    
    <!-- MODALE : Suppression d'une notification -->
    <div class="modal-overlay" id="modalNotif">
        <div class="modal-box">
            <div class="modal-icon warning"><i class="fas fa-exclamation-circle"></i></div>
            <h3>Confirmer la suppression</h3>
            <p id="modalNotifMessage">Êtes-vous sûr de vouloir supprimer cette notification ?</p>
            <p class="sub-text" id="modalNotifDetail">Cette action est irréversible.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalNotif()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienSuppressionNotif" class="btn-confirm-danger">
                    <i class="fas fa-trash"></i> Confirmer
                </a>
            </div>
        </div>
    </div>

    <!-- MODALE : Suppression de toutes les notifications -->
    <div class="modal-overlay" id="modalSupprimerToutes">
        <div class="modal-box">
            <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
            <h3 class="danger">⚠️ Suppression massive</h3>
            <p>Êtes-vous sûr de vouloir supprimer <strong>toutes</strong> les notifications ?</p>
            <p class="sub-text">Cette action est irréversible.</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="fermerModalSupprimerToutes()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <a href="#" id="lienSupprimerToutes" class="btn-confirm-danger">
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
function ouvrirModalNotif(id, message, token) {
    document.getElementById('modalNotifMessage').textContent = 'Êtes-vous sûr de vouloir supprimer cette notification ?';
    document.getElementById('modalNotifDetail').textContent = message || 'Cette action est irréversible.';
    document.getElementById('lienSuppressionNotif').href = '?supprimer=' + id + '&csrf_token=' + token + '&filtre=<?= $filtre ?>';
    document.getElementById('modalNotif').classList.add('active');
}

function fermerModalNotif() {
    document.getElementById('modalNotif').classList.remove('active');
}

function ouvrirModalSupprimerToutes(token) {
    document.getElementById('lienSupprimerToutes').href = '?supprimer_toutes=1&csrf_token=' + token + '&filtre=<?= $filtre ?>';
    document.getElementById('modalSupprimerToutes').classList.add('active');
}

function fermerModalSupprimerToutes() {
    document.getElementById('modalSupprimerToutes').classList.remove('active');
}

document.getElementById('modalNotif').addEventListener('click', function(e) {
    if (e.target === this) fermerModalNotif();
});

document.getElementById('modalSupprimerToutes').addEventListener('click', function(e) {
    if (e.target === this) fermerModalSupprimerToutes();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fermerModalNotif();
        fermerModalSupprimerToutes();
    }
});
</script>

</body>
</html>