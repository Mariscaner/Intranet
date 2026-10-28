<?php

declare(strict_types=1);

namespace Drupal\mersch_guides;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\mersch_accueil\Entity\LienRapide;
use Drupal\mersch_guides\Controller\GuidesController;

/**
 * Reprise des guides IT existants (US-20, D-014).
 *
 * Le dossier contient guides.json (catégorie, titre, résumé et mots-clés en
 * LU, DE et FR, nom du PDF) et les PDF. Il reste dans le dossier du projet,
 * hors du dépôt. Ensuite, les admins tiennent les guides à jour dans
 * l'intranet (US-21).
 */
class ImportGuides {

  /**
   * Dossier des PDF des guides.
   */
  public const DOSSIER_PDF = 'public://guides-it';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Importe le dossier ; renvoie le nombre de guides créés.
   *
   * @throws \InvalidArgumentException
   *   Fichier illisible, catégorie inconnue, PDF manquant, ou guides déjà
   *   présents sans l'option de remplacement.
   */
  public function importer(string $dossier, bool $remplacer = FALSE): int {
    $donnees = json_decode((string) @file_get_contents($dossier . '/guides.json'), TRUE);
    if (!is_array($donnees) || !isset($donnees['guides']) || !is_array($donnees['guides'])) {
      throw new \InvalidArgumentException(sprintf('guides.json introuvable ou illisible dans %s.', $dossier));
    }
    $categories = array_keys(\Drupal::classResolver(GuidesController::class)->categories());
    // Tout vérifier avant d'écrire : pas de reprise à moitié faite.
    foreach ($donnees['guides'] as $guide) {
      if (!in_array($guide['categorie'] ?? '', $categories, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Catégorie inconnue : %s.', $guide['categorie'] ?? ''));
      }
      if (empty($guide['titre']['lb']) || empty($guide['resume']['lb'])) {
        throw new \InvalidArgumentException('Chaque guide a un titre et un résumé en luxembourgeois.');
      }
      if (empty($guide['pdf']) || !is_readable($dossier . '/' . basename($guide['pdf']))) {
        throw new \InvalidArgumentException(sprintf('PDF introuvable : %s.', $guide['pdf'] ?? ''));
      }
    }

    $noeuds = $this->entityTypeManager->getStorage('node');
    $existants = $noeuds->loadByProperties(['type' => GuidesController::TYPE]);
    if ($existants && !$remplacer) {
      throw new \InvalidArgumentException('Des guides existent déjà : relancez avec l\'option de remplacement.');
    }
    $noeuds->delete($existants);

    $dossierPdf = self::DOSSIER_PDF;
    $this->fileSystem->prepareDirectory($dossierPdf, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    foreach ($donnees['guides'] as $guide) {
      // Seul le nom de fichier compte : pas de chemin hors du dossier importé.
      $nom = basename($guide['pdf']);
      $uri = $this->fileSystem->copy($dossier . '/' . $nom, $dossierPdf . '/' . $nom, FileExists::Rename);
      $fichier = $this->entityTypeManager->getStorage('file')->create(['uri' => $uri]);
      $fichier->setPermanent();
      $fichier->save();

      $node = $noeuds->create([
        'type' => GuidesController::TYPE,
        'langcode' => 'lb',
        'field_guide_categorie' => $guide['categorie'],
        'field_guide_pdf' => $fichier->id(),
        'status' => TRUE,
      ] + $this->textes($guide, 'lb'));
      foreach (array_diff(LienRapide::LANGUES, ['lb']) as $langcode) {
        if (!empty($guide['titre'][$langcode])) {
          $node->addTranslation($langcode, $this->textes($guide, $langcode));
        }
      }
      $node->save();
    }
    return count($donnees['guides']);
  }

  /**
   * Champs traduisibles d'un guide dans une langue.
   */
  private function textes(array $guide, string $langcode): array {
    return [
      'title' => $guide['titre'][$langcode],
      'field_guide_resume' => $guide['resume'][$langcode] ?? $guide['resume']['lb'],
      'field_guide_mots_cles' => $guide['mots_cles'][$langcode] ?? '',
      'status' => TRUE,
    ];
  }

}
