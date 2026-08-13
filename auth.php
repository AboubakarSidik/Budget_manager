<?php
// ============================================================
// AUTHENTIFICATION - CONNEXION + INSCRIPTION
// ============================================================

require_once 'session_init.php';
require_once 'config.php';
require_once 'functions.php';
require_once 'functions_mail.php';

// Si déjà connecté, rediriger vers le dashboard
if (estConnecte()) {
    rediriger('index.php');
}

// --- Limite de tentatives de connexion ---
if (!isset($_SESSION['tentatives'])) {
    $_SESSION['tentatives'] = 0;
}

$erreur = '';
$succes = '';
$info = '';

// Message après suppression du compte
if (isset($_GET['supprime']) && $_GET['supprime'] == 1) {
    $info = "✅ Votre compte a été supprimé avec succès. Nous espérons vous revoir bientôt !";
}

// ============================================================
// TRAITEMENT : INSCRIPTION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inscription') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        
        $nom = nettoyer($_POST['nom'] ?? '');
        $prenom = nettoyer($_POST['prenom'] ?? '');
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $mot_de_passe = $_POST['mot_de_passe'] ?? '';
        $confirmation = $_POST['confirmation'] ?? '';
        
        // --- Validation ---
        if (empty($nom) || empty($prenom) || empty($email) || empty($mot_de_passe)) {
            $erreur = "Tous les champs sont obligatoires.";
        } elseif (!preg_match('/^[a-zA-ZÀ-ÿ\s-]+$/', $nom)) {
            $erreur = "Le nom ne doit contenir que des lettres, espaces et tirets.";
        } elseif (!preg_match('/^[a-zA-ZÀ-ÿ\s-]+$/', $prenom)) {
            $erreur = "Le prénom ne doit contenir que des lettres, espaces et tirets.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = "Adresse email invalide.";
        } elseif (strlen($mot_de_passe) < 8) {
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
        } elseif (emailExiste($pdo, $email)) {
            $erreur = "Cette adresse email est déjà utilisée.";
        }
        
        // --- Insertion ---
        if (empty($erreur)) {
            $uuid = genererUUID();
            $hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
            
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO compte (id, nom, prenom, email, mot_de_passe_hash, situation_principale, actif)
                    VALUES (?, ?, ?, ?, ?, 'autre', 1)
                ");
                $stmt->execute([$uuid, $nom, $prenom, $email, $hash]);
                
                // Catégories par défaut
                $stmt = $pdo->prepare("
                    INSERT INTO categorie (id, compte_id, nom, montant_plafond)
                    VALUES 
                    (?, ?, 'Logement', 150000),
                    (?, ?, 'Nourriture', 80000),
                    (?, ?, 'Transport', 50000),
                    (?, ?, 'Santé', 40000),
                    (?, ?, 'Éducation', 30000),
                    (?, ?, 'Communication', 20000),
                    (?, ?, 'Loisirs', 30000),
                    (?, ?, 'Dettes', 50000),
                    (?, ?, 'Activité professionnelle personnelle', 0),
                    (?, ?, 'Autre', 0)
                ");
                $catIds = array_map(function() { return genererUUID(); }, range(1, 10));
                $params = [];
                foreach ($catIds as $catId) {
                    $params[] = $catId;
                    $params[] = $uuid;
                }
                $stmt->execute($params);
                
                $pdo->commit();
                
                // Créer le cookie de première visite
                setcookie('budget_manager_visited', '1', time() + 365 * 24 * 3600, '/');
                
                // --- Envoi de l'email de bienvenue ---
                if (DEBUG_MODE) {
                    $info = "✅ Compte créé avec succès ! (Email de bienvenue simulé)";
                } else {
                    $email_envoye = emailBienvenue($email, $prenom);
                    if ($email_envoye) {
                        $info = "✅ Compte créé ! Un email de bienvenue vous a été envoyé.";
                    } else {
                        $info = "✅ Compte créé ! (Impossible d'envoyer l'email)";
                    }
                }
                
                // Connexion automatique
                session_regenerate_id(true);
                $_SESSION['utilisateur_id'] = $uuid;
                $_SESSION['utilisateur_nom'] = $prenom . ' ' . $nom;
                $_SESSION['utilisateur_email'] = $email;
                $_SESSION['tentatives'] = 0;
                
                rediriger('index.php');
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $erreur = "Une erreur est survenue lors de la création du compte.";
            }
        }
    }
}

