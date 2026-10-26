<?php

declare(strict_types=1);

namespace Drupal\mersch_accueil\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mersch_accueil\Form\LienRapideForm;
use Drupal\mersch_accueil\LienRapideListBuilder;

/**
 * Lien rapide de la page d'accueil (US-10), géré par les admins (US-11).
 *
 * Le libellé et la description sont saisis en LU, DE et FR ; une langue
 * vide reprend le texte luxembourgeois.
 */
#[ConfigEntityType(
  id: 'mersch_lien_rapide',
  label: new TranslatableMarkup('Quick link'),
  label_collection: new TranslatableMarkup('Quick links'),
  config_prefix: 'lien_rapide',
  entity_keys: [
    'id' => 'id',
    'weight' => 'weight',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => LienRapideListBuilder::class,
    'form' => [
      'add' => LienRapideForm::class,
      'edit' => LienRapideForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/mersch/liens-rapides',
    'add-form' => '/admin/config/mersch/liens-rapides/add',
    'edit-form' => '/admin/config/mersch/liens-rapides/{mersch_lien_rapide}',
    'delete-form' => '/admin/config/mersch/liens-rapides/{mersch_lien_rapide}/delete',
  ],
  admin_permission: 'administer mersch liens rapides',
  config_export: [
    'id',
    'libelle',
    'description',
    'url',
    'icone',
    'weight',
  ],
)]
class LienRapide extends ConfigEntityBase {

  /**
   * Langues de saisie, dans l'ordre d'affichage (D-005).
   */
  public const LANGUES = ['lb', 'de', 'fr'];

  /**
   * Icônes proposées (tracés SVG dans mersch-lien-rapide-icone.html.twig).
   */
  public const ICONES = ['calendrier', 'document', 'aide', 'web', 'lien'];

  protected $id;

  /**
   * Libellé par langue, clés lb, de, fr.
   */
  protected array $libelle = [];

  /**
   * Description courte par langue, clés lb, de, fr.
   */
  protected array $description = [];

  protected string $url = '';

  protected string $icone = 'lien';

  protected int $weight = 0;

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->libelle['lb'] ?? $this->id;
  }

  /**
   * Libellé dans la langue demandée, sinon en luxembourgeois.
   */
  public function libelle(string $langcode): string {
    return $this->texte($this->libelle, $langcode);
  }

  /**
   * Description dans la langue demandée, sinon en luxembourgeois.
   */
  public function description(string $langcode): string {
    return $this->texte($this->description, $langcode);
  }

  /**
   * Texte saisi pour une langue, sans repli (formulaire d'édition).
   */
  public function saisie(string $champ, string $langcode): string {
    return (string) ($this->{$champ}[$langcode] ?? '');
  }

  public function getUrl(): string {
    return $this->url;
  }

  public function icone(): string {
    return in_array($this->icone, self::ICONES, TRUE) ? $this->icone : 'lien';
  }

  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * Validation d'URL commune au formulaire et aux tests (US-11).
   *
   * Seules les adresses absolues http ou https sont acceptées ; un nom de
   * serveur interne sans point (https://apps, https://helpdesk) est valide.
   */
  public static function urlValide(string $url): bool {
    $schema = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $hote = (string) parse_url($url, PHP_URL_HOST);
    return in_array($schema, ['http', 'https'], TRUE)
      && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $hote) === 1
      && filter_var($url, FILTER_VALIDATE_URL) !== FALSE;
  }

  /**
   * Texte d'une langue, avec repli sur le LU quand il est vide.
   */
  private function texte(array $textes, string $langcode): string {
    return trim($textes[$langcode] ?? '') ?: trim($textes['lb'] ?? '');
  }

}
