<?php

declare(strict_types=1);

namespace Drupal\mersch_sso;

/**
 * La connexion SSO est refusée ; la raison choisit le message affiché.
 */
class EchecConnexion extends \RuntimeException {

  public const NON_RECONNU = 'non_reconnu';
  public const INACTIF = 'inactif';
  public const REFUSE = 'refuse';
  public const BLOQUE = 'bloque';
  public const ANNUAIRE = 'annuaire';

  public function __construct(public readonly string $raison, string $detail = '') {
    parent::__construct($detail ?: $raison);
  }

}
