<?php

function wisski_apus_dialog_select_entity() {

  $params = (object) array(
    'search_string' => '',
    'search_mode' => 'contains',
    'classes' => '',
  );
  wisski_core_parse_http_params($params, 'params');

  $form = array();

  

  return \Drupal::service('renderer')->render($form);

}

  
