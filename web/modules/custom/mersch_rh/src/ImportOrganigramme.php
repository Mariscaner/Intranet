<?php

declare(strict_types=1);

namespace Drupal\mersch_rh;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

/**
 * Premier chargement de l'organigramme depuis l'extraction du PDF des RH.
 *
 * Le dossier contient organigramme.json (services et postes) et les photos.
 * Ces fichiers sont des données personnelles : ils ne vont jamais dans le
 * dépôt. Ensuite, les rédacteurs RH tiennent l'organigramme à jour dans
 * l'intranet.
 */
class ImportOrganigramme {

  /**
   * Dossier des photos de l'organigramme.
   */
  public const DOSSIER_PHOTOS = 'public://rh/organigramme';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Importe le dossier ; renvoie le nombre de services, postes et photos.
   *
   * @throws \InvalidArgumentException
   *   Fichier illisible, service inconnu, ou organigramme déjà rempli sans
   *   l'option de remplacement.
   */
  public function importer(string $dossier, bool $remplacer = FALSE): array {
    $donnees = json_decode((string) @file_get_contents($dossier . '/organigramme.json'), TRUE);
    if (!is_array($donnees) || !isset($donnees['services'], $donnees['postes'])) {
      throw new \InvalidArgumentException(sprintf('organigramme.json introuvable ou illisible dans %s.', $dossier));
    }
    $noeuds = $this->entityTypeManager->getStorage('node');
    $termes = $this->entityTypeManager->getStorage('taxonomy_term');
    $existants = $noeuds->loadByProperties(['type' => 'rh_poste']);
    if ($existants && !$remplacer) {
      throw new \InvalidArgumentException('L\'organigramme contient déjà des postes : relancez avec l\'option de remplacement.');
    }
    $noeuds->delete($existants);
    $termes->delete($termes->loadByProperties(['vid' => 'rh_service']));

    $services = [];
    foreach ($donnees['services'] as $service) {
      $parent = $service['parent'] ?? NULL;
      if ($parent !== NULL && !isset($services[$parent])) {
        throw new \InvalidArgumentException(sprintf('Service parent inconnu : %s.', $parent));
      }
      $terme = $termes->create([
        'vid' => 'rh_service',
        'name' => $service['nom'],
        'weight' => (int) ($service['ordre'] ?? 0),
        'parent' => $parent === NULL ? 0 : $services[$parent],
      ]);
      $terme->save();
      $services[$service['id']] = (int) $terme->id();
    }

    $dossierPhotos = self::DOSSIER_PHOTOS;
    $this->fileSystem->prepareDirectory($dossierPhotos, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $photos = 0;
    foreach ($donnees['postes'] as $poste) {
      if (!isset($services[$poste['service'] ?? ''])) {
        throw new \InvalidArgumentException(sprintf('Service inconnu pour %s : %s.', $poste['nom'] ?? '?', $poste['service'] ?? ''));
      }
      $valeurs = [
        'type' => 'rh_poste',
        'title' => $poste['nom'],
        'field_rh_fonction' => $poste['fonction'] ?? '',
        'field_rh_unite' => $services[$poste['service']],
        'field_rh_ordre' => (int) ($poste['ordre'] ?? 0),
        'status' => TRUE,
      ];
      // Seul le nom de fichier compte : pas de chemin hors du dossier importé.
      $source = empty($poste['photo']) ? NULL : $dossier . '/photos/' . basename($poste['photo']);
      if ($source && is_readable($source)) {
        $uri = $this->fileSystem->copy($source, $dossierPhotos . '/' . basename($source), FileExists::Rename);
        $fichier = $this->entityTypeManager->getStorage('file')->create(['uri' => $uri]);
        $fichier->setPermanent();
        $fichier->save();
        $valeurs['field_rh_photo'] = ['target_id' => $fichier->id(), 'alt' => $poste['nom']];
        $photos++;
      }
      $noeuds->create($valeurs)->save();
    }
    return ['services' => count($services), 'postes' => count($donnees['postes']), 'photos' => $photos];
  }

}
