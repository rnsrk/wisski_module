<?php
/**
 * @file
 * Contains \Drupal\wisski_core\Plugin\views\filter\StringFilter.
 */

namespace Drupal\wisski_core\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\StringFilter as ViewsString;

/**
 * Filter handler for string.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("wisski_field_string")
 */
class FieldString extends ViewsString {

  function operators() {
#    dpm("op!!");
    $operators = parent::operators();
    $operators_new = array(
    /*
      '=' => array(
        'title' => t('Is equal to'),
        'short' => t('='),
        'method' => 'opEqual',
        'values' => 1,
      ),
      '!=' => array(
        'title' => t('Is not equal to'),
        'short' => t('!='),
        'method' => 'opEqual',
        'values' => 1,
      ),
      'CONTAINS' => array(
        'title' => t('Contains'),
        'short' => t('contains'),
        'method' => 'opContains',
        'values' => 1,
      ),
      'STARTS_WITH' => array(
        'title' => t('Starts with'),
        'short' => t('begins'),
        'method' => 'opStartsWith',
        'values' => 1,
      ),
      'ENDS_WITH' => array(
        'title' => t('Ends with'),
        'short' => t('ends'),
        'method' => 'opEndsWith',
        'values' => 1,
      ),
    */
      'EMPTY' => array(
        'title' => t('Is empty'),
        'short' => t('empty'),
        'method' => 'opSimple',
        'values' => 0,
      ),
      'NOT_EMPTY' => array(
        'title' => t('Is not empty'),
        'short' => t('not_empty'),
        'method' => 'opSimple',
        'values' => 0,
      ),
      'IN' => array(
        'title' => t('One of'),
        'short' => t('in'),
        'method' => 'opMulti',
        'values' => 1,
      ),
    );

#    dpm($operators, "old");
    
    $operators = array_merge($operators, $operators_new);

#    dpm(serialize($operators), "op");

    return $operators;
  }

  /**
   * {@inheritdoc}
   */
  function query() {
    $field = isset($this->configuration['wisski_field']) ? $this->configuration['wisski_field'] : $this->realField;
    $info = $this->operators();
    if (!empty($info[$this->operator]['method'])) {
      $this->{$info[$this->operator]['method']}($field);
    }
  }


  /**
   * {@inheritdoc}
   */
  function opSimple($field) {
    $this->query->addWhere($this->options['group'], $field, $this->value, $this->operator);
  }

  function opMulti($field) {
    $value = explode(',', $this->value);
    $this->query->addWhere($this->options['group'], $field, $value, $this->operator);
  }
  
  protected function opStartsWith($field) {
    $operator = $this->getConditionOperator('STARTS_WITH');
//    $this->query->addWhere($this->options['group'], $field, $this->connection->escapeLike($this->value) . '', $operator);
    $this->query->addWhere($this->options['group'], $field, $this->value . '', $operator);
  }

  protected function opNotStartsWith($field) {
    $operator = $this->getConditionOperator('NOT_STARTS_WITH');
    $this->query->addWhere($this->options['group'], $field, '' . $this->value . '', $operator);
  }

  protected function opEndsWith($field) {
    $operator = $this->getConditionOperator('ENDS_WITH');
    $this->query->addWhere($this->options['group'], $field, '' . $this->value, $operator);
  }

  protected function opNotEndsWith($field) {
    $operator = $this->getConditionOperator('NOT_ENDS_WITH');
    $this->query->addWhere($this->options['group'], $field, '' . $this->value . '', $operator);
  }

  protected function opNotLike($field) {
    $operator = $this->getConditionOperator('NOT');
    $this->query->addWhere($this->options['group'], $field, '' . $this->value . '', $operator);
  }

  protected function opShorterThan($field) {
    $operator = $this->getConditionOperator('SHORTERTHAN');
    //$placeholder = $this->placeholder();
    // Type cast the argument to an integer because the SQLite database driver
    // has to do some specific alterations to the query base on that data type.
    //$this->query->addWhereExpression($this->options['group'], "LENGTH($field) > $placeholder", [$placeholder => (int) $this->value]);
    $this->query->addWhere($this->options['group'], $field, '' . $this->value . '', $operator);
  }

  protected function opLongerThan($field) {
    $operator = $this->getConditionOperator('LONGERTHAN');
    //$placeholder = $this->placeholder();
    // Type cast the argument to an integer because the SQLite database driver
    // has to do some specific alterations to the query base on that data type.
    //$this->query->addWhereExpression($this->options['group'], "LENGTH($field) > $placeholder", [$placeholder => (int) $this->value]);
    $this->query->addWhere($this->options['group'], $field, '' . $this->value . '', $operator);
  }

  /**
   * Filters by a regular expression.
   *
   * @param string $field
   *   The expression pointing to the queries field, for example "foo.bar".
   */
  protected function opRegex($field) {
    $this->query->addWhere($this->options['group'], $field, $this->value, 'REGEXP');
  }

  
  function placeholder() {
    $field = isset($this->configuration['wisski_field']) ? $this->configuration['wisski_field'] : $this->realField;
    $this->query->addWhere($this->options['group'], $field, $this->value, $this->operator);

  }

}
