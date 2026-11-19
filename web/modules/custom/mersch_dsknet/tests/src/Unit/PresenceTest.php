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

  public function testTrouver(): void {
    $fiches = [
      ['pers' => ['noPers' => 147, 'lastName' => 'BETTENDORFF', 'firstName' => 'Marc']],
      ['pers' => ['noPers' => 12, 'lastName' => 'Muller', 'firstName' => 'Eric']],
      ['pers' => ['noPers' => 13, 'lastName' => 'Muller', 'firstName' => 'Eric']],
      ['pers' => ['noPers' => 20, 'lastName' => 'Bucari', 'firstName' => 'Daniel']],
      ['pers' => ['noPers' => 21, 'lastName' => 'Martins Franco', 'firstName' => 'Alexandra']],
      ['pers' => ['noPers' => 22, 'lastName' => 'Lopes', 'firstName' => 'Carlos Alexandre']],
      ['pers' => ['noPers' => 23, 'lastName' => 'Lopes Rocha', 'firstName' => 'Steven']],
      ['pers' => ['noPers' => 24, 'lastName' => 'Kass', 'firstName' => 'Victor']],
      ['pers' => ['noPers' => 25, 'lastName' => 'Kass', 'firstName' => 'Vicky']],
      ['pers' => ['noPers' => 0, 'lastName' => 'Sans', 'firstName' => 'Numero']],
    ];
    $noms = Presence::listerNoms($fiches, fn (string $nom, string $prenom): string => Presence::nettoyer($nom . ' ' . $prenom));
    $this->assertCount(9, $noms);
    $this->assertSame(147, Presence::trouver($noms, 'bettendorff', 'marc'));
    // Homonymes exacts : pas de liaison.
    $this->assertNull(Presence::trouver($noms, 'muller', 'eric'));
    // Prénom abrégé, nom composé, deuxième prénom.
    $this->assertSame(20, Presence::trouver($noms, 'bucari', 'dan'));
    $this->assertSame(21, Presence::trouver($noms, 'martins', 'alexandra'));
    $this->assertSame(22, Presence::trouver($noms, 'lopes', 'carlos'));
    // Deux candidats (Victor, Vicky) : pas de liaison.
    $this->assertNull(Presence::trouver($noms, 'kass', 'vic'));
    // Moins de 3 lettres ou nom différent : pas de liaison.
    $this->assertNull(Presence::trouver($noms, 'bucari', 'da'));
    $this->assertNull(Presence::trouver($noms, 'buca', 'daniel'));
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
