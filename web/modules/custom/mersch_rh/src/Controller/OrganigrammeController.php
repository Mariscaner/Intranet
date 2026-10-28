<?php

declare(strict_types=1);

namespace Drupal\mersch_rh\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\mersch_rh\Organigramme;

/**
 * Page de l'organigramme (US-12).
 */
class OrganigrammeController extends ControllerBase {

  public function __construct(
    private readonly Organigramme $organigramme,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Page /rh/organigramme.
   */
  public function page(): array {
    $cache = (new CacheableMetadata())
      ->addCacheTags(['node_list:rh_poste', 'taxonomy_term_list:rh_service'])
      ->addCacheContexts(['languages:language_interface', 'user.permissions']);
    $donnees = $this->organigramme->donnees($cache);

    $build = [
      '#theme' => 'mersch_organigramme',
      '#sections' => $donnees['sections'],
      '#personnes' => $donnees['personnes'],
      '#date' => $donnees['date'] ? $this->dateFormatter->format($donnees['date'], 'custom', 'd.m.Y') : NULL,
      '#attached' => ['library' => ['mersch_accueil/accueil', 'mersch_rh/organigramme']],
    ];
    $cache->applyTo($build);
    return $build;
  }

}
