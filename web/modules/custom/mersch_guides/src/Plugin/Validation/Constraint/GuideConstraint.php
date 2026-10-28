<?php

declare(strict_types=1);

namespace Drupal\mersch_guides\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Contenu minimal d'un guide IT (US-21).
 */
#[Constraint(
  id: 'MerschGuide',
  label: new TranslatableMarkup('IT guide content', [], ['context' => 'Validation']),
  type: 'entity:node',
)]
class GuideConstraint extends SymfonyConstraint {

  public string $vide = 'A guide needs a text or a PDF (US-21).';

}
