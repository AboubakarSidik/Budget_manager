<?php
// ============================================================
// LANDING PAGE - DESIGN PRO
// ============================================================

require_once 'config.php';
$deja_visiteur = isset($_COOKIE['budget_manager_visited']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ============================================================
           RESET
           ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #ffffff;
            color: #0f172a;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .container {
            max-width: 1120px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* ============================================================
           SECTION DIVIDER
           ============================================================ */
        .divider {
            border: none;
            height: 2px;
            background: #e2e8f0;
            margin: 0;
            opacity: 1;
        }

        /* ============================================================
           HEADER
           ============================================================ */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            border-bottom: 2px solid #f1f5f9;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: #0f172a;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 800;
        }

        .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .logo-text span {
            color: #2563eb;
        }

        .nav-links {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .nav-links a {
            padding: 8px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .nav-links .btn-login {
            color: #475569;
        }

        .nav-links .btn-login:hover {
            background: #f1f5f9;
        }

        .nav-links .btn-signup {
            background: #0f172a;
            color: white;
        }

        .nav-links .btn-signup:hover {
            background: #1e293b;
            transform: translateY(-1px);
        }

        /* ============================================================
           HERO
           ============================================================ */
        .hero {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: center;
            padding: 80px 0 60px 0;
        }

        .hero-left h1 {
            font-size: 52px;
            font-weight: 900;
            line-height: 1.05;
            color: #0f172a;
            letter-spacing: -2px;
        }

        .hero-left h1 .highlight {
            background: linear-gradient(135deg, #2563eb, #0d9488);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-left p {
            font-size: 18px;
            color: #64748b;
            margin-top: 20px;
            max-width: 460px;
            line-height: 1.7;
        }

        .hero-actions {
            display: flex;
            gap: 14px;
            margin-top: 32px;
            flex-wrap: wrap;
        }

        .hero-actions .btn-primary {
            padding: 16px 36px;
            background: #0f172a;
            color: white;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .hero-actions .btn-primary:hover {
            background: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.2);
        }

        .hero-actions .btn-secondary {
            padding: 16px 36px;
            background: transparent;
            color: #0f172a;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.2s;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .hero-actions .btn-secondary:hover {
            background: #f8fafc;
            border-color: #2563eb;
        }

        /* --- MOCKUP --- */
        .mockup {
            background: #f8fafc;
            border-radius: 24px;
            padding: 32px;
            border: 2px solid #e2e8f0;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }

        .mockup:hover {
            border-color: #2563eb;
            box-shadow: 0 32px 100px rgba(37, 99, 235, 0.08);
        }

        .mockup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .mockup-header .dots {
            display: flex;
            gap: 6px;
        }

        .mockup-header .dots span {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }

        .mockup-header .dots .red { background: #ef4444; }
        .mockup-header .dots .yellow { background: #eab308; }
        .mockup-header .dots .green { background: #22c55e; }

        .mockup-header .label {
            font-size: 13px;
            color: #94a3b8;
            font-weight: 500;
        }

        .mockup-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .mockup-grid .stat {
            background: white;
            padding: 18px 20px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .mockup-grid .stat::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: transparent;
            transition: all 0.3s ease;
        }

        .mockup-grid .stat:hover::before {
            background: #2563eb;
        }

        .mockup-grid .stat:hover {
            border-color: #2563eb;
            transform: translateY(-2px);
        }

        .mockup-grid .stat .label {
            font-size: 11px;
            color: #94a3b8;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .mockup-grid .stat .value {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 4px;
        }

        .mockup-grid .stat .value.blue { color: #2563eb; }
        .mockup-grid .stat .value.green { color: #22c55e; }
        .mockup-grid .stat .value.orange { color: #eab308; }

        /* ============================================================
           STATS BANNER 
           ============================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
            padding: 40px 0;
            border-top: 2px solid #e2e8f0;
            border-bottom: 2px solid #e2e8f0;
            margin: 20px 0 50px 0;
        }

        .stats-row .stat-item {
            text-align: center;
            padding: 0 20px;
            border-right: 2px solid #f1f5f9;
        }

        .stats-row .stat-item:last-child {
            border-right: none;
        }

        .stats-row .stat-item .number {
            font-size: 32px;
            font-weight: 800;
            color: #0f172a;
        }

        .stats-row .stat-item .number .blue { color: #2563eb; }
        .stats-row .stat-item .number .green { color: #22c55e; }
        .stats-row .stat-item .number .orange { color: #eab308; }
        .stats-row .stat-item .number .purple { color: #8b5cf6; }

        .stats-row .stat-item .desc {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
        }

        /* ============================================================
           FEATURES
           ============================================================ */
        .features {
            padding: 40px 0 60px 0;
        }

        .features-header {
            text-align: center;
            max-width: 600px;
            margin: 0 auto 48px auto;
        }

        .features-header h2 {
            font-size: 34px;
            font-weight: 800;
            color: #0f172a;
        }

        .features-header p {
            font-size: 18px;
            color: #64748b;
            margin-top: 8px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        .feature-card {
            background: white;
            border-radius: 16px;
            padding: 32px 28px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            border-color: #2563eb;
            transform: translateY(-6px);
            box-shadow: 0 16px 60px rgba(0, 0, 0, 0.06);
        }

        .feature-card .icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 16px;
        }

        .feature-card .icon.blue { background: #eff6ff; color: #2563eb; }
        .feature-card .icon.green { background: #f0fdf4; color: #22c55e; }
        .feature-card .icon.orange { background: #fffbeb; color: #eab308; }
        .feature-card .icon.purple { background: #f3e8ff; color: #8b5cf6; }
        .feature-card .icon.red { background: #fef2f2; color: #ef4444; }
        .feature-card .icon.teal { background: #ecfdf5; color: #14b8a6; }

        .feature-card h4 {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
        }

        .feature-card p {
            font-size: 14px;
            color: #64748b;
            margin-top: 6px;
            line-height: 1.6;
        }

        /* ============================================================
           CTA
           ============================================================ */
        .cta {
            background: #0f172a;
            border-radius: 24px;
            padding: 56px 48px;
            text-align: center;
            margin: 40px 0 60px 0;
            border: 1px solid #334155;
        }

        .cta h3 {
            font-size: 32px;
            font-weight: 700;
            color: white;
        }

        .cta p {
            font-size: 18px;
            color: #94a3b8;
            margin-top: 8px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .cta .btn-cta {
            display: inline-block;
            margin-top: 24px;
            padding: 16px 48px;
            background: #2563eb;
            color: white;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.25);
        }

        .cta .btn-cta:hover {
            background: #1d4ed8;
            transform: translateY(-3px);
            box-shadow: 0 12px 48px rgba(37, 99, 235, 0.4);
        }

        /* ============================================================
           FOOTER
           ============================================================ */
        .footer {
            padding: 24px 0;
            border-top: 2px solid #e2e8f0;
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
        }

        .footer .heart {
            color: #ef4444;
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            .hero {
                grid-template-columns: 1fr;
                padding: 40px 0;
                gap: 40px;
            }

            .hero-left h1 {
                font-size: 34px;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }

            .stats-row .stat-item {
                border-right: none;
                border-bottom: 1px solid #f1f5f9;
                padding: 8px 0;
            }

            .stats-row .stat-item:last-child {
                border-bottom: none;
            }

            .features-grid {
                grid-template-columns: 1fr 1fr;
            }

            .header {
                flex-direction: column;
                gap: 16px;
            }

            .nav-links {
                width: 100%;
                justify-content: center;
            }

            .cta {
                padding: 32px 24px;
            }

            .cta h3 {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .hero-left h1 {
                font-size: 28px;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .mockup {
                padding: 16px;
            }

            .mockup-grid .stat .value {
                font-size: 17px;
            }

            .stats-row {
                grid-template-columns: 1fr 1fr;
            }

            .nav-links a {
                font-size: 13px;
                padding: 6px 14px;
            }

            .cta h3 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>

    <div class="container">

        <!-- ============================================================
             HEADER
             ============================================================ -->
        <header class="header">
            <div class="logo">
                <div class="logo-icon">B</div>
                <span class="logo-text">Budget<span>Manager</span></span>
            </div>
            <div class="nav-links">
                <a href="auth.php" class="btn-login">Se connecter</a>
                <a href="auth.php?onglet=inscription" class="btn-signup">S'inscrire</a>
            </div>
        </header>

        <!-- ============================================================
             HERO
             ============================================================ -->
        <section class="hero">
            <div class="hero-left">
                <h1>
                    Gardez le contrôle<br>
                    <span class="highlight">de votre argent</span>
                </h1>
                <p>
                    Suivez vos revenus, maîtrisez vos dépenses et atteignez 
                    vos objectifs d'épargne en toute simplicité.
                </p>
                <div class="hero-actions">
                    <a href="auth.php?onglet=inscription" class="btn-primary">
                        <i class="fas fa-rocket"></i> Commencer
                    </a>
                    <a href="#features" class="btn-secondary">
                        En savoir plus <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="mockup">
                <div class="mockup-header">
                    <span class="label">📊 Tableau de bord</span>
                    <div class="dots">
                        <span class="red"></span>
                        <span class="yellow"></span>
                        <span class="green"></span>
                    </div>
                </div>
                <div class="mockup-grid">
                    <div class="stat">
                        <div class="label">Budget</div>
                        <div class="value blue">525 000 FCFA</div>
                    </div>
                    <div class="stat">
                        <div class="label">Épargne</div>
                        <div class="value green">220 000 FCFA</div>
                    </div>
                    <div class="stat">
                        <div class="label">Dépenses</div>
                        <div class="value orange">275 000 FCFA</div>
                    </div>
                    <div class="stat">
                        <div class="label">Taux épargne</div>
                        <div class="value green">42%</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================
             STATS BANNER (PHRASES CORRIGÉES)
             ============================================================ -->
        <div class="stats-row">
            <div class="stat-item">
                <div class="number"><span class="blue">4</span>+</div>
                <div class="desc">Fonctionnalités</div>
            </div>
            <div class="stat-item">
                <div class="number"><span class="green">100</span>%</div>
                <div class="desc">Sécurisé</div>
            </div>
            <div class="stat-item">
                <div class="number"><span class="orange">0</span> FCFA</div>
                <div class="desc">Gratuit</div>
            </div>
            <div class="stat-item">
                <div class="number"><span class="purple">1</span> min</div>
                <div class="desc">Prêt en 1 minute</div>
            </div>
        </div>

        <!-- ============================================================
             FEATURES
             ============================================================ -->
        <section id="features" class="features">
            <div class="features-header">
                <h2>Des outils concrets</h2>
                <p>Pour une gestion sereine de votre budget au quotidien</p>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="icon blue"><i class="fas fa-coins"></i></div>
                    <h4>Suivez vos revenus</h4>
                    <p>Sources régulières, variables ou ponctuelles.</p>
                </div>
                <div class="feature-card">
                    <div class="icon green"><i class="fas fa-receipt"></i></div>
                    <h4>Planifiez vos dépenses</h4>
                    <p>Prévues ou effectuées, avec plafonds.</p>
                </div>
                <div class="feature-card">
                    <div class="icon orange"><i class="fas fa-piggy-bank"></i></div>
                    <h4>Épargnez intelligemment</h4>
                    <p>Calculez et allouez votre épargne.</p>
                </div>
                <div class="feature-card">
                    <div class="icon purple"><i class="fas fa-bullseye"></i></div>
                    <h4>Atteignez vos objectifs</h4>
                    <p>Suivez votre progression en temps réel.</p>
                </div>
                <div class="feature-card">
                    <div class="icon red"><i class="fas fa-chart-pie"></i></div>
                    <h4>Analysez vos finances</h4>
                    <p>Graphiques et indicateurs clairs.</p>
                </div>
                <div class="feature-card">
                    <div class="icon teal"><i class="fas fa-bell"></i></div>
                    <h4>Alertes personnalisées</h4>
                    <p>Soyez informé des dépassements.</p>
                </div>
            </div>
        </section>

        <!-- ============================================================
             CTA
             ============================================================ -->
        <div class="cta">
            <h3>Commencez à épargner dès aujourd'hui</h3>
            <p>Rejoignez des milliers d'utilisateurs qui ont déjà pris le contrôle de leurs finances.</p>
            <a href="auth.php?onglet=inscription" class="btn-cta">
                Créer mon compte gratuitement
            </a>
        </div>

        <!-- ============================================================
             FOOTER
             ============================================================ -->
        <footer class="footer">
            &copy; 2026 Budget Manager — Développé avec <span class="heart">❤</span>
        </footer>

    </div>

</body>
</html>