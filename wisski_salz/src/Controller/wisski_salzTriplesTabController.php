<?php

namespace Drupal\wisski_salz\Controller;

use Symfony\Component\Routing\Exception\InvalidParameterException;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use \Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\wisski_core;
use Drupal\wisski_salz\AdapterHelper;
use Drupal\wisski_salz\Plugin\wisski_salz\Engine\Sparql11Engine;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Link;

class wisski_salzTriplesTabController extends ControllerBase {

  public function forward($wisski_individual) {


    $storage = \Drupal::service('entity_type.manager')->getStorage('wisski_individual');

    //let's see if the user provided us with a bundle, if not, the storage will try to guess the right one
    $match = \Drupal::request();
    $bundle_id = $match->query->get('wisski_bundle');
    if ($bundle_id) $storage->writeToCache($wisski_individual,$bundle_id);

    // get the target uri from the parameters
    $target_uri = $match->query->get('target_uri');

    $entity = $storage->load($wisski_individual);
    
    // if it is empty, the entity is the starting point
    if(empty($target_uri)) {

#      $target_uri = AdapterHelper::getUrisForDrupalId($entity->id());
      $target_uri = AdapterHelper::getOnlyOneUriPerAdapterForDrupalId($entity->id());      
      $target_uri = current($target_uri);
      
    } else // if not we want to view something else
      $target_uri = urldecode($target_uri);
      
    // go through all adapters    
    $adapters = \Drupal::entityTypeManager()->getStorage('wisski_salz_adapter')->loadMultiple();

    #$my_url = \Drupal\Core\Url::fromRoute('wisski_salz.wisski_individual.triples', $entity->id()));

    $form['in_triples'] = array(
      '#type' => 'table',
      '#caption' => $this->t('In-coming triples'),
      '#header' => array('Subject', 'Predicate', 'Object', 'Graph', 'Adapter'),
    );
    
    $form['out_triples'] = array(
      '#type' => 'table',
      '#caption' => $this->t('Out-going triples'),
      '#header' => array('Subject', 'Predicate', 'Object', 'Graph', 'Adapter'),
    );
    #return $form;

    // keep track if some adapter supported triples
    $any_had_triples = false;

    foreach ($adapters as $a) {
      $label = $a->label();
      $e = $a->getEngine();
      
      // if adapters don't support triples, don't ask it for any
      if (!$e->supportsTriples()) {
        continue;
      }
      $any_had_triples = true;
      
      $values = 'VALUES ?x { <' . $target_uri . '> } ';
      $q = "SELECT ?g ?s ?sp ?po ?o WHERE { $values { { GRAPH ?g { ?s ?sp ?x } } UNION { GRAPH ?g { ?x ?po ?o } } } }";
#        dpm($q);
      $results = $e->directQuery($q);
      foreach ($results as $result) {
#var_dump($result);
        if (isset($result->sp)) {
          
          $existing_bundles = $e->getBundleIdsForUri($result->s->getUri());

          if(empty($existing_bundles))
            $subjecturi = Url::fromRoute('wisski_salz.wisski_individual.triples', array('wisski_individual' => $entity->id(), 'target_uri' => $result->s->getUri() ) );
          else {
            $remote_entity_id = $e->getDrupalId($result->s->getUri());
            $subjecturi = Url::fromRoute('wisski_salz.wisski_individual.triples', array('wisski_individual' => $remote_entity_id, 'target_uri' => $result->s->getUri() ) );
          }

          $predicateuri = Url::fromRoute('wisski_salz.wisski_individual.triples', array('wisski_individual' => $entity->id(), 'target_uri' => $result->sp->getUri() ) );

          $objecturi = Url::fromRoute('wisski_salz.wisski_individual.triples', array('wisski_individual' => $entity->id(), 'target_uri' => $target_uri ) );

#            dpm(\Drupal::l($this->t('sub'), $subjecturi));
          $form['in_triples'][] = array(
#              "<" . $result->s->getUri() . ">",
            Link::fromTextAndUrl($this->t($result->s->getUri()), $subjecturi)->toRenderable(),
            Link::fromTextAndUrl($this->t($result->sp->getUri()), $predicateuri)->toRenderable(),
            Link::fromTextAndUrl($this->t($target_uri), $objecturi)->toRenderable(),
            array('#type' => 'item', '#title' => $result->g->getUri()),
            array('#type' => 'item', '#title' => $label),
          );
        } else {
          
          $subjecturi = Url::fromRoute('wisski_salz.wisski_individual.triples', array('wisski_individual' => $entity->id(), 'target_uri' => $target_uri ) );

          $predicateuri = Url::fromRoute('wisski_salz.wisski_individual.triples', array('wisski_individual' => $entity->id(), 'target_uri' => $result->po->getUri() ) );
#         dpm("??"); 
          if($result->o instanceof \EasyRdf_Resource or get_class($result->o) == "EasyRdf\Resource") {
            try {
            
              $existing_bundles = $e->getBundleIdsForUri($result->o->getUri());
              
              if(empty($existing_bundles))
                $objecturi = Url::fromRoute('wisski_salz.wisski_individual.triples', array('wisski_individual' => $entity->id(), 'target_uri' => $result->o->getUri() ) );
              else {
                $remote_entity_id = $e->getDrupalId($result->o->getUri());              
                $objecturi = Url::fromRoute('wisski_salz.wisski_individual.triples', array('wisski_individual' => $remote_entity_id, 'target_uri' => $result->o->getUri() ) );
              }
              $got_target_url = TRUE;
            } catch (InvalidParameterException $ex) {
              $got_target_url = FALSE;
            }
            $object_text = $result->o->getUri();
          } else {
            $got_target_url = FALSE;
            $object_text = $result->o->dumpValue('string');
          }
          $graph_uri = isset($result->g) ? $result->g->getUri() : 'DEFAULT';
          $form['out_triples'][] = array(
            Link::fromTextAndUrl($target_uri, $subjecturi)->toRenderable(),
            Link::fromTextAndUrl($result->po->getUri(), $predicateuri)->toRenderable(),
            $got_target_url ? Link::fromTextAndUrl($object_text, $objecturi)->toRenderable() : array('#type' => 'item', '#title' => $object_text),
            array('#type' => 'item', '#title' => $graph_uri),
            array('#type' => 'item', '#title' => $label),
          );
        }
      }
    }

    // if no adapter supports triples, tell the user
    if (! $any_had_triples ) {
      return array(
        '#type' => 'markup',
        '#markup' => t('No WissKI Adapter that supports displaying triples. '),
      );
    }
    

    $form['#title'] = $this->t('View Triples for ') . $target_uri;

    return $form;

  }
}
