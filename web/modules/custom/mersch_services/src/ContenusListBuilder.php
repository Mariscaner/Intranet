<?php

declare(strict_types=1);

namespace Drupal\mersch_services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mersch_services\Form\FiltreContenusForm;
use Drupal\node\NodeListBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Liste des contenus de l'administration, filtrable par rubrique (US-27).
 *
 * L'intranet n'utilise pas Views : la page /admin/content est la liste des
 * contenus du cœur, à laquelle on ajoute les filtres rubrique, titre et
 * statut. Un rédacteur ne voit que ses rubriques.
 */
class ContenusListBuilder extends NodeListBuilder {

  /**
   * Contenus par page.
   *
   * @var int
   */
  protected $limit = 50;

  protected Rubriques $rubriques;

  protected RequestStack $requestStack;

  protected AccountInterface $currentUser;

  protected FormBuilderInterface $formBuilder;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->rubriques = $container->get('mersch_services.rubriques');
    $instance->requestStack = $container->get('request_stack');
    $instance->currentUser = $container->get('current_user');
    $instance->formBuilder = $container->get('form_builder');
    return $instance;
  }

  /**
   * Filtres demandés dans l'adresse (?rubrique=…&titre=…&statut=…).
   *
   * @return array{rubrique: string, titre: string, statut: string}
   */
  public function filtres(): array {
    $query = $this->requestStack->getCurrentRequest()?->query;
    $rubrique = (string) $query?->get('rubrique', '');
    $statut = (string) $query?->get('statut', '');
    return [
      'rubrique' => in_array($rubrique, $this->rubriques->rubriquesDe($this->currentUser), TRUE) ? $rubrique : '',
      'titre' => trim((string) $query?->get('titre', '')),
      'statut' => in_array($statut, ['1', '0'], TRUE) ? $statut : '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    $query = parent::getEntityListQuery();
    $filtres = $this->filtres();
    $admin = $this->currentUser->hasPermission('administer nodes') || $this->currentUser->hasPermission('administer mersch services');

    if ($filtres['rubrique'] !== '') {
      $rubriques = [$filtres['rubrique']];
    }
    elseif (!$admin) {
      // Sans filtre, un rédacteur voit toutes ses rubriques, et elles seules.
      $rubriques = $this->rubriques->rubriquesDe($this->currentUser);
    }
    else {
      $rubriques = [];
    }

    if ($rubriques || !$admin) {
      $groupe = $query->orConditionGroup();
      foreach ($rubriques as $rubrique) {
        if ($rubrique === 'services' && !$admin) {
          // Seulement les services dont la personne est rédacteur (US-26) et
          // leurs nouvelles.
          $ids = array_keys($this->rubriques->servicesModifiables($this->currentUser)) ?: [0];
          $groupe->condition('nid', $ids, 'IN');
          $groupe->condition($query->andConditionGroup()
            ->condition('type', Rubriques::NOUVELLE)
            ->condition('field_snouv_service', $ids, 'IN'));
        }
        else {
          $groupe->condition('type', $this->rubriques->types($rubrique) ?: ['-'], 'IN');
        }
      }
      if (!$rubriques) {
        $groupe->condition('nid', 0);
      }
      $query->condition($groupe);
    }
    if ($filtres['titre'] !== '') {
      $query->condition('title', $filtres['titre'], 'CONTAINS');
    }
    if ($filtres['statut'] !== '') {
      $query->condition('status', (int) $filtres['statut']);
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = parent::buildHeader();
    $header = array_slice($header, 0, 1, TRUE)
      + ['rubrique' => $this->t('Section')]
      + array_slice($header, 1, NULL, TRUE);
    return $header;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row = parent::buildRow($entity);
    $libelles = $this->rubriques->libelles();
    $rubrique = '';
    foreach (Rubriques::EXISTANTES as $cle => $definition) {
      if (in_array($entity->bundle(), $definition['types'], TRUE)) {
        $rubrique = $libelles[$cle];
      }
    }
    if (in_array($entity->bundle(), [Rubriques::TYPE, Rubriques::NOUVELLE], TRUE)) {
      $rubrique = $libelles['services'];
    }
    return array_slice($row, 0, 1, TRUE)
      + ['rubrique' => $rubrique]
      + array_slice($row, 1, NULL, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build['filtres'] = $this->formBuilder->getForm(FiltreContenusForm::class, $this->filtres(), $this->rubriques->rubriquesDe($this->currentUser));
    $build += parent::render();
    $build['table']['#empty'] = $this->t('No content matches these filters.');
    $build['table']['#cache']['contexts'][] = 'url.query_args';
    $build['table']['#cache']['contexts'][] = 'user';
    return $build;
  }

}
