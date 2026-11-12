<?php

declare(strict_types=1);

namespace Drupal\mersch_services\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\mersch_services\Contacts;
use Drupal\mersch_services\Rubriques;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Liste des services (US-22), bas de page liens et contacts (US-23, US-24),
 * tableau d'administration (US-25, US-26) et recherche dans l'annuaire.
 */
class ServicesController extends ControllerBase {

  public function __construct(
    private readonly Rubriques $rubriques,
    private readonly Contacts $contacts,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Page /services : services publiés, dans l'ordre fixé par l'admin.
   *
   * Un service non publié n'est visible que de ses rédacteurs et des
   * admins, pour le préparer (US-25).
   */
  public function liste(): array {
    $cache = (new CacheableMetadata())
      ->addCacheTags(['node_list:' . Rubriques::TYPE])
      ->addCacheContexts(['languages:language_interface', 'languages:language_content', 'user']);

    $services = [];
    foreach ($this->services() as $node) {
      $cache->addCacheableDependency($node);
      if (!$node->isPublished() && !$this->rubriques->estRedacteur($node, $this->currentUser())) {
        continue;
      }
      $traduction = $this->entityRepository->getTranslationFromContext($node);
      $texte = trim(strip_tags((string) $traduction->get('field_service_texte')->value));
      $services[] = [
        'titre' => $traduction->label(),
        'url' => $this->url($node)->toString(),
        'resume' => $texte !== '' ? Unicode::truncate(html_entity_decode($texte), 160, TRUE, TRUE) : '',
        'rubrique' => str_replace('_', '-', $this->rubriques->rubrique($node) ?? 'service'),
        'non_publie' => !$node->isPublished(),
      ];
    }

    $build = [
      '#theme' => 'mersch_services',
      '#services' => $services,
      '#attached' => ['library' => ['mersch_accueil/accueil', 'mersch_services/services']],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Bas de page d'un service : liens puis contacts (US-22 à US-24).
   */
  public function pied(NodeInterface $service): array {
    $cache = (new CacheableMetadata())
      ->addCacheableDependency($service)
      ->addCacheTags(['mersch_agent_list'])
      ->addCacheContexts(['languages:language_content', 'user']);
    $traduction = $this->entityRepository->getTranslationFromContext($service);

    $liens = [];
    foreach ($traduction->get('field_service_liens') as $item) {
      $url = $item->getUrl();
      $liens[] = [
        'titre' => $item->title ?: $url->toString(),
        'url' => $url->toString(),
        'externe' => $url->isExternal(),
      ];
    }

    $contacts = [];
    foreach ($service->get('field_service_contacts') as $item) {
      $agent = $this->contacts->trouver($item->getValue());
      if (!$agent) {
        // Agent retiré de la liste Excel : plus affiché (US-28).
        continue;
      }
      $contacts[] = [
        'nom' => $agent['nom'],
        'prenom' => $agent['prenom'],
        'role' => (string) $item->role,
        'service' => $agent['service'],
        'batiment' => $agent['batiment'],
        'telephone' => $agent['telephone'],
        'tel_lien' => self::lienTelephone($agent['telephone']),
        'gsm' => $agent['gsm'],
        'gsm_lien' => self::lienTelephone($agent['gsm']),
        'email' => $agent['email'],
      ];
    }

    $telephone = trim((string) $service->get('field_service_telephone')->value);
    $general = [
      'email' => (string) $service->get('field_service_email')->value,
      'telephone' => $telephone,
      'tel_lien' => self::lienTelephone($telephone),
    ];

    $modifier = NULL;
    if ($service->access('update')) {
      $modifier = $traduction->toUrl('edit-form')->toString();
    }

    $build = [
      '#theme' => 'mersch_service_pied',
      '#liens' => $liens,
      '#general' => ($general['email'] !== '' || $general['telephone'] !== '') ? $general : NULL,
      '#contacts' => $contacts,
      '#modifier' => $modifier,
      '#attached' => ['library' => ['mersch_services/services']],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Tableau des services dans l'administration : tous pour un admin, les
   * siens pour un rédacteur (US-25, US-26).
   */
  public function admin(): array {
    $compte = $this->currentUser();
    $admin = $compte->hasPermission('administer mersch services');
    $libelles = $this->rubriques->libelles();

    $lignes = [];
    foreach ($this->services() as $node) {
      if (!$admin && !$this->rubriques->estRedacteur($node, $compte)) {
        continue;
      }
      $rubrique = $this->rubriques->rubrique($node);
      $operations = [];
      if ($node->access('update')) {
        $operations['modifier'] = ['title' => $this->t('Edit'), 'url' => $node->toUrl('edit-form')];
      }
      if ($node->access('update') && $this->moduleHandler()->moduleExists('content_translation') && $compte->hasPermission('translate service node')) {
        $operations['traduire'] = ['title' => $this->t('Translate'), 'url' => $node->toUrl('drupal:content-translation-overview')];
      }
      if ($node->access('delete')) {
        $operations['supprimer'] = ['title' => $this->t('Delete'), 'url' => $node->toUrl('delete-form')];
      }
      $lignes[] = [
        ['data' => ['#type' => 'link', '#title' => $node->label(), '#url' => $this->url($node)]],
        $rubrique ? $libelles[$rubrique] : $this->t('Own page'),
        implode(', ', $this->redacteurs($node)) ?: '—',
        count($node->get('field_service_contacts')),
        count($node->get('field_service_liens')),
        $node->isPublished() ? $this->t('Published') : $this->t('Not published'),
        ['data' => ['#type' => 'operations', '#links' => $operations]],
      ];
    }

    return [
      'aide' => [
        '#markup' => '<p>' . ($admin
          ? $this->t('Each department has a page with its presentation, links and contacts. To choose the editors of a department, tick it on their account (People, Edit, “Departments edited”).')
          : $this->t('The departments you can edit: presentation, links and contacts.')) . '</p>',
      ],
      'tableau' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Department'),
          $this->t('Page'),
          $this->t('Editors'),
          $this->t('Contacts'),
          $this->t('Links'),
          $this->t('Status'),
          $this->t('Operations'),
        ],
        '#rows' => $lignes,
        '#empty' => $this->t('No department yet.'),
      ],
      '#cache' => [
        'tags' => ['node_list:' . Rubriques::TYPE, 'user_list'],
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * Accès au tableau : admins et rédacteurs d'au moins un service.
   */
  public function accesAdmin(AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer mersch services')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    foreach ($this->services() as $node) {
      if ($this->rubriques->estRedacteur($node, $account)) {
        return AccessResult::allowed()->cachePerUser()->addCacheTags(['node_list:' . Rubriques::TYPE]);
      }
    }
    return AccessResult::forbidden()->cachePerUser()->addCacheTags(['node_list:' . Rubriques::TYPE]);
  }

  /**
   * Agents de l'annuaire pour le champ « Collègue » (au fil de la frappe).
   */
  public function agents(Request $request): JsonResponse {
    $resultats = [];
    foreach ($this->contacts->chercher((string) $request->query->get('q', '')) as $agent) {
      $resultats[] = [
        'value' => $this->contacts->libelle($agent),
        'label' => trim($agent['nom'] . ' ' . $agent['prenom']) . ($agent['service'] !== '' ? ' – ' . $agent['service'] : ''),
      ];
    }
    return new JsonResponse($resultats);
  }

  /**
   * Adresse de la page d'un service : la rubrique liée, sinon le service.
   */
  public function url(NodeInterface $service): Url {
    $rubrique = $this->rubriques->rubrique($service);
    return $rubrique ? Url::fromRoute(Rubriques::EXISTANTES[$rubrique]['route']) : $service->toUrl();
  }

  /**
   * Tous les services, triés par ordre puis par nom.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function services(): array {
    $stockage = $this->entityTypeManager()->getStorage('node');
    $ids = $stockage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', Rubriques::TYPE)
      ->sort('field_service_poids')
      ->sort('title')
      ->execute();
    return $stockage->loadMultiple($ids);
  }

  /**
   * Noms des comptes qui ont ce service coché.
   *
   * @return string[]
   */
  private function redacteurs(NodeInterface $service): array {
    $stockage = $this->entityTypeManager()->getStorage('user');
    $ids = $stockage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_mersch_services', $service->id())
      ->condition('status', 1)
      ->sort('name')
      ->execute();
    return array_map(fn ($user) => $user->getDisplayName(), $stockage->loadMultiple($ids));
  }

  /**
   * Lien tel: au format international ; préfixe +352 pour un numéro local
   * (même règle que l'annuaire).
   */
  public static function lienTelephone(string $numero): string {
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
