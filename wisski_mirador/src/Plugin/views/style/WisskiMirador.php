<?php

namespace Drupal\wisski_mirador\Plugin\views\style;

use Drupal\core\form\FormStateInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;


/**
 * Style plugin to render a mirador viewer as a
 * views display style.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "wisskimirador",
 *   title = @Translation("WissKI Mirador"),
 *   help = @Translation("The WissKI Mirador views Plugin"),
 *   theme = "views_view_wisskimirador",
 *   display_types = { "normal" }
 * )
 */
class WisskiMirador extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['path'] = array('default' => 'wisski_mirador');
    $options['enable_annotations'] = array('default' => "");
    $options['entity_type_for_annotation'] = array('default' => "");
    $options['bundle_for_annotation'] = array('default' => "");
    $options['field_for_annotation_id'] = array('default' => "");
    $options['field_for_annotation_json'] = array('default' => "");
    $options['field_for_annotation_entity'] = array('default' => "");
    $options['field_for_annotation_reference'] = array('default' => "");
    $options['window_settings'] = array('default' => '{
            "allowClose": false,
            "allowFullscreen": true,
            "allowMaximize": false,
            "allowTopMenuButton": true,
            "allowWindowSideBar": true,
            "sideBarPanel": "info",
            "defaultSideBarPanel": "attribution",
            "sideBarOpenByDefault": false,
            "defaultView": "single",
            "forceDrawAnnotations": true,
            "hideWindowTitle": true,
            "highlightAllAnnotations": false,
            "imageToolsEnabled": true,
            "showLocalePicker": true,
            "sideBarOpen": true,
            "switchCanvasOnSearch": true,
            "panels": {
              "info": true,
              "attribution": true,
              "canvas": true,
              "annotations": true,
              "search": true,
              "layers": true
            }
      }');

    return $options;
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['enable_annotations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Annotations'),
      '#description' => $this->t('This enables annotation storage with Mirador. Annotations are stored locally and you have to define a mapping in Drupal to an associated entity here.'),
      '#default_value' => $this->options['enable_annotations'],
    ];

    $form['entity_type_for_annotation'] = [
      '#title' => $this->t('Entity type for annotations'),
      '#description' => $this->t('The machine name of the entity type the annotations are stored to.'),
      '#type' => 'textfield',
      '#size' => '50',
      '#default_value' => $this->options['entity_type_for_annotation'],
    ];

    $form['bundle_for_annotation'] = [
      '#title' => $this->t('Bundle for annotations'),
      '#description' => $this->t('The machine id of the bundle the annotations are stored to. The bundle needs fields to store the annotations which are defined below.'),
      '#type' => 'textfield',
      '#size' => '50',
      '#default_value' => $this->options['bundle_for_annotation'],
    ];

    $form['field_for_annotation_id'] = [
      '#title' => $this->t('Field for annotation id'),
      '#description' => $this->t('The machine id of the field for the id of the annotation. This is needed for searching etc.'),
      '#type' => 'textfield',
      '#size' => '50',
      '#default_value' => $this->options['field_for_annotation_id'],
    ];

    $form['field_for_annotation_json'] = [
      '#title' => $this->t('Field for annotation json dump'),
      '#description' => $this->t('This field (machine id) serves as a data dump for the whole annotation json.'),
      '#type' => 'textfield',
      '#size' => '50',
      '#default_value' => $this->options['field_for_annotation_json'],
    ];

    $form['field_for_annotation_entity'] = [
      '#title' => $this->t('Field for annotation entity'),
      '#description' => $this->t('This field (machine name) stores the entity that is annotated (usually the filename of the image file).'),
      '#type' => 'textfield',
      '#size' => '50',
      '#default_value' => $this->options['field_for_annotation_entity'],
    ];

    $form['field_for_annotation_reference'] = [
      '#title' => $this->t('Field for annotation reference'),
      '#description' => $this->t('This field (machine name) stores the referred entity the annotation is created upon (e.g. the object that the above file depicts).'),
      '#type' => 'textfield',
      '#size' => '50',
      '#default_value' => $this->options['field_for_annotation_reference'],
    ];

    $form['window_settings'] = [
      '#title' => $this->t('window'),
      '#description' => $this->t('Settings for Mirador "window" parameter'),
      '#type' => 'textarea',
      '#default_value' => $this->options['window_settings'],
    ];

    return $form;
  }

  public function usesFields() {
    return TRUE;
  }

  public function validate() {
    $fields = FALSE;

    $errors = array();

    foreach ($this->displayHandler->getHandlers('field') as $field) {
      if (empty($field->options['exclude'])) {
        $fields = TRUE;
      }
    }

    if (!$fields) {
      $errors[] = $this->t('No fields are selected for display purpose. For Mirador you either need to display entity id (then the manifests get loaded automatically) or you need to display a field with a reference (http...) to an IIIF manifest.');
    }

    if(empty($errors))
      parent::validate();

    return $errors;
  }

  /**
   * Renders the View.
   */
  public function render() {

    $view = $this->view;

#    dpm($view->field);

    $results = $view->result;

#    dpm($results);

    $ent_list = array();
    $direct_load_list = array();

    $site_config = \Drupal::config('system.site');

    $to_print = "";

    if(!empty($site_config->get('name')))
      $to_print .= $site_config->get('name');
    if(!$site_config->get('slogan')) {
      if(!empty($to_print) && !empty($site_config->get('slogan'))) {
        $to_print .= " (" . $site_config->get('slogan') . ") ";
      } else {
        $to_print .= $site_config->get('slogan');
      }
    }

    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
      $base_url = $_SERVER["HTTP_X_FORWARDED_PROTO"] . '://' . $_SERVER['HTTP_HOST'];
    } else {
      $base_url = 'http://' . $_SERVER['HTTP_HOST'];
    }

    if(empty($to_print))
      $to_print .= $base_url;

    $iter = 0;
    $result_count = count($results);

    foreach($results as $result) {

#      dpm($result->eid);
#      return;
#      dpm(serialize($this->view));
#      dpm($result->__get('entity:wisski_individual/eid'), "res?");


      if(isset($result->eid))
        $entity_id = $result->eid;
      else
        $entity_id = NULL;

      // tuning for solr which does not have eids but stores it in entity:wisski_individual/eid
      if(empty($result->eid)) {
        if(method_exists($result, "__get")) {
          if(!empty(current($result->__get('entity:wisski_individual/eid')))) {
            $entity_id = current($result->__get('entity:wisski_individual/eid'));
          }
        }
      }

#      $entity_id = empty($result->eid) ? if(!empty(current($result->__get('entity:wisski_individual/eid'))) { current($result->__get('entity:wisski_individual/eid')) } : $result->eid;

#      dpm($result, "res?");
#      $ent_list[] = array("manifestId" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest", "manifestUri" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest", "location" => $to_print);
      if(!empty($entity_id)) {
        $ent_list[] = array("manifestId" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest");
#      $direct_load_list[] = array( "loadedManifest" => $base_url . "/wisski/navigate/" . $result->eid . "/iiif_manifest", "viewType" => "ImageView" );
        if ($result_count > 1) {
          $direct_load_list[] = array( "loadedManifest" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest", "availableViews" => array( 'ImageView'), "slotAddress" => "row1.column" . ++$iter, "viewType" => "ImageView", "bottomPanel" => false, "sidePanel" => false, "annotationLayer" => false);
        }
        else {
          $direct_load_list[] = array( "loadedManifest" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest", "availableViews" => array( 'ImageView'), "viewType" => "ImageView", "bottomPanel" => false, "sidePanel" => false, "annotationLayer" => false);
        }
      } else {
        $field_to_load_http_uri_from = $view->field;
        $field_to_load_http_uri_from = array_keys($field_to_load_http_uri_from);
        $field_to_load_http_uri_from = current($field_to_load_http_uri_from);

#        dpm($result->$field_to_load_http_uri_from);

        $result_field = $result->$field_to_load_http_uri_from;

#        dpm($result_field);
        $result_field = current($result_field);
        if(!empty($result_field["value"]))
          $result_field = $result_field["value"];
        else
          $result_field = $result_field[0]["value"];

        $ent_list[] = array("manifestId" => $result_field);

#        dpm($ent_list, "ente?");

      }
#      $direct_load_list[] = array( "loadedManifest" => $base_url . "/wisski/navigate/" . $result->eid . "/iiif_manifest", "availableViews" => array( 'ImageView'), "windowOptions" => array( "zoomLevel" => 1, "osdBounds" => array(
#            "height" => 1500,
#            "width" => 1500,
#            "x" => 1000,
#            "y" => 2000,
#        )), "slotAddress" => "row1.column" . ++$iter, "viewType" => "ImageView", "bottomPanel" => false, "sidePanel" => false, "annotationLayer" => false);
    }

    if(isset($view->attachment_before)) {
      $attachments = $view->attachment_before;

      foreach($attachments as $attachment) {
        $subview = $attachment['#view'];

        $subview->execute();
        $subcount= count($subview->result);

        foreach($subview->result as $res) {

          $entity_id = empty($res->eid) ? current($res->__get('entity:wisski_individual/eid')) : $res->eid;
          $ent_list[] = array("manifestId" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest");
#          $ent_list[] = array("manifestId" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest", "manifestUri" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest", "location" => $to_print);
          dpm($base_url);
          if ($subcount > 1) {
            $direct_load_list[] = array( "loadedManifest" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest", "availableViews" => array( 'ImageView'), "slotAddress" => "row1.column" . ++$iter, "viewType" => "ImageView", "bottomPanel" => false, "sidePanel" => false, "annotationLayer" => false );
          }
          else {
            $direct_load_list[] = array( "loadedManifest" => $base_url . "/wisski/navigate/" . $entity_id . "/iiif_manifest", "availableViews" => array( 'ImageView'), "viewType" => "ImageView", "bottomPanel" => false, "sidePanel" => false, "annotationLayer" => false );
          }
#          $direct_load_list[] = array( "loadedManifest" => $base_url . "/wisski/navigate/" . $res->eid . "/iiif_manifest", "viewType" => "ImageView" );
#          $direct_load_list[] = array( "loadedManifest" => $base_url . "/wisski/navigate/" . $res->eid . "/iiif_manifest", "availableViews" => array( 'ImageView'), "windowOptions" => array( "zoomLevel" => 1, "osdBounds" => array(
#            "height" => 2000,
#            "width" => 2000,
#            "x" => 1000,
#            "y" => 2000,
#        )), "slotAddress" => "row1.column" . ++$iter, "viewType" => "ImageView", "bottomPanel" => false, "sidePanel" => false, "annotationLayer" => false );
        }

//        dpm($subview->result, "resi!");

      }
    }

#    dpm($ent_list, "ente gut...");

    $layout = count($ent_list);

    $layout_str = "";

    if($layout < 9) {
      $layout_str = "1x" . $layout;
    } else {
      $layout_str = "1x1";
    }

#    foreach($ent_list as $ent


    $form = array();

    $form['#attached']['drupalSettings']['wisski']['mirador']['data'] = $ent_list;
    $form['#attached']['drupalSettings']['wisski']['mirador']['options'] = $this->options;

    $form['#attached']['drupalSettings']['wisski']['mirador']['window_settings'] = json_decode($this->options['window_settings'], TRUE);
    $form['#attached']['drupalSettings']['wisski']['mirador']['layout'] = $layout_str;

    if($layout < 9) {
      $form['#attached']['drupalSettings']['wisski']['mirador']['windowObjects'] = $direct_load_list;
    }

    $form['#markup'] = '<div id="viewer"></div>';
    $form['#allowed_tags'] = array('div', 'select', 'option','a', 'script');
#    #$form['#attached']['drupalSettings']['wisski_jit'] = $wisski_individual;
    $form['#attached']['library'][] = "wisski_mirador/mirador";

    $session = \Drupal::request()->getSession();
    $session->set('mirador-options', $this->options);

    return $form;

  }




}
