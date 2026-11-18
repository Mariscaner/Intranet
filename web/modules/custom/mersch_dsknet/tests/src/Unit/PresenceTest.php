<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_dsknet\Unit;

use Drupal\mersch_dsknet\Correspondance;
use Drupal\mersch_dsknet\Presence;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * État de présence de l'annuaire (D-044) et liaison par nom.
 */
#[Group('mersch_dsknet')]
class PresenceTest extends UnitTestCase {

  private const TYPES = ['D' => 'deplacement', 'MISSION' => 'deplacement', 'E' => 'entree', 'S' => 'sortie'];
  private const JOUR = '2026-11-18';

  public function testSansPointageDuJour(): void {
    $this->assertSame('absent', Presence::etatDepuis(['noPers' => 1], self::JOUR, self::TYPES));
    $hier = ['lastBooking' => ['dateTime' => '2026-11-17T16:30:00', 'type' => 'B1', 'index' => 1]];
    $this->assertSame('absent', Presence::etatDepuis($hier, self::JOUR, self::TYPES));
  }

  public function testCodesConnus(): void {
    $ligne = fn (array $p): array => ['lastBooking' => $p + ['date' => self::JOUR]];
    $this->assertSame('deplacement', Presence::etatDepuis($ligne(['type' => 'X', 'subType' => 'mission', 'index' => 3]), self::JOUR, self::TYPES));
    $this->assertSame('present', Presence::etatDepuis($ligne(['type' => 'E', 'index' => 2]), self::JOUR, self::TYPES));
    $this->assertSame('absent', Presence::etatDepuis($ligne(['type' => 's', 'index' => 1]), self::JOUR, self::TYPES));
  }

  public function testAlternance(): void {
    $ligne = fn (int $index): array => ['lastBooking' => ['dateTime' => self::JOUR . 'T08:00:00', 'type' => 'B1', 'index' => $index]];
    $this->assertSame('present', Presence::etatDepuis($ligne(1), self::JOUR, self::TYPES));
    $this->assertSame('absent', Presence::etatDepuis($ligne(2), self::JOUR, self::TYPES));
    $this->assertSame('present', Presence::etatDepuis($ligne(3), self::JOUR, self::TYPES));
    // DSK qui compte à partir de 0.
    $this->assertSame('present', Presence::etatDepuis($ligne(0), self::JOUR, self::TYPES, 0));
    $this->assertSame('absent', Presence::etatDepuis($ligne(1), self::JOUR, self::TYPES, 0));
    // Sans n° de pointage : pas d'état plutôt qu'un état faux.
    $this->assertNull(Presence::etatDepuis(['lastBooking' => ['date' => self::JOUR, 'type' => 'B1']], self::JOUR, self::TYPES));
  }

  public function testGrouperNoms(): void {
    $fiches = [
      ['pers' => ['noPers' => 147, 'lastName' => 'BETTENDORFF', 'firstName' => 'Marc']],
      ['pers' => ['noPers' => 12, 'lastName' => 'Muller', 'firstName' => 'Eric']],
      ['pers' => ['noPers' => 13, 'lastName' => 'Muller', 'firstName' => 'Éric']],
      ['pers' => ['noPers' => 0, 'lastName' => 'Sans', 'firstName' => 'Numéro']],
    ];
    $cle = fn (string $nom, string $prenom): string => Presence::nettoyer(str_replace('É', 'E', $nom . ' ' . $prenom));
    $noms = Presence::grouperNoms($fiches, $cle);
    $this->assertSame([147], $noms['bettendorff marc']);
    // Homonymes : deux candidats, la fiche ne sera pas reliée.
    $this->assertSame([12, 13], $noms['muller eric']);
    $this->assertCount(2, $noms);
  }

  public function testNettoyer(): void {
    $this->assertSame('beckers weber anita', Presence::nettoyer("Beckers-Weber  Anita"));
    $this->assertSame('da conceicao fernandez sandra', Presence::nettoyer(' da Conceicao Fernandez, Sandra '));
  }

  public function testReduire(): void {
    $fiche = ['pers' => ['noPers' => 5, 'login' => 'bema', 'lastName' => 'B', 'firstName' => 'M', 'persId' => '1980…', 'badge' => '42']];
    $this->assertSame(['pers' => ['noPers' => 5, 'login' => 'bema', 'lastName' => 'B', 'firstName' => 'M']], Correspondance::reduire($fiche));
  }

}
