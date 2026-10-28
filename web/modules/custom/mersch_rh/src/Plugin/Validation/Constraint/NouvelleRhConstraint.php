<?php

declare(strict_types=1);

namespace Drupal\mersch_rh\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Gabarit de nouvelle RH (D-023).
 */
#[Constraint(
  id: 'MerschNouvelleRh',
  label: new TranslatableMarkup('HR news template', [], ['context' => 'Validation']),
  type: 'entity:node',
)]
class NouvelleRhConstraint extends SymfonyConstraint {

  public string $tropLong = 'The text has @mots words; the maximum is 150 (D-023).';

  public string $accordManquant = 'A new face, a retirement or a photo requires the written consent of the person concerned (D-023).';

}
