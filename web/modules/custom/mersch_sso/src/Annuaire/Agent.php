<?php

declare(strict_types=1);

namespace Drupal\mersch_sso\Annuaire;

/**
 * Les seules données lues dans l'AD (minimisation RGPD, D-035).
 */
final class Agent {

  public function __construct(
    public readonly string $identifiant,
    public readonly string $nom,
    public readonly string $mail,
  ) {}

}
