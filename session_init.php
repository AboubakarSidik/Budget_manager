<?php
// ============================================================
// INITIALISATION DE LA SESSION - BUDGET MANAGER
// ============================================================

// --- Configuration de session AVANT de démarrer ---
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);  // 1 si HTTPS

// --- Démarrer la session si elle n'est pas déjà active ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Générer un token CSRF si inexistant ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Thème désactivé - toujours clair
$_SESSION['theme'] = 'clair';