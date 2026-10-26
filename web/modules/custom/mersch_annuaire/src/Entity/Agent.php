<?php

declare(strict_types=1);

namespace Drupal\mersch_annuaire\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Fiche d'un agent dans l'annuaire.
 *
 * Les fiches ne se modifient pas dans l'intranet : elles sont remplacées à
 * chaque import de la liste Excel du personnel (D-026). Les champs reprennent
 * les colonnes de la liste, rien de plus (minimisation RGPD).
 */
#[ContentEntityType(
  id: 'mersch_agent',
  label: new TranslatableMarkup('Staff member'),
  label_collection: new TranslatableMarkup('Staff directory'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'nom',
  ],
  base_table: 'mersch_agent',
  admin_permission: 'import mersch annuaire',
)]
class Agent extends ContentEntityBase {

  /**
   * Colonnes de la liste Excel reprises dans la fiche, avec leur libellé.
   */
  public const CHAMPS = [
    'nom' => 'Last name',
    'prenom' => 'First name',
    'service' => 'Department',
    'batiment' => 'Building',
    'telephone' => 'Phone',
    'gsm' => 'Mobile',
    'email' => 'E-mail',
    'email_service' => 'Department e-mail',
    'remarque' => 'Remark',
  ];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    foreach (self::CHAMPS as $nom => $libelle) {
      $fields[$nom] = BaseFieldDefinition::create('string')
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        ->setLabel(new TranslatableMarkup($libelle))
        ->setSetting('max_length', 255)
        ->setRequired(in_array($nom, ['nom', 'prenom', 'service'], TRUE));
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return $this->get('nom')->value . ' ' . $this->get('prenom')->value;
  }

}
