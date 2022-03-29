<?php

namespace Drupal\wisski_doi\Form;

use Drupal\wisski_core\WisskiStorageInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wisski_doi\WisskiDoiActions;
use Drupal\wisski_doi\WisskiDoiDbActions;
use Drupal\wisski_doi\WisskiDoiDataciteRestActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for reverting a wisski_individual revision.
 *
 * @internal
 */
class WisskiDoiBatch4CurrentRevisionsConfirmForm extends WisskiDoiBatch4StaticRevisionsConfirmForm {

  /**
   * Config name of WissKI individuals to batch.
   *
   * @var string
   */
  const INDIVIDUALS_IN_BATCH = 'wisski_doi_batch.array_for_current_revisions';

  /**
   * Constructs a new form to request a DOI for a static revision.
   *
   * @param \Drupal\wisski_core\WisskiStorageInterface $wisski_storage
   *   The WissKI Storage service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\wisski_doi\WisskiDoiActions $wisskiDoiActions
   *   The WissKi DOI Service.
   * @param \Drupal\wisski_doi\WisskiDoiDataciteRestActions $wisskiDoiRestActions
   *   The WissKi DOI Rest Service.
   * @param \Drupal\wisski_doi\WisskiDoiDbActions $wisskiDoiDbActions
   *   The WissKI DOI database Service.
   */
  public function __construct(WisskiStorageInterface       $wisski_storage,
                              DateFormatterInterface       $date_formatter,
                              TimeInterface                $time,
                              WisskiDoiActions             $wisskiDoiActions,
                              WisskiDoiDataciteRestActions $wisskiDoiRestActions,
                              WisskiDoiDbActions           $wisskiDoiDbActions) {
    $this->wisskiStorage = $wisski_storage;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->wisskiDoiActions = $wisskiDoiActions;
    $this->wisskiDoiRestActions = $wisskiDoiRestActions;
    $this->wisskiDoiDbActions = $wisskiDoiDbActions;
  }

  /**
   * Populate the reachable variables from services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('wisski_individual'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('wisski_doi.wisski_doi_actions'),
      $container->get('wisski_doi.wisski_doi_rest_actions'),
      $container->get('wisski_doi.wisski_doi_db_actions'),
    );
  }

  /**
   * The machine name of the form.
   *
   * @return string
   *   The form id.
   */
  public function getFormId() {
    return 'wisski_doi_batch_form_for_current_revisions';
  }

  /**
   * The question of the confirm form.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The confirmation questions.
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to request DOIs for current revisions of the selected WissKI individuals?');
  }

  /**
   * Details between title and body.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description texts.
   */
  public function getDescription() {
    return $this->t('This assigns a DOI to each current revision of the selected
    WissKI individuals.
    The DOI points to the current state of the data record, if you like to assign a DOI which points
    always to a static state of the dataset, please use "Get DOIs for static revisions". <br>
    <b>Following data will be received from the data records:</b> <br>
    <ul>
    <li>author</li>
    <li>title</li>
    <li>revision creation date</li>
    <li>language</li>
    </ul>');
  }

  /**
   * Start DOI batches for current revisions.
   *
   * Saves no revision, but directly request a DOI for current
   * revision,then store the DOI data in Drupal DB.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Get new values from form state.
    $newValues = $form_state->cleanValues()->getValues();
    $doiMetaData = $newValues;

    // Get AJAX info.
    $contributorItems = $this->configFactory
      ->getEditable('wisski_doi.contributor.items');

    // Have to overwrite contributors cause AJAX mess up the form_state.
    $doiMetaData['contributors'] = $contributorItems->get('contributors');

    // Iterate over selected WissKI individuals.
    $this->wisskiDoiActions->batchLoop(1, $this->wisskiIndividualsBatch, $doiMetaData, static::INDIVIDUALS_IN_BATCH);

    // Redirect to batch overview.
    $form_state->setRedirect(
      'entity.wisski_bundle.doi_batch', ['wisski_bundle' => $this->wisskiBundleId]
     );
  }

}
