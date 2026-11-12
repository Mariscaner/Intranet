<?php

declare(strict_types=1);

namespace Drupal\mersch_services\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mersch_services\Contacts;

/**
 * Saisie d'un contact : nom cherché dans l'annuaire, puis rôle facultatif.
 *
 * Le champ propose les agents de l'annuaire au fil de la frappe
 * (« NOM Prénom (#12) »). Un contact déjà enregistré qui n'est plus dans
 * l'annuaire est signalé au rédacteur (US-28).
 */
#[FieldWidget(
  id: 'mersch_contact_annuaire',
  label: new TranslatableMarkup('Staff directory search'),
  field_types: ['mersch_contact'],
)]
class ContactWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $item = $items[$delta];
    $enregistre = [
      'nom' => (string) $item->nom,
      'prenom' => (string) $item->prenom,
      'email' => (string) $item->email,
    ];
    $texte = '';
    $description = '';
    if ($enregistre['nom'] !== '' || $enregistre['email'] !== '') {
      $agent = $this->contacts()->trouver($enregistre);
      if ($agent) {
        $texte = $this->contacts()->libelle($agent);
      }
      else {
        $texte = trim($enregistre['nom'] . ' ' . $enregistre['prenom']) ?: $enregistre['email'];
        $description = $this->t('Contact not found in the staff directory: not shown on the page. Choose another colleague or empty the field.');
      }
    }

    $element += [
      '#type' => 'container',
      '#attributes' => ['class' => ['mersch-contact-saisie']],
    ];
    $element['agent'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Colleague'),
      '#default_value' => $texte,
      '#autocomplete_route_name' => 'mersch_services.agents',
      '#maxlength' => 255,
      '#size' => 40,
      '#description' => $description,
      '#attributes' => $description ? ['class' => ['error']] : [],
    ];
    $element['role'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Role (optional)'),
      '#default_value' => (string) $item->role,
      '#maxlength' => 128,
      '#size' => 30,
      '#placeholder' => $this->t('e.g. Head of department'),
    ];
    $element['enregistre'] = ['#type' => 'value', '#value' => $enregistre + ['texte' => $texte]];
    $element['#element_validate'][] = [static::class, 'valider'];
    return $element;
  }

  /**
   * Retrouve l'agent choisi ; refuse un nom qui n'est pas dans l'annuaire.
   */
  public static function valider(array &$element, FormStateInterface $form_state): void {
    $texte = trim((string) $element['agent']['#value']);
    $enregistre = $element['enregistre']['#value'];
    $contacts = \Drupal::service('mersch_services.contacts');
    $valeur = ['nom' => '', 'prenom' => '', 'email' => ''];

    if ($texte === '') {
      // Champ vidé : le contact est retiré.
    }
    elseif ($texte === $enregistre['texte']) {
      // Inchangé : on garde ce qui était enregistré, même introuvable.
      $valeur = array_intersect_key($enregistre, $valeur);
    }
    else {
      $agent = NULL;
      if (preg_match('/\(#(\d+)\)\s*$/', $texte, $m)) {
        $agent = $contacts->parId((int) $m[1]);
      }
      if (!$agent) {
        // Nom tapé sans choisir dans la liste : accepté s'il désigne un seul agent.
        $trouves = $contacts->chercher($texte, 2);
        $agent = count($trouves) === 1 ? $trouves[0] : NULL;
      }
      if (!$agent) {
        $form_state->setError($element['agent'], t('Choose the colleague in the list that appears while you type.'));
        return;
      }
      $valeur = ['nom' => $agent['nom'], 'prenom' => $agent['prenom'], 'email' => $agent['email']];
    }
    $valeur['role'] = trim((string) $element['role']['#value']);
    $form_state->setValueForElement($element, $valeur);
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $resultat = [];
    foreach ($values as $valeur) {
      if (!is_array($valeur) || (($valeur['nom'] ?? '') === '' && ($valeur['email'] ?? '') === '')) {
        continue;
      }
      $resultat[] = [
        'nom' => $valeur['nom'],
        'prenom' => $valeur['prenom'] ?? '',
        'email' => $valeur['email'] ?? '',
        'role' => $valeur['role'] ?? '',
      ];
    }
    return $resultat;
  }

  /**
   * Service de recherche dans l'annuaire.
   */
  private function contacts(): Contacts {
    return \Drupal::service('mersch_services.contacts');
  }

}
