<?php

/**
 * @file
 * Contains Drupal\wisski_adapter_dms\Plugin\wisski_salz\Engine\DmsEngine.
 */

namespace Drupal\wisski_adapter_dms\Plugin\wisski_salz\Engine;

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\wisski_core\Entity\WisskiBundle;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\wisski_adapter_dms\Query\Query;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity; 
use Drupal\wisski_pathbuilder\Entity\WisskiPathEntity; 
use Drupal\wisski_pathbuilder\PathbuilderEngineInterface;
use Drupal\wisski_salz\NonWritableEngineBase;
use Drupal\wisski_salz\AdapterHelper;
use DOMDocument;
use EasyRdf\Graph as EasyRdf_Graph;
use EasyRdf\RdfNamespace as EasyRdf_Namespace;
use EasyRdf\Literal as EasyRdf_Literal;

use Symfony\Component\DependencyInjection;
use Drupal\Component\Serialization\Json;

/**
 * Wiki implementation of an external entity storage client.
 *
 * @Engine(
 *   id = "dms",
 *   name = @Translation("GNM DMS"),
 *   description = @Translation("Provides access to the DMS of the Germanisches Nationalmuseum")
 * )
 */
class DmsEngine extends NonWritableEngineBase implements PathbuilderEngineInterface {

  protected $uriPattern  = "!^http://objektkatalog.gnm.de/objekt/(.+)$!u";
  
  /**
   * Workaround for super-annoying easyrdf buggy behavior:
   * it will only work on prefixed properties
   */
  protected $rdfNamespaces = array(
    'gnd' => 'http://d-nb.info/standards/elementset/gnd#',
    'geo' => 'http://www.opengis.net/ont/geosparql#',
    'sf' => 'http://www.opengis.net/ont/sf#',    
  );
  


  protected $possibleSteps = array(
      'Object' => array(
        'docid' => NULL,
        'invnr' => NULL,
        'imgid' => NULL,
        'depid' => NULL,
//        'xml' => NULL,
#        'PermanentLocationNote' => NULL,
#        'workingImageUrl' => NULL,

        'objectmetadataproviso' => NULL,
        'acquisitionproviso' => NULL,
        'LastEdition' => NULL,
        'Sammlungsreferat' => NULL,
        'AllgemeineBezeichnung' => NULL,
        'Title' => NULL,
        'BeschreibungdesObjekts' => NULL,
        'InventoryBeziehungenZuAnderenObjekten' => NULL,
        'InventoryIndividuelleEinordnung' => NULL,
        'InventoryZustandsbeschreibung' => NULL,
        'StandigerStandort' => NULL,
        'VitrinenText' => NULL,
        
        'XML_ConstructorsInfo' => NULL,
/*          'results' => array(
            'lido:eventActor' => array(
              'lido:actorInRole' => array(
                'lido:actor' => array(
                  'lido:nameActorSet' => array(
                    'lido:appellationValue' => NULL
                  )
                ),
                'lido:roleActor' => array(
                  'lido:term' => NULL,
                ),
              )
            )
          )
        ),*/
        'XML_Fundangaben' => NULL,
        'XML_ConstructionDates' => array(
          'results' => array(
            'lido:eventDate' => array(
              'lido:displayDate' => NULL,
              'lido:date' => array(
                'lido:earliestDate' => NULL,
                'lido:latestDate' => NULL,
              ),
            ),
          ),
        ),
        'XML_ConstructionEventPlaces' => array(
          'results' => array(
            'lido:eventPlace' => array(
              'lido:displayPlace' => NULL,
            ),
          ),
        ),
        'XML_ConstructionMaterialTechniques' => array(
          'results' => array(
            'lido:eventMaterialsTech' => array(
              'lido:displayMaterialsTech' => NULL,
            ),
          ),
        ),
        'XML_Classifications' => array(
          'results' => array(
            'lido:classification' => array(
              'lido:term' => NULL,
            ),
          ),
        ),
        'XML_Measurements' => array(
          'results' => array(
            'lido:displayObjectMeasurements' => NULL,
          ),
        ),
        'XML_Inscriptions' => NULL,
/*        'XML_Inscriptions' => array(
          'results' => array(
            'lido:inscriptions' => array(
              'lido:inscriptionDescription' => array(
                'lido:descriptiveNoteValue' => NULL,
              ),
            ),
          ),
        ),*/
        'XML_DarstellungSubjectSets' => array(
          'results' => array(
            'lido:subjectSet' => array(
              'lido:displaySubject' => NULL,
            ),
          ),
        ),
        'XML_MusterSubjectSets' => array(
          'results' => array(
            'lido:subjectSet' => array(
              'lido:subject' => array(
                'lido:subjectConcept' => array(
                  'lido:term'=> NULL,
                ),
              ),
            ),
          ),
        ),
        'XML_IconClassSubjectSets' => array(
          'results' => array(
            'lido:subjectSet' => array(
              'lido:subject' => array(
                'lido:subjectConcept' => array(
                  'lido:term' => NULL,
                ),
              ),
            ),
          ),
        ),
        'XML_RelatedWork_Literature' => array(
          'results' => array(
            'lido:relatedWorkSet' => array(
              'lido:relatedWork' => array(
                'lido:displayObject' => NULL,
              ),
            ),
          ),
        ),
        'imagepath' => NULL,
        'CurrentOwnership' => NULL,
              
      ),
  );

