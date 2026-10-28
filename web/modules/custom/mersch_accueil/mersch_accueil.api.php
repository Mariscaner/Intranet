<?php

/**
 * @file
 * Hooks fournis par le module mersch_accueil.
 */

declare(strict_types=1);

use Drupal\Core\Cache\CacheableMetadata;

/**
 * Fournit les dernières nouvelles d'une rubrique pour l'accueil.
 *
 * L'accueil fusionne les nouvelles de toutes les rubriques, les trie de la
 * plus récente à la plus ancienne et en affiche
 * \Drupal\mersch_accueil\Controller\AccueilController::NOUVELLES.
 *
 * @param int $limite
 *   Nombre maximum de nouvelles à fournir.
 * @param \Drupal\Core\Cache\CacheableMetadata $cache
 *   Métadonnées de cache de l'accueil, à compléter (étiquettes des contenus).
 *
 * @return array
 *   Liste de [ titre, created (horodatage), rubrique (rh, communication ou
 *   guides_it) ], nouvelles publiées et visibles par l'utilisateur seulement.
 */
function hook_mersch_accueil_nouvelles(int $limite, CacheableMetadata $cache): array {
  return [
    ['titre' => 'Neien Nik ass eraus', 'created' => 1793170000, 'rubrique' => 'communication'],
  ];
}
