<?php

namespace Drupal\wisski_doi;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\user\Entity\User;
use Drupal\wisski_core\WisskiEntityInterface;
use Drupal\wisski_core\WisskiStorage;
use Drupal\wisski_doi\Form\WisskiDoiConfirmFormRequestDoiForStaticRevision;
use Drupal\wisski_salz\AdapterHelper;

/**
 * Controller for DOI actions.
 */
class WisskiDoiActions implements WisskiDoiActionsInterface {
  use StringTranslationTrait;
  /**
   * The WisskiEntity revision.
   *
   * @var \Drupal\wisski_core\WisskiEntityInterface
   */
  protected WisskiEntityInterface $revision;

  /**
   * The WissKI storage.
   *
   * @var \Drupal\wisski_core\WisskiStorage
   */
  protected WisskiStorage $wisskiStorage;

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
  private WisskiDoiActions $wisskiDoiActions;

  /**
   * The service to interact with the REST API .
   *
   * @var \Drupal\wisski_doi\WisskiDoiRestActionsInterface
   */
  protected WisskiDoiRestActionsInterface $wisskiDoiRestActions;

  /**
   * The service to interact with the database.
   *
   * @var \Drupal\wisski_doi\WisskiDoiDbActionsInterface
   */
  protected WisskiDoiDbActionsInterface $wisskiDoiDbActions;

  /**
   * {@inheritDoc}
   */
  public function __construct(TranslationInterface $stringTranslation,
                              DateFormatterInterface $date_formatter,
                              TimeInterface $time,
                              WisskiDoiRestActionsInterface $wisskiDoiRestActions,
                              WisskiDoiDbActionsInterface $wisskiDoiDbActions,
                              EntityTypeManagerInterface $entityTypeManager
                              ) {
    $this->stringTranslation = $stringTranslation;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->wisskiDoiRestActions = $wisskiDoiRestActions;
    $this->wisskiDoiDbActions = $wisskiDoiDbActions;
    $this->wisskiStorage = $entityTypeManager->getStorage('wisski_individual');
  }

  /**
   * {@inheritDoc}
   */
  public function getWisskiIndividualMetadata(WisskiEntityInterface $wisskiIndividual) {
    $revisionUser = $wisskiIndividual->getRevisionUser();
    if (!empty($revisionUser)) {
      $author = $revisionUser->getDisplayName();
    }
    elseif (isset($wisskiIndividual->get('uid')->getValue()[0]['target_id'])) {
      $uid = $wisskiIndividual->get('uid')->getValue()[0]['target_id'];
      $author = User::load($uid)->getDisplayName();
    }
    else {
      $author = ':unkn';
    }
    return [
      "bundleId" => $wisskiIndividual->bundle(),
      'entityId' => $wisskiIndividual->id(),
      'author' => $author,
      'title' => $wisskiIndividual->label(),
      'creationDate' => date('d.m.Y H:i:s', $wisskiIndividual->getRevisionCreationTime()),
      'language' => $wisskiIndividual->language()->getId(),
    ];
  }

  /**
   * Requests a DOI for a static revision.
   *
   * Saves two revisions, to yield a static revision,
   * requests a DOI for that revision and saves DOI data
   * to local database.
   *
   * @param \Drupal\wisski_core\WisskiEntityInterface $wisskiIndividual
   *   The WissKI individual.
   * @param array $doiMetadata
   *   The DOI metadata.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function getStaticDoi(WisskiEntityInterface $wisskiIndividual, array $doiMetadata) {

    // Load metadata of WissKI individual.
    $wisskiIndividualMetaData = $this->getWisskiIndividualMetadata($wisskiIndividual);

    // Assemble DOI metadata with WissKI individual metadata.
    $doiMetadata += $wisskiIndividualMetaData;

    /*
     * Save two revisions, because current revision has no
     * revision URI. Start with first save process.
     */
    $doiRevision = $this->wisskiStorage->createRevision($wisskiIndividual);
    $doiRevision->setNewRevision(TRUE);
    $doiRevision->revision_log = $this->t('DOI revision requested at %request_date.', [
      '%request_date' => $this->dateFormatter->format($this->time->getCurrentTime(), 'custom', 'd.m.Y H:i:s'),
    ]);
    $doiRevision->save();

    // Assemble revision URL and store it in form.
    $http = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $doiRevisionId = $doiRevision->getRevisionId();
    $doiRevisionURL = $http . $_SERVER['HTTP_HOST'] . '/wisski/navigate/' . $wisskiIndividual->id() . '/revisions/' . $doiRevisionId . '/view';

    // Append revision info to doiInfo.
    $doiMetadata += [
      "revisionId" => $doiRevisionId,
      "revisionUrl" => $doiRevisionURL,
    ];

