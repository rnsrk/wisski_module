<?php

namespace Drupal\wisski_mirador\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;


class WisskiMiradorApiController extends ControllerBase {

  // public variables
  // @TODO: Make these dynamic in the interface.
  public $entity_type = NULL;
  public $bundle = NULL;
  public $field_to_entity = NULL;
  public $field_json = NULL;
  public $field_id = NULL;

  public function getLocalvarsFromSession() {
    $session = \Drupal::request()->getSession();
    $options = $session->get('mirador-options');
    
    $this->entity_type = $options['entity_type_for_annotation'];
    $this->bundle = $options['bundle_for_annotation'];
    $this->field_to_entity = $options['field_for_annotation_entity'];
    $this->field_json = $options['field_for_annotation_json'];
    $this->field_id = $options['field_for_annotation_id'];
  }


  /* Typically called when writing 
   */
  public function common() {
    //dpm(\Drupal::request());

    // retrieve the local vars from the session
    $this->getLocalvarsFromSession();
    
    $cont = \Drupal::request()->getContent();
    
    $cont = json_decode($cont, TRUE);

#    \Drupal::logger('WissKIsaveProcess')->debug(__METHOD__ . " with values: " . serialize(\Drupal::request()));
    
    // store everything to variables.
    $canvas = $cont['annotation']['canvas'];
    $annotation_id = $cont['annotation']['uuid'];
    $data = $cont['annotation']['data'];

    // see if we already have this annotation
    // if not we create it anew
    // if yes, it is an update!
    $entity_ids = \Drupal::entityQuery($this->entity_type)
      ->condition('bundle', array($this->bundle=>$this->bundle))
#      ->condition($field_to_entity, $file_path)
      ->condition($this->field_id, $annotation_id)->execute(); #->range(0,1);

    // just take the first, there should not be more than this    
    $entity_id = current($entity_ids);

    if(empty($entity_id)) {

      // build the values and create the entity
      $values = array("bundle" => $this->bundle, $this->field_to_entity => $canvas, $this->field_id => $annotation_id, $this->field_json => $data);
    
      $entity = \Drupal::entityTypeManager()->getStorage($this->entity_type)->create($values);
      
    } else {
      // load the entity and change the json
      $entity = \Drupal::service('entity_type.manager')->getStorage($this->entity_type)->load($entity_id);

      $field_json = $this->field_json;
      
      $entity->$field_json->value = $data;
      
    }

    // finally do a save
    $entity->save();
    
    return new JsonResponse($data);
  }

  /**
   * Ironically this is the main function - I don't exactly know why. 
   * However it paints all the magical thingies :)
   */
  public function pages() {

    // retrieve the local vars from the session
    $this->getLocalvarsFromSession();

#    $session = \Drupal::request()->getSession();
#    \Drupal::logger('WissKIsaveProcess')->debug(__METHOD__ . " with values: " . serialize($session));

#    dpm(\Drupal::request());
    $uri = \Drupal::request()->query->get('uri');

    $host = \Drupal::request()->getSchemeAndHttpHost();
    $call_url = \Drupal::request()->getRequestUri();

    // fetch the annotations for this file
    // we assume that it is not used multiple times in several datasets!    
    $annotation_ids = \Drupal::entityQuery($this->entity_type)
      ->condition('bundle', array($this->bundle=>$this->bundle))
      ->condition($this->field_to_entity, $uri)->execute();
          
    $annotations = array();

    // more easy mapping for better readable access.    
    $field_id = $this->field_id;
    $field_json = $this->field_json;

    // iterate the annotations    
    foreach($annotation_ids as $annotation_id) {
      $one_annotation = \Drupal::service('entity_type.manager')->getStorage($this->entity_type)->load($annotation_id);

      $one_annotation = $one_annotation->getValues(TRUE);
      
      // I dont know why we have to do that here
      //  TODO handle translation somehow!
      $one_annotation = $one_annotation[0];
      
      // get the id field of the annotation and the json code of the annotation
      // this could be more elaborate here, e.g. we could extract the annotations
      // contents to a full semantic model etc.
      $main_property_for_field_id = $one_annotation[$field_id]["main_property"];
      $main_property_for_json = $one_annotation[$field_json]["main_property"];

      // fetch the contents      
      $my_field_id = $one_annotation[$field_id][0][$main_property_for_field_id];      
      $my_json = $one_annotation[$field_json][0][$main_property_for_json];

      // and write it accordingly to the array      
      $annotations[] = json_decode($my_json, TRUE);
    }

    $data = array();
    
    // only return something if there is at least one annotation
    // otherwise adding annotations won't work!
    if(!empty($annotations))
      $data = array (
        "@context" => "http://iiif.io/api/presentation/3/context.json",
        "id" => $host . $call_url,
        "type" => "AnnotationPage",
        "items" => $annotations,
      );

    return new JsonResponse($data);
  }
  
