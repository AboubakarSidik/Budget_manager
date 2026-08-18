// ============================================================
// BUDGET MANAGER - FONCTIONS GLOBALES
// ============================================================

/**
 * Afficher/masquer le mot de passe
 */
function togglePassword(fieldId) {
    const input = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId + '-icon');
    if (!input || !icon) return;
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

/**
 * Vérification de la force du mot de passe
 */
function checkPasswordStrength(password) {
    const rules = [
        { id: 'hint-length', test: password.length >= 8 },
        { id: 'hint-upper', test: /[A-Z]/.test(password) },
        { id: 'hint-lower', test: /[a-z]/.test(password) },
        { id: 'hint-digit', test: /[0-9]/.test(password) },
        { id: 'hint-special', test: /[^a-zA-Z0-9]/.test(password) }
    ];
    rules.forEach(rule => {
        const el = document.getElementById(rule.id);
        if (el) {
            if (rule.test) {
                el.innerHTML = '<i class="fas fa-check-circle" style="color: #22c55e;"></i> ' + el.textContent.replace(/^[^ ]+ /, '');
            } else {
                el.innerHTML = '<i class="fas fa-circle" style="font-size: 8px; color: #94a3b8;"></i> ' + el.textContent.replace(/^[^ ]+ /, '');
            }
        }
    });
}

/**
 * Formatage d'un montant en FCFA
 */
function formatFCFA(montant) {
    return new Intl.NumberFormat('fr-FR', {
        style: 'decimal',
        maximumFractionDigits: 0,
        minimumFractionDigits: 0
    }).format(montant) + ' FCFA';
}

/**
 * Affiche un message de notification (toast)
 */
function showToast(message, type = 'info') {
    const colors = {
        info: '#2563eb',
        success: '#22c55e',
        warning: '#eab308',
        error: '#ef4444'
    };
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        bottom: 24px;
        right: 24px;
        padding: 14px 24px;
        background: ${colors[type] || colors.info};
        color: white;
        border-radius: 12px;
        font-family: 'Inter', sans-serif;
        font-size: 14px;
        font-weight: 500;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        z-index: 9999;
        animation: slideUp 0.4s ease;
        max-width: 400px;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

const styleToast = document.createElement('style');
styleToast.textContent = `
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
`;
document.head.appendChild(styleToast);

// ============================================================
// MODALES PERSONNALISÉES
// ============================================================

// 1. MODALE SUPPRESSION (générique)
function ouvrirModalSuppression(id, nom, detail) {
    const modal = document.getElementById('modalSuppression');
    if (!modal) {
        console.error('Modal suppression non trouvée');
        return;
    }
    document.getElementById('modalSuppressionMessage').textContent = 'Êtes-vous sûr de vouloir supprimer "' + nom + '" ?';
    document.getElementById('modalSuppressionDetail').textContent = detail || 'Cette action est irréversible.';
    document.getElementById('lienSuppression').href = '?supprimer=' + id;
    modal.classList.add('active');
}

function fermerModalSuppression() {
    const modal = document.getElementById('modalSuppression');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalSuppression');
    if (modal && e.target === modal) fermerModalSuppression();
});

// 2. MODALE SOURCE (compte.php)
function ouvrirModalSource(id, libelle, type) {
    const modal = document.getElementById('modalSource');
    if (!modal) return;
    document.getElementById('modalSourceMessage').textContent = 'Êtes-vous sûr de vouloir supprimer la source "' + libelle + '" ?';
    document.getElementById('modalSourceDetail').textContent = 'Type : ' + type.charAt(0).toUpperCase() + type.slice(1) + ' - Les revenus associés resteront.';
    document.getElementById('lienSuppressionSource').href = '?supprimer_source=' + id + '&onglet=profil';
    modal.classList.add('active');
}

function fermerModalSource() {
    const modal = document.getElementById('modalSource');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalSource');
    if (modal && e.target === modal) fermerModalSource();
});

// 3. MODALE CATEGORIE (categories.php)
function ouvrirModalCategorie(id, nom) {
    const modal = document.getElementById('modalCategorie');
    if (!modal) return;
    document.getElementById('modalCategorieMessage').textContent = 'Êtes-vous sûr de vouloir supprimer la catégorie "' + nom + '" ?';
    document.getElementById('modalCategorieDetail').textContent = 'Cette action est irréversible.';
    document.getElementById('lienSuppressionCategorie').href = '?supprimer=' + id;
    modal.classList.add('active');
}

function fermerModalCategorie() {
    const modal = document.getElementById('modalCategorie');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalCategorie');
    if (modal && e.target === modal) fermerModalCategorie();
});

// 4. MODALE MODIFIER CATEGORIE (categories.php)
function ouvrirModalModifierCategorie(id, nom, plafond) {
    const modal = document.getElementById('modalModifierCategorie');
    if (!modal) return;
    document.getElementById('modif_categorie_id').value = id;
    document.getElementById('modif_nom').value = nom;
    document.getElementById('modif_montant_plafond').value = plafond;
    modal.classList.add('active');
}

function fermerModalModifierCategorie() {
    const modal = document.getElementById('modalModifierCategorie');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalModifierCategorie');
    if (modal && e.target === modal) fermerModalModifierCategorie();
});

