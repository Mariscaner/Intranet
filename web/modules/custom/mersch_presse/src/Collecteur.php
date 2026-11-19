<?php

declare(strict_types=1);

namespace Drupal\mersch_presse;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\mersch_presse\Entity\Article;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Collecte les articles des flux configurés (US-39, D-045).
 */
class Collecteur {

  /**
   * Intervalle entre deux collectes : 2 h, moins une marge pour le cron
   * horaire du serveur (drush cron à la 17e minute).
   */
  public const INTERVALLE = 2 * 3600 - 600;

  /**
   * Heures de collecte : de 6 h à 20 h (heure du serveur).
   */
  public const PREMIERE_HEURE = 6;
  public const DERNIERE_HEURE = 20;

  /**
   * Clé d'état : heure de la dernière collecte.
   */
  private const ETAT = 'mersch_presse.derniere_collecte';

  /**
   * Étiquette de cache du bloc de l'accueil.
   */
  public const CACHE_ACCUEIL = 'mersch_presse_accueil';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Appelé par hook_cron() : collecte si c'est l'heure, purge, et rafraîchit
   * le bloc de l'accueil (les articles de plus de 24 h en sortent).
   */
  public function cron(): void {
    $maintenant = $this->time->getRequestTime();
    $heure = (int) date('G', $maintenant);
    $derniere = (int) $this->state->get(self::ETAT, 0);
    if ($heure >= self::PREMIERE_HEURE && $heure <= self::DERNIERE_HEURE && $maintenant - $derniere >= self::INTERVALLE) {
      $this->collecter();
    }
    $this->purger();
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_ACCUEIL]);
  }

  /**
   * Lit chaque flux actif et enregistre les nouveaux articles sur Mersch.
   *
   * @return array
   *   Bilan : { nouveaux, erreurs: [nom de la source => message] }.
   */
  public function collecter(): array {
    $maintenant = $this->time->getCurrentTime();
    $this->state->set(self::ETAT, $maintenant);
    $config = $this->configFactory->get('mersch_presse.settings');
    $motsCles = $config->get('mots_cles') ?? [];
    $bilan = ['nouveaux' => 0, 'erreurs' => []];

    foreach ($config->get('sources') ?? [] as $source) {
      if (empty($source['actif']) || empty($source['url'])) {
        continue;
      }
      try {
        $reponse = $this->httpClient->request('GET', $source['url'], [
          'timeout' => 20,
          'headers' => [
            'User-Agent' => 'Intranet-Mersch-Revue-de-presse/1.0',
            'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml',
          ],
        ]);
        $articles = Analyseur::articles((string) $reponse->getBody(), $source['nom'], $maintenant, Article::EXTRAIT);
      }
      catch (\Throwable $e) {
        // Un flux en panne n'empêche pas de lire les autres (US-39).
        $bilan['erreurs'][$source['nom']] = $e->getMessage();
        $this->logger->warning('Flux @nom (@url) illisible : @message', [
          '@nom' => $source['nom'],
          '@url' => $source['url'],
          '@message' => $e->getMessage(),
        ]);
        continue;
      }
      // La recherche Google Actualités filtre déjà sur Mersch.
      $google = parse_url($source['url'], PHP_URL_HOST) === 'news.google.com';
      foreach ($articles as $article) {
        if (($google || Analyseur::concerne($article, $motsCles)) && $this->enregistrer($article)) {
          $bilan['nouveaux']++;
        }
      }
    }

    if ($bilan['nouveaux']) {
      $this->logger->info('@nombre nouveaux articles de presse.', ['@nombre' => $bilan['nouveaux']]);
    }
    return $bilan;
  }

  /**
   * Enregistre un article s'il n'est pas déjà connu (même lien ou même titre).
   */
  private function enregistrer(array $article): bool {
    $stockage = $this->entityTypeManager->getStorage('mersch_presse_article');
    $cleLien = sha1($article['lien']);
    $cleTitre = Analyseur::cleTitre($article['titre']);
    $requete = $stockage->getQuery()->accessCheck(FALSE)->range(0, 1);
    $ou = $requete->orConditionGroup()
      ->condition('cle_lien', $cleLien)
      ->condition('cle_titre', $cleTitre);
    if ($requete->condition($ou)->execute()) {
      return FALSE;
    }
    $stockage->create([
      'titre' => mb_substr($article['titre'], 0, 512),
      'media' => mb_substr($article['media'], 0, 128),
      'extrait' => $article['extrait'],
      'lien' => $article['lien'],
      'date' => $article['date'],
      'cle_lien' => $cleLien,
      'cle_titre' => $cleTitre,
    ])->save();
    return TRUE;
  }

  /**
   * Supprime les articles de plus de 3 ans (D-045).
   */
  public function purger(): void {
    $stockage = $this->entityTypeManager->getStorage('mersch_presse_article');
    $limite = $this->time->getRequestTime() - Article::CONSERVATION;
    $ids = $stockage->getQuery()->accessCheck(FALSE)->condition('date', $limite, '<')->range(0, 200)->execute();
    if ($ids) {
      $stockage->delete($stockage->loadMultiple($ids));
    }
  }

  /**
   * Heure de la dernière collecte, ou NULL.
   */
  public function derniereCollecte(): ?int {
    return $this->state->get(self::ETAT) ?: NULL;
  }

}
