<?php

/**
 * @file
 * Contains Drupal\wisski_adapter_gnd\Plugin\wisski_salz\Engine\GndEngine.
 */

namespace Drupal\wisski_adapter_gnd\Plugin\wisski_salz\Engine;

require __DIR__ . '/../../../../../..//vendor/autoload.php';

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\wisski_adapter_gnd\Query\Query;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity; 
use Drupal\wisski_pathbuilder\Entity\WisskiPathEntity; 
use Drupal\wisski_pathbuilder\PathbuilderEngineInterface;
use Drupal\wisski_salz\NonWritableEngineBase;
use Drupal\wisski_salz\AdapterHelper;
use DOMDocument;
use EasyRdf\Graph as EasyRdf_Graph;
use EasyRdf\RdfNamespace as EasyRdf_Namespace;
use EasyRdf\Literal as EasyRdf_Literal;

/**
 * Wiki implementation of an external entity storage client.
 *
 * @Engine(
 *   id = "gnd",
 *   name = @Translation("DNB GND"),
 *   description = @Translation("Provides access to the Gemeinsame Normdatei of the Deutsche Nationalbibliothek")
 * )
 */
class GndEngine extends NonWritableEngineBase implements PathbuilderEngineInterface {
  
  protected $uriPattern  = "!^http[s]*://d-nb.info/gnd/(.+)$!u";
  protected $fetchTemplate = "http://d-nb.info/gnd/{id}/about/lds";
  
  /**
   * Workaround for super-annoying easyrdf buggy behavior:
   * it will only work on prefixed properties
   */
  protected $rdfNamespaces = array(
    'gndo' => 'https://d-nb.info/standards/elementset/gnd#',
    'geo' => 'http://www.opengis.net/ont/geosparql#',
    'sf' => 'http://www.opengis.net/ont/sf#',    
  );
  


  protected $possibleSteps = array(
      'ConferenceOrEvent' => array(
        'gndo:preferredNameForTheConferenceOrEvent' => NULL,
        'gndo:variantNameForTheConferenceOrEvent' => NULL,
        ),
      'CorporateBody' => array(
        'gndo:preferredNameForTheCorporateBody' => NULL,
        'gndo:variantNameForTheCorporateBody' => NULL,
        ),
      'Family' => array(
        'gndo:preferredNameForTheFamily' => NULL,
        'gndo:variantNameForTheFamily' => NULL,
        ),
      'Person' => array(
        'gndo:preferredNameForThePerson' => NULL,
        'gndo:variantNameForThePerson' => NULL,
        ),
      'PlaceOrGeographicName' => array(
        'gndo:preferredNameForThePlaceOrGeographicName' => NULL,
        'gndo:variantNameForThePlaceOrGeographicName' => NULL,
        ),
      'TerritorialCorporateBodyOrAdministrativeUnit' => array(
        'gndo:preferredNameForThePlaceOrGeographicName' => NULL,
        'gndo:variantNameForThePlaceOrGeographicName' => NULL,
        'geo:hasGeometry geo:asWKT' => NULL, 
        ),
      'SubjectHeading' => array(
        'gndo:preferredNameForTheSubjectHeading' => NULL,
        'gndo:variantNameForTheSubjectHeading' => NULL,
        ),
      'Work' => array(
        'gndo:preferredNameForTheWork' => NULL,
        'gndo:variantNameForTheWork' => NULL,
        ),
  );


  /**
   * {@inheritdoc} 
   */
  public function hasEntity($entity_id) {
    // use the new function
    // By Mark: This fetches all uris to later throw them away. 
    // why should we do this? I change that... hopefully
    // it will work later on.
    //$uris = AdapterHelper::doGetUrisForDrupalIdAsArray($entity_id);
    // we now ask for the right one right away.
    $uris = AdapterHelper::getUrisForDrupalId($entity_id, $this->adapterId(), FALSE);

    if (empty($uris)) return FALSE;
    else
      return TRUE;
//    foreach ($uris as $uri) {
      // fetchData also checks if the URI matches the GND URI pattern
      // and if so tries to get the data.
    // By Mark: I think this is useless by now. If we know the uri, we know the uri
    // if not, we don't ask for it... this would be pointless!
    //if ($this->fetchData($uri)) {
    //  return TRUE;
    //}
    //}
    //return FALSE;
  }


