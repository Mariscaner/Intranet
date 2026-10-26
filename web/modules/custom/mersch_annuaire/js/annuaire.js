/**
 * @file
 * Recherche dans l'annuaire dès 2 caractères, sans accents ni majuscules (US-07).
 */
((Drupal, once) => {
  // Même normalisation que côté serveur : « Bâtiment » et « batiment » se valent.
  const cle = (texte) =>
    texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

  Drupal.behaviors.merschAnnuaire = {
    attach(context) {
      once('mersch-annuaire', '[data-mersch-annuaire]', context).forEach((annuaire) => {
        const champ = annuaire.querySelector('[data-mersch-annuaire-recherche]');
        const compte = annuaire.querySelector('[data-mersch-annuaire-compte]');
        const vide = annuaire.querySelector('[data-mersch-annuaire-vide]');
        const services = annuaire.querySelectorAll('[data-mersch-annuaire-service]');

        champ.addEventListener('input', () => {
          const saisie = cle(champ.value);
          // Les numéros se cherchent aussi avec ou sans espaces.
          const chiffres = saisie.replace(/\s/g, '');
          const actif = saisie.length >= 2;
          let trouves = 0;
          services.forEach((service) => {
            let visibles = 0;
            service.querySelectorAll('[data-mersch-annuaire-agent]').forEach((ligne) => {
              const texte = ligne.dataset.recherche;
              const visible = !actif || texte.includes(saisie) || (/^\d+$/.test(chiffres) && texte.includes(chiffres));
              ligne.hidden = !visible;
              visibles += visible ? 1 : 0;
            });
            service.hidden = visibles === 0;
            trouves += visibles;
          });
          vide.hidden = trouves > 0;
          compte.textContent = actif
            ? Drupal.formatPlural(trouves, '1 staff member found', '@count staff members found')
            : Drupal.t('@count staff members', { '@count': compte.dataset.total });
        });

        // Recherche lancée depuis l'accueil : /annuaire?q=texte.
        const q = new URLSearchParams(window.location.search).get('q');
        if (q) {
          champ.value = q;
          champ.dispatchEvent(new Event('input'));
        }
      });
    },
  };
})(Drupal, once);
