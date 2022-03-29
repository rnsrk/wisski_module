<?php

namespace Drupal\blockmirador\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;


/**
 * Provides a Mirador Viewer Block to display external IIIF.
 *
 * @Block(
 *   id = "BlockMirador",
 *   admin_label = @Translation("Mirador Block Viewer"),
 * )
 */

class blockmirador extends BlockBase implements BlockPluginInterface {
  /**
   * {@inheritdoc}
   */

  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    // Change this so that we get all the field names

    $form['iiif_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IIIF Field Number'),
      '#description' => $this->t('IIIF Field number'),
      '#default_value' => isset($config['iiif_field']) ? $config['iiif_field'] : '',
    ];

    $form['viewer_height'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Viewer Height'),
      '#description' => $this->t('Viewer Height'),
      '#default_value' => isset($config['viewer_height']) ? $config['viewer_height'] : '600',
    ];



    return $form;
  }


  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $this->configuration['iiif_field'] = $values['iiif_field'];
    $this->configuration['viewer_height'] = $values['viewer_height'];
  }


  public function build() {

    $config = $this->getConfiguration();

    $iiif_field = $config['iiif_field'];
    $viewer_height = $config['viewer_height'];

    return [
      '#markup' => $this-> t("<div id='mirador_block' class='mirador_block' style='position:relative'></div>"),

      '#attached' => [
         'library' => [
           'blockmirador/viewer',
          ],
          'drupalSettings' => [
            'blockmirador' => ['iiif_field' => $iiif_field,
                            'viewer_height' => $viewer_height]
          ]
       ],

    ];
  }

}