  public function fetchData($uri = NULL, $id = NULL) {

#    dpm("yay?");
    
    if (!$id) {
      if (!$uri) {
        return FALSE;
      } elseif (preg_match($this->uriPattern, $uri, $matches)) {
        $id = $matches[1];
      } else {
        // not a URI
        return FALSE;
      }
    }
    
    // 
    $cache = \Drupal::cache('wisski_adapter_gnd');
    $data = $cache->get($id);

#    dpm($data, "from cache?");
    if ($data) {
      return $data->data;
    }

    $replaces = array(
      '{id}' => $id,
    );
    $fetchUrl = strtr($this->fetchTemplate, $replaces);

    $data = file_get_contents($fetchUrl);
#    dpm($data, "data?");
    if ($data === FALSE || empty($data)) {
      return FALSE;
    }

#    $doc = new DOMDocument();
#    if (!$doc->load($fetchUrl)) {
#      return FALSE;
#    }

#    dpm($fetchUrl, "fu?");
    $graph = new EasyRdf_Graph($fetchUrl, $data, 'turtle');
#    dpm($graph, "graph?");    
    if ($graph->countTriples() == 0) {
      return FALSE;
    }

    foreach ($this->rdfNamespaces as $prefix => $ns) {
      EasyRdf_Namespace::set($prefix, $ns);
    }

    $data = array();


// Property Chains don't work with unnamed bnodes :/
    foreach ($this->possibleSteps as $concept => $rdfPropertyChains) {
#      dpm($concept, "con?");
#      dpm($rdfPropertyChains, "rdf?");
      foreach ($rdfPropertyChains as $propChain => $tmp) {
        $pChain = explode(' ', $propChain);
        $dtProp = NULL;
        if ($tmp === NULL) {
          // last property is a datatype property
          $dtProp = array_pop($pChain);
        }
        
        
#        dpm($dtProp, "yay!");
//        $resources = array($uri => $uri);
//	By Mark: GND seems to change itself to use https
//      if you still have http in the scheme it answers with
//      https breaking the line above!
//      instead, we try it otherwise.

        $res = $graph->resources();

#        dpm(serialize(array_keys($res)), "res?");

        if(!in_array($uri, array_keys($res))) {
          $newuri = str_replace("http://", "https://", $uri);
        
          $resources = array($newuri => $newuri);
        } else {
          $resources = array($uri => $uri);
        }
        
        

        foreach ($pChain as $prop) {
          $newResources = array();
          foreach ($resources as $resource) {
#            dpm($graph->properties($resource), "props");
#            dpm($graph->allResources($resource, $prop), "Getting Resource $resource for prop $prop");
            foreach ($graph->allResources($resource, $prop) as $r) {
#              dpm($r, "er");
#              if(!empty($r->getUri())
              $newResources[$r->getUri()] = $r;
            }
          }
#          dpm($resources, "old");
#          dpm($newResources, "new");
          $resources = $newResources;
        }
        if ($dtProp) {
#          dpm($resources, "my res?");
          foreach ($resources as $resource) {
#            dpm($graph, "dtprop!");
            
            foreach ($graph->all($resource, $dtProp) as $thing) {
#              dpm($thing->getDatatype(), "thing");
#              dpm($dtProp, "dtprop!");
              
              if($thing->getDatatype() == "geo:wktLiteral") {
                // unluckily GND is not very WKT-conforming...
                $value = $thing->getValue();
                $value = str_replace("+", "", $value);
                $value = str_replace("Point", "POINT", $value);
                $value = str_replace(" ( ", "(", $value);
                $value = str_replace(" ) ", ")", $value);

#                $value = "POINT ( 011 011 )";
 
                
                $data[$concept][$propChain][] = $value;
              } else if ($thing instanceof EasyRdf_Literal) {
                $data[$concept][$propChain][] = $thing->getValue();
//              } else {
//                $data[$field][] = $thing->getUri();
              }
            }
          }
        }      
      }
    }

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
#      dpm(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
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
        
        if (empty($paths)) {
#          $out[$eid][$field_id] = NULL;              
        } else {
          
          foreach ($paths as $key => $path) {
            $values = $this->pathToReturnValue($path, $pbs[$key], $eid, 0, $main_property);
            if (!empty($values)) {
              foreach ($values as $v) {
                $out[$eid][$field_id][] = $v;
              }
            }
          }
        }
      }
    }
   
#dpm($out, 'lfp');   
    return $out;

  }


  public function pathToReturnValue($path, $pb, $eid = NULL, $position = 0, $main_property = NULL) {
#dpm($path->getName(), 'spam');
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
    do {
      $step = array_shift($path_array);
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
    foreach ($data_walk as $value) {
      if (empty($main_property)) {
        $out[] = $value;
      } else {
        $out[] = array($main_property => $value);
      }
    }
#    drupal_set_message(serialize($out));
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
    return new Query($entity_type,$condition,$namespaces);
  }

  public function providesDatatypeProperty() {
    return TRUE;
  }


} 
