<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\mersch_sso\Comptes;
use Drupal\user\UserDataInterface;
use Psr\Log\LoggerInterface;

/**
 * Trouve le n° DSK (noPers) de l'agent connecté.
 *
 * Ordre : n° saisi par un admin sur le compte (user.data mersch_dsknet,
 * clé « nopers ») ; en mode « login » (défaut), n° relié automatiquement
 * (clé « nopers_auto ») : DSKNet authentifie ses agents par LDAP sur
 * l'AD (clé samAccountName), son champ login est donc l'identifiant AD
 * rattaché par le SSO ; en mode « codePers », recherche du code personnel
 * DSK égal à l'identifiant AD (n° non enregistré).
 *
 * Seul le n° DSK est gardé : la liste DSK lue pour relier les comptes est
 * jetée aussitôt (ni nom, ni autre donnée).
 */
class Correspondance {

  /**
   * N° déjà trouvés pendant la requête, par compte.
   *
   * @var array<int, int|null>
   */
  private array $trouves = [];

  public function __construct(
    private readonly Client $client,
    private readonly Reglages $reglages,
    private readonly UserDataInterface $userData,
    private readonly Comptes $comptes,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
  ) {}

  /**
   * N° relié automatiquement par le login DSK, ou NULL.
   */
  public function noPersAuto(int $uid): ?int {
    $valeur = $this->userData->get('mersch_dsknet', $uid, 'nopers_auto');
    return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : NULL;
  }

  /**
   * Relie les comptes SSO aux agents DSK par le login (identifiant AD).
   *
   * Lit une fois la liste des agents DSK actifs, n'en garde que les paires
   * login → n° DSK, puis réécrit le n° automatique de chaque compte SSO.
   *
   * @return array
   *   ['relies' => int, 'sans_dsk' => string[] (identifiants AD)].
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   */
  public function relierTous(): array {
    $this->state->set('mersch_dsknet.relie', $this->time->getRequestTime());
    $logins = $this->paires();
    $relies = 0;
    $sansDsk = [];
    foreach ($this->userData->get('mersch_sso', NULL, 'identifiant') as $uid => $identifiant) {
      $noPers = $logins[strtolower((string) $identifiant)] ?? NULL;
      if ($noPers) {
        $this->userData->set('mersch_dsknet', (int) $uid, 'nopers_auto', $noPers);
        $relies++;
      }
      else {
        $this->userData->delete('mersch_dsknet', (int) $uid, 'nopers_auto');
        $sansDsk[] = (string) $identifiant;
      }
    }
    return ['relies' => $relies, 'sans_dsk' => $sansDsk];
  }

  /**
   * Paires login → n° DSK des agents DSK actifs aujourd'hui.
   *
   * /listAll/details d'abord ; s'il manque (DSK-API 1.6), liste des n° par
   * /listAll/id puis /pers/{n}/details agent par agent. Seuls le login et
   * le n° sont gardés, le reste de chaque fiche est jeté aussitôt.
   *
   * @return array<string, int>
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   */
  public function paires(): array {
    $jour = date('Y-m-d', $this->time->getRequestTime());
    $periode = ['dateFrom' => $jour, 'dateTo' => $jour];
    try {
      return self::logins($this->client->get('/listAll/details', $periode));
    }
    catch (AppelAbsent) {
      // Repli agent par agent ci-dessous.
    }
    try {
      $numeros = self::numeros($this->client->get('/listAll/id', $periode));
    }
    catch (AppelAbsent) {
      $numeros = self::numeros($this->client->get('/listAll', $periode));
    }
    $fiches = [];
    foreach ($numeros as $noPers) {
      try {
        $fiche = $this->client->get("/pers/$noPers/details");
      }
      catch (AppelAbsent $e) {
        // Fiche d'un agent introuvable : on passe ; appel absent : on arrête.
        if (!$fiches) {
          throw $e;
        }
        continue;
      }
      $pers = is_array($fiche) ? ($fiche['pers'] ?? $fiche) : [];
      $fiches[] = ['pers' => ['noPers' => $pers['noPers'] ?? $noPers, 'login' => $pers['login'] ?? '']];
    }
    return self::logins($fiches);
  }

