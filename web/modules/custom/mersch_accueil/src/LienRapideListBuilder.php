<?php

declare(strict_types=1);

namespace Drupal\mersch_accueil;

use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Liste des liens rapides, à réordonner par glisser-déposer (US-11).
 */
class LienRapideListBuilder extends DraggableListBuilder {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mersch_lien_rapide_liste';
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label (LU)');
    $header['url'] = $this->t('URL');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\mersch_accueil\Entity\LienRapide $entity */
    $row['label'] = $entity->label();
    $row['url'] = ['#plain_text' => $entity->getUrl()];
    $row['status'] = ['#plain_text' => $entity->status() ? $this->t('Shown') : $this->t('Hidden')];
    return $row + parent::buildRow($entity);
  }

}
