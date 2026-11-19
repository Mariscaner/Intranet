<?php

declare(strict_types=1);

namespace Drupal\mersch_presse\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\mersch_presse\Entity\Article;

/**
 * Suppression d'un article ajouté à la main (US-44).
 */
class ArticleSupprimerForm extends ConfirmFormBase {

  private ?Article $article = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mersch_presse_article_supprimer';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Article $mersch_presse_article = NULL): array {
    $this->article = $mersch_presse_article;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the article “@titre”?', ['@titre' => $this->article?->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('mersch_presse.archive');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->article->delete();
    $this->messenger()->addStatus($this->t('The article is deleted.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