  protected $server;
  protected $database;
  protected $user;
  protected $password;
  protected $table;

  public function defaultConfiguration() {
    return parent::defaultConfiguration() + [
      'server' => "tcp:server-sql.gnm.de,1433",
      'database' => "gnm_data",
      'user' => '',
      'password' => '',
      'table' => 'dms2objektkatalog',
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {

    // this does not exist
    parent::setConfiguration($configuration);
    $this->server = $this->configuration['server'];
    $this->database = $this->configuration['database'];
    $this->user = $this->configuration['user'];
    $this->password = $this->configuration['password'];
    $this->table = $this->configuration['table'];
  }


  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return array(
      'server' => $this->server,
      'database' => $this->database,
      'user' => $this->user,
      'password' => $this->password,
      'table' => $this->table,
    ) + parent::getConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form = parent::buildConfigurationForm($form, $form_state);

    $form['server'] = array(
      '#type' => 'textfield',
      '#title' => 'Connection string for server',
      '#default_value' => $this->server,
      '#return_value' => $this->server,
    );
    
    $form['database'] = array(
      '#type' => 'textfield',
      '#title' => 'Database that should be accessed',
      '#default_value' => $this->database,
      '#return_value' => $this->database,
    );

    $form['user'] = array(
      '#type' => 'textfield',
      '#title' => 'The user for the database',
      '#default_value' => $this->user,
      '#return_value' => $this->user,
    );

    $form['password'] = array(
      '#type' => 'textfield',
      '#title' => 'The password for the database',
      '#default_value' => $this->password,
      '#return_value' => $this->password,
    );

    $form['table'] = array(
      '#type' => 'textfield',
      '#title' => 'The table name for the table to access',
      '#default_value' => $this->table,
      '#return_value' => $this->table,
    );
    
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    
    $this->server = $form_state->getValue('server');
    $this->database = $form_state->getValue('database');
    $this->user = $form_state->getValue('user');
    $this->password = $form_state->getValue('password');
    $this->table = $form_state->getValue('table');  
  }  
  
  
  
  


  /**
   * {@inheritdoc} 
   */
  public function hasEntity($entity_id) {
    // use the new function
    
#    $uris = AdapterHelper::getDrupalIdForUri($entity_id, FALSE, $this->adapterId());
    $uris = AdapterHelper::getUrisForDrupalId($entity_id, $this->adapterId());
#    dpm($uris, "uris");
    if (empty($uris)) return FALSE;
    
    #foreach ($uris as $uri) {
    #  // fetchData also checks if the URI matches the GND URI pattern
    #  // and if so tries to get the data.
      if ($this->fetchData($uris)) {
        return TRUE;
      }
    #}
 
#    if ($this->fetchData($entity_id)) {
#      return TRUE;
#    }
 
    return FALSE;
  }


  public function fetchData($uri, $id = NULL) {
#    dpm("yay?");

    if (!$id) {
      if (!$uri) {
        return FALSE;
      } elseif (preg_match($this->uriPattern, $uri, $matches)) {
        $id = $matches[1];
        $id = urldecode($id);
      } else {
        // not a URI
        return FALSE;
      }
    }

    
#    dpm($id, "yay!");
    
#    return NULL;
    
    // 
    $cache = \Drupal::cache('wisski_adapter_dms');
    $data = $cache->get($id);
    if ($data) {
#      dpm($data->data);
      return $data->data;
    }

#    dpm(microtime(), "microtime: ");
    $con = sqlsrv_connect($this->server, array("Database"=>$this->database, "UID"=>$this->user, "PWD"=>$this->password, "MultipleActiveResultSets" => false) );
#    dpm(microtime(), "microtime: ");
#    dpm(serialize(sqlsrv_errors()), "error");
    #    
    $query = "SELECT TOP 1 * FROM " . $this->table . " WHERE invnr = '" . $id . "'  AND StatusId = 5";
#    $query = "SELECT TOP 1 * FROM " . $this->table . " WHERE invnr = '" . $id . "' LEFT OUTER JOIN DMS2ObjectKatalog.PrimaryImage ON " . $this->table . ".imgid = DMS2ObjectKatalog.PrimaryImage.imageid";
#        
#    $ret = sqlsrv_query($con, $query);
#

#    dpm(microtime(), "microtime1: ");
#    $stmt = sqlsrv_prepare( $con, $query, array(), array("Scrollable" => SQLSRV_CURSOR_CLIENT_BUFFERED, "ClientBufferMaxKBSize" => 51200));
#    sqlsrv_execute( $stmt);
    $stmt = sqlsrv_query($con, $query, array(), array("Scrollable" => SQLSRV_CURSOR_CLIENT_BUFFERED, "ClientBufferMaxKBSize" => 51200));
#    dpm(microtime(), "microtime2: ");
            
#  $result = array();
#               

    if(empty($stmt))
      return array();


    $outarr = array();
    
    $keys = array_keys($this->possibleSteps['Object']);
#    dpm($keys, "key");    
    while($a_ret = sqlsrv_fetch_array($stmt))  {
#      dpm($a_ret);
#        dpm($data);
      foreach($keys as $step) {
        if(isset($a_ret[$step])) {
          if(!is_object($a_ret[$step]))
            $a_ret[$step] = htmlspecialchars_decode($a_ret[$step]);
          $data['Object'][$step] = array($a_ret[$step]);
        } else {
          $data['Object'][$step] = array();
        }
      }
    }
    
    $steps = $this->possibleSteps['Object'];
    $keys = array_keys($steps);

    if(isset($data['Object']['imagepath']) && isset($data['Object']['imagepath'][0])) {
      $imagestring = $data['Object']['imagepath'][0];
    
      $imagestring = str_replace("_k2.jpg", "_k3.jpg", $imagestring);
    
      $imagearray = explode(".jpg,", $imagestring);
      
      foreach($imagearray as $imgkey => $imgarr) {
        // as long as it is not the last one.
        if($imgkey < count($imagearray)-1)
          $imagearray[$imgkey] = $imgarr . ".jpg";
      }  
    
#    dpm($imagearray);
      $data['Object']['imagepath'] = $imagearray;
    }    

#    dpm(htmlentities(serialize($data['Object'])), "step?");
    
    // expand xml values

    foreach($keys as $step) {
      if(is_array($steps[$step])) { // if it is an array, we expand it...
#        dpm(htmlentities(serialize($data['Object'][$step])), "step?");
#        ($steps[$step]);
        
        // what do we get out there?
#        $outvals = array();
        if(!is_array($data) || !is_array($data['Object']) || !is_array($data['Object'][$step]))
          continue;
          
        // this should be only one!!!!
        foreach($data['Object'][$step] as $xml) {
#        $xml = $data['Object'][$step];
          $outvals = array();
          $parser = xml_parser_create();

          $vals = array();
          $index = array();

          xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
          xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);

          $ret = xml_parse_into_struct($parser, $xml, $vals, $index);
          xml_parser_free($parser);
 #         dpm(serialize($index), "ret?");
 #         dpm(serialize($vals), "step?");
          
          $walk_array = $steps[$step];
          $walk_values = $vals;
          
          $level=0;
          $tmp_out = &$outvals;
          
          while(!empty($walk_array)) {
            $key = key($walk_array);
            $value = current($walk_array);
            $level++;
#            dpm($outvals, "out?");            
#            dpm($key, "looking for");
#            dpm($walk_values, "in?");
            $found_something = FALSE;

            foreach($walk_values as $val_key => $one_value) {
              // we can unset it in any way to reduce the amount to search in later runs...
              // either we find what we search for anyway
              // or what we find is not relevant.

              if($one_value['tag'] == $key && $one_value['type'] != "close" && $one_value['level'] == $level) {
                
                if(count($index[$key]) > 2) {
                  unset($walk_values[$val_key]);
                  if(isset($index[$val_key]))
                    $index[$val_key] = array_slice($index[$val_key], 1, -1);  
                }

                $tmp_out = &$tmp_out[$key];
                $found_something = TRUE;

#                dpm($tmp_out, "tmp?");                
#                dpm($key, "found the value!");
#                dpm($value, "value?");
              #  dpm(
                if(is_array($value)) {
                  // search below!
                  break;
                } else {
                  // we've found what we've searched for!
                  if(isset($one_value['value'])) {
#                    dpm($tmp_out, "before");
#                    dpm($outvals, "current state");
                    $tmp_out[] = $one_value['value'];
#                    dpm($tmp_out, "after adding " . $one_value['value']);
#                    dpm(serialize($outvals), "current state");
                    $tmp_out = &$outvals;
                    unset($walk_values[$val_key]);

                    // try searching in the remaining parts again...
                    $value = $steps[$step];
                    $level = 0;
                    break;
                    #break;
                  }
                }
              }
            }
            
            if($found_something) {
              $walk_array = $value;
#              $level = 0;
            } else
              break;
          }
#          dpm($outvals, "got outvals: ");
          $data['Object'][$step] = $outvals;
          
        }
      }
    }

#    $available_languages = \Drupal::languageManager()->getLanguages();
#    $available_languages = array_keys($available_languages);
    


    $cache->set($id, $data);
#    dpm($data, "data");
    return $data;

  }

