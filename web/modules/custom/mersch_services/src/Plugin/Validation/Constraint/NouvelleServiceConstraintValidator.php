<?php

declare(strict_types=1);

namespace Drupal\mersch_services\Plugin\Validation\Constraint;

use Drupal\mersch_services\Rubriques;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Vérifie une nouvelle de service : 150 mots au plus, sans minimum (D-031),
 * et un service dont la personne est rédacteur (D-007).
 */
class NouvelleServiceConstraintValidator extends ConstraintValidator {

  public const MOTS_MAX = 150;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== Rubriques::NOUVELLE) {
      return;
    }
    assert($constraint instanceof NouvelleServiceConstraint);

    $mots = self::compterMots((string) $entity->get('field_snouv_texte')->value);
    if ($mots > self::MOTS_MAX) {
      $this->context->buildViolation($constraint->tropLong, ['@mots' => $mots])
        ->atPath('field_snouv_texte')
        ->addViolation();
    }

    // Contrôle la personne connectée ; un script lancé par drush (anonyme)
    // passe.
    $service = $entity->get('field_snouv_service')->entity;
    $compte = \Drupal::currentUser();
    if ($service instanceof NodeInterface && $compte->isAuthenticated()
      && !\Drupal::service('mersch_services.rubriques')->estRedacteur($service, $compte)) {
      $this->context->buildViolation($constraint->pasSonService)
        ->atPath('field_snouv_service')
        ->addViolation();
    }
  }

  /**
   * Nombre de mots d'un texte, balises HTML ôtées.
   */
  public static function compterMots(string $texte): int {
    $texte = trim(html_entity_decode(strip_tags($texte), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $texte === '' ? 0 : count(preg_split('/\s+/u', $texte));
  }

}
