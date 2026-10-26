<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_annuaire\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mersch_annuaire\Importeur;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Import d'un CSV fictif : remplacement complet et refus d'une liste vide.
 */
#[CoversClass(Importeur::class)]
#[Group('mersch_annuaire')]
#[RunTestsInSeparateProcesses]
class ImporteurTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['mersch_annuaire'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('mersch_agent');
    $this->installConfig(['mersch_annuaire']);
  }

  /**
   * Remplacement complet de l'annuaire à chaque import.
   */
  public function testRemplacement(): void {
    $importeur = $this->container->get('mersch_annuaire.importeur');
    $stockage = $this->container->get('entity_type.manager')->getStorage('mersch_agent');

    $premier = $this->csv("Nom/Prénom;Tél.;Gsm;Mail;;Service;Bâtiment;Remarques\nDUPONT Jean;32 50 23 999;;jean.dupont@exemple.lu;;IT;Annexe;\nMARTIN Anne;;;;;Recette;Château;\nHELPDESK IT;32 50 23 666;;;;IT;Annexe;\n");
    $this->assertSame(2, $importeur->importer($importeur->analyser($premier, 'liste.csv'), 'liste.csv'));

    $second = $this->csv("Nom/Prénom;Tél.;Gsm;Mail;;Service;Bâtiment;Remarques\nWEBER Paul;;621000111;;;Piscine;Piscine;\n");
    $importeur->importer($importeur->analyser($second, 'liste.csv'), 'liste.csv');
    $agents = $stockage->loadMultiple();
    $this->assertCount(1, $agents);
    $this->assertSame('621 000 111', reset($agents)->get('gsm')->value);
    $this->assertSame(1, $this->container->get('state')->get(Importeur::ETAT)['agents']);

    // Une liste sans ligne valide ne vide pas l'annuaire.
    $vide = $this->csv("Nom/Prénom;Service\nCuisine;\n");
    try {
      $importeur->importer($importeur->analyser($vide, 'vide.csv'), 'vide.csv');
      $this->fail('Une liste vide doit être refusée.');
    }
    catch (\InvalidArgumentException) {
    }
    $this->assertCount(1, $stockage->loadMultiple());
  }

  /**
   * Écrit un CSV temporaire.
   */
  private function csv(string $contenu): string {
    $chemin = $this->container->get('file_system')->tempnam('temporary://', 'annuaire');
    file_put_contents($chemin, $contenu);
    return $chemin;
  }

}