  /**
   * {@inheritdoc}
   */
  public function checkUriExists ($uri) {
    return !empty($this->fetchData($uri));
  }


  /**
   * {@inheritdoc} 
   */
  public function createEntity($entity) {
    return;
  }
  
  public function getBundleIdsForEntityId($id) {
    $uri = $this->getUriForDrupalId($id);
    $data = $this->fetchData($uri);
    
    $pbs = $this->getPbsForThis();
    $bundle_ids = array();
    foreach($pbs as $key => $pb) {
      $groups = $pb->getMainGroups();
      foreach ($groups as $group) {
        $path = $group->getPathArray(); 
#dpm(array($path,$group, $pb->getPbPath($group->getID())),'bundlep');
        if (isset($data[$path[0]])) {
          $bid = $pb->getPbPath($group->getID())['bundle'];
#dpm(array($bundle_ids,$bid),'bundlesi');
          $bundle_ids[] = $bid;
        }
      }
    }
    
#dpm($bundle_ids,'bundles');

    return $bundle_ids;

  }


  /**
   * {@inheritdoc} 
   */
  public function loadFieldValues(array $entity_ids = NULL, array $field_ids = NULL, $bundle = NULL,$language = LanguageInterface::LANGCODE_DEFAULT) {
#    dpm("load field values!");    
    if (!$entity_ids) {
      // TODO: get all entities
      $entity_ids = array(
        "http://d-nb.info/gnd/11852786X"
      );
    }
    
    $out = array();

    foreach ($entity_ids as $eid) {

      foreach($field_ids as $fkey => $fieldid) {  
        
        $got = $this->loadPropertyValuesForField($fieldid, array(), $entity_ids, $bundleid_in);

        if (empty($out)) {
          $out = $got;
        } else {
          foreach($got as $eid => $value) {
            if(empty($out[$eid])) {
              $out[$eid] = $got[$eid];
            } else {
              $out[$eid] = array_merge($out[$eid], $got[$eid]);
            }
          }
        }

      }
 
    }

    return $out;

  }
  
  
  /**
   * {@inheritdoc} 
   */
  public function loadPropertyValuesForField($field_id, array $property_ids, array $entity_ids = NULL, $bundleid_in = NULL) {
#dpm(func_get_args(), 'lpvff');

    $main_property = FieldStorageConfig::loadByName('wisski_individual', $field_id);
    if(!empty($main_property)) {
      $main_property = $main_property->getMainPropertyName();
    }
    
#     drupal_set_message("mp: " . serialize($main_property) . "for field " . serialize($field_id));
#    if (in_array($main_property,$property_ids)) {
#      return $this->loadFieldValues($entity_ids,array($field_id),$language);
#    }
#    return array();

    if(!empty($field_id) && empty($bundleid_in)) {
      $this->messenger()->addError("Es wurde $field_id angefragt und bundle ist aber leer.");
      #dpm(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
      return;
    }
    

    $pbs = array($this->getPbForThis());
    $paths = array();
    foreach($pbs as $key => $pb) {
      if (!$pb) continue;
      $field = $pb->getPbEntriesForFid($field_id);
#dpm(array($key,$field),'öäü');
      if (is_array($field) && !empty($field['id'])) {
        $paths[] = WisskiPathEntity::load($field["id"]);
      }
    }
      
    $out = array();

    foreach ($entity_ids as $eid) {
      
      if($field_id == "eid") {
        $out[$eid][$field_id] = array($eid);
      } elseif($field_id == "name") {
        // tempo hack
        $out[$eid][$field_id] = array($eid);
        continue;
      } elseif($field_id == "field_permalink") {
        // tempo hack
        $out[$eid][$field_id] = array("http://objektkatalog.gnm.de/objekt/");
        continue;
      } elseif($field_id == "field_iiif_link") {
        // tempo hack
        $out[$eid][$field_id] = array("Hallo welt!");
        continue;
      } elseif ($field_id == "bundle") {
      
      // Bundle is a special case.
      // If we are asked for a bundle, we first look in the pb cache for the bundle
      // because it could have been set by 
      // measures like navigate or something - so the entity is always displayed in 
      // a correct manor.
      // If this is not set we just select the first bundle that might be appropriate.
      // We select this with the first field that is there. @TODO:
      // There might be a better solution to this.
      // e.g. knowing what bundle was used for this id etc...
      // however this would need more tables with mappings that will be slow in case
      // of a lot of data...
        
        if(!empty($bundleid_in)) {
          $out[$eid]['bundle'] = array($bundleid_in);
          continue;
        } else {
          // if there is none return NULL
          $out[$eid]['bundle'] = NULL;
          continue;
        }
      } else {
#        dpm($paths, "paths?");
        if (empty($paths)) {
#          $out[$eid][$field_id] = NULL;              
        } else {
          
          foreach ($paths as $key => $path) {
          
            if($path->isGroup()) {
              #dpm("it is a group!");
#              $ref = array();
#              foreach($entity_ids as $eid) {
#                $ref[] = array('target_id' => $eid, 'value' => $eid);
#              }
##              
#              $out[$eid][$field_id] = $ref;
              
            } else {
              $values = $this->pathToReturnValue($path, $pbs[$key], $eid, 0, $main_property);
              if (!empty($values)) {
                 $out[$eid][$field_id][] = $values;
#                foreach ($values as $v) {
#                  $out[$eid][$field_id][] = $v;
#                }
              }
            }
          }
        }
      }
    }
   
#dpm($out, 'lfp');   
    return $out;

  }


