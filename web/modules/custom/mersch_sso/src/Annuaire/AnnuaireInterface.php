<?php

declare(strict_types=1);

namespace Drupal\mersch_sso\Annuaire;

/**
 * Lecture du nom et de l'e-mail d'un agent dans l'AD.
 *
 * Derrière une interface pour pouvoir simuler l'AD dans les tests.
 */
interface AnnuaireInterface {

  /**
   * Cherche un agent par son identifiant AD (sAMAccountName).
   *
   * @return \Drupal\mersch_sso\Annuaire\Agent|null
   *   L'agent, ou NULL s'il n'existe pas dans l'AD.
   *
   * @throws \Drupal\mersch_sso\Annuaire\AnnuaireIndisponible
   *   Si l'AD ne répond pas ou refuse la liaison.
   */
  public function chercher(string $identifiant): ?Agent;

}
