<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_communication\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mersch_accueil\Controller\AccueilController;
use Drupal\mersch_communication\Controller\CommunicationController;
use Drupal\mersch_communication\Plugin\Validation\Constraint\NouvelleCommunicationConstraintValidator;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Page Communication : liens mersch.lu et nouvelles (US-15 à US-18).
 */
#[Group('mersch_communication')]
#[RunTestsInSeparateProcesses]
class PageCommunicationTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'file', 'image', 'text', 'filter',
    'datetime', 'node', 'block', 'language', 'locale', 'content_translation',
    'mersch_accueil', 'mersch_communication',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node', 'mersch_accueil', 'mersch_communication']);
    // Le format de texte des nouvelles n'est valide que pour qui peut l'utiliser.
    $this->setUpCurrentUser([], ['use text format mersch_simple']);
  }

  /**
   * Le rôle Rédacteur Communication édite et dépublie ses nouvelles, sans
   * les supprimer ni toucher aux autres rubriques (D-007, D-018, D-019).
   */
  public function testRoleRedacteurCommunication(): void {
    $role = Role::load('redacteur_communication');
    $this->assertTrue($role->hasPermission('create comm_nouvelle content'));
    $this->assertTrue($role->hasPermission('edit any comm_nouvelle content'));
    $this->assertFalse($role->hasPermission('delete any comm_nouvelle content'));
    $this->assertFalse($role->hasPermission('administer nodes'));
    $this->assertFalse($role->hasPermission('administer mersch communication'));
    $this->assertSame([], array_filter($role->getPermissions(), fn (string $permission) => str_contains($permission, 'rh_')));

    $redacteur = $this->createUser([], NULL, FALSE, ['roles' => ['redacteur_communication']]);
    $lecteur = $this->createUser(['access content']);
    $nouvelle = $this->nouvelle('Neien Nik ass eraus');

    $this->assertTrue($nouvelle->access('update', $redacteur));
    $this->assertFalse($nouvelle->access('delete', $redacteur));
    $this->assertTrue($nouvelle->get('status')->access('edit', $redacteur));
    $this->assertFalse($nouvelle->get('status')->access('edit', $lecteur));
  }

  /**
   * Gabarit de nouvelle : 150 mots au plus sans minimum, accord écrit pour
   * une photo (D-031).
   */
  public function testGabaritNouvelle(): void {
    $this->assertSame([], $this->chemins($this->nouvelle('Kuerz', texte: 'Moien!', save: FALSE)));

    $tropLong = $this->nouvelle('Ze laang', texte: str_repeat('Moien ', 151), save: FALSE);
    $this->assertSame(['field_comm_texte'], $this->chemins($tropLong));

    $photo = $this->fichier('stand.png');
    // Le cœur contrôle aussi l'accès au fichier déposé : seule la règle de
    // l'accord est vérifiée ici.
    $sansAccord = $this->nouvelle('Stand', photo: $photo, save: FALSE);
    $this->assertContains('field_comm_accord', $this->chemins($sansAccord));
    $avecAccord = $this->nouvelle('Stand', photo: $photo, accord: TRUE, save: FALSE);
    $this->assertNotContains('field_comm_accord', $this->chemins($avecAccord));

    $this->assertSame(3, NouvelleCommunicationConstraintValidator::compterMots('<p>Moien  a</p> <p>merci!</p>'));
  }

  /**
   * Liens vers mersch.lu dans l'ordre Nik, agenda, albums ; nouvelles plus
   * récentes d'abord, sans les échues ni les dépubliées (US-15 à US-18).
   */
  public function testPage(): void {
    $this->setCurrentUser($this->createUser(['access content']));
    $this->nouvelle('Ancienne', created: 1000);
    $this->nouvelle('Récente', created: 2000);
    $this->nouvelle('Échue', fin: '2020-01-01');
    $this->nouvelle('Dépubliée', statut: FALSE);

    $page = CommunicationController::create($this->container)->page();
    $this->assertSame(['nik', 'agenda', 'albums'], array_keys($page['#liens']));
    $this->assertSame('https://www.mersch.lu/agenda', $page['#liens']['agenda']['url']);
    $this->assertSame('mersch.lu/mediatheque', $page['#liens']['nik']['adresse']);
    $this->assertSame(['Récente', 'Ancienne'], array_column($page['#nouvelles'], 'titre'));
    $this->assertNull($page['#nouvelles'][0]['modifier']);
    $this->assertContains('node_list:comm_nouvelle', $page['#cache']['tags']);
    $this->assertContains('config:mersch_communication.settings', $page['#cache']['tags']);
    $this->assertContains(CommunicationController::CACHE_TAG_JOUR, $page['#cache']['tags']);
  }

  /**
   * L'accueil affiche les 3 dernières nouvelles, avec leur rubrique.
   */
  public function testAccueil(): void {
    $this->setCurrentUser($this->createUser(['access content']));
    foreach ([1000, 4000, 2000, 3000] as $created) {
      $this->nouvelle('Nouvelle ' . $created, created: $created);
    }
    $this->nouvelle('Échue', fin: '2020-01-01');

    $accueil = AccueilController::create($this->container)->accueil();
    $this->assertSame(['Nouvelle 4000', 'Nouvelle 3000', 'Nouvelle 2000'], array_column($accueil['#nouvelles'], 'titre'));
    $this->assertSame('communication', $accueil['#nouvelles'][0]['rubrique']['cle']);
    $this->assertContains('node_list:comm_nouvelle', $accueil['#cache']['tags']);
  }

  /**
   * La page /communication n'est plus la page d'attente de l'accueil.
   */
  public function testRoute(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('mersch_accueil.communication');
    $this->assertSame('\Drupal\mersch_communication\Controller\CommunicationController::page', $route->getDefault('_controller'));
  }

  /**
   * Adresse courte affichée sous un lien.
   */
  public function testAdresseCourte(): void {
    $this->assertSame('mersch.lu/agenda', CommunicationController::adresseCourte('https://www.mersch.lu/agenda/'));
    $this->assertSame('mersch.lu', CommunicationController::adresseCourte('https://www.mersch.lu'));
  }

  /**
   * Crée une nouvelle Communication.
   */
  private function nouvelle(string $titre, ?string $texte = NULL, ?File $photo = NULL, bool $accord = FALSE, ?string $fin = NULL, bool $statut = TRUE, ?int $created = NULL, bool $save = TRUE): Node {
    $node = Node::create([
      'type' => 'comm_nouvelle',
      'title' => $titre,
      'field_comm_texte' => ['value' => $texte ?? 'Texte de démonstration.', 'format' => 'mersch_simple'],
      'field_comm_photo' => $photo?->id(),
      'field_comm_accord' => $accord,
      'field_comm_fin_publication' => $fin,
      'status' => $statut,
      'created' => $created ?? \Drupal::time()->getRequestTime(),
    ]);
    if ($save) {
      $node->save();
    }
    return $node;
  }

  /**
   * Champs en erreur d'un contenu.
   */
  private function chemins(Node $node): array {
    $chemins = [];
    foreach ($node->validate() as $violation) {
      $chemins[] = explode('.', $violation->getPropertyPath())[0];
    }
    return array_values(array_unique($chemins));
  }

  /**
   * Image PNG d'un pixel, déposée comme par le formulaire.
   */
  private function fichier(string $nom): File {
    file_put_contents('public://' . $nom, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $fichier = File::create(['uri' => 'public://' . $nom, 'filename' => $nom]);
    $fichier->setTemporary();
    $fichier->save();
    return $fichier;
  }

}