  public function pathToReturnValue($path, $pb, $eid = NULL, $position = 0, $main_property = NULL, $relative = TRUE, $language = LanguageInterface::LANGCODE_DEFAULT) {
#dpm($path->getName(), 'spam');

    $languages = array();
    if($language == LanguageInterface::LANGCODE_DEFAULT)
      $language = \Drupal::service('language_manager')->getCurrentLanguage()->getId();

    if($language == "all") {
      $to_load = \Drupal::languageManager()->getLanguages();
      $languages = array_keys($to_load);

#      dpm($languages, "langs?");
    } else {
      $languages = array($language);
    }



    $field_id = $pb->getPbPath($path->getID())["field"];

    $uri = AdapterHelper::getUrisForDrupalId($eid, $this->adapterId());
    $data = $this->fetchData($uri);
#    dpm($data, "data");
    if (!$data) {
      return [];
    }
    $path_array = $path->getPathArray();
    $path_array[] = $path->getDatatypeProperty();
    $data_walk = $data;
#    dpm($data_walk, "data");
#    dpm($path_array, "pa");

#    dpm(serialize($path));
  
    do {
      $step = array_shift($path_array);

      if(empty($step))
        break;

      if (isset($data_walk[$step])) {
        $data_walk = $data_walk[$step];
      } else {
        // this is oversimplified in case there is another path in question but this
        // one had no data. E.g. a preferred name exists, but no variant name and 
        // the variant name is questioned. Then it will resolve most of the array
        // up to the property and then stop here. 
        //
        // in this case nothing should stay in $data_walk because
        // the foreach below would generate empty data if there is something
        // left.
        // By Mark: I don't know if this really is what should be here, martin
        // @Martin: Pls check :)
        $data_walk = array();
        continue; // go to the next path
      }
    } while (!empty($path_array));
    
    
    
    // now data_walk contains only the values
    $out = array();
#    dpm($data_walk, "walk");
#    return $out;
    
    $available_languages = \Drupal::languageManager()->getLanguages();
    $available_languages = array_keys($available_languages);
    
    foreach ($data_walk as $value) {
      if (empty($main_property)) {
        $out[] = $value;
      } else {
        $out[] = array($main_property => $value, 'wisski_language' => $language);
      }
    }
    
#    dpm(serialize($out));

    // add languages
/*
    $available_languages = \Drupal::languageManager()->getLanguages();
    $available_languages = array_keys($available_languages);

    $real_out = array();
    
    foreach($available_languages as $lang) {
      $real_out[$lang] = $out;
    }
    
    dpm($real_out, "real out?");
    return $real_out;
*/  
#    dpm(serialize($out));
    return $out;

  }


