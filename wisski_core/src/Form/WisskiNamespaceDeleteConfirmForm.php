<?php

/**
 * @file
 * Contains \Drupal\wisski_pipe\Form\Processor\DeleteForm.
 */

namespace Drupal\wisski_core\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\wisski_core\WisskiNameSpaceOperator;
use Drupal\Core\Database\Connection;

/**
 * Provides a form to remove a processor from a pipe.
 */
class WisskiNamespaceDeleteConfirmForm extends ConfirmFormBase {

   private string $namespace;

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the namespace %namespace?', ['%namespace' => $this->namespace]);
    // return $this->t('Are you sure you want to delete the @plugin processor from the %pipe pipe?', ['%pipe' => $this->pipe->label(), '@plugin' => $this->processor->getLabel()]);
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
    return 'wisski_namespace_delete_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $namespace = NULL) {
    $this->namespace = $namespace;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /*$this->pipe->removeProcessor($this->processor->getUuid());
    $this->pipe->save();

    $this->messenger()->addStatus($this->t('The processor %label has been deleted.', ['%label' => $this->processor->getLabel()]));
    $this->logger('wisski_pipe')->notice('The processor %label has been deleted in the @pipe pipe.', [
      '%label' => $this->processor->getLabel(),
      '@pipe' => $this->pipe->label(),
    ]);*/

    (new WisskiNameSpaceOperator(\Drupal::service('database')))->deleteSingleNamespace($this->namespace);

    $form_state->setRedirect('wisski.wisski_ontology');

  }

}
