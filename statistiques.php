<?php
// ============================================================
// STATISTIQUES - GRAPHIQUES ET INDICATEURS
// ============================================================

require_once 'config.php';
require_once 'session_init.php';
require_once 'functions.php';

if (!estConnecte()) {
    rediriger('auth.php');
}

$compte_id = $_SESSION['utilisateur_id'];
$mois_courant = getMoisEnCours($pdo, $compte_id);

if (!$mois_courant) {
    $_SESSION['message_info'] = "Veuillez d'abord créer votre budget.";
    rediriger('budget.php');
}

$mois_id = $mois_courant['id'];
$erreur = '';
$succes = '';
$info = '';

// Récupérer le nombre de notifications non lues
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE compte_id = ? AND etat = 'non_lue'");
$stmt->execute([$compte_id]);
$nb_non_lues = $stmt->fetchColumn();

// ============================================================
// RÉCUPÉRATION DES DONNÉES POUR LES GRAPHIQUES
// ============================================================

// 1. Dépenses par catégorie (camembert)
$stmt = $pdo->prepare("
    SELECT c.nom, COALESCE(SUM(d.montant_reel), 0) as total
    FROM categorie c
    LEFT JOIN ligne_depense ld ON ld.categorie_id = c.id
    LEFT JOIN depense d ON d.ligne_depense_id = ld.id AND d.mois_id = ?
    WHERE c.compte_id = ?
    GROUP BY c.id
    HAVING total > 0
    ORDER BY total DESC
");
$stmt->execute([$mois_id, $compte_id]);
$depenses_categorie = $stmt->fetchAll();

// 2. Revenus par source (camembert)
$stmt = $pdo->prepare("
    SELECT s.libelle, COALESCE(SUM(r.montant), 0) as total
    FROM source_revenu s
    LEFT JOIN revenu r ON r.source_revenu_id = s.id AND r.mois_id = ?
    WHERE s.compte_id = ?
    GROUP BY s.id
    HAVING total > 0
    ORDER BY total DESC
");
$stmt->execute([$mois_id, $compte_id]);
$revenus_source = $stmt->fetchAll();

// 3. Évolution des dépenses et de l'épargne (6 derniers mois)
$stmt = $pdo->prepare("
    SELECT m.periode,
           (SELECT COALESCE(SUM(r.montant), 0) FROM revenu r WHERE r.mois_id = m.id) as budget_total,
           (SELECT COALESCE(SUM(d.montant_reel), 0) FROM depense d WHERE d.mois_id = m.id AND d.montant_reel IS NOT NULL) as depenses_reelles,
           (SELECT COALESCE(SUM(ui.montant), 0) FROM utilisation_imprevu ui WHERE ui.mois_id = m.id) as imprevus_utilises
    FROM mois m
    WHERE m.compte_id = ?
    ORDER BY m.periode DESC
    LIMIT 6
");
$stmt->execute([$compte_id]);
$evolution = $stmt->fetchAll();
$evolution = array_reverse($evolution);

// 4. Prévision vs Réel par catégorie (barres)
$stmt = $pdo->prepare("
    SELECT c.nom,
           COALESCE(SUM(ld.montant_prevu), 0) as total_prevu,
           COALESCE(SUM(d.montant_reel), 0) as total_reel
    FROM categorie c
    LEFT JOIN ligne_depense ld ON ld.categorie_id = c.id
    LEFT JOIN depense d ON d.ligne_depense_id = ld.id AND d.mois_id = ?
    WHERE c.compte_id = ?
    GROUP BY c.id
    HAVING total_prevu > 0 OR total_reel > 0
    ORDER BY c.nom
");
$stmt->execute([$mois_id, $compte_id]);
$prevu_reel = $stmt->fetchAll();

// 5. Indicateurs clés
$budget_total = getBudgetTotal($pdo, $mois_id);
$depenses_reelles = getDepensesReelles($pdo, $mois_id);
$imprevus_utilises = getImprevusUtilises($pdo, $mois_id);
$epargne_reelle = $budget_total - $depenses_reelles - $imprevus_utilises;
$taux_epargne = ($budget_total > 0) ? round(($epargne_reelle / $budget_total) * 100, 1) : 0;

// 6. Total des dépenses prévues et effectuées pour le taux de réalisation
$stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_prevu), 0) FROM depense WHERE mois_id = ?");
$stmt->execute([$mois_id]);
$total_prevues = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_reel), 0) FROM depense WHERE mois_id = ? AND montant_reel IS NOT NULL");
$stmt->execute([$mois_id]);
$total_effectuees = $stmt->fetchColumn();