// ============================================================
// TRAITEMENT : CONNEXION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'connexion') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $erreur = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $mot_de_passe = $_POST['mot_de_passe'] ?? '';
        
        if (empty($email) || empty($mot_de_passe)) {
            $erreur = "Veuillez remplir tous les champs.";
        } elseif ($_SESSION['tentatives'] >= 5) {
            $erreur = "⚠️ Trop de tentatives de connexion. Veuillez attendre 15 minutes.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM compte WHERE email = ? AND actif = 1");
            $stmt->execute([$email]);
            $utilisateur = $stmt->fetch();
            
            if ($utilisateur && password_verify($mot_de_passe, $utilisateur['mot_de_passe_hash'])) {
                // Mise à jour dernière connexion
                $stmt = $pdo->prepare("UPDATE compte SET derniere_connexion = NOW() WHERE id = ?");
                $stmt->execute([$utilisateur['id']]);
                
                session_regenerate_id(true);
                $_SESSION['utilisateur_id'] = $utilisateur['id'];
                $_SESSION['utilisateur_nom'] = $utilisateur['prenom'] . ' ' . $utilisateur['nom'];
                $_SESSION['utilisateur_email'] = $utilisateur['email'];
                $_SESSION['tentatives'] = 0;
                
                rediriger('index.php');
            } else {
                $_SESSION['tentatives']++;
                $erreur = "Email ou mot de passe incorrect.";
            }
        }
    }
}

