<?php

declare(strict_types=1);

namespace Drupal\mersch_annuaire;

use Drupal\Component\Transliteration\TransliterationInterface;

/**
 * Transforme les lignes de la liste Excel du personnel en fiches d'agents.
 *
 * Classe sans dépendance à la base : elle ne fait que lire et nettoyer.
 * Règles (D-026) : une ligne est retenue si elle a un nom au format
 * « NOM Prénom », un service, et ne figure pas dans la liste d'exclusion.
 */
class ListeParser {

  /**
   * En-têtes reconnus pour chaque champ, après normalisation.
   */
  private const ENTETES = [
    'nom' => ['nomprenom', 'nometprenom', 'prenomnom', 'nom', 'agent'],
    'telephone' => ['tel', 'telephone', 'telfixe', 'fixe'],
    'gsm' => ['gsm', 'mobile', 'portable', 'natel'],
    'email' => ['mail', 'email', 'courriel'],
    'email_service' => ['mailservice', 'emailservice', 'mailduservice'],
    'service' => ['service'],
    'batiment' => ['batiment', 'bureau', 'site'],
    'remarque' => ['remarque', 'remarques'],
  ];

  /**
   * Nombre de lignes parcourues pour trouver la ligne d'en-tête.
   */
  private const LIGNES_AVANT_ENTETE = 10;

  public function __construct(
    private readonly TransliterationInterface $transliteration,
  ) {}

  /**
   * Analyse les lignes du fichier.
   *
   * @param array<int, array<int, mixed>> $lignes
   *   Lignes du fichier, en-tête compris, cellule par cellule.
   * @param string[] $exclusions
   *   Valeurs de la colonne Nom/Prénom à ignorer.
   * @param array<string, string> $sigles
   *   Noms complets des sigles de service (« ST » => « Service technique »).
   *
   * @return array{agents: list<array<string, string>>, ignores: list<array{ligne: int, texte: string, motif: string}>, avertissements: list<array<string, mixed>>}
   *   Les fiches retenues (avec leur numéro de ligne dans le fichier), les
   *   lignes ignorées avec leur motif, et les avertissements à relire.
   *
   * @throws \InvalidArgumentException
   *   Si la ligne d'en-tête est introuvable.
   */
  public function analyser(array $lignes, array $exclusions = [], array $sigles = []): array {
    $lignes = array_values($lignes);
    [$index_entete, $colonnes] = $this->trouverEntete($lignes);
    $exclus = array_flip(array_map($this->cle(...), $exclusions));

    $agents = [];
    $ignores = [];
    $avertissements = [];
    $deja_vus = [];

    foreach (array_slice($lignes, $index_entete + 1, NULL, TRUE) as $index => $cellules) {
      $numero = $index + 1;
      $valeurs = [];
      foreach ($colonnes as $champ => $colonne) {
        $valeurs[$champ] = $this->texte($cellules[$colonne] ?? '');
      }
      if (implode('', $valeurs) === '') {
        continue;
      }
      $texte = $valeurs['nom'] !== '' ? $valeurs['nom'] : implode(' ', array_filter($valeurs));

      if ($valeurs['nom'] !== '' && isset($exclus[$this->cle($valeurs['nom'])])) {
        $ignores[] = ['ligne' => $numero, 'texte' => $texte, 'motif' => 'exclu'];
        continue;
      }
      if ($valeurs['service'] === '') {
        $ignores[] = ['ligne' => $numero, 'texte' => $texte, 'motif' => 'service_manquant'];
        continue;
      }
      $nom_prenom = $this->separerNom($valeurs['nom']);
      if ($nom_prenom === NULL) {
        $ignores[] = ['ligne' => $numero, 'texte' => $texte, 'motif' => 'nom_non_reconnu'];
        continue;
      }

      $agent = [
        'ligne' => (string) $numero,
        'nom' => $nom_prenom[0],
        'prenom' => $nom_prenom[1],
        'service' => $this->nettoyerService($valeurs['service'], $sigles),
        'batiment' => $valeurs['batiment'],
        'telephone' => $this->formaterTelephone($valeurs['telephone']),
        'gsm' => $this->formaterTelephone($valeurs['gsm']),
        'email' => '',
        'email_service' => '',
        'remarque' => $valeurs['remarque'],
      ];
      foreach (['email', 'email_service'] as $champ) {
        $adresse = mb_strtolower($valeurs[$champ]);
        if ($adresse === '') {
          continue;
        }
        if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === FALSE) {
          $avertissements[] = ['type' => 'email_invalide', 'ligne' => $numero, 'valeur' => $adresse];
          continue;
        }
        $agent[$champ] = $adresse;
      }

      $doublon = $this->cle($agent['nom'] . ' ' . $agent['prenom'] . ' ' . $agent['service']);
      if (isset($deja_vus[$doublon])) {
        $ignores[] = ['ligne' => $numero, 'texte' => $texte, 'motif' => 'doublon'];
        continue;
      }
      $deja_vus[$doublon] = TRUE;
      $agents[] = $agent;
    }

