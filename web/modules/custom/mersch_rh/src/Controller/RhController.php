<?php

declare(strict_types=1);

namespace Drupal\mersch_rh\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\mersch_rh\Organigramme;
use Drupal\node\NodeInterface;

/**
 * Page RH : organigramme (US-12), nouvelles (US-13), communications (US-14).
 */
class RhController extends ControllerBase {

  /**
   * Types de contenu de la rubrique RH, éditables par les rédacteurs RH.
   */
  public const TYPES = ['rh_nouvelle', 'rh_communication', 'rh_poste'];

  /**
   * Étiquette de cache vidée chaque jour par le cron (fin de publication).
   */
  public const CACHE_TAG_JOUR = 'mersch_rh_jour';

  /**
   * Nombre maximum d'éléments par liste sur la page.
   */
  private const LIMITE = 30;

  public function __construct(
    private readonly Organigramme $organigramme,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Page /rh.
   */
  public function page(): array {
    $cache = (new CacheableMetadata())
      ->addCacheTags([
        'node_list:rh_nouvelle',
        'node_list:rh_communication',
        'node_list:rh_poste',
        'taxonomy_term_list:rh_service',
        self::CACHE_TAG_JOUR,
      ])
      ->addCacheContexts(['languages:language_interface', 'languages:language_content', 'user.permissions']);

    $build = [
      '#theme' => 'mersch_rh',
      '#rubrique' => [
        'cle' => 'rh',
        'texte' => $this->t('Organisation chart, official communications and staff news.'),
      ],
      '#organigramme' => $this->organigramme($cache),
      '#nouvelles' => $this->nouvelles($cache),
      '#communications' => $this->communications($cache),
      '#attached' => ['library' => ['mersch_accueil/accueil', 'mersch_rh/rh']],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Résumé de l'organigramme : nombre de personnes, date, sections.
   */
  private function organigramme(CacheableMetadata $cache): array {
    $donnees = $this->organigramme->donnees($cache);
    $url = Url::fromRoute('mersch_rh.organigramme');
    return [
      'url' => $url->toString(),
      'personnes' => $donnees['personnes'],
      'date' => $donnees['date'] ? $this->dateFormatter->format($donnees['date'], 'custom', 'd.m.Y') : NULL,
      'sections' => array_map(fn (array $section) => [
        'nom' => $section['nom'],
        'url' => $url->setOption('fragment', $section['id'])->toString(),
      ], $donnees['sections']),
    ];
  }

  /**
   * Dernières nouvelles pour l'accueil (hook_mersch_accueil_nouvelles()).
   */
  public function dernieres(int $limite, CacheableMetadata $cache): array {
    $cache->addCacheTags(['node_list:rh_nouvelle', self::CACHE_TAG_JOUR]);
    $dernieres = [];
    foreach ($this->charger($this->idsNouvelles($limite)) as $node) {
      $cache->addCacheableDependency($node);
      $dernieres[] = [
        'titre' => $this->entityRepository->getTranslationFromContext($node)->label(),
        'created' => (int) $node->getCreatedTime(),
        'rubrique' => 'rh',
      ];
    }
    return $dernieres;
  }

  /**
   * Identifiants des nouvelles publiées et non échues, plus récentes d'abord.
   */
  private function idsNouvelles(int $limite): array {
    $aujourdhui = $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y-m-d');
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'rh_nouvelle')
      ->condition('status', NodeInterface::PUBLISHED);
    $fin = $query->orConditionGroup()
      ->notExists('field_rh_fin_publication')
      ->condition('field_rh_fin_publication', $aujourdhui, '>=');
    return $query->condition($fin)
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, $limite)
      ->execute();
  }

  /**
   * Nouvelles publiées et non échues, groupées par catégorie, plus récentes
   * d'abord.
   */
  private function nouvelles(CacheableMetadata $cache): array {
    $ids = $this->idsNouvelles(self::LIMITE);

    $groupes = [
      'arrivee' => [
        'titre' => $this->t('New faces'),
        'sous_titre' => $this->t('At the service of the departments and the citizens'),
        'elements' => [],
      ],
      'retraite' => [
        'titre' => $this->t('Thank you!'),
        'sous_titre' => $this->t('We wish you a happy retirement'),
        'elements' => [],
      ],
      'info' => [
        'titre' => $this->t('Staff news'),
        'sous_titre' => '',
        'elements' => [],
      ],
    ];
    foreach ($this->charger($ids) as $node) {
      $cache->addCacheableDependency($node);
      $traduction = $this->entityRepository->getTranslationFromContext($node);
      $categorie = $node->get('field_rh_categorie')->value;
      $photo = $node->get('field_rh_photo');
      $groupes[$categorie ?? 'info']['elements'][] = $this->element($node, $traduction) + [
        'service' => $traduction->get('field_rh_service')->value,
        'photo' => $photo->isEmpty() || !$photo->entity ? NULL : [
          '#theme' => 'image_style',
          '#style_name' => 'mersch_portrait',
          '#uri' => $photo->entity->getFileUri(),
          '#alt' => $photo->alt ?: $traduction->label(),
          '#attributes' => ['loading' => 'lazy'],
        ],
        'initiales' => self::initiales($traduction->label()),
      ];
    }
    return array_filter($groupes, fn (array $groupe) => $groupe['elements']);
  }

  /**
   * Communications RH publiées, plus récentes d'abord.
   */
  private function communications(CacheableMetadata $cache): array {
    $ids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'rh_communication')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, self::LIMITE)
      ->execute();

    $communications = [];
    foreach ($this->charger($ids) as $node) {
      $cache->addCacheableDependency($node);
      $traduction = $this->entityRepository->getTranslationFromContext($node);
      $pdf = $node->get('field_rh_pdf')->entity;
      $communications[] = $this->element($node, $traduction) + [
        'pdf' => $pdf ? [
          'url' => $pdf->createFileUrl(),
          'taille' => ByteSizeMarkup::create((int) $pdf->getSize()),
        ] : NULL,
      ];
    }
    return $communications;
  }

  /**
   * Champs communs aux nouvelles et aux communications.
   */
  private function element(NodeInterface $node, NodeInterface $traduction): array {
    $langue = $this->languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $texte = $traduction->get('field_rh_texte');
    return [
      'titre' => $traduction->label(),
      'date' => $this->dateFormatter->format($node->getCreatedTime(), 'custom', 'd.m.Y'),
      'texte' => $texte->isEmpty() ? NULL : [
        '#type' => 'processed_text',
        '#text' => $texte->value,
        '#format' => $texte->format,
      ],
      // US-02 : version LU affichée faute de traduction.
      'non_traduit' => !$node->hasTranslation($langue),
      'modifier' => $node->access('update') ? $node->toUrl('edit-form')->toString() : NULL,
    ];
  }

  /**
   * Charge des contenus dans l'ordre des identifiants.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function charger(array $ids): array {
    return $ids ? array_values($this->entityTypeManager()->getStorage('node')->loadMultiple($ids)) : [];
  }

  /**
   * Initiales affichées à la place d'une photo (« Luc REISCH » → « LR »).
   */
  public static function initiales(string $nom): string {
    $mots = preg_split('/[\s\-]+/u', trim($nom), -1, PREG_SPLIT_NO_EMPTY);
    $lettres = array_map(fn (string $mot) => mb_strtoupper(mb_substr($mot, 0, 1)), array_slice($mots, 0, 2));
    return implode('', $lettres);
  }

}
