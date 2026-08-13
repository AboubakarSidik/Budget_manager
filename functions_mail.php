<?php
// ============================================================
// FONCTIONS D'ENVOI D'EMAIL - BUDGET MANAGER
// ============================================================

// Protection contre l'accès direct
if (!defined('SITE_NAME')) {
    die('Accès direct interdit');
}

// === PHPMailer ===
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'vendor/autoload.php';

/**
 * Envoie un email via Gmail SMTP
 */
function envoyerEmail($destinataire, $nom, $sujet, $messageHTML) {
    
    if (DEBUG_MODE) {
        error_log("📧 EMAIL SIMULÉ : $sujet -> $destinataire");
        return true;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        
        // --- Encodage UTF-8 ---
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        
        // --- Expéditeur avec encodage UTF-8 ---
        $mail->setFrom(SMTP_FROM_EMAIL, '=?UTF-8?B?' . base64_encode(SMTP_FROM_NAME) . '?=');
        
        // --- Destinataire avec encodage UTF-8 ---
        $mail->addAddress($destinataire, '=?UTF-8?B?' . base64_encode($nom) . '?=');
        
        // --- Sujet avec encodage UTF-8 ---
        $mail->Subject = '=?UTF-8?B?' . base64_encode($sujet) . '?=';
        
        // --- Corps du message ---
        $mail->isHTML(true);
        $mail->Body    = $messageHTML;
        $mail->AltBody = strip_tags($messageHTML);
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Erreur d'envoi d'email : " . $mail->ErrorInfo);
        return false;
    }
}

// ============================================================
// EMAIL BIENVENUE 
// ============================================================

