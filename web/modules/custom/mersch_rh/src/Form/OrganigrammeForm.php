<?php

declare(strict_types=1);

namespace Drupal\mersch_rh\Form;

use Drupal\Component\Utility\Environment;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\mersch_rh\Organigramme;

/**
 * Dépôt de l'organigramme par un rédacteur RH (US-12, D-011).
 */
class OrganigrammeForm extends FormBase {

  use AutowireTrait;

  public function __construct(
    private readonly Organigramme $organigramme,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mersch_rh_organigramme';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $actuel = $this->organigramme->fichier();
    $form['actuel'] = [
      '#type' => 'item',
      '#title' => $this->t('Current organisation chart'),
      '#markup' => $actuel
        ? $this->t('%fichier, uploaded on @date.', [
          '%fichier' => $actuel->getFilename(),
          '@date' => $this->dateFormatter->format($this->organigramme->date(), 'short'),
        ])
        : $this->t('None yet.'),
    ];

    $form['fichier'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('New organisation chart'),
      '#description' => $this->t('The PDF supplied by HR (or a PNG or JPG image), at most @taille. It replaces the current chart, which is deleted: there is no archive.', [
        '@taille' => ByteSizeMarkup::create(Environment::getUploadMaxSize()),
      ]),
      '#upload_location' => 'public://rh/organigramme',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => Organigramme::EXTENSIONS],
      ],
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Replace'),
      '#button_type' => 'primary',
    ];
    $form['actions']['annuler'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('mersch_accueil.rh'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fid = $form_state->getValue(['fichier', 0]);
    $fichier = $fid ? File::load($fid) : NULL;
    if (!$fichier) {
      return;
    }
    $this->organigramme->remplacer($fichier, (int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('The organisation chart has been replaced.'));
    $form_state->setRedirect('mersch_accueil.rh');
  }

}
