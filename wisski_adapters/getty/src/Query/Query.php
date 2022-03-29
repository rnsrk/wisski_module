<?php

namespace Drupal\wisski_adapter_aat\Query;

use Drupal\wisski_salz\Query\WisskiQueryBase;
use Drupal\wisski_salz\Query\ConditionAggregate;
use Drupal\wisski_adapter_sparql11_pb\Plugin\wisski_salz\Engine\Sparql11EngineWithPB;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\wisski_salz\AdapterHelper;

class Query extends WisskiQueryBase {


  public function execute() {
    $result = array();
    
    if ($this->isFieldQuery()) {
      

    } elseif ($this->isPathQuery()) {

    }
    

    if ($this->count) {
      $result = count($result);
    }

    return $result;
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

