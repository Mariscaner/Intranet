<?php

declare(strict_types=1);

namespace Drupal\mersch_rh\Drush\Commands;

use Drupal\mersch_rh\ImportOrganigramme;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Premier chargement de l'organigramme (serveur ou DDEV).
 */
final class OrganigrammeCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly ImportOrganigramme $import,
  ) {
    parent::__construct();
  }

  /**
   * Charge l'organigramme extrait du PDF des RH (organigramme.json + photos).
   */
  #[CLI\Command(name: 'mersch:organigramme-import', aliases: ['moi'])]
  #[CLI\Argument(name: 'dossier', description: 'Folder with organigramme.json and photos/.')]
  #[CLI\Option(name: 'remplacer', description: 'Delete the current organisation chart first.')]
  #[CLI\Usage(name: 'drush mersch:organigramme-import ~/organigramme', description: 'First load of the organisation chart.')]
  public function importer(string $dossier, array $options = ['remplacer' => FALSE]): int {
    try {
      $resultat = $this->import->importer(rtrim($dossier, '/'), (bool) $options['remplacer']);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(sprintf('Organigramme chargé : %d services, %d postes, %d photos.', $resultat['services'], $resultat['postes'], $resultat['photos']));
    return self::EXIT_SUCCESS;
  }

}
