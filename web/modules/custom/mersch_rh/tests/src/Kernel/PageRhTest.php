<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_rh\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mersch_rh\Controller\RhController;
use Drupal\mersch_rh\Organigramme;
use Drupal\mersch_rh\Plugin\Validation\Constraint\NouvelleRhConstraintValidator;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Page RH : organigramme, nouvelles, communications (US-12 à US-14).
 */
#[Group('mersch_rh')]
#[RunTestsInSeparateProcesses]
class PageRhTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'file', 'image', 'text', 'filter', 'options',
    'datetime', 'node', 'block', 'language', 'locale', 'content_translation',
    'mersch_accueil', 'mersch_rh',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node', 'mersch_accueil', 'mersch_rh']);
    // Le format de texte des nouvelles n'est valide que pour qui peut l'utiliser.
    $this->setUpCurrentUser([], ['use text format mersch_simple']);
  }

  /**
   * Le rôle Rédacteur RH édite et dépublie ses contenus, sans les supprimer
   * (D-007, D-018, D-019).
   */
  public function testRoleRedacteurRh(): void {
    $role = Role::load('redacteur_rh');
    $this->assertTrue($role->hasPermission('edit any rh_nouvelle content'));
    $this->assertTrue($role->hasPermission('deposer organigramme mersch'));
    $this->assertFalse($role->hasPermission('delete any rh_nouvelle content'));
    $this->assertFalse($role->hasPermission('administer nodes'));

    $redacteur = $this->createUser([], NULL, FALSE, ['roles' => ['redacteur_rh']]);
    $lecteur = $this->createUser(['access content']);
    $nouvelle = $this->nouvelle('Luc REISCH', 'retraite', TRUE);

    $this->assertTrue($nouvelle->access('update', $redacteur));
    $this->assertFalse($nouvelle->access('delete', $redacteur));
    $this->assertTrue($nouvelle->get('status')->access('edit', $redacteur));
    $this->assertFalse($nouvelle->get('status')->access('edit', $lecteur));
  }

  /**
   * Gabarit de nouvelle : 150 mots au plus, accord écrit pour une annonce
   * nominative ou une photo (D-023).
   */
  public function testGabaritNouvelle(): void {
    $sansAccord = $this->nouvelle('Pol KUGENER', 'arrivee', FALSE, save: FALSE);
    $this->assertSame(['field_rh_accord'], $this->chemins($sansAccord));

    $info = $this->nouvelle('Fermeture du service', 'info', FALSE, save: FALSE);
    $this->assertSame([], $this->chemins($info));

    $tropLong = $this->nouvelle('Luc REISCH', 'retraite', TRUE, texte: str_repeat('Merci ', 151), save: FALSE);
    $this->assertSame(['field_rh_texte'], $this->chemins($tropLong));

    $this->assertSame(3, NouvelleRhConstraintValidator::compterMots('<p>Moien  a</p> <p>merci!</p>'));
  }

  /**
   * Un nouvel organigramme remplace l'ancien, qui est supprimé (D-011).
   */
  public function testRemplacementOrganigramme(): void {
    $service = $this->container->get('mersch_rh.organigramme');
    $this->assertNull($service->fichier());

    $ancien = $this->fichier('organigramme-1.pdf');
    $service->remplacer($ancien, 1);
    $this->assertSame($ancien->id(), $service->fichier()->id());
    $this->assertTrue(Organigramme::estPdf($service->fichier()));

    $nouveau = $this->fichier('organigramme-2.pdf');
    $service->remplacer($nouveau, 1);
    $this->assertSame($nouveau->id(), $service->fichier()->id());
    $this->assertTrue($service->fichier()->isPermanent());
    $this->assertNull(File::load($ancien->id()));
    $this->assertFileDoesNotExist('public://organigramme-1.pdf');
  }

  /**
   * Nouvelles groupées par catégorie, plus récentes d'abord ; une nouvelle
   * échue ou dépubliée n'apparaît pas ; communications à part (US-13, US-14).
   */
  public function testPage(): void {
    $this->setCurrentUser($this->createUser(['access content']));
    $this->nouvelle('Ancien', 'arrivee', TRUE, created: 1000);
    $this->nouvelle('Pol KUGENER', 'arrivee', TRUE, created: 2000);
    $this->nouvelle('Luc REISCH', 'retraite', TRUE);
    $this->nouvelle('Échue', 'info', FALSE, fin: '2020-01-01');
    $this->nouvelle('Dépubliée', 'info', FALSE, statut: FALSE);
    $pdf = $this->fichier('circulaire.pdf');
    Node::create([
      'type' => 'rh_communication',
      'title' => 'Circulaire congés',
      'field_rh_pdf' => $pdf->id(),
    ])->save();

    $page = RhController::create($this->container)->page();
    $this->assertSame(['arrivee', 'retraite'], array_keys($page['#nouvelles']));
    $this->assertSame(['Pol KUGENER', 'Ancien'], array_column($page['#nouvelles']['arrivee']['elements'], 'titre'));
    $this->assertSame('PK', $page['#nouvelles']['arrivee']['elements'][0]['initiales']);
    $this->assertNull($page['#nouvelles']['arrivee']['elements'][0]['modifier']);
    $this->assertSame(['Circulaire congés'], array_column($page['#communications'], 'titre'));
    $this->assertNull($page['#organigramme']);
    $this->assertContains('node_list:rh_nouvelle', $page['#cache']['tags']);
    $this->assertContains(RhController::CACHE_TAG_JOUR, $page['#cache']['tags']);
  }

  /**
   * Initiales affichées sans photo.
   */
  public function testInitiales(): void {
    $this->assertSame('AJ', RhController::initiales('Ayrton Jorge SIMOES'));
    $this->assertSame('ÉS', RhController::initiales('élise schmit'));
  }

  /**
   * Crée une nouvelle RH.
   */
  private function nouvelle(string $titre, string $categorie, bool $accord, ?string $texte = NULL, ?string $fin = NULL, bool $statut = TRUE, ?int $created = NULL, bool $save = TRUE): Node {
    $node = Node::create([
      'type' => 'rh_nouvelle',
      'title' => $titre,
      'field_rh_categorie' => $categorie,
      'field_rh_accord' => $accord,
      'field_rh_texte' => ['value' => $texte ?? 'Texte de démonstration.', 'format' => 'mersch_simple'],
      'field_rh_fin_publication' => $fin,
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
   * Fichier temporaire, comme après un dépôt.
   */
  private function fichier(string $nom): File {
    file_put_contents('public://' . $nom, '%PDF-1.4');
    $fichier = File::create(['uri' => 'public://' . $nom, 'filename' => $nom]);
    $fichier->setTemporary();
    $fichier->save();
    return $fichier;
  }

}