    // Request DOI.
    $response = $this->wisskiDoiRestActions->createOrUpdateDoi($doiMetadata);

    // Safe to db if successfully.
    $response['responseStatus'] == 201 ? $this->wisskiDoiDbActions->writeToDb($response['dbData']) : \Drupal::service('messenger')->addError($this->t('%responseStatus', ['%responseStatus' => $response['responseStatus']]));
    // Start second save process. This is the current revision now.
    $doiRevision = $this->wisskiStorage->createRevision($wisskiIndividual);
    $doiRevision->revision_log = $this->t('Revision copy, because of DOI request from %request_date.', [
      '%request_date' => $this->dateFormatter->format($this->time->getCurrentTime(), 'custom', 'd.m.Y H:i:s'),
    ]);
    $doiRevision->save();
  }

  /**
   * Requests a DOI for a Current revision.
   *
   * Just requests a DOI for the current revision and saves DOI data
   * to local database.
   *
   * @param \Drupal\wisski_core\WisskiEntityInterface $wisskiIndividual
   *   The WissKI individual.
   * @param array $doiMetadata
   *   The DOI metadata.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function getCurrentDoi(WisskiEntityInterface $wisskiIndividual, array $doiMetadata) {
    // Load metadata of WissKI individual.
    $wisskiIndividualMetaData = $this->getWisskiIndividualMetadata($wisskiIndividual);

    // Assemble DOI metadata with WissKI individual metadata.
    $doiMetadata += $wisskiIndividualMetaData;

    // Get WissKI entity URI.
    $target_uri = AdapterHelper::getOnlyOneUriPerAdapterForDrupalId($wisskiIndividual->id());
    $target_uri = current($target_uri);
    $doiMetadata += [
      "entityUri" => $target_uri,
    ];

    // Get AJAX info.
    $contributorItems = \Drupal::configFactory()
      ->getEditable(WisskiDoiConfirmFormRequestDoiForStaticRevision::CONTRIBUTOR_ITEMS_CONFIG);
    // Have to overwrite contributors cause AJAX mess up the form_state.
    $doiMetadata['contributors'] = $contributorItems->get('contributors');

    /*
     * No need to save a revision, because the revisionUrl points to the
     * resolver with the entity URI and not to a "real" revision URL, like
     * http://{domain}/wisski/navigate/{entity_id}/revisions/{revision_id}/view
     */

    // Assemble revision URL and store it in form.
    $http = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $doiCurrentRevisionURL = $http . $_SERVER['HTTP_HOST'] . '/wisski/get?uri=' . $doiMetadata["entityUri"];

    // Append revision info to doiInfo.
    $doiMetadata += [
      "revisionUrl" => $doiCurrentRevisionURL,
    ];
    // Request DOI.
    $response = $this->wisskiDoiRestActions->createOrUpdateDoi($doiMetadata);
    // Write response to database.
    $response['responseStatus'] == 201 ? $this->wisskiDoiDbActions->writeToDb($response['dbData']) : \Drupal::service('messenger')->addError($this->t('%responseStatus', ['%responseStatus' => $response['responseStatus']]));
  }

  /**
   * Loops over all selected WissKI and requests DOIs.
   *
   * @param bool $current
   *   DOI for current or static revision.
   * @param array $wisskiIndividualsBatch
   *   The batch set with individuals that should get a DOI.
   * @param array $doiMetaData
   *   The metadata for the DOI.
   * @param string $individualsInBatchStateName
   *   The name of the config store.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function batchLoop(bool $current, array $wisskiIndividualsBatch, array $doiMetaData, string $individualsInBatchStateName) {
    if ($wisskiIndividualsBatch) {
      $skipper = 0;
      foreach ($wisskiIndividualsBatch as $wisskiIndividualId) {
        // Leave if there is a DOI pointing to current revision.
        $currentDoi = $this->wisskiDoiDbActions->readDoiRecords($wisskiIndividualId, NULL, 1);
        if (!empty($currentDoi)) {
          $skipper += 1;
          continue;
        }
        $wisskiIndividual = $this->wisskiStorage->load($wisskiIndividualId);
        $current ? $this->getCurrentDoi($wisskiIndividual, $doiMetaData) : $this->getStaticDoi($wisskiIndividual, $doiMetaData);
        unset($wisskiIndividualsBatch[$wisskiIndividualId]);
        \Drupal::configFactory()->getEditable($individualsInBatchStateName)
          ->set('wisskiIndividualsToProcess', $wisskiIndividualsBatch)
          ->save();
      }
      \Drupal::configFactory()->getEditable($individualsInBatchStateName)->delete();
      \Drupal::messenger()->addWarning($this->t('%skipper DOI request skipped, DOI(s) for current revision exist(s) already.', ['%skipper' => $skipper]));
    }
  }

}
