<?php

declare(strict_types=1);

namespace Drupal\mersch_sso\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mersch_sso\Reglages;
use Drupal\user\Entity\User;

/**
 * Rapport d'état : SSO actif, extension LDAP, keytab, comptes à revoir.
 */
class Exigences {

  use StringTranslationTrait;

  public function __construct(
    private readonly Reglages $reglages,
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    if (!$this->reglages->actif()) {
      return [
        'mersch_sso' => [
          'title' => $this->t('Windows login (SSO)'),
          'value' => $this->t('Not active'),
          'description' => $this->t('Set SSO_ACTIF="oui" in /etc/intranet-mersch.conf once the keytab is installed, then run installer.sh again (docs/sso-ad.md).'),
          'severity' => RequirementSeverity::Info,
        ],
      ];
    }

    $problemes = [];
    if (!extension_loaded('ldap')) {
      $problemes[] = $this->t('PHP extension ldap missing (package php-ldap).');
    }
    if (!is_readable((string) $this->reglages->get('keytab'))) {
      $problemes[] = $this->t('Keytab not readable by the web server: @fichier.', ['@fichier' => $this->reglages->get('keytab')]);
    }
    $exigences['mersch_sso'] = [
      'title' => $this->t('Windows login (SSO)'),
      'value' => $problemes ? $this->t('Active, with problems') : $this->t('Active (realm @realm)', ['@realm' => $this->reglages->realm()]),
      'description' => $problemes ? ['#theme' => 'item_list', '#items' => $problemes] : '',
      'severity' => $problemes ? RequirementSeverity::Error : RequirementSeverity::OK,
    ];

    $a_signaler = $this->state->get('mersch_sso.a_signaler', []);
    if ($a_signaler) {
      $noms = array_map(fn ($compte) => $compte->getDisplayName(), User::loadMultiple($a_signaler));
      $exigences['mersch_sso_inactifs'] = [
        'title' => $this->t('Inactive editor or administrator accounts'),
        'value' => implode(', ', $noms),
        'description' => $this->t('No login for @mois months. These accounts have a role and are not blocked automatically: block them or remove their role.', ['@mois' => $this->reglages->get('inactivite_mois')]),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    return $exigences;
  }

}
