<?php

declare(strict_types=1);

namespace Drupal\mersch_services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Rubriques de l'intranet et droits des rédacteurs sur les services.
 *
 * Les rubriques RH, Communication et Guides IT gardent leur page ; leur
 * service y ajoute liens et contacts en bas de page. Les autres services
 * ont leur propre page (US-22).
 */
class Rubriques {

  use StringTranslationTrait;

  /**
   * Type de contenu des pages de service.
   */
  public const TYPE = 'service';

  /**
   * Type de contenu des nouvelles d'un service.
   */
  public const NOUVELLE = 'service_nouvelle';

  /**
   * Étiquette de cache vidée chaque jour par le cron (fin de publication).
   */
  public const CACHE_TAG_JOUR = 'mersch_services_jour';

  /**
   * Rubriques existantes : route de leur page, permission qui désigne leurs
   * rédacteurs (04_roles-et-droits) et types de contenu.
   */
  public const EXISTANTES = [
    'rh' => [
      'route' => 'mersch_accueil.rh',
      'permission' => 'create rh_nouvelle content',
      'types' => ['rh_nouvelle', 'rh_communication', 'rh_poste'],
    ],
    'communication' => [
      'route' => 'mersch_accueil.communication',
      'permission' => 'create comm_nouvelle content',
      'types' => ['comm_nouvelle'],
    ],
    'guides_it' => [
      'route' => 'mersch_accueil.guides_it',
      'permission' => 'create guide_it content',
      'types' => ['guide_it'],
    ],
  ];

  /**
   * Champs que seuls les administrateurs modifient (Q5 : nom, adresse,
   * ordre, publication, rubrique liée).
   */
  public const CHAMPS_ADMIN = ['title', 'path', 'status', 'field_service_poids', 'field_service_rubrique', 'langcode'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Libellés des rubriques pour le filtre de l'administration (US-27).
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public function libelles(): array {
    return [
      'rh' => $this->t('Human resources'),
      'communication' => $this->t('Communication'),
      'guides_it' => $this->t('IT guides'),
      'services' => $this->t('Department pages'),
    ];
  }

  /**
   * Types de contenu d'une rubrique du filtre.
   *
   * @return string[]
   */
  public function types(string $rubrique): array {
    if ($rubrique === 'services') {
      return [self::TYPE, self::NOUVELLE];
    }
    return self::EXISTANTES[$rubrique]['types'] ?? [];
  }

  /**
   * Rubriques que la personne peut filtrer : toutes pour un admin, les
   * siennes pour un rédacteur (US-27).
   *
   * @return string[]
   */
  public function rubriquesDe(AccountInterface $account): array {
    if ($account->hasPermission('administer mersch services')) {
      return array_keys($this->libelles());
    }
    $rubriques = [];
    foreach (self::EXISTANTES as $cle => $rubrique) {
      if ($account->hasPermission($rubrique['permission'])) {
        $rubriques[] = $cle;
      }
    }
    if ($this->servicesDe($account)) {
      $rubriques[] = 'services';
    }
    return $rubriques;
  }

  /**
   * Identifiants des services cochés sur le compte de la personne (US-26).
   *
   * @return int[]
   */
  public function servicesDe(AccountInterface $account): array {
    if ($account->isAnonymous()) {
      return [];
    }
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user || !$user->hasField('field_mersch_services')) {
      return [];
    }
    return array_map('intval', array_column($user->get('field_mersch_services')->getValue(), 'target_id'));
  }

  /**
   * La personne est-elle rédacteur de ce service ?
   *
   * Un admin, un agent qui a ce service sur son compte, ou un rédacteur de
   * la rubrique liée (rédacteurs RH pour le service RH).
   */
  public function estRedacteur(NodeInterface $service, AccountInterface $account): bool {
    if ($account->hasPermission('administer mersch services')) {
      return TRUE;
    }
    if (in_array((int) $service->id(), $this->servicesDe($account), TRUE)) {
      return TRUE;
    }
    $rubrique = $this->rubrique($service);
    return $rubrique && $account->hasPermission(self::EXISTANTES[$rubrique]['permission']);
  }

  /**
   * Service d'une nouvelle de service, ou NULL.
   */
  public function serviceDe(NodeInterface $nouvelle): ?NodeInterface {
    $service = $nouvelle->hasField('field_snouv_service') ? $nouvelle->get('field_snouv_service')->entity : NULL;
    return $service instanceof NodeInterface ? $service : NULL;
  }

  /**
   * Services que la personne peut modifier, triés par ordre puis par nom.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  public function servicesModifiables(AccountInterface $account): array {
    $stockage = $this->entityTypeManager->getStorage('node');
    $ids = $stockage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::TYPE)
      ->sort('field_service_poids')
      ->sort('title')
      ->execute();
    return array_filter($stockage->loadMultiple($ids), fn (NodeInterface $service) => $this->estRedacteur($service, $account));
  }

  /**
   * Rubrique existante liée au service, ou NULL.
   */
  public function rubrique(NodeInterface $service): ?string {
    if (!$service->hasField('field_service_rubrique')) {
      return NULL;
    }
    $rubrique = $service->get('field_service_rubrique')->value;
    return isset(self::EXISTANTES[$rubrique]) ? $rubrique : NULL;
  }

  /**
   * Service lié à une rubrique existante (le premier publié ou non).
   */
  public function servicePourRubrique(string $rubrique): ?NodeInterface {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::TYPE)
      ->condition('field_service_rubrique', $rubrique)
      ->sort('nid')
      ->range(0, 1)
      ->execute();
    return $ids ? $this->entityTypeManager->getStorage('node')->load(reset($ids)) : NULL;
  }

}
