<?php

declare(strict_types=1);

namespace Drupal\mersch_presse\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Article de presse sur la commune de Mersch (US-39, D-045).
 *
 * Droits d'auteur et RGPD : seuls le titre, le média, la date, un extrait de
 * 300 caractères au plus et le lien sont gardés ; ni texte intégral, ni image.
 * Un article masqué (US-42) reste enregistré pour ne pas revenir au passage
 * suivant. Tout article est supprimé 3 ans après sa date (D-045). La
 * Communication peut ajouter un article de la presse écrite à la main (D-048).
 */
#[ContentEntityType(
  id: 'mersch_presse_article',
  label: new TranslatableMarkup('Press article'),
  label_collection: new TranslatableMarkup('Press review'),
  base_table: 'mersch_presse_article',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'titre',
  ],
  admin_permission: 'administer mersch presse',
)]
class Article extends ContentEntityBase {

  /**
   * Longueur maximale de l'extrait, en caractères (D-045).
   */
  public const EXTRAIT = 300;

  /**
   * Durée de conservation : 3 ans (D-045), en secondes.
   */
  public const CONSERVATION = 3 * 365 * 86400;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['titre'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setSetting('max_length', 512)
      ->setRequired(TRUE);
    $fields['media'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Media'))
      ->setSetting('max_length', 128)
      ->setRequired(TRUE);
    $fields['extrait'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Excerpt'))
      ->setSetting('max_length', self::EXTRAIT + 10);
    // Facultatif pour un article de la presse écrite ajouté à la main.
    $fields['lien'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Link'));
    $fields['date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Date'))
      ->setRequired(TRUE);
    // Empreintes du lien et du titre : évitent les doublons, par exemple le
    // même article lu dans le flux du média et dans Google Actualités.
    $fields['cle_lien'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link key'))
      ->setSetting('max_length', 40);
    $fields['cle_titre'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title key'))
      ->setSetting('max_length', 40);
    $fields['masque'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Hidden'))
      ->setDefaultValue(FALSE);
    // Article ajouté à la main par la Communication (D-048).
    $fields['manuel'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Added manually'))
      ->setDefaultValue(FALSE);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Collected on'));
    return $fields;
  }

  /**
   * L'article a-t-il été ajouté à la main (D-048) ?
   */
  public function estManuel(): bool {
    return (bool) $this->get('manuel')->value;
  }

  /**
   * L'article est-il masqué (US-42) ?
   */
  public function estMasque(): bool {
    return (bool) $this->get('masque')->value;
  }

}