function emailBienvenue($email, $prenom) {
    $sujet = "Bienvenue sur Budget Manager";
    
    // Nettoyer le prénom pour l'affichage
    $prenom_clean = html_entity_decode($prenom, ENT_QUOTES, 'UTF-8');
    
    $message = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                background-color: #f4f6f8;
                color: #1a1a2e;
            }
            .container {
                max-width: 560px;
                margin: 0 auto;
                padding: 32px 24px;
                background-color: #ffffff;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            }
            .header {
                text-align: center;
                padding: 16px 0 20px 0;
                border-bottom: 1px solid #edf2f7;
            }
            .header h1 {
                font-size: 22px;
                font-weight: 600;
                margin: 0;
                color: #1a1a2e;
            }
            .header h1 span {
                color: #2563eb;
            }
            .header .sub {
                font-size: 13px;
                color: #8898aa;
                margin-top: 2px;
            }
            .content {
                padding: 28px 0 16px 0;
            }
            .content h2 {
                font-size: 18px;
                font-weight: 600;
                color: #1a1a2e;
                margin: 0 0 6px 0;
            }
            .content p {
                font-size: 14px;
                color: #4a5568;
                line-height: 1.6;
                margin: 0 0 12px 0;
            }
            .content .features {
                margin: 16px 0 20px 0;
                padding: 0;
                list-style: none;
            }
            .content .features li {
                padding: 6px 0;
                font-size: 14px;
                color: #2d3748;
                border-bottom: 1px solid #f7fafc;
            }
            .content .features li:last-child {
                border-bottom: none;
            }
            .content .features li span {
                margin-right: 8px;
            }
            .btn {
                display: inline-block;
                padding: 10px 28px;
                background-color: #2563eb;
                color: #ffffff;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 500;
                font-size: 14px;
                text-align: center;
            }
            .btn:hover {
                background-color: #1d4ed8;
            }
            .center {
                text-align: center;
            }
            .footer {
                text-align: center;
                padding: 20px 0 4px 0;
                border-top: 1px solid #edf2f7;
                font-size: 12px;
                color: #8898aa;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Budget <span>Manager</span></h1>
                <div class="sub">Gérez votre budget personnel simplement</div>
            </div>
            <div class="content">
                <h2>Bonjour ' . htmlspecialchars($prenom_clean, ENT_QUOTES, 'UTF-8') . ',</h2>
                <p>Votre compte Budget Manager a été créé avec succès.</p>
                <ul class="features">
                    <li><span>📊</span> Suivez vos revenus mois par mois</li>
                    <li><span>🧾</span> Planifiez et surveillez vos dépenses</li>
                    <li><span>🎯</span> Définissez vos objectifs d\'épargne</li>
                    <li><span>📈</span> Analysez vos finances en un coup d\'œil</li>
                </ul>
                <div class="center">
                    <a href="' . SITE_URL . '" class="btn">Accéder à mon compte</a>
                </div>
            </div>
            <div class="footer">
                <p>© Budget Manager — Gérez votre budget personnel simplement</p>
            </div>
        </div>
    </body>
    </html>
    ';
    return envoyerEmail($email, $prenom_clean, $sujet, $message);
}

// ============================================================
// EMAIL RÉINITIALISATION 
// ============================================================

function emailReinitialisation($email, $prenom, $lien) {
    $sujet = "Réinitialisation de votre mot de passe";
    $prenom_clean = html_entity_decode($prenom, ENT_QUOTES, 'UTF-8');
    $message = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                background-color: #f4f6f8;
                color: #1a1a2e;
            }
            .container {
                max-width: 560px;
                margin: 0 auto;
                padding: 32px 24px;
                background-color: #ffffff;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            }
            .header {
                text-align: center;
                padding: 16px 0 20px 0;
                border-bottom: 1px solid #edf2f7;
            }
            .header h1 {
                font-size: 22px;
                font-weight: 600;
                margin: 0;
                color: #1a1a2e;
            }
            .header h1 span {
                color: #2563eb;
            }
            .content {
                padding: 28px 0 16px 0;
            }
            .content h2 {
                font-size: 18px;
                font-weight: 600;
                color: #1a1a2e;
                margin: 0 0 6px 0;
            }
            .content p {
                font-size: 14px;
                color: #4a5568;
                line-height: 1.6;
                margin: 0 0 12px 0;
            }
            .btn {
                display: inline-block;
                padding: 10px 28px;
                background-color: #2563eb;
                color: #ffffff;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 500;
                font-size: 14px;
                text-align: center;
            }
            .btn:hover {
                background-color: #1d4ed8;
            }
            .center {
                text-align: center;
            }
            .footer {
                text-align: center;
                padding: 20px 0 4px 0;
                border-top: 1px solid #edf2f7;
                font-size: 12px;
                color: #8898aa;
            }
            .note {
                font-size: 12px;
                color: #a0aec0;
                text-align: center;
                margin-top: 16px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Budget <span>Manager</span></h1>
            </div>
            <div class="content">
                <h2>Bonjour ' . htmlspecialchars($prenom_clean, ENT_QUOTES, 'UTF-8') . ',</h2>
                <p>Vous avez demandé à réinitialiser votre mot de passe.</p>
                <p>Cliquez sur le bouton ci-dessous pour en définir un nouveau :</p>
                <div class="center">
                    <a href="' . $lien . '" class="btn">Réinitialiser mon mot de passe</a>
                </div>
                <p class="note">Ce lien est valable 1 heure.<br>Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.</p>
            </div>
            <div class="footer">
                <p>© Budget Manager — Gérez votre budget personnel simplement</p>
            </div>
        </div>
    </body>
    </html>
    ';
    return envoyerEmail($email, $prenom_clean, $sujet, $message);
}

// ============================================================
// EMAIL SUPPRESSION 
// ============================================================

function emailSuppression($email, $prenom) {
    $sujet = "Votre compte Budget Manager a été supprimé";
    $prenom_clean = html_entity_decode($prenom, ENT_QUOTES, 'UTF-8');
    $message = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                background-color: #f4f6f8;
                color: #1a1a2e;
            }
            .container {
                max-width: 560px;
                margin: 0 auto;
                padding: 32px 24px;
                background-color: #ffffff;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            }
            .header {
                text-align: center;
                padding: 16px 0 20px 0;
                border-bottom: 1px solid #edf2f7;
            }
            .header h1 {
                font-size: 22px;
                font-weight: 600;
                margin: 0;
                color: #1a1a2e;
            }
            .header h1 span {
                color: #2563eb;
            }
            .content {
                padding: 28px 0 16px 0;
            }
            .content h2 {
                font-size: 18px;
                font-weight: 600;
                color: #1a1a2e;
                margin: 0 0 6px 0;
            }
            .content p {
                font-size: 14px;
                color: #4a5568;
                line-height: 1.6;
                margin: 0 0 12px 0;
            }
            .footer {
                text-align: center;
                padding: 20px 0 4px 0;
                border-top: 1px solid #edf2f7;
                font-size: 12px;
                color: #8898aa;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Budget <span>Manager</span></h1>
            </div>
            <div class="content">
                <h2>Bonjour ' . htmlspecialchars($prenom_clean, ENT_QUOTES, 'UTF-8') . ',</h2>
                <p>Votre compte Budget Manager a été supprimé avec succès.</p>
                <p>Toutes vos données ont été effacées de nos serveurs.</p>
                <p>Nous espérons vous revoir bientôt.</p>
            </div>
            <div class="footer">
                <p>© Budget Manager — Gérez votre budget personnel simplement</p>
            </div>
        </div>
    </body>
    </html>
    ';
    return envoyerEmail($email, $prenom_clean, $sujet, $message);
}

// ============================================================
// EMAIL CONFIRMATION CHANGEMENT DE MOT DE PASSE 
// ============================================================

function emailConfirmationChangement($email, $prenom) {
    $sujet = "Votre mot de passe a été modifié";
    $prenom_clean = html_entity_decode($prenom, ENT_QUOTES, 'UTF-8');
    $message = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                background-color: #f4f6f8;
                color: #1a1a2e;
            }
            .container {
                max-width: 560px;
                margin: 0 auto;
                padding: 32px 24px;
                background-color: #ffffff;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            }
            .header {
                text-align: center;
                padding: 16px 0 20px 0;
                border-bottom: 1px solid #edf2f7;
            }
            .header h1 {
                font-size: 22px;
                font-weight: 600;
                margin: 0;
                color: #1a1a2e;
            }
            .header h1 span {
                color: #2563eb;
            }
            .content {
                padding: 28px 0 16px 0;
            }
            .content h2 {
                font-size: 18px;
                font-weight: 600;
                color: #1a1a2e;
                margin: 0 0 6px 0;
            }
            .content p {
                font-size: 14px;
                color: #4a5568;
                line-height: 1.6;
                margin: 0 0 12px 0;
            }
            .footer {
                text-align: center;
                padding: 20px 0 4px 0;
                border-top: 1px solid #edf2f7;
                font-size: 12px;
                color: #8898aa;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Budget <span>Manager</span></h1>
            </div>
            <div class="content">
                <h2>Bonjour ' . htmlspecialchars($prenom_clean, ENT_QUOTES, 'UTF-8') . ',</h2>
                <p>Votre mot de passe a été modifié avec succès.</p>
                <p>Si vous n\'êtes pas à l\'origine de cette modification, contactez-nous immédiatement.</p>
            </div>
            <div class="footer">
                <p>© Budget Manager — Gérez votre budget personnel simplement</p>
            </div>
        </div>
    </body>
    </html>
    ';
    return envoyerEmail($email, $prenom_clean, $sujet, $message);
}