  /**
   * {@inheritdoc} 
   */
  public function getPathAlternatives($history = [], $future = []) {
#    dpm($history);
    if (empty($history)) {
      $keys = array_keys($this->possibleSteps);
      return array_combine($keys, $keys);
    } else {
#      dpm($history, "hist");
      $steps = $this->possibleSteps;
      
#      dpm($steps, "keys");
      // go through the history deeper and deeper!
      foreach($history as $hist) {
#        $keys = array_keys($this->possibleSteps);
        
        // if this is not set, we can not go in there.
        if(!isset($steps[$hist])) {
          return array();
        } else {
          $steps = $steps[$hist];
        }
      }
      
      // see if there is something
      $keys = array_keys($steps);
      
      if(!empty($keys))
        return array_combine($keys, $keys);
      
      return array();
    }
  }
  
  
  /**
   * {@inheritdoc} 
   */
  public function getPrimitiveMapping($step) {
    $keys = array_keys($this->possibleSteps[$step]);
    return array_combine($keys, $keys);
  }
  
  
  /**
   * {@inheritdoc} 
   */
  public function getStepInfo($step, $history = [], $future = []) {
    return array($step, '');
  }


  public function getQueryObject(EntityTypeInterface $entity_type,$condition, array $namespaces) {
    return new Query($entity_type,$condition,$namespaces, $this);
  }

