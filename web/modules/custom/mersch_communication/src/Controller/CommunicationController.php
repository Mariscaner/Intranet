<?php

declare(strict_types=1);

namespace Drupal\mersch_communication\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\node\NodeInterface;

/**
 * Page Communication : Nik (US-15), agenda (US-16), albums photos (US-17)
 * et nouvelles (US-18).
 */
class CommunicationController extends ControllerBase {

  /**
   * Type de contenu des nouvelles, seul contenu des rédacteurs Communication.
   */
  public const TYPE = 'comm_nouvelle';

  /**
   * Étiquette de cache vidée chaque jour par le cron (fin de publication).
   */
  public const CACHE_TAG_JOUR = 'mersch_communication_jour';

  /**
   * Nombre maximum de nouvelles sur la page.
   */
  private const LIMITE = 30;

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Page /communication.
   */
  public function page(): array {
    $config = $this->config('mersch_communication.settings');
    $cache = (new CacheableMetadata())
      ->addCacheableDependency($config)
      ->addCacheTags(['node_list:' . self::TYPE, self::CACHE_TAG_JOUR])
      ->addCacheContexts(['languages:language_interface', 'languages:language_content', 'user.permissions']);

    $build = [
      '#theme' => 'mersch_communication',
      '#rubrique' => [
        'cle' => 'communication',
        'texte' => $this->t('The Gemengebuet, the events calendar and the photos are on mersch.lu. Here you will find the links and the internal news.'),
      ],
      '#liens' => $this->liens($config->get()),
      '#nouvelles' => $this->nouvelles($cache),
      '#attached' => ['library' => ['mersch_accueil/accueil', 'mersch_communication/communication']],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Les trois liens vers mersch.lu, dans l'ordre de la maquette (D-012).
   */
  private function liens(array $urls): array {
    $liens = [
      'nik' => [
        // Nom propre du Gemengebuet, invariable (glossaire).
        'titre' => 'Nik',
        'texte' => $this->t('All issues of the Gemengebuet.'),
      ],
      'agenda' => [
        'titre' => $this->t('Upcoming events'),
        'texte' => $this->t('The events calendar of the commune.'),
      ],
      'albums' => [
        'titre' => $this->t('Photo albums'),
        'texte' => $this->t('Photos of the commune events.'),
      ],
    ];
    foreach ($liens as $cle => &$lien) {
      $lien['url'] = $urls[$cle] ?? '';
      $lien['adresse'] = self::adresseCourte($lien['url']);
    }
    return array_filter($liens, fn (array $lien) => $lien['url'] !== '');
  }

  /**
   * Nouvelles publiées et non échues, plus récentes d'abord.
   */
  private function nouvelles(CacheableMetadata $cache): array {
    $aujourdhui = $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y-m-d');
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', self::TYPE)
      ->condition('status', NodeInterface::PUBLISHED);
    $fin = $query->orConditionGroup()
      ->notExists('field_comm_fin_publication')
      ->condition('field_comm_fin_publication', $aujourdhui, '>=');
    $ids = $query->condition($fin)
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, self::LIMITE)
      ->execute();

    $langue = $this->languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $nouvelles = [];
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $node) {
      $cache->addCacheableDependency($node);
      $traduction = $this->entityRepository->getTranslationFromContext($node);
      $texte = $traduction->get('field_comm_texte');
      $photo = $node->get('field_comm_photo');
      $nouvelles[] = [
        'titre' => $traduction->label(),
        'date' => $this->dateFormatter->format($node->getCreatedTime(), 'custom', 'd.m.Y'),
        'texte' => $texte->isEmpty() ? NULL : [
          '#type' => 'processed_text',
          '#text' => $texte->value,
          '#format' => $texte->format,
        ],
        'photo' => $photo->isEmpty() || !$photo->entity ? NULL : [
          '#theme' => 'image_style',
          '#style_name' => 'mersch_nouvelle',
          '#uri' => $photo->entity->getFileUri(),
          '#alt' => $photo->alt ?: '',
          '#attributes' => ['loading' => 'lazy'],
        ],
        // US-02 : version LU affichée faute de traduction.
        'non_traduit' => !$node->hasTranslation($langue),
        'modifier' => $node->access('update') ? $node->toUrl('edit-form')->toString() : NULL,
      ];
    }
    return $nouvelles;
  }

  /**
   * Adresse affichée sous un lien : domaine et chemin, sans paramètres
   * (« mersch.lu/mediatheque »).
   */
  public static function adresseCourte(string $url): string {
    $parties = parse_url($url);
    if (empty($parties['host'])) {
      return $url;
    }
    $hote = preg_replace('/^www\./', '', $parties['host']);
    return rtrim($hote . ($parties['path'] ?? ''), '/');
  }

}
