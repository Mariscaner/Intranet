<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\ContentEntityInterface as EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\mersch_annuaire\ListeParser;

/**
 * État de présence des agents de l'annuaire (US-36 à US-38, D-044).
 *
 * Un seul appel /presAbs pour tous les agents, gardé une minute en cache.
 * Seul l'état du moment (present, absent, deplacement) en sort : ni motif,
 * ni type d'absence, ni date de retour, ni heure. La seule donnée
 * conservée est le n° DSK de chaque fiche de l'annuaire.
 */
class Presence {

  public const PRESENT = 'present';
  public const ABSENT = 'absent';
  public const DEPLACEMENT = 'deplacement';

  /**
   * Clé state : n° DSK de chaque fiche de l'annuaire [clé fiche => noPers].
   *
   * La clé (e-mail, sinon nom et prénom) survit à un nouvel import de la
   * liste Excel, qui recrée les fiches avec de nouveaux id.
   */
  public const LIAISONS = 'mersch_dsknet.presence_agents';

  /**
   * Durée de vie du cache des états (secondes).
   */
  public const DUREE = 60;

  private const CLE_CACHE = 'mersch_dsknet:presence';

  public function __construct(
    private readonly Client $client,
    private readonly Reglages $reglages,
    private readonly Correspondance $correspondance,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ListeParser $parser,
    private readonly StateInterface $state,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * États des fiches reliées, [id fiche => état].
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   * @throws \Drupal\mersch_dsknet\AppelAbsent
   */
  public function etats(): array {
    $cache = $this->cache->get(self::CLE_CACHE);
    if ($cache && is_array($cache->data)) {
      return $cache->data;
    }
    $liaisons = (array) $this->state->get(self::LIAISONS, []);
    $parNoPers = $liaisons ? $this->etatsDsk() : [];
    $etats = [];
    if ($parNoPers) {
      foreach ($this->entityTypeManager->getStorage('mersch_agent')->loadMultiple() as $fiche) {
        $noPers = (int) ($liaisons[$this->cleFiche($fiche)] ?? 0);
        if (isset($parNoPers[$noPers])) {
          $etats[(int) $fiche->id()] = $parNoPers[$noPers];
        }
      }
    }
    $this->cache->set(self::CLE_CACHE, $etats, $this->time->getRequestTime() + self::DUREE);
    return $etats;
  }

  /**
   * États DSK du moment, [noPers => état] (le reste de /presAbs est jeté).
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   * @throws \Drupal\mersch_dsknet\AppelAbsent
   */
  public function etatsDsk(): array {
    $lignes = $this->client->get('/presAbs', ['count' => 1000]);
    $jour = date('Y-m-d', $this->time->getRequestTime());
    $types = (array) $this->reglages->get('types_pointage');
    $base = (int) $this->reglages->get('presence_index_base');
    $etats = [];
    foreach (is_array($lignes) ? $lignes : [] as $ligne) {
      $noPers = is_array($ligne) ? (int) ($ligne['noPers'] ?? 0) : 0;
      if ($noPers > 0 && ($etat = self::etatDepuis($ligne, $jour, $types, $base))) {
        $etats[$noPers] = $etat;
      }
    }
    return $etats;
  }

  /**
   * État d'une ligne /presAbs, ou NULL s'il ne peut être déduit.
   *
   * Dernier pointage du jour : code « déplacement » (réglage types_pointage)
   * → deplacement ; code d'entrée ou de sortie connu → present / absent ;
   * code générique (B1) → alternance, un n° de pointage impair = entré.
   * Aucun pointage aujourd'hui → absent.
   *
   * @param array $ligne
   *   Ligne de /presAbs (RS_PersPresAbs).
   * @param string $jour
   *   Date du jour, AAAA-MM-JJ.
   * @param array $types
   *   Réglage types_pointage (code DSK → entree, sortie, deplacement).
   * @param int $base
   *   N° (index) du premier pointage du jour chez DSK : 1 ou 0.
   */
  public static function etatDepuis(array $ligne, string $jour, array $types, int $base = 1): ?string {
    $pointage = is_array($ligne['lastBooking'] ?? NULL) ? $ligne['lastBooking'] : NULL;
    $date = $pointage ? substr((string) ($pointage['dateTime'] ?? $pointage['date'] ?? ''), 0, 10) : '';
    if (!$pointage || $date !== $jour) {
      return self::ABSENT;
    }
    $types = array_change_key_case($types, CASE_UPPER);
    foreach (['type', 'subType'] as $champ) {
      $valeur = strtoupper(trim((string) ($pointage[$champ] ?? '')));
      $code = $valeur !== '' ? ($types[$valeur] ?? NULL) : NULL;
      if ($code !== NULL) {
        return match ($code) {
          'deplacement' => self::DEPLACEMENT,
          'entree' => self::PRESENT,
          'sortie' => self::ABSENT,
          default => NULL,
        };
      }
    }
    if (!isset($pointage['index']) || !is_numeric($pointage['index'])) {
      return NULL;
    }
    $rang = (int) $pointage['index'] - $base + 1;
    return $rang % 2 === 1 ? self::PRESENT : self::ABSENT;
  }

  /**
   * Relie les fiches de l'annuaire aux agents DSK (US-38).
   *
   * 1. E-mail de la fiche = e-mail d'un compte relié à DSK (D-043).
   * 2. Sinon, nom et prénom égaux à ceux d'un seul agent DSK actif.
   * Seul le n° DSK est gardé ; les noms lus dans DSK sont jetés aussitôt.
   *
   * @return array
   *   ['relies' => int, 'non_relies' => string[] (nom prénom)].
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   */
  public function relierAnnuaire(): array {
    $this->state->set('mersch_dsknet.presence_relie', $this->time->getRequestTime());
    $fiches = $this->entityTypeManager->getStorage('mersch_agent')->loadMultiple();
    $comptes = $this->comptesParEmail();
    $noms = NULL;
    $liaisons = [];
    $nonRelies = [];
    foreach ($fiches as $fiche) {
      $email = mb_strtolower(trim((string) $fiche->get('email')->value));
      $noPers = NULL;
      if ($email !== '' && isset($comptes[$email])) {
        $uid = $comptes[$email];
        $noPers = $this->correspondance->noPersSaisi($uid) ?? $this->correspondance->noPersAuto($uid);
      }
      if (!$noPers) {
        // Liste DSK lue une seule fois, au premier besoin.
        $noms ??= $this->nomsDsk();
        $cle = $this->cleNom((string) $fiche->get('nom')->value, (string) $fiche->get('prenom')->value);
        $candidats = $noms[$cle] ?? [];
        $noPers = count($candidats) === 1 ? $candidats[0] : NULL;
      }
      if ($noPers) {
        $liaisons[$this->cleFiche($fiche)] = $noPers;
      }
      else {
        $nonRelies[] = trim($fiche->get('nom')->value . ' ' . $fiche->get('prenom')->value);
      }
    }
    $this->state->set(self::LIAISONS, $liaisons);
    $this->cache->delete(self::CLE_CACHE);
    sort($nonRelies);
    return ['relies' => count($liaisons), 'non_relies' => $nonRelies];
  }

  /**
   * Comptes du site par e-mail en minuscules, [e-mail => uid].
   */
  private function comptesParEmail(): array {
    $comptes = [];
    $stockage = $this->entityTypeManager->getStorage('user');
    $ids = $stockage->getQuery()->accessCheck(FALSE)->condition('uid', 0, '>')->exists('mail')->execute();
    foreach ($stockage->loadMultiple($ids) as $compte) {
      $mail = mb_strtolower(trim((string) $compte->getEmail()));
      if ($mail !== '') {
        $comptes[$mail] = (int) $compte->id();
      }
    }
    return $comptes;
  }

  /**
   * N° DSK des agents actifs par clé de nom, [clé => noPers[]].
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   */
  private function nomsDsk(): array {
    try {
      $fiches = $this->correspondance->fiches();
    }
    catch (AppelAbsent) {
      return [];
    }
    return self::grouperNoms($fiches, fn (string $nom, string $prenom): string => $this->cleNom($nom, $prenom));
  }

  /**
   * Regroupe des fiches DSK (pers.lastName, firstName, noPers) par clé.
   *
   * @return array<string, int[]>
   */
  public static function grouperNoms(array $fiches, callable $cle): array {
    $noms = [];
    foreach ($fiches as $ligne) {
      $pers = is_array($ligne) ? ($ligne['pers'] ?? $ligne) : NULL;
      $noPers = is_array($pers) ? (int) ($pers['noPers'] ?? 0) : 0;
      $nom = trim((string) ($pers['lastName'] ?? ''));
      if ($noPers <= 0 || $nom === '') {
        continue;
      }
      $k = $cle($nom, (string) ($pers['firstName'] ?? ''));
      if (!in_array($noPers, $noms[$k] ?? [], TRUE)) {
        $noms[$k][] = $noPers;
      }
    }
    return $noms;
  }

  /**
   * Clé stable d'une fiche : e-mail en minuscules, sinon nom et prénom.
   */
  public function cleFiche(EntityInterface $fiche): string {
    $email = mb_strtolower(trim((string) $fiche->get('email')->value));
    return $email !== '' ? $email : 'nom:' . $this->cleNom((string) $fiche->get('nom')->value, (string) $fiche->get('prenom')->value);
  }

  /**
   * Clé de comparaison : sans accents ni majuscules, tirets et espaces unifiés.
   */
  public function cleNom(string $nom, string $prenom): string {
    return self::nettoyer($this->parser->cle($nom . ' ' . $prenom));
  }

  /**
   * Garde lettres et chiffres, un espace entre les mots.
   */
  public static function nettoyer(string $texte): string {
    return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($texte)));
  }

}
