<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_guides\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mersch_accueil\Entity\LienRapide;
use Drupal\mersch_guides\Controller\GuidesController;
use Drupal\mersch_guides\ImportGuides;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Page Guides IT : catégories, recherche, helpdesk, reprise (US-19 à US-21).
 */
#[Group('mersch_guides')]
#[RunTestsInSeparateProcesses]
class PageGuidesTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'file', 'image', 'text', 'filter', 'options',
    'node', 'block', 'language', 'locale', 'content_translation',
    'mersch_accueil', 'mersch_guides',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    $this->installConfig(['system', 'filter', 'node', 'language', 'mersch_accueil', 'mersch_guides']);
    $this->container->get('module_handler')->loadInclude('mersch_accueil', 'install');
    mersch_accueil_install(FALSE);
    $this->setUpCurrentUser([], ['use text format mersch_simple']);
  }

  /**
   * Seuls les administrateurs publient un guide (D-007) : aucun rôle de
   * rédacteur n'a de droit sur le type Guide IT.
   */
  public function testDroits(): void {
    $guide = $this->guide('Passwuert änneren', 'securite');
    $lecteur = $this->createUser(['access content']);
    $this->assertTrue($guide->access('view', $lecteur));
    $this->assertFalse($guide->access('update', $lecteur));
    $roles = glob(dirname(__DIR__, 4) . '/*/config/install/user.role.*.yml');
    $this->assertNotEmpty($roles);
    foreach ($roles as $role) {
      $this->assertStringNotContainsString('guide_it', file_get_contents($role), basename($role));
    }
  }

  /**
   * Un guide contient un texte ou un PDF (US-21).
   */
  public function testTexteOuPdf(): void {
    $this->assertSame(['field_guide_texte'], $this->chemins($this->guide('Vide', save: FALSE, texte: NULL)));
    $this->assertSame([], $this->chemins($this->guide('Texte', save: FALSE)));
    // Le cœur contrôle aussi l'accès au fichier déposé : seule la règle du
    // texte ou PDF est vérifiée ici.
    $this->assertNotContains('field_guide_texte', $this->chemins($this->guide('PDF', save: FALSE, texte: NULL, pdf: $this->pdf('guide.pdf'))));
  }

  /**
   * Cartes triées par catégorie puis titre, filtres limités aux catégories
   * utilisées, texte de recherche sans accents et dans toutes les langues,
   * dépubliés absents (US-19).
   */
  public function testPage(): void {
    $this->setCurrentUser($this->createUser(['access content']));
    $this->guide('Säi Windows-Passwuert änneren', 'securite', motsCles: 'passwuert', traductions: ['fr' => 'Changer son mot de passe Windows']);
    $this->guide('IT Hëllef vu Miersch', 'helpdesk');
    $this->guide('Verdächteg E-Mail', 'securite');
    $this->guide('Dépublié', 'materiel', statut: FALSE);

    $page = GuidesController::create($this->container)->page();
    $this->assertSame(['IT Hëllef vu Miersch', 'Säi Windows-Passwuert änneren', 'Verdächteg E-Mail'], array_column($page['#guides'], 'titre'));
    $this->assertSame(['helpdesk', 'securite'], array_column($page['#categories'], 'cle'));

    $recherche = $page['#guides'][1]['recherche'];
    $this->assertStringContainsString('sai windows-passwuert anneren', $recherche);
    $this->assertStringContainsString('changer son mot de passe windows', $recherche);
    $this->assertStringContainsString('security and accounts', $recherche);
    $this->assertNull($page['#guides'][0]['modifier']);
    $this->assertNull($page['#guides'][0]['pdf']);
    $this->assertContains('node_list:guide_it', $page['#cache']['tags']);
  }

  /**
   * Le lien vers le helpdesk reprend le lien rapide de l'accueil (US-19).
   */
  public function testHelpdesk(): void {
    $this->setCurrentUser($this->createUser(['access content']));
    $page = GuidesController::create($this->container)->page();
    $this->assertSame('https://helpdesk', $page['#helpdesk']);
    $this->assertContains('config:mersch_accueil.lien_rapide.helpdesk', $page['#cache']['tags']);

    LienRapide::load('helpdesk')->delete();
    $page = GuidesController::create($this->container)->page();
    $this->assertSame(GuidesController::HELPDESK, $page['#helpdesk']);
  }

  /**
   * Pied de la page d'un guide : PDF et date de mise à jour (US-21).
   */
  public function testPied(): void {
    $guide = $this->guide('DSKNet', 'applications', texte: NULL, pdf: $this->pdf('dsknet.pdf'));
    $pied = GuidesController::create($this->container)->pied($guide);
    $this->assertStringEndsWith('/guides-it/dsknet.pdf', $pied['#pdf']['url']);
    $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4}$/', $pied['#date']);
  }

  /**
   * Reprise des guides existants depuis guides.json (US-20).
   */
  public function testImport(): void {
    $dossier = $this->siteDirectory . '/reprise';
    mkdir($dossier);
    file_put_contents($dossier . '/a.pdf', '%PDF-1.4');
    file_put_contents($dossier . '/guides.json', json_encode(['guides' => [[
      'categorie' => 'applications',
      'pdf' => '../a.pdf',
      'titre' => ['lb' => 'DSKNet: Presenzen a Congéen', 'fr' => 'DSKNet : présences et congés'],
      'resume' => ['lb' => 'Seng Stonnen agesinn.', 'fr' => 'Consulter ses heures.'],
      'mots_cles' => ['lb' => 'congé'],
    ]]]));
    $import = $this->container->get(ImportGuides::class);

    $this->assertSame(1, $import->importer($dossier));
    $guides = Node::loadMultiple();
    $guide = reset($guides);
    $this->assertSame('lb', $guide->language()->getId());
    $this->assertSame('DSKNet : présences et congés', $guide->getTranslation('fr')->label());
    $this->assertFalse($guide->hasTranslation('de'));
    $this->assertStringEndsWith('guides-it/a.pdf', $guide->get('field_guide_pdf')->entity->getFileUri());

    $this->expectException(\InvalidArgumentException::class);
    $import->importer($dossier);
  }

  /**
   * Crée un guide IT.
   */
  private function guide(string $titre, string $categorie = 'helpdesk', ?string $texte = 'Schrëtt fir Schrëtt.', ?File $pdf = NULL, string $motsCles = '', array $traductions = [], bool $statut = TRUE, bool $save = TRUE): Node {
    $node = Node::create([
      'type' => 'guide_it',
      'langcode' => 'lb',
      'title' => $titre,
      'field_guide_categorie' => $categorie,
      'field_guide_resume' => 'Resumé.',
      'field_guide_mots_cles' => $motsCles,
      'field_guide_texte' => $texte === NULL ? NULL : ['value' => $texte, 'format' => 'mersch_simple'],
      'field_guide_pdf' => $pdf?->id(),
      'status' => $statut,
    ]);
    foreach ($traductions as $langcode => $titreTraduit) {
      $node->addTranslation($langcode, ['title' => $titreTraduit, 'field_guide_resume' => 'Résumé.']);
    }
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
   * Petit PDF déposé comme par le formulaire.
   */
  private function pdf(string $nom): File {
    $dossier = 'public://guides-it';
    \Drupal::service('file_system')->prepareDirectory($dossier, 1);
    file_put_contents('public://guides-it/' . $nom, '%PDF-1.4');
    $fichier = File::create(['uri' => 'public://guides-it/' . $nom, 'filename' => $nom]);
    $fichier->setPermanent();
    $fichier->save();
    return $fichier;
  }

}
