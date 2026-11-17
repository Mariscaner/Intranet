<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_accueil\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mersch_accueil\Controller\AccueilController;
use Drupal\mersch_accueil\Entity\Idee;
use Drupal\mersch_accueil\Form\IdeeForm;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Boîte à idées de l'accueil.
 */
#[Group('mersch_accueil')]
#[RunTestsInSeparateProcesses]
class IdeeTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'block', 'filter', 'language', 'locale', 'mersch_accueil'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('mersch_idee');
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    $this->installConfig(['user', 'mersch_accueil']);
    // Utilisateur 1 créé d'abord : il aurait tous les droits.
    $this->createUser();
    mersch_accueil_install(TRUE);
  }

  /**
   * Un lecteur non connecté ne voit pas la boîte à idées.
   */
  public function testAnonymeSansBoite(): void {
    $build = AccueilController::boiteIdees();
    $this->assertArrayNotHasKey('formulaire', $build);
    $this->assertArrayNotHasKey('#prefix', $build);
  }

  /**
   * Un agent connecté envoie une idée : seuls auteur, date et texte sont
   * conservés ; il ne voit pas les idées reçues.
   */
  public function testAgentEnvoieUneIdee(): void {
    $this->assertTrue(Role::load(RoleInterface::AUTHENTICATED_ID)->hasPermission('submit mersch idees'));
    $agent = $this->setUpCurrentUser();
    $build = AccueilController::boiteIdees();
    $this->assertArrayHasKey('formulaire', $build);
    $this->assertArrayNotHasKey('admin', $build);

    $form_state = (new FormState())->setValues(['texte' => '  Eng Kantin-Säit  ']);
    \Drupal::formBuilder()->submitForm(IdeeForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $idees = Idee::loadMultiple();
    $this->assertCount(1, $idees);
    $idee = reset($idees);
    $this->assertSame('Eng Kantin-Säit', $idee->get('texte')->value);
    $this->assertSame($agent->id(), $idee->get('uid')->target_id);
    $this->assertNotEmpty($idee->get('created')->value);
    $this->assertEqualsCanonicalizing(['id', 'uuid', 'uid', 'created', 'texte'], array_keys($idee->getFieldDefinitions()));

    $this->assertFalse($idee->access('view', $agent));
    $this->assertFalse($idee->access('delete', $agent));
  }

  /**
   * Une idée vide ou trop longue est refusée.
   */
  public function testIdeeInvalide(): void {
    $this->setUpCurrentUser();
    foreach (['   ', str_repeat('a', Idee::LONGUEUR + 1)] as $texte) {
      $form_state = (new FormState())->setValues(['texte' => $texte]);
      \Drupal::formBuilder()->submitForm(IdeeForm::class, $form_state);
      $this->assertNotEmpty($form_state->getErrors());
    }
    $this->assertCount(0, Idee::loadMultiple());
  }

  /**
   * Un admin voit le lien vers les idées reçues et peut les supprimer.
   */
  public function testAdminVoitLesIdees(): void {
    Idee::create(['uid' => 1, 'texte' => 'Iddi'])->save();
    $admin = $this->setUpCurrentUser([], ['administer mersch idees', 'submit mersch idees']);
    $build = AccueilController::boiteIdees();
    $this->assertArrayHasKey('admin', $build);
    $this->assertStringContainsString('(1)', (string) $build['admin']['#title']);
    $idee = current(Idee::loadMultiple());
    $this->assertTrue($idee->access('delete', $admin));
  }

  /**
   * Une idée de plus de 12 mois est supprimée par le cron (D-041).
   */
  public function testConservation(): void {
    $maintenant = \Drupal::time()->getRequestTime();
    Idee::create(['uid' => 1, 'texte' => 'Al', 'created' => $maintenant - Idee::CONSERVATION - 60])->save();
    Idee::create(['uid' => 1, 'texte' => 'Nei', 'created' => $maintenant - 86400])->save();
    mersch_accueil_cron();
    $textes = array_map(fn (Idee $idee) => $idee->get('texte')->value, Idee::loadMultiple());
    $this->assertSame(['Nei'], array_values($textes));
  }

}
