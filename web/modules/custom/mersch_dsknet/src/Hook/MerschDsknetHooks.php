<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mersch_dsknet\Client;
use Drupal\mersch_dsknet\Correspondance;
use Drupal\mersch_dsknet\DsknetIndisponible;
use Drupal\mersch_dsknet\Reglages;
use Drupal\user\UserInterface;

/**
 * Thème, n° DSK sur le compte (admins), rapport d'état.
 */
class MerschDsknetHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly Correspondance $correspondance,
    private readonly Client $client,
    private readonly Reglages $reglages,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'mersch_dsknet' => [
        'variables' => ['statut' => 'ok', 'donnees' => [], 'lien_dsknet' => NULL],
      ],
    ];
  }

  /**
   * Implements hook_form_FORM_ID_alter() for user_form.
   *
   * Seuls les admins voient et modifient le n° DSK d'un compte.
   */
  #[Hook('form_user_form_alter')]
  public function formulaireCompte(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser->hasPermission('administer users')) {
      return;
    }
    $compte = $form_state->getFormObject()->getEntity();
    if (!$compte instanceof UserInterface || $compte->isNew()) {
      return;
    }
    $form['mersch_dsknet'] = [
      '#type' => 'details',
      '#title' => $this->t('DSKNet'),
      '#open' => TRUE,
      '#weight' => 5,
      'mersch_dsknet_nopers' => [
        '#type' => 'number',
        '#title' => $this->t('DSK staff number (noPers)'),
        '#min' => 1,
        '#step' => 1,
        '#default_value' => $this->correspondance->noPersSaisi((int) $compte->id()),
        '#description' => $this->reglages->get('correspondance') === 'manuel'
          ? $this->t('Number of this staff member in DSKNet. Without it, the staff member does not see their DSKNet data.')
          : $this->t('Leave empty: the intranet finds the staff member in DSKNet by their Windows login. Fill in only if this does not work.'),
      ],
    ];
    $form['actions']['submit']['#submit'][] = [static::class, 'enregistrer'];
  }

  /**
   * Enregistre le n° DSK saisi par l'admin.
   */
  public static function enregistrer(array $form, FormStateInterface $form_state): void {
    $compte = $form_state->getFormObject()->getEntity();
    $valeur = $form_state->getValue('mersch_dsknet_nopers');
    \Drupal::service('mersch_dsknet.correspondance')->saisir((int) $compte->id(), is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : NULL);
  }

  /**
   * Implements hook_cron().
   *
   * Relie chaque jour les comptes SSO aux agents DSK (mode « login »).
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->prolonger();
    if ($this->reglages->get('correspondance') !== 'login'
      || \Drupal::time()->getRequestTime() - (int) \Drupal::state()->get('mersch_dsknet.relie', 0) < 86400) {
      return;
    }
    try {
      $this->correspondance->relierTous();
    }
    catch (DsknetIndisponible) {
      // Déjà journalisé par le client ; nouvel essai au prochain cron.
    }
  }

  /**
   * Prolonge la session DSK du jeton une fois par jour (revalidateToken).
   *
   * Le jeton DSK est une session qui expire ; revalidateToken double sa
   * durée de vie. La date de fin est gardée pour le rapport d'état.
   */
  private function prolonger(): void {
    $state = \Drupal::state();
    if (\Drupal::time()->getRequestTime() - (int) $state->get('mersch_dsknet.prolonge', 0) < 86400) {
      return;
    }
    $state->set('mersch_dsknet.prolonge', \Drupal::time()->getRequestTime());
    try {
      $session = $this->client->get('/revalidateToken');
      if (is_array($session) && !empty($session['validUntil'])) {
        $state->set('mersch_dsknet.valide_jusque', (string) $session['validUntil']);
      }
    }
    catch (DsknetIndisponible) {
      // Déjà journalisé par le client.
    }
  }

  /**
   * Implements hook_user_delete().
   */
  #[Hook('user_delete')]
  public function compteSupprime(UserInterface $compte): void {
    $this->correspondance->saisir((int) $compte->id(), NULL);
    \Drupal::service('user.data')->delete('mersch_dsknet', (int) $compte->id());
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function exigences(): array {
    $exigence = ['title' => $this->t('DSKNet')];
    if (!$this->client->jetonLisible()) {
      return ['mersch_dsknet' => $exigence + [
        'value' => $this->t('Token not readable'),
        'description' => $this->t('The web server cannot read the line DSKNET_TOKEN= in @fichier (docs/dsknet.md).', ['@fichier' => $this->reglages->get('fichier_jeton')]),
        'severity' => RequirementSeverity::Error,
      ]];
    }
    try {
      $this->client->get('/test', [], TRUE);
    }
    catch (DsknetIndisponible) {
      return ['mersch_dsknet' => $exigence + [
        'value' => $this->t('No answer'),
        'description' => $this->t('@base/test does not answer with the token. See the log: drush-intranet watchdog:show --type=mersch_dsknet', ['@base' => $this->reglages->base()]),
        'severity' => RequirementSeverity::Error,
      ]];
    }
    return ['mersch_dsknet' => $exigence + [
      'value' => $this->t('Connected (@base)', ['@base' => $this->reglages->base()]),
      'description' => \Drupal::state()->get('mersch_dsknet.valide_jusque') ? $this->t('Token valid until @date (extended every day by cron).', ['@date' => \Drupal::state()->get('mersch_dsknet.valide_jusque')]) : '',
      'severity' => RequirementSeverity::OK,
    ]];
  }

}
