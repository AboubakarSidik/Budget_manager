<?php
// ============================================================
// SUPPRESSION DÉFINITIVE DU COMPTE
// ============================================================

require_once 'config.php';
require_once 'session_init.php';
require_once 'functions.php';
require_once 'functions_mail.php';

if (!estConnecte()) {
    rediriger('landing.php');
}

// Générer un token CSRF si inexistant
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$compte_id = $_SESSION['utilisateur_id'];
$erreur = '';
$succes = '';

$stmt = $pdo->prepare("SELECT email, prenom FROM compte WHERE id = ?");
$stmt->execute([$compte_id]);
$user = $stmt->fetch();

if (!$user) {
    rediriger('landing.php');
}

if (isset($_GET['confirm']) && $_GET['confirm'] === '1') {
    
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        if (!DEBUG_MODE) {
            emailSuppression($user['email'], $user['prenom']);
        }
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("DELETE FROM compte WHERE id = ?");
            $stmt->execute([$compte_id]);
            $pdo->commit();
            
            $_SESSION = array();
            session_destroy();
            setcookie('budget_manager_visited', '', time() - 3600, '/');
            
            // Rediriger avec message de succès
            header('Location: landing.php?supprime=1');
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur lors de la suppression du compte.";
        }
    }
}

// Récupérer les messages de session (pour les erreurs)
if (isset($_SESSION['message_succes'])) {
    $succes = $_SESSION['message_succes'];
    unset($_SESSION['message_succes']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supprimer mon compte</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8fafc; padding: 20px; }
        .card { background: white; border-radius: 20px; padding: 40px 32px; max-width: 480px; width: 100%; border: 2px solid #fecaca; box-shadow: 0 20px 60px rgba(0,0,0,0.06); }
        .card .icon { text-align: center; font-size: 48px; color: #dc2626; margin-bottom: 12px; }
        .card h2 { font-size: 24px; font-weight: 700; color: #dc2626; text-align: center; margin-bottom: 8px; }
        .card .subtitle { font-size: 14px; color: #64748b; text-align: center; margin-bottom: 24px; }
        .card .consequences { background: #fef2f2; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; }
        .card .consequences li { list-style: none; padding: 4px 0; font-size: 14px; color: #475569; }
        .card .consequences li i { color: #dc2626; margin-right: 8px; }
        .card .warning { font-weight: 600; color: #dc2626; text-align: center; margin-bottom: 20px; }
        .btn { padding: 10px 24px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .btn-secondary { background: #f1f5f9; color: #0f172a; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(220,38,38,0.3); }
        .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .message { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
        .message.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .message.success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        
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
            border: 2px solid #fecaca;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-box .modal-icon { text-align: center; font-size: 48px; color: #dc2626; margin-bottom: 12px; }
        .modal-box h3 { text-align: center; font-size: 20px; font-weight: 700; color: #dc2626; margin-bottom: 8px; }
        .modal-box p { text-align: center; font-size: 14px; color: #475569; margin-bottom: 4px; }
        .modal-box .sub-text { font-size: 13px; color: #94a3b8; margin-bottom: 20px; }
        .modal-box .modal-actions { display: flex; gap: 12px; justify-content: center; }
        .modal-box .modal-actions .btn-cancel { background: #f1f5f9; color: #0f172a; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .modal-box .modal-actions .btn-cancel:hover { background: #e2e8f0; }
        .modal-box .modal-actions .btn-confirm-danger { background: #dc2626; color: white; padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; }
        .modal-box .modal-actions .btn-confirm-danger:hover { background: #b91c1c; transform: scale(1.02); }
        @media (max-width: 480px) { .card { padding: 24px 16px; } .modal-box { margin: 16px; padding: 24px; } }


        /* ============================================================
           MODE SOMBRE (genere automatiquement)
           ============================================================ */

body.theme-sombre {
    background: #0f172a;
}

body.theme-sombre .card {
    background: #1e293b;
    border: 2px solid rgba(239, 68, 68, 0.3);
}

body.theme-sombre .card .icon {
    color: #f87171;
}

body.theme-sombre .card h2 {
    color: #f87171;
}

body.theme-sombre .card .subtitle {
    color: #94a3b8;
}

body.theme-sombre .card .consequences {
    background: rgba(239, 68, 68, 0.12);
}

body.theme-sombre .card .consequences li {
    color: #cbd5e1;
}

body.theme-sombre .card .consequences li i {
    color: #f87171;
}

body.theme-sombre .card .warning {
    color: #f87171;
}

body.theme-sombre .btn-secondary {
    background: #334155;
    color: #f1f5f9;
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

body.theme-sombre .modal-box {
    background: #1e293b;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7);
    border: 2px solid rgba(239, 68, 68, 0.3);
}

body.theme-sombre .modal-box .modal-icon {
    color: #f87171;
}

body.theme-sombre .modal-box h3 {
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

<div class="card">
    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
    <h2>Supprimer mon compte</h2>
    <p class="subtitle">Cette action est irréversible.</p>
    
    <?php if (!empty($erreur)): ?>
        <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= afficher($erreur) ?></div>
    <?php endif; ?>
    
    <?php if (!empty($succes)): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= afficher($succes) ?></div>
    <?php endif; ?>
    
    <ul class="consequences">
        <li><i class="fas fa-minus-circle"></i> Toutes vos données seront supprimées</li>
        <li><i class="fas fa-minus-circle"></i> Revenus et dépenses perdus</li>
        <li><i class="fas fa-minus-circle"></i> Objectifs et historiques supprimés</li>
        <li><i class="fas fa-minus-circle"></i> Sources de revenus supprimées</li>
    </ul>
    
    <p class="warning">⚠️ Cette action est définitive et ne peut pas être annulée.</p>
    
    <div class="actions">
        <a href="compte.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Annuler</a>
        <button class="btn btn-danger" onclick="ouvrirModalSuppressionCompte()">
            <i class="fas fa-trash"></i> Supprimer définitivement
        </button>
    </div>
</div>

<!-- MODALE -->
<div class="modal-overlay" id="modalSuppressionCompte">
    <div class="modal-box">
        <div class="modal-icon"><i class="fas fa-skull"></i></div>
        <h3>⚠️ Suppression définitive</h3>
        <p>Êtes-vous sûr de vouloir supprimer définitivement votre compte ?</p>
        <p class="sub-text">Cette action est irréversible. Toutes vos données seront perdues.</p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="fermerModalSuppressionCompte()"><i class="fas fa-times"></i> Annuler</button>
            <a href="?confirm=1&csrf_token=<?= urlencode($_SESSION['csrf_token']) ?>" class="btn-confirm-danger"><i class="fas fa-trash"></i> Supprimer définitivement</a>
        </div>
    </div>
</div>

<script>
function ouvrirModalSuppressionCompte() {
    document.getElementById('modalSuppressionCompte').classList.add('active');
}
function fermerModalSuppressionCompte() {
    document.getElementById('modalSuppressionCompte').classList.remove('active');
}
document.getElementById('modalSuppressionCompte').addEventListener('click', function(e) {
    if (e.target === this) fermerModalSuppressionCompte();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fermerModalSuppressionCompte();
});
</script>

</body>
</html>