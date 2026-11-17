<?php

declare(strict_types=1);

namespace Drupal\mersch_accueil;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Idées reçues, plus récentes d'abord ; réservé aux admins.
 */
class IdeeListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = 50;

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    return $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->pager($this->limit)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['date'] = $this->t('Date');
    $header['auteur'] = $this->t('Author');
    $header['texte'] = $this->t('Idea');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\mersch_accueil\Entity\Idee $entity */
    $auteur = $entity->get('uid')->entity;
    $row['date'] = \Drupal::service('date.formatter')->format((int) $entity->get('created')->value, 'custom', 'd.m.Y H:i');
    $row['auteur'] = $auteur ? $auteur->getDisplayName() : $this->t('Former user');
    $row['texte'] = ['data' => ['#markup' => nl2br(htmlspecialchars((string) $entity->get('texte')->value))]];
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Ideas sent by staff from the home page. Delete an idea once it has been handled: only the author, the date and the text are kept. An idea is deleted automatically after 12 months.') . '</p>',
    ];
    $build += parent::render();
    $build['table']['#empty'] = $this->t('No ideas yet.');
    return $build;
  }

}
