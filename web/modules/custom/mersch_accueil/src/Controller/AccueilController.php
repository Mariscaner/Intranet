<?php

declare(strict_types=1);

namespace Drupal\mersch_accueil\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Url;
use Drupal\mersch_accueil\Entity\LienRapide;
use Drupal\mersch_accueil\Form\IdeeForm;

/**
 * Page d'accueil et pages d'attente des rubriques.
 */
class AccueilController extends ControllerBase implements TrustedCallbackInterface {

  /**
   * Route de l'annuaire, fournie par le module mersch_annuaire (D-026).
   */
  private const ROUTE_ANNUAIRE = 'mersch_annuaire.annuaire';

  /**
   * Nombre de nouvelles affichées sur l'accueil, toutes rubriques confondues.
   */
  public const NOUVELLES = 3;

  /**
   * Style d'image des cartes d'actualité (config/install).
   */
  private const STYLE_CARTE = 'mersch_accueil_carte';

  public function __construct(
    private readonly RouteProviderInterface $routeProvider,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Accueil : nouvelles en tête, shortcuts (US-10), rubriques (US-03) ;
   * annuaire (US-06, US-07) et boîte à idées en colonne latérale.
   */
  public function accueil(): array {
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();

    $liens = [];
    $entites = $this->entityTypeManager()->getStorage('mersch_lien_rapide')->loadByProperties(['status' => TRUE]);
    uasort($entites, [LienRapide::class, 'sort']);
    foreach (array_values($entites) as $position => $lien) {
      $liens[] = [
        'libelle' => $lien->libelle($langcode),
        'description' => $lien->description($langcode),
        'url' => $lien->getUrl(),
        'icone' => $lien->icone(),
        // Une case sans teinte choisie prend la suivante dans la liste.
        'couleur' => $lien->couleur() ?: LienRapide::COULEURS[$position % count(LienRapide::COULEURS)],
      ];
    }

    $annuaire = $this->annuaireDisponible() ? Url::fromRoute(self::ROUTE_ANNUAIRE)->toString() : NULL;
    $cache = (new CacheableMetadata())
      ->addCacheTags(['config:mersch_lien_rapide_list', 'config:image.style.' . self::STYLE_CARTE])
      ->addCacheContexts(['languages:language_interface', 'languages:language_content', 'user.permissions']);
    $rubriques = $this->rubriques();

    $build = [
      '#theme' => 'mersch_accueil',
      '#liens' => $liens,
      '#annuaire' => $annuaire,
      '#rubriques' => $rubriques,
      '#nouvelles' => $this->nouvelles($rubriques, $cache),
      // Revue de presse (module mersch_presse, US-40), si le module est actif.
      '#presse' => $this->moduleHandler()->invokeAll('mersch_accueil_presse'),
      '#idees' => [
        // Construit à part : le formulaire ne rend pas l'accueil incachable.
        '#lazy_builder' => [static::class . '::boiteIdees', []],
        '#create_placeholder' => TRUE,
      ],
      '#attached' => ['library' => ['mersch_accueil/accueil']],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Boîte à idées : formulaire pour les agents connectés, lien vers les idées
   * reçues pour les admins ; rien pour un lecteur non connecté.
   */
  public static function boiteIdees(): array {
    $compte = \Drupal::currentUser();
    $build = ['#cache' => ['contexts' => ['user.permissions'], 'tags' => ['mersch_idee_list']]];
    if ($compte->hasPermission('submit mersch idees')) {
      $build['formulaire'] = \Drupal::formBuilder()->getForm(IdeeForm::class);
    }
    if ($compte->hasPermission('administer mersch idees')) {
      $nombre = \Drupal::entityQuery('mersch_idee')->accessCheck(FALSE)->count()->execute();
      $build['admin'] = [
        '#type' => 'link',
        '#title' => \Drupal::translation()->formatPlural($nombre, 'See the idea received (1)', 'See the ideas received (@count)'),
        '#url' => Url::fromRoute('entity.mersch_idee.collection'),
        '#prefix' => '<p class="mersch-idees__admin">',
        '#suffix' => '</p>',
      ];
    }
    if (isset($build['formulaire']) || isset($build['admin'])) {
      $build['#prefix'] = '<section class="mersch-bloc mersch-idees" aria-labelledby="mersch-accueil-idees"><h2 id="mersch-accueil-idees">' . t('Ideas box') . '</h2>';
      $build['#suffix'] = '</section>';
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['boiteIdees'];
  }

  /**
   * Dernières nouvelles des rubriques, plus récentes d'abord, d'après la
   * maquette. Chaque module de rubrique fournit les siennes
   * (hook_mersch_accueil_nouvelles()).
   */
  private function nouvelles(array $rubriques, CacheableMetadata $cache): array {
    $nouvelles = [];
    $this->moduleHandler()->invokeAllWith('mersch_accueil_nouvelles', function (callable $hook) use (&$nouvelles, $cache) {
      array_push($nouvelles, ...$hook(self::NOUVELLES, $cache));
    });
    usort($nouvelles, fn (array $a, array $b) => $b['created'] <=> $a['created']);

    return array_map(fn (array $nouvelle) => [
      'titre' => $nouvelle['titre'],
      'date' => $this->dateFormatter->format($nouvelle['created'], 'custom', 'd.m.Y'),
      'rubrique' => $rubriques[$nouvelle['rubrique']] ?? NULL,
      // Sans photo, le gabarit affiche l'illustration de la rubrique.
      'photo' => empty($nouvelle['photo']['uri']) ? NULL : [
        '#theme' => 'image_style',
        '#style_name' => self::STYLE_CARTE,
        '#uri' => $nouvelle['photo']['uri'],
        '#alt' => $nouvelle['photo']['alt'] ?? '',
        '#attributes' => ['loading' => 'lazy', 'class' => ['mersch-carte__photo']],
      ],
    ], array_slice($nouvelles, 0, self::NOUVELLES));
  }

  /**
   * Page d'attente d'une rubrique, en attendant son contenu.
   */
  public function rubrique(string $rubrique): array {
    $rubriques = $this->rubriques();
    return [
      '#theme' => 'mersch_rubrique',
      '#rubrique' => $rubriques[$rubrique] ?? [],
      '#attached' => ['library' => ['mersch_accueil/accueil']],
    ];
  }

  /**
   * Les trois rubriques du menu (01, §3), dans l'ordre du menu.
   */
  private function rubriques(): array {
    return [
      'rh' => [
        'cle' => 'rh',
        'titre' => $this->t('Human resources'),
        'texte' => $this->t('Organisation chart, official communications and staff news.'),
        'url' => Url::fromRoute('mersch_accueil.rh')->toString(),
      ],
      'communication' => [
        'cle' => 'communication',
        'titre' => $this->t('Communication'),
        'texte' => $this->t('The Gemengebuet, the events calendar, the photo albums and internal news.'),
        'url' => Url::fromRoute('mersch_accueil.communication')->toString(),
      ],
      'guides_it' => [
        'cle' => 'guides-it',
        'titre' => $this->t('IT guides'),
        'texte' => $this->t('Solve a problem yourself, or ask IT Hëllef vu Miersch.'),
        'url' => Url::fromRoute('mersch_accueil.guides_it')->toString(),
      ],
    ];
  }

  /**
   * L'accueil fonctionne aussi sans le module d'annuaire.
   */
  private function annuaireDisponible(): bool {
    return count($this->routeProvider->getRoutesByNames([self::ROUTE_ANNUAIRE])) > 0;
  }

}
