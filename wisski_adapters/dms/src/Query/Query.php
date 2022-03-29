<?php

namespace Drupal\wisski_adapter_dms\Query;

use Drupal\wisski_salz\Query\WisskiQueryBase;
use Drupal\wisski_salz\Query\ConditionAggregate;
use Drupal\wisski_adapter_dms\Plugin\wisski_salz\Engine\DmsEngine;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\wisski_salz\AdapterHelper;
use Drupal\wisski_salz\Query\Condition;

class Query extends WisskiQueryBase {


  public function execute() {
#    dpm("exe!");

#    return;

#    dpm(serialize($this->count), "count?1");

#    dpm($this->condition, "cond?");

    $result = array();
    $limit = 0;
    $offset = 0;

   // get the adapter
    $engine = $this->getEngine();
#    dpm($engine, "engine");
    if (empty($engine))
      return array();
    
    // get the adapter id
    $adapterid = $engine->adapterId();
#    dpm($adapterid, "adapterid");

    // if we have not adapter, we may go home, too
    if (empty($adapterid))
      return array();
#    return;
    // get all pbs
    $pbs = array();
    $ents = array();
    // collect all pbs that this engine is responsible for
    foreach (WisskiPathbuilderEntity::loadMultiple() as $pb) {
      if (!empty($pb->getAdapterId()) && $pb->getAdapterId() == $adapterid) {
        $pbs[$pb->id()] = $pb;
      }
    }
    
#    dpm($this->pager, "pager?");
    
    if(!empty($this->pager)) {
      list($limit, $offset) = $this->getPager();
    } else if ( !empty($this->range)) {
      #dpm(array($this->pager, $this->range),'limits '.__CLASS__);
      $limit = $this->range['length'];
      $offset = $this->range['start'];
    } //else dpm($this,'no limits');
#
#    dpm($limit, "limit?");
#    dpm($offset, "offset?");

//wisski_tick('prepared '.$pb->id());
#    return;
    // care about everything...
    if (TRUE ) { //$this->isFieldQuery()) {
#      dpm("fq!");

      // bad hack, but this is how it was...
      // TODO: handle correctly multiple pbs
      $pb = current($pbs);
      //wisski_tick("field query");

      $eidquery = NULL;
      $bundlequery = NULL;

#      dpm(serialize($this->condition->conditions()), "condi?");

      $num_conds = 0;
      
      $eidcondition = array();

      foreach ($this->condition->conditions() as $condition) {
        $field = $condition['field'];
        $value = $condition['value'];

        $num_conds++;

        if(!is_string($condition['field'])) {
          // might be one deeper
          $condition = current($condition['field']->conditions());
#          dpm($condition, "cond?");
          $field = $condition['field'];
          $value = $condition['value'];
        }

#        dpm($field, "asked for field");
#        dpm($value, "asked for value");

        if($field == "bundle")
          $bundlequery = $value;
        if($field == "eid")
          $eidquery = $value;
      }

#        dpm($eidquery,"eidquery");
#        dpm($bundlequery, "bundlequery");
#        return;

      $giveback = array();

      // eids are a special case
      if ($eidquery !== NULL) {

#        if(is_array($eidquery))
#          $eidquery = current($eidquery);

        if(is_array($bundlequery)) 
          $bundlequery = current($bundlequery);

        // load the id, this hopefully helps.
#        $thing['eid'] = $eidquery;

#          dpm($eidquery, "thing");


        if($bundlequery === NULL) {
          if(!is_array($eidquery)) {
            if(is_numeric($eidquery))
              $giveback = array($eidquery => $eidquery);
          } else {
            $giveback = array_values($eidquery); // array($thing['eid']);
          }
          
          $eidcondition['field'] = $pb->id() . ".inventarnummer";
          $eidcondition['operator'] = "=";
          
          $uris = AdapterHelper::getUrisForDrupalId($condition['value'], $adapterid);
          $invnr = substr($uris, strlen("http://objektkatalog.gnm.de/objekt/"));

          $invnr = urldecode($invnr);
          
          $eidcondition['value'] = $invnr;
          
#          dpm($invnr);
          #$invnr = urlencode($invnr);

#          $uri = 

          #$uri = "http://objektkatalog.gnm.de/objekt/" . $invnr;
#          $invnr = substr($strlen("http://objektkatalog.gnm.de/objekt/"), 
#          $condition['value'] = 
        } else {

          foreach($eidquery as $key => $eid) {

            // I dont know why this may be the case, but it sometimes is...
            if(is_array($eid)) {
              $eid = current($eid);
            } else {

            }    
            
#            dpm("bundle?");
            // load the bundles for this id
            $bundleids = $engine->getBundleIdsForEntityId($eid);        

#            dpm($bundleids, "bundle ids for $eid");

            if(in_array($bundlequery, $bundleids))
              $giveback[$eid] = $eid;//array($thing['eid']);
#              drupal_set_message(serialize($giveback) . "I give back for ask " . serialize($eidquery));
            //wisski_tick('Field query out 1');
          }
#          drupal_set_message(serialize($giveback) . "I give back for ask " . serialize($eidquery));
#          dpm("I give back: " . $giveback);
#          return $giveback;

        }
        
#        dpm($giveback);
        
#        return $giveback;
      }
      
      if($num_conds == 1 && !empty($giveback)) {
        return $giveback;
      }
      
#      dpm("half");    
      //wisski_tick("field query half");
      
#      dpm($eidcondition, "eid?");

      foreach($this->condition->conditions() as $condition) {
        
        $conditions = $this->condition->conditions();
        
        if(!is_string($condition['field'])) {
          // might be one deeper
          $conditions = $condition['field']->conditions();
          $condition = current($condition['field']->conditions());
#          dpm("cond!", "cond?");
          $field = $condition['field'];
          $value = $condition['value'];
        }
        
        $field = $condition['field'];
        $value = $condition['value'];
        
#        dpm($conditions);
        
        // if there is an array in value but it has only one value (comes from delegator!)
        // then we take the value!
#        if(is_array($value) && count($value) == 1)
#          $value = current($value);
#        drupal_set_message("you are evil!" . microtime() . serialize($this));
#        return;

#        drupal_set_message("my cond is: " . serialize($condition));
#        dpm($field, "field");
#        dpm($value, "I am asking for");

        // just return something if it is a bundle-condition
        if($field == 'bundle' ) {
#          dpm("bundle");
#          dpm($conditions);
#  	        drupal_set_message("I go and look for : " . serialize($value) . " and " . serialize($limit) . " and " . serialize($offset) . " and " . $this->count);
#          dpm(serialize($this->count), "count?");
          if($this->count) {
#   	         drupal_set_message("I give back to you: " . serialize($pbadapter->getEngine()->loadIndividualsForBundle($value, $pb, NULL, NULL, TRUE)));
            //wisski_tick('Field query out 2');
            return $engine->loadIndividualsForBundle($value, $pb, NULL, NULL, TRUE, $conditions);
          }

#            dpm($pbadapter->getEngine()->loadIndividualsForBundle($value, $pb, $limit, $offset, FALSE, $this->condition->conditions()), 'out!');
#            dpm(array_keys($pbadapter->getEngine()->loadIndividualsForBundle($value, $pb, $limit, $offset, FALSE, $this->condition->conditions())), "muhaha!");
#            return;           
          //wisski_tick('Field query out 3');
#          dpm($value, "value");
#          dpm($pb, "pb");
#          dpm($limit, "limit");
#          dpm($offset, "off");
#          return;
#          dpm($engine, "engine");

#dpm(microtime(), "start query");
          $ret = $engine->loadIndividualsForBundle($value, $pb, $limit, $offset, FALSE, $conditions);
#dpm(microtime(), "end query");
#          dpm($ret, "ret?");

          return array_keys($ret);
        }

        if($field == 'label' ) {
#          dpm("label");
          // This here has to be replaced  by the current bundle id for the object...
          // this should be dynamic!
          if($this->count) {
            return $engine->loadIndividualsForBundle(array('b34869d99be8c4f788285d14caa31c05'), $pb, NULL, NULL, TRUE, $this->condition->conditions());
          }

          return array_keys($engine->loadIndividualsForBundle(array('b34869d99be8c4f788285d14caa31c05'), $pb, $limit, $offset, FALSE, $this->condition->conditions()));
        }


#        dpm($field, "fi");
        if($field instanceof Condition) {
#          dpm($field, "field");
          #dpm($field->conditions(), "cond");

          foreach($field->conditions() as $subcondition) {
            #dpm($subcondition, "sub");
            #dpm($subcondition['field'], "val");
#            dpm("yay?");
            $pb_and_path = explode(".", $subcondition['field']);

            $pathid = $pb_and_path[1];

            $pbp = $pb->getPbPath($pathid);

            $value = $subcondition['value'];

            $bundle = $pbp['bundle'];
            if($this->count) {
              $ret = $engine->loadIndividualsForBundle($bundle, $pb, NULL, NULL, TRUE, $field->conditions());
#              dpm($ret);
              return $ret;
            } else {
              $ret = $engine->loadIndividualsForBundle($bundle, $pb, NULL, NULL, FALSE, $field->conditions());
#              dpm($ret, "got");
              return array_keys($ret);
            }

          }
        } else {
#          dpm("default!");
          if(!empty($eidcondition))
            $conditions[] = $eidcondition;
#          dpm($conditions);
          // default handling
          if($this->count) {
            return $engine->loadIndividualsForBundle(array('b34869d99be8c4f788285d14caa31c05'), $pb, NULL, NULL, TRUE, $conditions);
          }

          return array_keys($engine->loadIndividualsForBundle(array('b34869d99be8c4f788285d14caa31c05'), $pb, $limit, $offset, FALSE, $conditions));
        }
      }

    //wisski_tick("afterprocessing");
    
    } elseif ($this->isPathQuery()) {
    }

    return array_keys($ents);
  }
  
  /**
   * {@inheritdoc}
   */
  public function existsAggregate($field, $function, $langcode = NULL) {
    return $this->conditionAggregate->exists($field, $function, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function notExistsAggregate($field, $function, $langcode = NULL) {
    return $this->conditionAggregate->notExists($field, $function, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function conditionAggregateGroupFactory($conjunction = 'AND') {
    return new ConditionAggregate($conjunction, $this);
  }

}

