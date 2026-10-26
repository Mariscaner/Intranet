<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_accueil\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mersch_accueil\Entity\LienRapide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Liens rapides de l'accueil (US-10, US-11).
 */
#[Group('mersch_accueil')]
#[RunTestsInSeparateProcesses]
class LienRapideTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'block', 'language', 'locale', 'mersch_accueil'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['mersch_accueil']);
  }

  /**
   * Les 4 liens du cahier des charges sont installés, dans l'ordre (US-10).
   */
  public function testLiensParDefaut(): void {
    $liens = LienRapide::loadMultiple();
    uasort($liens, [LienRapide::class, 'sort']);
    $urls = array_values(array_map(fn (LienRapide $lien) => $lien->getUrl(), $liens));
    $this->assertSame([
      'https://apps',
      'https://gescom.siginet.lu',
      'https://helpdesk',
      'https://www.mersch.lu',
    ], $urls);
    $this->assertSame('Présences et congés', $liens['dsknet']->description('fr'));
  }

  /**
   * Une langue non saisie reprend le texte luxembourgeois.
   */
  public function testRepliSurLeLuxembourgeois(): void {
    $lien = LienRapide::create([
      'id' => 'test',
      'libelle' => ['lb' => 'Kantin', 'de' => '', 'fr' => 'Cantine'],
      'description' => ['lb' => 'Menu vun der Woch'],
      'url' => 'https://kantin',
    ]);
    $lien->save();
    $lien = LienRapide::load('test');
    $this->assertSame('Kantin', $lien->libelle('de'));
    $this->assertSame('Cantine', $lien->libelle('fr'));
    $this->assertSame('Menu vun der Woch', $lien->description('fr'));
    $this->assertSame('lien', $lien->icone());
  }

  /**
   * Seules les adresses absolues http ou https sont acceptées (US-11).
   */
  #[DataProvider('urls')]
  public function testValidationUrl(string $url, bool $valide): void {
    $this->assertSame($valide, LienRapide::urlValide($url));
  }

  public static function urls(): array {
    return [
      'serveur interne' => ['https://apps', TRUE],
      'domaine' => ['https://gescom.siginet.lu', TRUE],
      'chemin et paramètres' => ['http://intranet.mersch.lu/a?b=c', TRUE],
      'javascript' => ['javascript:alert(1)', FALSE],
      'sans schéma' => ['www.mersch.lu', FALSE],
      'ftp' => ['ftp://serveur/fichier', FALSE],
      'vide' => ['', FALSE],
      'espace' => ['https://mer sch.lu', FALSE],
    ];
  }

}
