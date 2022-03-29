<?php

namespace Drupal\wisski_doi\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
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
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a form for reverting a wisski_individual revision.
 *
 * @internal
 */
class WisskiDoiConfirmFormRequestDoiForStaticRevision extends ConfirmFormBase {

  /**
   * The storage name of the contributor items.
   *
   * @var string
   */
  const CONTRIBUTOR_ITEMS_CONFIG = 'wisski_doi.contributor.items';

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
   * The WissKI Individual.
   *
   * @var \Drupal\wisski_core\WisskiEntityInterface
   */
  protected WisskiEntityInterface $wisski_individual;

  /**
   * All information for the DOI request and write process to wisski_doi table.
   *
   * @var array
   */
  protected array $doiInfo;

  /**
   * The service to management DOI metadata.
   *
   * @var \Drupal\wisski_doi\WisskiDoiActions
   */
  private ?WisskiDoiActions $wisskiDoiActions;

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
   * Constructs a new form to request a DOI for a static revision.
   *
   * @param \Drupal\wisski_core\WisskiStorageInterface $wisski_storage
   *   The WissKI Storage service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\wisski_doi\WisskiDoiDataciteRestActions $wisskiDoiRestActions
   *   The WissKi DOI Rest Service.
   * @param \Drupal\wisski_doi\WisskiDoiDbActions $wisskiDoiDbActions
   *   The WissKI DOI database Service.
   */
  public function __construct(WisskiStorageInterface       $wisski_storage,
                              DateFormatterInterface       $date_formatter,
                              TimeInterface                $time,
                              WisskiDoiDataciteRestActions $wisskiDoiRestActions,
                              WisskiDoiDbActions           $wisskiDoiDbActions) {
    $this->wisskiStorage = $wisski_storage;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
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
    return 'wisski_doi_request_form_for_static_revision';
  }

  /**
   * Storage of the contributor names.
   *
   * @return array
   *   The list of storage items.
   */
  protected function getEditableConfigNames() {
    return [
      'wisski_doi.contributor.items',
    ];
  }

  /**
   * The question of the confirm form.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The confirmation questions.
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to request a findable DOI for this revision?');
  }

  /**
   * Route, if you hit chancel.
   *
   * @return \Drupal\Core\Url
   *   The Chancel URL.
   */
  public function getCancelUrl() {
    return new Url('wisski_individual.doi.administration', ['wisski_individual' => $this->wisski_individual->id()]);
  }

  /**
   * Text on the submit button.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The submit button text.
   */
  public function getConfirmText() {
    return $this->t('Request DOI for this revision');
  }

