<?php

declare(strict_types=1);

namespace Drupal\mersch_communication\Plugin\Validation\Constraint;

use Drupal\mersch_communication\Controller\CommunicationController;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Vérifie le gabarit d'une nouvelle Communication (US-18, D-031) : 150 mots
 * au plus, sans minimum ; une photo exige l'accord écrit.
 *
 * Une nouvelle qui nomme une personne sans photo ne peut pas être reconnue
 * automatiquement : la case reste à cocher par le rédacteur (aide du champ).
 */
class NouvelleCommunicationConstraintValidator extends ConstraintValidator {

  public const MOTS_MAX = 150;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== CommunicationController::TYPE) {
      return;
    }
    assert($constraint instanceof NouvelleCommunicationConstraint);

    $mots = self::compterMots((string) $entity->get('field_comm_texte')->value);
    if ($mots > self::MOTS_MAX) {
      $this->context->buildViolation($constraint->tropLong, ['@mots' => $mots])
        ->atPath('field_comm_texte')
        ->addViolation();
    }

    if (!$entity->get('field_comm_photo')->isEmpty() && !$entity->get('field_comm_accord')->value) {
      $this->context->buildViolation($constraint->accordManquant)
        ->atPath('field_comm_accord')
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
