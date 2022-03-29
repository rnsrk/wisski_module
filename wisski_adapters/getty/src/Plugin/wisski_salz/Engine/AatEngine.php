<?php

/**
 * @file
 * Contains Drupal\wisski_adapter_aat\Plugin\wisski_salz\Engine\AatEngine.
 */

namespace Drupal\wisski_adapter_aat\Plugin\wisski_salz\Engine;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\wisski_adapter_aat\Query\Query;
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
 * Wisski implementation of an external entity storage client.
 *
 * @Engine(
 *   id = "aat",
 *   name = @Translation("Getty AAT"),
 *   description = @Translation("Provides access to the Getty AAT")
 * )
 */
class AatEngine extends NonWritableEngineBase implements PathbuilderEngineInterface {
  
  // http://vocab.getty.edu/doc/
  protected $uriPattern  = "!^http[s]*://vocab.getty.edu/aat/(.+)$!u";
  protected $fetchTemplate = "http://vocab.getty.edu/aat/{id}.ttl";
  protected $debug = False;
  
  /**
   * Workaround for super-annoying easyrdf buggy behavior:
   * it will only work on prefixed properties
   */
  protected $rdfNamespaces = array(
    'aat' => 'http://vocab.getty.edu/aat/',
    'gvp' => 'http://vocab.getty.edu/ontology#',
    'gndo' => 'https://d-nb.info/standards/elementset/gnd#',
    'geo' => 'http://www.opengis.net/ont/geosparql#',
    'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
    'sf' => 'http://www.opengis.net/ont/sf#',    
    'skos' => 'http://www.w3.org/2004/02/skos/core#',
  );
  
  protected $possibleSteps = array(
      'Concept' => array(
        'skos:prefLabel' => NULL,
        'gvp:parentString' => NULL,
        'rdfs:label' => NULL,
      ),
  );

  /**
   * {@inheritdoc} 
   */
  public function hasEntity($entity_id) {
    $uris = AdapterHelper::getUrisForDrupalId($entity_id, $this->adapterId(), FALSE);

    if (empty($uris)){ 
      return FALSE;
    }
    else {
      return TRUE;
    }
  }


  public function fetchData($uri = NULL, $id = NULL) {
#    dpm("yay?");
    if ($this->debug) {
      $this->messenger()->addMessage($this->t("fetchData; uri: [$uri], id: [$id]"));
      $backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 4 );
      $this->messenger()->addMessage($this->t("Aufruf1 aus: " . serialize($backtrace[1]['function']) . " / " . microtime()));
      $this->messenger()->addMessage($this->t("Aufruf2 aus: " . serialize($backtrace[2]['function']) . " / " . microtime()));
      $this->messenger()->addMessage($this->t("Aufruf3 aus: " . serialize($backtrace[3]['function']) . " / " . microtime()));
      $this->messenger()->addMessage($this->t("backtrace: " . serialize($backtrace) . " / " . microtime()));
    }
    
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
    if ($this->debug) {
      $this->messenger()->addMessage($this->t("nach if, id: [$id]"));
    }
    \Drupal::logger('AatEngine')->notice('fetchData: %uri', [
        '%uri' => $uri,
     ]);
   
    // 
    $cache = \Drupal::cache('wisski_adapter_aat');
    $data = $cache->get($id);

#    dpm($data, "from cache?");
    // if debugging is turned on don't use caching so there's no early return here!
    if (!($this->debug) and $data) {
      return $data->data;
    }

    $replaces = array(
      '{id}' => $id,
    );
    $fetchUrl = strtr($this->fetchTemplate, $replaces);
    if ($this->debug) {
      $this->messenger()->addMessage($this->t("fetchUrl: '" . $fetchUrl . "' / " . microtime()));
    }

    $opts = [
      "http" => [
        "method" => "GET",
        "header" => "Accept: application/turtle\r\n"
      ]
    ];

    $context = stream_context_create($opts);
    
    // fetch data from remote site into array structure
    $data = file_get_contents($fetchUrl, false, $context);

    if ($data === FALSE || empty($data)) {
      return FALSE;
    }

    // get relevant information from array (preferred label (english), generic terms)
    // http://vocab.getty.edu/aat/300011154 --> http://www.w3.org/2008/05/skos-xl#prefLabel
    // --> http://vocab.getty.edu/aat/term/1000011154-en
    //     http://vocab.getty.edu/aat/term/1000011154-en -->  http://vocab.getty.edu/ontology#term --> "aventurine"
    //     http://vocab.getty.edu/aat/term/1000011154-en --> http://vocab.getty.edu/ontology#qualifier --> "quartz"
    // ( http://vocab.getty.edu/aat/300388277: "English (language)" )

    // relevant 'predicates': 'http://vocab.getty.edu/ontology#parentString', 
    // 'http://vocab.getty.edu/ontology#parentStringAbbrev', 'http://www.w3.org/2004/02/skos/core#prefLabel', 
    // 'http://www.w3.org/2004/02/skos/core#altLabel', 


