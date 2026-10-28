<?php

declare(strict_types=1);

namespace Drupal\mersch_guides\Plugin\Validation\Constraint;

use Drupal\mersch_guides\Controller\GuidesController;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Vérifie qu'un guide contient un texte ou un PDF (US-21).
 *
 * Le PDF est commun aux trois langues ; le texte peut n'exister qu'en LU,
 * les autres langues l'affichent alors avec la mention « traduction non
 * disponible » (US-02).
 */
class GuideConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== GuidesController::TYPE) {
      return;
    }
    assert($constraint instanceof GuideConstraint);

    if (!$entity->get('field_guide_pdf')->isEmpty()) {
      return;
    }
    foreach ($entity->getTranslationLanguages() as $langcode => $langue) {
      $texte = $entity->getTranslation($langcode)->get('field_guide_texte')->value;
      if (trim(strip_tags((string) $texte)) !== '') {
        return;
      }
    }
    $this->context->buildViolation($constraint->vide)
      ->atPath('field_guide_texte')
      ->addViolation();
  }

}
