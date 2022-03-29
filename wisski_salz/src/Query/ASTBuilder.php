<?php

/**
 * contains \Drupal\wisski_salz\ASTBuilder
 */
namespace Drupal\wisski_salz\Query;

use Drupal\Core\Config\Entity\Query\Condition as ConditionParent;

/**
 * Class ASTBuilder implements various functions based only on the Query AST.
 */
class ASTBuilder {

  const TYPE_FILTER = "filter";
  const TYPE_LOGICAL_AGGREGATE = "logical_aggregate";

   /*
    An AST of a Query is represented as follows:   
   
    AST = LOGICAL_AGGREGATE | FILTER | "null"

    LOGICAL_AGGREGATE = {
      "type": "logical_aggregate",
      "operator" = "and" | "or"
      "children" = NODE *
    }

    FILTER = {
      "type": "filter",
      "field": ...
      "operator": ...
      "value": ...
      "langcode": ...
    }

    A NULL AST indiciates that an empty condition was provided. 

    */

  /** makeAST returns an AST from a condition object and optionally simplifies it */
  public static function makeConditionAST(ConditionParent $condition, bool $simplify = TRUE) {
    $ast = self::makeAggregateAST($condition); // a condition is always an aggregate ast
    if ($simplify) {
      $ast = self::simplifyAST($ast);
    }
    return $ast;
  }

  /** makeAggregateAST returns an AST of type logical_aggregate from this condition */
  private static function makeAggregateAST(ConditionParent $condition) {
    // received an invalid condition!
    if (!($condition instanceOf ConditionParent)) {
      return NULL;
    }
  
    // get the child conditions of this condition!
    $children = [];
    foreach ($condition->conditions() as $cond) {
      // if field is a string, it's a leaf!
      $field = $cond["field"];
      if (is_string($field)) {
        array_push($children, self::makeFilterAST($cond));
        continue;
      }
    
      // it's an aggregate
      array_push($children, self::makeAggregateAST($field));
    }
  
    // build the child array
    return array(
      "type" => self::TYPE_LOGICAL_AGGREGATE,
      "operator" => strtoupper($condition->getConjunction()), // normalize conjunction
      "children" => $children,
    );
  }

  /** makeFilterAST returns an AST of type filter from the condition */
  private static function makeFilterAST(array $condition) {
    return array(
      "type" => self::TYPE_FILTER,
      "field" => $condition["field"],
      "operator" => $condition["operator"],
      "value" => $condition["value"],
      "langcode" => $condition["langcode"],
    );
  }