// 5. MODALE NOTIFICATION (alertes.php)
function ouvrirModalNotif(id, message) {
    const modal = document.getElementById('modalNotif');
    if (!modal) return;
    document.getElementById('modalNotifMessage').textContent = 'Êtes-vous sûr de vouloir supprimer cette notification ?';
    document.getElementById('modalNotifDetail').textContent = message || 'Cette action est irréversible.';
    document.getElementById('lienSuppressionNotif').href = '?supprimer=' + id;
    modal.classList.add('active');
}

function fermerModalNotif() {
    const modal = document.getElementById('modalNotif');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalNotif');
    if (modal && e.target === modal) fermerModalNotif();
});

// 6. MODALE SUPPRESSION TOUTES NOTIFICATIONS
function ouvrirModalSupprimerToutes() {
    const modal = document.getElementById('modalSupprimerToutes');
    if (!modal) return;
    modal.classList.add('active');
}

function fermerModalSupprimerToutes() {
    const modal = document.getElementById('modalSupprimerToutes');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalSupprimerToutes');
    if (modal && e.target === modal) fermerModalSupprimerToutes();
});

// 7. MODALE REVENU (revenus.php)
function ouvrirModalRevenu(id, nom, montant) {
    const modal = document.getElementById('modalSuppressionRevenu');
    if (!modal) return;
    document.getElementById('modalRevenuMessage').textContent = 'Êtes-vous sûr de vouloir supprimer le revenu "' + nom + '" ?';
    document.getElementById('modalRevenuDetail').textContent = 'Montant : ' + montant + ' - Cette action est irréversible.';
    document.getElementById('lienSuppressionRevenu').href = '?supprimer=' + id;
    modal.classList.add('active');
}

function fermerModalRevenu() {
    const modal = document.getElementById('modalSuppressionRevenu');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalSuppressionRevenu');
    if (modal && e.target === modal) fermerModalRevenu();
});

// 8. MODALE IMPREVU (imprevus.php)
function ouvrirModalImprevu(id, libelle, montant) {
    const modal = document.getElementById('modalSuppressionImprevu');
    if (!modal) return;
    document.getElementById('modalImprevuMessage').textContent = 'Êtes-vous sûr de vouloir supprimer l\'imprévu "' + libelle + '" ?';
    document.getElementById('modalImprevuDetail').textContent = 'Montant : ' + montant + ' - Cette action est irréversible.';
    document.getElementById('lienSuppressionImprevu').href = '?supprimer=' + id;
    modal.classList.add('active');
}

function fermerModalImprevu() {
    const modal = document.getElementById('modalSuppressionImprevu');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalSuppressionImprevu');
    if (modal && e.target === modal) fermerModalImprevu();
});

// 9. MODALE OBJECTIF (objectifs.php)
function ouvrirModalObjectif(id, nom, collecte) {
    const modal = document.getElementById('modalSuppressionObjectif');
    if (!modal) return;
    document.getElementById('modalObjectifMessage').textContent = 'Êtes-vous sûr de vouloir supprimer l\'objectif "' + nom + '" ?';
    document.getElementById('modalObjectifDetail').textContent = 'Montant collecté : ' + collecte + ' - Les montants alloués seront reversés en épargne libre.';
    document.getElementById('lienSuppressionObjectif').href = '?supprimer=' + id;
    modal.classList.add('active');
}

function fermerModalObjectif() {
    const modal = document.getElementById('modalSuppressionObjectif');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalSuppressionObjectif');
    if (modal && e.target === modal) fermerModalObjectif();
});

// 10. UTILITAIRE : Récupérer le filtre dans l'URL
function getFilterParam() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('filtre') || 'toutes';
}

// ============================================================
// GESTION DU THÈME AVEC localStorage (SYNCHRONISÉ)
// ============================================================

// Appliquer le thème
function appliquerTheme(theme) {
    const body = document.body;
    const icon = document.getElementById('themeIcon');
    const label = document.getElementById('themeLabel');
    
    if (theme === 'sombre') {
        body.classList.add('theme-sombre');
        if (icon) icon.className = 'fas fa-moon';
        if (label) label.textContent = 'Sombre';
    } else {
        body.classList.remove('theme-sombre');
        if (icon) icon.className = 'fas fa-sun';
        if (label) label.textContent = 'Clair';
    }
    localStorage.setItem('theme', theme);
}

// Récupérer le thème
function getTheme() {
    return localStorage.getItem('theme') || 'clair';
}

// Basculer le thème
function toggleTheme() {
    const actuel = getTheme();
    const nouveau = actuel === 'clair' ? 'sombre' : 'clair';
    appliquerTheme(nouveau);
}

// Charger le thème au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    const theme = getTheme();
    appliquerTheme(theme);
});

// Écouter les changements dans les autres onglets
window.addEventListener('storage', function(e) {
    if (e.key === 'theme') {
        appliquerTheme(e.newValue);
    }
});

// Charger le thème AVANT l'affichage complet (anti-flash)
(function() {
    const theme = localStorage.getItem('theme') || 'clair';
    if (theme === 'sombre') {
        document.documentElement.classList.add('theme-sombre');
        document.body.classList.add('theme-sombre');
    }
})();