  public function providesDatatypeProperty() {
    return TRUE;
  }

    /**
   * Gets the bundle and loads every individual in the store
   * the fun is - we only can handle objects, so we give them to them.
   *
   */ 
  public function loadIndividualsForBundle($bundleid, $pathbuilder, $limit = NULL, $offset = NULL, $count = FALSE, $conditions = FALSE) {

    if(empty($bundleid)) {
      foreach($conditions as $cond) {
        if($cond["field"] == "bundle") {
          $val = $cond["value"];
          $bundleid = $val;
        }
      }
    }

    // check if it knows the bundle.
    $group = $pathbuilder->getGroupsForBundle(current($bundleid));

#    dpm($conditions, "cond?");    
#    dpm($bundleid, "bundle?");
    
#    dpm($group, "group?");
    
    // if not - return nothing!
    if(empty($group))
      return array();

#    dpm(serialize($pathbuilder), "pb");

#    dpm(serialize($bundleid), "bundle?");


#    dpm(microtime(), "mic");
    $con = sqlsrv_connect($this->server, array("Database"=>$this->database, "UID"=>$this->user, "PWD"=>$this->password, "MultipleActiveResultSets" => false) );
#    $con = sqlsrv_connect($this->server, array("Database"=>$this->database, "UID"=>$this->user, "PWD"=>$this->password) );
  
#    dpm(serialize(sqlsrv_errors()), "error?");

#    dpm(microtime(), "mic2");

#    dpm($offset, "offset");
#    dpm(serialize($count), "cnt");

#    dpm($conditions, "cond");
    
    $where = "";
    
    // build conditions for where
    foreach($conditions as $cond) {
      // if it is a bundle condition, skip it...
      if($cond['field'] == "bundle")
        continue;
        
      if($cond['field'] == "label") {
#        // for now guess this is the inventory number
#        $cond['field'] = "yay.invnr";
#        dpm($bundleid, "bundle?");
        //is an array?
        $bundleid = current($bundleid);
        $bundle = WisskiBundle::load($bundleid);
        $tp = $bundle->getTitlePattern();
        
        $first = "";
        $first_weight = 100000000000000;
        
        foreach($tp as $key => $thing) {
#          dpm($thing, "iterating");
          if(isset($thing['weight']) && $thing['weight'] < $first_weight) {
            $first = $thing['name'];
            $first_weight = $thing['weight'];
          }
        }
        
        $cond['field'] = $first;
        
      }
      
#      dpm($cond['field'], "cond is?");
        
      $pb_and_path = explode(".", $cond['field']);
      
      $pathid = $pb_and_path[1];
      
      if(empty($pathid))
        continue;
            
      $path = WisskiPathEntity::load($pathid);
      
      if(empty($path))
        continue;         
      
      if($where != "")
        $where .= " AND";
      else
        $where .= "WHERE";

#      dpm($cond);
      if($cond['operator'] == "starts" || $cond['operator'] == "STARTS_WITH")
        $where .= " LOWER(convert(varchar(1000)," . $path->getDatatypeProperty() . ")) LIKE LOWER('" . $cond['value'] . "%') ";      
      else if($cond['operator'] == "ends" || $cond['operator'] == "ENDS_WITH")
        $where .= " LOWER(convert(varchar(1000)," . $path->getDatatypeProperty() . ")) LIKE LOWER('%" . $cond['value'] . "') ";
      else if($cond['operator'] == "in" || $cond['operator'] == "CONTAINS" || $cond['operator'] == "contains")
        $where .= " LOWER(convert(varchar(1000)," . $path->getDatatypeProperty() . ")) LIKE LOWER('%" . $cond['value'] . "%') ";
      else if($cond['operator'] == "=")
        $where .= " convert(varchar(1000)," . $path->getDatatypeProperty() . ") = '" . $cond['value'] . "' ";
      else if($cond['operator'] == "NOT_EMPTY")
        $where .= " " . $path->getDatatypeProperty() . " <> ''";
      else if($cond['operator'] == "EMPTY")
              $where .= " " . $path->getDatatypeProperty() . " IS NULL OR datalength(" . $path->getDatatypeProperty() .")=0 ";
      else
        $this->messenger()->addError("Operator " . $cond['operator'] . " not supported - sorry.");
      
    }

#    dpm(microtime(), "after cond?");

    if(empty($where))
      $where = "WHERE StatusId = 5";
    else
      $where .= " AND StatusId = 5";

#    dpm($where, "where?");

    if($count) {
      $query = "SELECT DISTINCT docid FROM " . $this->table . " $where";
#      $query = "SELECT COUNT(dbo.XmlFiles.DocumentId) AS DocCount FROM dbo.XmlFiles";  #" . $this->table;
#      $query = "select max(ROWS) from sysindexes where id = object_id('dbo.XmlFiles')";
      
#      $query = "select sum (spart.rows) from sys.partitions spart where spart.object_id = object_id(" . $this->table . ") and spart.index_id < 2";
#      dpm($query, "query");
#      dpm(microtime(), "micin?");
#      $stmt = sqlsrv_prepare( $con, $query, array(), array("Scrollable" => SQLSRV_CURSOR_CLIENT_BUFFERED, "ClientBufferMaxKBSize" => 51200));
#      sqlsrv_execute( $stmt);
      $stmt = sqlsrv_query( $con, $query, array(), array("Scrollable" => SQLSRV_CURSOR_CLIENT_BUFFERED, "ClientBufferMaxKBSize" => 51200));
#      $ret = sqlsrv_query($con, $query);
#      dpm(serialize($ret), "ret?");
      if(empty($stmt))
        return array();


      
      $cnt = sqlsrv_num_rows( $stmt );
      
#      while($a_ret = sqlsrv_fetch_array($ret))  {
#        dpm($a_ret, "ret");
#        $cnt = $a_ret[0];
#      }
#      dpm(microtime(), "micent");
      return $cnt;
    } else {
#    
      $limitstr = "";
      if($limit > 0)
        $limitstr = " TOP " . $limit;
      
      $fromnumber = $offset;
      $tonumber = $offset+$limit;  
      
#      dpm($fromnumber, "from");
#      dpm($tonumber, "to");
#       dpm(microtime(), "micbef");      

      if($tonumber > 0)
        $query = "SELECT * FROM " . $this->table . " $where ORDER BY docid OFFSET " . $offset . " ROWS FETCH NEXT " . $limit . " ROWS ONLY"; 
#        $query = "SELECT * FROM ( SELECT *, ROW_NUMBER() over (ORDER BY docid) as ct FROM " . $this->table . " $where) sub where ct > " . $fromnumber . " and ct <= " . $tonumber . "";
      else
        $query = "SELECT * FROM " . $this->table . " $where ";
#      dpm($query, "query");
#      $query .= " COLLATE SQL_Latin1_General_CP1_CI_AS";
#      return array();
#        
#      $stmt = sqlsrv_prepare( $con, $query, array(), array("Scrollable" => SQLSRV_CURSOR_CLIENT_BUFFERED, "ClientBufferMaxKBSize" => 51200));
      $stmt = sqlsrv_query( $con, $query, array(), array("Scrollable" => SQLSRV_CURSOR_CLIENT_BUFFERED, "ClientBufferMaxKBSize" => 51200));      
      #$ret = sqlsrv_query($con, $query);

#      dpm(microtime(), "bef ex");

#      sqlsrv_execute( $stmt); 
      if(empty($stmt))
        return array();

#      dpm($ret, "ret?");
#            
#  $result = array();
#     dpm(microtime(), "micin");          
      $outarr = array();
#      dpm(serialize(sqlsrv_errors()), "error?");
      while($a_ret = sqlsrv_fetch_array($stmt, SQLSRV_SCROLL_FIRST))  {
#        dpm(serialize($a_ret), "??");
#        dpm(microtime(), "start");
        $invnr = $a_ret['invnr'];
        
        $invnr = urlencode($invnr);
        
        $uri = "http://objektkatalog.gnm.de/objekt/" . $invnr;

        $uriname = AdapterHelper::getDrupalIdForUri($uri,TRUE,$this->adapterId());
        $outarr[$uriname] = array('eid' => $uriname, 'bundle' => $bundleid, 'name' => $uri);
#        dpm(microtime(), "end");
      }
#      dpm(serialize(sqlsrv_errors()), "error?");
#      dpm(serialize($outarr), "out?");
#      dpm(microtime(), "micend");
      return $outarr;
    }
  }

