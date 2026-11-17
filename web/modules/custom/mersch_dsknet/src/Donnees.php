<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Lit les données DSKnet d'un agent et les met en forme pour la page.
 *
 * Rien n'est enregistré : chaque affichage relit DSKnet. Seuls les libellés
 * des types d'absence (communs à tous, sans donnée personnelle) sont gardés
 * en cache un jour. Chaque bloc échoue seul (appel absent de la version
 * installée, DSKnet injoignable) sans empêcher les autres.
 */
class Donnees {

  public const ABSENT = 'absent';
  public const INDISPONIBLE = 'indisponible';

  public function __construct(
    private readonly Client $client,
    private readonly Reglages $reglages,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Les quatre blocs de la page pour l'agent.
   *
   * @return array
   *   Clés etat, solde, compteurs, absences ; chacune contient les données
   *   ou ['erreur' => self::ABSENT|self::INDISPONIBLE].
   */
  public function tout(int $noPers, string $langue): array {
    $resultat = [];
    foreach (['etat', 'solde', 'compteurs', 'absences'] as $bloc) {
      try {
        $resultat[$bloc] = $this->$bloc($noPers, $langue);
      }
      catch (AppelAbsent) {
        $resultat[$bloc] = ['erreur' => self::ABSENT];
      }
      catch (DsknetIndisponible) {
        $resultat[$bloc] = ['erreur' => self::INDISPONIBLE];
      }
    }
    return $resultat;
  }

  /**
   * État : dernier pointage du jour (entrée, sortie, déplacement).
   */
  public function etat(int $noPers, string $langue = ''): array {
    $jour = $this->bruts('dayBookings', $noPers);
    return self::etatDepuis(is_array($jour) ? ($jour['dayBookings'] ?? []) : [], (array) $this->reglages->get('types_pointage'));
  }

  /**
   * Solde d'heures du mois en cours.
   */
  public function solde(int $noPers, string $langue = ''): array {
    return self::soldeDepuis($this->bruts('solde', $noPers));
  }

  /**
   * Compteurs du tableau de bord DSK (congé, CET…), valeur restante.
   */
  public function compteurs(int $noPers, string $langue): array {
    return self::compteursDepuis(
      $this->bruts('counters', $noPers),
      $this->langId($langue),
      array_map('intval', (array) $this->reglages->get('compteurs')),
    );
  }

  /**
   * Absences accordées d'aujourd'hui à N mois, regroupées en périodes.
   */
  public function absences(int $noPers, string $langue): array {
    $bruts = $this->bruts('absences', $noPers);
    // DSK-API 1.6 : objet dont la liste des jours est sous « abs ».
    $periodes = self::periodes(is_array($bruts) && isset($bruts['abs']) ? $bruts['abs'] : $bruts);
    $langId = $this->langId($langue);
    foreach ($periodes as &$periode) {
      $periode['libelle'] = $this->libelleCompte($periode['compte'], $langId);
      unset($periode['compte']);
    }
    return $periodes;
  }

  /**
   * Réponse brute d'un appel (pour la page et le diagnostic).
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   */
  public function bruts(string $appel, int $noPers): mixed {
    $maintenant = $this->time->getRequestTime();
    $jour = date('Y-m-d', $maintenant);
    return match ($appel) {
      'dayBookings' => $this->client->get("/pers/$noPers/dayBookings", ['theDate' => $jour]),
      'solde' => $this->client->get("/pers/$noPers/solde", ['previousMonth' => $jour]),
      'counters' => $this->client->get("/pers/$noPers/counters", ['refYear' => date('Y', $maintenant)]),
      'absences' => $this->client->get("/pers/$noPers/absences", [
        'dateFrom' => $jour,
        'dateTo' => date('Y-m-d', strtotime('+' . max(1, (int) $this->reglages->get('absences_mois')) . ' months', $maintenant)),
      ]),
    };
  }

  /**
   * Libellé d'un type d'absence, gardé un jour en cache (commun à tous).
   */
  private function libelleCompte(int $noAccount, ?int $langId): string {
    $cle = "mersch_dsknet:compte:$noAccount";
    $libelles = $this->cache->get($cle)->data ?? NULL;
    if (!is_array($libelles)) {
      try {
        $compte = $this->client->get("/account/$noAccount");
        $libelles = is_array($compte) ? ($compte['caption'] ?? []) : [];
        $this->cache->set($cle, $libelles, $this->time->getRequestTime() + 86400);
      }
      catch (DsknetIndisponible) {
        $libelles = [];
      }
    }
    return self::libelle($libelles, $langId);
  }

  /**
   * LangID DSK de la langue d'interface, ou NULL si non réglé.
   */
  private function langId(string $langue): ?int {
    $langues = (array) $this->reglages->get('langues');
    return isset($langues[$langue]) ? (int) $langues[$langue] : NULL;
  }

  /**
   * Met en forme l'état à partir des pointages du jour.
   */
  public static function etatDepuis(array $pointages, array $types): array {
    $pointages = array_values(array_filter($pointages, 'is_array'));
    if (!$pointages) {
      return ['code' => 'aucun', 'heure' => NULL];
    }
    usort($pointages, fn (array $a, array $b): int => [($a['index'] ?? 0), ($a['bk'] ?? 0)] <=> [($b['index'] ?? 0), ($b['bk'] ?? 0)]);
    $dernier = end($pointages);
    $types = array_change_key_case($types, CASE_UPPER);
    $code = 'inconnu';
    foreach (['type', 'subType'] as $champ) {
      $valeur = strtoupper(trim((string) ($dernier[$champ] ?? '')));
      if ($valeur !== '' && isset($types[$valeur])) {
        $code = $types[$valeur];
        break;
      }
    }
    $heure = NULL;
    if (!empty($dernier['time'])) {
      $heure = substr((string) $dernier['time'], 0, 5);
    }
    elseif (isset($dernier['bk']) && is_numeric($dernier['bk'])) {
      $heure = self::heures((int) $dernier['bk']);
    }
    return ['code' => $code, 'heure' => $heure];
  }

  /**
   * Met en forme le solde d'heures du mois.
   */
  public static function soldeDepuis(mixed $solde): array {
    if (!is_array($solde)) {
      return ['valeur' => NULL, 'negatif' => FALSE];
    }
    if (isset($solde['timeBalance']) && is_numeric($solde['timeBalance'])) {
      $minutes = (int) $solde['timeBalance'];
      return ['valeur' => self::heures($minutes), 'negatif' => $minutes < 0];
    }
    $texte = trim((string) ($solde['timeBalanceStr'] ?? ''));
    return ['valeur' => $texte !== '' ? $texte : NULL, 'negatif' => str_starts_with($texte, '-')];
  }

  /**
   * Compteurs à afficher, avec leur valeur restante.
   *
   * Si DSK dit lesquels vont sur son tableau de bord (visibleDashboard), ce
   * sont ceux-là ; sinon ceux du réglage « compteurs » ; sinon tous.
   */
  public static function compteursDepuis(mixed $compteurs, ?int $langId, array $choisis = []): array {
    $compteurs = array_values(array_filter(is_array($compteurs) ? $compteurs : [], 'is_array'));
    $avecVisibilite = array_filter($compteurs, fn (array $c): bool => isset($c['visibility']['visibleDashboard']));
    if ($avecVisibilite) {
      $compteurs = array_filter($compteurs, fn (array $c): bool => !empty($c['visibility']['visibleDashboard']));
    }
    elseif ($choisis) {
      $compteurs = array_filter($compteurs, fn (array $c): bool => in_array((int) ($c['noCounter'] ?? 0), $choisis, TRUE));
    }
    usort($compteurs, fn (array $a, array $b): int => ($a['orderCode'] ?? 0) <=> ($b['orderCode'] ?? 0));
    $resultat = [];
    foreach ($compteurs as $c) {
      $reste = $c['rest'] ?? $c['balance'] ?? NULL;
      if (!is_numeric($reste)) {
        continue;
      }
      $enJours = !empty($c['inDay']);
      $resultat[] = [
        'libelle' => self::libelle($c['caption'] ?? [], $langId),
        'valeur' => $enJours ? self::jours((float) $reste) : self::heures((int) round((float) $reste * 60)),
        'unite' => $enJours ? 'j' : 'h',
        'negatif' => (float) $reste < 0,
      ];
    }
    return $resultat;
  }

  /**
   * Regroupe les jours d'absence (une ligne par jour) en périodes.
   *
   * Deux jours du même type séparés de 3 jours au plus (week-end, jour
   * férié) forment une seule période.
   *
   * @return array
   *   Liste de ['debut', 'fin', 'compte', 'moment' (M, A ou NULL)].
   */
  public static function periodes(mixed $jours): array {
    $jours = array_values(array_filter(is_array($jours) ? $jours : [], fn ($j): bool => is_array($j) && !empty($j['theDate']) && isset($j['noAccount'])));
    usort($jours, fn (array $a, array $b): int => strcmp(substr((string) $a['theDate'], 0, 10), substr((string) $b['theDate'], 0, 10)));
    $periodes = [];
    $courante = NULL;
    foreach ($jours as $j) {
      $date = substr((string) $j['theDate'], 0, 10);
      $compte = (int) $j['noAccount'];
      $moment = in_array($j['whenAbs'] ?? NULL, ['M', 'A'], TRUE) ? $j['whenAbs'] : NULL;
      if ($courante && $courante['compte'] === $compte && $courante['moment'] === NULL && $moment === NULL
        && (strtotime($date) - strtotime($courante['fin'])) <= 3 * 86400) {
        $courante['fin'] = $date;
        continue;
      }
      if ($courante && $courante['compte'] === $compte && $courante['fin'] === $date) {
        // Matin et après-midi du même jour : journée entière.
        $courante['moment'] = NULL;
        continue;
      }
      if ($courante) {
        $periodes[] = $courante;
      }
      $courante = ['debut' => $date, 'fin' => $date, 'compte' => $compte, 'moment' => $moment];
    }
    if ($courante) {
      $periodes[] = $courante;
    }
    return $periodes;
  }

  /**
   * Libellé DSK dans la langue voulue, sinon le premier.
   */
  public static function libelle(mixed $libelles, ?int $langId): string {
    $libelles = array_values(array_filter(is_array($libelles) ? $libelles : [], fn ($l): bool => is_array($l) && trim((string) ($l['caption'] ?? '')) !== ''));
    foreach ($libelles as $l) {
      if ($langId !== NULL && (int) ($l['langID'] ?? -1) === $langId) {
        return trim((string) $l['caption']);
      }
    }
    return $libelles ? trim((string) $libelles[0]['caption']) : '';
  }

  /**
   * Minutes → « 1:08 », « -0:45 », « 14:33 ».
   */
  public static function heures(int $minutes): string {
    $signe = $minutes < 0 ? '-' : '';
    $minutes = abs($minutes);
    return sprintf('%s%d:%02d', $signe, intdiv($minutes, 60), $minutes % 60);
  }

  /**
   * Jours → « 1,82 », « 12 », « 0,5 ».
   */
  public static function jours(float $jours): string {
    $texte = rtrim(rtrim(number_format($jours, 2, ',', ''), '0'), ',');
    return $texte === '-0' ? '0' : $texte;
  }

}
