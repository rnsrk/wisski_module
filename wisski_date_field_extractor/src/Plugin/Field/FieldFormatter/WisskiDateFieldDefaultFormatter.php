<?php

namespace Drupal\wisski_date_field_extractor\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'WissKI date field' formatter.
 *
 * @FieldFormatter(
 *   id = "wisski_date_field_formatter",
 *   label = @Translation("WissKI date field formatter"),
 *   field_types = {
 *     "wisski_date_field",
 *     "wisski_verbal_date_field"
 *   }
 * )
 */
class WisskiDateFieldDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [];

    foreach ($items as $delta => $item) {
      // Render each element as markup.
      $element[$delta] = ['#markup' => $item->value];
    }

    return $element;
  }

}

