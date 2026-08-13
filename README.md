# Budget Manager

Application web de gestion de budget personnel développée en **PHP** avec base de données **MySQL**. Elle permet de suivre ses revenus, ses dépenses, son épargne et ses objectifs financiers, mois par mois.

## Fonctionnalités

- **Authentification** : inscription, connexion, mot de passe oublié avec réinitialisation par email
- **Dashboard** : vue d'ensemble du mois en cours (revenus, dépenses, épargne, alertes)
- **Budget mensuel** : gestion du mois et des priorités de dépenses
- **Revenus** : ajout et suivi des sources de revenus du mois
- **Dépenses** : gestion des dépenses prévues et réelles, réparties par catégories
- **Catégories** : création de catégories personnalisées avec plafonds de dépenses
- **Épargne** : calcul automatique de l'épargne réelle réalisée chaque mois
- **Objectifs d'épargne** : définition d'objectifs et suivi de la progression, avec allocation mensuelle
- **Imprévus** : gestion d'une réserve financière pour les dépenses imprévues
- **Alertes** : notifications et suggestions automatiques (dépassement de plafond, objectifs, etc.)
- **Statistiques** : graphiques et indicateurs sur l'évolution du budget
- **Historique** : consultation des mois précédents
- **Mon compte** : gestion du profil, sécurité, suppression de compte

## Stack technique

- **Backend** : PHP (procédural, orienté pages)
- **Base de données** : MySQL /(via PDO, requêtes préparées)
- **Frontend** : HTML, CSS, JavaScript 
- **Emails** : [PHPMailer](https://github.com/PHPMailer/PHPMailer) via SMTP (Gmail) — utilisé pour la réinitialisation de mot de passe
- **Gestion des dépendances** : Composer

### Structure de la base de données

Le schéma (`database.sql`) contient notamment les tables suivantes :

| Table | Rôle |
|---|---|
| `utilisateur` / `compte` | Comptes utilisateurs |
| `mois` | Un enregistrement par mois budgétaire |
| `revenu` / `source_revenu` | Revenus et leurs sources |
| `depense` / `ligne_depense` | Dépenses et leur détail |
| `categorie` | Catégories de dépenses et plafonds |
| `objectif_epargne` / `allocation` | Objectifs d'épargne et allocations mensuelles |
| `objectif_controle_depenses` | Objectifs de maîtrise des dépenses |
| `imprevu` / `utilisation_imprevu` | Réserve pour imprévus et son utilisation |
| `notification` | Alertes générées pour l'utilisateur |
| `reset_token` / `reset_log` | Réinitialisation de mot de passe |

Plusieurs **vues SQL** (`vue_budget_mois`, `vue_depenses_categorie`, `vue_epargne_mois`, `vue_objectif_controle_suivi`, `vue_objectif_epargne_progression`) sont utilisées pour simplifier les calculs et l'agrégation des données affichées dans l'application.

## Installation en local

### Prérequis

- PHP 8+ avec l'extension PDO MySQL
- MySQL ou MariaDB
- [Composer](https://getcomposer.org/)
- Un serveur web (Apache/XAMPP, ou le serveur intégré de PHP)

### Étapes

1. **Cloner le projet**
   ```bash
   git clone https://github.com/TON-PSEUDO/budget-manager.git
   cd budget-manager
   ```

2. **Installer les dépendances PHP**
   ```bash
   composer install
   ```

3. **Créer la base de données**

   Créez une base MySQL nommée `budget_manager` puis importez le schéma :
   ```bash
   mysql -u root -p budget_manager < database.sql
   ```

4. **Configurer l'application**

   Copiez le fichier d'exemple et renseignez vos propres valeurs :
   ```bash
   cp config.example.php config.php
   ```

   Éditez `config.php` avec :
   - vos identifiants de base de données (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`)
   - l'URL de votre site (`SITE_URL`)
   - vos identifiants SMTP si vous voulez activer l'envoi réel d'emails (`SMTP_USERNAME`, `SMTP_PASSWORD`, un [mot de passe d'application Gmail](https://myaccount.google.com/apppasswords), pas votre mot de passe Gmail classique)

   ⚠️ **`config.php` ne doit jamais être commité sur Git** (il est déjà listé dans `.gitignore`).

   En mode `DEBUG_MODE = true`, les liens de réinitialisation de mot de passe sont affichés directement à l'écran plutôt qu'envoyés par email — pratique pour tester sans configurer de SMTP.

5. **Lancer l'application**

   Avec le serveur intégré de PHP :
   ```bash
   php -S localhost:8000
   ```
   Puis ouvrez [http://localhost:8000](http://localhost:8000).

   Ou placez le dossier dans `htdocs` (XAMPP) / `www` et accédez-y via Apache.

## Sécurité

- Mots de passe hashés (jamais stockés en clair)
- Requêtes SQL préparées (protection contre les injections SQL)
- `.htaccess` bloquant l'accès direct aux fichiers PHP sensibles (`config.php`, `functions.php`, `session_init.php`, `functions_mail.php`)
- En-têtes de sécurité (`X-Frame-Options`, `X-XSS-Protection`, `X-Content-Type-Options`)
- Fichiers sensibles (`config.php`, `*.sql`, `.env`) exclus du dépôt Git

## Licence

Projet personnel — usage libre.
