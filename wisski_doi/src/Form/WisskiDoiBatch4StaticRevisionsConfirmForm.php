<?php

namespace Drupal\wisski_doi\Form;

use Drupal\wisski_core\WisskiStorageInterface;
use Drupal\wisski_core\WisskiEntityInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\wisski_doi\WisskiDoiActions;
use Drupal\wisski_doi\WisskiDoiDbActions;
use Drupal\wisski_doi\WisskiDoiDataciteRestActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for reverting a wisski_individual revision.
 *
 * @internal
 */
class WisskiDoiBatch4StaticRevisionsConfirmForm extends ConfirmFormBase {

  /**
   * Batch metadata config name.
   *
   * @var string
   */
  const SELECTED_INDIVIDUALS = 'wisski_doi_batch_form.storage';

  /**
   * Config name of WissKI individuals to batch.
   *
   * @var string
   */
  const INDIVIDUALS_IN_BATCH = 'wisski_doi_batch.array_for_static_revisions';

  /**
   * The WisskiEntity revision.
   *
   * @var \Drupal\wisski_core\WisskiEntityInterface
   */
  protected WisskiEntityInterface $revision;

  /**
   * The WissKI storage.
   *
   * @var \Drupal\wisski_core\WisskiStorageInterface
   */
  protected WisskiStorageInterface $wisskiStorage;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;


  /**
   * The service to management DOI metadata.
   *
   * @var \Drupal\wisski_doi\WisskiDoiActions
   */
  protected WisskiDoiActions $wisskiDoiActions;

  /**
   * The service to interact with the REST API .
   *
   * @var \Drupal\wisski_doi\WisskiDoiDataciteRestActions
   */
  protected WisskiDoiDataciteRestActions $wisskiDoiRestActions;

  /**
   * The service to interact with the database.
   *
   * @var \Drupal\wisski_doi\WisskiDoiDbActions
   */
  protected WisskiDoiDbActions $wisskiDoiDbActions;

  /**
   * The metadata for the batch.
   *
   * @var array
   */
  protected array $batchMetadata;

  /**
   * The WissKI bundle.
   *
   * @var string
   */
  protected string $wisskiBundleId;

