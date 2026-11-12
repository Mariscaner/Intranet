<?php

declare(strict_types=1);

namespace Drupal\mersch_sso;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mersch_sso\Annuaire\AnnuaireIndisponible;
use Drupal\mersch_sso\Annuaire\AnnuaireInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Comptes Drupal des agents connectés par SSO (US-29, US-31, US-33).
 *
 * Le lien identifiant AD ↔ compte est gardé dans user.data (module
 * mersch_sso, clé « identifiant »), jamais par une fiche de l'annuaire Excel
 * (ses fiches sont recréées à chaque import).
 */
class Comptes {

  /**
   * Compte n° 1 : compte local de secours, jamais rattaché au SSO (US-32).
   */
  public const COMPTE_SECOURS = 1;

  /**
   * Identifiant AD accepté : lettres, chiffres, point, tiret, souligné.
   */
  private const MOTIF_IDENTIFIANT = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UserDataInterface $userData,
    private readonly AnnuaireInterface $annuaire,
    private readonly Reglages $reglages,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Identifiant AD tiré du principal Kerberos transmis par Apache.
   *
   * @param string $principal
   *   REMOTE_USER, par exemple « bema@DOMAIN.LOCAL ».
   *
   * @throws \Drupal\mersch_sso\EchecConnexion
   *   Si le principal est absent, mal formé ou d'un autre realm.
   */
  public function identifiant(string $principal): string {
    $morceaux = explode('@', trim($principal));
    if (count($morceaux) !== 2) {
      throw new EchecConnexion(EchecConnexion::NON_RECONNU, 'principal sans realm');
    }
    [$nom, $realm] = $morceaux;
    if ($realm !== $this->reglages->realm()) {
      throw new EchecConnexion(EchecConnexion::REFUSE, "realm refusé : $realm");
    }
    $identifiant = strtolower($nom);
    if (!preg_match(self::MOTIF_IDENTIFIANT, $identifiant)) {
      // Refuse aussi les principaux de service (HTTP/…, host/…).
      throw new EchecConnexion(EchecConnexion::REFUSE, 'identifiant mal formé');
    }
    return $identifiant;
  }

  /**
   * Trouve, rattache ou crée le compte de l'agent, et met à jour nom et e-mail.
   *
   * @throws \Drupal\mersch_sso\EchecConnexion
   */
  public function compte(string $identifiant): UserInterface {
    $compte = $this->compteLie($identifiant);
    try {
      $agent = $this->annuaire->chercher($identifiant);
    }
    catch (AnnuaireIndisponible $e) {
      if ($compte && $compte->isActive()) {
        // Agent déjà connu : nom et e-mail gardés jusqu'à la prochaine fois.
        $this->logger->warning('AD injoignable, connexion de @id avec les données connues : @erreur', ['@id' => $identifiant, '@erreur' => $e->getMessage()]);
        return $compte;
      }
      if ($compte) {
        throw new EchecConnexion(EchecConnexion::BLOQUE, "compte bloqué : $identifiant");
      }
      throw new EchecConnexion(EchecConnexion::ANNUAIRE, $e->getMessage());
    }
    if (!$agent) {
      throw new EchecConnexion(EchecConnexion::REFUSE, "absent de l'AD sous la base LDAP");
    }

    $stockage = $this->entityTypeManager->getStorage('user');
    if (!$compte && $agent->mail !== '') {
      // US-31 : compte local existant au même e-mail, pas encore rattaché.
      $ids = $stockage->getQuery()->accessCheck(FALSE)->condition('mail', Database::getConnection()->escapeLike($agent->mail), 'LIKE')->execute();
      $candidats = array_filter(
        array_map('intval', $ids),
        fn (int $uid): bool => $uid !== self::COMPTE_SECOURS && !$this->identifiantDe($uid),
      );
      if (count($candidats) === 1) {
        $compte = $stockage->load(reset($candidats));
        $this->logger->notice('Compte @nom rattaché à l\'identifiant AD @id.', ['@nom' => $compte->getAccountName(), '@id' => $identifiant]);
      }
    }
    if (!$compte) {
      $compte = $stockage->create([
        'name' => $this->nomLibre($identifiant),
        'mail' => $agent->mail ?: NULL,
        'init' => $agent->mail ?: NULL,
        'status' => 1,
      ]);
      $this->logger->notice('Compte créé pour l\'identifiant AD @id.', ['@id' => $identifiant]);
    }
    if (!$compte->isActive()) {
      throw new EchecConnexion(EchecConnexion::BLOQUE, "compte bloqué : $identifiant");
    }

    if ($agent->mail !== '' && strcasecmp((string) $compte->getEmail(), $agent->mail) !== 0 && !$this->mailPris($agent->mail, (int) $compte->id())) {
      $compte->setEmail($agent->mail);
    }
    $compte->save();
    $this->userData->set('mersch_sso', (int) $compte->id(), 'identifiant', $identifiant);
    $this->userData->set('mersch_sso', (int) $compte->id(), 'nom', $agent->nom);
    return $compte;
  }

