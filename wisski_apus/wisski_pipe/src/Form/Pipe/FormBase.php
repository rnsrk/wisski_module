<?php

/**
 * @file
 * Contains \Drupal\wisski_pipe\Form\Pipe\FormBase.
 */

namespace Drupal\wisski_pipe\Form\Pipe;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Base form for pipe add and edit forms.
 */
abstract class FormBase extends EntityForm {

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\wisski_pipe\PipeInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pipe Name'),
      '#default_value' => $this->entity->label(),
      '#description' => $this->t('The human-readable name of this pipe. This name must be unique.'),
      '#required' => TRUE,
      '#size' => 30,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => ['\Drupal\wisski_pipe\Entity\Pipe', 'load']
      ],
      '#disabled' => !$this->entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->entity->getDescription(),
      '#description' => $this->t('The text will be displayed on the <em>pipe collection</em> page.'),
    ];

    $form['additional_settings'] = array(
      '#type' => 'vertical_tabs',
      '#weight' => 99,
    );

    return parent::form($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $wisski_pipe = $this->entity;

    // Prevent leading and trailing spaces in labels.
    $wisski_pipe->set('label', trim($wisski_pipe->label()));

    $status = $wisski_pipe->save();
    // TODO: Drupal Rector Notice: Please delete the following comment after you've made any necessary changes.
    // Please confirm that `$entity` is an instance of `\Drupal\Core\Entity\EntityInterface`. Only the method name and not the class name was checked for this replacement, so this may be a false positive.
    $edit_link = $this->entity->toLink($this->t('Edit'))->toString();
    switch ($status) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('Created new pipe %label.', ['%label' => $wisski_pipe->label()]));
        $this->logger('wisski_pipe')->notice('Created new pipe %label.', ['%label' => $wisski_pipe->label(), 'link' => $edit_link]);
        $form_state->setRedirect('wisski_pipe.processors', [
          'wisski_pipe' => $wisski_pipe->id(),
        ]);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('Updated pipe %label.', ['%label' => $wisski_pipe->label()]));
        $this->logger('wisski_pipe')->notice('Updated pipe %label.', ['%label' => $wisski_pipe->label(), 'link' => $edit_link]);
        // TODO: Drupal Rector Notice: Please delete the following comment after you've made any necessary changes.
        // Please confirm that `$wisski_pipe` is an instance of `Drupal\Core\Entity\EntityInterface`. Only the method name and not the class name was checked for this replacement, so this may be a false positive.
        $form_state->setRedirectUrl($wisski_pipe->toUrl('edit-form'));
        break;
    }
  }

}