  /**
   * The set of WissKI individuals, which have to processed.
   *
   * @var array
   */
  protected array $wisskiIndividualsBatch;

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
    return 'wisski_doi_batch_form_for_static_revisions';
  }

  /**
   * Storage of the contributor names.
   *
   * @return array
   *   The list of storage items.
   */
  protected function getEditableConfigNames() {
    return [
      WisskiDoiConfirmFormRequestDoiForStaticRevision::CONTRIBUTOR_ITEMS_CONFIG,
      static::INDIVIDUALS_IN_BATCH,
    ];
  }

  /**
   * The question of the confirm form.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The confirmation questions.
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to request DOIs for static revisions of the selected WissKI individuals?');
  }

  /**
   * Route, if you hit chancel.
   *
   * @return \Drupal\Core\Url
   *   The Chancel URL.
   */
  public function getCancelUrl() {
    return new Url('entity.wisski_bundle.doi_batch', ['wisski_bundle' => $this->wisskiBundleId]);
  }

  /**
   * Text on the submit button.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The submit button text.
   */
  public function getConfirmText() {
    return $this->t('Request DOIs');
  }

  /**
   * Details between title and body.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description texts.
   */
  public function getDescription() {
    return $this->t('This saves a revision and assigns a DOI to each of the selected
    WissKI individuals.
    The DOI points only to this revision, you can not change the data of the
    dataset afterwards (only the metadata of the DOI). If you like to assign a DOI which points
    always to the current state of the dataset, please use "Get DOIs for current revisions". <br>
    <b>Following data will be received from the data records:</b> <br>
    <ul>
    <li>author</li>
    <li>title</li>
    <li>revision creation date</li>
    <li>language</li>
    </ul>');
  }

  /**
   * Build table from DOI settings and WissKI individual state.
   *
   * Load DOI settings from Manage->Configuration->WissKI:WissKI DOI Settings.
   * Store it in a table.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $wisskiBundleId
   *   The WissKI bundle ID.
   *
   * @return array
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $wisskiBundleId = NULL) {
    \Drupal::messenger()->deleteAll();
    // Assign WissKI bundle to class property.
    $this->wisskiBundleId = $wisskiBundleId;

    /* #tree will ensure the HTML elements get named distinctively.
     * Not just name=[name] but name=[container][123][name].
     */
    $form['#tree'] = TRUE;

    /*
     * Load existing form data.
     */

    // General form.
    $form = parent::buildForm($form, $form_state);

    // DOI Settings from config page.
    $doiSettings = \Drupal::configFactory()
      ->getEditable(WisskiDoiRepositorySettings::DOI_SETTINGS);

    // Contributors from form.
    $contributorItems = $this->config(WisskiDoiConfirmFormRequestDoiForStaticRevision::CONTRIBUTOR_ITEMS_CONFIG);

    // Load selected WissKI individuals.
    $wisskiIndividualIds = $this->configFactory
      ->getEditable(static::SELECTED_INDIVIDUALS)->get('wisskiIndividuals');
    // Remove keys with empty values.
    $wisskiIndividualIds = array_filter($wisskiIndividualIds);
    $wisskiIndividualIds = empty($wisskiIndividualIds) ? array_column($this->wisskiDoiDbActions->readBundleRecords($wisskiBundleId), 'eid') : $wisskiIndividualIds;
    count($wisskiIndividualIds) <= 15 ?: \Drupal::messenger()->addWarning($this->t('There are a lot DOIs to request, this may take a while (ca. 1 seconds per DOI)'));
    // Load processed batch data.
    $batchStateStaticRevisions = $this
      ->configFactory
      ->getEditable(static::INDIVIDUALS_IN_BATCH)
      ->get('wisskiIndividualsToProcess');

    $this->wisskiIndividualsBatch = $batchStateStaticRevisions ?: $wisskiIndividualIds;

    // Batch metadata.
    $this->batchMetadata = [
      "event" => 'draft',
      "contributors" => $contributorItems->get('contributors'),
      "publisher" => $doiSettings->get('doiSettings.data_publisher'),
      "resourceType" => 'Dataset',
    ];

    // Resource type option from DataCite schema.
    $resourceTypeOptions = [
      'Audiovisual' => 'Audiovisual',
      'Collection' => 'Collection',
      'DataPaper' => 'DataPaper',
      'Dataset' => 'Dataset',
      'Event' => 'Event',
      'Image' => 'Image',
      'InteractiveResource' => 'InteractiveResource',
      'Model' => 'Model',
      'PhysicalObject' => 'PhysicalObject',
      'Service' => 'Service',
      'Software' => 'Software',
      'Sound' => 'Sound',
      'Text' => 'Text',
      'Workflow' => 'Workflow',
      'Other' => 'Other',
    ];

    /*
     * publish - Triggers a state move from draft or registered to findable.
     * register - Triggers a state move from draft to registered (register)
     * or from findable to registered (hide).
     * draft - Triggers a state move from findable to registered.
     */
    $doiEvents = [
      'draft' => 'draft',
      'register' => 'register',
      'hide' => 'hide',
      'publish' => 'publish',
    ];

    $form['count'] = [
      '#type' => 'item',
      '#value' => count($this->wisskiIndividualsBatch),
      '#markup' => count($this->wisskiIndividualsBatch),
      '#title' => $this->t('Count of selected WissKI individuals'),
    ];

    $form['event'] = [
      '#type' => 'select',
      '#title' => $this->t('Event'),
      '#options' => $doiEvents,
      '#default_value' => $this->batchMetadata['event'],
      '#description' => $this->t('The event for the DOI. If you register or publish the DOI, it can not be deleted!'),
    ];

    $form['contributors'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Contributors'),

    ];
    $form['contributors']['contributorGroup'] = [
      '#type' => 'fieldgroup',
      '#attributes' => ['class' => ['wisski-doi-contributorGroup']],
    ];

    $form['contributors']['contributorGroup']['contributor'] = [
      '#type' => 'textfield',
      '#description' => $this->t('Additional Contributors like previous editors of the dataset.'),
    ];
    $form['contributors']['contributorGroup']['submit'] = [
      '#type' => 'button',
      '#ajax' => [
        'callback' => [
          '\Drupal\wisski_doi\Form\WisskiDoiConfirmFormRequestDoiForStaticRevision',
          'addContributor',
        ],
        'wrapper' => 'contributor-list',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Adding contributor...'),
        ],
      ],
      '#value' => $this->t('Add'),
    ];

    $form['contributors']['contributorTable'] = [
      '#type' => 'item',
      '#markup' => WisskiDoiConfirmFormRequestDoiForStaticRevision::renderContributors($contributorItems->get('contributors')),
    ];

    $form['publisher'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Publisher'),
      '#default_value' => $this->batchMetadata['publisher'],
      '#description' => $this->t('The publisher of the database.'),
    ];

    $form['resourceType'] = [
      '#type' => 'select',
      '#title' => $this->t('Type of record'),
      '#options' => $resourceTypeOptions,
      '#default_value' => 'Dataset',
      '#description' => $this->t('The type of data in DOI terms, usually "Dataset".'),
    ];
    $form['#attached']['library'][] = 'wisski_doi/wisskiDoi';
    return $form;
  }

  /**
   * Start the DOI batches for static revisions.
   *
   * First save a DOI revision to receive a revision id,
   * request a DOI for that revision,then save a second
   * time to store the revision and DOI in Drupal DB.
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
    $doiMetaData = $form_state->cleanValues()->getValues();

    // Get AJAX info.
    $contributorItems = $this->configFactory
      ->getEditable('wisski_doi.contributor.items');

    // Have to overwrite contributors cause AJAX mess up the form_state.
    $doiMetaData['contributors'] = $contributorItems->get('contributors');
    // Iterate over selected WissKI individuals.
    if ($this->wisskiIndividualsBatch) {
      foreach ($this->wisskiIndividualsBatch as $wisskiIndividualId) {
        unset($this->wisskiIndividualsBatch[$wisskiIndividualId]);
        $wisskiIndividual = $this->wisskiStorage->load($wisskiIndividualId);
        $this->wisskiDoiActions->getStaticDoi($wisskiIndividual, $doiMetaData);
        $this->configFactory->getEditable(static::INDIVIDUALS_IN_BATCH)
          ->set('wisskiIndividualsToProcess', $this->wisskiIndividualsBatch)
          ->save();
      }
      $this->configFactory->getEditable(static::INDIVIDUALS_IN_BATCH)->delete();
    }

    // Redirect to batch overview.
    $form_state->setRedirect(
      'entity.wisski_bundle.doi_batch', ['wisski_bundle' => $this->wisskiBundleId]
     );
  }

}
