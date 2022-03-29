<?php

namespace Drupal\wisski_doi\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\wisski_salz\AdapterHelper;

/**
 * Provides a form for reverting a wisski_individual revision.
 *
 * @internal
 */
class WisskiDoiConfirmFormRequestDoiForRevision extends WisskiDoiConfirmFormRequestDoiForStaticRevision {

  /**
   * The DOI data.
   *
   * @var array $doiInfo
   */

  /**
   * Validate if a DOI for a current revision exists.
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
    $rows = $this->wisskiDoiDbActions->readDoiRecords($form_state->getValue('entityId'));
    $continue = TRUE;
    foreach ($rows as $row => $key) {
      if ($key['isCurrent']) {
        $form_state->setErrorByName('entityId', $this->t('You have already a DOI for the current revision! If you want to assign a DOI to a static revision, return to DOI Administration page and click "Get DOI for static state".'));
        $continue = FALSE;
        break;
      }
    }
    return $continue;
  }

  /**
   * The machine name of the form.
   *
   * @return string
   *   The form id.
   */
  public function getFormId() {
    return 'wisski_doi_request_form_for_current_revision';
  }

  /**
   * The question of the confirm form.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The confirmation questions.
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to request a findable DOI for the current and changeable state of the dataset?');
  }

  /**
   * Text on the submit button.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The submit button text.
   */
  public function getConfirmText() {
    return $this->t('Request DOI for current state');
  }

  /**
   * Details between title and body.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description texts.
   */
  public function getDescription() {
    return $this->t('This assigns a DOI to the current and possibly changing state of the dataset.
    The DOI points always to last available revision, you can change the data of the
    dataset afterwards. If you like to assign a DOI which points
    always to the same state of the dataset, please use "Get DOI for static state".');
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
   * @throws \Exception
   *   Error if WissKI entity URI could not be loaded (?).
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Get new values from form state.
    $doiMetaData = $form_state->cleanValues()->getValues();

    // Request DOI for current revision.
    \Drupal::service('wisski_doi.wisski_doi_actions')->getCurrentDoi($this->wisski_individual, $doiMetaData);

    // Redirect to DOI administration.
    $form_state->setRedirect(
      'wisski_individual.doi.administration', ['wisski_individual' => $this->wisski_individual->id()]
    );
  }


}
