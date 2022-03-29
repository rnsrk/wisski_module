<?php

/**
 * contains \Drupal\wisski_salz\WisskiQueryBase
 */
namespace Drupal\wisski_salz\Query;
use Drupal\Core\Entity\Query\QueryBase;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\Query\QueryAggregateInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\wisski_salz\EngineInterface;


abstract class WisskiQueryBase extends QueryBase implements QueryInterface, QueryAggregateInterface {

  protected $parent_engine;
  
  protected $query_column_type;
  
  const FIELD_QUERY = 1;
  const PATH_QUERY = 2;

  public function __construct(EntityTypeInterface $entity_type,$conjunction,array $namespaces,EngineInterface $parent_engine=NULL) {
#    dpm($parent_engine, "par");
    $namespaces = array_merge($namespaces,QueryBase::getNamespaces($this));
    parent::__construct($entity_type,$conjunction,$namespaces);
    $this->parent_engine = $parent_engine;
    $this->query_column_type = self::FIELD_QUERY;
  }
  
  public function getEngine() {
    return $this->parent_engine;
  }

  /**
   * Builds a condition AST that is nicely iteratable.
   */
  public function getConditionAST(bool $simplify = TRUE) {
    // the top-most condition is always an aggregate, even if we have only one condition!
    return ASTBuilder::makeConditionAST($this->condition, $simplify);
  }

  public function makeQueryPlan() {
    $annotator = new ASTAnnotator(NULL);
    $planner = new QueryPlanner(NULL);
    
    $ast = $this->getConditionAST(TRUE);
    $aast = $annotator->annotate($ast);
    return $planner->plan($aast);
  }
  
  public function normalQuery() {
    $this->count = FALSE;
    return $this;
  }
  
  public function countQuery() {
    $this->count = TRUE;
    return $this;
  }
  
  public function setPathQuery() {
    $this->query_column_type = self::PATH_QUERY;
  }
  
  public function setFieldQuery() {
    $this->query_column_type = self::FIELD_QUERY;
  }
  
  public function isFieldQuery() {
    return $this->query_column_type === self::FIELD_QUERY;
  }
  
  public function isPathQuery() {
    return $this->query_column_type === self::PATH_QUERY;
  }

}