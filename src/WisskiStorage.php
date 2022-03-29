<?php

namespace Drupal\wisski_core;

use Drupal\Core\File\FileSystemInterface;
use Drupal\wisski_core\Entity\WisskiBundle;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\ContentEntityStorageBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Language\LanguageInterface;

use Drupal\file\Entity\File;
use Drupal\file\FileStorage;
use Drupal\image\Entity\ImageStyle;

use Drupal\wisski_core\Entity\WisskiEntity;
use Drupal\wisski_core\Query\WisskiQueryInterface;
use Drupal\wisski_core\WisskiCacheHelper;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity;
use Drupal\wisski_salz\AdapterHelper;
use Drupal\wisski_salz\Entity\Adapter;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Test Storage that returns a Singleton Entity, so we can see what the FieldItemInterface does
 */
class WisskiStorage extends SqlContentEntityStorage implements WisskiStorageInterface {

  /*
  public function create(array $values = array()) {
    $user = \Drupal::currentUser();
    
    
    dpm($values, "before");
    if(!isset($values['uid']))
      $values['uid'] = $user->id();
      
    dpm($values, "values");
    return parent::create($values);
  }
  */
  
  private $pbmanager = NULL;
   
  
  /**
   * stores mappings from entity IDs to arrays of storages, that handle the id
   * and arrays of bundles the entity is in
   */
  private $entity_info = array();


  /**
   * Internal cache - needed since drupal 8.6
   */
  private $stored_entities = NULL;
  
  private $entity_cache = array();

  //cache the style in this object in case it will be used for multiple entites
  private $image_style;
  private $adapter;
  private $preview_image_adapters = array();

//  protected $tableMapping = NULL;

  public function getCacheValues($ids, $field_id = array(), $bundle_id = array()) {
  
    foreach($ids as $id) {
      // TODO: Drupal Rector Notice: Please delete the following comment after you've made any necessary changes.
      // You will need to use `\Drupal\core\Database\Database::getConnection()` if you do not yet have access to the container here.
      $cached_field_values = \Drupal::database()->select('wisski_entity_field_properties', 'f')
        ->fields('f',array('fid', 'ident','delta','properties'))
        ->condition('eid',$id);
#        ->condition('bid',$values[$id]['bundle'])
#          ->condition('fid',$field_name)

      if(!empty($field_ids)) {
        $cached_field_values = $cached_field_values->condition('fid', $field_id);
      }
        
      if(!empty($bundle_ids)) {
        $cached_field_values = $cached_field_values->condition('bid', $bundle_id);
      }

      $cached_field_values = $cached_field_values->execute()
        ->fetchAll();

      return $cached_field_values;    
    
    }
    
    
  
  }

