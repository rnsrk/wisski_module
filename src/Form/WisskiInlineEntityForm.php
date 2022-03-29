<?php

namespace Drupal\wisski_core\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Render\Element;
use Drupal\inline_entity_form\InlineFormInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\inline_entity_form\Form\EntityInlineForm;
use Drupal\wisski_core\WisskiCacheHelper;


/**
 * Generic entity inline form handler.
 */
class WisskiInlineEntityForm extends EntityInlineForm {

  /**
   * {@inheritdoc}
   */
  public function entityFormValidate(array &$entity_form, FormStateInterface $form_state) {

#    dpm(" I am here!!");
    $values = $form_state->getCompleteForm();
#    $values = $form_state->getFormObject();

    if(isset($entity_form['#default_value'])) {
      $entity = $entity_form['#default_value'];

      $entity_id = $entity->id();
      $bundle_id = $entity->bundle();
    
      if(!empty($entity_id) && !empty($bundle_id)) {
        WisskiCacheHelper::putCallingBundle($entity_id,$bundle_id);
      }
    
    }
    
    parent::entityFormValidate($entity_form, $form_state);
  }


}
