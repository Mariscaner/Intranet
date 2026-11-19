<?php

declare(strict_types=1);

namespace Drupal\mersch_presse\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\mersch_presse\Collecteur;
use Drupal\mersch_presse\Entity\Article;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Archive de la revue de presse (US-41) et masquage d'un article (US-42).
 */
class PresseController extends ControllerBase {

  /**
   * Articles par page de l'archive.
   */
  private const PAR_PAGE = 25;

  /**
   * Articles au plus dans le bloc de l'accueil.
   */
  public const ACCUEIL = 5;

  /**
   * Fenêtre du bloc de l'accueil : 24 h (D-046).
   */
  public const FENETRE = 86400;

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Bloc « Mersch dans la presse » de l'accueil (US-40).
   */
  public function blocAccueil(): array {
    $limite = \Drupal::time()->getRequestTime() - self::FENETRE;
    $ids = $this->requete()
      ->condition('date', $limite, '>=')
      ->sort('date', 'DESC')
      ->range(0, self::ACCUEIL)
      ->execute();
    return [
      '#theme' => 'mersch_presse_accueil',
      '#articles' => $this->lignes($ids, FALSE),
      '#archive' => Url::fromRoute('mersch_presse.archive')->toString(),
      '#attached' => ['library' => ['mersch_presse/presse']],
      '#cache' => [
        // Rafraîchi à chaque cron (sortie des articles de plus de 24 h).
        'tags' => ['mersch_presse_article_list', Collecteur::CACHE_ACCUEIL],
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * Archive cherchable par texte, média et période (US-41).
   */
  public function archive(Request $request): array {
    $moderateur = $this->currentUser()->hasPermission('hide mersch presse articles');
    $filtres = [
      'q' => trim((string) $request->query->get('q', '')),
      'media' => (string) $request->query->get('media', ''),
      'du' => $this->dateValide((string) $request->query->get('du', '')),
      'au' => $this->dateValide((string) $request->query->get('au', '')),
      'masques' => $moderateur && $request->query->get('masques') === '1',
    ];

    $requete = $this->requete($filtres['masques'])->sort('date', 'DESC')->pager(self::PAR_PAGE);
    foreach (preg_split('/\s+/u', $filtres['q'], -1, PREG_SPLIT_NO_EMPTY) as $mot) {
      $ou = $requete->orConditionGroup()
        ->condition('titre', $mot, 'CONTAINS')
        ->condition('extrait', $mot, 'CONTAINS')
        ->condition('media', $mot, 'CONTAINS');
      $requete->condition($ou);
    }
    if ($filtres['media'] !== '') {
      $requete->condition('media', $filtres['media']);
    }
    if ($filtres['du']) {
      $requete->condition('date', strtotime($filtres['du'] . ' 00:00:00'), '>=');
    }
    if ($filtres['au']) {
      $requete->condition('date', strtotime($filtres['au'] . ' 23:59:59'), '<=');
    }

    return [
      '#theme' => 'mersch_presse_archive',
      '#articles' => $this->lignes($requete->execute(), $moderateur),
      '#filtres' => $filtres,
      '#medias' => $this->medias(),
      '#moderateur' => $moderateur,
      '#action' => Url::fromRoute('mersch_presse.archive')->toString(),
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['mersch_presse/presse']],
      '#cache' => [
        'tags' => ['mersch_presse_article_list'],
        'contexts' => ['url.query_args', 'user.permissions', 'languages:language_interface'],
      ],
    ];
  }

  /**
   * Masque un article ou l'affiche de nouveau (US-42).
   */
  public function masquer(Article $mersch_presse_article, bool $masque): RedirectResponse {
    $mersch_presse_article->set('masque', $masque)->save();
    $this->messenger()->addStatus($masque
      ? $this->t('The article “@titre” is hidden.', ['@titre' => $mersch_presse_article->label()])
      : $this->t('The article “@titre” is shown again.', ['@titre' => $mersch_presse_article->label()]));
    // Drupal suit le paramètre « destination » s'il reste sur le site.
    return $this->redirect('mersch_presse.archive');
  }

  /**
   * Requête de base : articles visibles, ou tous pour un modérateur.
   */
  private function requete(bool $avecMasques = FALSE) {
    $requete = $this->entityTypeManager()->getStorage('mersch_presse_article')->getQuery()->accessCheck(FALSE);
    if (!$avecMasques) {
      $requete->condition('masque', 0);
    }
    return $requete;
  }

  /**
   * Lignes à afficher : { titre, lien, media, date, extrait, masque, action }.
   */
  private function lignes(array $ids, bool $moderateur): array {
    $lignes = [];
    $articles = $this->entityTypeManager()->getStorage('mersch_presse_article')->loadMultiple($ids);
    $destination = Url::fromRoute('<current>', [], ['query' => \Drupal::request()->query->all()])->toString();
    foreach ($ids as $id) {
      if (!isset($articles[$id])) {
        continue;
      }
      /** @var \Drupal\mersch_presse\Entity\Article $article */
      $article = $articles[$id];
      $ligne = [
        'titre' => $article->label(),
        'lien' => $article->get('lien')->value,
        'media' => $article->get('media')->value,
        'date' => $this->dateFormatter->format((int) $article->get('date')->value, 'custom', 'd.m.Y'),
        'extrait' => $article->get('extrait')->value,
        'masque' => $article->estMasque(),
        'action' => NULL,
      ];
      if ($moderateur) {
        $route = $article->estMasque() ? 'mersch_presse.afficher' : 'mersch_presse.masquer';
        $ligne['action'] = [
          'titre' => $article->estMasque() ? $this->t('Show again') : $this->t('Hide'),
          'url' => Url::fromRoute($route, ['mersch_presse_article' => $id], ['query' => ['destination' => $destination]])->toString(),
        ];
      }
      $lignes[] = $ligne;
    }
    return $lignes;
  }

  /**
   * Médias présents dans l'archive, par ordre alphabétique.
   */
  private function medias(): array {
    $resultats = $this->entityTypeManager()->getStorage('mersch_presse_article')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('masque', 0)
      ->groupBy('media')
      ->sort('media')
      ->execute();
    return array_column($resultats, 'media');
  }

  /**
   * Date AAAA-MM-JJ valide, sinon chaîne vide.
   */
  private function dateValide(string $date): string {
    $d = \DateTime::createFromFormat('!Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $date : '';
  }

}
