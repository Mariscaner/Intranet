<?php

declare(strict_types=1);

namespace Drupal\mersch_sso\Annuaire;

use Drupal\mersch_sso\Reglages;
use Psr\Log\LoggerInterface;

/**
 * Lecture dans l'AD par LDAPS : sAMAccountName, displayName et mail, rien
 * d'autre (D-035).
 *
 * Liaison « gssapi » (par défaut) : l'intranet s'authentifie avec le keytab
 * du serveur, aucun mot de passe n'est stocké. Liaison « motdepasse » : le
 * mot de passe du compte de service est lu dans un fichier hors dépôt,
 * lisible par www-data seulement.
 */
class LdapAnnuaire implements AnnuaireInterface {

  /**
   * Attributs lus, et seulement ceux-ci.
   */
  public const ATTRIBUTS = ['samaccountname', 'displayname', 'mail'];

  public function __construct(
    private readonly Reglages $reglages,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function chercher(string $identifiant): ?Agent {
    if (!extension_loaded('ldap')) {
      throw new AnnuaireIndisponible('Extension PHP ldap absente (paquet php-ldap).');
    }
    $ldap = $this->connecter();
    try {
      $filtre = sprintf(
        '(&(objectCategory=person)(objectClass=user)(sAMAccountName=%s))',
        ldap_escape($identifiant, '', LDAP_ESCAPE_FILTER),
      );
      $resultat = @ldap_search($ldap, (string) $this->reglages->get('ldap_base'), $filtre, self::ATTRIBUTS, 0, 2, 10);
      if ($resultat === FALSE) {
        throw new AnnuaireIndisponible('Recherche LDAP refusée : ' . ldap_error($ldap));
      }
      $entrees = ldap_get_entries($ldap, $resultat);
      if (($entrees['count'] ?? 0) !== 1) {
        return NULL;
      }
      $entree = $entrees[0];
      $valeur = static fn (string $attribut): string => trim((string) ($entree[$attribut][0] ?? ''));
      return new Agent(
        strtolower($valeur('samaccountname') ?: $identifiant),
        $valeur('displayname') ?: $identifiant,
        strtolower($valeur('mail')),
      );
    }
    finally {
      ldap_unbind($ldap);
    }
  }

  /**
   * Ouvre et lie la connexion LDAP.
   *
   * @return \LDAP\Connection
   *   La connexion liée.
   */
  private function connecter(): \LDAP\Connection {
    $ca = (string) $this->reglages->get('ldap_ca');
    if ($ca !== '') {
      ldap_set_option(NULL, LDAP_OPT_X_TLS_CACERTFILE, $ca);
    }
    ldap_set_option(NULL, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);

    $ldap = ldap_connect((string) $this->reglages->get('ldap_uri'));
    if ($ldap === FALSE) {
      throw new AnnuaireIndisponible('URI LDAP invalide.');
    }
    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);
    ldap_set_option($ldap, LDAP_OPT_TIMELIMIT, 10);

    if ($this->reglages->get('ldap_liaison') === 'motdepasse') {
      $fichier = (string) $this->reglages->get('ldap_fichier_mdp');
      $mdp = is_readable($fichier) ? trim((string) file_get_contents($fichier)) : '';
      if ($mdp === '') {
        throw new AnnuaireIndisponible("Mot de passe LDAP illisible : $fichier.");
      }
      $lie = @ldap_bind($ldap, (string) $this->reglages->get('ldap_compte'), $mdp);
      unset($mdp);
    }
    else {
      // Le client Kerberos prend ses identifiants dans le keytab du serveur
      // (MIT krb5 : KRB5_CLIENT_KTNAME), en mémoire seulement.
      putenv('KRB5_CLIENT_KTNAME=' . $this->reglages->get('keytab'));
      putenv('KRB5CCNAME=MEMORY:mersch_sso');
      if (defined('LDAP_OPT_X_SASL_NOCANON')) {
        ldap_set_option($ldap, LDAP_OPT_X_SASL_NOCANON, TRUE);
      }
      // Sur LDAPS, TLS chiffre déjà : l'AD refuse une seconde couche SASL.
      $tls = str_starts_with(strtolower((string) $this->reglages->get('ldap_uri')), 'ldaps://');
      $lie = @ldap_sasl_bind($ldap, NULL, NULL, 'GSSAPI', NULL, NULL, NULL, $tls ? 'maxssf=0' : NULL);
    }
    if (!$lie) {
      $erreur = ldap_error($ldap);
      $this->logger->error('Liaison LDAP refusée : @erreur', ['@erreur' => $erreur]);
      throw new AnnuaireIndisponible("Liaison LDAP refusée : $erreur");
    }
    return $ldap;
  }

}
