<?php
// ============================================================
// CONFIGURATION - BUDGET MANAGER (EXEMPLE)
// ============================================================
// 📌 Copiez ce fichier en config.php et remplissez vos valeurs
// 📌 Ne commitez JAMAIS config.php sur GitHub !
// ============================================================

// --- Paramètres de connexion à la base de données ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'budget_manager');
define('DB_USER', 'root');
define('DB_PASS', '');  // ← Remplacez par votre mot de passe MySQL

// --- Paramètres de l'application ---
define('SITE_URL', 'http://localhost/budget_manager/');  // ← Changez en production

// --- Mode développement ---
define('DEBUG_MODE', true);  // true = lien affiché sur la page, false = envoi d'email

// --- Configuration email (Gmail SMTP) ---
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USERNAME', 'votre.email@gmail.com');      // ← Votre email Gmail
define('SMTP_PASSWORD', 'votre_mot_de_passe_app');     // ← Mot de passe d'application Gmail
define('SMTP_FROM_EMAIL', 'votre.email@gmail.com');
define('SMTP_FROM_NAME', 'Budget Manager');

// --- Connexion PDO ---
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    if (DEBUG_MODE) {
        die("❌ Erreur de connexion à la base de données : " . $e->getMessage());
    } else {
        die("❌ Une erreur technique est survenue. Veuillez réessayer ultérieurement.");
    }
}