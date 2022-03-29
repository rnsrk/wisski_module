<?php

namespace Drupal\wisski_date_field_extractor\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'WissKI date field' field type.
 *
 * @FieldType(
 *   id = "wisski_date_field",
 *   label = @Translation("WissKI date field"),
 *   description = @Translation("Field for the input of calculable dates."),
 *   default_widget = "wisski_date_field_widget",
 *   default_formatter = "wisski_date_field_formatter"
 * )
 */
class WisskiDateFieldItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'text',
          'size' => 'small',
          'not null' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      // settings: field ids
      'field_id_qualified_begin' => '',
      'field_id_earliest_begin' => '',
      'field_id_latest_begin' => '',
      'field_id_qualified_end' => '',
      'field_id_earliest_end' => '',
      'field_id_latest_end' => '',
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
  
    $element = [];

    $element['field_id_qualified_begin'] = [
      '#title' => $this->t('Field ID for qualified begin field'),
      '#type' => 'textfield',
      '#description' => $this->t('ID for the qualified begin date field. If left blank, the begin date will not be saved.'),
      '#default_value' => $this->getSetting('field_id_qualified_begin'),
    ];
    $element['field_id_earliest_begin'] = [
      '#title' => $this->t('Field ID for earliest begin field'),
      '#type' => 'textfield',
      '#description' => $this->t('ID for the earliest begin date field. If left blank, the begin date will not be saved.'),
      '#default_value' => $this->getSetting('field_id_earliest_begin'),
    ];
    $element['field_id_latest_begin'] = [
      '#title' => $this->t('Field ID for latest begin field'),
      '#type' => 'textfield',
      '#description' => $this->t('ID for the latest begin date field. If left blank, the begin date will not be saved.'),
      '#default_value' => $this->getSetting('field_id_latest_begin'),
    ];
    $element['field_id_qualified_end'] = [
      '#title' => $this->t('Field ID for qualified end field'),
      '#type' => 'textfield',
      '#description' => $this->t('ID for the qualified end date field. If left blank, the end date will not be saved.'),
      '#default_value' => $this->getSetting('field_id_qualified_end'),
    ];
    $element['field_id_earliest_end'] = [
      '#title' => $this->t('Field ID for earliest end date field'),
      '#type' => 'textfield',
      '#description' => $this->t('ID for the earliest end date field. If left blank, the end date will not be saved.'),
      '#default_value' => $this->getSetting('field_id_earliest_end'),
    ];
    $element['field_id_latest_end'] = [
      '#title' => $this->t('Field ID for latest end date field'),
      '#type' => 'textfield',
      '#description' => $this->t('ID for the latest end date field. If left blank, the end date will not be saved.'),
      '#default_value' => $this->getSetting('field_id_latest_end'),
    ];

    return $element;
  }


  /**
    * {@inheritdoc}
  */
  public function isEmpty() {
    $value = $this->get('value')->getValue();
    return ( $value === NULL || $value === '' );
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = [];
    $properties['value'] = DataDefinition::create('string');

    return $properties;
  }
}

