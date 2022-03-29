<?php

namespace Drupal\wisski_core\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;

class WisskiTitlePatternDeleteForm extends EntityConfirmFormBase {

  public function getQuestion() {
    $bundle = $this->entity;
    return $this->t('Do you really want to delete the title pattern for %label (bundle %bundle)?',array('%label'=>$bundle->label(),'%bundle'=>$bundle->id()));
  }
  
  public function getCancelUrl() {
    $bundle = $this->entity;
    // TODO: Drupal Rector Notice: Please delete the following comment after you've made any necessary changes.
    // Please confirm that `$bundle` is an instance of `Drupal\Core\Entity\EntityInterface`. Only the method name and not the class name was checked for this replacement, so this may be a false positive.
    return $bundle->toUrl('title-form');
  }
  
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $bundle = $this->entity;
    $bundle->removeTitlePattern();
    $bundle->save();
    $this->messenger()->addStatus(t('Removed title pattern for bundle %name.', array('%name' => $bundle->label())));
    // TODO: Drupal Rector Notice: Please delete the following comment after you've made any necessary changes.
    // Please confirm that `$bundle` is an instance of `Drupal\Core\Entity\EntityInterface`. Only the method name and not the class name was checked for this replacement, so this may be a false positive.
    $form_state->setRedirectUrl($bundle->toUrl('edit-form'));
  }
}