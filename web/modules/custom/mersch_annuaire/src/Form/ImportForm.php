<?php

declare(strict_types=1);

namespace Drupal\mersch_annuaire\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\mersch_annuaire\Importeur;
use Drupal\mersch_annuaire\LecteurFichier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Import de la liste Excel du personnel par un administrateur (US-09, D-026).
 *
 * « Vérifier » affiche le compte rendu sans toucher à l'annuaire ;
 * « Importer » remplace l'annuaire par la liste du fichier.
 */
class ImportForm extends FormBase {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'mersch_annuaire.importeur')]
    private readonly Importeur $importeur,
    private readonly StateInterface $state,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mersch_annuaire_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $dernier = $this->state->get(Importeur::ETAT);
    if ($dernier) {
      // Un import en ligne de commande (Drush) n'a pas d'auteur connecté.
      $auteur = $dernier['uid'] ? $this->entityTypeManager->getStorage('user')->load($dernier['uid']) : NULL;
      $form['dernier'] = [
        '#type' => 'item',
        '#title' => $this->t('Last import'),
        '#markup' => $this->t('@date by @auteur: @agents staff members from %fichier.', [
          '@date' => $this->dateFormatter->format($dernier['date'], 'short'),
          '@auteur' => $auteur?->getDisplayName() ?? 'Drush',
          '@agents' => $dernier['agents'],
          '%fichier' => $dernier['fichier'],
        ]),
      ];
    }

    $resultat = $form_state->get('resultat');
    if ($resultat) {
      $form['compte_rendu'] = [
        '#type' => 'details',
        '#title' => $this->importeur->resume($resultat),
        '#open' => TRUE,
        'liste' => [
          '#theme' => 'item_list',
          '#items' => $this->importeur->compteRendu($resultat),
          '#empty' => $this->t('Nothing to report.'),
        ],
      ];
    }

    $form['fichier'] = [
      '#type' => 'file',
      '#title' => $this->t('Excel staff list'),
      '#description' => $this->t('Excel (@extensions) file with the columns Nom/Prénom, Tél., Gsm, Mail, mail service, Service, Bâtiment, Remarques. The new list replaces the current directory. The file itself is not kept.', [
        '@extensions' => implode(', ', LecteurFichier::EXTENSIONS),
      ]),
      '#attributes' => ['accept' => implode(',', array_map(fn ($e) => '.' . $e, LecteurFichier::EXTENSIONS))],
    ];

    $form['exclusions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Exclusion list'),
      '#description' => $this->t('Lines of the Excel list not to show (generic accounts, rooms…), one per line, as written in the Nom/Prénom column.'),
      '#default_value' => implode("\n", $this->config('mersch_annuaire.settings')->get('exclusions') ?? []),
      '#rows' => 4,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['verifier'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check'),
      '#name' => 'verifier',
    ];
    $form['actions']['importer'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#name' => 'importer',
      '#button_type' => 'primary',
    ];
    $form['actions']['voir'] = [
      '#type' => 'link',
      '#title' => $this->t('View the directory'),
      '#url' => Url::fromRoute('mersch_annuaire.annuaire'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $fichier = $this->getRequest()->files->get('files', [])['fichier'] ?? NULL;
    if (!$fichier || !$fichier->isValid()) {
      $form_state->setErrorByName('fichier', $this->t('Choose the Excel staff list.'));
      return;
    }
    try {
      $resultat = $this->importeur->analyser($fichier->getRealPath(), $fichier->getClientOriginalName(), $this->exclusions($form_state));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('fichier', $e->getMessage());
      return;
    }
    if ($resultat['agents'] === []) {
      $form_state->setErrorByName('fichier', $this->t('No valid line in this file: the current directory is kept.'));
    }
    $form_state->set('resultat', $resultat);
    $form_state->set('nom_fichier', $fichier->getClientOriginalName());
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory()->getEditable('mersch_annuaire.settings')
      ->set('exclusions', $this->exclusions($form_state))
      ->save();

    $resultat = $form_state->get('resultat');
    if ($form_state->getTriggeringElement()['#name'] === 'importer') {
      $nombre = $this->importeur->importer($resultat, $form_state->get('nom_fichier'));
      $this->messenger()->addStatus($this->t('Directory updated: @nombre staff members.', ['@nombre' => $nombre]));
    }
    else {
      $this->messenger()->addStatus($this->t('Check only: the directory has not changed.'));
    }
    $form_state->setRebuild();
  }

  /**
   * Liste d'exclusion saisie, sans lignes vides.
   *
   * @return string[]
   *   Une valeur par ligne.
   */
  private function exclusions(FormStateInterface $form_state): array {
    $lignes = preg_split('/\R/', (string) $form_state->getValue('exclusions'));
    return array_values(array_filter(array_map('trim', $lignes)));
  }

}
