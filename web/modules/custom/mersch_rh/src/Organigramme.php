<?php

declare(strict_types=1);

namespace Drupal\mersch_rh;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\mersch_rh\Controller\RhController;
use Drupal\node\NodeInterface;

/**
 * Organigramme tenu à jour par les rédacteurs RH (US-12).
 *
 * Les services sont les termes du vocabulaire rh_service (ordre et parent
 * donnent la mise en page), les personnes et postes vacants sont des
 * contenus rh_poste : nom, fonction, service, photo. Ni grade, ni
 * ancienneté, ni temps de travail.
 */
class Organigramme {

  /**
   * Route de l'annuaire (mersch_annuaire), pour trouver le téléphone.
   */
  private const ROUTE_ANNUAIRE = 'mersch_annuaire.annuaire';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * Arbre des services avec leurs postes, nombre de personnes, dernière
   * modification.
   */
  public function donnees(CacheableMetadata $cache): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'rh_poste')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('field_rh_ordre')
      ->sort('title')
      ->execute();
    $postes = [];
    $personnes = 0;
    $date = 0;
    $annuaire = count($this->routeProvider->getRoutesByNames([self::ROUTE_ANNUAIRE])) > 0;
    foreach ($ids ? $this->entityTypeManager->getStorage('node')->loadMultiple($ids) : [] as $node) {
      $cache->addCacheableDependency($node);
      $nom = $node->label();
      $vacant = self::estVacant($nom);
      $photo = $node->get('field_rh_photo');
      $postes[$node->get('field_rh_unite')->target_id][] = [
        'nom' => $nom,
        'fonction' => $node->get('field_rh_fonction')->value,
        'vacant' => $vacant,
        'photo' => $photo->isEmpty() || !$photo->entity ? NULL : [
          '#theme' => 'image_style',
          '#style_name' => 'mersch_portrait',
          '#uri' => $photo->entity->getFileUri(),
          '#alt' => $nom,
          '#attributes' => ['loading' => 'lazy'],
        ],
        'initiales' => RhController::initiales($nom),
        'annuaire' => $annuaire && !$vacant ? Url::fromRoute(self::ROUTE_ANNUAIRE, [], ['query' => ['q' => $nom]])->toString() : NULL,
        'modifier' => $node->access('update') ? $node->toUrl('edit-form')->toString() : NULL,
      ];
      $personnes += $vacant ? 0 : 1;
      $date = max($date, $node->getChangedTime());
    }

    // Arbre des services, dans l'ordre choisi par les RH.
    $services = [];
    $racines = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree('rh_service', 0, NULL, TRUE) as $terme) {
      $date = max($date, $terme->getChangedTime());
      $id = (int) $terme->id();
      $services[$id] = [
        'id' => 'service-' . $id,
        'nom' => $terme->label(),
        'postes' => $postes[$id] ?? [],
        'enfants' => [],
        'large' => FALSE,
      ];
      $parent = (int) ($terme->parents[0] ?? 0);
      if ($parent && isset($services[$parent])) {
        $services[$parent]['enfants'][] = &$services[$id];
      }
      else {
        $racines[] = &$services[$id];
      }
    }

    return [
      'sections' => array_values(array_filter(array_map([self::class, 'elaguer'], $racines))),
      'personnes' => $personnes,
      'date' => $date ?: NULL,
    ];
  }

  /**
   * Retire les services sans personne ni sous-service rempli, et repère les
   * services à afficher sur toute la largeur (plusieurs équipes, comme le
   * Service régie).
   */
  private static function elaguer(array $service): ?array {
    $service['enfants'] = array_values(array_filter(array_map([self::class, 'elaguer'], $service['enfants'])));
    $service['large'] = count($service['enfants']) > 2 || array_filter(array_column($service['enfants'], 'enfants'));
    return $service['postes'] || $service['enfants'] ? $service : NULL;
  }

  /**
   * « Poste vacant » saisi à la place d'un nom.
   */
  public static function estVacant(string $nom): bool {
    return (bool) preg_match('/^postes?\s+vacants?\b/iu', trim($nom));
  }

}
