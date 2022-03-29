<?php

/**
 * @file
 * Contains \Drupal\wisski_adapter_sparql11_pb\Plugin\Field\FieldFormatter\WisskiFullTextFormatter.
 */
   
namespace Drupal\wisski_adapter_sparql11_pb\Plugin\Field\FieldFormatter;
   
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\wisski_salz\AdapterHelper;
use Drupal\Component\Utility\Html;
use Drupal\wisski_core\WisskiCacheHelper;
   
/**
 * Plugin implementation of the 'wisski_full_text_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "wisski_full_text_formatter",
 *   module = "wisski_adapter_sparql11_pb",
 *   label = @Translation("WissKI Full Text Formatter"),
 *   field_types = {
 *     "text_long",
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class WisskiFullTextFormatter extends FormatterBase implements ContainerFactoryPluginInterface {
  
  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, $field_definition, array $settings, $label, $view_mode, array $third_party_settings, ImageFactory $image_factory) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);

  #  $this->imageFactory = $image_factory;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('image.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      // Add default settings with text, so we can wrap them in t().
      // in milliseconds.
      'speed' => 100,
      'collapsedHeight' => 75,
      // In pixel.
      'heightMargin' => 16,
      'moreLink' => '<a href="#">' . t('Read more') . '</a>',
      'lessLink' => '<a href="#">' . t('Close') . '</a>',
      'embedCSS' => 1,
      'sectionCSS' => 'display: block; width: 100%;',
      'startOpen' => 0,
      'expandedClass' => 'readmore-js-expanded',
      'collapsedClass' => 'readmore-js-collapsed',
      'imagecache_external_style' => '',
      'imagecache_external_link' => '',
      'use_readmore' => 1,
    ) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $settings = $this->getSettings();
    $elements = [];
/*
    $image_styles = image_style_options(FALSE);
    $elements['imagecache_external_style'] = array(
      '#title' => t('Image style'),
      '#type' => 'select',
      '#default_value' => $settings['imagecache_external_style'],
      '#empty_option' => t('None (original image)'),
      '#options' => $image_styles,
    );
*/

    $elements['use_readmore'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use readmore'),
      '#description' => $this->t('Do you want to use readmore?'),
      '#default_value' => $this->getSetting('use_readmore'),
    ];

    $elements['speed'] = [
      '#type' => 'number',
      '#min' => 1,
      '#title' => $this->t('Speed'),
      '#description' => $this->t('Speed for show / hide read more.'),
      '#default_value' => $this->getSetting('speed'),
    ];

    $elements['collapsedHeight'] = [
      '#type' => 'number',
      '#min' => 1,
      '#title' => $this->t('Collapsed Height'),
      '#description' => $this->t('Height after which readmore will be added.'),
      '#default_value' => $this->getSetting('collapsedHeight'),
    ];

    $elements['heightMargin'] = [
      '#type' => 'number',
      '#min' => 1,
      '#title' => $this->t('Height margin'),
      '#description' => $this->t('Avoids collapsing blocks that are only slightly larger than maxHeight.'),
      '#default_value' => $this->getSetting('heightMargin'),
    ];

    $elements['moreLink'] = [
      '#type' => 'textfield',
      '#title' => $this->t('More link'),
      '#description' => $this->t('Link for more.'),
      '#default_value' => $this->getSetting('moreLink'),
    ];

    $elements['lessLink'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Less link'),
      '#description' => $this->t('Link for less.'),
      '#default_value' => $this->getSetting('lessLink'),
    ];

    $elements['embedCSS'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Embed CSS'),
      '#description' => $this->t('Insert required CSS dynamically, set this to false if you include the necessary CSS in a stylesheet.'),
      '#default_value' => $this->getSetting('embedCSS'),
    ];

    $elements['sectionCSS'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Section styling'),
      '#description' => $this->t('Sets the styling of the blocks, ignored if embedCSS is false).'),
      '#default_value' => $this->getSetting('sectionCSS'),
    ];

    $elements['startOpen'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Start open'),
      '#description' => $this->t('Do not immediately truncate, start in the fully opened position.'),
      '#default_value' => $this->getSetting('startOpen'),
    ];

    $elements['expandedClass'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Expanded class'),
      '#description' => $this->t('Class added to expanded blocks.'),
      '#default_value' => $this->getSetting('expandedClass'),
    ];

    $elements['collapsedClass'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Collapsed class'),
      '#description' => $this->t('Class added to collapsed blocks.'),
      '#default_value' => $this->getSetting('collapsedClass'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $settings = $this->getSettings();
    
    $summary[] = $this->t('Speed: @value', ['@value' => $this->getSetting('speed')]);
    $summary[] = $this->t('Collapsed Height: @value', ['@value' => $this->getSetting('collapsedHeight')]);
    $summary[] = $this->t('Height margin: @value', ['@value' => $this->getSetting('heightMargin')]);
    $summary[] = $this->t('More link: @value', ['@value' => $this->getSetting('moreLink')]);
    $summary[] = $this->t('Less link: @value', ['@value' => $this->getSetting('lessLink')]);
    $summary[] = $this->t('Embed CSS: @value', ['@value' => $this->getSetting('embedCSS')]);
    $summary[] = $this->t('Section styling: @value', ['@value' => $this->getSetting('sectionCSS')]);
    $summary[] = $this->t('Start open: @value', ['@value' => $this->getSetting('startOpen')]);
    $summary[] = $this->t('Expanded class: @value', ['@value' => $this->getSetting('expandedClass')]);
    $summary[] = $this->t('Collapsed class: @value', ['@value' => $this->getSetting('collapsedClass')]);

    
    return $summary;
  }

  /**
   * {@inheritdoc}
   *
   * TODO: fix link functions.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    
    $settings = $this->getSettings();
    $field = $items->getFieldDefinition();
    $field_settings = $this->getFieldSettings();
    $elements = [];
//    drupal_set_message(serialize($field->getLabel() == "Entity name"));
#    drupal_set_message(serialize($items[0]->getParent()->getEntity()->id()));

#    drupal_set_message("yay!" . microtime());

    foreach($items as $delta => $item) {
      $values = $item->toArray();
      
      $elements[$delta] = ['#markup' => $item->value];
      
    }
    
  
    if($settings['use_readmore']) {
  
      $integer_fields = [
        'speed',
        'collapsedHeight',
        'heightMargin',
        'embedCSS',
        'startOpen',
      ];
      foreach ($integer_fields as $key) {
        $settings[$key] = (int) $settings[$key];
      }
      $field_name = $items->getFieldDefinition()->getName();
    #foreach ($items as $delta => $item) {
#      dpm($elements, "elements");

      if(!empty($elements)) {
        $unique_id = Html::getUniqueId('field-readmore-' . $field_name);
#        $elements['#prefix'] = '<div class="field-readmore ' . $unique_id . '">';
#        $elements['#suffix'] = '</div>';

        if(!empty($elements['#attributes']) && !empty($elements['#attributes']['class'])) {
          $elements['#attributes']['class'] = array_merge($elements['#attributes']['class'], array("field-readmore " . $unique_id));
        } else {
          $elements['#attributes']['class'] = array("field-readmore " . $unique_id);
        }
#        $elements['#attached']['library'][] = 'wisski_adapter_sparql11_pb/readmorejs';
        $elements['#attached']['library'][] = 'wisski_adapter_sparql11_pb/readmore';
        $elements['#attached']['drupalSettings']['readmoreSettings'][$unique_id] = $settings;
      }
    }
  
#    dpm($elements, "returned?");
    return $elements;
  
  }
  
}                   
