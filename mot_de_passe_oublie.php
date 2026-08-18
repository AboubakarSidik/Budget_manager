<?php
// ============================================================
// MOT DE PASSE OUBLIÉ - VERSION SÉCURISÉE
// ============================================================

require_once 'session_init.php';
require_once 'config.php';
require_once 'functions.php';
require_once 'functions_mail.php';

// Générer un token CSRF si inexistant
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$erreur = '';
$succes = '';
$email = '';
$lien_generer = '';

// Récupérer l'IP du visiteur
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ============================================================
// VÉRIFICATION : LIMITE PAR IP (5 demandes par heure)
// ============================================================
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM reset_log 
    WHERE ip = ? AND date_demande > DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$stmt->execute([$ip]);
$nb_demandes = $stmt->fetchColumn();

if ($nb_demandes >= 5) {
    $erreur = "⚠️ Trop de demandes de réinitialisation (5 max par heure). Réessayez dans 1 heure.";
}

// ============================================================
// VÉRIFICATION : DÉLAI ENTRE TENTATIVES (5 minutes)
// ============================================================
if (empty($erreur)) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reset_log 
        WHERE ip = ? AND date_demande > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute([$ip]);
    $nb_recentes = $stmt->fetchColumn();
    
    if ($nb_recentes > 0) {
        $erreur = "⚠️ Une demande a déjà été faite récemment. Attendez 5 minutes.";
    }
}

// ============================================================
// TRAITEMENT : DEMANDE DE RÉINITIALISATION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && empty($erreur)) {
    
    // Vérification CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = "Adresse email invalide.";
        } else {
            // Vérifier si le compte existe et est actif
            $stmt = $pdo->prepare("SELECT id, prenom FROM compte WHERE email = ? AND actif = 1");
            $stmt->execute([$email]);
            $utilisateur = $stmt->fetch();
            
            // Journaliser la demande
            $stmt = $pdo->prepare("INSERT INTO reset_log (email, ip) VALUES (?, ?)");
            $stmt->execute([$email, $ip]);
            
            if ($utilisateur) {
                // Vérifier si un token existe déjà (et le supprimer)
                $stmt = $pdo->prepare("DELETE FROM reset_token WHERE compte_id = ?");
                $stmt->execute([$utilisateur['id']]);
                
                // Générer le token
                $token = bin2hex(random_bytes(32));
                $token_hash = hash('sha256', $token);
                $expire = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $stmt = $pdo->prepare("
                    INSERT INTO reset_token (compte_id, token_hash, expire_at)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$utilisateur['id'], $token_hash, $expire]);
                
                $lien = SITE_URL . "reinitialiser_mot_de_passe.php?token=" . $token;
                
                // Envoyer l'email
                if (DEBUG_MODE) {
                    $lien_generer = $lien;
                    $succes = "✅ Un lien de réinitialisation a été généré (mode test).";
                } else {
                    $envoye = emailReinitialisation($email, $utilisateur['prenom'], $lien);
                    if ($envoye) {
                        $succes = "✅ Un email de réinitialisation a été envoyé à <strong>" . afficher($email) . "</strong>.";
                    } else {
                        $lien_generer = $lien;
                        $succes = "⚠️ L'email n'a pas pu être envoyé. Utilisez le lien ci-dessous :";
                    }
                }
            } else {
                // Message générique pour la sécurité
                $succes = "✅ Si l'email existe, un lien de réinitialisation vous a été envoyé.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié</title>
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
        .back-link { display: block; text-align: center; margin-top: 16px; font-size: 14px; color: #64748b; }
        .back-link a { color: #2563eb; text-decoration: none; font-weight: 500; }
        .back-link a:hover { text-decoration: underline; }
        .message { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
        .message.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .message.success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .lien-box {
            background: #f1f5f9;
            padding: 12px 16px;
            border-radius: 10px;
            word-break: break-all;
            font-size: 13px;
            color: #2563eb;
            margin-top: 12px;
            border: 1px solid #e2e8f0;
        }
        .lien-box a { color: #2563eb; text-decoration: underline; }


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

body.theme-sombre .back-link {
    color: #94a3b8;
}

body.theme-sombre .back-link a {
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

body.theme-sombre .lien-box {
    background: #334155;
    color: #60a5fa;
    border: 1px solid #334155;
}

body.theme-sombre .lien-box a {
    color: #60a5fa;
}
</style>
</head>
<body>

<div class="card">
    <h2>🔐 Mot de passe oublié</h2>
    <p class="subtitle">Entrez votre email pour recevoir un lien de réinitialisation.</p>

    <?php if (!empty($erreur)): ?>
        <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= afficher($erreur) ?></div>
    <?php endif; ?>

    <?php if (!empty($succes)): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= $succes ?></div>
        <?php if (!empty($lien_generer)): ?>
            <div class="lien-box">
                <i class="fas fa-link"></i> 
                <a href="<?= $lien_generer ?>" target="_blank"><?= $lien_generer ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        
        <div class="form-group">
            <label for="email">📧 Adresse email</label>
            <input type="email" id="email" name="email" placeholder="exemple@email.com" value="<?= afficher($email) ?>" required>
        </div>
        <button type="submit" class="btn-primary">
            <i class="fas fa-paper-plane"></i> Envoyer le lien
        </button>
    </form>

    <div class="back-link">
        <a href="auth.php"><i class="fas fa-arrow-left"></i> Retour à la connexion</a>
    </div>
</div>

</body>
</html>