    $avertissements = array_merge($avertissements, $this->unifierServices($agents));
    return ['agents' => $agents, 'ignores' => $ignores, 'avertissements' => $avertissements];
  }

  /**
   * Clé de comparaison : minuscules, sans accents ni espaces superflus.
   */
  public function cle(string $texte): string {
    $texte = $this->transliteration->transliterate($this->texte($texte), 'fr');
    return mb_strtolower($texte);
  }

  /**
   * Trouve la ligne d'en-tête et la colonne de chaque champ.
   *
   * @return array{0: int, 1: array<string, int>}
   *   L'index de la ligne d'en-tête et la colonne de chaque champ.
   */
  private function trouverEntete(array $lignes): array {
    foreach (array_slice($lignes, 0, self::LIGNES_AVANT_ENTETE) as $index => $cellules) {
      $colonnes = [];
      $precedent = NULL;
      foreach (array_values($cellules) as $colonne => $cellule) {
        $entete = preg_replace('/[^a-z]/', '', $this->cle((string) $cellule));
        $champ = NULL;
        foreach (self::ENTETES as $candidat => $alias) {
          if (in_array($entete, $alias, TRUE)) {
            $champ = $candidat;
            break;
          }
        }
        // Dans la liste du personnel, la colonne « mail service » suit la
        // colonne Mail et n'a pas d'en-tête.
        if ($champ === NULL && $entete === '' && $precedent === 'email') {
          $champ = 'email_service';
        }
        if ($champ !== NULL && !isset($colonnes[$champ])) {
          $colonnes[$champ] = $colonne;
        }
        $precedent = $champ;
      }
      if (isset($colonnes['nom'], $colonnes['service'])) {
        return [$index, $colonnes + array_fill_keys(array_keys(self::ENTETES), PHP_INT_MAX)];
      }
    }
    throw new \InvalidArgumentException('En-tête introuvable : les colonnes « Nom/Prénom » et « Service » sont obligatoires.');
  }

  /**
   * Sépare « NOM Prénom » : le nom est la suite de mots en majuscules.
   *
   * @return array{0: string, 1: string}|null
   *   Le nom et le prénom, ou NULL si la cellule n'a pas ce format.
   */
  private function separerNom(string $texte): ?array {
    $mots = explode(' ', $texte);
    $nom = [];
    foreach ($mots as $mot) {
      if (!preg_match('/\p{L}/u', $mot) || $mot !== mb_strtoupper($mot)) {
        break;
      }
      $nom[] = $mot;
    }
    // Tout en majuscules (« DUPONT JEAN ») : le dernier mot est le prénom.
    if (count($nom) === count($mots) && count($mots) > 1) {
      array_pop($nom);
    }
    $prenom = implode(' ', array_slice($mots, count($nom)));
    if ($nom === [] || $prenom === '') {
      return NULL;
    }
    // Prénom saisi tout en majuscules ou en minuscules : on le met en forme.
    if ($prenom === mb_strtoupper($prenom) || $prenom === mb_strtolower($prenom)) {
      $prenom = mb_convert_case(mb_strtolower($prenom), MB_CASE_TITLE);
    }
    return [implode(' ', $nom), $prenom];
  }

  /**
   * Nettoie un service : espaces et séparateur « - » homogènes, majuscule
   * en tête de chaque partie, sigle de tête remplacé par son nom complet
   * (« ST - chef » devient « Service technique - Chef »).
   */
  private function nettoyerService(string $service, array $sigles): string {
    $parties = explode(' - ', trim(preg_replace('/\s*-\s*/u', ' - ', $service), ' -'));
    $parties = array_map(fn (string $partie) => mb_strtoupper(mb_substr($partie, 0, 1)) . mb_substr($partie, 1), $parties);
    foreach ($sigles as $sigle => $nom) {
      if (mb_strtoupper($parties[0]) === mb_strtoupper($sigle)) {
        $parties[0] = $nom;
        break;
      }
    }
    return implode(' - ', $parties);
  }

  /**
   * Met en forme un numéro : un GSM à 9 chiffres devient « 621 123 456 ».
   */
  private function formaterTelephone(string $numero): string {
    $chiffres = preg_replace('/\D/', '', $numero);
    if (strlen($chiffres) === 9 && $chiffres[0] === '6' && !str_starts_with($numero, '+')) {
      return implode(' ', str_split($chiffres, 3));
    }
    return $numero;
  }

  /**
   * Regroupe les variantes d'un même service et signale les services proches.
   *
   * « ST - Secretariat » et « ST - Secrétariat » deviennent un seul service ;
   * on garde l'écriture avec majuscules et accents. Deux services presque
   * identiques (faute de frappe) sont seulement signalés.
   *
   * @return list<array<string, mixed>>
   *   Les avertissements à afficher.
   */
  private function unifierServices(array &$agents): array {
    $variantes = [];
    foreach ($agents as $agent) {
      $variantes[$this->cle($agent['service'])][$agent['service']] = ($variantes[$this->cle($agent['service'])][$agent['service']] ?? 0) + 1;
    }

    $retenus = [];
    $avertissements = [];
    foreach ($variantes as $cle => $ecritures) {
      uksort($ecritures, fn (string $a, string $b) => $this->score($b, $ecritures[$b]) <=> $this->score($a, $ecritures[$a]));
      $retenus[$cle] = (string) array_key_first($ecritures);
      if (count($ecritures) > 1) {
        $avertissements[] = ['type' => 'variantes_service', 'retenu' => $retenus[$cle], 'variantes' => array_keys($ecritures)];
      }
    }
    foreach ($agents as &$agent) {
      $agent['service'] = $retenus[$this->cle($agent['service'])];
    }
    unset($agent);

    $cles = array_keys($retenus);
    sort($cles);
    foreach ($cles as $i => $a) {
      foreach (array_slice($cles, $i + 1) as $b) {
        // Même préfixe (« SR », « ST »…) et au plus 3 lettres d'écart.
        if (strtok($a, ' ') === strtok($b, ' ') && min(strlen($a), strlen($b)) > 8 && levenshtein($a, $b) <= 3) {
          $avertissements[] = ['type' => 'services_proches', 'valeurs' => [$retenus[$a], $retenus[$b]]];
        }
      }
    }
    return $avertissements;
  }

  /**
   * Préférence d'écriture : majuscule en tête de chaque partie, puis accents,
   * puis fréquence.
   */
  private function score(string $ecriture, int $frequence): array {
    $majuscules = 0;
    foreach (explode(' - ', $ecriture) as $partie) {
      $premiere = mb_substr($partie, 0, 1);
      $majuscules += (int) ($premiere !== mb_strtolower($premiere));
    }
    $accents = strlen($ecriture) - strlen($this->transliteration->transliterate($ecriture, 'fr'));
    return [$majuscules, $accents !== 0 ? 1 : 0, $frequence];
  }

  /**
   * Cellule en texte sur une ligne, espaces réduits.
   */
  private function texte(mixed $cellule): string {
    if ($cellule === NULL) {
      return '';
    }
    if (is_float($cellule) && floor($cellule) === $cellule) {
      $cellule = (int) $cellule;
    }
    return trim(preg_replace('/\s+/u', ' ', (string) $cellule));
  }

}
