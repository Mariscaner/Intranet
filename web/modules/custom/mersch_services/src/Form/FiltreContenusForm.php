<?php

declare(strict_types=1);

namespace Drupal\mersch_services\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Filtres de la liste des contenus : rubrique, titre, statut (US-27).
 *
 * Formulaire GET sans jeton : les filtres restent dans l'adresse, qu'on peut
 * garder en favori.
 */
class FiltreContenusForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mersch_services_filtre_contenus';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $filtres
   *   Valeurs actuelles { rubrique, titre, statut }.
   * @param string[] $rubriques
   *   Rubriques proposées à la personne.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $filtres = [], array $rubriques = []): array {
    $libelles = \Drupal::service('mersch_services.rubriques')->libelles();
    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#attributes']['class'][] = 'mersch-filtre-contenus';
    $form['#after_build'][] = [static::class, 'nettoyer'];
    $form['#cache']['contexts'] = ['url.query_args', 'user'];

    $form['filtres'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form--inline', 'clearfix']],
    ];
    if ($rubriques) {
      $form['filtres']['rubrique'] = [
        '#type' => 'select',
        '#title' => $this->t('Section'),
        '#options' => ['' => count($rubriques) < count($libelles) ? $this->t('- All my sections -') : $this->t('- Any -')]
          + array_intersect_key($libelles, array_flip($rubriques)),
        '#value' => $filtres['rubrique'] ?? '',
      ];
    }
    $form['filtres']['titre'] = [
      '#type' => 'search',
      '#title' => $this->t('Title contains'),
      '#value' => $filtres['titre'] ?? '',
      '#size' => 30,
    ];
    $form['filtres']['statut'] = [
      '#type' => 'select',
      '#title' => $this->t('Published status'),
      '#options' => ['' => $this->t('- Any -'), '1' => $this->t('Published'), '0' => $this->t('Unpublished')],
      '#value' => $filtres['statut'] ?? '',
    ];
    $form['filtres']['actions'] = ['#type' => 'actions'];
    $form['filtres']['actions']['filtrer'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
      '#name' => '',
    ];
    if (array_filter($filtres)) {
      $form['filtres']['actions']['effacer'] = [
        '#type' => 'link',
        '#title' => $this->t('Reset'),
        '#url' => Url::fromRoute('<current>'),
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $form;
  }

  /**
   * Retire les champs techniques de Drupal de l'adresse.
   */
  public static function nettoyer(array $form): array {
    unset($form['form_build_id'], $form['form_id'], $form['form_token']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Formulaire GET : rien à enregistrer.
  }

}
