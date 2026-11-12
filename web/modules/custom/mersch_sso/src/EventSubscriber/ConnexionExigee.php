<?php

declare(strict_types=1);

namespace Drupal\mersch_sso\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\mersch_sso\Lien;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Anonyme sur une route qui exige la connexion : vers /sso/connexion.
 *
 * Pour les lots 2 et 3 (calendrier, réservations) : déclarer la route avec
 * l'option « _mersch_sso: TRUE » et l'exigence « _user_is_logged_in: 'TRUE' ».
 */
class ConnexionExigee implements EventSubscriberInterface {

  public function __construct(
    private readonly Lien $lien,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Avant les pages d'erreur du cœur.
    return [KernelEvents::EXCEPTION => ['rediriger', 100]];
  }

  /**
   * Redirige un anonyme refusé vers la connexion SSO.
   */
  public function rediriger(ExceptionEvent $event): void {
    $requete = $event->getRequest();
    $route = $requete->attributes->get('_route_object');
    if (!$event->getThrowable() instanceof AccessDeniedHttpException
      || $this->currentUser->isAuthenticated()
      || !$route || !$route->getOption('_mersch_sso')
      || $requete->getRequestFormat() !== 'html'
      || !\Drupal::service('mersch_sso.reglages')->actif()) {
      return;
    }
    $reponse = new TrustedRedirectResponse($this->lien->urlConnexion($requete->getRequestUri()));
    $reponse->getCacheableMetadata()->setCacheMaxAge(0);
    $event->setResponse($reponse);
  }

}
