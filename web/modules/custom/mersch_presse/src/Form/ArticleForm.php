<?php

declare(strict_types=1);

namespace Drupal\mersch_presse\Form;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\mersch_presse\Analyseur;
use Drupal\mersch_presse\Entity\Article;

/**
 * Ajout ou modification d'un article de la presse écrite (US-44, D-048).
 *
 * Titre, média, date, extrait de 300 caractères au plus, lien facultatif et,
 * depuis D-049, scan PDF ou photo facultatif de l'article.
 */
class ArticleForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mersch_presse_article';
  }

  /**
   * Seuls les articles ajoutés à la main se modifient ou se suppriment.
   */
  public static function accesManuel(Article $mersch_presse_article): AccessResultInterface {
    return AccessResult::allowedIf($mersch_presse_article->estManuel())->addCacheableDependency($mersch_presse_article);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Article $mersch_presse_article = NULL): array {
    $form_state->set('article', $mersch_presse_article);
    $a = $mersch_presse_article;
    $date = $a ? date('Y-m-d', (int) $a->get('date')->value) : date('Y-m-d', \Drupal::time()->getRequestTime());

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('For an article of the printed press that is not online. Give its title, the media, the date and, if you wish, a short excerpt and the scan or photo of the article.') . '</p>',
    ];
    $form['titre'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#maxlength' => 512,
      '#required' => TRUE,
      '#default_value' => $a?->label(),
    ];
    $form['media'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Media'),
      '#description' => $this->t('For example: Luxemburger Wort, Tageblatt, Lëtzebuerger Journal.'),
      '#maxlength' => 128,
      '#required' => TRUE,
      '#default_value' => $a?->get('media')->value,
      '#attributes' => ['list' => 'mersch-presse-medias', 'autocomplete' => 'off'],
    ];
    $form['medias'] = $this->listeMedias();
    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Date of publication'),
      '#required' => TRUE,
      '#default_value' => $date,
    ];
    $form['extrait'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excerpt'),
      '#description' => $this->t('Optional, @max characters at most.', ['@max' => Article::EXTRAIT]),
      '#maxlength' => Article::EXTRAIT,
      '#rows' => 3,
      '#default_value' => $a?->get('extrait')->value,
    ];
    $form['lien'] = [
      '#type' => 'url',
      '#title' => $this->t('Link'),
      '#description' => $this->t('Optional: address of the article if it is also online.'),
      '#maxlength' => 2048,
      '#default_value' => $a?->get('lien')->value,
    ];
    $form['fichier'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Scan or photo'),
      '#description' => $this->t('Optional: PDF, JPG or PNG, @taille at most. Only for an article about the commune; the file is deleted with the article.', ['@taille' => Article::TAILLE]),
      '#upload_location' => 'public://presse/' . date('Y-m'),
      '#upload_validators' => [
        'FileExtension' => ['extensions' => Article::EXTENSIONS],
        'FileSizeLimit' => ['fileLimit' => Bytes::toNumber(Article::TAILLE)],
      ],
      '#default_value' => ($a && !$a->get('fichier')->isEmpty()) ? [(int) $a->get('fichier')->target_id] : NULL,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save')];
    if ($a) {
      $form['actions']['supprimer'] = [
        '#type' => 'link',
        '#title' => $this->t('Delete'),
        '#url' => Url::fromRoute('mersch_presse.supprimer', ['mersch_presse_article' => $a->id()]),
        '#attributes' => ['class' => ['button', 'button--danger']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $date = \DateTime::createFromFormat('!Y-m-d', (string) $form_state->getValue('date'));
    if (!$date || $date->getTimestamp() > \Drupal::time()->getRequestTime()) {
      $form_state->setErrorByName('date', $this->t('The date cannot be in the future.'));
    }
    if (mb_strlen((string) $form_state->getValue('extrait')) > Article::EXTRAIT) {
      $form_state->setErrorByName('extrait', $this->t('Optional, @max characters at most.', ['@max' => Article::EXTRAIT]));
    }
    $lien = trim((string) $form_state->getValue('lien'));
    if ($lien !== '' && !preg_match('#^https?://#i', $lien)) {
      $form_state->setErrorByName('lien', $this->t('The link must start with https://.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $stockage = \Drupal::entityTypeManager()->getStorage('mersch_presse_article');
    /** @var \Drupal\mersch_presse\Entity\Article|null $article */
    $article = $form_state->get('article') ?? $stockage->create(['manuel' => TRUE]);
    $ancien = $article->isNew() ? NULL : $article->get('fichier')->entity;
    $titre = trim((string) $form_state->getValue('titre'));
    $lien = trim((string) $form_state->getValue('lien'));
    // Midi : la date reste la même quel que soit le fuseau.
    $article->set('titre', $titre)
      ->set('media', trim((string) $form_state->getValue('media')))
      ->set('date', strtotime($form_state->getValue('date') . ' 12:00:00'))
      ->set('extrait', trim((string) $form_state->getValue('extrait')))
      ->set('lien', $lien === '' ? NULL : $lien)
      // Empreintes : un article collecté plus tard avec le même titre ou le
      // même lien n'est pas ajouté une deuxième fois.
      ->set('cle_lien', $lien === '' ? NULL : sha1($lien))
      ->set('cle_titre', Analyseur::cleTitre($titre))
      // Le champ fichier enregistre l'usage du fichier et le rend permanent.
      ->set('fichier', $form_state->getValue('fichier')[0] ?? NULL)
      ->save();
    // Fichier remplacé ou retiré : l'ancien est supprimé.
    if ($ancien && (int) $ancien->id() !== (int) $article->get('fichier')->target_id) {
      Article::supprimerSiInutilise($ancien);
    }
    $this->messenger()->addStatus($this->t('The article “@titre” is saved.', ['@titre' => $titre]));
    $form_state->setRedirect('mersch_presse.archive');
  }

  /**
   * Suggestions de médias : ceux déjà présents dans la revue.
   */
  private function listeMedias(): array {
    $resultats = \Drupal::entityTypeManager()->getStorage('mersch_presse_article')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->groupBy('media')
      ->sort('media')
      ->execute();
    $liste = ['#type' => 'html_tag', '#tag' => 'datalist', '#attributes' => ['id' => 'mersch-presse-medias']];
    foreach (array_column($resultats, 'media') as $i => $media) {
      $liste[$i] = ['#type' => 'html_tag', '#tag' => 'option', '#attributes' => ['value' => (string) $media]];
    }
    return $liste;
  }

}