  /**
   * simplifyAST performs basic simplification and normalization of ASTs.
   * This does not change the semantics of any conditions but might change the internal order and structure.
   *
   * This function first replaces unknown FILTERs by NULL.
   * After that it only simplifies LOGICAL_AGGREGATE conditions and does the following:
   * - Replace empty aggregates by NULL. This is both done for operators "AND" and "OR".
   * - Replace aggregates that only have one child by that child.
   * - Remove empty children.
   * - Merge children that contain the same aggregate into one.
   * - Remove duplicate children.
   * - Order children consistently (the actual order is an implementation detail and should not be relied upon).
   */
  public static function simplifyAST(?array $ast) {

    // TODO: This function is somewhat slow beecause we constantly re-create child arrays.
    // For now this is ok, but we might want to change that in the future. 

    // if an AST is null, we're done!
    if ($ast == NULL) {
      return NULL;
    }
    
    // in the filter case, just check that we are a known filter. 
    // when not a known filter, return NULL.
    if ($ast['type'] == self::TYPE_FILTER) {
      if (!self::isKnownField($ast['field'])) {
        Debuggable::debug("Encountered unknown field " . $ast['field'] . ", keeping it for forward compatibility. ");
      }
      return $ast;
    }

    // because we're not a filer, we must be a logical aggregate. 
  
    // Rebuild the children array.
    // This variable will eventually contain all the rebuilt children.
    $children = array();

    // we want to take special care of children that are aggregates, because we can
    // merge multiple aggregates that are of the same type.
    // We first exclude them from the child array and put them into this one.
    // It contains one array per 'operator'.
    $aggregate_children = array();

    foreach($ast['children'] as $child) {
      // simplify recursively!
      $child = self::simplifyAST($child);
     
      // child is NULL => skip
      if ($child == NULL) {
        continue;
      }

      // child is not an aggregate => re-use it.
      if($child['type'] !== self::TYPE_LOGICAL_AGGREGATE) {
        array_push($children, $child);
        continue;
      }

      // Child is an aggregate, so put it into the list of children for that aggregate.
      // To do that, we first have to make sure that the aggregate group exists.
      $operator = $child['operator'];
      if (!array_key_exists($operator, $aggregate_children)) {
        $aggregate_children[$operator] = array();
      }

      array_push($aggregate_children[$operator], $child);
    }

    // iterate over each of the aggregate children groups and merge them into one.
    foreach ($aggregate_children as $operator => $group) {
      // $group is guaranteed not recursively contain any groups.
      // $group is also non-empty (otherwise $operator would not exist).
      
      // if we have a single child, don't do anything expensive and just use it.
      if (count($group) == 1) {
        array_push($children, $group[0]);
        continue;
      }

      // throw together all the children in this group.
      $group_children = array();
      foreach ($group as $group_child) {
        $group_children = array_merge($group_children, $group_child['children']);
      }

      // order and dedup the children.
      // if we have only a single deduped child, return that and don't do any more merging.
      $group_children = self::dedupAndOrderASTs($group_children);
      if (count($group_children) == 1) {
        array_push($children, $group_children[0]);
        continue;
      }

      // put the children of this operator into the original children.
      $group_child = array(
        "type" => self::TYPE_LOGICAL_AGGREGATE,
        "operator" => $operator,
        "children" => $group_children,
      );
      array_push($children, $group_child);
    }

    // now that we have recreated the child array we're almost done.
    // sort them and check for special cases. 
    $ast['children'] = self::dedupAndOrderASTs($children);
    $count = count($ast['children']);

    // no children => drop this case and replace it by NULL
    if ($count == 0) {
      // DANGER DANGER DANGER
      // This part of the code considers any empty aggregate group as irrelevant.
      // Logically this is not the case for an empty 'OR'.
      //
      // We still do this because it makes the code simpler (don't need to inspect the operator)
      // and no user would probably provide an empty OR group. 
      if(Debuggable::debug_enabled()) {
        if ($ast['operator'] == 'OR') {
          Debuggable:debug("Dropping empty 'OR' " . self::TYPE_LOGICAL_AGGREGATE);
        }
      }


      return NULL;
    }

    // one child => return that child
    if ($count == 1) {
      return $ast['children'][0];
    }

    // return the ast itself
    return $ast;
  }

  /** checks if $filter is a known filter */
  private static function isKnownField(string $field) {
    if (
      $field == 'eid' || 
      $field == 'bundle' || 
      $field == 'bundle_label' || 
    //  $field == 'bundles' || // TODO: not sure where this can happen
      $field == 'title' || 
      $field == 'preferred_uri' || 
      $field == 'status' || 
      $field == 'preview_image'
    ) {
      return true;
    }

    // Field representing a path builder field!
    // "${path}.${field}"
    return str_contains($field, '.');
  }

  /** deduplicats and consistently orders a list of asts */
  private static function dedupAndOrderASTs(array $asts) {
    
    // create an associative array of stringifcation => value
    // this removes duplicates.
    $assoc = array();
    foreach ($asts as $ast) {
      $assoc[self::stringifyAST($ast)] = $ast;
    }
    
    // sort the associative array by the keys
    ksort($assoc);

    // return only the values
    return array_values($assoc);
  }

  /** 
   * Turns an AST into a string.
   * 
   * Callers may rely on the fact that two ASTs are (structurally) identical if their string representations are identical.
   */
  public static function stringifyAST(?array $ast) {
    return json_encode($ast);
  }

}