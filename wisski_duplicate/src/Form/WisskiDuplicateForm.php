<?php
/**
 * @file
 *
 */

namespace Drupal\wisski_duplicate\Form;

use Drupal\wisski_salz\Entity\Adapter;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

use Drupal\wisski_merge\Merger;
use Drupal\wisski_salz\AdapterHelper;

/**
 * Overview form for ontology handling
 *
 * @return form
 *   Form for the Duplication detection
 * @author Mark Fichtner
 */
class WisskiDuplicateForm extends FormBase {

  /**
   * {@inheritdoc}.
   * The Id of every WissKI form is the name of the form class
   */
  public function getFormId() {
    return 'WisskiDuplicateForm';
  }

/*  public function unify() {
    drupal_set_message('hello');
    dpm("unify is called!");
    return TRUE;
  }
*/
  public function buildForm(array $form, FormStateInterface $form_state) {
#    dpm($form_state, "fs?");
    $form = array();

    $pbs = \Drupal::entityTypeManager()->getStorage('wisski_pathbuilder')->loadMultiple();

    $groups = array();
    $group_names = array();

    foreach($pbs as $pb) {
      $groups = array_merge($groups, $pb->getAllGroups());
    }
    
    foreach($groups as $id => $group) {
      $group_names[$group->id()] = $group->label();
    }
    
    $selected_group = "";

    $selected_group = !empty($form_state->getValue('select_group')) ? $form_state->getValue('select_group') : "0";

#    dpm($group_names, "gn?");
#    dpm($selected_group, "sel?");

    // generate a select field
    $form['select_group'] = array(
      '#type' => 'select',
      '#title' => $this->t('Select the group which you want to detect duplicates.'),
      '#default_value' => $selected_group,
      '#options' => array_merge(array("0" => 'Please select.'), $group_names),
      '#ajax' => array(
        'callback' => 'Drupal\wisski_core\Form\WisskiOntologyForm::ajaxStores',
        'wrapper' => 'select_store_div',
        'event' => 'change',
        #'effect' => 'slide',

      ),
    );

    // ajax wrapper
    $form['stores'] = array(
      '#type' => 'markup',
      // The prefix/suffix provide the div that we're replacing, named by
      // #ajax['wrapper'] below.
      '#prefix' => '<div id="select_store_div">',
      '#suffix' => '</div>',
      '#value' => "",
    );
    

    // if there is already a bundle selected
    if(!empty($form_state->getValue('select_group'))) {
    
#      dpm($selected_group, "sel?");
#      dpm($pb, "pb?");
#      dpm($pb->getAllPathsForGroupId($selected_group, TRUE), "got?");
    
      $paths = array();
    
      foreach($pbs as $pb) {
        $paths = array_merge($paths, $pb->getAllPathsForGroupId($selected_group, TRUE));
      }
    
      foreach($paths as $id => $path) {
        $path_names[$path->id()] = $path->label();
      }
      
#      dpm($path_names, "pn?");
    
      $selected_path = "";

      $selected_path = !empty($form_state->getValue('select_path')) ? $form_state->getValue('select_path') : "0";

      // generate a select field
      $form['stores']['select_path'] = array(
        '#type' => 'select',
        '#title' => $this->t('Select the Path which you want to detect duplicates.'),
        '#default_value' => $selected_path,
        '#options' => array_merge(array("0" => 'Please select.'), $path_names),
        '#ajax' => array(
          'callback' => 'Drupal\wisski_core\Form\WisskiOntologyForm::ajaxStores',
          'wrapper' => 'select_store_div',
          'event' => 'change',
          #'effect' => 'slide',

        ),
      );
      
      if(!empty($form_state->getValue('select_path'))) {
        $path = \Drupal::entityTypeManager()->getStorage('wisski_path')->load($selected_path);
        $i = 1;
        foreach($pbs as $pb) {
          $adapter = \Drupal::entityTypeManager()->getStorage('wisski_salz_adapter')->load($pb->getAdapterId());
          
          $engine = $adapter->getEngine();
          
          if(!($engine instanceof \Drupal\wisski_adapter_sparql11_pb\Plugin\wisski_salz\Engine\Sparql11EngineWithPB))
            continue;
          
          $triples = $engine->generateTriplesForPath($pb, $path);

          $query = "SELECT ?out (COUNT(DISTINCT ?x0) as ?anzahl) (GROUP_CONCAT( DISTINCT ?x0;separator = ', ') as ?grp) WHERE { " . $triples . " } GROUP BY ?out ORDER BY DESC(?anzahl)";
          
#          dpm($query, "query?");
          
          $result = $engine->directQuery($query);
          
          if(!empty($result) && count($result) > 0 ) {

            $options = array();
            $reses = array();
            
            foreach($result as $res) {

              $grp = $res->grp->getValue();
              
              $urls = explode(", ", $grp);
              
              $grp = "";
              foreach($urls as $key => $url) {
                $grp .= "<a href='" . base_path() . "wisski/get?uri=" . $url . "'>" . $url . "</a>, ";
              }
              
              $grp = substr($grp, 0, -2);

              $html = $res->anzahl . "x - '" . $res->out . "' - " . $this->t('Entities: ') . $grp . "";
              $options[$i] = $html;
              $reses[$i] = $res->grp->getValue();
              $i++;
            }
            
            $form['stores']['table'] = array(
              '#type' => 'checkboxes',
              '#options' => $options,
              '#title' => $this->t('What do you want to unify?'),
            );
            
            $form['stores']['tableoptions'] = array(
              '#type' => 'hidden',
              '#value'=> $reses,
            );
            
/*
            $form['stores']['header'] = array(
              '#type' => 'item',
              '#markup' => '<b>Duplicates:</b><br/>',
            );
            
            $table = "<table><tr><th>" . $this->t('Values') . "</th><th>" . $this->t('Count') . "</th><th>" . $this->t('Uris') . "</th><th>" . $this->t('Unify') . "</th></tr>";
            
            
            
            
            foreach($result as $res) {
              $cb = array(
                '#type' => 'checkbox',
                '#name' => 'check_' . $i++,
                '#title' => $this->t('Unify'),
              );
              
              $rendered = \Drupal::service('renderer')->render($cb);
#              dpm($rendered, "ren?");
              // $table .= "<tr><td>" . $ont->ont . "</td><td>" . $ont->iri . "</td><td>" . $ont->ver . "</td><td>" . $ont->graph . "</td></tr>";
              $table .= "<tr><td>" . $res->out . "</td><td>" . $res->anzahl . "</td><td>" . $res->grp . "</td><td>" . $rendered . "</td></tr>";
            }

            $table .= "</table>";
          
            $form['stores']['table'] = array(
              '#type' => 'item',
              '#markup' => $table,
              '#allowed_tags' => ['input', 'table', 'tr', 'td', 'th'],
            );
*/            
            
          } 
          
        }
        
        $form['stores']['apply'] = array(
          '#type' => 'submit', 
          '#value' => $this->t('Unify'),
  //        '#submit' => array('::unify') 
        ); 
        
      }
   
    }

   return $form;

  }