    $graph = new EasyRdf_Graph($fetchUrl, $data, 'turtle');
    if ($this->debug) {
#      dpm($graph, "graph?");
      $this->messenger()->addMessage($this->t("graph->countTriples: " . $graph->countTriples() . " / " . microtime()));
    }
    if ($graph->countTriples() == 0) {
      return FALSE;
    }

    foreach ($this->rdfNamespaces as $prefix => $ns) {
      EasyRdf_Namespace::set($prefix, $ns);
    }

    $data = array();


// Property Chains don't work with unnamed bnodes :/
    foreach ($this->possibleSteps as $concept => $rdfPropertyChains) {
      if ($this->debug) {
#        dpm($concept, "con?");
#        dpm($rdfPropertyChains, "rdf?");
        $this->messenger()->addMessage($this->t("concept: " . serialize($concept) . " / " . microtime()));
        $this->messenger()->addMessage($this->t("rdfPropertyChains: " . serialize($rdfPropertyChains) . " / " . microtime()));
      }
      foreach ($rdfPropertyChains as $propChain => $tmp) {
        $pChain = explode(' ', $propChain);
        if ($this->debug) {
          $this->messenger()->addMessage($this->t("propChain: " . serialize($propChain) . " / " . microtime()));
          $this->messenger()->addMessage($this->t("pChain: " . serialize($pChain) . " / " . microtime()));
        }
        $dtProp = NULL;
        if ($tmp === NULL) {
          // last property is a datatype property
          $dtProp = array_pop($pChain);
        }
       
        if ($this->debug) {
#          dpm($dtProp, "yay!");
          $this->messenger()->addMessage($this->t("dtProp: " . serialize($dtProp) . " / " . microtime()));
        }
        $resources = array($uri => $uri);
        if ($this->debug) {
          $this->messenger()->addMessage($this->t("resources: " . serialize($resources) . " / " . microtime()));
        }

        foreach ($pChain as $prop) {
          $newResources = array();
          foreach ($resources as $resource) {
            if ($this->debug) {
              $this->messenger()->addMessage($this->t("resource: '" . $resource . "', prop: '" . $prop . " / " . microtime()));
#              dpm($graph->properties($resource), "props");
#              dpm($graph->allResources($resource, $prop), "Getting Resource $resource for prop $prop");
            }
            foreach ($graph->allResources($resource, $prop) as $r) {
#              dpm($r, "er");
#              if(!empty($r->getUri())
              $newResources[$r->getUri()] = $r;
            }
          }
          if ($this->debug) {
#            dpm($resources, "old");
#            dpm($newResources, "new");
            $this->messenger()->addMessage($this->t("resources: " . serialize($resources) . " / " . microtime()));
            $this->messenger()->addMessage($this->t("newResources: " . serialize($newResources) . " / " . microtime()));
          }
          $resources = $newResources;
        }
        if ($dtProp) {
          if ($this->debug) {
#            dpm($resources, "my res?");
            $this->messenger()->addMessage($this->t("if; resources: " . serialize($resources) . " / " . microtime()));
          }
          foreach ($resources as $resource) {
            if ($this->debug) {
#              dpm($graph, "dtprop!");
              $this->messenger()->addMessage($this->t("if/foreach; resource: " . serialize($resource) . " / " . microtime()));
            }
            
            foreach ($graph->all($resource, $dtProp) as $thing) {
              if ($this->debug) {
#                dpm($thing->getDatatype(), "thing");
#                dpm($dtProp, "dtprop!");
                $this->messenger()->addMessage($this->t("thing: " . serialize($thing) . " / " . microtime()));
                $this->messenger()->addMessage($this->t("dtProp: " . serialize($dtProp) . " / " . microtime()));
              }
              if ($thing instanceof EasyRdf_Literal) {
                if ($this->debug) {
                  $this->messenger()->addMessage($this->t("Erfolg! value: " . serialize($thing->getValue()) . " / " . microtime()));
                }
                $data[$concept][$propChain][] = $thing->getValue();
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
        "http://vocab.getty.edu/aat/300015637"
      );
    }
    
    $out = array();

    foreach ($entity_ids as $eid) {

      foreach($field_ids as $fkey => $fieldid) {  
        
        $got = $this->loadPropertyValuesForField($fieldid, array(), $entity_ids, $bundleid_in, $language);

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
  public function loadPropertyValuesForField($field_id, array $property_ids, array $entity_ids = NULL, $bundleid_in = NULL,$language = LanguageInterface::LANGCODE_DEFAULT) {
#dpm(func_get_args(), 'lpvff');

    $main_property = \Drupal\field\Entity\FieldStorageConfig::loadByName('wisski_individual', $field_id);
    if(!empty($main_property)) {
      $main_property = $main_property->getMainPropertyName();
    }
    
#    if (in_array($main_property,$property_ids)) {
#      return $this->loadFieldValues($entity_ids,array($field_id),$language);
#    }
#    return array();

    if(!empty($field_id) && empty($bundleid_in)) {
      $this->messenger()->addMessage($this->t(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)), 'error');
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
