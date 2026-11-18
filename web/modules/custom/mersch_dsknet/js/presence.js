/**
 * @file
 * État de présence dans l'annuaire, relu chaque minute (US-36, US-37, D-044).
 *
 * Chargé seulement pour les agents connectés. Relecture suspendue tant que
 * l'onglet est caché, reprise dès qu'il redevient visible.
 */
((Drupal, drupalSettings, once) => {
  const libelles = () => ({
    present: Drupal.t('Present'),
    absent: Drupal.t('Absent'),
    deplacement: Drupal.t('Business trip'),
  });

  Drupal.behaviors.merschPresence = {
    attach(context) {
      const reglages = drupalSettings.merschPresence || {};
      once('mersch-presence', '[data-mersch-annuaire]', context).forEach((annuaire) => {
        if (!reglages.url) {
          return;
        }
        const noms = libelles();
        const avis = annuaire.querySelector('[data-mersch-presence-avis]');
        let minuterie = null;

        const afficher = (donnees) => {
          const etats = donnees.etats || {};
          annuaire.querySelectorAll('[data-mersch-presence]').forEach((pastille) => {
            const etat = etats[pastille.dataset.merschPresence];
            pastille.hidden = !noms[etat];
            if (noms[etat]) {
              pastille.className = `mersch-presence mersch-presence--${etat}`;
              pastille.textContent = noms[etat];
            }
          });
          if (avis) {
            avis.hidden = donnees.disponible !== false;
          }
        };

        const lire = () => {
          fetch(reglages.url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((reponse) => (reponse.ok ? reponse.json() : { etats: {}, disponible: false }))
            .catch(() => ({ etats: {}, disponible: false }))
            .then(afficher);
        };

        const demarrer = () => {
          lire();
          clearInterval(minuterie);
          minuterie = setInterval(lire, (reglages.intervalle || 60) * 1000);
        };

        document.addEventListener('visibilitychange', () => {
          if (document.hidden) {
            clearInterval(minuterie);
          }
          else {
            demarrer();
          }
        });
        demarrer();
      });
    },
  };
})(Drupal, drupalSettings, once);
