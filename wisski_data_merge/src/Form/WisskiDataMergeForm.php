<?php
/**
 * @file
 *
 */

namespace Drupal\wisski_data_merge\Form;

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
class WisskiDataMergeForm extends FormBase {

  /**
   * {@inheritdoc}.
   * The Id of every WissKI form is the name of the form class
   */
  public function getFormId() {
    return 'WisskiDataMergeForm';
  }

  /**
   * {@inheritdoc}.
   * 
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

    // generate a select field
    $form['select_group'] = array(
      '#type' => 'select',
      '#title' => $this->t('Select the group which you want to merge data.'),
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
        
      $paths = array();
    
      foreach($pbs as $pb) {
        $paths = array_merge($paths, $pb->getAllPathsForGroupId($selected_group, TRUE));
      }
    
      foreach($paths as $id => $path) {
        $path_names[$path->id()] = $path->label();
      }
      
    
      $selected_path = "";

      $selected_path = !empty($form_state->getValue('select_path')) ? $form_state->getValue('select_path') : "0";

      // generate a select field
      $form['stores']['select_path'] = array(
        '#type' => 'select',
        '#title' => $this->t('Select the Path which you want to merge data for duplicates.'),
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

          $query = "SELECT ?out (COUNT(?out) as ?anzahl) (GROUP_CONCAT( ?out;separator = ', ') as ?grp) WHERE { " . $triples . " } GROUP BY ?out ORDER BY DESC(?anzahl)";
          
#          dpm($query, "query?");
          
          $result = $engine->directQuery($query);
          
          if(!empty($result) && count($result) > 0 ) {

            $options = array();
            $reses = array();
            
            foreach($result as $res) {

              $grp = $res->grp->getValue();

              $html = $res->anzahl . "x - '" . $res->out . "' - " . $this->t('Entities: ') . $grp . "";
              $options[$i] = $html;
              
              $outval = $res->out->getValue();
              
              $outval = $engine->escapeSparqlLiteral($outval);
              
              $reses[$i] = $outval;
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

    $selected_path = $form_state->getValue('select_path'); 

    $path = \Drupal::entityTypeManager()->getStorage('wisski_path')->load($selected_path);

#    dpm($table, "table?");

    // if it is selected it should be "1" in the value... or at least not 0
    foreach($table as $key => $value) {

#      dpm($url_arr[$key], "got?");

      if(empty($value)) 
        continue;

#        // dont work on only 1 uri...
#      if(strpos($url_arr[$key], ", ") === FALSE)
#        continue;

#      $values = explode(", ", $url_arr[$key]);

#      $outvalue = current($values);

      $outvalue = $url_arr[$key];
      
      $pbs = \Drupal::entityTypeManager()->getStorage('wisski_pathbuilder')->loadMultiple();
      
      foreach($pbs as $pb) {
        $adapter = \Drupal::entityTypeManager()->getStorage('wisski_salz_adapter')->load($pb->getAdapterId());
        
        $engine = $adapter->getEngine();
        
        if(!($engine instanceof \Drupal\wisski_adapter_sparql11_pb\Plugin\wisski_salz\Engine\Sparql11EngineWithPB))
          continue;
        
        $triples = $engine->generateTriplesForPath($pb, $path);

        $query = "SELECT * WHERE { " . $triples . " FILTER( ?out = '" . $outvalue . "' ) . } LIMIT 1";
        
#        dpm($query, "query?");
        
        $result = $engine->directQuery($query);
        
        $filters = " FILTER( ?out = '" . $outvalue . "' ) . ";

        $delfront = "DELETE { ";
#        $wherefront = "WHERE { ";
        
        foreach($result as $res) {
        
          $pa = $path->getPathArray();
          $palength = count($pa);
          
#          dpm($pa, "pa?");
          
          #dpm($res, "res");
          #dpm($path->getPathArray(), "pa?");
          #dpm(count($path->getPathArray()), "path");
          
          for($pos = 2; $pos < $palength; $pos += 2) {
            $var = "x" . $pos;
            $inverse = $engine->getInverseProperty($pa[($pos -1)]);
            $filters .= " FILTER( ?x" . $pos . " != <" . $res->$var . "> ) . ";
            $delfront .= "?x" . $pos . " a <" . $pa[$pos] . "> . ";
            $delfront .= "?x" . ($pos-2) . " <" . $pa[($pos -1)] . "> ?x" . $pos . " . ";
            
            if($inverse)
              $delfront .= "?x" . $pos . " <" . $inverse . "> ?x" . ($pos-2) . " . ";
          }
          
          $delfront .= "?x" . ($pos-2) . " <" . $path->getDatatypeProperty() . "> '" . $outvalue . "'";
          
#          dpm($filters, "fil");
#          dpm($delfront, "del?");
        }
        
        $triples = $engine->generateTriplesForPath($pb, $path);
        
        $delquery = $delfront . " } WHERE { " . $triples . $filters . " }";
        
#        dpm($delquery, "query");

        $result = $engine->directUpdate($delquery);
        
        $this->messenger()->addMessage($this->t('I merged deleted %first.', ['%first' => $outvalue]));
        
      }           
      
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
