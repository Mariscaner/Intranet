<?php

declare(strict_types=1);

namespace Drupal\mersch_services\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\mersch_services\Rubriques;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le service RH, Communication ou Guides IT n'a pas de page à lui : sa page
 * est celle de la rubrique, où ses liens et contacts s'affichent en bas.
 */
class Redirection implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly Rubriques $rubriques,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Après le routage (priorité 32) et le contrôle d'accès.
    return [KernelEvents::REQUEST => ['rediriger', 20]];
  }

  /**
   * Redirige la page d'un service lié vers sa rubrique.
   */
  public function rediriger(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->routeMatch->getRouteName() !== 'entity.node.canonical') {
      return;
    }
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== Rubriques::TYPE) {
      return;
    }
    $rubrique = $this->rubriques->rubrique($node);
    if ($rubrique) {
      $url = Url::fromRoute(Rubriques::EXISTANTES[$rubrique]['route'])->setAbsolute()->toString();
      $event->setResponse(new RedirectResponse($url));
    }
  }

}