// Déterminer l'onglet actif
$onglet_actif = isset($_GET['onglet']) && $_GET['onglet'] === 'inscription' ? 'inscription' : 'connexion';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Manager - Authentification</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="auth-container">
    
    <!-- ===== LOGO ===== -->
    <div class="auth-logo">
        <h1><i class="fas fa-wallet"></i> Budget Manager</h1>
        <p>Gérez votre budget personnel simplement</p>
    </div>
    
    <!-- ===== MESSAGES ===== -->
    <?php if (!empty($info)): ?>
        <div class="message info"><i class="fas fa-info-circle"></i> <?= afficher($info) ?></div>
    <?php endif; ?>
    
    <?php if (!empty($erreur)): ?>
        <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= afficher($erreur) ?></div>
    <?php endif; ?>
    
    <?php if (!empty($succes)): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= afficher($succes) ?></div>
    <?php endif; ?>
    
    <!-- ===== TABS ===== -->
    <div class="auth-tabs" role="tablist">
        <a href="?onglet=connexion" 
           class="<?= $onglet_actif === 'connexion' ? 'active' : '' ?>" 
           style="flex:1; padding:10px 16px; border:none; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; text-align:center; transition:all 0.3s ease; background:<?= $onglet_actif === 'connexion' ? '#ffffff' : 'transparent' ?>; color:<?= $onglet_actif === 'connexion' ? '#0f172a' : '#64748b' ?>; box-shadow:<?= $onglet_actif === 'connexion' ? '0 2px 8px rgba(0,0,0,0.08)' : 'none' ?>; font-family:'Inter',sans-serif; cursor:pointer;">
            <i class="fas fa-sign-in-alt"></i> Se connecter
        </a>
        <a href="?onglet=inscription" 
           class="<?= $onglet_actif === 'inscription' ? 'active' : '' ?>" 
           style="flex:1; padding:10px 16px; border:none; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; text-align:center; transition:all 0.3s ease; background:<?= $onglet_actif === 'inscription' ? '#ffffff' : 'transparent' ?>; color:<?= $onglet_actif === 'inscription' ? '#0f172a' : '#64748b' ?>; box-shadow:<?= $onglet_actif === 'inscription' ? '0 2px 8px rgba(0,0,0,0.08)' : 'none' ?>; font-family:'Inter',sans-serif; cursor:pointer;">
            <i class="fas fa-user-plus"></i> S'inscrire
        </a>
    </div>
    
    <!-- ============================================================
         FORMULAIRE CONNEXION
         ============================================================ -->
    <div class="auth-form <?= $onglet_actif === 'connexion' ? 'active' : '' ?>" id="form-connexion">
        <form method="POST" action="?onglet=connexion">
            <input type="hidden" name="action" value="connexion">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Adresse email</label>
                <input type="email" id="email" name="email" 
                       placeholder="exemple@email.com" 
                       value="<?= isset($_POST['email']) ? afficher($_POST['email']) : '' ?>" 
                       required>
            </div>
            
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Mot de passe</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="mot_de_passe" 
                           placeholder="Votre mot de passe" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('password')">
                        <i class="fas fa-eye" id="password-icon"></i>
                    </button>
                </div>
            </div>
            
            <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                <a href="mot_de_passe_oublie.php" class="forgot-link">
                    Mot de passe oublié ?
                </a>
            </div>
            
            <button type="submit" class="btn-primary">
                <i class="fas fa-sign-in-alt"></i> Se connecter
            </button>
        </form>
    </div>
    
    <!-- ============================================================
         FORMULAIRE INSCRIPTION
         ============================================================ -->
    <div class="auth-form <?= $onglet_actif === 'inscription' ? 'active' : '' ?>" id="form-inscription">
        <form method="POST" action="?onglet=inscription">
            <input type="hidden" name="action" value="inscription">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="form-group">
                <label for="nom"><i class="fas fa-user"></i> Nom</label>
                <input type="text" id="nom" name="nom" 
                       placeholder="Dupont" 
                       value="<?= isset($_POST['nom']) ? afficher($_POST['nom']) : '' ?>" 
                       required>
            </div>
            
            <div class="form-group">
                <label for="prenom"><i class="fas fa-user"></i> Prénom</label>
                <input type="text" id="prenom" name="prenom" 
                       placeholder="Jean" 
                       value="<?= isset($_POST['prenom']) ? afficher($_POST['prenom']) : '' ?>" 
                       required>
            </div>
            
            <div class="form-group">
                <label for="email-inscription"><i class="fas fa-envelope"></i> Adresse email</label>
                <input type="email" id="email-inscription" name="email" 
                       placeholder="exemple@email.com" 
                       value="<?= isset($_POST['email']) ? afficher($_POST['email']) : '' ?>" 
                       required>
            </div>
            
            <div class="form-group">
                <label for="password-inscription"><i class="fas fa-lock"></i> Mot de passe</label>
                <div class="input-wrapper">
                    <input type="password" id="password-inscription" name="mot_de_passe" 
                           placeholder="8 caractères minimum" 
                           oninput="checkPasswordStrength(this.value)" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('password-inscription')">
                        <i class="fas fa-eye" id="password-inscription-icon"></i>
                    </button>
                </div>
                <div class="password-hint" id="password-hint">
                    <span id="hint-length"><i class="fas fa-circle" style="font-size: 8px;"></i> 8 caractères minimum</span><br>
                    <span id="hint-upper"><i class="fas fa-circle" style="font-size: 8px;"></i> Une majuscule</span><br>
                    <span id="hint-lower"><i class="fas fa-circle" style="font-size: 8px;"></i> Une minuscule</span><br>
                    <span id="hint-digit"><i class="fas fa-circle" style="font-size: 8px;"></i> Un chiffre</span><br>
                    <span id="hint-special"><i class="fas fa-circle" style="font-size: 8px;"></i> Un caractère spécial</span>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirmation"><i class="fas fa-check-circle"></i> Confirmer le mot de passe</label>
                <div class="input-wrapper">
                    <input type="password" id="confirmation" name="confirmation" 
                           placeholder="Confirmez votre mot de passe" required>
                </div>
            </div>
            
            <button type="submit" class="btn-primary">
                <i class="fas fa-user-plus"></i> Créer mon compte
            </button>
        </form>
    </div>
    
</div>

<script src="js/app.js"></script>

</body>
</html>