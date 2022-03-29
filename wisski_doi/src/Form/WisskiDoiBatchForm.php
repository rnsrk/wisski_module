<?php

namespace Drupal\wisski_doi\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManager;
use Drupal\wisski_doi\WisskiDoiDbActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller to render DOI batch table.
 */
class WisskiDoiBatchForm extends ConfigFormBase {


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
   * The service to interact with the database.
   *
   * @var \Drupal\wisski_doi\WisskiDoiDbActions
   */
  private WisskiDoiDbActions $wisskiDoiDbActions;

  /**
   * The service to interact with the database.
   *
   * @var \Drupal\Core\Pager\PagerManager
   */
  private PagerManager $pagerManager;

  /**
   * The WissKI bundle id.
   *
   * @var string
   */
  private string $wisskiBundleId;

  private $batchStateStaticRevisions;

  /**
   * Construct the WisskiDoiAdministration class.
   */
  public function __construct(WisskiDoiDbActions $wisskiDOiDbActions, PagerManager $pagerManager) {
    $this->wisskiDoiDbActions = $wisskiDOiDbActions;
    $this->pagerManager = $pagerManager;
    parent::__construct($this->configFactory());
  }

  /**
   * Get the services from the container.
   */
  public static function create(ContainerInterface $container) {
    $wisskiDOiDbActions = $container->get('wisski_doi.wisski_doi_db_actions');
    $pagerManager = $container->get('pager.manager');
    return new static($wisskiDOiDbActions, $pagerManager);
  }

  /**
   * The machine name of the form.
   */
  public function getFormId() {
    return 'wisski_doi_batch_form';
  }

  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames() {
    return [
      static::SELECTED_INDIVIDUALS,
    ];
  }

  /**
   * The machine name of the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $wisski_bundle = NULL) {
    $this->messenger()->addStatus($this
        ->t('To receive DOIs for all Entities just click
        "Get DOIs for current revisions" or
        "Get DOIs for static revisions"
        without check any individual.'));
    $this->wisskiBundleId = $wisski_bundle;
    $this->config(static::SELECTED_INDIVIDUALS);
    $records = $this->wisskiDoiDbActions->readBundleRecords($wisski_bundle);
    $chunk = $this->pagerArray($records, 25);
    foreach ([0, 1] as $isCurrent) {
      $this->doiAnnotation($chunk, $isCurrent);
    }

    // Read state of batch process.
    $this->batchStateStaticRevisions = $this->configFactory->getEditable(static::INDIVIDUALS_IN_BATCH)
      ->get('wisskiIndividualsToProcess');

    empty($this->batchStateStaticRevisions) ?: $this->messenger()
      ->addWarning($this->t('There are %count unpocessed WissKI individuals,
      if you want to finish you batch process, click "Get remaining DOIs",
      otherwise click "Reset batch state"', ['%count' => count($this->batchStateStaticRevisions)]));

    // Build form.
    $form['table'] = [
      '#type' => 'tableselect',
      '#header' => [
        'eid' => $this->t('EID'),
        'label' => $this->t('Label'),
        'link' => $this->t('Link'),
        'currentDoi' => $this->t('Current DOI'),
        'latestStaticDoi' => $this->t('Latest Static DOI'),
      ],
      '#options' => $chunk,
      '#empty' => $this
        ->t('No entities found.'),
    ];
    $form['pager'] = [
      '#type' => 'pager',
      '#attributes' => ['class' => 'wisski-doi-pager'],
    ];

    if ($this->batchStateStaticRevisions) {
      $form['actions']['eraseBatch'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reset batch state'),
        '#button_type' => 'danger',
        '#submit' => [[$this->configFactory->getEditable(static::INDIVIDUALS_IN_BATCH),
          'delete',
        ],
        ],
      ];
      $submitFormToGetDois4StaticText = $submitFormToGetDois4CurrentText = $this->t('Get remaining DOIs');
    }
    else {
      $submitFormToGetDois4StaticText = $this->t('Get DOIs for static revisions');
      $submitFormToGetDois4CurrentText = $this->t('Get DOIs for current revisions');
    }
    $form['actions']['submitFormToGetDois4Current'] = [
      '#type' => 'submit',
      '#value' => $submitFormToGetDois4CurrentText,
      '#button_type' => 'primary',
      '#submit' => [[$this, 'submitFormToGetDois4Current']],
    ];

    $form['actions']['submitFormToGetDois4Static'] = [
      '#type' => 'submit',
      '#value' => $submitFormToGetDois4StaticText,
      '#button_type' => 'primary',
      '#submit' => [[$this, 'submitFormToGetDois4Static']],
    ];

    return $form;
  }

  /**
   * Redirect to batch service to get DOIs for static revisions.
   */
  public function submitFormToGetDois4Static(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable(static::SELECTED_INDIVIDUALS)
      // Set the submitted configuration setting.
      ->set('wisskiIndividuals', $form_state->getValue('table'))
      ->save();

    $form_state->setRedirect(
      'wisski_individual.doi.batch_for_static_revisions', ['wisskiBundleId' => $this->wisskiBundleId]
    );
  }

  /**
   * Redirect to batch service to get DOIs for current revisions.
   */
  public function submitFormToGetDois4Current(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable(static::SELECTED_INDIVIDUALS)
      // Set the submitted configuration setting.
      ->set('wisskiIndividuals', $form_state->getValue('table'))
      ->save();

    $form_state->setRedirect(
      'wisski_individual.doi.batch_for_current_revisions', ['wisskiBundleId' => $this->wisskiBundleId]
    );
  }

  /**
   * Returns pager array.
   *
   * @param array $items
   *   All records to render.
   * @param int $itemsPerPage
   *   The page limits.
   *
   * @return array
   *   The chunk to render.
   */
  public function pagerArray(array $items, int $itemsPerPage) {
    // Get total items count.
    $total = count($items);
    // Get the number of the current page.
    $currentPage = $this->pagerManager->createPager($total, $itemsPerPage)
      ->getCurrentPage();
    // Split an array into chunks.
    $chunk = array_chunk($items, $itemsPerPage, TRUE);
    // Return current group item.
    return $chunk[$currentPage];
  }

  /**
   * Annotate the chunk with DOI data.
   *
   * @param array $chunk
   *   The chunk REFERENCE to render.
   * @param int $isCurrent
   *   Flag, if we are looking for DOIs for static (0) or current (1) revision.
   */
  public function doiAnnotation(array &$chunk, int $isCurrent) {
    foreach ($chunk as $record) {
      $cssClass = $isCurrent ? 'current' : 'latest-static';
      $key = $isCurrent ? 'currentDoi' : 'latestStaticDoi';
      $doiRecords = $this->wisskiDoiDbActions->readLatestDoiRecords($record['eid'], $isCurrent);
      if ($doiRecords) {
        $doiLink = 'https://doi.org/' . $doiRecords['doi'];
        $chunk[$record['eid']][$key] = [
          'data' => $this->t('<span><a href=":doiLink" class="wisski-:currentFlag-doi-link">:doiLink</a> (:state) created %created</span>', [
            ':doiLink' => $doiLink,
            ':currentFlag' => $cssClass,
            ':state' => $doiRecords['state'],
            '%created' => date('d.M.Y H:i:s', strtotime($doiRecords['created'])),
          ]),
        ];
      }
      else {
        $chunk[$record['eid']][$key] = 'No DOI assigned';
      }
    }
  }

}
