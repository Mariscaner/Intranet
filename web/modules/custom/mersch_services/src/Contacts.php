<?php

declare(strict_types=1);

namespace Drupal\mersch_services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mersch_annuaire\ListeParser;

/**
 * Relie les contacts d'un service aux fiches de l'annuaire (US-23, US-28).
 *
 * L'import de la liste Excel remplace toutes les fiches (D-026) : un contact
 * est donc retrouvé par son e-mail, sinon par son nom et son prénom, jamais
 * par l'identifiant de la fiche. Les numéros affichés sont toujours ceux de
 * l'annuaire du jour ; un agent qui a quitté la liste n'est plus affiché.
 */
class Contacts {

  /**
   * Fiches de l'annuaire, chargées une fois par requête.
   *
   * @var array<int, array<string, string>>|null
   */
  private ?array $agents = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ListeParser $parser,
  ) {}

  /**
   * Fiche de l'annuaire qui correspond à un contact enregistré, ou NULL.
   *
   * @param array{nom?: ?string, prenom?: ?string, email?: ?string} $contact
   *   Valeurs enregistrées du contact.
   *
   * @return array<string, string>|null
   *   La fiche (id, nom, prenom, service, batiment, telephone, gsm, email).
   */
  public function trouver(array $contact): ?array {
    $email = mb_strtolower(trim((string) ($contact['email'] ?? '')));
    $nom = $this->cleNom((string) ($contact['nom'] ?? ''), (string) ($contact['prenom'] ?? ''));
    $parNom = NULL;
    foreach ($this->agents() as $agent) {
      if ($email !== '' && mb_strtolower($agent['email']) === $email) {
        return $agent;
      }
      if ($parNom === NULL && $nom !== '' && $this->cleNom($agent['nom'], $agent['prenom']) === $nom) {
        $parNom = $agent;
      }
    }
    return $parNom;
  }

  /**
   * Fiche de l'annuaire par son identifiant du jour (saisie du formulaire).
   */
  public function parId(int $id): ?array {
    return $this->agents()[$id] ?? NULL;
  }

  /**
   * Agents dont le nom, le prénom ou le service contient le texte tapé.
   *
   * @return array<int, array<string, string>>
   *   Au plus $limite fiches, triées par nom.
   */
  public function chercher(string $texte, int $limite = 10): array {
    $mots = array_filter(preg_split('/\s+/', $this->parser->cle($texte)) ?: []);
    if (!$mots) {
      return [];
    }
    $resultats = [];
    foreach ($this->agents() as $agent) {
      $cible = $this->parser->cle($agent['nom'] . ' ' . $agent['prenom'] . ' ' . $agent['service']);
      foreach ($mots as $mot) {
        if (!str_contains($cible, $mot)) {
          continue 2;
        }
      }
      $resultats[] = $agent;
    }
    usort($resultats, fn (array $a, array $b) => $this->cleNom($a['nom'], $a['prenom']) <=> $this->cleNom($b['nom'], $b['prenom']));
    return array_slice($resultats, 0, $limite);
  }

  /**
   * Texte du champ de saisie pour une fiche : « NOM Prénom (#12) ».
   */
  public function libelle(array $agent): string {
    return trim($agent['nom'] . ' ' . $agent['prenom']) . ' (#' . $agent['id'] . ')';
  }

  /**
   * Vide le cache interne (après un import, dans les tests).
   */
  public function reinitialiser(): void {
    $this->agents = NULL;
  }

  /**
   * Toutes les fiches de l'annuaire, indexées par identifiant.
   *
   * @return array<int, array<string, string>>
   */
  private function agents(): array {
    if ($this->agents === NULL) {
      $this->agents = [];
      foreach ($this->entityTypeManager->getStorage('mersch_agent')->loadMultiple() as $entite) {
        $agent = ['id' => (string) $entite->id()];
        foreach (['nom', 'prenom', 'service', 'batiment', 'telephone', 'gsm', 'email'] as $champ) {
          $agent[$champ] = trim((string) $entite->get($champ)->value);
        }
        $this->agents[(int) $entite->id()] = $agent;
      }
    }
    return $this->agents;
  }

  /**
   * Clé de comparaison d'un nom : sans accents, ni majuscules, ni espaces
   * en trop.
   */
  private function cleNom(string $nom, string $prenom): string {
    return trim(preg_replace('/\s+/', ' ', $this->parser->cle($nom . ' ' . $prenom)));
  }

}
