<?php

namespace Drupal\wisski_pathbuilder\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wisski_pathbuilder\PathbuilderManager;

/**
 * Exports the pathbuilders and ontologies.
 *
 * Zips all pathbuildes and all ontologies of all adapters
 * and saves it the public directory public://wisski_export/.
 */
class ExportAllConfirmForm extends ConfirmFormBase {

  /**
   * The Directory to save the ontologies and pathbuilders.
   *
   * @var string
   */
  const EXPORT_ROOT_DIR = 'public://wisski_exports/';

  /**
   * The variable for the pathbuilder manager service.
   *
   * @var \Drupal\wisski_pathbuilder\PathbuilderManager
   */
  private PathbuilderManager $pathbuilderManager;

  /**
   * Create service container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wisski_pathbuilder.manager')
    );
  }

  /**
   * Constructs form variables.
   *
   * @param \Drupal\wisski_pathbuilder\PathbuilderManager $pathbuilderManager
   *   Performs file system operations and updates database records accordingly.
   */
  public function __construct(PathbuilderManager $pathbuilderManager) {
    $this->pathbuilderManager = $pathbuilderManager;
  }

  /**
   * The question.
   */
  public function getQuestion() {
    return $this->t('Do you want to export all pathbuilders and related ontologies?');
  }

  /**
   * The route if you hit Cancel.
   */
  public function getCancelUrl() {
    return new Url('entity.wisski_pathbuilder.collection');
  }

  /**
   * The form id.
   */
  public function getFormId() {
    return 'wisski_pathbuilder_export_all_confirm_form';
  }

  /**
   * The description.
   */
  public function getDescription() {
    return 'This creates a zip file with current, date containing every pathbuilder and every ontology.';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    return parent::buildForm($form, $form_state);

  }

  /**
   * Loads all pathbuilders and all ontologies and saves them.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $directoryTree = $this->pathbuilderManager->prepareExportDirectories(static::EXPORT_ROOT_DIR);
    if ($directoryTree) {
      $this->pathbuilderManager->exportAllOntologies($directoryTree['ontologiesDir']);
      $this->pathbuilderManager->exportAllPathbuilders($directoryTree['pathbuilderDir']);
      $zipFiles = [];
      $this->pathbuilderManager->collectZipDirs($directoryTree['instanceDir'], $zipFiles);
      $this->pathbuilderManager->zipPathbuildersAndOntologies($directoryTree['relativeExportDir'], $zipFiles);
      $this->pathbuilderManager->rRmDir($directoryTree['relativeExportDir']);
    }

    $form_state->setRedirect(
      'entity.wisski_pathbuilder.collection');
  }

}
