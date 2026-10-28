<?php

declare(strict_types=1);

namespace Drupal\mersch_accueil\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\mersch_accueil\Entity\LienRapide;

/**
 * Page d'accueil et pages d'attente des rubriques.
 */
class AccueilController extends ControllerBase {

  /**
   * Route de l'annuaire, fournie par le module mersch_annuaire (D-026).
   */
  private const ROUTE_ANNUAIRE = 'mersch_annuaire.annuaire';

  /**
   * Nombre de nouvelles affichées sur l'accueil, toutes rubriques confondues.
   */
  public const NOUVELLES = 3;

  public function __construct(
    private readonly RouteProviderInterface $routeProvider,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Accueil : liens rapides (US-10), annuaire (US-06, US-07), rubriques (US-03).
   */
  public function accueil(): array {
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();

    $liens = [];
    $entites = $this->entityTypeManager()->getStorage('mersch_lien_rapide')->loadByProperties(['status' => TRUE]);
    uasort($entites, [LienRapide::class, 'sort']);
    foreach ($entites as $lien) {
      $liens[] = [
        'libelle' => $lien->libelle($langcode),
        'description' => $lien->description($langcode),
        'url' => $lien->getUrl(),
        'icone' => $lien->icone(),
      ];
    }

    $annuaire = $this->annuaireDisponible() ? Url::fromRoute(self::ROUTE_ANNUAIRE)->toString() : NULL;
    $cache = (new CacheableMetadata())
      ->addCacheTags(['config:mersch_lien_rapide_list'])
      ->addCacheContexts(['languages:language_interface', 'languages:language_content', 'user.permissions']);
    $rubriques = $this->rubriques();

    $build = [
      '#theme' => 'mersch_accueil',
      '#liens' => $liens,
      '#annuaire' => $annuaire,
      '#rubriques' => $rubriques,
      '#nouvelles' => $this->nouvelles($rubriques, $cache),
      '#attached' => ['library' => ['mersch_accueil/accueil']],
    ];
    $cache->applyTo($build);
    return $build;
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