  public static function ajaxStores(array $form, FormStateInterface $form_state) {
#   drupal_set_message('hello');
 #   dpm("yay!");
    return $form['stores'];
  }


  public function validateForm(array &$form, FormStateInterface $form_state) {
    #drupal_set_message('hello');
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
#    drupal_set_message('hello');
#    dpm(serialize($form_state->getValues()), "fs?");

    $url_arr = $form_state->getValue('tableoptions');

    $table = $form_state->getValue('table');

#    dpm($table, "table?");

    // if it is selected it should be "1" in the value... or at least not 0
    foreach($table as $key => $value) {

#      dpm($url_arr[$key], "got?");

      if(empty($value)) 
        continue;

        // dont work on only 1 uri...
      if(strpos($url_arr[$key], ", ") === FALSE)
        continue;

      $urls = explode(", ", $url_arr[$key]);

      $urls = array_unique($urls);
      
      if(count($urls) >= 2) {
      
#      dpm($urls, "urls?");
      
        $from_eids = array();
#    \Drupal::logger('MERGE ')->debug('yay44: @yay', ['@yay' => serialize($urls)]);
        foreach ($urls as $url) {
          $url = trim($url);
          if ($url == '') {
            continue;
         }

#      \Drupal::logger('MERGE ')->debug('yay2223: @yay', ['@yay' => serialize($url)]);
          $eid = AdapterHelper::getDrupalIdForUri($url, FALSE);
#        $eid = AdapterHelper::extractIdFromWisskiUri($url);
#      \Drupal::logger('MERGE ')->debug('yay222: @yay', ['@yay' => serialize($eid)]);
          $from_eids[] = $eid;
        }


#      dpm($from_eids, "from?");
        $first = array_shift($from_eids);
      
#      dpm("I merge " . $first . " with " . serialize($from_eids));

        $merger = new Merger();
        $status = $merger->mergeEntities($from_eids, $first);

        $this->messenger()->addMessage($this->t('I merged %first with %all and got status %status', ['%first' => $first, '%all' => serialize($from_eids), '%status' => $status ]));
      }
      
      // remove some of the paths that are only duplicates...
#      $query = 
#      foreach(
      
      
      
    }


    $form_state->setRebuild(TRUE);
    $form_state->setValue('table', array());
    $input = $form_state->getUserInput();
    $input['table'] = array();
    $form_state->setUserInput($input);
#    $options = 
#    dpm("submit called!");

    return;

  }
  
  public function unify($path, $url) {
    $path = \Drupal::entityTypeManager()->getStorage('wisski_path')->load($selected_path);
  }

}
