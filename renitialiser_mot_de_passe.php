<?php
// ============================================================
// RÉINITIALISATION DU MOT DE PASSE - VERSION SÉCURISÉE
// ============================================================

require_once 'session_init.php';
require_once 'config.php';
require_once 'functions.php';
require_once 'functions_mail.php';

// Générer un token CSRF si inexistant
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$token = $_GET['token'] ?? '';
$erreur = '';
$succes = '';
$valide = false;
$compte_id = null;
$email = '';
$prenom = '';

// ============================================================
// VÉRIFICATION DU TOKEN
// ============================================================
if (!empty($token)) {
    $token_hash = hash('sha256', $token);
    
    $stmt = $pdo->prepare("
        SELECT r.compte_id, c.email, c.prenom
        FROM reset_token r
        JOIN compte c ON c.id = r.compte_id
        WHERE r.token_hash = ? AND r.expire_at > NOW() AND c.actif = 1
    ");
    $stmt->execute([$token_hash]);
    $result = $stmt->fetch();
    
    if ($result) {
        $compte_id = $result['compte_id'];
        $email = $result['email'];
        $prenom = $result['prenom'];
        $valide = true;
    } else {
        $erreur = "⚠️ Lien invalide ou expiré. Veuillez refaire une demande.";
    }
} else {
    $erreur = "⚠️ Lien invalide.";
}

// ============================================================
// TRAITEMENT : NOUVEAU MOT DE PASSE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valide && isset($_POST['mot_de_passe'])) {
    
    // Vérification CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $mot_de_passe = $_POST['mot_de_passe'] ?? '';
        $confirmation = $_POST['confirmation'] ?? '';
        
        if (strlen($mot_de_passe) < 8) {
            $erreur = "Le mot de passe doit contenir au moins 8 caractères.";
        } elseif (!preg_match('/[A-Z]/', $mot_de_passe)) {
            $erreur = "Le mot de passe doit contenir une majuscule.";
        } elseif (!preg_match('/[a-z]/', $mot_de_passe)) {
            $erreur = "Le mot de passe doit contenir une minuscule.";
        } elseif (!preg_match('/[0-9]/', $mot_de_passe)) {
            $erreur = "Le mot de passe doit contenir un chiffre.";
        } elseif (!preg_match('/[^a-zA-Z0-9]/', $mot_de_passe)) {
            $erreur = "Le mot de passe doit contenir un caractère spécial.";
        } elseif ($mot_de_passe !== $confirmation) {
            $erreur = "Les mots de passe ne correspondent pas.";
        } else {
            // Mettre à jour le mot de passe
            $hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE compte SET mot_de_passe_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $compte_id]);
            
            // Supprimer le token
            $stmt = $pdo->prepare("DELETE FROM reset_token WHERE compte_id = ?");
            $stmt->execute([$compte_id]);
            
            // Email de confirmation
            if (!DEBUG_MODE) {
                emailConfirmationChangement($email, $prenom);
            }
            
            $succes = "✅ Votre mot de passe a été modifié avec succès !<br>
                        Un email de confirmation vous a été envoyé à <strong>" . afficher($email) . "</strong>.<br>
                        Vous allez être redirigé vers la page de connexion.";
            header("refresh:4;url=auth.php");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialiser mon mot de passe</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f8fafc; font-family: 'Inter', sans-serif; padding: 20px; }
        .card { background: white; border-radius: 20px; padding: 40px 32px; max-width: 420px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
        .card h2 { font-size: 24px; font-weight: 700; color: #0f172a; text-align: center; margin-bottom: 8px; }
        .card .subtitle { font-size: 14px; color: #64748b; text-align: center; margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; }
        .form-group input { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 15px; font-family: 'Inter', sans-serif; transition: border-color 0.2s; }
        .form-group input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        .btn-primary { width: 100%; padding: 14px; background: #2563eb; color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary:hover { background: #1d4ed8; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(37,99,235,0.3); }
        .message { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
        .message.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .message.success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .password-hint { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .back-link { display: block; text-align: center; margin-top: 16px; font-size: 14px; color: #64748b; }
        .back-link a { color: #2563eb; text-decoration: none; font-weight: 500; }
        .back-link a:hover { text-decoration: underline; }
        .email-display { font-weight: 600; color: #0f172a; }


        /* ============================================================
           MODE SOMBRE (genere automatiquement)
           ============================================================ */

body.theme-sombre {
    background: #0f172a;
}

body.theme-sombre .card {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .card h2 {
    color: #f1f5f9;
}

body.theme-sombre .card .subtitle {
    color: #94a3b8;
}

body.theme-sombre .form-group label {
    color: #e2e8f0;
}

body.theme-sombre .form-group input {
    border: 2px solid #334155;
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

body.theme-sombre .back-link {
    color: #94a3b8;
}

body.theme-sombre .back-link a {
    color: #60a5fa;
}

body.theme-sombre .email-display {
    color: #f1f5f9;
}
</style>
</head>
<body>

<div class="card">
    <h2>🔑 Nouveau mot de passe</h2>

    <?php if ($valide && empty($succes)): ?>
        <p class="subtitle">
            Réinitialisation du mot de passe pour <span class="email-display"><?= afficher($email) ?></span>
        </p>

        <?php if (!empty($erreur)): ?>
            <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= afficher($erreur) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="form-group">
                <label for="mot_de_passe">🔒 Nouveau mot de passe</label>
                <input type="password" id="mot_de_passe" name="mot_de_passe" placeholder="8 caractères minimum" required>
                <div class="password-hint">8+ caractères, une majuscule, une minuscule, un chiffre, un caractère spécial</div>
            </div>
            <div class="form-group">
                <label for="confirmation">🔒 Confirmer le mot de passe</label>
                <input type="password" id="confirmation" name="confirmation" placeholder="Confirmez votre mot de passe" required>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Modifier le mot de passe
            </button>
        </form>

        <div class="back-link">
            <a href="auth.php"><i class="fas fa-arrow-left"></i> Retour à la connexion</a>
        </div>

    <?php elseif (!empty($succes)): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= $succes ?></div>
        <p style="text-align:center; margin-top:16px;">
            <a href="auth.php" style="color:#2563eb; text-decoration:none; font-weight:500;">
                <i class="fas fa-arrow-right"></i> Aller à la connexion
            </a>
        </p>
    <?php else: ?>
        <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= afficher($erreur) ?></div>
        <p style="text-align:center; margin-top:16px;">
            <a href="mot_de_passe_oublie.php" style="color:#2563eb; text-decoration:none; font-weight:500;">
                <i class="fas fa-arrow-left"></i> Faire une nouvelle demande
            </a>
        </p>
    <?php endif; ?>
</div>

</body>
</html>