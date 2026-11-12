<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_services\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mersch_services\Controller\ServicesController;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pages des services : droits, contacts de l'annuaire, adresses (US-22 à
 * US-28).
 */
#[Group('mersch_services')]
#[RunTestsInSeparateProcesses]
class ServicesTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'file', 'image', 'text', 'filter', 'options',
    'datetime', 'node', 'taxonomy', 'block', 'language', 'locale',
    'content_translation', 'link', 'path', 'path_alias',
    'mersch_accueil', 'mersch_annuaire', 'mersch_rh', 'mersch_services',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('mersch_agent');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'filter', 'node', 'mersch_accueil', 'mersch_rh', 'mersch_services']);
    \Drupal::moduleHandler()->loadInclude('mersch_services', 'install');
    mersch_services_install(FALSE);
    // Utilisateur 1 créé d'abord : il aurait tous les droits.
    $this->setUpCurrentUser([], ['access content', 'use text format mersch_simple']);

    $agents = \Drupal::entityTypeManager()->getStorage('mersch_agent');
    foreach ([
      ['MULLER', 'Anne', 'Service technique', '32 50 23-410', 'anne.muller@exemple.lu'],
      ['SCHMIT', 'Léa', 'Service technique', '', ''],
      ['KLEIN', 'Tom', 'Population', '32 50 23-200', 'tom.klein@exemple.lu'],
    ] as [$nom, $prenom, $service, $tel, $email]) {
      $agents->create(['nom' => $nom, 'prenom' => $prenom, 'service' => $service, 'telephone' => $tel, 'email' => $email])->save();
    }
  }

  /**
   * L'installation crée les services RH, Communication et Guides IT (Q1).
   */
  public function testServicesDesRubriques(): void {
    $rubriques = \Drupal::service('mersch_services.rubriques');
    foreach (['rh', 'communication', 'guides_it'] as $cle) {
      $service = $rubriques->servicePourRubrique($cle);
      $this->assertInstanceOf(NodeInterface::class, $service, $cle);
      $this->assertSame($cle, $rubriques->rubrique($service));
    }
  }

  /**
   * Un rédacteur de service modifie ses services seulement, sans nom,
   * adresse ni suppression ; un rédacteur RH tient le service RH (US-26).
   */
  public function testDroitsDesRedacteurs(): void {
    $technique = $this->service('Technesche Service');
    $population = $this->service('Biergerservice');
    $redacteur = $this->createUser([], NULL, FALSE, ['field_mersch_services' => [$technique->id()]]);
    $this->assertContains('redacteur_service', $redacteur->getRoles(), 'Rôle donné avec le premier service coché.');

    $this->assertTrue($technique->access('update', $redacteur));
    $this->assertFalse($population->access('update', $redacteur));
    $this->assertFalse($technique->access('delete', $redacteur));
    $this->assertFalse($technique->get('title')->access('edit', $redacteur));
    $this->assertFalse($technique->get('path')->access('edit', $redacteur));
    $this->assertFalse($technique->get('status')->access('edit', $redacteur));
    $this->assertTrue($technique->get('field_service_liens')->access('edit', $redacteur));
    $this->assertTrue($technique->get('field_service_contacts')->access('edit', $redacteur));

    $redacteurRh = $this->createUser([], NULL, FALSE, ['roles' => ['redacteur_rh']]);
    $serviceRh = \Drupal::service('mersch_services.rubriques')->servicePourRubrique('rh');
    $this->assertTrue($serviceRh->access('update', $redacteurRh));
    $this->assertFalse($technique->access('update', $redacteurRh));

    // Service masqué : vu par son rédacteur, pas par les lecteurs (US-25).
    $technique->setUnpublished()->save();
    $this->assertTrue($technique->access('view', $redacteur));
    $this->assertFalse($technique->access('view', $this->createUser(['access content'])));

    // Plus aucun service coché : le rôle est retiré.
    $redacteur->set('field_mersch_services', [])->save();
    $this->assertNotContains('redacteur_service', $redacteur->getRoles());
  }

  /**
   * Les contacts sont lus dans l'annuaire du jour, par e-mail ou par nom ;
   * un agent parti n'est plus affiché (US-23, US-28).
   */
  public function testContactsSuiventLAnnuaire(): void {
    $service = $this->service('Technesche Service', [
      ['nom' => 'MULLER', 'prenom' => 'Anne', 'email' => 'anne.muller@exemple.lu', 'role' => 'Cheffe de service'],
      ['nom' => 'SCHMIT', 'prenom' => 'Léa', 'email' => '', 'role' => ''],
      ['nom' => 'PARTI', 'prenom' => 'Jean', 'email' => 'jean.parti@exemple.lu', 'role' => ''],
    ]);

    $pied = \Drupal::classResolver(ServicesController::class)->pied($service);
    $this->assertSame(['MULLER', 'SCHMIT'], array_column($pied['#contacts'], 'nom'));
    $this->assertSame('Cheffe de service', $pied['#contacts'][0]['role']);
    $this->assertSame('tel:+352325023410', $pied['#contacts'][0]['tel_lien']);
    $this->assertContains('mersch_agent_list', $pied['#cache']['tags']);

    // Nouvel import : nouveaux identifiants, nouveau numéro, même e-mail.
    $agents = \Drupal::entityTypeManager()->getStorage('mersch_agent');
    $agents->delete($agents->loadMultiple());
    $agents->create(['nom' => 'MULLER', 'prenom' => 'Anne', 'service' => 'Service technique', 'telephone' => '32 50 23-499', 'email' => 'Anne.Muller@exemple.lu'])->save();
    \Drupal::service('mersch_services.contacts')->reinitialiser();

    $pied = \Drupal::classResolver(ServicesController::class)->pied($service);
    $this->assertSame(['MULLER'], array_column($pied['#contacts'], 'nom'));
    $this->assertSame('32 50 23-499', $pied['#contacts'][0]['telephone']);
  }

  /**
   * Recherche des agents pour le champ « Collègue », sans accents.
   */
  public function testRechercheAgents(): void {
    $contacts = \Drupal::service('mersch_services.contacts');
    $this->assertSame(['SCHMIT'], array_column($contacts->chercher('lea'), 'nom'));
    $this->assertSame(['MULLER', 'SCHMIT'], array_column($contacts->chercher('technique'), 'nom'));
    $this->assertSame([], $contacts->chercher(''));
  }

  /**
   * Adresse courte /services/<nom> donnée automatiquement (US-25).
   */
  public function testAdresseCourte(): void {
    $premier = $this->service('Service État civil');
    $second = $this->service('Service État civil');
    $this->assertSame('/services/service-etat-civil', $premier->get('path')->alias);
    $this->assertSame('/services/service-etat-civil-2', $second->get('path')->alias);
  }

  /**
   * Nouvelles d'un service : rédacteurs du service seulement, 150 mots au
   * plus, échues et non publiées absentes de la page.
   */
  public function testNouvellesDuService(): void {
    $technique = $this->service('Technesche Service');
    $population = $this->service('Biergerservice');
    $redacteur = $this->createUser([], NULL, FALSE, ['field_mersch_services' => [$technique->id()]]);
    $lecteur = $this->createUser(['access content']);
    $controle = \Drupal::entityTypeManager()->getAccessControlHandler('node');
    $this->assertTrue($controle->createAccess('service_nouvelle', $redacteur));
    $this->assertFalse($controle->createAccess('service_nouvelle', $lecteur));

    $sienne = $this->nouvelle($technique, 'Stroossenaarbechten');
    $autre = $this->nouvelle($population, 'Nei Dokumenter');
    $this->assertTrue($sienne->access('update', $redacteur));
    $this->assertFalse($sienne->access('delete', $redacteur));
    $this->assertTrue($sienne->get('status')->access('edit', $redacteur));
    $this->assertFalse($autre->access('update', $redacteur));

    // Gabarit et service du rédacteur, contrôlés pour la personne connectée.
    $this->setCurrentUser($redacteur);
    $longue = $this->nouvelle($technique, 'Ze laang', implode(' ', array_fill(0, 151, 'mot')), save: FALSE);
    $this->assertSame(['field_snouv_texte'], $this->chemins($longue));
    $ailleurs = $this->nouvelle($population, 'Net säin', save: FALSE);
    $this->assertSame(['field_snouv_service'], $this->chemins($ailleurs));

    // Page du service : publiées, non échues, plus récentes d'abord.
    $this->setCurrentUser($lecteur);
    $hier = date('Y-m-d', \Drupal::time()->getRequestTime() - 86400);
    $this->nouvelle($technique, 'Échue', fin: $hier);
    $this->nouvelle($technique, 'Brouillon', publie: FALSE);
    $plusRecente = $this->nouvelle($technique, 'Lescht Neiegkeet');
    $plusRecente->setCreatedTime(\Drupal::time()->getRequestTime() + 60)->save();
    $cache = new CacheableMetadata();
    $titres = array_column(\Drupal::classResolver(ServicesController::class)->nouvelles($technique, $cache), 'titre');
    $this->assertSame(['Lescht Neiegkeet', 'Stroossenaarbechten'], $titres);
    $this->assertContains('mersch_services_jour', $cache->getCacheTags());
  }

  /**
   * Crée une nouvelle de service.
   */
  private function nouvelle(NodeInterface $service, string $titre, string $texte = 'E kuerzen Text.', ?string $fin = NULL, bool $publie = TRUE, bool $save = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'service_nouvelle',
      'title' => $titre,
      'field_snouv_service' => $service->id(),
      'field_snouv_texte' => ['value' => $texte, 'format' => 'mersch_simple'],
      'field_snouv_fin_publication' => $fin,
      'status' => (int) $publie,
    ]);
    if ($save) {
      $node->save();
    }
    return $node;
  }

  /**
   * Champs en erreur à la validation.
   *
   * @return string[]
   */
  private function chemins(NodeInterface $node): array {
    $chemins = [];
    foreach ($node->validate() as $violation) {
      $chemins[] = explode('.', $violation->getPropertyPath())[0];
    }
    return array_values(array_unique($chemins));
  }

  /**
   * Crée une page de service publiée.
   */
  private function service(string $titre, array $contacts = []): NodeInterface {
    $node = Node::create([
      'type' => 'service',
      'title' => $titre,
      'field_service_contacts' => $contacts,
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

}
