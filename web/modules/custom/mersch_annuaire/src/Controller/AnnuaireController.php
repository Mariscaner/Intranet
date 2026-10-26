<?php

declare(strict_types=1);

namespace Drupal\mersch_annuaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mersch_annuaire\ListeParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Page de l'annuaire : agents groupés par service, triés par nom (US-06).
 */
class AnnuaireController extends ControllerBase {

  public function __construct(
    #[Autowire(service: 'mersch_annuaire.parser')]
    private readonly ListeParser $parser,
  ) {}

  /**
   * Affiche l'annuaire avec le champ de recherche (US-07, US-08).
   */
  public function page(): array {
    $agents = $this->entityTypeManager()->getStorage('mersch_agent')->loadMultiple();

    $services = [];
    foreach ($agents as $agent) {
      $fiche = [];
      foreach (['nom', 'prenom', 'service', 'batiment', 'telephone', 'gsm', 'email', 'email_service', 'remarque'] as $champ) {
        $fiche[$champ] = (string) $agent->get($champ)->value;
      }
      $fiche['tel_lien'] = $this->lienTelephone($fiche['telephone']);
      $fiche['gsm_lien'] = $this->lienTelephone($fiche['gsm']);
      // Texte de recherche sans accents ni majuscules ; les chiffres des
      // numéros sont ajoutés sans espaces.
      $fiche['recherche'] = $this->parser->cle(implode(' ', [
        $fiche['nom'], $fiche['prenom'], $fiche['service'], $fiche['batiment'],
        $fiche['email'], $fiche['email_service'], $fiche['remarque'],
        preg_replace('/\D/', '', $fiche['telephone']),
        preg_replace('/\D/', '', $fiche['gsm']),
      ]));
      $services[$this->parser->cle($fiche['service'])]['nom'] = $fiche['service'];
      $services[$this->parser->cle($fiche['service'])]['agents'][] = $fiche;
    }

    ksort($services);
    foreach ($services as &$service) {
      usort($service['agents'], fn (array $a, array $b) => [$this->parser->cle($a['nom']), $this->parser->cle($a['prenom'])] <=> [$this->parser->cle($b['nom']), $this->parser->cle($b['prenom'])]);
    }
    unset($service);

    return [
      '#theme' => 'mersch_annuaire',
      '#services' => array_values($services),
      '#nombre' => count($agents),
      '#attached' => ['library' => ['mersch_annuaire/annuaire']],
      '#cache' => ['tags' => ['mersch_agent_list']],
    ];
  }

  /**
   * Lien tel: au format international ; préfixe +352 pour un numéro local.
   */
  private function lienTelephone(string $numero): string {
    $chiffres = preg_replace('/\D/', '', $numero);
    if ($chiffres === '') {
      return '';
    }
    if (str_starts_with(trim($numero), '+')) {
      return 'tel:+' . $chiffres;
    }
    if (str_starts_with($chiffres, '00')) {
      return 'tel:+' . substr($chiffres, 2);
    }
    return 'tel:+352' . $chiffres;
  }

}