  public function addCacheValues($ids, $values) {
#    dpm($ids, "ids");
#    dpm($values, "values");
    
    // default initialisation.
    $entities = NULL;
    
    // Here we have to look for all base fields
    // and basefields are typically stored in
    // x-default language code (= system default)
    // these have to be stored accordingly or we burn in translation hell

    $base_fields = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions("wisski_individual");
    $base_field_names = array_keys($base_fields);

    // add the values from the cache
    foreach ($ids as $id) {
      
      //@TODO combine this with getEntityInfo
      if (!empty($values[$id])) {
#ddl($values, 'values');

        // TODO: CHECK if bundle is in here or if it is in x-default
        if(isset($values[$id]['bundle']))
          $bundle = ($values[$id]['bundle']);
        else
          $bundle = NULL;
        // by MyF: we need the field defs here because it depends on the bundle; this is not sexy but it only works this way
        $field_defs = \Drupal::service('entity_field.manager')->getFieldDefinitions("wisski_individual", $bundle);
        if (!isset($bundle)) {
          continue;
        } else {
          if(isset($values[$id]['bundle']['x-default']))
            $bundle = $values[$id]['bundle']['x-default'];
        }
        // load the cache
        // TODO: Drupal Rector Notice: Please delete the following comment after you've made any necessary changes.
        // You will need to use `\Drupal\core\Database\Database::getConnection()` if you do not yet have access to the container here.
        $cached_field_values = \Drupal::database()->select('wisski_entity_field_properties', 'f')
          ->fields('f',array('fid', 'ident','delta','properties','lang'))
          ->condition('eid',$id)
          ->condition('bid',$bundle)
#          ->condition('fid',$field_name)
          ->execute()
          ->fetchAll();
#          ->fetchAllAssoc('fid');
// fetchAllAssoc('fid') is wrong here because
// if you have duplicateable fields it will fail!
#        dpm($cached_field_values, "cache");
#        dpm($id, "id?");                          

        $pbs_info = \Drupal::service('wisski_pathbuilder.manager')->getPbsUsingBundle($bundle);

        foreach($cached_field_values as $key => $cached_field_value) {
          $field_id = $cached_field_value->fid;
          
          // don't act on eid and bundle as these are primarily loaded
          // from the wisski system itself.
          // if we don't skip that here we typically load "target_id" => bundleid from
          // the cache and that is not properly handled lateron.
          if($field_id == "eid" || $field_id == "bundle")
            continue;
          
          // if it is a base field, simply set it to the appropriate langcode
          if(in_array($field_id, $base_field_names)) {
            $base_field_def = $base_fields[$field_id];
            
            // if this is not translatable simply throw it in there.
            if(!$base_field_def->isTranslatable()) {
#              dpm("$field_id is not translatable");
              $cached_value = unserialize($cached_field_value->properties);
              $delta = $cached_field_value->delta;
              $cardinality = $base_field_def->getCardinality();
              
              // if we have a cached value
              if(!empty($cached_value)) {
                
                if($cardinality > 1) {
                  // either we merge it if there already is something in values
                  if(isset($values[$id][$field_id][$delta]) && is_array($values[$id][$field_id][$delta])) {
                    $values[$id][$field_id][$delta] = array_merge($cached_value, $values[$id][$field_id][$delta]);
                  
                  } else {
                    // or we set it directly if there is nothing there yet.
                    $values[$id][$field_id][$delta] = $cached_value; 
                  }
                } else {
                  // we skip the data if cardinality is 1
                  if(isset($values[$id][$field_id]) && is_array($values[$id][$field_id])) {
                    $values[$id][$field_id] = array_merge($cached_value, $values[$id][$field_id]);
                  
                  } else {
                    // or we set it directly if there is nothing there yet.
                    $values[$id][$field_id] = $cached_value; 
                  }
                }
                continue;
              }
            }
          }
                    
          $clanguage = trim($cached_field_value->lang);
#          dpm($values[$id][$field_id], "i am here!");
#          dpm($field_id, "field id is");
#          dpm($clanguage, "clang");
#          if($field_id == 'b1abe31d92a85c73f932db318068d0d5')
#            drupal_set_message(serialize($cached_field_value));
#          dpm($cached_field_value->properties, "sdasdf");
#          dpm($values[$id][$field_id], "is set to");
#          dpm(serialize(isset($values[$id][$field_id])), "magic");
          
          // empty here might make problems
          // if we loaded something from TS we can skip the cache.
          // By Mark: Unfortunatelly this is not true. There is a rare case
          // that there is additional information, e.g. in files.
          if(isset($values[$id][$field_id]) && isset($values[$id][$field_id][$clanguage]) ) {
            $cached_value = unserialize($cached_field_value->properties);
            $delta = $cached_field_value->delta;

            // if we really have information, merge that!
            if(isset($values[$id][$field_id][$clanguage][$delta]) && is_array($values[$id][$field_id][$clanguage][$delta]) && !empty($cached_value)) {
              // by mark:
              // now it might be, that the item in $values[$id][$field_id][$delta] is not the item in
              // $cached_value - this can happen if we have the full uri in ident, but the number from the
              // triple store... @todo - I dont know if this is relevant... I will try to handle this in the loading
              // from the ts...           
 #             getFileId

#              dpm($values[$id][$field_id][$delta], "I am merging: " . serialize($cached_value));
              $values[$id][$field_id][$clanguage][$delta] = array_merge($cached_value, $values[$id][$field_id][$clanguage][$delta]); #, $cached_value);
            }

            continue;
          }
            
          // if we didn't load something, we might need the cache.
          // however not if the TS is the normative thing and has no data for this.
#          $pbs_info = \Drupal::service('wisski_pathbuilder.manager')->getPbsUsingBundle($values[$id]['bundle']);
#          dpm($pbs_info);
          
          $continue = FALSE;
          // iterate through all infos
          foreach($pbs_info as $pb_info) {
            
            // lazy-load the pb
            if(empty($pb_cache[$pb_info['pb_id']]))
              $pb_cache[$pb_info['pb_id']] = WisskiPathbuilderEntity::load($pb_info['pb_id']);
            $pb = $pb_cache[$pb_info['pb_id']];
                        
            if(!empty($pb->getPbEntriesForFid($field_id))) {
#              drupal_set_message("I found something for $field_id");
              // if we have a field in any pathbuilder matching this
              // we continue.
              $continue = TRUE;
              break;
            }
          }
          
          // do it
          if($continue)
            continue;
          
                  
#          dpm($cached_field_value->properties, "I am alive!");

          $cached_value = unserialize($cached_field_value->properties);
          
          if(empty($cached_value))
            continue;

          // now it should be save to set this value
#          if(!empty($values[$id][$field_id]))
#            $values[$id][$field_id] = 
#          else
#          dpm("$field_id loaded from cache." . serialize($cached_value));
#          dpm($clanguage, "clanguage is: ");
#          dpm($values[$id][$field_id]);
#          dpm($field_id, "fieldid");
#          dpm($id, "id");
          

          if(!isset($values[$id][$field_id])){
            $values[$id][$field_id] = array();
          }
          // @TODO Check
          // MyF: This is a special case for example label might be requested without language being set
          // thus it returns an error
          // for now we just continue here, but it might be possible that the cached value needs recursive merging
          if (!is_array($values[$id][$field_id])){       
            $tempVal = $values[$id][$field_id];
            # dpm($tempVal);
            $values[$id][$field_id] = array();
            $values[$id][$field_id][$clanguage] = array(0 => array('value' => $tempVal));
          }
          $values[$id][$field_id][$clanguage] = $cached_value;
        }
        
#        dpm($values, "values after");
             
        try {
#        dpm("yay!");
#          dpm($values[$id]);
#          return;
//          $values[$id]["langcode"][0]['value'] = "ar";
//          $values[$id]["langcode"][1]['value'] = "en";
//          $values[$id]["langcode"] = array("x-default" => "en", "fr" => "fr");

          $test = array();

          $available_languages = \Drupal::languageManager()->getLanguages();
          $available_languages = array_keys($available_languages);
#          $available_languages[] = "x-default";

          $not_set_languages = array();

          $set_languages = array();

          // todo: we can skip that for base fields...
          foreach($values[$id] as $key => $val) {
            // skip the title as it tries to be delivered in any language
            // @TODO: This might be possible to be changed.
            if($key == "label" || $key == "title")
              continue;

            // if we dont have anything, continue.
            if(!isset($field_defs[$key]))
              continue;

            // by MyF: If we consider a field that is an entity reference we want to skip the language since eitherwise the following occurs:
            // Entity Frosch has two fields: Name: Froeschli, Entity Ref: Teich (both in DE)
            // Now we translate only the Teich to its english version: Pond
            // If we switch the interface rendering language now to english we will see the following:
            // Name: --empty--, Entity Ref: Pond
            // but we want to display it like that: Name: Froeschli, Entity Ref: Pond
            // (since there is no english version for the Name or since we do not want to translate it (!), we take the name created with the original language)
            // The problem is that the variable $set_language below will contain every language which somehow occurs in context of the entity, that means that
            // the language "en" is added to this variable although there is no english translation for the entity name
            // to avoid this, we have to continue here, so only languages are added which do not come from entity references
            $field_def = $field_defs[$key];

            // by MyF: this part does not work as expected; we comment this out since we prefer empty and translated fields
            // instead of only original languages fields
            /*
            if(is_array($val)){
              $firstVal = current($val);
              if(!empty($firstVal)){
                $realFirstVal = current($firstVal);
                if(!empty($realFirstVal)){
                  if(isset($realFirstVal["wisskiDisamb"])){
                    continue;
                  }
                }
              }
            }
           // if($field_def->getType() === 'string') and )
 #           dpm(serialize($field_def->getType() === 'entity_reference'));
            if ($field_def->getType() === 'entity_reference')
              continue;
              */

            foreach($available_languages as $alang) {
#            dpm("checking $alang in $key with " . serialize($val));
#            dpm("my array key: " . array_key_exists($alang, $val));
            if(is_array($val) && array_key_exists($alang, $val)) {
                $set_languages[$alang] = $alang;
              } else {
                // we add it to the not setted languages
                // because we need that to clear the titles
                // that should not exist.
            #    dpm("answer is false");
                $not_set_languages[$alang] = $alang;
              }
              
              if(count($set_languages) == count($available_languages))
                break;
              
            }

            if(count($set_languages) == count($available_languages))
              break;
          }
         
#          dpm($available_languages, "available langs");          
#          dpm($set_languages, "the setted languages");
          
          // clear the titles that are not represented in the data
#          foreach($set_languages as $slang) {
#            unset($values[$id]["label"][$slang]);
#          }

//          foreach($values[$id] as $key => $val) {
//            if(is_array($val)) {
//              
//            }
//          }

//          dpm($values[$id], "??");

          $orig_lang = "x-default";
          // fetch the original language from
          // default langcode
          // by Mark: I am unsure if this is a correct assumption
          // it might be that we have to fetch it from elsewhere
          // but for now this seems ok.
          
          if(isset($values[$id]["default_langcode"])){

            foreach($values[$id]["default_langcode"] as $key => $value) {
              // key is a langcode and value is an array with value key.
              if($value["value"] == TRUE)
                $orig_lang = $key;
            } 
          }
         
          
          // if the entity has no default langcode (which might be and probably is the default
          // for old wisski instances) we just use the first language
          // that comes up
          if(empty($values[$id]["default_langcode"])) {    
          //MyF: it might happen that $set_languages is empty! thats why we have to look at the first language in $not_set_languages    
            if(empty($set_languages)){
              $orig_lang = \Drupal::service('language_manager')->getCurrentLanguage()->getId();
            } else {
              $orig_lang = current($set_languages);
            }
            
            # dpm($set_languages, "set?");
          }          

#          dpm("my orig lang is: " . serialize($values[$id]));

          // this should not happen, but maybe it was stored wrongly.
          // therefore we correct it here for further development
          if(is_array($orig_lang))
            $orig_lang = current($orig_lang);
          
          // by Mark:
          // if the orig_lang is not in the available languages there is something fishy
          // and we will have difficulties because data wont be loaded actively.
          // so in this case we rewrite the orig lang.
          if(!in_array($orig_lang, $available_languages)) {
            $orig_lang = \Drupal::service('language_manager')->getCurrentLanguage()->getId();
//            dpm($orig_lang, "orig?");
//            dpm($available_languages, "avail?");
          }
          
            
#          dpm("my orig lang is: " . serialize($orig_lang));

          
#          dpm(serialize($base_fields), "yay, basefields!");
          
          // MF^2: In any case we process the value array for a certain entity
          // and set the x-default langcode accordingly for all base fields. 
          // Just in case of a translatable base field that really has language tags
          // in it, we handle it otherwise. However it is hard to detect that
          // so we have to have a deeper look into the array key and act accordingly.
          // see below ;)
          // in case of a "normal" field we assume that it is always translatable
          // (which typically holds for wisski entities)
          
          // after that we can iterate through all non-base-fields and these
          // typically have different languages enabled by default due to the
          // setting in the wisski Entity
#          dpm($values[$id], "val?");      
 #         dpm($field_defs, "fieldfed");
          foreach($values[$id] as $key => $val) {
            // if it is a base field, simply set it to the appropriate langcode
            if(in_array($key, $base_field_names)) {
              $base_field_def = $base_fields[$key];
             
              
              // if this is translatable
              if($base_field_def->isTranslatable()) {
#		      dpm($val, "val? for $key");
#      dpm($available_languages, "langs?");		      
                // then we go and look for the first key
                if(is_array($val)){
                  $does_it_have_any_language = FALSE;
                  foreach($val as $pot_lang => $some_field_values) {
                    
                    // if this is a language tag
                    /*
                    if(!in_array($pot_lang, $available_languages)) {
                      // if not we set it to lang default
                      // This is the special case where somebody put in
                      // something that should not have been put into a 
                      // translatable base field like array("value" => "smthg")
                      // unfortunately this currently happens 
                      // @TODO: Fix this case!
                      
                      // By Mark: This case also happens if there is a language in the
                      // ts that is not in the drupal... so this check is too weak 
                      // and we should NOT do this!
                      
                      
                      $test[$key][LanguageInterface::LANGCODE_DEFAULT] = $val;
//                      dpm($available_languages, "avail?");
//                      dpm($pot_lang, "pot?");
                      break;
                    }
                    */
                    
                    if(in_array($pot_lang, $available_languages) || $pot_lang == "und") {
                      $does_it_have_any_language = TRUE;
                    }
                    
                  }
                  
                  if(!$does_it_have_any_language)
                    $test[$key][LanguageInterface::LANGCODE_DEFAULT] = $val;
                }
                #dpm($test);
    
                // if we have found something, we can savely continue.               
                if(isset($test[$key][LanguageInterface::LANGCODE_DEFAULT]))
                  continue;
              
                // if not we do the "normal field handling"
                // because it is a translatable base field
                // which has language tags that must be replaced accordingly.
              } else {
                // if it is not translatable, we always put it to x-default.
 #               dpm("I am changing it here");
 #               dpm($key, "key: ");
 #               dpm($val, "val: ");            
                $test[$key][LanguageInterface::LANGCODE_DEFAULT] = $val;
                continue;
              }
            }
            
            // and now we do the "normal field" handling and the handling for
            // translatable base fields (in fact we do the handling for any 
            // translatable fields)
            if(is_array($val)){


              // if we dont have anything, continue.
              if(!isset($field_defs[$key]))
                continue;
                
 #             dpm($val, "val!");
              $field_def = $field_defs[$key];
              if ($field_def->getType() === 'entity_reference') {
                foreach($available_languages as $al){
                  if(isset($val[LanguageInterface::LANGCODE_DEFAULT])){
                    $val[$al] = $val[LanguageInterface::LANGCODE_DEFAULT];
                  } else {
                    $my_val = array(); 
                    if(isset($val[$orig_lang])) 
                      $my_val = $val[$orig_lang];
                    else // if the orig lang is not set it becomes difficult...
                      $my_val = current($val);

                    $val[$al] = $my_val;
                  }
                }
              }
              
              foreach($val as $field_lang => $field_vals) {
                // if it is the default language of the entity, we exchange the 
                // language tag of the original language for x-default
                #if(gettype($field_lang) != gettype($orig_lang)){
#                  dpm("Warning: gettype(field_lang) != gettype(orig_lang)");
                #}
                
                // check if we have languages that are very odd like und and und is not in the
                // available languages.
		
		if($field_lang == "und" && !in_array($field_lang, $available_languages)) {
		  $curr_lang = \Drupal::service('language_manager')->getCurrentLanguage()->getId();
		  if(!isset($val[$curr_lang]))
      		    $field_lang = $curr_lang;
                  else if(!isset($val[$orig_lang]))
                    $field_lang = $orig_lang;
		}
		
		
		#dpm(serialize($field_lang));
		#dpm(serialize($orig_lang));
		if($field_lang == $orig_lang) {
                  $test[$key][LanguageInterface::LANGCODE_DEFAULT] = $field_vals;
                  $test[$key][$orig_lang] = $field_vals;
                } else {
                                  
                  // we just trust it for now...                 
                  $test[$key][$field_lang] = $field_vals;
                }
              }
            }
           #dpm($test, "test22?"); 
            
            // else we just take it as it is.
//            if(!empty(array_intersect(array_keys($val), $set_languages))) {
//              $test[$key] = $val;
//              continue;
//            }
          } 
                    
            /*
            if(is_array($val)) {
#              $test[$key] = $val;
              $test[$key] = array("x-default" => $val); #, "ar" => $val);
#              $test[$key] = array("ar" => $val);
            } else {
              dpm("I am $key");
#              $test[$key] = $val;
              if($key == "eid") {
                $test[$key] = array("x-default" => array($val)); #, "ar" => array($val));
#                $test[$key] = array("ar" => array($val));
              }
              else
                $test[$key] = $val;
#              $test[$key] = array("ar" => array($val));
            }
          }
          */
#          dpm(serialize($test), "I've got after base field analysis");

          // we still have to set default_langcode and
          // content_translation_source and published(status) probably? 
          // Or have to get it from cache correctly.
        
        
        
        
#          $test["eid"] = array("x-default" => $test["eid"]);
#          $test["langcode"] = array("x-default" => "en", "fr" => "fr");
#          $test["label"] = array("x-default" => "juhu?", "fr" => "oh weh");

#           $test["label"] = "aha?";

#           $bla = array("miau", "genau");
#           $bla = array(0 => "miau", 1 => "genau");

           // this is working, do not touch it or it will die!
           //$test["label"] = array("fr" => array(0 => array("value" => "juhu?")), "x-default" => array(0 => array("value" => "oh weh")));

#           $label["translatableEntityKeys"] = array("label" => array("en" => "yay", "fr" => "no!"));
#          $test["status"] = array("x-default" => "1", "fr" => "1");
#          $test["default_langcode"] = array("x-default" => "1", "fr" => "0");
#          $test["content_translation_source"] = array("x-default" => "und", "fr" => "en");
          //$test["fb18eeb8a1dce42fc045f3ebd12f20f9"] = array("x-default" => $test["fb18eeb8a1dce42fc045f3ebd12f20f9"]["x-default"], "fr" => $test["fb18eeb8a1dce42fc045f3ebd12f20f9"]["x-default"]);

#            dpm($test, "what do we give?");

#          $test["default_langcode"]["x-default"] = array("value" => TRUE);
          
          
#          $test["eid"] = $test["eid"]["x-default"][0];
          
#          $entity = $this->create($values[$id]);
#          dpm($entity);
          // Initialize translations array.
          $translations = array_fill_keys(array_keys($values), []);

          $translations[$id] = $set_languages; //array("x-default", "fr", "ar");
    
#          dpm($this->entityClass, "??");
#          return;
          // Debug from Node:
          // default_langcode always contains "x-default" with string-value "1"
          // and all other languages with string-value "0"
          // 
          // langcode always contains the original language in "x-default"
          // and all other languages with e.g. "ar"=>"ar"
#          dpm($orig_lang);
          $test['langcode'] = $set_languages;
          // set all x-default values in an extra step
          $test['langcode']['x-default'] = $orig_lang;
          $test['default_langcode']= array('x-default' => '1');
          $test['published']['x-default'] = array(0 => array('value' => True));

          if(!isset($test['published'])){
            $test['published'] = array();
          }  
          foreach($set_languages as $sl){
            // a:1:{i:0;a:1:{s:5:"value";b:1;}
            $test['published'][$sl] = array(0 => array('value' => True));
            if($sl == $orig_lang) continue;
            $test['default_langcode'][$sl]= '0';
          }

          $entity = new $this->entityClass($test,$this->entityTypeId, $bundle, $translations[$id]);

          $inter_lang = \Drupal::service('language_manager')->getCurrentLanguage()->getId();

#          $entity = $entity->getTranslation($inter_lang);
          
#          dpm($entity);
    
#          $entity = new $this->entityClass($values[$id], "wisski_individual", $values[$id]["bundle"], array_keys($translations[$id]));
          //dpm(serialize($entity), "yay!");
          $entity->enforceIsNew(FALSE);
          $entities[$id] = $entity;
#          dpm($entities);
        } catch (\Exception $e) {
          \Drupal::messenger()->addError("An error occured: " . $e->getMessage());
        }
      }
    }
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  protected function doLoadMultiple(array $ids = NULL) {
#    dpm("yay, I do loadmultiple!");
#   dpm(microtime(), "first! I load " . serialize($ids));
  //dpm($ids,__METHOD__);
    $entities = array();

#    return;

#    dpm($entity_cache, "yay?");

    $entity_cache = $this->entity_cache;
    
    foreach($ids as $key => $id) {
      // see if we have already something in the entity cache...
      if(isset($entity_cache[$id])) {

        // if so, take that from the cache!
        $entities[$id] = $entity_cache[$id];

        // and unset it for the rest...
        unset($ids[$key]);

#        dpm($id, "I take that from cache!");

      }   
    }
    
    // only load something if there still is something to load!
    if(!empty($ids)) {
//      return;
    // this loads everything from the triplestore
      $values = $this->getEntityInfo($ids);
#      return;

    #$entity_cache = $this->entity_cache;
    
#    dpm($values, "values in get entity info ");
#      dpm(microtime(), "after load");
      $pb_cache = array();
    
      $moduleHandler = \Drupal::service('module_handler');
      if (!$moduleHandler->moduleExists('wisski_pathbuilder')){
        return NULL;
      }
                      
/*
    // add the values from the cache
    foreach ($ids as $id) {
      
      //@TODO combine this with getEntityInfo
      if (!empty($values[$id])) {
#ddl($values, 'values');
        if (!isset($values[$id]['bundle'])) continue;

        // load the cache
        $cached_field_values = db_select('wisski_entity_field_properties','f')
          ->fields('f',array('fid', 'ident','delta','properties'))
          ->condition('eid',$id)
          ->condition('bid',$values[$id]['bundle'])
#          ->condition('fid',$field_name)
          ->execute()
          ->fetchAll();
#          ->fetchAllAssoc('fid');
// fetchAllAssoc('fid') is wrong here because
// if you have duplicateable fields it will fail!
#        dpm($cached_field_values, "argh");                          

        $pbs_info = \Drupal::service('wisski_pathbuilder.manager')->getPbsUsingBundle($values[$id]['bundle']);

        foreach($cached_field_values as $key => $cached_field_value) {
          $field_id = $cached_field_value->fid;
          
#          if($field_id == 'b1abe31d92a85c73f932db318068d0d5')
#            drupal_set_message(serialize($cached_field_value));
#          dpm($cached_field_value->properties, "sdasdf");
#          dpm($values[$id][$field_id], "is set to");
#          dpm(serialize(isset($values[$id][$field_id])), "magic");
          
          // empty here might make problems
          // if we loaded something from TS we can skip the cache.
          // By Mark: Unfortunatelly this is not true. There is a rare case
          // that there is additional information, e.g. in files.
          if( isset($values[$id][$field_id]) ) {
            $cached_value = unserialize($cached_field_value->properties);
            $delta = $cached_field_value->delta;

            // if we really have information, merge that!
            if(isset($values[$id][$field_id][$delta]) && is_array($values[$id][$field_id][$delta]) && !empty($cached_value))
              $values[$id][$field_id][$delta] = array_merge($cached_value, $values[$id][$field_id][$delta]); #, $cached_value);

            continue;
          }
            
          // if we didn't load something, we might need the cache.
          // however not if the TS is the normative thing and has no data for this.
#          $pbs_info = \Drupal::service('wisski_pathbuilder.manager')->getPbsUsingBundle($values[$id]['bundle']);
#          dpm($pbs_info);
          
          $continue = FALSE;
          // iterate through all infos
          foreach($pbs_info as $pb_info) {
            
            // lazy-load the pb
            if(empty($pb_cache[$pb_info['pb_id']]))
              $pb_cache[$pb_info['pb_id']] = WisskiPathbuilderEntity::load($pb_info['pb_id']);
            $pb = $pb_cache[$pb_info['pb_id']];
                        
            if(!empty($pb->getPbEntriesForFid($field_id))) {
#              drupal_set_message("I found something for $field_id");
              // if we have a field in any pathbuilder matching this
              // we continue.
              $continue = TRUE;
              break;
            }
          }
          
          // do it
          if($continue)
            continue;
          
                  
#          dpm($cached_field_value->properties, "I am alive!");

          $cached_value = unserialize($cached_field_value->properties);
          
          if(empty($cached_value))
            continue;

          // now it should be save to set this value
#          if(!empty($values[$id][$field_id]))
#            $values[$id][$field_id] = 
#          else
#          dpm($cached_value, "loaded from cache.");
          $values[$id][$field_id] = $cached_value;
        }
        
#        dpm($values, "values after");
             
        try {
#        dpm("yay!");
#          dpm(serialize($values[$id]));
          $entity = $this->create($values[$id]);
#          dpm(serialize($entity), "yay!");
          $entity->enforceIsNew(FALSE);
          $entities[$id] = $entity;
#          dpm($entities);
        } catch (\Exception $e) {
          drupal_set_message("An error occured: " . $e->getMessage(), "error");
        }
      }
    }
*/
      $entities = $this->addCacheValues($ids, $values);    
#    dpm(microtime(), "last!");
#    dpm(array('in'=>$ids,'out'=>$entities),__METHOD__);

      // early opt out
      if(empty($entities))
        return array();

      // somehow the validation for example seems to call everything more than once
      // therefore we cache every full call...
      // if we have loaded it already, we just take it from the cache!
      $entity_cache = $this->entity_cache;
#      dpm($entity_cache, "already have: ");
    
      foreach($entities as $id => $value) {
#        dpm(serialize($value->bundle->getValue()), "for $id");
        $entity_cache[$id] = $value;
      }
    
      $this->entity_cache = $entity_cache;
    }

#    dpm(serialize($entities), "out?");


#    dpm(microtime(), "exit");

#    dpm($entities);
    
#    foreach($entities as $entity) {
#      dpm($entity->isDefaultTranslation());
#      dpm($entity->activeLangcode);
#      dpm(LanguageInterface::LANGCODE_DEFAULT);
#    }

#    \Drupal::logger('wisski salz')->warning("Log {id} ", array('id' => serialize($entities) ) );
    
    return $entities;
  }

  /**
   * gathers entity field info from all available adapters
   * @param $id entity ID
   * @param $cached TRUE for static caching, FALSE for forced update
   * @return array keyed by entity id containing entity field info
   */
  protected function getEntityInfo(array $ids,$cached = FALSE) {
#    drupal_set_message(serialize($ids) .  " : " .  serialize($this));
#    dpm(microtime(), "in1 asking for " .  serialize($ids));
#    dpm($this->latestRevisionIds, "yay123!");

    // get the main entity id
    // if this is NULL then we have a main-form
    // if it is not NULL we have a sub-form    
    if(!empty($this->entities)) 
      $mainentityid = key($this->entities);
    else if(!empty($this->stored_entities))
      $mainentityid = key($this->stored_entities);
    else
      $mainentityid = NULL;

  

#    dpm($mainentityid);

#    drupal_set_message("key is: " . serialize($mainentityid));

    // this is an array of the known entities.
    // whenever some adapter knows any of the entities that
    // are queried here, it sets the corresponding id
    // with $id => TRUE
    $known_entity_ids = array();

    $entity_info = &$this->entity_info;
    if ($cached) {
      $ids = array_diff_key($ids,$entity_info);
      if (empty($ids)) return $entity_info;
      // if there remain entities that were not in cache, $ids now only 
      // contains their ids and we load these in the remaining procedure.
    }
    
    // prepare config variables if we only want to use main bundles.    
    $topBundles = array();
    $set = \Drupal::configFactory()->getEditable('wisski_core.settings');
    $only_use_topbundles = $set->get('wisski_use_only_main_bundles');
#dpm(microtime(), "in2");
    if($only_use_topbundles) 
      $topBundles = WisskiHelper::getTopBundleIds();

    $bundle_from_uri = \Drupal::request()->query->get('wisski_bundle');
    
    //$adapters = entity_load_multiple('wisski_salz_adapter');
    $adapters = \Drupal::entityTypeManager()->getStorage('wisski_salz_adapter')->loadMultiple();
    
    $info = array();
    // for every id
    foreach($ids as $id) {
    
      $this->stored_entities[$id] = $id;
    
#      dpm($mainentityid, "main");
#      dpm($id, "id");
#      dpm(microtime(), "in3");
      //make sure the entity knows its ID at least
      $info[$id]['eid'] = $id;
      
      //see if we got bundle information cached. Useful for entity reference and more      
      $overall_bundle_ids = array();

      // by mark:
      // in case of a main form always use the uri-parameter if provided
      // this is always better than trying something else!
      if( empty($mainentityid) && !empty($bundle_from_uri) ) {
#        $cached_bundle = WisskiCacheHelper::getCallingBundle($id);
#        dpm($id . " " . serialize($bundle_ids) . " and " . serialize($cached_bundle));
        $cached_bundle = $bundle_from_uri;
        
        $this->writeToCache($id, $cached_bundle);
      } else {
        $cached_bundle = WisskiCacheHelper::getCallingBundle($id);
#        dpm($id . " " . serialize($bundle_ids) . " and " . serialize($cached_bundle));      
        // only use that if it is a top bundle when the checkbox was set. Always use it otherwise.
        if ($cached_bundle) {
          if($only_use_topbundles && empty($mainentityid) && !in_array($cached_bundle, $topBundles)) {

            // check if there is any valid top bundle.
            $valid_topbundle = AdapterHelper::getBundleIdsForEntityId($id, TRUE);

            // if we found any, we trust the system that this is probably the best!
            if($valid_topbundle)
              $cached_bundle = current($valid_topbundle); // whichever system might have more than one of this? I dont know...

            // if we did not find any top bundle we guess that the cached one
            // will probably be the best. We dont start searching
            // for anything again....

            //$cached_bundle = NULL;
          } else
            $info[$id]['bundle'] = $cached_bundle;
        #dpm($cached_bundle, "cb");
        }
      }
            
#      drupal_set_message(serialize($bundle_ids) . " and " . serialize($cached_bundle));
#      dpm(microtime(), "in4");
      // ask all adapters
      foreach($adapters as $aid => $adapter) {
        // if they know that id
#        drupal_set_message(serialize($adapter->hasEntity($id)) . " said adapter " . serialize($adapter));
        if($adapter->hasEntity($id)) {
          $known_entity_ids[$id] = TRUE;
          
          // if we have something in cache, take that first.
          if (isset($cached_bundle) && !empty($cached_bundle)) {
            $bundle_ids = array($cached_bundle);
          } else {

            // take all bundles
            $bundle_ids = $adapter->getBundleIdsForEntityId($id);

            if (empty($bundle_ids)) {
              // if the adapter cannot determine at least one bundle, it will
              // also not be able to contribute to the field data
              // TODO: check if this assumption is right!
              continue; // next adapter
            }

            if (!empty($bundle_from_uri) && (empty($bundle_ids) || in_array($bundle_from_uri, $bundle_ids))) {
              $bundle_ids = array($bundle_from_uri);
            }

/*          By Martin: the following lines have to be replaced by the ones above.
            the code below would give priority to the uri bundle for entities 
            in subforms, too.
            with the code above it is no longer possible to brute force a 
            certain bundle, however.

            By Mark: Works - also with the transdisciplinary approach
            // if the bundle is given via the uri, we use that and only that
            if(!empty($bundle_from_uri))
              $bundle_ids = array($bundle_from_uri);
            else {
              // if so - ask for the bundles for that id
              // we assume bundles to be prioritized i.e. the first bundle in the set is the best guess for the view
#              drupal_set_message(serialize($bundle_ids));
            }*/
          }
#          dpm($bundle_ids, "bids");
#          dpm($overall_bundle_ids, "obids");
          $overall_bundle_ids = array_merge($overall_bundle_ids, $bundle_ids);

          $bundle_ids = array_slice($bundle_ids,0,1); // HACK! (randomly takes the first one
          #drupal_set_message(serialize($bundle_ids) . " and " . serialize($cached_bundle) . " for " . serialize($ids));
          foreach($bundle_ids as $bundleid) {
            // be more robust.
            if(empty($bundleid)) {
              \Drupal::messenger()->addWarning("Beware, there is somewhere an empty bundle id specified in your pathbuilder!");
              \Drupal::messenger()->addStatus("I have been looking for a bundle for $id and I got from cache: " . serialize($cached_bundle) . " and I have left: " . serialize($bundle_ids));
              continue;
            }
            
            // do this here because if we only use main bundles we need to store this for the title
            if($cached_bundle != $bundleid)
              $this->writeToCache($id, $bundleid);

#            dpm($bundleid, "bid1");
            $field_definitions = $this->entityFieldManager->getFieldDefinitions('wisski_individual',$bundleid);
#            dpm($field_definitions, "yay");
#            wpm($field_definitions, 'gei-fd');
            
#            // see if a field is going to show up.
#            $view_ids = \Drupal::entityQuery('entity_view_display')
#              ->condition('id', 'wisski_individual.' . $bundleid . '.', 'STARTS_WITH')
#              ->execute();
              
#            // is there a view display for it?
#            $entity_view_displays = \Drupal::entityManager()->getStorage('entity_view_display')->loadMultiple($view_ids);
            
#            // did we get something?
#            if(!empty($entity_view_displays))
#              $entity_view_display = current($entity_view_displays);
#            else
#              $entity_view_display = NULL;
#            dpm($entity_view_displays->getComponent('field_name'), "miau");

            try {
#              dpm(microtime(), "load field $field_name");
              foreach ($field_definitions as $field_name => $field_def) {
#                dpm($entity_view_display->getComponent($field_name), "miau");
#                if($field_name 
#                dpm("my label" . "loading $field_name" . " and I am the: " . serialize($field_def));
#                dpm(microtime(), "loading $field_name");
 
// By Mark: We don't need that here I think - moved it to below, delete in future!             
//                $main_property = $field_def->getFieldStorageDefinition()->getMainPropertyName();
#dpm(array($adapter->id(), $field_name,$id, $bundleid),'ge1','error');
                
                //MyF: we added BaseFieldOverride here for compatibility reasons, but we should check which instanceofs are really needed in future
                if ($field_def instanceof BaseFieldDefinition || $field_def instanceof BaseFieldOverride) {

#                  dpm($field_name, "fn?");
#                  dpm("it is a base field!");
                  //the bundle key will be set via the loop variable $bundleid
                  if ($field_name === 'bundle') continue;
#                  drupal_set_message("Hello i am a base field ".$field_name);

                  $new_field_values = array();
                  // special case for entity name - call the title generator!
                  if ($field_name === 'name') $new_field_values[$id][$field_name] = array(wisski_core_generate_title($id)); 
                  if ($field_name === 'label') $new_field_values[$id][$field_name] = array(wisski_core_generate_title($id)); 
                  // we already know the eid
                  if ($field_name === 'eid') $new_field_values[$id][$field_name] = array($id);

                  if ($field_name === 'wisski_uri') $new_field_values[$id][$field_name] = array($adapter->getEngine()->getUriForDrupalId($id, FALSE));
                  
                  if ($field_name === 'preview_image') {
                    $preview_image_uri = $this->getPreviewImageUri($id, $bundleid);

                    // prefix with public path
                    if (strpos($preview_image_uri, "public://") !== FALSE) {
                      $preview_image_uri = str_replace("public:/", \Drupal::service('stream_wrapper.public')->baseUrl(), $preview_image_uri);
                    }
                    
                    $preview_id = $this->getFileId($preview_image_uri);
                    
#                    dpm($preview_id);
                    $new_field_values[$id][$field_name] = array(array("target_id" => intval($preview_id)));
//                    $new_field_values[$id][$field_name] = array($preview_image_uri);
                  }
#                  dpm($new_field_values, "nfv?");
                  
                  // and for now we don't handle uuid, vid, langcode, uid, status
                  // do this for performance reasons here.
                  // this means we have to change this later!
                  if ($field_name === 'uuid' || $field_name === 'vid' || $field_name === 'langcode' || $field_name === 'uid' || $field_name === 'status') 
                    continue;

                  //this is a base field and cannot have multiple values
                  //@TODO make sure, we load the RIGHT value
                  if(empty($new_field_values)) 
//                    $new_field_values = $adapter->loadPropertyValuesForField($field_name,array(),array($id),$bundleid);
                 
                  //By MyF: We removed the language from this call => see declaration for more information
                   # $new_field_values = $adapter->loadPropertyValuesForField($field_name,array(),array($id),$bundleid, $language);
                   $new_field_values = $adapter->loadPropertyValuesForField($field_name,array(),array($id),$bundleid);
                  
#                  dpm("at this point my field_name is: " . $field_name);
#                  dpm("my nfv are: " . serialize($new_field_values));
#                  dpm($new_field_values, $field_name);

                  if (empty($new_field_values)) continue;
#                  drupal_set_message("Hello i am still alive ". serialize($new_field_values));
                  $new_field_values = $new_field_values[$id][$field_name];
#                  dpm($new_field_values, "nfv2");
#                  drupal_set_message(serialize($info[$id][$field_name]) . " " . $field_name);
                  if (isset($info[$id][$field_name])) {
                    $old_field_value = $info[$id][$field_name];
                    if (in_array($old_field_value,$new_field_values) && count($new_field_values) > 1) {
#                      drupal_set_message("muahah!2" . $field_name);
                      //@TODO drupal_set_message('Multiple values for base field '.$field_name,'error');
                      //FALLLBACK: do nothing, old field value stays the same
                      //WATCH OUT: if you change this remember to handle preview_image case correctly
                    } elseif (count($new_field_values) === 1) {
#                       drupal_set_message("muahah!1" . $field_name);
                      $info[$id][$field_name] = $new_field_values[0];
                    } else {
#                      drupal_set_message("muahah!" . $field_name);
                      //@TODO drupal_set_message('Multiple values for base field '.$field_name,'error');
                      //WATCH OUT: if you change this remember to handle preview_image case correctly
                    }
                  } elseif (!empty($new_field_values)) {
                  #  dpm($new_field_values, "argh: ");
                    $info[$id][$field_name] = current($new_field_values);
#                    $info[$id][$field_name] = $new_field_values;
                  }
#                  dpm($info, "done");  
#                  dpm($info[$id][$field_name], $field_name);
                  if (!isset($info[$id]['bundle'])) $info[$id]['bundle'] = $bundleid;
                  continue;                 
                }
#                dpm(microtime(), "actual load for field " . $field_name . " in bundle " . $bundleid . " for id " . $id);

#                // check if the field is in the display
#                if(!empty($entity_view_display) && !$entity_view_display->getComponent($field_name))
#                  continue;
                  
                //here we have a "normal field" so we can assume an array of field values is OK
                $new_field_values = $adapter->loadPropertyValuesForField($field_name,array(),array($id),$bundleid);

#               dpm("after load" . serialize($new_field_values));
#                dpm($new_field_values, "nfv");
                if (empty($new_field_values)) continue;
                $info[$id]['bundle'] = $bundleid;

                if ($field_def->getType() === 'entity_reference') {
                  $field_settings = $field_def->getSettings();
#if (!isset($field_settings['handler_settings']['target_bundles'])) dpm($field_def);
                  $target_bundles = $field_settings['handler_settings']['target_bundles'];
                  if (count($target_bundles) === 1) {
                    $target_bundle_id = current($target_bundles);
                  } else if( count($target_bundles) === 1) {
                    \Drupal::messenger()->addStatus($this->t('There is no target bundle id for field %field - I could not continue.',array('%field' => $field_name)));
                  } else {
                    \Drupal::messenger()->addStatus($this->t('Multiple target bundles for field %field, %field_label',array('%field' => $field_def->getLabel(), '%field_label' => $field_name)));
#                    dpm($target_bundles);
                    //@TODO create a MASTER BUNDLE and choose that one here
                    $target_bundle_id = current($target_bundles);
                  }
#                  dpm($target_bundles);
                  $target_ids = $new_field_values[$id][$field_name];
                  
                  // by mark:
                  // as this is a language-thingie we just take the first one
                  // for now we assume that these are all the same across the different
                  // languages
                  $target_ids = current($target_ids);
                  
                  if (!is_array($target_ids)) $target_ids = array(array('target_id'=>$target_ids));
                  foreach ($target_ids as $target_id) {
#dpm($target_id, "bwtb:$aid");                    
                    $target_id = $target_id['target_id'];
                    $this->writeToCache($target_id,$target_bundle_id);
                    #dpm($info, $field_name);
                    #dpm($target_id, "wrote to cache");
                    #dpm($target_bundle_id, "wrote to cache2");
                  }
                  
                }
                
                // NOTE: this is a dirty hack that sets the text format for all long texts
                // with summary
                // TODO: make format storable and provide option for default format in case
                // no format can be retrieved from storage
                //
                // By Mark: We need this in case we have old data that never was
                // saved before.
                // in this case we take the default format, which is the first one.
                //
                $hack_type = $field_def->getType();
#                dpm(\Drupal::entityManager()->getStorage('filter_format')->loadByProperties(array('status' => TRUE)), "ht");
                if ($hack_type == 'text_with_summary' || $hack_type == 'text_long') {
                  $formats = \Drupal::service('entity_type.manager')->getStorage('filter_format')->loadByProperties(array('status' => TRUE));
                  $format = current($formats);
#                  dpm($format->get("format"), "format");
                  // By Mark: This has changed due to language thingies...
                  // we have to change that here but be backward compatible.
                  foreach($new_field_values as &$xid) {
                    foreach($xid as &$xfieldname) {
                      foreach ($xfieldname as &$xindex) {
                        $found_smthg = FALSE;
                        if(is_array($xindex)) {
                          foreach($xindex as &$subindex) {
                            if(isset($subindex['wisski_language']))
                              $subindex['format'] = $format->get("format");
                              $found_smthg = TRUE;
                          }
                        }

                        if(!$found_smthg)
                          $xindex['format'] = $format->get("format");
                      }
                    }
                  }
#                 $value['value'] = $value;
#                 $value['format'] = 'full_html';
                }
                
                // by mark:
                // do the sorting first...
                //try finding the weights and sort the values accordingly
                if (isset($new_field_values[$id][$field_name])) {
                  // TODO: Drupal Rector Notice: Please delete the following comment after you've made any necessary changes.
                  // You will need to use `\Drupal\core\Database\Database::getConnection()` if you do not yet have access to the container here.
                  $cached_field_values = \Drupal::database()->select('wisski_entity_field_properties', 'f')
                    ->fields('f',array('ident','delta','properties'))
                    ->condition('eid',$id)
                    ->condition('bid',$bundleid)
                    ->condition('fid',$field_name)
                    ->execute()
                    ->fetchAllAssoc('delta');
                    // this is evil because same values will be killed then... we go for weight instead.
#                    ->fetchAllAssoc('ident');
#                  dpm($cached_field_values, "cfv");

                  if (!empty($cached_field_values)) {

//                    $head = array();
//                    $tail = array();

#                    dpm($id, "id?");
#                    dpm($field_name, "fn?");
#                    dpm($new_field_values, "nfvori?");
#                    dpm($new_field_values[$id][$field_name], "nfv");

                    // get the main property
                    $main_property = $field_def->getFieldStorageDefinition()->getMainPropertyName();

                    // there is no delta as a key in this array :(
                    foreach($new_field_values[$id][$field_name] as $lang => $new_values) { 
                      $head = array();
                      $tail = array();
                      foreach ($new_values as $nfv) {
                        // this would be smarter, however currently the storage can't
                        // store the disamb so this is pointless...
                        //$ident = isset($nfv['wisskiDisamb']) ? $nfv['wisskiDisamb'] : $nfv[$main_property];

                        // this was a good approach, however it is not correct when you have
                        // the same value several times
                        //$ident = $nfv[$main_property];
                        //if (isset($cached_field_values[$ident])) {

                        // store the found item
                        $found_cached_field_value = NULL;

                        // iterate through the cached values and delete
                        // anything we find from the cache to correct the weight
                        // TODO - adapt to language!
                        foreach($cached_field_values as $key => $cached_field_value) {
#                          dpm($nfv[$main_property], "comparing to " . $cached_field_value->ident);
                          if(isset($nfv[$main_property])) {
//                            if((string)$cached_field_value->ident === (string)$nfv[$main_property] ) {
                              if((string)$cached_field_value->ident === (string)mb_substr($nfv[$main_property], 0, 1000) ) {
                              unset($cached_field_values[$key]);
                              $found_cached_field_value = $cached_field_value;
                              break;
                            }
                          } else if(is_string($nfv)) {
                            if((string)$cached_field_value->ident === (string)mb_substr($nfv, 0, 1000) ) {
//                            if((string)$cached_field_value->ident === (string)$nfv ) {
                              unset($cached_field_values[$key]);
                              $found_cached_field_value = $cached_field_value;
                              break;
                            }
                          }
                          #else if(!isset($nfv[$main_property]))
                          #                          dpm($nfv);
                        }
                      
                        // if we found something go for it...
                        if (isset($found_cached_field_value)) {
                          if(is_array($nfv))
                            $head[$found_cached_field_value->delta] = $nfv + unserialize($found_cached_field_value->properties);
                          else
                            $head[$found_cached_field_value->delta] = unserialize($found_cached_field_value->properties);
                        } else $tail[] = $nfv;
                      }

                      // do a ksort, because array_merge will resort anyway!
                      ksort($head);
                      ksort($tail);                    

#                    dpm($head, "head");
#                    dpm($tail, "tail");
                      $new_field_values[$id][$field_name][$lang] = array_merge($head,$tail);
                    }
#                    dpm($new_field_values[$id][$field_name], "miaz");
                  }
#                  if (!isset($info[$id]) || !isset($info[$id][$field_name])) $info[$id][$field_name] = $new_field_values[$id][$field_name];
#                  else $info[$id][$field_name] = array_merge($info[$id][$field_name],$new_field_values[$id][$field_name]);
                }
                

                // we integrate a file handling mechanism that must necessarily
                // also handle other file based fields e.g. "image"
                //
                // the is_file test is a hack. there doesn't seem to be an easy
                // way to determine if a field is file-based. this test tests
                // whether the field depends on the file module. NOTE: there
                // may be other reasons why a field depends on file than
                // handling files
                $is_file = in_array('file',$field_def->getFieldStorageDefinition()->getDependencies()['module']);
                $has_values = !empty($new_field_values[$id][$field_name]);
                if ($is_file && $has_values) {

#                  dpm($new_field_values[$id][$field_name], "yay!");
                  
                  foreach ($new_field_values[$id][$field_name] as $file_lang => $file_val) {
                    foreach ($new_field_values[$id][$field_name][$file_lang] as $key => &$properties_array) {
                      // we assume that $value is an image URI which is to be
                      // replaced by a File entity ID
                      // we use the special property original_target_id as the
                      // loadPropertyValuesForField()/pathToReturnValues()
                      // replaces the URI with the corresp. file entity id.
                      if (!isset($properties_array['original_target_id']) && !isset($properties_array['target_id'])) continue;
                      else if(isset($properties_array['target_id']) && !isset($properties_array['original_target_id'])) $properties_array['original_target_id'] = $properties_array['target_id'];
                      $file_uri = $properties_array['original_target_id'];
#       	             dpm($file_uri, "got");                    
                      $local_uri = '';
                        
                      $properties_array['target_id'] = $this->getFileId($file_uri,$local_uri, $id);
/*
                    $properties_array = array(
                      'target_id' => $this->getFileId($file_uri,$local_uri, $id),
#                      'tmp_wki_file_target' => $file_uri,
                      //this is a fallback
                      //@TODO get the alternative text from the stores
#                      'alt' => substr($local_uri,strrpos($local_uri,'/') + 1),
                    );
*/
#                    dpm($local_uri, "uri");
                    }
                  }
                }
                
                if (isset($new_field_values[$id][$field_name])) {                
                  if (!isset($info[$id]) || !isset($info[$id][$field_name])) $info[$id][$field_name] = $new_field_values[$id][$field_name];
                  else $info[$id][$field_name] = array_merge($info[$id][$field_name],$new_field_values[$id][$field_name]);
                }
              }
            } catch (\Exception $e) {
              \Drupal::messenger()->addStatus('Could not load entities in adapter '.$adapter->id() . ' because ' . $e->getMessage());
              //throw $e;
            }              
          }     
          
        } else {
#          drupal_set_message("No, I don't know " . $id . " and I am " . $aid . ".");
        }
          
      } // end foreach adapter
      
      if(empty($known_entity_ids[$id])) {
        unset($info[$id]);
        continue;
      }
      
      if (!isset($info[$id]['bundle'])) {
        // we got no bundle information
        // this may especially be the case if we have an instance with no fields filled out.
        // if some adapters found some bundle info, we make a best guess
        if (!empty($overall_bundle_ids)) {
          $top_bundle_ids = WisskiHelper::getTopBundleIds();
          $best_guess = array_intersect($overall_bundle_ids, $top_bundle_ids);
          if (empty($best_guess)) {
            $best_guess = $overall_bundle_ids;
          }
          // if there are multiples, tkae the first one
          // TODO: rank remaining bundles
          $info[$id]['bundle'] = current($best_guess);
        }
      }
      
    }
    
#    dpm($info, "info?");

    $entity_info = WisskiHelper::array_merge_nonempty($entity_info,$info);
#    dpm(microtime(), "out5");
#    dpm(serialize($entity_info), 'gei');

    return $entity_info;
  }

  public function getFileId($file_uri,&$local_file_uri='', $entity_id = 0) {
    $value = NULL;
    
#    dpm('Image uri: '.$file_uri);
    if (empty($file_uri)) return NULL;
    //first try the cache
    $cid = 'wisski_file_uri2id_'.md5($file_uri);
#    dpm(microtime(), "in fid");
    if ($cache = \Drupal::cache()->get($cid)) {
      // check if it really exists.
#      dpm(microtime(), "got fid");
      if(file_exists($file_uri) && filesize($file_uri) > 0) {
        list($file_uri,$local_file_uri) = $cache->data;
        return $file_uri;
      }
    }
    
#    dpm(microtime(), "out");
#   dpm("yay!");       
    // another hack, make sure we have a good local name
    // @TODO do not use md5 since we cannot assume that to be consistent over time
    $local_file_uri = $this->ensureSchemedPublicFileUri($file_uri);
    #dpm($file_uri, "1");
    #dpm($local_file_uri);
    // we now check for an existing 'file managed' with that uri

    // Mark: I don't think that this ever can be fulfilled. I think 
    // most of the time only local_file_uri can be guessed.
    // For sureness: old code below!
    //$query = \Drupal::entityQuery('file')->condition('uri',$file_uri);

    $query = \Drupal::entityQuery('file')->condition('uri',$local_file_uri)->range(0,1);        

    $file_ids = $query->execute();
    if (!empty($file_ids)) {
#      dpm(microtime(), "out fid");
#      dpm($file_ids, "2");
      // if there is one, we must set the field value to the image's FID
      if(file_exists($local_file_uri) && filesize($local_file_uri) > 0) {
        $value = current($file_ids);
      } else {
        $file_storage = \Drupal::entityTypeManager()->getStorage('file');
        $file_entities = $file_storage->loadMultiple($file_ids);
        $file_storage->delete($file_entities);
        //file_delete(current($file_ids));
        $file_ids = NULL;
      }
    }         
    
    if(empty($file_ids)) {
#     dpm($local_file_uri, "loc");
      $file = NULL;
      // if we have no managed file with that uri, we try to generate one.
      // in the if test we test whether there exists on the server a file 
      // called $local_file_uri: file_destination() with 2nd param returns
      // FALSE if there is such a file!
      //if (file_destination($local_file_uri,FILE_EXISTS_ERROR) === FALSE) {
      if (\Drupal::service('file_system')->getDestinationFilename($local_file_uri,FileSystemInterface::EXISTS_ERROR) === FALSE) {
#            dpm($local_file_uri, "7");
        $file = File::create([
          'uri' => $local_file_uri,
          'uid' => \Drupal::currentUser()->id(),
          'status' => FILE_STATUS_PERMANENT,
        ]);

        //$file->setFileName(drupal_basename($local_file_uri));
        $file->setFileName(\Drupal::service('file_system')->basename($local_file_uri));
        $mime_type = \Drupal::service('file.mime_type.guesser')->guess($local_file_uri);

        $file->setMimeType($mime_type);
        $file->setPermanent();

        $file->save();
        $value = $file->id();
            
      } else {
        try {
      
          // we have to encode the image url, 
          // see http://php.net/manual/en/function.file-get-contents.php
          // NOTE: although the docs say we must use urlencode(), the docs
          // for urlencode() and rawurlencode() specify that rawurlencode
          // must be used for url path part.
          // TODO: this encode hack only works properly if the file name 
          // is the last part of the URL and if only the filename contains
          // disallowed chars. 
          $tmp = explode("/", $file_uri);
#              $tmp[count($tmp) - 1] = rawurlencode($tmp[count($tmp) - 1]);
          $file_uri = join('/', $tmp);

          // replace space.
          // we need to replace space to %20
          // because urls are like http://projektdb.gnm.de/provenienz2014/sites/default/files/Z 2156.jpg
          $file_uri = str_replace(array(' ','Ä','Ö','Ü','ä','ö','ü','ß'), array('%20','%C3%84','%C3%96','%C3%9C','%C3%A4','%C3%B6','%C3%BC','%C3%9F'), $file_uri); 

          $data = @file_get_contents($file_uri, false, $context);
          
          if (empty($data)) { 
            \Drupal::messenger()->addError($this->t('Could not fetch file with uri %uri.',array('%uri'=>$file_uri,)));
          }

#              dpm(array('data'=>$data,'uri'=>$file_uri,'local'=>$local_file_uri),'Trying to save image');
          $file = file_save_data($data, $local_file_uri);

          if ($file) {
            $value = $file->id();
            //dpm('replaced '.$file_uri.' with new file '.$value);
          } else {
            \Drupal::messenger()->addError('Error saving file');
            //dpm($data,$file_uri);
          }
        }
        catch (EntityStorageException $e) {
          \Drupal::messenger()->addError($this->t('Could not create file with uri %uri. Exception Message: %message',array('%uri'=>$file_uri,'%message'=>$e->getMessage())));
        }
      }

      if (!empty($file)) {
        // we have to register the usage of this file entity otherwise 
        // Drupal will complain that it can't refer to this file when 
        // saving the WissKI individual
        // (it is unclear to me why Drupal bothers about that...)
        \Drupal::service('file.usage')->add($file, 'wisski_core', 'wisski_individual', $entity_id);
      }
    }
    
    
    

#    dpm($value,'image fid');
#    dpm($local_file_uri, "loc");
    //set cache
    \Drupal::cache()->set($cid,array($value,$local_file_uri));
    return $value;
  }

  /**
   * returns a file URI starting with public://
   * if the input URI already looks like this we return unchanged, a full file path
   + to the file directory will be renamed accordingly
   * every other uri will be renamed by a hash function
   */
  public function ensureSchemedPublicFileUri($file_uri) {
    if (strpos($file_uri,'public:/') === 0) return $file_uri;
    if (strpos($file_uri,'private:/') === 0) return $file_uri;

#    dpm($file_uri, "fi");
#    dpm(\Drupal::service('stream_wrapper.public')->baseUrl(), "fo");

    if (strpos($file_uri,\Drupal::service('stream_wrapper.public')->baseUrl()) === 0) {
      return $this->getSchemedUriFromPublicUri($file_uri);
    }

    $original_path = \Drupal::config('system.file')->get('default_scheme') . '://wisski_original/';

    \Drupal::service('file_system')->prepareDirectory($original_path, FileSystemInterface::CREATE_DIRECTORY);

    // do a htmlentities in case of any & or fragments...

    // This is a problem with URIs which contain .de and so on...
    //$extension = htmlentities(substr($file_uri,strrpos($file_uri,'.')));

    $position_of_ext = strrpos($file_uri,'.');

    // This should be somewhere at the end... typically extensions are up to 4 letters?
    if($position_of_ext > (strlen($file_uri) - 5))
      $extension = htmlentities(substr($file_uri,$position_of_ext));
    else
      $extension = ".jpg";

    // load the valid image extensions
    $image_factory = \Drupal::service('image.factory'); 
    $supported_extensions = $image_factory->getSupportedExtensions();

    $extout = "";
#    dpm($supported_extensions);

    // go through them and see if there is any in this extension
    // fragment. If so - make it "clean" and get rid of any 
    // appended fragment parts.
    foreach($supported_extensions as $key => $ext) {
      if(strpos($extension, $ext)) {
        $extout = '.' . $ext;
        break;
      }
    }

    // if not - we assume jpg.
    if((empty($extout) && empty($extension)) || strpos($extension, "php") !== FALSE )
      $extout = '.jpg';
    else if(!empty($extension)) // keep extensions if there are any - for .skp like in the kuro-case.
      $extout = $extension;

#    dpm($extension, "found ext");

    // this is evil in case it is not .tif or .jpeg but something with . in the name...
#    return file_default_scheme().'://'.md5($file_uri).substr($file_uri,strrpos($file_uri,'.'));    
    // this is also evil, because many modules can't handle public:// :/
    // to make it work we added a directory.
    return \Drupal::config('system.file')->get('default_scheme').'://wisski_original/'.md5($file_uri).$extout;
    // external uri doesn't work either
    // this is just a documentation of what I've tried...
#    return \Drupal::service('stream_wrapper.public')->baseUrl() . '/' . md5($file_uri);
#    return \Drupal::service('file_system')->realpath( file_default_scheme().'://'.md5($file_uri) );
#    return \Drupal::service('stream_wrapper.public')->getExternalUrl() . '/' . md5($file_uri);
#    return str_replace('/foko2014/', '', file_url_transform_relative(file_create_url(file_default_scheme().'://'.md5($file_uri))));

  }
  
  public function getPublicUrlFromFileId($file_id) {
    
    if ($file_object = File::load($file_id)) {
      return str_replace(
        'public:/',																						//standard file uri is public://.../filename.jpg
        \Drupal::service('stream_wrapper.public')->baseUrl(),	//we want DRUPALHOME/sites/default/.../filename.jpg
        $file_object->getFileUri()
      );
    }
    return NULL;
  }
  
  public function getSchemedUriFromPublicUri($file_uri) {
  
    return str_replace(
      \Drupal::service('stream_wrapper.public')->baseUrl(),
      'public:/',
      $file_uri
    );
  }

  /**
   * This function is called by the Views module.
   */
/*
  public function getTableMapping(array $storage_definitions = NULL) {

    $definitions = $storage_definitions ? : \Drupal::getContainer()->get('entity_field.manager')->getFieldStorageDefinitions($this->entityTypeId);

    $table_mapping = $this->tableMapping;

    return $table_mapping;
  }
*/
  /**
   * {@inheritdoc}
   */
//  public function load($id) {
//    //@TODO load WisskiEntity here
//  }

  /**
   * {@inheritdoc}
   */
#  public function loadRevision($revision_id) {
#    return NULL;
#  }

  /**
   * {@inheritdoc}
   */
#  public function deleteRevision($revision_id) {
#  }

  /**
   * {@inheritdoc}
   */
#  public function loadByProperties(array $values = array()) {
#    
#    return array();
#  }

  /**
   * {@inheritdoc}
   */
#  public function delete(array $entities) {
#  }

  /**
   * {@inheritdoc}
   */
#  protected function doDelete($entities) {
#  }

  /**
   * {@inheritdoc}
   */
/*
  public function save(EntityInterface $entity) {
#    drupal_set_message("I am saving, yay!" . serialize($entity->id()));
    return parent::save($entity);
  }
*/
  /**
   * {@inheritdoc}
   */
  protected function getQueryServiceName() {
    return 'entity.query.wisski_core';
  }

  /**
   * {@inheritdoc}
   * @TODO must be implemented
   */
#  protected function doLoadRevisionFieldItems($revision_id) {
#  }

  /**
   * {@inheritdoc}
   * @TODO must be implemented
   */
  protected function doSaveFieldItems(ContentEntityInterface $entity, array $names = []) {
#    dpm(serialize($entity), "yay?");
#    return;
#    \Drupal::logger('WissKIsaveProcess')->debug(__METHOD__ . " with values: " . serialize(func_get_args()));
#    \Drupal::logger('WissKIsaveProcess')->debug(serialize($entity->uid->getValue())); 
#    dpm(func_get_args(),__METHOD__);
#    return;

#    dpm($entity->uid->getValue(), 'uid');
#    dpm(serialize($entity), "lustige entity");
#    return NULL;

    // first we get the languages of the entity that should be saved
    // because it might be that a language should be deleted
    // only do detailed handling of the language should be saved
    // otherwise we do nothing - thats what we can do best anyway ;D
#    $translation_languages = $entity->getTranslationLanguages();
    
#    dpm(serialize($translation_languages));

    $moduleHandler = \Drupal::service('module_handler');
    if (!$moduleHandler->moduleExists('wisski_pathbuilder')){
      return NULL;
    }
                      

    $uid = $entity->uid;
    // override the user setting
    if(isset($uid) && empty($uid->getValue()['target_id']) ) {
      $user = \Drupal::currentUser();
#    dpm($values, "before");
      $uid->setValue(array('target_id' => (int)$user->id()));
    }
    
#    dpm($entity->uid->getValue(), 'uid');


    // gather values with property caching
    // set second param of getValues to FALSE: we must not write
    // field values to cache now as there may be no eid yet (on create)

#    $entity->updateOriginalValues();

    list($values,$original_values) = $entity->getValues($this,FALSE);
    
#    dpm($values, "yay?");
#    dpm($original_values, "ori?");
#    dpm(serialize($entity->bundle()), "ente?");
#    dpm(serialize($values['bundle'][0]['target_id']), "is sis shiit?");
#    return;
    $bundle_id = $values['bundle'][0]['target_id'];
#    dpm("saving ". $entity->id() . " with bundle " . serialize($values['bundle']) . " or " . serialize($entity->bundle()));   
    if (empty($bundle_id)) $bundle_id = $entity->bundle();
    // TODO: What shall we do if bundle_id is still empty. Can this happen?

    // we only load the pathbuilders and adapters that can handle the bundle.
    // Loading all of them would take too long and most of them don't handle
    // the bundle, assumingly.
    // We have this information cached.
    // Then we filter the writable ones
    $pbs_info = \Drupal::service('wisski_pathbuilder.manager')->getPbsUsingBundle($bundle_id);
    $adapters_ids = array();
    $pb_ids = array();
    foreach($pbs_info as $pbid => $info) {
      if ($info['writable']) {
        $aid = $info['adapter_id'];
        $pb_ids[$pbid] = $pbid;
        $adapter_ids[$aid] = $aid;
      }
      elseif ($info['preferred_local']) {
        // we warn here as the peferred local store should be writable if an 
        // entity is to be saved. Eg. the sameAs mechanism relies on that.
        \Drupal::messenger()->addWarning(t('The preferred local store %a is not writable.', array('%a' => $adapter->label())));
      } 
    }
    // if there are no adapters by now we die...
    if(empty($adapter_ids)) {
      \Drupal::messenger()->addError("There is no writable storage backend defined.");
      return;
    }
    
    $pathbuilders = WisskiPathbuilderEntity::loadMultiple($pb_ids);
    $adapters = Adapter::loadMultiple($adapter_ids);

    
    $entity_id = $entity->id();

    // we track if this is a newly created entity, if yes, we want to write it to ALL writable adapters
    $create_new = $entity->isNew() && empty($entity_id);
    $init = $create_new;
    
    // if there is no entity id yet, we register the new entity
    // at the adapters
    if (empty($entity_id)) {    
      foreach($adapters as $aid => $adapter) {
        $entity_id = $adapter->createEntity($entity);
        $create_new = FALSE;
      }
    }
    if (empty($entity_id)) {
      \Drupal::messenger()->addError('No local adapter could create the entity');
      return;
    }
    
    // now we should have an entity id and a bundle - so cache it!
    $this->writeToCache($entity_id, $bundle_id);
    
    foreach($pathbuilders as $pb_id => $pb) {
      
      //get the adapter
      $aid = $pb->getAdapterId();
      $adapter = $adapters[$aid];

      $success = FALSE;
#      drupal_set_message("I ask adapter " . serialize($adapter) . " for id " . serialize($entity->id()) . " and get: " . serialize($adapter->hasEntity($id)));
      // if they know that id
      // Martin: The former if test excluded the case where the entity was
      // there already but the adapter had no information about it, so that
      // nothing is saved in this case (into this store!). This means that
      // for an existing entity nothing can be added on save. This is the case
      // e.g. when an entity from an authority is only referred to first and
      // later someone wants to add local information. This was not possible 
      // with this if. Thats why we always want it to be true.
      if(TRUE || $create_new || $adapter->hasEntity($entity_id)) {
        
        // perhaps we have to check for the field definitions - we ignore this for now.
        //   $field_definitions = $this->entityManager->getFieldDefinitions('wisski_individual',$bundle_idid);
        try {
#          dpm($values, "write");
          //we force the writable adapter to write values for newly created entities even if unknown to the adapter by now
          //@TODO return correct success code
          
#          $translations = $entity->getTranslationLanguages();
          
#          dpm(serialize($entity), "la ente");
#          dpm(LanguageInterface::LANGCODE_DEFAULT, "default langcode?");

#          dpm(serialize($entity->get('langcode')->getValue()), "langi?");
          
          // this is the language which is selected by the user before creating something
          // (= interface language)
          if(empty($entity->langcode->getValue())) {
            $langcode = \Drupal::service('language_manager')->getCurrentLanguage()->getId();

            // we have to replace the entities language because it seems to be wrong when sent to us.
            $entity->set('langcode',array("value" => $langcode));
          } else {
            $lc = $entity->langcode->getValue();
#            $lc_prop = $entity->langcode->

            // this is evil because we take simply the first one.
            //$lc = current($lc);
            
            // go deeper...
            while(is_array($lc))
              $lc = current($lc);              
            
            $langcode = $lc;
            
#            dpm($lc);
          }
          

          // this it the prefered language of the website          
          //$default_langcode = \Drupal::service('language_manager')->getDefaultLanguage()->getId();
          //if($langcode == $default_langcode) $langcode = "und";
          
#          dpm($langcode, "current langcode?");
          
#          foreach($translations as $language => $translation) {
 #           dpm($language, "I give");
#          dpm($entity, "ent?");
#          dpm($values, "vals?");
#          dpm($pb, "pb?");
#          dpm($bundle_id, "bun?");
#          dpm($original_values, "ori?");
#          dpm($create_new, "cr?");
#          dpm($init, "init?");
#          dpm($langcode, "lang?");
          $adapter_info = $adapter->writeFieldValues($entity, $values, $pb, $bundle_id, $original_values,$create_new, $init, $langcode);
#          }

  #        dpm($entity->getTranslationLanguages(), "langs?");

          // By Mark: perhaps it would be smarter to give the writeFieldValues the entity
          // object because it could make changes to it
          // e.g. which uris were used for reference (disamb) etc.
          // as long as it is like now you can't promote uris back to the storage.
          // By Martin: this is an important point. Also the adapters should propagate
          // disamb/all ?xX uris also when loading as there is no way to trace the value
          // otherwise.

          $success = TRUE;
        } catch (\Exception $e) {
          \Drupal::messenger()->addStatus('Could not write entity into adapter '.$adapter->id() . ' because ' . serialize($e->getMessage()));
          throw $e;
        }
      } else {
        //drupal_set_message("No, I don't know " . $id . " and I am " . $aid . ".");
      }
      
      if ($success) {

        // TODO: why are the next two necessary? what do they do?
        $entity->set('eid',$entity_id);
        $entity->enforceIsNew(FALSE);
        //we have successfully written to this adapter

        // write values and weights to cache table
        // we reuse the getValues function and set the second param to true
        // as we are not interested in the values we discard them
        $entity->getValues($this, TRUE);
        // TODO: eventually there should be a seperate function for the field caching
        
      }
    }

    $bundle = WisskiBundle::load($bundle_id);
    if ($bundle) $bundle->flushTitleCache($entity_id);
 
#    dpm(serialize($entity->bundle()), "my bundle really is?");
    #    return;
#    if($entity->isNewRevision())
#      dpm(serialize("new revision!!"));
    $this->doSaveWisskiRevision($entity, $names); 
  }
  
  protected function doSaveWisskiRevision(ContentEntityInterface $entity, array $names = [])
  {
    #dpm(serialize($entity), "in do save wisski revision");

    $uid = $entity->revision_uid;
     // override the user setting
    if(isset($uid) && empty($uid->getValue()['target_id']) ) {
      $entity->revision_uid = $entity->uid;
    }
    
    // check if published is set because we will get an error
    // if it is null
    
    $published = $entity->published;
    
    if(isset($published) && empty($published->getValue())) {
      // assume everything is published.
      $entity->setPublished(TRUE);
    }
    
    // check if default langcode is set because we will get an error if it is null...
    $default_langcode = $entity->default_langcode;
    if(isset($default_langcode) && empty($default_langcode->getValue())) {
      $my_lang = \Drupal::service('language_manager')->getCurrentLanguage()->getId();
      $entity->set("default_langcode", $my_lang);
    }
    
    $original = !empty($entity->original) ? $entity->original : NULL;
    
    // skip the last step if the bundle diverges
    $skip_last_step = FALSE;
    
    if(!empty($original)) {
      if(!empty($original->bundle) && !empty($entity->bundle)) {
        $obid = $original->bundle->target_id;
        $ebid = $entity->bundle->target_id;
        if($obid === $ebid) {
          #dpm("bundles are the same!");
        } else {
          \Drupal::messenger()->addStatus('Warning: Entity ' . $entity->id() . " was loaded with a different bundle id. Most times this comes from the selection of bundle override in the WissKI Settings. Deactivate that there to get rid of this message.");
          $skip_last_step = TRUE;
          //$original->set("bundle", $entity->bundle->target_id);
          #dpm("divergent bundle: " .serialize($original->bundle->target_id) . " vs. " .serialize($entity->bundle->target_id));
          // reset original bundle
          //$entity->original = $original;
          //dpm(serialize($entity));
        }
      }
    }
    
    $full_save = empty($names);
    $update = !$full_save || !$entity->isNew();

    if ($full_save) {
      $shared_table_fields = TRUE;
      $dedicated_table_fields = TRUE;
    }
    else {
      $table_mapping = $this->getTableMapping();
      $shared_table_fields = FALSE;
      $dedicated_table_fields = [];

      // Collect the name of fields to be written in dedicated tables and check
      // whether shared table records need to be updated.
      foreach ($names as $name) {
        $storage_definition = $this->fieldStorageDefinitions[$name];
        if ($table_mapping->allowsSharedTableStorage($storage_definition)) {
          $shared_table_fields = TRUE;
        }
        elseif ($table_mapping->requiresDedicatedTableStorage($storage_definition)) {
          $dedicated_table_fields[] = $name;
        }
      }
    }

    // Update shared table records if necessary.
    if ($shared_table_fields) {
      $record = $this->mapToStorageRecord($entity->getUntranslated(), $this->baseTable);
      // Create the storage record to be saved.
      if ($update) {
#        return;
        $default_revision = $entity->isDefaultRevision();
        if ($default_revision) {
          $id = $record->{$this->idKey};
          // Remove the ID from the record to enable updates on SQL variants
          // that prevent updating serial columns, for example, mssql.
          unset($record->{$this->idKey});
          $this->database
            ->update($this->baseTable)
            ->fields((array) $record)
            ->condition($this->idKey, $id)
            ->execute();
        }
        if ($this->revisionTable) {
          if ($full_save) {
            $entity->{$this->revisionKey} = $this->saveRevision($entity);
          }
          else {
            $record = $this->mapToStorageRecord($entity->getUntranslated(), $this->revisionTable);
            // Remove the revision ID from the record to enable updates on SQL
            // variants that prevent updating serial columns, for example,
            // mssql.
            unset($record->{$this->revisionKey});
            $entity->preSaveRevision($this, $record);
            $this->database
              ->update($this->revisionTable)
              ->fields((array) $record)
              ->condition($this->revisionKey, $entity->getRevisionId())
              ->execute();
          }
        }
        if ($default_revision && $this->dataTable) {
#          return;
          $this->saveToSharedTables($entity);
        }
        if ($this->revisionDataTable) {
          $new_revision = $full_save && $entity->isNewRevision();
          $this->saveToSharedTables($entity, $this->revisionDataTable, $new_revision);
        }
      }
      else {
#        return;
        $insert_id = $this->database
          ->insert($this->baseTable, ['return' => Database::RETURN_INSERT_ID])
          ->fields((array) $record)
          ->execute();
        // Even if this is a new entity the ID key might have been set, in which
        // case we should not override the provided ID. An ID key that is not set
        // to any value is interpreted as NULL (or DEFAULT) and thus overridden.
        if (!isset($record->{$this->idKey})) {
          $record->{$this->idKey} = $insert_id;
        }
        $entity->{$this->idKey} = (string) $record->{$this->idKey};
        if ($this->revisionTable) {
          $record->{$this->revisionKey} = $this->saveRevision($entity);
        }
        if ($this->dataTable) {
          $this->saveToSharedTables($entity);
        }
        if ($this->revisionDataTable) {
          $this->saveToSharedTables($entity, $this->revisionDataTable);
        }
      }
    }

#    dpm(serialize($entity));

    if(!$skip_last_step) {
      // Update dedicated table records if necessary.
      if ($dedicated_table_fields) {
        $names = is_array($dedicated_table_fields) ? $dedicated_table_fields : [];
        $this->saveToDedicatedTables($entity, $update, $names);
      }
    }
  }

  /**
   * {@inheritdoc}
   * @TODO must be implemented
   */
  protected function doDeleteFieldItems($entities) {

    //dpm("yay????");

    $moduleHandler = \Drupal::service('module_handler');
    if (!$moduleHandler->moduleExists('wisski_pathbuilder')){
      return NULL;
    }
                      

    $local_adapters = array();
    $writable_adapters = array();
    $delete_adapters = array(); // adapters that we use for deleting the entities
#    $all_adapters = entity_load_multiple('wisski_salz_adapter');
    $all_adapters = \Drupal::entityTypeManager()->getStorage('wisski_salz_adapter')->loadMultiple();

    foreach($all_adapters as $aid => $adapter) {
      // we locate all writable stores
      // then we locate all local stores in these writable stores

      if($adapter->getEngine()->isWritable())
        $writable_adapters[$aid] = $adapter;
             
      if($adapter->getEngine()->isPreferredLocalStore())
        $local_adapters[$aid] = $adapter;
      
    }
    // if there are no adapters by now we die...
    if(empty($writable_adapters)) {
      \Drupal::messenger()->addError("There is no writable storage backend defined.");
      \Drupal::messenger()->addError("No writable storage backend defined.");
      return;
    }
    
    if($diff = array_diff_key($local_adapters,$writable_adapters)) {
      if (count($diff) === 1)
        \Drupal::messenger()->addWarning('The preferred local store '.key($diff).' is not writable');
      else \Drupal::messenger()->addWarning('The preferred local stores '.implode(', ',array_keys($diff)).' are not writable');
    }
    
    //we load all pathbuilders, check if they know the fields and have writable adapters
    $pathbuilders = WisskiPathbuilderEntity::loadMultiple();

    foreach($pathbuilders as $pb_id => $pb) {
      $aid = $pb->getAdapterId();
      //check, if it's writable, if not we can stop here
      if (isset($writable_adapters[$aid])) {
        $delete_adapters[$aid] = $writable_adapters[$aid];
      }
    }
    
    foreach($entities as $entity) {
      foreach ($delete_adapters as $adapter) {
        $return = $adapter->deleteEntity($entity);
      }
      AdapterHelper::deleteUrisForDrupalId($entity->id());
      WisskiCacheHelper::flushCallingBundle($entity->id());
    }

    if (empty($return)) {
      \Drupal::messenger()->addError('No local adapter could delete the entity');
      return;
    }
  }

  /**
   * {@inheritdoc}
   * @TODO must be implemented
   */
#  protected function doDeleteRevisionFieldItems(ContentEntityInterface $revision) {
#  }

  /**
   * {@inheritdoc}
   * @TODO must be implemented
   */
#  protected function readFieldItemsToPurge(FieldDefinitionInterface $field_definition, $batch_size) {
#    return array();
#  }

  /**
   * {@inheritdoc}
   * @TODO must be implemented
   */
#  protected function purgeFieldItems(ContentEntityInterface $entity, FieldDefinitionInterface $field_definition) {
#  }

  /**
   * {@inheritdoc}
   */
#  protected function doSave($id, EntityInterface $entity) {
#  }

  /**
   * {@inheritdoc}
   * @TODO must be implemented
   */
  protected function has($id, EntityInterface $entity) {
    
    if ($entity->isNew()) return FALSE;
#    $adapters = entity_load_multiple('wisski_salz_adapter');
    $adapters = \Drupal::entityTypeManager()->getStorage('wisski_salz_adapter')->loadMultiple();
    // ask all adapters
    foreach($adapters as $aid => $adapter) {
      if($adapter->getEngine()->hasEntity($id)) {
        return TRUE;
      }            
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   * @TODO must be implemented
   */
  public function countFieldData($storage_definition, $as_bool = FALSE) {
    //@TODO return the truth
    return $as_bool ? FALSE : 0;
  }

  /**
   * {@inheritdoc}
   */
  public function hasData() {
    //@TODO this is only for development purposes. So we can uninstall the module without having to delete data
    return FALSE;
  }  
  
  public function writeToCache($entity_id,$bundle_id) {
#    dpm($bundle_id, "bundle id for ente $entity_id: ");
#    dpm(microtime(), "mic in");
    try {
      WisskiCacheHelper::putCallingBundle($entity_id,$bundle_id);
    } catch (\Exception $e) {
#dpm(func_get_args(), 'writeToCache');
    }
#    dpm(microtime(), "mic out");
  }
  
  // WissKI image preview stuff.

    /**
   * externally prepare the preview images
   * this is necessary e.g. for views
   * @return returns true on sucess, false else.
   */
  public function preparePreviewImages() {
    $pref_local = AdapterHelper::getPreferredLocalStore();
    if (!$pref_local) {
      $conf_adapter = \Drupal::config('wisski_core.settings')->get('preview_image_adapters');
      
      if(!empty($conf_adapter)) {
        $this->preview_image_adapters = $conf_adapter;
        return TRUE;
      }
      
      \Drupal::messenger()->addWarning("No store for preview images was found. Please select one in the configuration.");
      
      return FALSE;
    } else {
      $this->adapter = $pref_local;
    
      $this->preview_image_adapters = \Drupal::config('wisski_core.settings')->get('preview_image_adapters');
      if (empty($this->preview_image_adapters)) {
        $this->preview_image_adapters = array($pref_local);
      }
    }
    return TRUE;
  }


  /**
   * this gathers the URI i.e. some public:// or remote path to this entity's
   * preview image
   */
  public function getPreviewImageUri($entity_id,$bundle_id) {
#    dpm("4.2.1: " . microtime());
    
    //first try the cache
    $preview = WisskiCacheHelper::getPreviewImageUri($entity_id);
#    dpm("4.2.2: " . microtime());
#    dpm($preview,__FUNCTION__.' '.$entity_id);
    
    if ($preview) {
      //do not log anything here, it is a performance sink
      //\Drupal::logger('wisski_preview_image')->debug('From Cache '.$preview);
      if ($preview === 'none') return NULL;
      return $preview;
    }
#    dpm("4.2.3: " . microtime());
#    dpm(serialize($this->preview_image_adapters), "pre?");
    //if the cache had nothing try the adapters
    //for this purpose we need the entity URIs, which are stored in the local
    //store, so if there is none, stop here
#    if (empty($this->preview_image_adapters)) return NULL;
#dpm("alive");
    $found_preview = FALSE;

    // we iterate through all the selected adapters but we stop at the first
    // image that was successfully converted to preview image style as we only
    // need one!
    foreach ($this->preview_image_adapters as $adapter_id => $adapter) {
      
      if ($adapter === NULL || !is_object($adapter)) {
        // we lazy-load adapters
#        dpm("4.2.99: " . microtime());
        $adapter = \Drupal::service('entity_type.manager')->getStorage('wisski_salz_adapter')->load($adapter_id);
#        dpm("4.2.999: " . microtime());
        if (empty($adapter)) {
          unset($this->preview_image_adapters[$adapter_id]);
          continue;
        } else {
          $this->preview_image_adapters[$adapter_id] = $adapter;
        }
      }
#      dpm(microtime(), "is_get_uris_evil?");
      if (empty(AdapterHelper::getUrisForDrupalId($entity_id, $adapter->id(), FALSE))) {
        // this is wrong here - any other backend might know the image!
        /*
        if (WISSKI_DEVEL) \Drupal::logger('wisski_preview_image')->debug($adapter->id().' does not know the entity '.$entity_id);
        WisskiCacheHelper::putPreviewImageUri($entity_id,'none');
        return NULL;
        */
        continue;
      }

      //ask the local adapter for any image for this entity
#      $images = $adapter->getEngine()->getImagesForEntityId($entity_id,$bundle_id);
      $images = array();
#      dpm(microtime(), "in storage1");
      
      $images = \Drupal::service('wisski_pathbuilder.manager')->getPreviewImage($entity_id, $bundle_id, $adapter);
#      dpm(microtime(), "in storage2");

#      $image_field_ids = \Drupal\wisski_core\WisskiHelper::getFieldsForBundleId($bundle_id, 'image', NULL, TRUE);

#      dpm($adapter->getEngine()->loadPropertyValuesForField(current($image_field_ids), array(), array($entity_id => $entity_id)),  "fv!");
      
#      dpm($this->getCacheValues(array($entity_id, )), "cache!");

#      dpm($images, "images");

      if(count($images) > 1) {

        $bids = array();
        $deltas = array();
        $fids = array();
        
        $ever_found_weight = FALSE;

        foreach($images as $image) { 	       

          $to_look_for = $image;

          $old_to_look_for = NULL;
          $fid_to_look_for = NULL;

          $found_weight = FALSE;

          while(!$found_weight && $old_to_look_for != $to_look_for) {

            $old_to_look_for = $to_look_for;

            // get the weight
            // TODO: Drupal Rector Notice: Please delete the following comment after you've made any necessary changes.
            // You will need to use `\Drupal\core\Database\Database::getConnection()` if you do not yet have access to the container here.
            $cached_field_values = \Drupal::database()->select('wisski_entity_field_properties', 'f')
              ->fields('f',array('eid', 'fid', 'bid', 'ident','delta','properties'));
#           ->condition('eid',$id)
#           ->condition('bid',$values[$id]['bundle'])
                        
            if(!empty($fid_to_look_for)) {
              $cached_field_values = $cached_field_values->condition('fid', $fid_to_look_for);
            }
            
            $cached_field_values = $cached_field_values->condition('ident', $to_look_for)
              ->execute()
              ->fetchAll();
              
#            dpm($cached_field_values, "looked for: " . $to_look_for);
          
            foreach($cached_field_values as $cfv) {
              // the eid from the image should be the ident of the field
              $to_look_for = $cfv->eid;

              // Mark: this is sloppy
              // in wisski this generally holds
              // however if you do entity reference to the image - this might not hold
              // then it probably should not be in fid, but in the properties or something
              // there you will have to change something in this case!!!              
              $fid_to_look_for = $cfv->bid;
              
              // delta is the weight
              $deltas[$image] = intval($cfv->delta);              
            }
            
            // didn't find anything?
            if(empty($deltas) || empty($deltas[$image])) {
              $deltas[$image] = 0;
//              $found_weight = TRUE;
            }
 
            // did we find a weight?           
            if($deltas[$image] != 0) {
              $found_weight = TRUE;
              $ever_found_weight = TRUE;
            }
          }        
        }

#        dpm($image, "image");
#        dpm($deltas, "weight");        

        // sort for weight
        if($ever_found_weight)
          asort($deltas);

#        dpm($found_weight);  
#        dpm($deltas, "after");
        
#        dpm(array_keys($deltas), "ak");
        
        // give out only the lightest one!
        $images = array(current(array_keys($deltas)));
        
                                                           
        
      }
 
      #dpm($images, "out");
      
      #dpm(microtime(), "in storage3");
      
#      dpm($images, "yay");
#    dpm("4.2.4: " . microtime());

      if (empty($images)) {
        if (WISSKI_DEVEL) \Drupal::logger('wisski_preview_image')->debug('No preview images available from adapter '.$adapter->id());
        continue;
      }
      
      $found_preview = TRUE;

      if (WISSKI_DEVEL) \Drupal::logger('wisski_preview_image')->debug("Images from adapter $adapter_id: ".serialize($images));
      //if there is at least one, take the first of them
      //@TODO, possibly we can try something mor sophisticated to find THE preview image
      $input_uri = current($images);
      
      if(empty($input_uri)) {
        if (WISSKI_DEVEL) \Drupal::logger('wisski_preview_image')->debug('No preview images available from adapter '.$adapter->id());
        continue;
      }
      
    #dpm("4.2.4.1: " . microtime());
      //now we have to ensure there is the correct image file on our server
      //and we get a derivate in preview size and we have this derivates URI
      //as the desired output
      $output_uri = '';
      
      //get a correct image uri in $output_uri, by saving a file there
      #$this->storage->getFileId($input_uri,$output_uri);
      // generalized this line for external use
      $this->getFileId($input_uri, $output_uri);

#    dpm("4.2.4.2: " . microtime());
      //try to get the WissKI preview image style
      $image_style = $this->getPreviewStyle();
#    dpm("4.2.5: " . microtime());    
      //process the image with the style
      $preview_uri = $image_style->buildUri($output_uri);
      #dpm(array('output_uri'=>$output_uri,'preview_uri'=>$preview_uri));
      
      // file already exists?
      if(file_exists($preview_uri)) {
#        dpm(microtime(), "file exists!");
        WisskiCacheHelper::putPreviewImageUri($entity_id,$preview_uri);
        #dpm(microtime(), "file exists 2");
        return $preview_uri;
      }
#      dpm($output_uri, "out");
#      dpm($preview_uri, "pre");
#      dpm($image_style->createDerivative($output_uri,$preview_uri), "create!");
      if ($out = $image_style->createDerivative($output_uri,$preview_uri)) {
        //drupal_set_message('Style did it - uri is ' . $preview_uri);
        WisskiCacheHelper::putPreviewImageUri($entity_id,$preview_uri);
        //we got the image resized and can output the derivates URI
        return $preview_uri;
      } else {
        \Drupal::messenger()->addError("Could not create a preview image for $input_uri. Probably its MIME-Type is wrong or the type is not allowed by your Imge Toolkit");
        WisskiCacheHelper::putPreviewImageUri($entity_id,NULL);
      }

    }
    
    if(empty($preview_uri) || empty($found_preview)) {
      
      $image_style = $this->getPreviewStyle();
      $output_uri = drupal_get_path('module', 'wisski_core') . "/images/img_nopic.png";
#      dpm($output_uri, "out");
      $preview_uri = $image_style->buildUri($output_uri);
      
      $existing_files = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties([
        'uri' => $preview_uri,
      ]);

      if (!count($existing_files)) {
        
        $user = \Drupal::currentUser();
        
        $file = File::create([
          'uri' => $preview_uri,
          'uid' => $user
            ->id(),
        ]);
      
        $file->setPermanent();
      
        $file->save();
      }
      
      if ($out = $image_style->createDerivative($output_uri,$preview_uri)) {
        WisskiCacheHelper::putPreviewImageUri($entity_id,$preview_uri);
        return $preview_uri;
      }
    }
    

#    dpm("could not find preview for " . $entity_id);
    return NULL;

  }
  
  
  /**
   * loads and - if necessary - in advance generates the 'wisski_preview' ImageStyle
   * object
   * the style resizes - mostly downsizes - the image and converts it to JPG
   */
  private function getPreviewStyle() {

    //cached?    
    if (isset($this->image_style)) return $this->image_style;
    
    //if not, try to load 'wisski_preview'
    $image_style_name = 'wisski_preview';

    $image_style = ImageStyle::load($image_style_name);
    if (is_null($image_style)) {
      //if it's not there we generate one
      
      //first create the container object with correct name and label
      $values = array('name'=>$image_style_name,'label'=>'Wisski Preview Image Style');
      $image_style = ImageStyle::create($values);
      
      //then gather and set the default values, those might have been set by 
      //the user
      //@TODO tell the user that changing the settings after the style has
      //been created will not result in newly resized images
      $settings = \Drupal::config('wisski_core.settings');
      $w = $settings->get('wisski_preview_image_max_width_pixel');
      $h = $settings->get('wisski_preview_image_max_height_pixel');      
      $config = array(
        'id' => 'image_scale',
        'data' => array(
          //set width and height and disallow upscale
          //we believe 100px to be an ordinary preview size
          'width' => isset($w) ? $w : 100,
          'height' => isset($h) ? $h : 100,
          'upscale' => FALSE,
        ),
      );
#wpm($config,'image style config');
      //add the resize effect to the style
      $image_style->addImageEffect($config);
      
      //configure and add the JPG conversion
      $config = array(
        'id' => 'image_convert',
        'data' => array(
          'extension' => 'jpeg',
        ),
      );
      $image_style->addImageEffect($config);
      $image_style->save();
    }
    $this->image_style = $image_style;
    return $image_style;
  }

#  protected function doLoadMultipleRevisionsFieldItems($revision_ids) {
#    // does not work yet.
#    return;
#  }

#  public function getSqlQuery() {
#    dpm("yay, Im here!");
#    return parent::getQuery();
#  }

  public function getDatabase() {
    return $this->database;
  }
  
  protected function buildQuery($ids, $revision_ids = FALSE) {
    $query = $this->database->select($this->dataTable, 'base');

    $query->addTag($this->entityTypeId . '_load_multiple');

    if ($revision_ids) {
      $query->join($this->revisionTable, 'revision', "revision.{$this->idKey} = base.{$this->idKey} AND revision.{$this->revisionKey} IN (:revisionIds[])", [':revisionIds[]' => $revision_ids]);
    }
    elseif ($this->revisionTable) {
      $query->join($this->revisionTable, 'revision', "revision.{$this->revisionKey} = base.{$this->revisionKey}");
    }

    // Add fields from the {entity} table.
    $table_mapping = $this->getTableMapping();
    $entity_fields = $table_mapping->getAllColumns($this->dataTable);

    if ($this->revisionTable) {
      // Add all fields from the {entity_revision} table.
      $entity_revision_fields = $table_mapping->getAllColumns($this->revisionTable);
      $entity_revision_fields = array_combine($entity_revision_fields, $entity_revision_fields);
      // The ID field is provided by entity, so remove it.
      unset($entity_revision_fields[$this->idKey]);

      // Remove all fields from the base table that are also fields by the same
      // name in the revision table.
      $entity_field_keys = array_flip($entity_fields);
      foreach ($entity_revision_fields as $name) {
        if (isset($entity_field_keys[$name])) {
          unset($entity_fields[$entity_field_keys[$name]]);
        }
      }
      $query->fields('revision', $entity_revision_fields);

      // Compare revision ID of the base and revision table, if equal then this
      // is the default revision.
      $query->addExpression('CASE base.' . $this->revisionKey . ' WHEN revision.' . $this->revisionKey . ' THEN 1 ELSE 0 END', 'isDefaultRevision');
    }

    $query->fields('base', $entity_fields);

    if ($ids) {
      $query->condition("base.{$this->idKey}", $ids, 'IN');
    }
#    dpm(serialize($query), "query?");
    return $query;
  }
  
}
