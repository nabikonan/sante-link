// DiabSuivi — Scripts globaux v2

document.addEventListener('DOMContentLoaded', () => {

    // ── Auto-fermeture des messages flash après 4s ────────────
    document.querySelectorAll('.alert').forEach(el => {
        setTimeout(() => {
            el.style.transition = 'opacity .5s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 500);
        }, 4000);
    });

    // ── Radio role-btn : activer la classe .active ────────────
    document.querySelectorAll('.role-btn input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', () => {
            document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
            radio.closest('.role-btn').classList.add('active');
        });
    });

    // ── Confirmation avant toute action destructrice ──────────
    // (complémente les onclick="return confirm(...)" inline)
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', e => {
            if (!confirm(el.dataset.confirm)) e.preventDefault();
        });
    });

    // ── Marquer les liens nav actifs ──────────────────────────
    const path = window.location.pathname;
    document.querySelectorAll('.nav-link').forEach(a => {
        if (a.getAttribute('href') && path.startsWith(a.getAttribute('href'))) {
            a.style.background = 'var(--green-l)';
            a.style.color      = 'var(--green-d)';
            a.style.fontWeight = '600';
        }
    });

    // ── Désactiver le bouton submit après envoi (évite doublons) ─
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled     = true;
                btn.style.opacity = '.6';
                btn.textContent  = 'Enregistrement…';
            }
        });
    });

});
