<?php

declare(strict_types=1);

namespace Drupal\mersch_services\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Contact d'un service, choisi dans l'annuaire (US-23).
 *
 * On ne garde que de quoi retrouver la fiche après un nouvel import (nom,
 * prénom, e-mail) et le rôle facultatif saisi par le rédacteur ; numéros et
 * bâtiment sont lus dans l'annuaire à l'affichage (minimisation, D-026).
 */
#[FieldType(
  id: 'mersch_contact',
  label: new TranslatableMarkup('Staff directory contact'),
  description: new TranslatableMarkup('A colleague from the staff directory, with an optional role.'),
  default_widget: 'mersch_contact_annuaire',
  no_ui: TRUE,
)]
class ContactItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['nom'] = DataDefinition::create('string')->setLabel(new TranslatableMarkup('Last name'));
    $properties['prenom'] = DataDefinition::create('string')->setLabel(new TranslatableMarkup('First name'));
    $properties['email'] = DataDefinition::create('string')->setLabel(new TranslatableMarkup('E-mail'));
    $properties['role'] = DataDefinition::create('string')->setLabel(new TranslatableMarkup('Role'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'nom' => ['type' => 'varchar', 'length' => 255],
        'prenom' => ['type' => 'varchar', 'length' => 255],
        'email' => ['type' => 'varchar', 'length' => 255],
        'role' => ['type' => 'varchar', 'length' => 128],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'nom';
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return trim((string) $this->get('nom')->getValue()) === '' && trim((string) $this->get('email')->getValue()) === '';
  }

}
