<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mersch_dsknet\AppelAbsent;
use Drupal\mersch_dsknet\DsknetIndisponible;
use Drupal\mersch_dsknet\Presence;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * États de présence de l'annuaire, en JSON (US-36, US-37).
 *
 * Réponse : {"etats": {"<id fiche>": "present|absent|deplacement"},
 * "disponible": true|false}. Rien d'autre : ni nom, ni motif, ni heure.
 */
class PresenceController extends ControllerBase {

  public function __construct(
    private readonly Presence $presence,
  ) {}

  /**
   * Retourne les états du moment (agents connectés seulement, voir route).
   */
  public function etats(): JsonResponse {
    try {
      $donnees = ['etats' => (object) $this->presence->etats(), 'disponible' => TRUE];
    }
    catch (DsknetIndisponible | AppelAbsent) {
      // Déjà journalisé par le client : l'annuaire reste affiché sans état.
      $donnees = ['etats' => (object) [], 'disponible' => FALSE];
    }
    $reponse = new JsonResponse($donnees);
    $reponse->headers->set('Cache-Control', 'private, no-store');
    return $reponse;
  }

}
