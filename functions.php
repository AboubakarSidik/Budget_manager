<?php
// ============================================================
// FONCTIONS UTILITAIRES - BUDGET MANAGER
// ============================================================

/**
 * Vérifie si l'utilisateur est connecté
 */
function estConnecte() {
    return isset($_SESSION['utilisateur_id']);
}

/**
 * Redirige vers une page
 */
function rediriger($page) {
    header('Location: ' . SITE_URL . $page);
    exit;
}

/**
 * Nettoie une chaîne pour le stockage (pas d'échappement HTML)
 */
function nettoyer($chaine) {
    if ($chaine === null) return '';
    return trim($chaine);
}

/**
 * Échappe une chaîne pour un affichage HTML sécurisé
 * Décode d'abord les entités pour éviter le double encodage
 */
function afficher($chaine) {
    if ($chaine === null) return '';
    // Décoder les entités HTML, puis ré-encoder proprement
    return htmlspecialchars(html_entity_decode($chaine, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

/**
 * Génère un UUID v4 pour la base de données
 */
function genererUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

/**
 * Récupère les informations de l'utilisateur connecté
 */
function getUtilisateur($pdo) {
    if (!estConnecte()) {
        return null;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM compte WHERE id = ?");
    $stmt->execute([$_SESSION['utilisateur_id']]);
    return $stmt->fetch();
}

/**
 * Formatage des montants en FCFA
 */
function formatFCFA($montant) {
    return number_format($montant, 0, ',', ' ') . ' FCFA';
}

/**
 * Récupère le mois en cours pour un utilisateur
 * Priorité : mois calendaire actuel, sinon dernier mois créé
 */
function getMoisEnCours($pdo, $compte_id) {
    // Mois calendaire actuel (format YYYY-MM)
    $mois_actuel = date('Y-m');
    
    // 1. Essayer de récupérer le mois calendaire actuel
    $stmt = $pdo->prepare("
        SELECT * FROM mois 
        WHERE compte_id = ? AND periode = ? AND statut IN ('en_cours', 'rouvert')
        LIMIT 1
    ");
    $stmt->execute([$compte_id, $mois_actuel]);
    $result = $stmt->fetch();
    
    // 2. Si pas de mois actuel, prendre le dernier mois créé
    if (!$result) {
        $stmt = $pdo->prepare("
            SELECT * FROM mois 
            WHERE compte_id = ? 
            ORDER BY periode DESC 
            LIMIT 1
        ");
        $stmt->execute([$compte_id]);
        return $stmt->fetch();
    }
    
    return $result;
}

/**
 * Vérifie si une adresse email est déjà utilisée
 */
function emailExiste($pdo, $email) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM compte WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Récupère toutes les catégories d'un utilisateur
 */
function getCategories($pdo, $compte_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM categorie 
        WHERE compte_id = ? 
        ORDER BY nom
    ");
    $stmt->execute([$compte_id]);
    return $stmt->fetchAll();
}

/**
 * Récupère les revenus d'un mois
 */
function getRevenusMois($pdo, $mois_id) {
    $stmt = $pdo->prepare("
        SELECT r.*, s.libelle AS source_libelle, s.type AS source_type
        FROM revenu r
        JOIN source_revenu s ON s.id = r.source_revenu_id
        WHERE r.mois_id = ?
        ORDER BY r.date_reception DESC
    ");
    $stmt->execute([$mois_id]);
    return $stmt->fetchAll();
}

/**
 * Récupère les dépenses d'un mois avec leurs catégories
 */
function getDepensesMois($pdo, $mois_id) {
    $stmt = $pdo->prepare("
        SELECT d.*, ld.nom AS ligne_nom, c.nom AS categorie_nom
        FROM depense d
        JOIN ligne_depense ld ON ld.id = d.ligne_depense_id
        JOIN categorie c ON c.id = ld.categorie_id
        WHERE d.mois_id = ?
        ORDER BY d.date_paiement DESC
    ");
    $stmt->execute([$mois_id]);
    return $stmt->fetchAll();
}

/**
 * Calcule le budget total d'un mois (somme des revenus)
 */
function getBudgetTotal($pdo, $mois_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) AS total FROM revenu WHERE mois_id = ?");
    $stmt->execute([$mois_id]);
    return $stmt->fetchColumn();
}

/**
 * Calcule le total des dépenses réelles d'un mois
 */
function getDepensesReelles($pdo, $mois_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_reel), 0) AS total FROM depense WHERE mois_id = ?");
    $stmt->execute([$mois_id]);
    return $stmt->fetchColumn();
}

/**
 * Calcule le total des imprévus utilisés d'un mois
 */
function getImprevusUtilises($pdo, $mois_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) AS total FROM utilisation_imprevu WHERE mois_id = ?");
    $stmt->execute([$mois_id]);
    return $stmt->fetchColumn();
}

/**
 * Calcule l'épargne réelle d'un mois
 */
function getEpargneReelle($pdo, $mois_id) {
    $budget = getBudgetTotal($pdo, $mois_id);
    $depenses = getDepensesReelles($pdo, $mois_id);
    $imprevus = getImprevusUtilises($pdo, $mois_id);
    return $budget - $depenses - $imprevus;
}

/**
 * Récupère les objectifs d'épargne avec leur progression
 */
function getObjectifsEpargne($pdo, $compte_id) {
    $stmt = $pdo->prepare("
        SELECT 
            o.*,
            oe.cible,
            oe.pourcentage_allocation,
            COALESCE(SUM(a.montant_alloue), 0) AS montant_collecte,
            CASE 
                WHEN oe.cible > 0 THEN (COALESCE(SUM(a.montant_alloue), 0) / oe.cible * 100)
                ELSE 0
            END AS progression
        FROM objectif o
        JOIN objectif_epargne oe ON oe.objectif_id = o.id
        LEFT JOIN allocation a ON a.objectif_epargne_id = oe.objectif_id
        WHERE o.compte_id = ? AND o.type = 'epargne'
        GROUP BY o.id
        ORDER BY o.date_fin ASC
    ");
    $stmt->execute([$compte_id]);
    return $stmt->fetchAll();
}

/**
 * Récupère les objectifs de contrôle avec leur suivi
 */
function getObjectifsControle($pdo, $compte_id) {
    $stmt = $pdo->prepare("
        SELECT 
            o.*,
            ocd.cible_depenses,
            ocd.duree_mois,
            COALESCE(SUM(d.montant_reel), 0) AS montant_depense,
            CASE 
                WHEN ocd.cible_depenses > 0 THEN (COALESCE(SUM(d.montant_reel), 0) / ocd.cible_depenses * 100)
                ELSE 0
            END AS progression
        FROM objectif o
        JOIN objectif_controle_depenses ocd ON ocd.objectif_id = o.id
        LEFT JOIN mois m ON m.compte_id = o.compte_id 
            AND m.periode BETWEEN DATE_FORMAT(o.date_debut, '%Y-%m') 
            AND DATE_FORMAT(o.date_fin, '%Y-%m')
        LEFT JOIN depense d ON d.mois_id = m.id
        WHERE o.compte_id = ? AND o.type = 'controle_depenses'
        GROUP BY o.id
        ORDER BY o.date_fin ASC
    ");
    $stmt->execute([$compte_id]);
    return $stmt->fetchAll();
}

/**
 * Crée un nouveau mois avec report des revenus fixes
 */
function creerNouveauMois($pdo, $compte_id) {
    $mois_id = genererUUID();
    $periode = date('Y-m');
    
    // Créer le mois
    $stmt = $pdo->prepare("
        INSERT INTO mois (id, compte_id, periode, pourcentage_critique, pourcentage_moyen, pourcentage_leger, montant_reserve_imprevus, statut)
        VALUES (?, ?, ?, 60, 30, 10, 0, 'en_cours')
    ");
    $stmt->execute([$mois_id, $compte_id, $periode]);
    
    // Report des revenus fixes du mois précédent
    $stmt = $pdo->prepare("
        SELECT * FROM mois 
        WHERE compte_id = ? AND periode < ?
        ORDER BY periode DESC 
        LIMIT 1
    ");
    $stmt->execute([$compte_id, $periode]);
    $mois_precedent = $stmt->fetch();
    
    if ($mois_precedent) {
        $stmt = $pdo->prepare("
            SELECT r.* FROM revenu r
            JOIN source_revenu s ON s.id = r.source_revenu_id
            WHERE r.mois_id = ? AND s.type = 'regulier'
        ");
        $stmt->execute([$mois_precedent['id']]);
        $revenus_fixes = $stmt->fetchAll();
        
        foreach ($revenus_fixes as $rv) {
            $stmt = $pdo->prepare("
                INSERT INTO revenu (id, source_revenu_id, mois_id, montant, date_reception, commentaire)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                genererUUID(),
                $rv['source_revenu_id'],
                $mois_id,
                $rv['montant'],
                date('Y-m-d'),
                $rv['commentaire']
            ]);
        }
    }
    
    return getMoisEnCours($pdo, $compte_id);
}