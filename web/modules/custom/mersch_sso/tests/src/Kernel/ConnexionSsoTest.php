<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_sso\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mersch_sso\Annuaire\Agent;
use Drupal\mersch_sso\Controller\ConnexionController;
use Drupal\Tests\mersch_sso\FauxAnnuaire;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connexion SSO : création et rattachement des comptes, refus, destinations,
 * connexion locale, comptes inactifs, lien d'en-tête (US-29 à US-33).
 */
#[Group('mersch_sso')]
#[RunTestsInSeparateProcesses]
class ConnexionSsoTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'mersch_sso', 'mersch_sso_test'];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Le vrai LDAP est remplacé par le faux annuaire.
    $container->getDefinition('mersch_sso.annuaire')
      ->setClass(FauxAnnuaire::class)
      ->setArguments([]);
  }

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user']);
    FauxAnnuaire::reinitialiser();
    // Utilisateur 1 (compte de secours) créé d'abord.
    User::create(['name' => 'secours', 'mail' => 'secours@domain.local', 'status' => 1])->save();
    $this->activer();
    FauxAnnuaire::$agents['abcd'] = new Agent('abcd', 'Anne Becker', 'Anne.Becker@domain.local');
  }

  /**
   * Règle le SSO.
   */
  private function activer(bool $actif = TRUE): void {
    $this->setSetting('mersch_sso', [
      'actif' => $actif,
      'serveur' => 'intranet.domain.local',
      'realm' => 'DOMAIN.LOCAL',
      'inactivite_mois' => 13,
    ]);
  }

  /**
   * Appelle /sso/connexion.
   */
  private function connecter(string $principal = 'abcd@DOMAIN.LOCAL', string $destination = '/fr/annuaire', array $serveur = []): Response {
    $url = '/sso/connexion' . ($destination !== '' ? '?destination=' . rawurlencode($destination) : '');
    $serveur += ['REMOTE_USER' => $principal, 'HTTP_HOST' => 'intranet.domain.local'];
    if ($principal === '') {
      unset($serveur['REMOTE_USER']);
    }
    $reponse = $this->container->get('http_kernel')->handle(Request::create($url, 'GET', [], [], [], $serveur));
    // Le contexte de requête (utilisateur courant) est conservé par le noyau.
    return $reponse;
  }

  /**
   * Compte lié à abcd, créé par une première connexion.
   */
  private function premiereConnexion(): User {
    $this->connecter();
    $ids = \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('name', 'abcd')->execute();
    return User::load((int) reset($ids));
  }

  /**
   * Nombre de comptes (hors anonyme).
   */
  private function nombreComptes(): int {
    return (int) \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('uid', 0, '>')->count()->execute();
  }

  /**
   * US-29 : première connexion, création du compte.
   */
  public function testPremiereConnexion(): void {
    $reponse = $this->connecter(destination: '/fr/annuaire?q=x');
    $this->assertSame(302, $reponse->getStatusCode());
    $this->assertSame('http://intranet.domain.local/fr/annuaire?q=x', $reponse->headers->get('Location'));

    $compte = User::load((int) \Drupal::currentUser()->id());
    $this->assertNotNull($compte);
    $this->assertSame('abcd', $compte->getAccountName());
    $this->assertSame('anne.becker@domain.local', strtolower($compte->getEmail()));
    $this->assertSame('Anne.Becker@domain.local', $compte->getEmail());
    $this->assertSame(['authenticated'], $compte->getRoles());
    $this->assertTrue($compte->isActive());
    $data = \Drupal::service('user.data');
    $this->assertSame('abcd', $data->get('mersch_sso', (int) $compte->id(), 'identifiant'));
    $this->assertSame('Anne Becker', $data->get('mersch_sso', (int) $compte->id(), 'nom'));
    $this->assertSame('Anne Becker', $compte->getDisplayName());
    $this->assertSame(2, $this->nombreComptes());

    // Principal en majuscules : même compte, pas de doublon.
    $this->connecter('ABCD@DOMAIN.LOCAL');
    $this->assertSame(2, $this->nombreComptes());
  }

  /**
   * US-31 : rattachement d'un compte existant au même e-mail.
   */
  public function testRattachement(): void {
    Role::create(['id' => 'redacteur', 'label' => 'Rédacteur'])->save();
    $existant = User::create(['name' => 'anne', 'mail' => 'anne.becker@DOMAIN.local', 'status' => 1, 'roles' => ['redacteur']]);
    $existant->save();

    $reponse = $this->connecter();
    $this->assertSame(302, $reponse->getStatusCode());
    $this->assertSame(2, $this->nombreComptes());
    $this->assertSame((int) $existant->id(), (int) \Drupal::currentUser()->id());
    $recharge = User::load($existant->id());
    $this->assertContains('redacteur', $recharge->getRoles());
    $this->assertSame('abcd', \Drupal::service('mersch_sso.comptes')->identifiantDe((int) $existant->id()));
  }

  /**
   * US-32 : le compte n° 1 n'est jamais rattaché.
   */
  public function testCompteSecoursJamaisRattache(): void {
    $uid1 = User::load(1);
    $uid1->setEmail('Anne.Becker@domain.local')->save();

    $reponse = $this->connecter();
    $this->assertSame(302, $reponse->getStatusCode());
    $this->assertNotSame(1, (int) \Drupal::currentUser()->id());
    $this->assertNull(\Drupal::service('mersch_sso.comptes')->identifiantDe(1));
    $this->assertSame(2, $this->nombreComptes());
    $this->assertSame('abcd', User::load((int) \Drupal::currentUser()->id())->getAccountName());
  }

  /**
   * Realm refusé, principal sans realm, principal de service, en-tête HTTP.
   */
  public function testPrincipauxRefuses(): void {
    foreach (['abcd@AUTRE.LOCAL', 'abcd', 'HTTP/x@DOMAIN.LOCAL'] as $principal) {
      $reponse = $this->connecter($principal);
      $this->assertSame(403, $reponse->getStatusCode(), $principal);
      $this->assertTrue(\Drupal::currentUser()->isAnonymous(), $principal);
    }
    // REMOTE_USER transmis comme en-tête HTTP : ignoré.
    $reponse = $this->connecter('', serveur: ['HTTP_REMOTE_USER' => 'abcd@DOMAIN.LOCAL']);
    $this->assertSame(403, $reponse->getStatusCode());
    $this->assertTrue(\Drupal::currentUser()->isAnonymous());
    $this->assertSame(1, $this->nombreComptes());
  }

  /**
   * Destinations externes refusées, interne conservée.
   */
  public function testDestinations(): void {
    foreach (['https://evil.example', '//evil.example', '/\\evil.example', '/sso/connexion'] as $destination) {
      $reponse = $this->connecter(destination: $destination);
      $this->assertSame(302, $reponse->getStatusCode(), $destination);
      $this->assertSame('http://intranet.domain.local/', $reponse->headers->get('Location'), $destination);
    }
    $reponse = $this->connecter(destination: '/fr/annuaire?q=x&page=2');
    $this->assertSame('http://intranet.domain.local/fr/annuaire?q=x&page=2', $reponse->headers->get('Location'));
  }

  /**
   * ConnexionController::destinationSure() en direct.
   */
  public function testDestinationSure(): void {
    $this->assertSame('/', ConnexionController::destinationSure(NULL));
    $this->assertSame('/', ConnexionController::destinationSure(['/a']));
    $this->assertSame('/', ConnexionController::destinationSure(''));
    $this->assertSame('/', ConnexionController::destinationSure('page'));
    $this->assertSame('/', ConnexionController::destinationSure('https://evil.example'));
    $this->assertSame('/', ConnexionController::destinationSure('//evil.example'));
    $this->assertSame('/', ConnexionController::destinationSure('/\\evil.example'));
    $this->assertSame('/', ConnexionController::destinationSure("/a\r\nLocation: x"));
    $this->assertSame('/', ConnexionController::destinationSure('/a b'));
    $this->assertSame('/', ConnexionController::destinationSure('/sso/connexion'));
    $this->assertSame('/', ConnexionController::destinationSure('/fr/sso/echec'));
    $this->assertSame('/fr/annuaire?q=x', ConnexionController::destinationSure('/fr/annuaire?q=x'));
    $this->assertSame('/user/1', ConnexionController::destinationSure('/user/1'));
    $this->assertSame('/ssomething', ConnexionController::destinationSure('/ssomething'));
  }

  /**
   * SSO inactif : 503, personne n'est connecté.
   */
  public function testSsoInactif(): void {
    $this->activer(FALSE);
    $reponse = $this->connecter();
    $this->assertSame(503, $reponse->getStatusCode());
    $this->assertTrue(\Drupal::currentUser()->isAnonymous());
    $this->assertSame(1, $this->nombreComptes());
  }

  /**
   * Compte bloqué, AD indisponible.
   */
  public function testBloqueEtAnnuaireIndisponible(): void {
    $compte = $this->premiereConnexion();
    \Drupal::currentUser()->setAccount(User::getAnonymousUser());

    // AD en panne, compte actif : connecté avec les données connues.
    FauxAnnuaire::$indisponible = TRUE;
    $reponse = $this->connecter();
    $this->assertSame(302, $reponse->getStatusCode());
    $this->assertSame((int) $compte->id(), (int) \Drupal::currentUser()->id());
    \Drupal::currentUser()->setAccount(User::getAnonymousUser());

    // Nouveau compte pendant la panne : refusé, rien créé.
    FauxAnnuaire::$agents['wxyz'] = new Agent('wxyz', 'Willy Xyz', 'willy@domain.local');
    $avant = $this->nombreComptes();
    $reponse = $this->connecter('wxyz@DOMAIN.LOCAL');
    $this->assertSame(403, $reponse->getStatusCode());
    $this->assertTrue(\Drupal::currentUser()->isAnonymous());
    $this->assertSame($avant, $this->nombreComptes());

    // Compte lié bloqué, AD en panne puis disponible : refusé dans les deux cas.
    $compte->block()->save();
    $this->assertSame(403, $this->connecter()->getStatusCode());
    FauxAnnuaire::$indisponible = FALSE;
    $this->assertSame(403, $this->connecter()->getStatusCode());
    $this->assertTrue(\Drupal::currentUser()->isAnonymous());
  }

  /**
   * Connexion locale refusée pour un compte lié, acceptée pour le compte n° 1.
   */
  public function testConnexionLocale(): void {
    $compte = $this->premiereConnexion();
    \Drupal::moduleHandler()->loadInclude('mersch_sso', 'module');
    $form = [];

    $etat = new FormState();
    $etat->set('uid', (int) $compte->id());
    mersch_sso_refuser_connexion_locale($form, $etat);
    $this->assertFalse($etat->get('uid'));
    $this->assertArrayHasKey('name', $etat->getErrors());

    $etat = new FormState();
    $etat->set('uid', 1);
    mersch_sso_refuser_connexion_locale($form, $etat);
    $this->assertSame(1, $etat->get('uid'));
    $this->assertSame([], $etat->getErrors());

    // Compte local non lié : accepté.
    $local = User::create(['name' => 'local', 'mail' => 'local@domain.local', 'status' => 1, 'pass' => 'x']);
    $local->save();
    $etat = new FormState();
    $etat->set('uid', (int) $local->id());
    mersch_sso_refuser_connexion_locale($form, $etat);
    $this->assertSame((int) $local->id(), $etat->get('uid'));

    // Le validateur est bien branché sur le formulaire de connexion.
    $formulaire = \Drupal::formBuilder()->getForm('Drupal\user\Form\UserLoginForm');
    $this->assertContains('mersch_sso_refuser_connexion_locale', $formulaire['#validate']);
  }

  /**
   * US-33 : blocage des comptes SSO inactifs.
   */
  public function testBloquerInactifs(): void {
    Role::create(['id' => 'redacteur', 'label' => 'Rédacteur'])->save();
    $data = \Drupal::service('user.data');
    $vieux = \Drupal::time()->getRequestTime() - 14 * 30 * 86400;
    $recent = \Drupal::time()->getRequestTime() - 30 * 86400;

    $creer = function (string $nom, int $date, array $roles = [], bool $lie = TRUE) use ($data): User {
      $compte = User::create(['name' => $nom, 'mail' => "$nom@domain.local", 'status' => 1, 'roles' => $roles, 'created' => $date, 'access' => $date, 'login' => $date]);
      $compte->save();
      if ($lie) {
        $data->set('mersch_sso', (int) $compte->id(), 'identifiant', $nom);
      }
      return $compte;
    };
    $inactif = $creer('inactif', $vieux);
    $redacteur = $creer('redac', $vieux, ['redacteur']);
    $nonlie = $creer('nonlie', $vieux, [], FALSE);
    $actif = $creer('actif', $recent);
    // Compte de secours : lié de force mais jamais bloqué.
    $uid1 = User::load(1);
    $uid1->set('created', $vieux)->set('access', $vieux)->set('login', $vieux)->save();
    $data->set('mersch_sso', 1, 'identifiant', 'secours');

    $signales = \Drupal::service('mersch_sso.comptes')->bloquerInactifs();

    $this->assertSame([(int) $redacteur->id()], $signales);
    $this->assertTrue(User::load($inactif->id())->isBlocked());
    $this->assertTrue(User::load($redacteur->id())->isActive());
    $this->assertTrue(User::load($nonlie->id())->isActive());
    $this->assertTrue(User::load($actif->id())->isActive());
    $this->assertTrue(User::load(1)->isActive());
  }

  /**
   * Lien d'en-tête.
   */
  public function testLien(): void {
    $lien = \Drupal::service('mersch_sso.lien');
    $this->assertSame('http://intranet.domain.local/sso/connexion?destination=%2Ffr%2Fannuaire%3Fq%3Dx', $lien->urlConnexion('/fr/annuaire?q=x'));
    $this->assertSame('http://intranet.domain.local/sso/connexion', $lien->urlConnexion('/user/login'));
    $this->assertSame('http://intranet.domain.local/sso/connexion', $lien->urlConnexion('/fr/user/login'));
    $this->assertSame('http://intranet.domain.local/sso/connexion', $lien->urlConnexion('/'));
    $this->assertSame('http://intranet.domain.local/sso/connexion', $lien->urlConnexion());
  }

  /**
   * Un anonyme ne reçoit aucune négociation (401 / WWW-Authenticate).
   */
  public function testAnonymeSansNegociation(): void {
    $reponse = $this->container->get('http_kernel')->handle(Request::create('/user/login', 'GET', [], [], [], ['HTTP_HOST' => 'intranet.domain.local']));
    $this->assertNotSame(401, $reponse->getStatusCode());
    $this->assertFalse($reponse->headers->has('WWW-Authenticate'));
    $this->assertTrue(\Drupal::currentUser()->isAnonymous());
  }

  /**
   * Route « _mersch_sso » : l'anonyme va vers /sso/connexion.
   */
  public function testRouteExigeantLaConnexion(): void {
    $reponse = $this->container->get('http_kernel')->handle(Request::create('/mersch-sso-test/protegee?a=1', 'GET', [], [], [], ['HTTP_HOST' => 'intranet.domain.local']));
    $this->assertSame(302, $reponse->getStatusCode());
    $this->assertSame('http://intranet.domain.local/sso/connexion?destination=%2Fmersch-sso-test%2Fprotegee%3Fa%3D1', $reponse->headers->get('Location'));
  }

}
