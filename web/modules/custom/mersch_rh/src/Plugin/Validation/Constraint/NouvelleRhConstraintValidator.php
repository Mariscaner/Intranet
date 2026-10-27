<?php

declare(strict_types=1);

namespace Drupal\mersch_rh\Plugin\Validation\Constraint;

use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Vérifie le gabarit d'une nouvelle RH (US-13, D-023).
 */
class NouvelleRhConstraintValidator extends ConstraintValidator {

  public const MOTS_MIN = 80;

  public const MOTS_MAX = 150;

  /**
   * Catégories nominatives par nature : l'accord écrit est toujours exigé.
   */
  public const CATEGORIES_NOMINATIVES = ['arrivee', 'retraite'];

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'rh_nouvelle') {
      return;
    }
    assert($constraint instanceof NouvelleRhConstraint);

    $mots = self::compterMots((string) $entity->get('field_rh_texte')->value);
    if ($mots > self::MOTS_MAX) {
      $this->context->buildViolation($constraint->tropLong, ['@mots' => $mots])
        ->atPath('field_rh_texte')
        ->addViolation();
    }

    $nominative = in_array($entity->get('field_rh_categorie')->value, self::CATEGORIES_NOMINATIVES, TRUE)
      || !$entity->get('field_rh_photo')->isEmpty();
    if ($nominative && !$entity->get('field_rh_accord')->value) {
      $this->context->buildViolation($constraint->accordManquant)
        ->atPath('field_rh_accord')
        ->addViolation();
    }
  }

  /**
   * Nombre de mots d'un texte, balises HTML ôtées.
   */
  public static function compterMots(string $texte): int {
    $texte = trim(html_entity_decode(strip_tags($texte), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $texte === '' ? 0 : count(preg_split('/\s+/u', $texte));
  }

}
