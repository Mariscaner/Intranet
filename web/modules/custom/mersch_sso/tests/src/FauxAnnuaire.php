<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_sso;

use Drupal\mersch_sso\Annuaire\Agent;
use Drupal\mersch_sso\Annuaire\AnnuaireIndisponible;
use Drupal\mersch_sso\Annuaire\AnnuaireInterface;

/**
 * Faux AD pour les tests : agents en mémoire, panne simulable.
 */
class FauxAnnuaire implements AnnuaireInterface {

  /**
   * Agents connus, par identifiant en minuscules.
   *
   * @var \Drupal\mersch_sso\Annuaire\Agent[]
   */
  public static array $agents = [];

  /**
   * L'AD est-il en panne ?
   */
  public static bool $indisponible = FALSE;

  /**
   * Remet le faux annuaire à zéro.
   */
  public static function reinitialiser(): void {
    self::$agents = [];
    self::$indisponible = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function chercher(string $identifiant): ?Agent {
    if (self::$indisponible) {
      throw new AnnuaireIndisponible('AD simulé en panne');
    }
    return self::$agents[$identifiant] ?? NULL;
  }

}
