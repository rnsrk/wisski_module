<?php

namespace Drupal\wisski_date_field_extractor\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Plugin implementation of the 'WissKI date field' widget.
 *
 * @FieldWidget(
 *   id = "wisski_date_field_widget",
 *   label = @Translation("WissKI date field widget"),
 *   field_types = {
 *     "wisski_date_field",
 *     "wisski_verbal_date_field"
 *   }
 * )
 */
class WisskiDateFieldDefaultWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $value = isset($items[$delta]->value) ? $items[$delta]->value : '';
    $element += [
      '#type' => 'textfield',
      '#default_value' => $value,
      '#size' => 60,
      '#maxlength' => 60
    ];
    return ['value' => $element];
  }

}

