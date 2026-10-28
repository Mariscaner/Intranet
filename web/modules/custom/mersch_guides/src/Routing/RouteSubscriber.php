<?php

declare(strict_types=1);

namespace Drupal\mersch_guides\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Remplace la page d'attente /guides-it de mersch_accueil.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('mersch_accueil.guides_it');
    if ($route) {
      $route->setDefault('_controller', '\Drupal\mersch_guides\Controller\GuidesController::page');
      $route->setDefault('rubrique', NULL);
    }
  }

}