// Taux de réalisation global
$taux_realisation_global = 0;
if ($total_prevues > 0) {
    $taux_realisation_global = round(($total_effectuees / $total_prevues) * 100, 1);
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE compte_id = ? AND etat = 'non_lue'");
$stmt->execute([$compte_id]);
$nb_alertes = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM objectif WHERE compte_id = ? AND statut = 'atteint'");
$stmt->execute([$compte_id]);
$nb_objectifs_atteints = $stmt->fetchColumn();

$couleurs = ['#2563eb', '#22c55e', '#eab308', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#84cc16'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Manager - Statistiques</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; }
        .app-container { max-width: 1200px; margin: 0 auto; padding: 16px 20px; }
        
        .app-header {
            background: #ffffff;
            border-radius: 16px;
            padding: 12px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
        }
        .app-header .top-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .app-header .logo h1 { font-size: 20px; font-weight: 700; background: linear-gradient(135deg, #2563eb, #0d9488); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .app-header .logo h1 i { background: none; -webkit-text-fill-color: #2563eb; }
        .app-header .user-info { display: flex; align-items: center; gap: 16px; }
        .app-header .user-info .user-name { font-weight: 500; color: #0f172a; font-size: 14px; }
        .app-header .user-info .logout-link { color: #ef4444; text-decoration: none; font-weight: 500; font-size: 13px; padding: 6px 12px; border-radius: 8px; transition: all 0.2s; }
        .app-header .user-info .logout-link:hover { background: #fef2f2; }
        
        .app-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 8px 0 4px 0;
            border-top: 1px solid #f1f5f9;
            margin-top: 10px;
        }
        .app-nav a {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 16px;
            border-radius: 12px;
            color: #94a3b8;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            gap: 2px;
            min-width: 56px;
        }
        .app-nav a i { font-size: 20px; transition: all 0.3s ease; }
        .app-nav a .nav-label { font-size: 10px; font-weight: 500; opacity: 0; transform: translateY(-4px); transition: all 0.3s ease; color: #64748b; }
        .app-nav a:hover .nav-label { opacity: 1; transform: translateY(0); }
        .app-nav a:hover { background: rgba(37,99,235,0.08); color: #2563eb; }
        .app-nav a:hover i { transform: translateY(-2px) scale(1.05); }
        .app-nav a.active { background: #2563eb; color: #ffffff; box-shadow: 0 4px 16px rgba(37,99,235,0.3); }
        .app-nav a.active .nav-label { opacity: 1; transform: translateY(0); color: #ffffff; }
        .app-nav a.active i { color: #ffffff; }
        .app-nav a .badge { position: absolute; top: 0px; right: 4px; background: #ef4444; color: white; font-size: 10px; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(239,68,68,0.4); }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin: 20px 0 16px 0; flex-wrap: wrap; gap: 12px; }
        .page-header h2 { font-size: 22px; font-weight: 700; color: #0f172a; }
        .page-header h2 i { color: #2563eb; margin-right: 10px; }
        
        .message { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
        .message.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .message.success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .message.info { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        
        .card { background: white; border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
        .card h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin-bottom: 16px; }
        .card h3 i { color: #2563eb; margin-right: 8px; }
        
        .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-box { background: white; border-radius: 16px; padding: 20px; border: 1px solid #e2e8f0; }
        .chart-box h4 { font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 12px; }
        .chart-box canvas { max-height: 280px; max-width: 100%; }
        
        .indicator-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .indicator-item { background: white; border-radius: 12px; padding: 14px 16px; border: 1px solid #e2e8f0; text-align: center; }
        .indicator-item .label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .indicator-item .value { font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 2px; }
        .indicator-item .value.green { color: #22c55e; }
        .indicator-item .value.blue { color: #2563eb; }
        .indicator-item .value.orange { color: #eab308; }
        .indicator-item .value.red { color: #ef4444; }
        
        @media (max-width: 768px) {
            .app-header .top-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .app-header .user-info { justify-content: space-between; }
            .app-nav { gap: 2px; justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .app-nav a { padding: 6px 12px; min-width: 44px; }
            .app-nav a i { font-size: 16px; }
            .app-nav a .nav-label { font-size: 8px; }
            .chart-grid { grid-template-columns: 1fr; }
            .indicator-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) { .app-container { padding: 10px 12px; } .app-header { padding: 10px 16px; } .card { padding: 16px; } }


        /* ============================================================
           MODE SOMBRE (genere automatiquement)
           ============================================================ */

body.theme-sombre {
    background: #334155;
}

body.theme-sombre .app-header {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .app-header .logo h1 i {
    -webkit-text-fill-color: #60a5fa;
}

body.theme-sombre .app-header .user-info .user-name {
    color: #f1f5f9;
}

body.theme-sombre .app-header .user-info .logout-link {
    color: #f87171;
}

body.theme-sombre .app-header .user-info .logout-link:hover {
    background: rgba(239, 68, 68, 0.12);
}

body.theme-sombre .app-nav {
    border-top: 1px solid #334155;
}

body.theme-sombre .app-nav a .nav-label {
    color: #94a3b8;
}

body.theme-sombre .app-nav a:hover {
    color: #60a5fa;
}

body.theme-sombre .app-nav a.active {
    color: #f1f5f9;
}

body.theme-sombre .app-nav a.active .nav-label {
    color: #f1f5f9;
}

body.theme-sombre .app-nav a.active i {
    color: #f1f5f9;
}

body.theme-sombre .page-header h2 {
    color: #f1f5f9;
}

body.theme-sombre .page-header h2 i {
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

body.theme-sombre .message.info {
    background: rgba(37, 99, 235, 0.12);
    color: #60a5fa;
    border: 1px solid rgba(37, 99, 235, 0.3);
}

body.theme-sombre .card {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .card h3 {
    color: #f1f5f9;
}

body.theme-sombre .card h3 i {
    color: #60a5fa;
}

body.theme-sombre .chart-box {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .chart-box h4 {
    color: #f1f5f9;
}

body.theme-sombre .indicator-item {
    background: #1e293b;
    border: 1px solid #334155;
}

body.theme-sombre .indicator-item .label {
    color: #94a3b8;
}

body.theme-sombre .indicator-item .value {
    color: #f1f5f9;
}

body.theme-sombre .indicator-item .value.green {
    color: #4ade80;
}

body.theme-sombre .indicator-item .value.blue {
    color: #60a5fa;
}

body.theme-sombre .indicator-item .value.orange {
    color: #fbbf24;
}

body.theme-sombre .indicator-item .value.red {
    color: #f87171;
}
</style>
</head>
<body>

<div class="app-container">
    
    <?php require_once 'header.php'; ?>
    
    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div class="page-header">
        <h2><i class="fas fa-chart-pie"></i> Statistiques</h2>
        <span style="font-size:14px; color:#64748b;">
            <?= date('F Y', strtotime($mois_courant['periode'] . '-01')) ?>
        </span>
    </div>
    
    <!-- ============================================================
         INDICATEURS CLÉS
         ============================================================ -->
    <div class="indicator-grid">
        <div class="indicator-item">
            <div class="label">💰 Budget total</div>
            <div class="value blue"><?= formatFCFA($budget_total) ?></div>
        </div>
        <div class="indicator-item">
            <div class="label">📊 Dépenses</div>
            <div class="value orange"><?= formatFCFA($depenses_reelles) ?></div>
        </div>
        <div class="indicator-item">
            <div class="label">🏦 Épargne</div>
            <div class="value green"><?= formatFCFA($epargne_reelle) ?></div>
        </div>
        <div class="indicator-item">
            <div class="label">📈 Taux d'épargne</div>
            <div class="value <?= $taux_epargne >= 20 ? 'green' : ($taux_epargne >= 10 ? 'orange' : 'red') ?>">
                <?= $taux_epargne ?>%
            </div>
        </div>
        <div class="indicator-item">
            <div class="label">📊 Taux de réalisation</div>
            <div class="value <?= $taux_realisation_global <= 100 ? 'green' : ($taux_realisation_global <= 120 ? 'orange' : 'red') ?>">
                <?= $taux_realisation_global ?>%
            </div>
        </div>
        <div class="indicator-item">
            <div class="label">🔔 Alertes</div>
            <div class="value <?= $nb_alertes > 0 ? 'red' : 'green' ?>">
                <?= $nb_alertes ?>
            </div>
        </div>
        <div class="indicator-item">
            <div class="label">🎯 Objectifs atteints</div>
            <div class="value blue"><?= $nb_objectifs_atteints ?></div>
        </div>
    </div>
    
    <!-- ============================================================
         GRAPHIQUES
         ============================================================ -->
    <div class="chart-grid">
        <!-- Camembert Dépenses -->
        <div class="chart-box">
            <h4><i class="fas fa-utensils" style="color:#22c55e;"></i> Dépenses par catégorie</h4>
            <canvas id="chartDepenses"></canvas>
        </div>
        
        <!-- Camembert Revenus -->
        <div class="chart-box">
            <h4><i class="fas fa-coins" style="color:#2563eb;"></i> Revenus par source</h4>
            <canvas id="chartRevenus"></canvas>
        </div>
    </div>
    
    <div class="chart-grid">
        <!-- Évolution -->
        <div class="chart-box">
            <h4><i class="fas fa-chart-line" style="color:#8b5cf6;"></i> Évolution (6 mois)</h4>
            <canvas id="chartEvolution"></canvas>
        </div>
        
        <!-- Prévision vs Réel -->
        <div class="chart-box">
            <h4><i class="fas fa-arrows-left-right" style="color:#eab308;"></i> Prévision vs Réel</h4>
            <canvas id="chartPrevuReel"></canvas>
        </div>
    </div>
    
    <!-- ============================================================
         FOOTER
         ============================================================ -->
    <footer style="margin-top:40px; padding:16px 0; text-align:center; color:#94a3b8; font-size:13px; border-top:1px solid #e2e8f0;">
        &copy; <?= date('Y') ?> Budget Manager - Gérez votre budget personnel simplement
    </footer>
    
</div>

<script>
<?php if (count($depenses_categorie) > 0): ?>
const ctxDepenses = document.getElementById('chartDepenses').getContext('2d');
new Chart(ctxDepenses, {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_column($depenses_categorie, 'nom')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($depenses_categorie, 'total')) ?>,
            backgroundColor: <?= json_encode(array_slice($couleurs, 0, count($depenses_categorie))) ?>
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11 } } }
        }
    }
});
<?php endif; ?>

<?php if (count($revenus_source) > 0): ?>
const ctxRevenus = document.getElementById('chartRevenus').getContext('2d');
new Chart(ctxRevenus, {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_column($revenus_source, 'libelle')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($revenus_source, 'total')) ?>,
            backgroundColor: <?= json_encode(array_slice($couleurs, 0, count($revenus_source))) ?>
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11 } } }
        }
    }
});
<?php endif; ?>

<?php if (count($evolution) > 1): ?>
const ctxEvolution = document.getElementById('chartEvolution').getContext('2d');
new Chart(ctxEvolution, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(function($e) { return date('M Y', strtotime($e['periode'] . '-01')); }, $evolution)) ?>,
        datasets: [
            {
                label: 'Dépenses',
                data: <?= json_encode(array_column($evolution, 'depenses_reelles')) ?>,
                borderColor: '#eab308',
                backgroundColor: 'rgba(234, 179, 8, 0.1)',
                fill: true,
                tension: 0.3,
                pointRadius: 4
            },
            {
                label: 'Épargne',
                data: <?= json_encode(array_map(function($e) { return $e['budget_total'] - $e['depenses_reelles'] - $e['imprevus_utilises']; }, $evolution)) ?>,
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                fill: true,
                tension: 0.3,
                pointRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11 } } }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});
<?php endif; ?>

<?php if (count($prevu_reel) > 0): ?>
const ctxPrevuReel = document.getElementById('chartPrevuReel').getContext('2d');
new Chart(ctxPrevuReel, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($prevu_reel, 'nom')) ?>,
        datasets: [
            {
                label: 'Prévu',
                data: <?= json_encode(array_column($prevu_reel, 'total_prevu')) ?>,
                backgroundColor: '#2563eb',
                borderRadius: 4
            },
            {
                label: 'Réel',
                data: <?= json_encode(array_column($prevu_reel, 'total_reel')) ?>,
                backgroundColor: '#eab308',
                borderRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11 } } }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});
<?php endif; ?>
</script>

<script src="js/app.js"></script>
</body>
</html>