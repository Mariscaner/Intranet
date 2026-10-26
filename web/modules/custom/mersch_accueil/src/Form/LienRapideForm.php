<?php

declare(strict_types=1);

namespace Drupal\mersch_accueil\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mersch_accueil\Entity\LienRapide;

/**
 * Ajout et modification d'un lien rapide (US-11).
 */
class LienRapideForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\mersch_accueil\Entity\LienRapide $lien */
    $lien = $this->entity;

    $langues = [
      'lb' => $this->t('Luxembourgish'),
      'de' => $this->t('German'),
      'fr' => $this->t('French'),
    ];

    $form['libelle'] = [
      '#type' => 'details',
      '#title' => $this->t('Label'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['description'] = [
      '#type' => 'details',
      '#title' => $this->t('Description'),
      '#description' => $this->t('Short text under the label. An empty language shows the Luxembourgish text.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach ($langues as $langcode => $nom) {
      $form['libelle'][$langcode] = [
        '#type' => 'textfield',
        '#title' => $nom,
        '#default_value' => $lien->saisie('libelle', $langcode),
        '#maxlength' => 60,
        '#required' => $langcode === 'lb',
      ];
      $form['description'][$langcode] = [
        '#type' => 'textfield',
        '#title' => $nom,
        '#default_value' => $lien->saisie('description', $langcode),
        '#maxlength' => 80,
      ];
    }

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $lien->id(),
      '#machine_name' => [
        'exists' => [LienRapide::class, 'load'],
        'source' => ['libelle', 'lb'],
      ],
      '#disabled' => !$lien->isNew(),
    ];

    $form['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#description' => $this->t('Full address starting with https:// or http://, for example https://helpdesk. The link opens in a new tab.'),
      '#default_value' => $lien->getUrl(),
      '#maxlength' => 2048,
      '#required' => TRUE,
    ];

    $form['icone'] = [
      '#type' => 'radios',
      '#title' => $this->t('Icon'),
      '#options' => [
        'calendrier' => $this->t('Calendar'),
        'document' => $this->t('Document'),
        'aide' => $this->t('Help'),
        'web' => $this->t('Website'),
        'lien' => $this->t('Link'),
      ],
      '#default_value' => $lien->icone(),
      '#required' => TRUE,
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show on the home page'),
      '#default_value' => $lien->status(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $url = trim((string) $form_state->getValue('url'));
    if (!LienRapide::urlValide($url)) {
      $form_state->setErrorByName('url', $this->t('The URL %url is not valid. Enter a full address starting with https:// or http://.', ['%url' => $url]));
    }
    $form_state->setValue('url', $url);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    // Un nouveau lien se place après les autres.
    if ($this->entity->isNew()) {
      $poids = array_map(fn (LienRapide $lien) => $lien->getWeight(), LienRapide::loadMultiple());
      $this->entity->set('weight', $poids ? max($poids) + 1 : 0);
    }
    $resultat = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('The quick link %label has been saved.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $resultat;
  }

}
