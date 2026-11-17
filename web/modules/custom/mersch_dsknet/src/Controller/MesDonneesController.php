<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mersch_dsknet\Correspondance;
use Drupal\mersch_dsknet\Donnees;
use Drupal\mersch_dsknet\DsknetIndisponible;

/**
 * Page « Mes données DSKnet » : uniquement l'agent connecté, lecture seule.
 */
class MesDonneesController extends ControllerBase {

  public function __construct(
    private readonly AccountInterface $agent,
    private readonly Correspondance $correspondance,
    private readonly Donnees $donnees,
    private readonly LanguageManagerInterface $langues,
    private readonly ConfigFactoryInterface $config,
  ) {}

  /**
   * Construit la page.
   */
  public function page(): array {
    $page = [
      '#theme' => 'mersch_dsknet',
      '#statut' => 'ok',
      '#lien_dsknet' => $this->config->get('mersch_accueil.lien_rapide.dsknet')->get('url') ?: NULL,
      '#attached' => ['library' => ['mersch_dsknet/dsknet']],
      // Données personnelles lues en direct : jamais mises en cache.
      '#cache' => ['max-age' => 0, 'contexts' => ['user']],
    ];
    try {
      $noPers = $this->correspondance->noPers((int) $this->agent->id());
    }
    catch (DsknetIndisponible) {
      $page['#statut'] = 'indisponible';
      return $page;
    }
    if (!$noPers) {
      $page['#statut'] = 'non_relie';
      return $page;
    }
    $langue = $this->langues->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    $page['#donnees'] = $this->donnees->tout($noPers, $langue);
    return $page;
  }

}
