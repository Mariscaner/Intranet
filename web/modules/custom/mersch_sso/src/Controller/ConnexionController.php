<?php

declare(strict_types=1);

namespace Drupal\mersch_sso\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\mersch_sso\Comptes;
use Drupal\mersch_sso\EchecConnexion;
use Drupal\mersch_sso\Reglages;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /sso/connexion : connexion de l'agent reconnu par Apache (US-29).
 */
class ConnexionController extends ControllerBase {

  use AutowireTrait;

  public function __construct(
    private readonly Comptes $comptes,
    private readonly Reglages $reglages,
    #[Autowire(service: 'page_cache_kill_switch')]
    private readonly KillSwitch $killSwitch,
    #[Autowire(service: 'logger.channel.mersch_sso')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Connecte l'agent dont Apache a vérifié le ticket Kerberos.
   */
  public function connexion(Request $request): array|Response {
    $this->killSwitch->trigger();
    $destination = self::destinationSure($request->query->get('destination'));
    // La destination est traitée ici : le cœur ne doit pas la réappliquer.
    $request->query->remove('destination');

    if (!$this->reglages->actif()) {
      return $this->page(EchecConnexion::INACTIF, Response::HTTP_SERVICE_UNAVAILABLE);
    }
    // Variable du serveur posée par mod_auth_gssapi, jamais un en-tête HTTP
    // (un en-tête arriverait en HTTP_REMOTE_USER). REDIRECT_REMOTE_USER :
    // même valeur après la réécriture interne vers index.php.
    $principal = (string) ($request->server->get('REMOTE_USER') ?: $request->server->get('REDIRECT_REMOTE_USER') ?: '');
    try {
      if ($principal === '') {
        throw new EchecConnexion(EchecConnexion::NON_RECONNU, 'REMOTE_USER absent');
      }
      $compte = $this->comptes->compte($this->comptes->identifiant($principal));
    }
    catch (EchecConnexion $e) {
      if ($principal === '' && (string) $request->server->get('REDIRECT_STATUS') === '401') {
        // Page d'erreur 401 d'Apache (ErrorDocument) : le code 401 et son
        // en-tête « WWW-Authenticate: Negotiate » doivent rester, sinon le
        // navigateur ne tente jamais Kerberos.
        return $this->page(EchecConnexion::NON_RECONNU, Response::HTTP_UNAUTHORIZED);
      }
      $this->logger->warning('Connexion SSO refusée (@raison) : @detail', ['@raison' => $e->raison, '@detail' => $e->getMessage()]);
      return $this->page($e->raison, Response::HTTP_FORBIDDEN);
    }

    if ((int) $this->currentUser()->id() !== (int) $compte->id()) {
      if ($this->currentUser()->isAuthenticated()) {
        // Autre agent sur le même navigateur : l'ancienne session est fermée.
        user_logout();
      }
      user_login_finalize($compte);
    }
    $url = $request->getSchemeAndHttpHost() . $destination;
    $reponse = new LocalRedirectResponse($url);
    $reponse->headers->set('Cache-Control', 'no-store, private');
    return $reponse;
  }

  /**
   * /sso/echec : page d'erreur 401 d'Apache (navigateur non reconnu).
   */
  public function echec(): array {
    $this->killSwitch->trigger();
    return $this->contenu(EchecConnexion::NON_RECONNU);
  }

  /**
   * Chemin interne sûr, ou l'accueil.
   *
   * Refuse les adresses externes (https://…, //hote, \\hote), les caractères
   * de contrôle et les pages /sso/ elles-mêmes.
   */
  public static function destinationSure(mixed $destination): string {
    if (!is_string($destination) || $destination === '' || $destination[0] !== '/'
      || str_starts_with($destination, '//') || str_contains($destination, '\\')
      || preg_match('/[\x00-\x20\x7f]/', $destination)
      || UrlHelper::isExternal($destination)
      || preg_match('#^/([a-z]{2}/)?sso(/|$|\?)#', $destination)) {
      return '/';
    }
    return $destination;
  }

  /**
   * Page de refus avec son code HTTP.
   */
  private function page(string $raison, int $code): Response {
    $contenu = $this->contenu($raison);
    $html = \Drupal::service('main_content_renderer.html')->renderResponse($contenu, \Drupal::request(), \Drupal::routeMatch());
    $html->setStatusCode($code);
    return $html;
  }

  /**
   * Message LU/DE/FR (selon la langue de la page) et liens utiles.
   */
  private function contenu(string $raison): array {
    $messages = [
      EchecConnexion::NON_RECONNU => $this->t('Your Windows session could not be recognised. Open the intranet on a computer of the municipality, logged in with your own Windows account, in Edge or Chrome. If the problem persists, contact the IT helpdesk.'),
      EchecConnexion::INACTIF => $this->t('Login with your Windows session is not active yet.'),
      EchecConnexion::REFUSE => $this->t('Your Windows account cannot be used to log in to the intranet. Contact the IT helpdesk.'),
      EchecConnexion::BLOQUE => $this->t('Your intranet account is blocked. Contact an administrator of the intranet.'),
      EchecConnexion::ANNUAIRE => $this->t('The directory of the municipality does not answer. Try again in a few minutes.'),
    ];
    return [
      '#title' => $this->t('Log in'),
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $messages[$raison] ?? $messages[EchecConnexion::NON_RECONNU],
        '#attributes' => ['class' => ['mersch-sso__message']],
      ],
      'liens' => [
        '#theme' => 'item_list',
        '#items' => [
          ['#type' => 'link', '#title' => $this->t('Back to the home page'), '#url' => Url::fromRoute('<front>')],
          ['#type' => 'link', '#title' => $this->t('Emergency account of the administrators'), '#url' => Url::fromRoute('user.login')],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