  public function getImagesForEntityId($entityid, $bundleid) {
    $pbs = $this->getPbsForThis();

    //$entityid = $this->getDrupalId($entityid);



    $ret = array();

    foreach($pbs as $pb) {
#    drupal_set_message("yay!" . $entityid . " and " . $bundleid);

      $groups = $pb->getGroupsForBundle($bundleid);

      foreach($groups as $group) {
        $paths = $pb->getImagePathIDsForGroup($group->id());

#      drupal_set_message("paths: " . serialize($paths));

        foreach($paths as $pathid) {

          $path = WisskiPathEntity::load($pathid);

#        drupal_set_message(serialize($path));

#        drupal_set_message("thing: " . serialize($this->pathToReturnValue($path->getPathArray(), $path->getDatatypeProperty(), $entityid, 0, NULL, 0)));

          // this has to be an absulte path - otherwise subgroup images won't load.
          $new_ret = $this->pathToReturnValue($path, $pb, $entityid, 0, NULL, FALSE);
#          if (!empty($new_ret)) dpm($pb->id().' '.$pathid.' '.$entitid,'News');
          $ret = array_merge($ret, $new_ret);

        }
      }
    }
#    drupal_set_message("returning: " . serialize($ret));
    //dpm($ret,__FUNCTION__);
    return $ret;
  }


} 
