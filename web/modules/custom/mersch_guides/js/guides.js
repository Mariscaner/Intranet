/**
 * @file
 * Guides IT : filtre par catégorie et recherche par mot-clé dès 2 caractères,
 * sans accents ni majuscules (US-19).
 */
((Drupal, once) => {
  // Même normalisation que côté serveur : « Congé » et « conge » se valent.
  const cle = (texte) =>
    texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

  Drupal.behaviors.merschGuides = {
    attach(context) {
      once('mersch-guides', '[data-mersch-guides]', context).forEach((page) => {
        const champ = page.querySelector('[data-mersch-guides-recherche]');
        const compte = page.querySelector('[data-mersch-guides-compte]');
        const vide = page.querySelector('[data-mersch-guides-vide]');
        const boutons = page.querySelectorAll('[data-mersch-guides-categorie]');
        const guides = page.querySelectorAll('[data-mersch-guides-guide]');
        let categorie = '';

        const filtrer = () => {
          const saisie = cle(champ.value);
          const actif = saisie.length >= 2;
          let trouves = 0;
          guides.forEach((guide) => {
            const visible = (categorie === '' || guide.dataset.categorie === categorie)
              && (!actif || guide.dataset.recherche.includes(saisie));
            guide.hidden = !visible;
            trouves += visible ? 1 : 0;
          });
          vide.hidden = trouves > 0;
          compte.textContent = actif || categorie
            ? Drupal.formatPlural(trouves, '1 guide found', '@count guides found')
            : Drupal.t('@count guides', { '@count': compte.dataset.total });
        };

        boutons.forEach((bouton) => {
          bouton.addEventListener('click', () => {
            categorie = bouton.dataset.merschGuidesCategorie;
            boutons.forEach((b) => b.setAttribute('aria-pressed', String(b === bouton)));
            filtrer();
          });
        });
        champ.addEventListener('input', filtrer);

        // Lien direct : /guides-it?q=passwuert.
        const q = new URLSearchParams(window.location.search).get('q');
        if (q) {
          champ.value = q;
          filtrer();
        }
      });
    },
  };
})(Drupal, once);
