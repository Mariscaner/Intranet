<?php

declare(strict_types=1);

namespace Drupal\mersch_accueil\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mersch_accueil\Entity\Idee;

/**
 * Boîte à idées de l'accueil, pour les agents connectés.
 */
class IdeeForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mersch_idee';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['texte'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your idea to improve the intranet'),
      '#rows' => 4,
      '#required' => TRUE,
      '#maxlength' => Idee::LONGUEUR,
      '#description' => $this->t('Only the administrators read the ideas. Your name and the date are kept with your idea.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send my idea'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $texte = trim((string) $form_state->getValue('texte'));
    if ($texte === '') {
      $form_state->setErrorByName('texte', $this->t('Please write your idea.'));
    }
    elseif (mb_strlen($texte) > Idee::LONGUEUR) {
      $form_state->setErrorByName('texte', $this->t('Your idea is too long (@max characters at most).', ['@max' => Idee::LONGUEUR]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    Idee::create([
      'uid' => $this->currentUser()->id(),
      'texte' => trim((string) $form_state->getValue('texte')),
    ])->save();
    $this->messenger()->addStatus($this->t('Thank you! Your idea has been sent to the administrators.'));
  }

}
