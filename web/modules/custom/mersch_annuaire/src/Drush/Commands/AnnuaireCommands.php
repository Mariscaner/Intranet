<?php

declare(strict_types=1);

namespace Drupal\mersch_annuaire\Drush\Commands;

use Drupal\mersch_annuaire\Importeur;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Import de la liste du personnel en ligne de commande (serveur ou DDEV).
 */
final class AnnuaireCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'mersch_annuaire.importeur')]
    private readonly Importeur $importeur,
  ) {
    parent::__construct();
  }

  /**
   * Importe la liste Excel du personnel dans l'annuaire (D-026).
   */
  #[CLI\Command(name: 'mersch:annuaire-import', aliases: ['mai'])]
  #[CLI\Argument(name: 'fichier', description: 'Excel (.xlsx) or CSV staff list.')]
  #[CLI\Option(name: 'verifier', description: 'Only show the report, do not change the directory.')]
  #[CLI\Usage(name: 'drush mersch:annuaire-import ~/personnel.xlsx --verifier', description: 'Check the list without importing it.')]
  public function importer(string $fichier, array $options = ['verifier' => FALSE]): int {
    if (!is_readable($fichier)) {
      $this->logger()->error(sprintf('Fichier introuvable : %s', $fichier));
      return self::EXIT_FAILURE;
    }
    try {
      $resultat = $this->importeur->analyser($fichier, basename($fichier));
      foreach ($this->importeur->compteRendu($resultat) as $phrase) {
        $this->io()->writeln('- ' . $phrase);
      }
      $this->logger()->notice((string) $this->importeur->resume($resultat));
      if ($options['verifier']) {
        $this->logger()->notice('Vérification seule : l\'annuaire n\'a pas changé.');
        return self::EXIT_SUCCESS;
      }
      $nombre = $this->importeur->importer($resultat, basename($fichier));
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(sprintf('Annuaire mis à jour : %d agents.', $nombre));
    return self::EXIT_SUCCESS;
  }

}
