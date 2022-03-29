<?php

namespace Drupal\wisski_core\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\wisski_core\WisskiNameSpaceOperator;
use Drupal\Core\Database\Connection;

/**
 * Provides a form to remove a processor from a pipe.
 */
class WisskiNamespaceEditConfirmForm extends ConfirmFormBase {

   private string $namespace;
   private string $new_shortname;

  /**
   * {@inheritdoc}
   */
   public function getQuestion() {
    return $this->t('Are you sure you want to edit the namespace %namespace?', ['%namespace' => $this->namespace]);
   }

     /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $continue = TRUE;

    // $hasNoWhitespace    = false === strpos($form_state->getValue('edit_namespace'), ' ');
    // dpm($hasNoWhitespace);
    if(!preg_match('/^\w+$/', $form_state->getValue('edit_namespace'))){
        $continue = FALSE;
        $form_state->setErrorByName('edit_namespace', $this->t('Namespace may not contain non-alphanumeric characters (spaces, special characters,...).'));
    }
  }


  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('wisski.wisski_ontology');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wisski_namespace_edit_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $namespace = NULL) {
    $this->namespace = $namespace;

    $form['edit_namespace'] = array(
        '#type' => 'textfield',
        '#title' => "New short name for <em>$namespace</em>:",
        '#description' => 'Please type in the new short name.',
      );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
   
    $this->new_shortname = $form_state->getValue('edit_namespace');

    (new WisskiNameSpaceOperator(\Drupal::service('database')))->editSingleNamespace($this->namespace, $this->new_shortname);

    $form_state->setRedirect('wisski.wisski_ontology');

  }

}
