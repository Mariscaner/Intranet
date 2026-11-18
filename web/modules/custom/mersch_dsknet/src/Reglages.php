<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet;

use Drupal\Core\Site\Settings;

/**
 * Réglages de l'accès à DSKnet ($settings['mersch_dsknet'] dans settings.php).
 *
 * Aucun secret ici : le jeton reste dans un fichier du serveur
 * (/etc/intranet/dsknet.env, ligne DSKNET_TOKEN=…) dont seul le chemin est
 * connu. Il n'est jamais copié dans la base, le cache ni le journal.
 */
class Reglages {

  private const DEFAUTS = [
    // Nom d'hôte, pas d'adresse IP : APPS est résolu par le DNS (ou
    // /etc/hosts en attendant le correctif de l'intégrateur). DSK-API 1.6 :
    // chemins /dsknet/… (doc OpenAPI 1.6.138.43, sans v2).
    'base' => 'http://APPS/API/dsknet',
    'fichier_jeton' => '/etc/intranet/dsknet.env',
    'delai' => 5,
    // « login » : le login DSK est l'identifiant AD (DSKNet s'authentifie par
    // LDAP sur domain.local, clé samAccountName) ; « codePers » : le code
    // personnel DSK est l'identifiant AD ; « manuel » : un admin saisit le
    // n° DSK (noPers) sur le compte. Un n° saisi par un admin l'emporte.
    'correspondance' => 'login',
    // Absences accordées affichées : d'aujourd'hui à N mois.
    'absences_mois' => 12,
    // Codes de pointage DSK (champ type, puis subType) → état affiché.
    // À confirmer avec drush mersch:dsknet-verifier (doc DSK sans exemple).
    'types_pointage' => [
      // B1 (pointage normal, vu le 2026-11-17) n'est pas listé : entrée ou
      // sortie selon le nombre de pointages du jour (voir Donnees::etatDepuis).
      'D' => 'deplacement', 'M' => 'deplacement', 'DEP' => 'deplacement', 'MISSION' => 'deplacement',
    ],
    // Annuaire (D-044) : n° (index) du premier pointage du jour chez DSK, pour
    // l'alternance entrée / sortie des pointages génériques (B1). À confirmer
    // avec drush mersch:dsknet-presence (colonne index).
    'presence_index_base' => 1,
    // Langue de l'interface → langID DSK des libellés (inconnu tant que le
    // diagnostic n'a pas tourné ; sinon le premier libellé est pris).
    'langues' => [],
    // Compteurs affichés si DSK ne dit pas lesquels vont sur son tableau de
    // bord (champ visibility, absent des anciennes versions) : n° noCounter.
    'compteurs' => [],
  ];

  /**
   * Retourne un réglage.
   */
  public function get(string $nom): mixed {
    $reglages = Settings::get('mersch_dsknet', []) + self::DEFAUTS;
    return $reglages[$nom] ?? NULL;
  }

  /**
   * Adresse de base sans barre finale.
   */
  public function base(): string {
    return rtrim((string) $this->get('base'), '/');
  }

}
