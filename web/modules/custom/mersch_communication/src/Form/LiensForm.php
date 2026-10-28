<?php

declare(strict_types=1);

namespace Drupal\mersch_communication\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mersch_accueil\Entity\LienRapide;

/**
 * Adresses des liens de la page Communication, réservées aux admins.
 *
 * Si mersch.lu change d'adresse, l'admin la corrige ici sans développement.
 */
class LiensForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mersch_communication_liens';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['mersch_communication.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mersch_communication.settings');
    $champs = [
      'nik' => $this->t('Gemengebuet Nik'),
      'agenda' => $this->t('Events calendar'),
      'albums' => $this->t('Photo albums'),
    ];
    foreach ($champs as $cle => $titre) {
      $form[$cle] = [
        '#type' => 'url',
        '#title' => $titre,
        '#default_value' => $config->get($cle),
        '#required' => TRUE,
        '#maxlength' => 2048,
      ];
    }
    $form['aide'] = [
      '#markup' => '<p>' . $this->t('The links open in a new tab. Nothing is copied into the intranet (D-012).') . '</p>',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach (['nik', 'agenda', 'albums'] as $cle) {
      $url = trim((string) $form_state->getValue($cle));
      if (!LienRapide::urlValide($url)) {
        $form_state->setErrorByName($cle, $this->t('The URL %url is not valid. Enter a full address starting with https:// or http://.', ['%url' => $url]));
      }
      $form_state->setValue($cle, $url);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('mersch_communication.settings');
    foreach (['nik', 'agenda', 'albums'] as $cle) {
      $config->set($cle, $form_state->getValue($cle));
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
