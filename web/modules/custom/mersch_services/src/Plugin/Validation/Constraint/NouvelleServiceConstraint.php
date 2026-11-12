<?php

declare(strict_types=1);

namespace Drupal\mersch_services\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Nouvelle d'un service : gabarit (D-031) et service du rédacteur (D-007).
 */
#[Constraint(
  id: 'MerschNouvelleService',
  label: new TranslatableMarkup('Department news template', [], ['context' => 'Validation']),
  type: 'entity:node',
)]
class NouvelleServiceConstraint extends SymfonyConstraint {

  public string $tropLong = 'The text has @mots words; the maximum is 150 (D-031).';

  public string $pasSonService = 'You can only publish news for the departments you edit.';

}
