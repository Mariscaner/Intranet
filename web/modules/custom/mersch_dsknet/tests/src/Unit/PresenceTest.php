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

  public function testSansPointage(): void {
    $this->assertSame('absent', Presence::etatDepuis([], self::TYPES));
  }

  public function testCodesConnus(): void {
    $this->assertSame('deplacement', Presence::etatDepuis([['index' => 1, 'type' => 'B1'], ['index' => 2, 'type' => 'X', 'subType' => 'mission']], self::TYPES));
    $this->assertSame('present', Presence::etatDepuis([['index' => 1, 'type' => 'B1'], ['index' => 2, 'type' => 'E']], self::TYPES));
    $this->assertSame('absent', Presence::etatDepuis([['index' => 1, 'type' => 's']], self::TYPES));
  }

  public function testAlternance(): void {
    $b1 = fn (int $i): array => ['index' => $i, 'type' => 'B1', 'bk' => 400 + $i];
    $this->assertSame('present', Presence::etatDepuis([$b1(1)], self::TYPES));
    $this->assertSame('absent', Presence::etatDepuis([$b1(2), $b1(1)], self::TYPES));
    $this->assertSame('present', Presence::etatDepuis([$b1(0), $b1(1), $b1(2)], self::TYPES));
  }

  public function testPointagesDuJour(): void {
    // DSK-API 1.6 : liste de pointages sous bk.
    $ligne = ['pers' => ['noPers' => 7], 'bk' => [
      ['dateTime' => self::JOUR . ' 07:45:00', 'type' => 'B1', 'index' => 1],
      ['dateTime' => '2026-11-17 16:00:00', 'type' => 'B1', 'index' => 2],
    ], 'bkReq' => [['type' => 'B1']]];
    $this->assertCount(1, Presence::pointagesDuJour($ligne, self::JOUR));
    // Doc 1.8 : days[] avec theDate et bookings.
    $ligne = ['noPers' => 7, 'days' => [['theDate' => self::JOUR, 'bookings' => [['type' => 'B1', 'index' => 1], ['type' => 'B1', 'index' => 2]]]]];
    $this->assertCount(2, Presence::pointagesDuJour($ligne, self::JOUR));
    // Jours indexés par date.
    $ligne = ['bk' => ['2026-11-18' => [['type' => 'B1']], '2026-11-17' => [['type' => 'B1']]]];
    $this->assertCount(1, Presence::pointagesDuJour($ligne, self::JOUR));
    $this->assertSame([], Presence::pointagesDuJour(['pers' => [], 'bk' => []], self::JOUR));
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
