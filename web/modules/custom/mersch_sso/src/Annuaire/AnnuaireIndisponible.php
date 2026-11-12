<?php

declare(strict_types=1);

namespace Drupal\mersch_sso\Annuaire;

/**
 * L'AD ne répond pas, ou la liaison LDAP est refusée.
 */
class AnnuaireIndisponible extends \RuntimeException {}
