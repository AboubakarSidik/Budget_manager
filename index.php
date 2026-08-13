<?php
// ============================================================
// PAGE D'ACCUEIL - REDIRECTION SELON SITUATION
// ============================================================

require_once 'session_init.php';

// Si l'utilisateur est connecté, aller au dashboard
if (isset($_SESSION['utilisateur_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Sinon, toujours aller à la landing page
header('Location: landing.php');
exit;