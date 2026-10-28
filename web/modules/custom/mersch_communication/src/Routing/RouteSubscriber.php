<?php

declare(strict_types=1);

namespace Drupal\mersch_communication\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Remplace la page d'attente /communication de mersch_accueil.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('mersch_accueil.communication');
    if ($route) {
      $route->setDefault('_controller', '\Drupal\mersch_communication\Controller\CommunicationController::page');
      $route->setDefault('rubrique', NULL);
    }
  }

}
