<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_dsknet\Unit;

use Drupal\mersch_dsknet\Correspondance;
use Drupal\mersch_dsknet\Donnees;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Mise en forme des réponses DSK : état, solde, compteurs, absences.
 */
#[Group('mersch_dsknet')]
class DonneesTest extends UnitTestCase {

  private const TYPES = ['E' => 'entree', 'S' => 'sortie', 'D' => 'deplacement'];

  public function testEtat(): void {
    $this->assertSame(['code' => 'aucun', 'heure' => NULL], Donnees::etatDepuis([], self::TYPES));
    $pointages = [
      ['index' => 2, 'time' => '12:01:00', 'type' => 's'],
      ['index' => 1, 'time' => '08:39:00', 'type' => 'E'],
      ['index' => 3, 'bk' => 781, 'type' => 'X', 'subType' => 'D'],
    ];
    $this->assertSame(['code' => 'deplacement', 'heure' => '13:01'], Donnees::etatDepuis($pointages, self::TYPES));
    $this->assertSame('sortie', Donnees::etatDepuis(array_slice($pointages, 0, 2), self::TYPES)['code']);
    $this->assertSame('inconnu', Donnees::etatDepuis([['type' => 'Z']], self::TYPES)['code']);
  }

  public function testSolde(): void {
    $this->assertSame(['valeur' => '1:08', 'negatif' => FALSE], Donnees::soldeDepuis(['timeBalance' => 68, 'timeBalanceStr' => '01:08']));
    $this->assertSame(['valeur' => '-0:45', 'negatif' => TRUE], Donnees::soldeDepuis(['timeBalance' => -45]));
    $this->assertSame(['valeur' => '-02:00', 'negatif' => TRUE], Donnees::soldeDepuis(['timeBalanceStr' => '-02:00']));
    $this->assertSame(['valeur' => NULL, 'negatif' => FALSE], Donnees::soldeDepuis(NULL));
  }

  public function testCompteurs(): void {
    $conge = ['noCounter' => 1, 'orderCode' => 2, 'caption' => [['langID' => 1, 'caption' => 'Congé'], ['langID' => 2, 'caption' => 'Urlaub']], 'inDay' => TRUE, 'rest' => 1.82, 'visibility' => ['visibleDashboard' => TRUE]];
    $cet = ['noCounter' => 2, 'orderCode' => 1, 'caption' => [['langID' => 1, 'caption' => 'CET']], 'inDay' => FALSE, 'rest' => 1.3166, 'visibility' => ['visibleDashboard' => TRUE]];
    $cache = ['noCounter' => 3, 'caption' => [['langID' => 1, 'caption' => 'Maladie']], 'rest' => 5, 'visibility' => ['visibleDashboard' => FALSE]];
    $resultat = Donnees::compteursDepuis([$conge, $cet, $cache], 2);
    $this->assertSame([
      ['libelle' => 'CET', 'valeur' => '1:19', 'unite' => 'h', 'negatif' => FALSE],
      ['libelle' => 'Urlaub', 'valeur' => '1,82', 'unite' => 'j', 'negatif' => FALSE],
    ], $resultat);
    // Ancienne version sans « visibility » : réglage, sinon tous.
    unset($conge['visibility'], $cet['visibility'], $cache['visibility']);
    $this->assertCount(1, Donnees::compteursDepuis([$conge, $cet, $cache], NULL, [1]));
    $this->assertCount(3, Donnees::compteursDepuis([$conge, $cet, $cache], NULL));
  }

  public function testPeriodes(): void {
    $jours = [
      ['theDate' => '2026-11-23', 'noAccount' => 10],
      ['theDate' => '2026-11-20', 'noAccount' => 10],
      ['theDate' => '2026-11-24', 'noAccount' => 10],
      ['theDate' => '2026-12-01', 'noAccount' => 10, 'whenAbs' => 'M'],
      ['theDate' => '2026-12-08', 'noAccount' => 11, 'whenAbs' => 'M'],
      ['theDate' => '2026-12-08', 'noAccount' => 11, 'whenAbs' => 'A'],
    ];
    $this->assertSame([
      ['debut' => '2026-11-20', 'fin' => '2026-11-24', 'compte' => 10, 'moment' => NULL],
      ['debut' => '2026-12-01', 'fin' => '2026-12-01', 'compte' => 10, 'moment' => 'M'],
      ['debut' => '2026-12-08', 'fin' => '2026-12-08', 'compte' => 11, 'moment' => NULL],
    ], Donnees::periodes($jours));
  }

  public function testFormats(): void {
    $this->assertSame('14:33', Donnees::heures(873));
    $this->assertSame('12', Donnees::jours(12.0));
    $this->assertSame('0,5', Donnees::jours(0.5));
    $this->assertSame('Congé', Donnees::libelle([['langID' => 1, 'caption' => 'Congé']], 9));
    $this->assertSame([12, 13], Correspondance::numeros([12, ['noPers' => 13], ['noPers' => 12], 'x']));
    // DSK-API 1.6 : /listAll renvoie [{pers: {noPers}}].
    $this->assertSame([7], Correspondance::numeros([['pers' => ['noPers' => 7, 'firstName' => 'X']]]));
  }

  public function testLogins(): void {
    $this->assertSame('bema', Correspondance::normaliser('DOMAIN\\BEMA'));
    $this->assertSame('bema', Correspondance::normaliser(' bema@domain.local'));
    $liste = [
      ['pers' => ['noPers' => 12, 'login' => 'BEMA', 'firstName' => 'Marc']],
      ['pers' => ['noPers' => 13, 'login' => 'DOMAIN\\abcd']],
      ['pers' => ['noPers' => 14, 'login' => 'xy']],
      ['pers' => ['noPers' => 15, 'login' => 'XY']],
      ['pers' => ['noPers' => 16, 'login' => '']],
    ];
    $this->assertSame(['bema' => 12, 'abcd' => 13], Correspondance::logins($liste));
  }

}
