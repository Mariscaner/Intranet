<?php

declare(strict_types=1);

namespace Drupal\mersch_guides\Drush\Commands;

use Drupal\mersch_guides\ImportGuides;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Reprise des guides IT existants (serveur ou DDEV).
 */
final class GuidesCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly ImportGuides $import,
  ) {
    parent::__construct();
  }

  /**
   * Publie les guides du dossier (guides.json + PDF).
   */
  #[CLI\Command(name: 'mersch:guides-import', aliases: ['mgi'])]
  #[CLI\Argument(name: 'dossier', description: 'Folder with guides.json and the PDF files.')]
  #[CLI\Option(name: 'remplacer', description: 'Delete the current guides first.')]
  #[CLI\Usage(name: 'drush mersch:guides-import ~/guides-it', description: 'First load of the IT guides.')]
  public function importer(string $dossier, array $options = ['remplacer' => FALSE]): int {
    try {
      $guides = $this->import->importer(rtrim($dossier, '/'), (bool) $options['remplacer']);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(sprintf('%d guides publiés.', $guides));
    return self::EXIT_SUCCESS;
  }

}
