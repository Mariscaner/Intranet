<?php

declare(strict_types=1);

namespace Drupal\mersch_rh;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * Organigramme fourni par les RH (US-12, D-011).
 *
 * Un seul fichier à la fois : le nouveau remplace l'ancien, qui est supprimé
 * (pas d'archive). Le fichier et sa date sont gardés dans l'état du site,
 * pas dans la configuration : c'est du contenu.
 */
class Organigramme {

  /**
   * Clé d'état : fid, date du dépôt, auteur.
   */
  public const ETAT = 'mersch_rh.organigramme';

  /**
   * Étiquette de cache de la page RH à vider à chaque dépôt.
   */
  public const CACHE_TAG = 'mersch_rh_organigramme';

  /**
   * Formats acceptés : le PDF des RH, ou une image.
   */
  public const EXTENSIONS = 'pdf png jpg jpeg';

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUsageInterface $fileUsage,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Fichier actuel, ou NULL si aucun organigramme n'a été déposé.
   */
  public function fichier(): ?FileInterface {
    $fid = $this->state->get(self::ETAT)['fid'] ?? NULL;
    return $fid ? $this->entityTypeManager->getStorage('file')->load($fid) : NULL;
  }

  /**
   * Date du dernier dépôt (horodatage), ou NULL.
   */
  public function date(): ?int {
    return $this->state->get(self::ETAT)['date'] ?? NULL;
  }

  /**
   * Remplace l'organigramme par un fichier déposé ; l'ancien est supprimé.
   */
  public function remplacer(FileInterface $nouveau, int $uid): void {
    $ancien = $this->fichier();

    $nouveau->setPermanent();
    $nouveau->save();
    $this->fileUsage->add($nouveau, 'mersch_rh', 'organigramme', 1);
    $this->state->set(self::ETAT, [
      'fid' => (int) $nouveau->id(),
      'date' => $this->time->getRequestTime(),
      'uid' => $uid,
    ]);

    if ($ancien && $ancien->id() !== $nouveau->id()) {
      $this->fileUsage->delete($ancien, 'mersch_rh', 'organigramme', 1, 0);
      $ancien->delete();
    }
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * L'organigramme est-il un PDF (sinon une image) ?
   */
  public static function estPdf(FileInterface $fichier): bool {
    return $fichier->getMimeType() === 'application/pdf';
  }

}
