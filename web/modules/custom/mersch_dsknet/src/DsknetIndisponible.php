<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet;

/**
 * DSKnet ne répond pas, refuse le jeton ou renvoie une réponse illisible.
 *
 * Le message sert au journal : il ne contient jamais le jeton ni de donnée
 * de l'agent.
 */
class DsknetIndisponible extends \RuntimeException {

  /**
   * Début d'une réponse illisible, pour le diagnostic drush seulement.
   */
  public string $extrait = '';

}
