<?php

declare(strict_types=1);

namespace Drupal\mersch_sso;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lien « Umellen » / nom de l'agent et « Ofmellen » de l'en-tête, et adresse
 * de connexion réutilisable par les lots 2 et 3.
 */
class Lien implements TrustedCallbackInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly Reglages $reglages,
    private readonly AccountInterface $currentUser,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['construire'];
  }

  /**
   * Adresse de connexion SSO, toujours au nom complet du serveur (le SPN ne
   * couvre que lui), avec retour à la page demandée.
   */
  public function urlConnexion(?string $destination = NULL): string {
    $url = $this->reglages->base() . '/sso/connexion';
    if ($destination !== NULL && $destination !== '/' && !preg_match('#^/([a-z]{2}/)?(sso|user)(/|$|\?)#', $destination)) {
      $url .= '?destination=' . rawurlencode($destination);
    }
    return $url;
  }

  /**
   * Adresse de connexion qui ramène à la page courante.
   */
  public function urlConnexionIci(): string {
    $requete = $this->requestStack->getCurrentRequest();
    return $this->urlConnexion($requete?->getRequestUri());
  }

  /**
   * Lazy builder de l'en-tête.
   */
  public function construire(): array {
    $cache = ['contexts' => ['user', 'url.path', 'url.query_args', 'languages:language_interface']];
    if ($this->currentUser->isAuthenticated()) {
      return [
        'nom' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->currentUser->getDisplayName(),
          '#attributes' => ['class' => ['mersch-entete__nom']],
        ],
        'deconnexion' => [
          '#type' => 'link',
          '#title' => $this->t('Log out'),
          '#url' => Url::fromRoute('user.logout'),
          '#attributes' => ['class' => ['mersch-entete__connexion']],
        ],
        '#cache' => $cache,
      ];
    }
    return [
      '#type' => 'link',
      '#title' => $this->t('Log in'),
      '#url' => Url::fromUri($this->urlConnexionIci()),
      '#attributes' => ['class' => ['mersch-entete__connexion'], 'rel' => 'nofollow'],
      '#cache' => $cache,
    ];
  }

}
