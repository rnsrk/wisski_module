<?php

namespace Drupal\wisski_doi\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wisski_salz\AdapterHelper;

/**
 * Provides a form for reverting a wisski_individual revision.
 */
class WisskiDoiConfirmFormUpdateMetadata extends WisskiDoiConfirmFormRequestDoiForStaticRevision {

  /**
   * The DOI record from wisski_doi table.
   *
   * @var array
   */
  protected array $dbRecord;

  /**
   * The machine name of the form.
   *
   * @return string
   *   The form id.
   */
  public function getFormId() {
    return 'wisski_doi_edit_form_for_doi_metadata';
  }

  /**
   * The question of the confirm form.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The confirmation questions.
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Make your changes!');
  }

  /**
   * Text on the submit button.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The submit button text.
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Update DOI metadata');
  }

  /**
   * Details between title and body.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description texts.
   */
  public function getDescription() {
    return $this->t('This updates the DOI metadata at your provider.
    It will NOT change the dataset in WissKI.</br> <em>Field values from drafts and registered DOIs cannot be retrieved online and are populated with local values. Therefore, they may differ from the online data!</em>');
  }

  /**
   * Validate if event is suitable.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   Can we continue?
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $continue = TRUE;
    if ($form_state->getValue('event') == 'draft' && in_array($this->dbRecord['state'], [
      // A registered or findable DOI can not go back to draft.
      'registered', 'findable',
    ])) {
      $form_state->setErrorByName('entityId', $this->t('You can not change a registered or findable DOI back to draft, sorry. Pick a suitable state'));
      $continue = FALSE;
    }
    elseif ($form_state->getValue('event') == 'hide' && $this->dbRecord['state'] != 'findable') {
      // Only findable can be hided.
      $form_state->setErrorByName('entityId', $this->t('You can not hide a draft or a registered DOI, sorry. Pick a suitable state'));
      $continue = FALSE;
    }
    return $continue;
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
   * @param ?int $wisski_individual
   *   The WissKI entity id.
   * @param ?int $did
   *   The internal DOI id in wisski_doi table.
   *
   * @return array
   *   The return form.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   Error if WissKI entity URI could not be loaded (?).
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $wisski_individual = NULL, ?int $did = NULL): array {
    $this->dbRecord = $this->wisskiDoiDbActions->readDoiRecords($wisski_individual, $did)[0];
    if ($this->dbRecord['state'] == 'findable') {
      $doiInfo = $this->wisskiDoiRestActions->readMetadata($this->dbRecord['doi']);
      $form_state->set('doiInfo', [
        "entityId" => $wisski_individual,
        "creationDate" => $doiInfo['data']['attributes']['dates'][0]['dateInformation'],
        "event" => 'publish',
        "author" => $doiInfo['data']['attributes']['creators'][0]['name'],
        "contributors" => $doiInfo['data']['attributes']['contributors'],
        "title" => $doiInfo['data']['attributes']['titles'][0]['title'],
        "publisher" => $doiInfo['data']['attributes']['publisher'],
        "language" => $doiInfo['data']['attributes']['language'],
        "resourceType" => $doiInfo['data']['attributes']['types']['resourceTypeGeneral'],
      ]);
    }

    return parent::buildForm($form, $form_state, $wisski_individual);
  }

  /**
   * The submit action.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @throws \Exception
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Get new values from form state.
    $doiInfo = $form_state->cleanValues()->getValues();
    // Get DOI.
    $doiInfo += [
      "doi" => $this->dbRecord['doi'],
    ];

    // Contributors have to be received extra.
    $contributorItems = \Drupal::configFactory()
      ->getEditable('wisski_doi.contributor.items');
    $doiInfo['contributors'] = $contributorItems->get('contributors');

    // Get WissKI entity URI.
    $target_uri = AdapterHelper::getOnlyOneUriPerAdapterForDrupalId($this->wisski_individual->id());
    $target_uri = current($target_uri);
    $doiInfo += [
      "entityUri" => $target_uri,
    ];

    /*
     * No need to save a revision, because the revisionUrl points to the
     * resolver with the entity URI and not to a "real" revision URL, like
     * http://{domain}/wisski/navigate/{entity_id}/revisions/{revision_id}/view
     */

    // Assemble revision URL and store it in form.
    $http = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $doiCurrentRevisionURL = $http . $_SERVER['HTTP_HOST'] . '/wisski/get?uri=' . $doiInfo["entityUri"];

    // Append revision info to doiInfo.
    $doiInfo += [
      "revisionUrl" => $doiCurrentRevisionURL,
    ];
    // Request DOI.
    $response = $this->wisskiDoiRestActions->createOrUpdateDoi($doiInfo, TRUE);
    // Write response to database.
    $response['responseStatus'] == 200 ? $this->wisskiDoiDbActions->updateDbRecord($response['dbData']['state'], intval($this->dbRecord['did'])) : \Drupal::logger('wisski_doi')
      ->error($this->t('Something went wrong Updating the DOI. Leave the database untouched'));
    // Redirect to DOI administration.
    $form_state->setRedirect(
      'wisski_individual.doi.administration', ['wisski_individual' => $this->wisski_individual->id()]
    );
  }

}
