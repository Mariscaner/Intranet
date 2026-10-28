/**
 * @file
 * Recherche dans l'organigramme : nom, fonction ou service, sans tenir compte
 * des accents ni des majuscules (comme l'annuaire, US-07).
 */
((Drupal, once) => {
  const normaliser = (texte) =>
    texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

  Drupal.behaviors.merschOrganigramme = {
    attach(context) {
      once('mersch-org', '#mersch-org-recherche', context).forEach((champ) => {
        const racine = champ.closest('.mersch-org');
        const resultat = racine.querySelector('.mersch-org__resultat');
        const postes = [...racine.querySelectorAll('.mersch-org__poste')].map((poste) => {
          // Un poste correspond aussi par le nom de ses services (ex. « Finances »).
          const services = [];
          for (let el = poste.closest('[data-service]'); el; el = el.parentElement.closest('[data-service]')) {
            services.push(el.dataset.service);
          }
          return { poste, texte: normaliser(`${poste.dataset.recherche} ${services.join(' ')}`) };
        });
        const blocs = [...racine.querySelectorAll('.mersch-org__service, .mersch-org__section')].reverse();

        champ.addEventListener('input', () => {
          const termes = normaliser(champ.value).split(/\s+/).filter((t) => t.length >= 2);
          let trouves = 0;
          postes.forEach(({ poste, texte }) => {
            const visible = termes.every((t) => texte.includes(t));
            poste.hidden = !visible;
            trouves += visible ? 1 : 0;
          });
          // Du plus profond au plus haut : un bloc sans poste visible disparaît.
          blocs.forEach((bloc) => {
            bloc.hidden = termes.length > 0 && !bloc.querySelector('.mersch-org__poste:not([hidden])');
          });
          resultat.hidden = termes.length === 0;
          resultat.textContent = trouves
            ? resultat.dataset.modele.replace('@count', trouves)
            : resultat.dataset.aucun;
        });
      });
    },
  };
})(Drupal, once);
