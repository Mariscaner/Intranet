<?php

declare(strict_types=1);

namespace Drupal\mersch_annuaire;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lit la liste du personnel : Excel (.xlsx, .xls, .ods) ou texte (.csv, .txt).
 */
class LecteurFichier {

  /**
   * Extensions acceptées.
   */
  public const EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv', 'txt'];

  /**
   * Renvoie les lignes de la première feuille, cellule par cellule.
   *
   * @param string $chemin
   *   Chemin du fichier sur le disque.
   * @param string $nom
   *   Nom d'origine du fichier, pour en connaître le type.
   *
   * @return array<int, array<int, mixed>>
   *   Les lignes du fichier.
   *
   * @throws \InvalidArgumentException
   *   Si le type de fichier n'est pas accepté ou le fichier illisible.
   */
  public function lire(string $chemin, string $nom): array {
    $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    if (!in_array($extension, self::EXTENSIONS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Type de fichier non accepté : %s.', $extension));
    }
    if (in_array($extension, ['csv', 'txt'], TRUE)) {
      return $this->lireTexte($chemin);
    }
    try {
      $lecteur = IOFactory::createReaderForFile($chemin);
      $lecteur->setReadDataOnly(FALSE);
      $classeur = $lecteur->load($chemin);
    }
    catch (\Throwable $e) {
      throw new \InvalidArgumentException('Fichier Excel illisible : ' . $e->getMessage(), 0, $e);
    }
    // Valeurs telles qu'affichées dans Excel, pour garder les espaces des numéros.
    $lignes = $classeur->getSheet(0)->toArray('', TRUE, TRUE, FALSE);
    $classeur->disconnectWorksheets();
    return $lignes;
  }

  /**
   * Lit un CSV ou un texte copié depuis Excel (tabulations).
   *
   * Le séparateur (tabulation, point-virgule ou virgule) est déduit de la
   * première ligne ; un fichier en Windows-1252 est converti en UTF-8.
   */
  private function lireTexte(string $chemin): array {
    $contenu = file_get_contents($chemin);
    if ($contenu === FALSE) {
      throw new \InvalidArgumentException('Fichier illisible.');
    }
    $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
    if (!mb_check_encoding($contenu, 'UTF-8')) {
      $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
    }
    $premiere = strtok($contenu, "\r\n") ?: '';
    $comptes = [
      "\t" => substr_count($premiere, "\t"),
      ';' => substr_count($premiere, ';'),
      ',' => substr_count($premiere, ','),
    ];
    arsort($comptes);
    $separateur = (string) array_key_first($comptes);

    $flux = fopen('php://memory', 'r+');
    fwrite($flux, $contenu);
    rewind($flux);
    $lignes = [];
    while (($cellules = fgetcsv($flux, NULL, $separateur, '"', '')) !== FALSE) {
      $lignes[] = $cellules;
    }
    fclose($flux);
    return $lignes;
  }

}
