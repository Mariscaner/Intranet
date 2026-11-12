<?php

declare(strict_types=1);

namespace Drupal\mersch_sso_test\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Page réservée aux utilisateurs connectés.
 */
class PageController extends ControllerBase {

  /**
   * Contenu minimal.
   */
  public function contenu(): array {
    return ['#markup' => 'Page protégée'];
  }

}
