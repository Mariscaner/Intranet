<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_annuaire\Unit;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\mersch_annuaire\ListeParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Lecture de la liste du personnel (données fictives).
 */
#[CoversClass(ListeParser::class)]
#[Group('mersch_annuaire')]
class ListeParserTest extends UnitTestCase {

  /**
   * En-tête de la liste Excel, colonne « mail service » sans titre.
   */
  private const ENTETE = ['Nom/Prénom', 'Tél.', 'Gsm', 'Mail', '', 'Service', 'Bâtiment', 'Remarques'];

  private ListeParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = new ListeParser(new PhpTransliteration());
  }

  /**
   * Colonnes, nom composé, GSM et e-mail du service.
   */
  public function testFiche(): void {
    $resultat = $this->parser->analyser([
      self::ENTETE,
      ['DUPONT DA SILVA Jean Paul', '32 50 23  999', 621000111, 'Jean.Dupont@exemple.lu', 'service@exemple.lu', 'SR  - Voirie', 'Atelier', 'Contre-maître'],
    ]);
    $this->assertSame([[
      'ligne' => '2',
      'nom' => 'DUPONT DA SILVA',
      'prenom' => 'Jean Paul',
      'service' => 'SR - Voirie',
      'batiment' => 'Atelier',
      'telephone' => '32 50 23 999',
      'gsm' => '621 000 111',
      'email' => 'jean.dupont@exemple.lu',
      'email_service' => 'service@exemple.lu',
      'remarque' => 'Contre-maître',
    ]], $resultat['agents']);
    $this->assertSame([], $resultat['ignores']);
  }

  /**
   * Lignes ignorées : sans service, sans prénom, exclues, doublons, vides.
   */
  public function testLignesIgnorees(): void {
    $resultat = $this->parser->analyser([
      ['Liste du personnel'],
      self::ENTETE,
      ['Cuisine Annexe', '32 50 23 000', '', '', '', '', 'Annexe', ''],
      ['ACCUEIL PISCINE', '', '', '', '', 'Piscine', '', ''],
      ['Salle de réunion', '', '', '', '', 'IT', '', ''],
      ['MARTIN Anne', '', '', '', '', 'Recette', '', ''],
      ['', '', '', '', '', '', '', ''],
      ['MARTIN Anne', '', '', '', '', 'Recette', '', ''],
    ], ['accueil piscine']);

    $this->assertCount(1, $resultat['agents']);
    $this->assertSame(
      [[3, 'service_manquant'], [4, 'exclu'], [5, 'nom_non_reconnu'], [8, 'doublon']],
      array_map(fn ($i) => [$i['ligne'], $i['motif']], $resultat['ignores']),
    );
  }

  /**
   * Prénoms saisis en majuscules ou minuscules.
   */
  public function testPrenoms(): void {
    $resultat = $this->parser->analyser([
      self::ENTETE,
      ['DUPONT MARIE', '', '', '', '', 'Piscine', '', ''],
      ['MARTIN luc', '', '', '', '', 'Piscine', '', ''],
      ['WEBER Anne-Marie', '', '', '', '', 'Piscine', '', ''],
      ['GÖRGEN Gaëtan', '', '', '', '', 'Piscine', '', ''],
    ]);
    $this->assertSame(
      [['DUPONT', 'Marie'], ['MARTIN', 'Luc'], ['WEBER', 'Anne-Marie'], ['GÖRGEN', 'Gaëtan']],
      array_map(fn ($a) => [$a['nom'], $a['prenom']], $resultat['agents']),
    );
  }

  /**
   * Variantes d'un service regroupées, services proches et e-mails invalides signalés.
   */
  public function testServicesEtAvertissements(): void {
    $resultat = $this->parser->analyser([
      self::ENTETE,
      ['A Un', '', '', 'a@exemple.lu', '', 'ST - Secretariat', '', ''],
      ['B Deux', '', '', 'b@exemple.lu', '', 'ST - Secretariat', '', ''],
      ['C Trois', '', '', 'pas-une-adresse', '', 'ST - Secrétariat', '', ''],
      ['D Quatre', '', '', '', '', 'ST - Autorisations de bâtir', '', ''],
      ['E Cinq', '', '', '', '', 'ST - Autoristaion de bâtir', '', ''],
      ['F Six', '', '', '', '', 'SR - Bâtiments', '', ''],
      ['G Sept', '', '', '', '', 'ST - Bâtiments', '', ''],
    ]);

    $services = array_unique(array_column($resultat['agents'], 'service'));
    $this->assertSame(['ST - Secrétariat', 'ST - Autorisations de bâtir', 'ST - Autoristaion de bâtir', 'SR - Bâtiments', 'ST - Bâtiments'], array_values($services));
    $this->assertSame('', $resultat['agents'][2]['email']);
    $this->assertSame(
      ['email_invalide', 'variantes_service', 'services_proches'],
      array_column($resultat['avertissements'], 'type'),
    );
  }

  /**
   * Sigle de tête remplacé par le nom complet du service.
   */
  public function testSigles(): void {
    $resultat = $this->parser->analyser([
      self::ENTETE,
      ['A Un', '', '', '', '', 'ST - chef', '', ''],
      ['B Deux', '', '', '', '', 'st', '', ''],
      ['C Trois', '', '', '', '', 'STEP', '', ''],
    ], [], ['ST' => 'Service technique']);
    $this->assertSame(
      ['Service technique - Chef', 'Service technique', 'STEP'],
      array_column($resultat['agents'], 'service'),
    );
  }

  /**
   * Fichier sans colonnes Nom/Prénom et Service.
   */
  public function testEnteteIntrouvable(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->parser->analyser([['Nom', 'Téléphone'], ['DUPONT Jean', '123']]);
  }

}