  public function edit_annotation($annotation_id) {
    //dpm(\Drupal::request());

    // retrieve the local vars from the session
    $this->getLocalvarsFromSession();

    
    $cont = \Drupal::request()->getContent();
    
    $cont = json_decode($cont, TRUE);

#    \Drupal::logger('WissKIsaveProcess')->debug(__METHOD__ . " with values: " . $annotation_id . " and " . serialize($cont));
  
    // store everything to variables.
    $canvas = $cont['annotation']['canvas'];
    $data = $cont['annotation']['data'];

    // see if we already have this annotation
    // if not we create it anew
    // if yes, it is an update!
    $entity_ids = \Drupal::entityQuery($this->entity_type)
      ->condition('bundle', array($this->bundle=>$this->bundle))
#      ->condition($field_to_entity, $file_path)
      ->condition($this->field_id, $annotation_id)->execute(); #->range(0,1);

    // just take the first, there should not be more than this    
    $entity_id = current($entity_ids);

    if(empty($entity_id)) {

      // build the values and create the entity
      $values = array("bundle" => $this->bundle, $this->field_to_entity => $canvas, $this->field_id => $annotation_id, $this->field_json => $data);
    
      $entity = \Drupal::entityTypeManager()->getStorage($this->entity_type)->create($values);
      
    } else {
      // load the entity and change the json
      $entity = \Drupal::service('entity_type.manager')->getStorage($this->entity_type)->load($entity_id);

      $field_json = $this->field_json;
      
      $entity->$field_json->value = $data;
      
    }

    // finally do a save
    $entity->save();
    
    return new JsonResponse($data);
  }
  
  public function delete_annotation($annotation_id) {
    //dpm(\Drupal::request());

    // retrieve the local vars from the session
    $this->getLocalvarsFromSession();

    
    $cont = \Drupal::request()->getContent();
    
    $cont = json_decode($cont, TRUE);
  
    // store everything to variables.
    $canvas = $cont['annotation']['canvas'];
    $data = $cont['annotation']['data'];

    // see if we already have this annotation
    $entity_ids = \Drupal::entityQuery($this->entity_type)
      ->condition('bundle', array($this->bundle=>$this->bundle))
#      ->condition($field_to_entity, $file_path)
      ->condition($this->field_id, $annotation_id)->execute(); #->range(0,1);

    // just take the first, there should not be more than this    
    $entity_id = current($entity_ids);

    if(!empty($entity_id)) {
      // load the entity and delete it
      $entity = \Drupal::service('entity_type.manager')->getStorage($this->entity_type)->load($entity_id);

      $entity->delete();
      
    }
    
    return new JsonResponse($data);
  }
  
  public function lists() {

    // retrieve the local vars from the session
    $this->getLocalvarsFromSession();



//    $cont = \Drupal::request()->getContent();
#    \Drupal::logger('WissKIsaveProcess')->debug(__METHOD__ . " with values: ");

    $response = array();
    
    return new JsonResponse($response);
  }
}