<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet\Drush\Commands;

use Drupal\mersch_dsknet\Correspondance;
use Drupal\mersch_dsknet\Donnees;
use Drupal\mersch_dsknet\DsknetIndisponible;
use Drupal\mersch_dsknet\Client;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Diagnostic DSKnet : version, jeton, correspondance, appels de la page.
 */
final class DsknetCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Client $client,
    private readonly Correspondance $correspondance,
    private readonly Donnees $donnees,
  ) {
    parent::__construct();
  }

  /**
   * Vérifie l'accès à DSKnet et, pour un agent, chaque appel de la page.
   *
   * N'affiche jamais le jeton. Avec un identifiant, affiche les données DSK
   * de cet agent : à lancer avec son propre identifiant AD.
   */
  #[CLI\Command(name: 'mersch:dsknet-verifier', aliases: ['mdv'])]
  #[CLI\Argument(name: 'identifiant', description: 'Windows login (AD) of the staff member, preferably your own.')]
  #[CLI\Option(name: 'nopers', description: 'DSK staff number to use instead of the automatic search.')]
  #[CLI\Usage(name: 'drush mersch:dsknet-verifier bema', description: 'Checks DSKnet for your own account.')]
  public function verifier(string $identifiant = '', array $options = ['nopers' => NULL]): int {
    $ok = TRUE;
    foreach (['/version', '/test'] as $chemin) {
      try {
        $reponse = $this->client->get($chemin);
        $this->io()->writeln(sprintf('OK      %s %s', $chemin, $chemin === '/version' ? json_encode($reponse, JSON_UNESCAPED_UNICODE) : ''));
      }
      catch (DsknetIndisponible $e) {
        $this->io()->writeln(sprintf('ERREUR  %s : %s', $chemin, $e->getMessage()));
        $ok = FALSE;
      }
    }
    if (!$ok || ($identifiant === '' && !$options['nopers'])) {
      return $ok ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }

    $noPers = (int) $options['nopers'];
    if (!$noPers) {
      $identifiant = strtolower($identifiant);
      try {
        $jour = date('Y-m-d');
        $parLogin = Correspondance::logins($this->client->get('/listAll/details', ['dateFrom' => $jour, 'dateTo' => $jour]));
        $noPers = (int) ($parLogin[$identifiant] ?? 0);
        $this->io()->writeln(sprintf('%s /listAll/details : %d logins DSK lus, « %s » %s', $noPers ? 'OK     ' : 'NON    ', count($parLogin), $identifiant, $noPers ? "→ n° DSK $noPers" : 'absent'));
        if (!$noPers) {
          $noPers = (int) $this->correspondance->parCode($identifiant);
          $this->io()->writeln(sprintf('%s /listAll/id (code personnel) : %s', $noPers ? 'OK     ' : 'NON    ', $noPers ? "→ n° DSK $noPers" : 'absent'));
        }
      }
      catch (DsknetIndisponible $e) {
        $this->io()->writeln('ERREUR  correspondance : ' . $e->getMessage());
        return self::EXIT_FAILURE;
      }
      if (!$noPers) {
        $this->io()->writeln('NON     agent introuvable dans DSK : saisir le n° DSK sur son compte (ou relancer avec --nopers=<n°>).');
        return self::EXIT_FAILURE;
      }
    }

    foreach (['dayBookings', 'solde', 'counters', 'absences'] as $appel) {
      try {
        $bruts = $this->donnees->bruts($appel, $noPers);
        $this->io()->writeln("OK      $appel");
        $this->io()->writeln('        ' . $this->resume($appel, $bruts));
      }
      catch (DsknetIndisponible $e) {
        $this->io()->writeln("ERREUR  $appel : " . $e->getMessage());
        $ok = FALSE;
      }
    }
    return $ok ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Relie les comptes SSO aux agents DSK par leur login (identifiant AD).
   *
   * Lancé aussi chaque jour par le cron. N'enregistre que le n° DSK.
   */
  #[CLI\Command(name: 'mersch:dsknet-relier', aliases: ['mdr'])]
  #[CLI\Usage(name: 'drush mersch:dsknet-relier', description: 'Links all SSO accounts to DSKNet now.')]
  public function relier(): int {
    try {
      $resultat = $this->correspondance->relierTous();
    }
    catch (DsknetIndisponible $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(sprintf('%d comptes reliés à DSK.', $resultat['relies']));
    if ($resultat['sans_dsk']) {
      $this->logger()->warning('Sans login DSK correspondant : ' . implode(', ', $resultat['sans_dsk']));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Ce qu'il faut confirmer pour régler la page (codes, unités, langues).
   */
  private function resume(string $appel, mixed $bruts): string {
    $bruts = is_array($bruts) ? $bruts : [];
    $champs = fn (array $ligne, array $noms): array => array_intersect_key($ligne, array_flip($noms));
    $lignes = match ($appel) {
      'dayBookings' => array_map(fn ($p) => $champs((array) $p, ['time', 'type', 'subType', 'source']), $bruts['dayBookings'] ?? []),
      'solde' => [$champs($bruts, ['timeBalance', 'timeBalanceStr', 'timeBalanceDate'])],
      'counters' => array_map(fn ($c) => $champs((array) $c, ['noCounter', 'caption', 'inDay', 'rest', 'balance', 'visibility']), $bruts),
      'absences' => [['jours' => count($bruts), 'types' => array_values(array_unique(array_column($bruts, 'noAccount')))]],
    };
    return json_encode($lignes, JSON_UNESCAPED_UNICODE);
  }

}