  /**
   * Paires login → n° DSK d'une réponse /listAll/details (le reste est jeté).
   *
   * Login accepté avec ou sans domaine : « bema », « DOMAIN\bema »,
   * « bema@domain.local ». Un login porté par deux agents est écarté.
   *
   * @return array<string, int>
   */
  public static function logins(mixed $donnees): array {
    $paires = [];
    $doublons = [];
    foreach (is_array($donnees) ? $donnees : [] as $ligne) {
      $pers = is_array($ligne) ? ($ligne['pers'] ?? $ligne) : NULL;
      $login = is_array($pers) ? self::normaliser((string) ($pers['login'] ?? '')) : '';
      $noPers = is_array($pers) ? (int) ($pers['noPers'] ?? 0) : 0;
      if ($login === '' || $noPers <= 0) {
        continue;
      }
      if (isset($paires[$login]) && $paires[$login] !== $noPers) {
        $doublons[$login] = TRUE;
      }
      $paires[$login] = $noPers;
    }
    return array_diff_key($paires, $doublons);
  }

  /**
   * Login DSK → identifiant AD en minuscules, sans domaine.
   */
  public static function normaliser(string $login): string {
    $login = strtolower(trim($login));
    if (str_contains($login, '\\')) {
      $login = substr($login, strrpos($login, '\\') + 1);
    }
    if (str_contains($login, '@')) {
      $login = strstr($login, '@', TRUE);
    }
    return $login;
  }

  /**
   * N° saisi par un admin, ou NULL.
   */
  public function noPersSaisi(int $uid): ?int {
    $valeur = $this->userData->get('mersch_dsknet', $uid, 'nopers');
    return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : NULL;
  }

  /**
   * Enregistre (ou efface, avec NULL) le n° saisi par un admin.
   */
  public function saisir(int $uid, ?int $noPers): void {
    if ($noPers) {
      $this->userData->set('mersch_dsknet', $uid, 'nopers', $noPers);
    }
    else {
      $this->userData->delete('mersch_dsknet', $uid, 'nopers');
    }
  }

  /**
   * N° DSK du compte, ou NULL si l'agent n'est pas relié.
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   */
  public function noPers(int $uid): ?int {
    if (array_key_exists($uid, $this->trouves)) {
      return $this->trouves[$uid];
    }
    $noPers = $this->noPersSaisi($uid);
    $mode = $this->reglages->get('correspondance');
    if (!$noPers && $mode === 'login') {
      $noPers = $this->noPersAuto($uid);
      // Agent nouveau (première connexion SSO) : relier au plus une fois par heure.
      if (!$noPers && $this->comptes->identifiantDe($uid) && $this->time->getRequestTime() - (int) $this->state->get('mersch_dsknet.relie', 0) > 3600) {
        $this->relierTous();
        $noPers = $this->noPersAuto($uid);
      }
    }
    if (!$noPers && $mode === 'codePers') {
      $identifiant = $this->comptes->identifiantDe($uid);
      $noPers = $identifiant ? $this->parCode($identifiant) : NULL;
    }
    return $this->trouves[$uid] = $noPers;
  }

  /**
   * Cherche l'agent actif dont le code personnel DSK est l'identifiant AD.
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   */
  public function parCode(string $identifiant): ?int {
    $jour = date('Y-m-d', $this->time->getRequestTime());
    foreach (array_unique([$identifiant, strtoupper($identifiant)]) as $code) {
      $numeros = self::numeros($this->client->get('/listAll/id', [
        'dateFrom' => $jour,
        'dateTo' => $jour,
        'codePers' => $code,
      ]));
      if (count($numeros) === 1) {
        return $numeros[0];
      }
      if (count($numeros) > 1) {
        $this->logger->warning('Plusieurs agents DSK ont le code personnel de l\'identifiant AD @id : saisir le n° DSK sur son compte.', ['@id' => $identifiant]);
        return NULL;
      }
    }
    return NULL;
  }

  /**
   * N° d'agents d'une réponse /listAll/id (entiers ou objets avec noPers).
   *
   * @return int[]
   */
  public static function numeros(mixed $donnees): array {
    if (!is_array($donnees)) {
      return [];
    }
    $numeros = [];
    foreach ($donnees as $ligne) {
      $n = is_array($ligne) ? ($ligne['noPers'] ?? NULL) : $ligne;
      if (is_numeric($n) && (int) $n > 0) {
        $numeros[] = (int) $n;
      }
    }
    return array_values(array_unique($numeros));
  }

}
