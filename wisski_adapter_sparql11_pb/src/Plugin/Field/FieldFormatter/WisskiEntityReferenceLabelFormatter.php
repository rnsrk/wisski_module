<?php

namespace Drupal\wisski_adapter_sparql11_pb\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Exception\UndefinedLinkTemplateException;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\Url;

/**
 * Plugin implementation of the 'entity reference label' formatter.
 *
 * @FieldFormatter(
 *   id = "wisski_entity_reference_label",
 *   label = @Translation("Label (WissKI)"),
 *   description = @Translation("Display the label of the referenced entities for WissKI entities."),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class WisskiEntityReferenceLabelFormatter extends EntityReferenceLabelFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'link' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements['link'] = [
      '#title' => t('Link label to the referenced entity'),
      '#type' => 'checkbox',
      '#default_value' => $this->getSetting('link'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->getSetting('link') ? t('Link to the referenced entity') : t('No link');
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
#    dpm("yay, wisski enhanced!");
#    dpm(serialize($items), "items?");

    $elements = [];
    $output_as_link = $this->getSetting('link');

    foreach($items as $delta => $item) {
#      dpm(serialize($item->target_id), "target?");
#      dpm(wisski_core_generate_title($item->target_id), "title?");

      $label = wisski_core_generate_title($item->target_id);

      // for now we take the interface language
      $langcode = \Drupal::service('language_manager')->getCurrentLanguage()->getId();
      
      if(isset($label[$langcode][0]["value"]))
        $label = $label[$langcode][0]["value"];
      else {
        $cur_label = current($label);
        if(isset($cur_label[0]["value"]))
          $label = $cur_label[0]["value"];
      }
        
      
      // If the link is to be displayed and the entity has a uri, display a
      // link.
      if ($output_as_link ) {
        try {
          $uri = Url::fromRoute('entity.wisski_individual.canonical', ['wisski_individual' => $item->target_id]);
//          $uri = //$entity->toUrl();
        }
        catch (UndefinedLinkTemplateException $e) {
          // This exception is thrown by \Drupal\Core\Entity\Entity::urlInfo()
          // and it means that the entity type doesn't have a link template nor
          // a valid "uri_callback", so don't bother trying to output a link for
          // the rest of the referenced entities.
          $output_as_link = FALSE;
        }
      }

      if ($output_as_link && isset($uri)) {
        $elements[$delta] = [
          '#type' => 'link',
          '#title' => $label,
          '#url' => $uri,
          '#options' => $uri->getOptions(),
        ];

        if (!empty($items[$delta]->_attributes)) {
          $elements[$delta]['#options'] += ['attributes' => []];
          $elements[$delta]['#options']['attributes'] += $items[$delta]->_attributes;
          // Unset field item attributes since they have been included in the
          // formatter output and shouldn't be rendered in the field template.
          unset($items[$delta]->_attributes);
        }
      }
      else {
        $elements[$delta] = ['#plain_text' => $label];
      }
 #     $elements[$delta]['#cache']['tags'] = $entity->getCacheTags();
    }


    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity) {
    return $entity->access('view label', NULL, TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * Loads the entities referenced in that field across all the entities being
   * viewed.
   */
  public function prepareView(array $entities_items) {
 #   dpm("I did not load anything!");
  }

}
