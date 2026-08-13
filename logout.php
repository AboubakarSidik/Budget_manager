<?php
// ============================================================
// DÉCONNEXION
// ============================================================

require_once 'session_init.php';

// Détruire la session
$_SESSION = array();
session_destroy();

// Rediriger vers la page d'accueil
header('Location: index.php');
exit;