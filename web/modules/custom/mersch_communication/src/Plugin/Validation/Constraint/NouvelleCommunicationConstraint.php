<?php

declare(strict_types=1);

namespace Drupal\mersch_communication\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Gabarit de nouvelle Communication (D-031).
 */
#[Constraint(
  id: 'MerschNouvelleCommunication',
  label: new TranslatableMarkup('Communication news template', [], ['context' => 'Validation']),
  type: 'entity:node',
)]
class NouvelleCommunicationConstraint extends SymfonyConstraint {

  public string $tropLong = 'The text has @mots words; the maximum is 150 (D-031).';

  public string $accordManquant = 'A photo requires the written consent of the people shown (D-031).';

}
