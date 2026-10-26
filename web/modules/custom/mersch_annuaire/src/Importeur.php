<?php

declare(strict_types=1);

namespace Drupal\mersch_annuaire;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Importe la liste Excel du personnel dans l'annuaire (D-026).
 *
 * La liste importée remplace entièrement la précédente. Le fichier lui-même
 * n'est jamais conservé : seules les fiches retenues sont enregistrées.
 */
class Importeur {

  use StringTranslationTrait;

  /**
   * Clé d'état : date, auteur et volume du dernier import.
   */
  public const ETAT = 'mersch_annuaire.dernier_import';

  public function __construct(
    private readonly LecteurFichier $lecteur,
    private readonly ListeParser $parser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Lit et analyse un fichier sans rien enregistrer.
   *
   * @param string $chemin
   *   Chemin du fichier sur le disque.
   * @param string $nom
   *   Nom d'origine du fichier.
   * @param string[]|null $exclusions
   *   Liste d'exclusion à appliquer ; par défaut celle de la configuration.
   * @param array<string, string>|null $sigles
   *   Noms complets des sigles de service ; par défaut ceux de la configuration.
   *
   * @return array
   *   Le résultat de ListeParser::analyser().
   *
   * @throws \InvalidArgumentException
   *   Si le fichier est illisible ou sans en-tête reconnu.
   */
  public function analyser(string $chemin, string $nom, ?array $exclusions = NULL, ?array $sigles = NULL): array {
    $config = $this->configFactory->get('mersch_annuaire.settings');
    $exclusions ??= $config->get('exclusions') ?? [];
    $sigles ??= array_column($config->get('sigles') ?? [], 'nom', 'sigle');
    return $this->parser->analyser($this->lecteur->lire($chemin, $nom), $exclusions, $sigles);
  }

  /**
   * Remplace l'annuaire par les fiches analysées.
   *
   * @param array $resultat
   *   Le résultat de analyser().
   * @param string $fichier
   *   Nom d'origine du fichier, gardé pour l'historique des imports.
   *
   * @return int
   *   Le nombre de fiches enregistrées.
   *
   * @throws \InvalidArgumentException
   *   Si aucune fiche n'est valide : l'annuaire actuel est alors conservé.
   */
  public function importer(array $resultat, string $fichier): int {
    if ($resultat['agents'] === []) {
      throw new \InvalidArgumentException('Aucune ligne valide : l\'annuaire actuel est conservé.');
    }
    $stockage = $this->entityTypeManager->getStorage('mersch_agent');
    $transaction = $this->database->startTransaction();
    try {
      $stockage->delete($stockage->loadMultiple());
      foreach ($resultat['agents'] as $agent) {
        unset($agent['ligne']);
        $stockage->create($agent)->save();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    $this->state->set(self::ETAT, [
      'date' => $this->time->getRequestTime(),
      'uid' => (int) $this->currentUser->id(),
      'fichier' => $fichier,
      'agents' => count($resultat['agents']),
      'ignores' => count($resultat['ignores']),
    ]);
    return count($resultat['agents']);
  }

  /**
   * Compte rendu lisible des lignes ignorées et des avertissements.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Une phrase par ligne ignorée ou par avertissement.
   */
  public function compteRendu(array $resultat): array {
    $motifs = [
      'exclu' => $this->t('in the exclusion list'),
      'service_manquant' => $this->t('no department'),
      'nom_non_reconnu' => $this->t('name not in the "LASTNAME First name" format'),
      'doublon' => $this->t('duplicate'),
    ];
    $phrases = [];
    foreach ($resultat['ignores'] as $ignore) {
      $phrases[] = $this->t('Line @ligne ignored (@motif): @texte', [
        '@ligne' => $ignore['ligne'],
        '@motif' => $motifs[$ignore['motif']],
        '@texte' => $ignore['texte'],
      ]);
    }
    foreach ($resultat['avertissements'] as $avertissement) {
      $phrases[] = match ($avertissement['type']) {
        'email_invalide' => $this->t('Line @ligne: invalid e-mail address "@valeur" left out.', [
          '@ligne' => $avertissement['ligne'],
          '@valeur' => $avertissement['valeur'],
        ]),
        'variantes_service' => $this->t('Department spelled several ways (@variantes), shown as "@retenu".', [
          '@variantes' => implode(' / ', $avertissement['variantes']),
          '@retenu' => $avertissement['retenu'],
        ]),
        'services_proches' => $this->t('Departments "@a" and "@b" look alike: check the spelling in the Excel list.', [
          '@a' => $avertissement['valeurs'][0],
          '@b' => $avertissement['valeurs'][1],
        ]),
      };
    }
    return $phrases;
  }

  /**
   * Résumé en une phrase.
   */
  public function resume(array $resultat): TranslatableMarkup {
    return $this->t('@agents staff members found, @ignores lines ignored, @avertissements warnings.', [
      '@agents' => count($resultat['agents']),
      '@ignores' => count($resultat['ignores']),
      '@avertissements' => count($resultat['avertissements']),
    ]);
  }

}
