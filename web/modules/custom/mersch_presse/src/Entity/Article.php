<?php

declare(strict_types=1);

namespace Drupal\mersch_presse\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;

/**
 * Article de presse sur la commune de Mersch (US-39, D-045).
 *
 * Droits d'auteur et RGPD : seuls le titre, le média, la date, un extrait de
 * 300 caractères au plus et le lien sont gardés ; ni texte intégral, ni image.
 * Un article masqué (US-42) reste enregistré pour ne pas revenir au passage
 * suivant. Tout article est supprimé 3 ans après sa date (D-045). La
 * Communication peut ajouter un article de la presse écrite à la main (D-048),
 * avec son scan PDF ou sa photo (D-049) ; le fichier est supprimé avec
 * l'article.
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
    // Scan PDF ou photo d'un article de la presse écrite (D-049).
    $fields['fichier'] = self::champFichier();
    // Article ajouté à la main par la Communication (D-048).
    $fields['manuel'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Added manually'))
      ->setDefaultValue(FALSE);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Collected on'));
    return $fields;
  }

  /**
   * Extensions acceptées pour le scan ou la photo (D-049).
   */
  public const EXTENSIONS = 'pdf jpg jpeg png';

  /**
   * Taille maximale du fichier.
   */
  public const TAILLE = '10 MB';

  /**
   * Définition du champ « fichier », partagée avec la mise à jour 11002.
   */
  public static function champFichier(): BaseFieldDefinition {
    return BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Scan or photo'))
      ->setSetting('uri_scheme', 'public')
      ->setSetting('file_directory', 'presse/[date:custom:Y]-[date:custom:m]')
      ->setSetting('file_extensions', self::EXTENSIONS)
      ->setSetting('max_filesize', self::TAILLE);
  }

  /**
   * {@inheritdoc}
   *
   * Le scan ou la photo est supprimé avec l'article (D-049) : Drupal garde
   * sinon les fichiers qui ne servent plus.
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    foreach ($entities as $article) {
      if ($fichier = $article->get('fichier')->entity) {
        self::supprimerSiInutilise($fichier);
      }
    }
  }

  /**
   * Supprime un fichier qui n'est plus utilisé nulle part.
   */
  public static function supprimerSiInutilise(FileInterface $fichier): void {
    if (!\Drupal::service('file.usage')->listUsage($fichier)) {
      $fichier->delete();
    }
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