  /**
   * Details between title and body.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description texts.
   */
  public function getDescription() {
    return $this->t('This saves this revision and assigns a DOI to it.
    The DOI points only to this revision, you can not change the data of the
    dataset afterwards (only the metadata of the DOI). If you like to assign a DOI which points
    always to the current state of the dataset, please use "Get DOI for current state".');
  }

  /**
   * Add a contributor name to the storage.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The contributor list in form of the contributor-list twig template.
   */
  public static function addContributor(array &$form, FormStateInterface $form_state) {
    $contributor = $form_state->getValue('contributors')['contributorGroup']['contributor'];
    $contributorItems = \Drupal::configFactory()
      ->getEditable(static::CONTRIBUTOR_ITEMS_CONFIG);
    $contributors = $contributorItems->get('contributors');
    $error = NULL;

    try {
      // Validate for duplicates or empty list.
      if (!empty($contributor)) {
        if (is_null($contributors)) {
          $contributors = [];
        }
        if (!in_array($contributor, array_column($contributors, 'name'))) {
          $contributors[] = ['name' => $contributor];
        }
        else {
          $error = t('Contributor :contributor already exists in this list', [':contributor' => $contributor]);
        }
      }
      else {
        $error = t('Contributor is empty!');
      }
    }
    catch (\Exception $e) {
      $error = t('Wrong text format. Enter a valid text format.');
    }
    $contributorItems->set('contributors', $contributors)->save();
    // Invoke AJAX.
    $response = new AjaxResponse();
    // Render template with params.
    $response->addCommand(new ReplaceCommand('#contributor-list', WisskiDoiConfirmFormRequestDoiForStaticRevision::renderContributors($contributors, $error)));
    return $response;
  }

  /**
   * Delete the specified contributor.
   *
   * @param string $contributor
   *   Contributor person.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   What is this and why do we use it?
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The contributor list in form of the contributor-list twig template.
   */
  public function removeContributor(string $contributor, Request $request) {
    // Load contributors from storage.
    $contributorItems = \Drupal::configFactory()
      ->getEditable('wisski_doi.contributor.items');
    $contributors = $contributorItems->get('contributors');
    // Remove contributor from list and save.
    if (!is_null($contributors) && ($ind = array_search($contributor, array_column($contributors, 'name'))) !== FALSE) {
      unset($contributors[$ind]);
      $contributorItems->set('contributors', $contributors)->save();
    }
    // Render template with params.
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#contributor-list', WisskiDoiConfirmFormRequestDoiForStaticRevision::renderContributors($contributors)));

    return $response;
  }

  /**
   * Delete all contributors.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   What is this and why do we use it?
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The contributor list in form of the contributor-list twig template.
   */
  public function clearContributors(Request $request) {
    // Load contributors.
    $contributorItems = \Drupal::configFactory()
      ->getEditable('wisski_doi.contributor.items');
    // Reset list.
    $contributorItems->set('contributors', NULL)->save();
    // Render template with params.
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#contributor-list', WisskiDoiConfirmFormRequestDoiForStaticRevision::renderContributors(NULL)));
    return $response;
  }

  /**
   * Renders Contributors template.
   *
   * @param ?array $contributors
   *   The contributors only with name key.
   * @param ?string $error
   *   The error message if any.
   *
   * @return array
   *   The render array for the contributors.
   */
  public static function renderContributors(?array $contributors, ?string $error = NULL) {
    $theme = [
      '#theme' => 'contributor-list',
      '#contributors' => $contributors,
      '#error' => $error,
    ];
    $renderer = \Drupal::service('renderer');

    return $renderer->render($theme);
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
   * @param int $wisski_individual
   *   The WissKI Entity ID.
   *
   * @return array
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $wisski_individual = NULL): array {
    /* #tree will ensure the HTML elements get named distinctively.
     * Not just name=[name] but name=[container][123][name].
     */
    $form['#tree'] = TRUE;

    // Load WissKI entity.
    // @todo Do not use storage load, but parameters or service instead.
    $this->wisski_individual = $this->wisskiStorage->load($wisski_individual);

    // Load existing form data.
    $form = parent::buildForm($form, $form_state);
    $doiSettings = \Drupal::configFactory()
      ->getEditable(WisskiDoiRepositorySettings::DOI_SETTINGS);
    $contributorItems = $this->config(static::CONTRIBUTOR_ITEMS_CONFIG);

    // Get metadata from individual.
    $this->doiInfo = \Drupal::service('wisski_doi.wisski_doi_actions')->getWisskiIndividualMetadata($this->wisski_individual);

    // Assemble parts of DOI information for request.
    $this->doiInfo += [
      "event" => 'draft',
      "contributors" => $contributorItems->get('contributors'),
      "publisher" => $doiSettings->get('doiSettings.data_publisher'),
      "language" => $this->wisski_individual->language()->getId(),
      "resourceType" => 'Dataset',
    ];

    /* Check if there is data from an update,
     * see WisskiDoiUpdateMeta::buildForm().
     */
    $this->doiInfo = !empty($form_state->get('doiInfo')) ? $form_state->get('doiInfo') : $this->doiInfo;

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

    /* Create form elements
     * Contributors are nested and get populated with AJAX functions and
     * a template @file contributor-list.html.twig
     */
    $form['entityId'] = [
      '#type' => 'item',
      '#value' => $this->doiInfo['entityId'],
      '#markup' => $this->doiInfo['entityId'],
      '#title' => $this->t('Entity ID'),
      '#description' => $this->t('The entity ID of the WissKI individual.'),
    ];
    $form['creationDate'] = [
      '#type' => 'item',
      '#value' => $this->doiInfo['creationDate'],
      '#title' => $this->t('Creation date'),
      '#markup' => $this->doiInfo['creationDate'],
      '#description' => $this->t('The datetime, when the revision was created.'),
    ];

    $form['event'] = [
      '#type' => 'select',
      '#title' => $this->t('Event'),
      '#options' => $doiEvents,
      '#default_value' => $this->doiInfo['event'],
      '#description' => $this->t('The event for the DOI. If you register or publish the DOI, it can not be deleted!'),
    ];

    $form['author'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Author'),
      '#default_value' => $this->doiInfo['author'],
      '#description' => $this->t('The author of the selected revision.'),
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
        'callback' => '::addContributor',
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

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $this->doiInfo['title'],
      '#description' => $this->t('The title, resolved from title pattern.'),
    ];
    $form['publisher'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Publisher'),
      '#default_value' => $this->doiInfo['publisher'],
      '#description' => $this->t('The publisher of the database.'),
    ];
    $form['language'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Language'),
      '#default_value' => $this->doiInfo['language'],
      '#description' => $this->t('The language of the dataset.'),
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
   * Save to revisions and request a DOI for one.
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
   * @throws \Exception
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Get new values from form state.
    $newVals = $form_state->cleanValues()->getValues();
    $this->doiInfo = $newVals;

    // Get AJAX info.
    $contributorItems = \Drupal::configFactory()
      ->getEditable('wisski_doi.contributor.items');
    // Have to overwrite contributors cause AJAX mess up the form_state.
    $this->doiInfo['contributors'] = $contributorItems->get('contributors');
    /*
     * Save two revisions, because current revision has no
     * revision URI. Start with first save process.
     */
    $doiRevision = $this->wisskiStorage->createRevision($this->wisski_individual);
    $doiRevision->setNewRevision(TRUE);
    $doiRevision->revision_log = $this->t('DOI revision requested at %request_date.', [
      '%request_date' => $this->dateFormatter->format($this->time->getCurrentTime(), 'custom', 'd.m.Y H:i:s'),
    ]);
    $doiRevision->save();
    // Assemble revision URL and store it in form.
    $http = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $doiRevisionId = $doiRevision->getRevisionId();
    $link = new Url('entity.wisski_individual.revision', [
      'wisski_individual' => $this->doiInfo['entityId'],
      'wisski_individual_revision' => $doiRevisionId,
    ]);
    $doiRevisionURL = $http . $_SERVER['HTTP_HOST'] . $link->toString();

    // Append revision info to doiInfo.
    $this->doiInfo += [
      "revisionId" => $doiRevisionId,
      "revisionUrl" => $doiRevisionURL,
    ];

    // Request DOI.
    $response = $this->wisskiDoiRestActions->createOrUpdateDoi($this->doiInfo);
    // Safe to db if successfully.
    $response['responseStatus'] == 201 ? $this->wisskiDoiDbActions->writeToDb($response['dbData']) : \Drupal::service('messenger')->addError($this->t('%responseStatus', ['%responseStatus' => $response['responseStatus']]));

    // Start second save process. This is the current revision now.
    $doiRevision = $this->wisskiStorage->createRevision($this->wisski_individual);
    $doiRevision->revision_log = $this->t('Revision copy, because of DOI request from %request_date.', [
      '%request_date' => $this->dateFormatter->format($this->time->getCurrentTime(), 'custom', 'd.m.Y H:i:s'),
    ],
    );
    $doiRevision->save();

    // Redirect to version history.
    $form_state->setRedirect(
      'wisski_individual.doi.administration', ['wisski_individual' => $this->wisski_individual->id()]
    );
  }

}
