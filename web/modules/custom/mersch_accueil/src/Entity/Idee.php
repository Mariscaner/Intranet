<?php

declare(strict_types=1);

namespace Drupal\mersch_accueil\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mersch_accueil\IdeeListBuilder;

/**
 * Idée d'amélioration de l'intranet, déposée par un agent connecté.
 *
 * RGPD : seuls l'auteur, la date et le texte sont conservés. Les idées ne
 * sont lues que par les admins, qui les suppriment une fois traitées ; une
 * idée est de toute façon supprimée 12 mois après son envoi (D-041).
 */
#[ContentEntityType(
  id: 'mersch_idee',
  label: new TranslatableMarkup('Idea'),
  label_collection: new TranslatableMarkup('Ideas box'),
  base_table: 'mersch_idee',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'list_builder' => IdeeListBuilder::class,
    'form' => [
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/idees',
    'delete-form' => '/admin/content/idees/{mersch_idee}/delete',
  ],
  admin_permission: 'administer mersch idees',
)]
class Idee extends ContentEntityBase {

  /**
   * Longueur maximale d'une idée, en caractères.
   */
  public const LONGUEUR = 2000;

  /**
   * Durée de conservation d'une idée : 12 mois (D-041), en secondes.
   */
  public const CONSERVATION = 365 * 86400;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Author'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Date'));
    $fields['texte'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Idea'))
      ->setRequired(TRUE);
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return mb_strimwidth((string) $this->get('texte')->value, 0, 60, '…');
  }

}