  /**
   * Identifiant AD rattaché au compte, ou NULL.
   */
  public function identifiantDe(int $uid): ?string {
    $valeur = $this->userData->get('mersch_sso', $uid, 'identifiant');
    return is_string($valeur) && $valeur !== '' ? $valeur : NULL;
  }

  /**
   * Nom affiché lu dans l'AD, ou NULL.
   */
  public function nomDe(int $uid): ?string {
    $valeur = $this->userData->get('mersch_sso', $uid, 'nom');
    return is_string($valeur) && $valeur !== '' ? $valeur : NULL;
  }

  /**
   * US-33 : bloque les comptes SSO inactifs, signale ceux des rédacteurs.
   *
   * @return int[]
   *   Comptes à signaler aux admins (rôle de rédacteur ou d'admin).
   */
  public function bloquerInactifs(): array {
    $mois = max(1, (int) $this->reglages->get('inactivite_mois'));
    $limite = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->modify("-$mois months")->getTimestamp();
    $lies = $this->userData->get('mersch_sso', NULL, 'identifiant');
    $a_signaler = [];
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($lies)) as $compte) {
      /** @var \Drupal\user\UserInterface $compte */
      if ((int) $compte->id() === self::COMPTE_SECOURS || !$compte->isActive()) {
        continue;
      }
      $dernier = max((int) $compte->getLastLoginTime(), (int) $compte->getLastAccessedTime(), (int) $compte->getCreatedTime());
      if ($dernier >= $limite) {
        continue;
      }
      if (array_diff($compte->getRoles(), ['authenticated'])) {
        $a_signaler[] = (int) $compte->id();
        continue;
      }
      $compte->block()->save();
      $this->logger->notice('Compte @nom bloqué : aucune connexion depuis @mois mois.', ['@nom' => $compte->getAccountName(), '@mois' => $mois]);
    }
    return $a_signaler;
  }

  /**
   * Compte rattaché à l'identifiant AD, ou NULL.
   */
  private function compteLie(string $identifiant): ?UserInterface {
    $uid = array_search($identifiant, $this->userData->get('mersch_sso', NULL, 'identifiant'), TRUE);
    if ($uid === FALSE || (int) $uid === self::COMPTE_SECOURS) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('user')->load($uid);
  }

  /**
   * Nom de compte libre : l'identifiant AD, suivi d'un numéro s'il est pris.
   */
  private function nomLibre(string $identifiant): string {
    $stockage = $this->entityTypeManager->getStorage('user');
    $nom = $identifiant;
    for ($i = 2; $stockage->loadByProperties(['name' => $nom]); $i++) {
      $nom = "$identifiant-$i";
    }
    return $nom;
  }

  /**
   * L'e-mail est-il déjà celui d'un autre compte ?
   */
  private function mailPris(string $mail, int $uid): bool {
    return (bool) $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)->condition('mail', Database::getConnection()->escapeLike($mail), 'LIKE')->condition('uid', $uid, '<>')->range(0, 1)->execute();
  }

}
