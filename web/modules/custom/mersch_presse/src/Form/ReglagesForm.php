<?php

declare(strict_types=1);

namespace Drupal\mersch_presse\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Flux et mots-clés de la revue de presse (US-43), réservé aux admins.
 */
class ReglagesForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mersch_presse_reglages';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['mersch_presse.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mersch_presse.settings');
    $lignes = array_map(
      fn (array $s) => (empty($s['actif']) ? '# ' : '') . $s['nom'] . ' | ' . $s['url'],
      $config->get('sources') ?? [],
    );

    /** @var \Drupal\mersch_presse\Collecteur $collecteur */
    $collecteur = \Drupal::service('mersch_presse.collecteur');
    $derniere = $collecteur->derniereCollecte();
    $form['etat'] = [
      '#markup' => '<p>' . ($derniere
        ? $this->t('Last collection: @date. Feeds are read every two hours from 6:00 to 20:00.', ['@date' => \Drupal::service('date.formatter')->format($derniere, 'custom', 'd.m.Y H:i')])
        : $this->t('No collection yet. Feeds are read every two hours from 6:00 to 20:00.')) . '</p>',
    ];
    $form['sources'] = [
      '#type' => 'textarea',
      '#title' => $this->t('News feeds'),
      '#description' => $this->t('One feed per line: <code>Media | RSS address</code>. Start a line with # to deactivate it. For a Google News search (news.google.com), only the articles of the sites below are kept, with the media given by Google.'),
      '#default_value' => implode("\n", $lignes),
      '#rows' => 10,
      '#required' => TRUE,
    ];
    $form['mots_cles'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Keywords'),
      '#description' => $this->t('One per line. An article is kept if its title or excerpt contains a word that begins with one of these keywords, regardless of case.'),
      '#default_value' => implode("\n", $config->get('mots_cles') ?? []),
      '#rows' => 10,
      '#required' => TRUE,
    ];
    $form['sites_google'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Google News: sites kept'),
      '#description' => $this->t('One per line, end of the site address. <code>.lu</code> keeps all Luxembourg media and leaves out foreign articles about other people called Mersch.'),
      '#default_value' => implode("\n", $config->get('sites_google') ?? ['.lu']),
      '#rows' => 3,
      '#required' => TRUE,
    ];
    $form['exclusions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded sections'),
      '#description' => $this->t('One per line. An article of a media feed whose address contains one of these parts (for example <code>/international</code>) is left out.'),
      '#default_value' => implode("\n", $config->get('exclusions') ?? []),
      '#rows' => 6,
    ];
    $form = parent::buildForm($form, $form_state);
    $form['actions']['collecter'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and collect now'),
      '#submit' => ['::submitForm', '::collecter'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $sources = [];
    foreach (preg_split('/\R/u', (string) $form_state->getValue('sources')) as $numero => $ligne) {
      $ligne = trim($ligne);
      if ($ligne === '') {
        continue;
      }
      $actif = !str_starts_with($ligne, '#');
      $parties = array_map('trim', explode('|', ltrim($ligne, '# '), 2));
      if (count($parties) !== 2 || $parties[0] === '' || !preg_match('#^https?://\S+$#i', $parties[1])) {
        $form_state->setErrorByName('sources', $this->t('Line @numero: write <code>Media | address starting with https://</code>.', ['@numero' => $numero + 1]));
        return;
      }
      $sources[] = ['nom' => $parties[0], 'url' => $parties[1], 'actif' => $actif];
    }
    $form_state->setValue('sources_lues', $sources);
    $motsCles = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', (string) $form_state->getValue('mots_cles'))), 'strlen')));
    foreach ($motsCles as $mot) {
      if (mb_strlen($mot) < 3) {
        $form_state->setErrorByName('mots_cles', $this->t('The keyword “@mot” is too short (3 characters at least).', ['@mot' => $mot]));
        return;
      }
    }
    $form_state->setValue('mots_cles_lus', $motsCles);
    $liste = fn (string $champ) => array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', (string) $form_state->getValue($champ))), 'strlen')));
    $form_state->setValue('sites_google_lus', $liste('sites_google'));
    $form_state->setValue('exclusions_lues', $liste('exclusions'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('mersch_presse.settings')
      ->set('sources', $form_state->getValue('sources_lues'))
      ->set('mots_cles', $form_state->getValue('mots_cles_lus'))
      ->set('sites_google', $form_state->getValue('sites_google_lus'))
      ->set('exclusions', $form_state->getValue('exclusions_lues'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Lance une collecte tout de suite et en donne le bilan.
   */
  public function collecter(array &$form, FormStateInterface $form_state): void {
    $bilan = \Drupal::service('mersch_presse.collecteur')->collecter();
    $this->messenger()->addStatus($this->formatPlural($bilan['nouveaux'], '1 new article.', '@count new articles.'));
    foreach ($bilan['erreurs'] as $nom => $message) {
      $this->messenger()->addWarning($this->t('Feed @nom could not be read: @message', ['@nom' => $nom, '@message' => $message]));
    }
  }

}
