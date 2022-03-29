<?php

namespace Drupal\wisski_core\Form;

//use \Drupal\Core\Entity\ContentEntityForm;
use \Drupal\Core\Form\FormStateInterface;
use \Drupal\Core\Entity\ContentEntityForm;
use \Drupal\wisski_core;
use \Drupal\wisski_salz\AdapterHelper;
use Drupal\wisski_core\Entity\WisskiBundle;

class WisskiEntityForm extends ContentEntityForm {

  public function buildForm(array $form, FormStateInterface $form_state) {

    $form = parent::buildForm($form,$form_state);
    
    if(!$this->entity->isNew()) {
      $form['#title'] = $this->t('Edit').' ' . $this->getEntity()->label(); //.wisski_core_generate_title($this->entity,NULL,TRUE);
    } else {
      $bundleid = $this->getEntity()->get('bundle')->getValue()[0]['target_id'];
      $bundle = WisskiBundle::load($bundleid);
      $form['#title'] = $this->t('Create new').' ' . $bundle->label();
    }
    // this code here is evil!!!
    // whenever you have subentities (referenced by entity reference)
    // no new ids are generated because it takes the oldest one in store (e.g. 12) and simply adds one (= 13).
    // this is nonsense because it does this for all new ones, so everything is 13 after this.
    // We really don't know the id we will be getting - so stop this here!
    /*
    if (empty($this->entity->id())) {
      $fresh_id = AdapterHelper::getFreshDrupalId();
      $this->entity->set('eid',$fresh_id);
      //dpm($this->entity->id(),'set '.$fresh_id);
    }
    */

#    dpm($form,__METHOD__);    
    $this->entity->saveOriginalValues($this->entityTypeManager->getStorage('wisski_individual'));

    //@TODO extend form
    //dpm($this->getEntity());
#    dpm($form,__METHOD__);
    return $form;
  }

   /**
   * {@inheritdoc}
   *
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
#    dpm("validate for wisski called!!");
    parent::validateForm($form, $form_state);
  }

  public function save(array $form, FormStateInterface $form_state) {
#    dpm($form, "form");
#    dpm($form_state, "fs");
    $entity = $this->getEntity();
        
    $this->copyFormValuesToEntity($entity,$form,$form_state);
    $entity->save();
    $bundleid = $entity->get('bundle')->getValue()[0]['target_id'];
    $drupalid = $entity->id();
#    $drupalid = AdapterHelper::getDrupalIdForUri($entity->id());
#    dpm($bundle,__METHOD__);
    $form_state->setRedirect(
      'entity.wisski_individual.canonical', 
#      'entity.wisski_individual.view', 
      array(
        'wisski_bundle' => $bundleid,
        'wisski_individual' => $drupalid,
      )
    );
  }
  
}
