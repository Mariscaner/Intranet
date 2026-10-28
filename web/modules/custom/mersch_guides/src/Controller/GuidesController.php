<?php

declare(strict_types=1);

namespace Drupal\mersch_guides\Controller;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\mersch_accueil\Entity\LienRapide;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Page Guides IT : catégories et recherche (US-19), guides publiés par les
 * administrateurs (US-20, US-21, D-007).
 */
class GuidesController extends ControllerBase {

  /**
   * Type de contenu des guides, réservé aux administrateurs.
   */
  public const TYPE = 'guide_it';

  /**
   * Adresse du helpdesk si le lien rapide « helpdesk » n'existe plus.
   */
  public const HELPDESK = 'https://helpdesk/';

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly DateFormatterInterface $dateFormatter,
    #[Autowire(service: 'transliteration')]
    private readonly TransliterationInterface $transliteration,
  ) {}

  /**
   * Page /guides-it.
   */
  public function page(): array {
    $cache = (new CacheableMetadata())
      ->addCacheTags(['node_list:' . self::TYPE])
      ->addCacheContexts(['languages:language_interface', 'languages:language_content', 'user.permissions']);
    $categories = $this->categories();

    $guides = [];
    foreach ($this->charger() as $node) {
      $cache->addCacheableDependency($node);
      $guides[] = $this->carte($node, $categories);
    }
    // Ordre des catégories, puis titre dans la langue affichée.
    $ordre = array_flip(array_keys($categories));
    usort($guides, fn (array $a, array $b) => [$ordre[$a['categorie']['cle']] ?? 99, $a['tri']] <=> [$ordre[$b['categorie']['cle']] ?? 99, $b['tri']]);

    // Seules les catégories qui contiennent un guide servent de filtre.
    $utilisees = array_column(array_column($guides, 'categorie'), 'cle');
    $filtres = [];
    foreach ($categories as $cle => $libelle) {
      if (in_array($cle, $utilisees, TRUE)) {
        $filtres[] = ['cle' => $cle, 'libelle' => $libelle];
      }
    }

    $build = [
      '#theme' => 'mersch_guides',
      '#rubrique' => [
        'cle' => 'guides-it',
        'texte' => $this->t('Solve a problem yourself, or ask IT Hëllef vu Miersch.'),
      ],
      '#categories' => $filtres,
      '#guides' => $guides,
      '#helpdesk' => $this->helpdesk($cache),
      '#attached' => ['library' => ['mersch_accueil/accueil', 'mersch_guides/guides']],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Carte d'un guide sur la page.
   */
  private function carte(NodeInterface $node, array $categories): array {
    $traduction = $this->entityRepository->getTranslationFromContext($node);
    $cle = (string) $node->get('field_guide_categorie')->value;
    $titre = (string) $traduction->label();
    return [
      'titre' => $titre,
      'tri' => $this->cle($titre),
      'resume' => (string) $traduction->get('field_guide_resume')->value,
      'categorie' => ['cle' => $cle, 'libelle' => $categories[$cle] ?? $cle],
      // Le lien garde la langue affichée, même si le guide n'est qu'en LU.
      'url' => $node->toUrl('canonical', ['language' => $this->languageManager()->getCurrentLanguage()])->toString(),
      'pdf' => $this->pdf($node),
      'date' => $this->dateFormatter->format($node->getChangedTime(), 'custom', 'd.m.Y'),
      'recherche' => $this->recherche($node, $cle),
      'non_traduit' => !$node->hasTranslation($this->langueContenu()),
      'modifier' => $node->access('update') ? $traduction->toUrl('edit-form')->toString() : NULL,
    ];
  }

  /**
   * En-tête de la page d'un guide : catégorie et résumé.
   */
  public function entete(NodeInterface $node): array {
    $traduction = $this->entityRepository->getTranslationFromContext($node);
    $cle = (string) $node->get('field_guide_categorie')->value;
    return [
      '#theme' => 'mersch_guide_entete',
      '#categorie' => ['cle' => $cle, 'libelle' => $this->categories()[$cle] ?? $cle],
      '#resume' => (string) $traduction->get('field_guide_resume')->value,
      '#non_traduit' => !$node->hasTranslation($this->langueContenu()),
      '#cache' => ['contexts' => ['languages:language_interface', 'languages:language_content']],
    ];
  }

  /**
   * Pied de la page d'un guide : PDF, date de mise à jour (US-21), lien vers
   * le helpdesk (US-19).
   */
  public function pied(NodeInterface $node): array {
    $cache = new CacheableMetadata();
    $build = [
      '#theme' => 'mersch_guide_pied',
      '#pdf' => $this->pdf($node),
      '#date' => $this->dateFormatter->format($node->getChangedTime(), 'custom', 'd.m.Y'),
      '#helpdesk' => $this->helpdesk($cache),
      '#retour' => Url::fromRoute('mersch_accueil.guides_it')->toString(),
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Libellés des catégories (01, §4, US-20), dans l'ordre d'affichage.
   *
   * Les mêmes libellés anglais sont les valeurs du champ, traduits par les
   * fichiers .po.
   */
  public function categories(?string $langcode = NULL): array {
    $options = $langcode ? ['langcode' => $langcode] : [];
    return [
      'helpdesk' => (string) $this->t('Helpdesk', [], $options),
      'applications' => (string) $this->t('Applications', [], $options),
      'messagerie' => (string) $this->t('Email and calendar', [], $options),
      'securite' => (string) $this->t('Security and accounts', [], $options),
      'materiel' => (string) $this->t('Hardware and software', [], $options),
    ];
  }

  /**
   * Texte de recherche d'un guide : titre, résumé et mots-clés dans toutes
   * ses langues, plus sa catégorie en LU, DE et FR, sans accents ni
   * majuscules (US-19). Un agent trouve « Passwuert » même en français.
   */
  private function recherche(NodeInterface $node, string $categorie): string {
    $textes = [];
    foreach ($node->getTranslationLanguages() as $langcode => $langue) {
      $traduction = $node->getTranslation($langcode);
      $textes[] = $traduction->label();
      $textes[] = $traduction->get('field_guide_resume')->value;
      $textes[] = $traduction->get('field_guide_mots_cles')->value;
    }
    foreach (LienRapide::LANGUES as $langcode) {
      $textes[] = $this->categories($langcode)[$categorie] ?? '';
    }
    return $this->cle(implode(' ', array_filter($textes)));
  }

  /**
   * Clé de comparaison : minuscules, sans accents (même règle que le JS).
   */
  private function cle(string $texte): string {
    return mb_strtolower($this->transliteration->transliterate($texte, 'fr'));
  }

  /**
   * Le PDF d'un guide, s'il en a un.
   */
  private function pdf(NodeInterface $node): ?array {
    $fichier = $node->get('field_guide_pdf')->entity;
    return $fichier ? [
      'url' => $fichier->createFileUrl(),
      'taille' => ByteSizeMarkup::create((int) $fichier->getSize()),
    ] : NULL;
  }

  /**
   * Adresse du helpdesk : celle du lien rapide « Helpdesk » de l'accueil,
   * tenu par les admins, pour ne pas la saisir deux fois.
   */
  private function helpdesk(CacheableMetadata $cache): string {
    $lien = LienRapide::load('helpdesk');
    $cache->addCacheTags(['config:mersch_accueil.lien_rapide.helpdesk']);
    return $lien && $lien->getUrl() !== '' ? $lien->getUrl() : self::HELPDESK;
  }

  /**
   * Guides publiés, accessibles à l'utilisateur.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function charger(): array {
    $ids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', self::TYPE)
      ->condition('status', NodeInterface::PUBLISHED)
      ->execute();
    return $ids ? array_values($this->entityTypeManager()->getStorage('node')->loadMultiple($ids)) : [];
  }

  /**
   * Langue du contenu affiché.
   */
  private function langueContenu(): string {
    return $this->languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
  }

}
