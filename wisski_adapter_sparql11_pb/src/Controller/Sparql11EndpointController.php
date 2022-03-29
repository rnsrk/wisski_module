<?php

namespace Drupal\wisski_adapter_sparql11_pb\Controller;

use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use \Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\wisski_core;
use Drupal\wisski_salz\AdapterHelper;
use Drupal\wisski_salz\Plugin\wisski_salz\Engine\Sparql11Engine;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Link;

use \Drupal\Core\Form\FormBase;
use EasyRDF\Sparql\Result as EasyRdf_Sparql_Result;

class Sparql11EndpointController extends FormBase {

  public function getFormId() {
    return "wisski_adapter_sparql11_pb_endpoint_form";
  }

  public function buildForm(array $form, FormStateInterface $form_state, $endpoint_id = NULL) {

    // early opt out.
    if(empty($endpoint_id))
      return array();

    $request = \Drupal::request();

    $headers = $request->headers;

    $accept = $headers->get("accept");

    // default is text
    $dumpformat = "text";
    
    // if the user wants html - give that to him
    if(strpos($accept, "text/html") !== FALSE) {
      $dumpformat = "html";
    }
     
    // this is here for later, but it does not help me up to now.   
    //$format_string = \EasyRdf_Utils::parseMimeType($accept);

    // if we have something from GET - prepopulate that!
    // @TODO: Handle POST
    if(isset($_GET['query']))
      $query = $_GET['query'];
    else
      $query = "";
      
    $query_fs = $form_state->getUserInput();
     
    if(!empty($query_fs['query']))
      $query = $query_fs['query'];
      
    $form['#title'] = "Query Endpoint " . $endpoint_id;

    $form['text']['#value'] = "Any SPARQL 1.1 SELECT or ASK Query can be stated here - no updates. You can also use it as a dynamic endpoint via GET/POST with the query parameter.";

    $form['endpoint_id'] = array(
      '#type' => 'hidden',
      '#value' => $endpoint_id
    );
    
    $result = NULL;

    if(!empty($query)) {
      $adapter = \Drupal::entityTypeManager()->getStorage('wisski_salz_adapter')->load($endpoint_id);

      $engine = $adapter->getEngine();
    
      $result = $engine->directQuery($query);
    }

    // the output for the user
    $dump = "";
    
    // should it be dumped as html?
    $htmldump = TRUE;
    
    if($result) {
      // Select and Ask result in such a Result, we can return this only as text or html
      if($result instanceOf EasyRdf_Sparql_Result)
        $dump = $result->dump($dumpformat);
      else { // it must be a graph
        // @Todo: Support anything else here.
        $dump = $result->serialise("rdfxml");
        $htmldump = FALSE;
      }
    }
    
    $form['result'] = array(
      '#type' => 'html_tag',
      '#title' => 'Result',
      '#value' => $dump,
      '#tag' => 'table'
    );

    $form['query'] = array(
      '#type' => 'textarea',
      '#title' => "Query",
      '#value' => $query
    );
      
    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Execute Query')
    );
    
    // if we dont want a html dump, dump it differently.
    if(!$htmldump) {
      $response = new Response();
      $response->setContent($dump);
      $response->headers->set('Content-Type', 'text/xml');
      // if so, simply dump that
      return $response;
      #print $dump;
      #exit();
    }

    return $form;
  }
  
  /**
   * @Inheritdoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // if there is a get parameter it is already in the form, so we can unset that here.    
    if(isset($_GET['query']))
      unset($_GET['query']);

    // we want to get back where we came from
    $form_state->setRebuild();
  }


}
