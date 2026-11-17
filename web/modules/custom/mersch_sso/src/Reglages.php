<?php

declare(strict_types=1);

namespace Drupal\mersch_sso;

use Drupal\Core\Site\Settings;

/**
 * Réglages du SSO, écrits par scripts/serveur/installer.sh dans
 * sites/default/settings.sso.php depuis /etc/intranet-mersch.conf.
 *
 * Aucun secret ici : le keytab et l'éventuel mot de passe LDAP restent dans
 * des fichiers du serveur dont seul le chemin est connu.
 */
class Reglages {

  /**
   * Valeurs par défaut : SSO désactivé tant que le keytab n'est pas livré.
   */
  private const DEFAUTS = [
    'actif' => FALSE,
    // Nom complet du serveur : le SPN HTTP/… ne couvre que lui.
    'serveur' => '',
    'https' => FALSE,
    'realm' => '',
    // Une ou plusieurs URI séparées par des espaces (ldaps://dc1… ldaps://dc2…).
    'ldap_uri' => '',
    'ldap_base' => '',
    // « gssapi » (keytab, aucun mot de passe) ou « motdepasse ».
    'ldap_liaison' => 'gssapi',
    'ldap_compte' => '',
    'ldap_fichier_mdp' => '/etc/intranet-mersch/ldap.secret',
    'ldap_ca' => '',
    'keytab' => '/etc/intranet-mersch/http.keytab',
    // US-33 : 24 mois (Marc, 2026-11-12).
    'inactivite_mois' => 24,
  ];

  /**
   * Retourne un réglage.
   */
  public function get(string $nom): mixed {
    $reglages = Settings::get('mersch_sso', []) + self::DEFAUTS;
    return $reglages[$nom] ?? NULL;
  }

  /**
   * Le SSO est-il activé et complet ?
   */
  public function actif(): bool {
    return (bool) $this->get('actif') && $this->get('realm') !== '' && $this->get('serveur') !== '';
  }

  /**
   * Realm Kerberos attendu, en majuscules (DOMAIN.LOCAL).
   */
  public function realm(): string {
    return strtoupper((string) $this->get('realm'));
  }

  /**
   * Adresse de base du serveur au nom complet (https://intranet.domain.local).
   */
  public function base(): string {
    return ($this->get('https') ? 'https://' : 'http://') . $this->get('serveur');
  }

}
