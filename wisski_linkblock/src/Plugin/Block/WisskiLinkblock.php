<?php

namespace Drupal\wisski_linkblock\Plugin\Block;

use Drupal\wisski_salz\Entity\Adapter;
use Drupal\wisski_pathbuilder\Entity\WisskiPathEntity;
use Drupal\wisski_core\WisskiHelper;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity as Pathbuilder;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;

use Drupal\wisski_salz\AdapterHelper;
use Drupal\wisski_core\WisskiCacheHelper;
use Drupal\wisski_core\Entity\WisskiEntity;

/**
 * Provides the WissKI Linkblock
 *
 * @Block(
 *   id = "wisski_linkblock",
 *   admin_label = @Translation("WissKI Linkblock"),
 * )
 */

class WisskiLinkblock extends BlockBase {
  
  /**
   * {@inheritdoc}
   */

  public function blockForm($form, FormStateInterface $form_state) {
    
    $form = parent::blockForm($form, $form_state);
    
    $linkblockpbid = "wisski_linkblock";
    
#    $form = parent::blockForm($form, $form_state);
    
    $config = $this->getConfiguration();

    $form['multi_pb'] = [
      '#type' => 'checkbox',
      '#title' => 'Use linkblock with any pathbuilder and adapter',
      '#default_value' => isset($config['multi_pb']) ? $config['multi_pb'] : 0,
    ];

    $field_options = array(
      Pathbuilder::CONNECT_NO_FIELD => $this->t('Do not connect a pathbuilder'),
      Pathbuilder::GENERATE_NEW_FIELD => $this->t('Create a block specific pathbuilder'),
    );
    
    $pbs = \Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity::loadMultiple();
    
    foreach($pbs as $pb) {
      $field_options[$pb->id()] = $pb->getName();
    }                      

    $form['pathbuilder'] = array(
      '#type' => 'select',
      '#title' => $this->t('Pathbuilder'),
      '#description' => $this->t('What pathbuilder do you want to choose as a source for paths for this linkblock?'),
      '#options' => $field_options,
      '#default_value' => isset($config['pathbuilder']) ? $config['pathbuilder'] : Pathbuilder::GENERATE_NEW_FIELD,
    );
        
    return $form;
  }
  

  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['multi_pb'] = $form_state->getValue('multi_pb');
    
#    dpm($form_state->getValues());
    
    // if the user said he wants a new one, he gets a new one!
    if($form_state->getValue('pathbuilder') == Pathbuilder::GENERATE_NEW_FIELD) {
      // I don't know why the id is hidden there...
      $block_id = $form_state->getCompleteFormState()->getValue('id');
      // title can be received normally.
      $title = $form_state->getValue('label');
      
      // generate a pb with a nice name - but it is unique for this block due to its id.            
      $pb = new \Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity(array("id" => 'pb_' . $block_id, "name" => "" . $title . " (Linkblock)"), "wisski_pathbuilder");   
      $pb->setType("linkblock");
      $pb->save();
      
      $this->configuration['pathbuilder'] = $pb->id();
    } else {
      $this->configuration['pathbuilder'] = $form_state->getValue('pathbuilder');
    }
    
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $config = $this->getConfiguration();
#    if (isset($config['better_lb']) && $config['better_lb']) {
#      return $this->betterBuild();
#    }

#  dpm($config);

    // check if we ask for multiple pbs and multiple adapters.
    if(isset($config['multi_pb']))
      $multimode = $config['multi_pb'];
    else
      $multimode = FALSE;

    $out = array();

    // what individual is queried?
    $individualid = \Drupal::routeMatch()->getParameter('wisski_individual');
    // if we get an entity, just use the id for the inner functions
    if ($individualid instanceof WisskiEntity) $individualid = $individualid->id();

    // if we have no - we're done here.
    if(empty($individualid)) {
      return $out;
    }
    
    if(isset($config['pathbuilder']))
      $linkblockpbid = $config['pathbuilder'];
    else
      $linkblockpbid = NULL;
      
    if(empty($linkblockpbid)) {
      $this->messenger()->addError("No Pathbuilder is specified for Linkblock.");
      return $out;
    }
    
    $pb = \Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity::load($linkblockpbid);
    
    if(empty($pb)) {
      $this->messenger()->addError("Something went wrong while loading data for Linkblock. No Pb was found!");
      return $out;
    }
    
    $language = \Drupal::service('language_manager')->getCurrentLanguage()->getId();
    
    // load all pbs only in multimode
    if($multimode)
      $pbs = \Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity::loadMultiple();
    else
      $pbs = array($pb);
      
    $dataout = array();

    // load all adapters here so we load them only once...    
    // in case of multimode, select all adapters
    if($multimode) 
      $adapters = Adapter::loadMultiple(); //entity_load_multiple('wisski_salz_adapter');            
    else // else use just the given one.
      $adapters = array(Adapter::load($pb->getAdapterId()));
        
    foreach($pbs as $datapb) {
        
      // skip the own one only in multimode
      if($pb == $datapb && $multimode)
        continue;


      // get the bundleid for the individual    
      $bundleid = $datapb->getBundleIdForEntityId($individualid);
      
      // get the group for the bundleid
      $groups = $datapb->getGroupsForBundle($bundleid);

      // iterate all groups    
      foreach($groups as $group) {
        $linkgroup = WisskiPathEntity::load($group->id());


        // if there is any
        if(!empty($linkgroup)) {
          $allpbpaths = $pb->getPbPaths();
          $pbtree = $pb->getPathTree();

          // if there is nothing, then don't show up!
          if(empty($allpbpaths) || !isset($allpbpaths[$linkgroup->id()]))
//            return;
// do not return! this leads to other pbs being unable to answer!
            continue;

          $pbarray = $allpbpaths[$linkgroup->id()];

          // for every path in there, load something
          foreach($pbtree[$linkgroup->id()]['children'] as $child) {
            $childid = $child['id'];

            // better catch these.            
            if(empty($childid) || ( isset($allpbpaths[$childid]) && $allpbpaths[$childid]['enabled'] == 0 ) )
              continue;

            $path = WisskiPathEntity::load($childid);
#drupal_set_message("child: " . serialize($childid));            
#            $adapters = \Drupal\wisski_salz\Entity\WisskiSalzAdapter

#              dpm($adapters);

            foreach($adapters as $adapter) {
              $engine = $adapter->getEngine();

              // get the data for this specific thing
              $tmpdata = $engine->pathToReturnValue($path, $pb, $individualid, 0, 'target_id', FALSE);
              $detectedEntries = array();
              
              
#              dpm(serialize($tmpdata), "tmp?");
#              dpm($path, "path?");
#              drupal_set_message("path: " . serialize($path));
              if(!empty($tmpdata)) {
                $dataout[$path->id()]['path'] = $path;

                $dataout[$path->id()]['adapter'] = $adapter;

                if(!isset($dataout[$path->id()]['data']))
                  $dataout[$path->id()]['data'] = array();

                // This part makes the linkblock language sensitive
                // We store the correct value in $detectedEntry if an adequate translation/language id for this entity exists
                // In case no translation for the requested language exists, $detectedEntry is empty and we take the first
                // translation we found for this entry, what should be normally the original language in which the entry was created
                //$detectedEntry = array();
#                dpm($tmpdata, "tmp?");
                
                foreach ($tmpdata as $tmp_id => $tmp) {
                  // check if language exists or if it is und 
                  // it also might be that it does not exist - e.g. if
                  // the path is an entity reference
                  if(isset($tmp['wisski_language']) && ($tmp['wisski_language'] == $language || $tmp['wisski_language'] == "und")){
                    $detectedEntries[] = $tmp;
                    // we do not break here - otherwise we don't get all results for this path!
                    //break;
                  }
                }
                
                
                if(empty($detectedEntries)){
                  // this is greatly wrong!
                  // tmpdata is an array of values for this path and not a single one.
                  //$detectedEntry = array(current($tmpdata));
                  $detectedEntries = $tmpdata;
                }
                $dataout[$path->id()]['data'] = array_merge($dataout[$path->id()]['data'], $detectedEntries);
             }
            }

          }

        }
        #dpm($linkgroup);
      }
    }

    // cache for 2 seconds so subsequent queries seem to be fast
#    if(!empty($dataout))  
    $out[]['#cache']['max-age'] = 2;
    // this does not work
#    $out['#cache']['disabled'] = TRUE;
#    $out[] = [ '#markup' => 'Time : ' . date("H:i:s"),];
#    drupal_set_message(serialize($dataout));
    $topBundles = array();
    $set = \Drupal::configFactory()->getEditable('wisski_core.settings');
    $only_use_topbundles = $set->get('wisski_use_only_main_bundles');

    if($only_use_topbundles) 
      $topBundles = WisskiHelper::getTopBundleIds();

    foreach($dataout as $pathid => $dataarray) {
      $path = $dataarray['path'];
      $adapter = $dataarray['adapter'];
      
      if(empty($dataarray['data']))
        continue;
      
      $out[] = [ '#markup' => '<h3>' . $path->getName() . '</h3>'];
      
      foreach($dataarray['data'] as $data) {

        $url = NULL;

        if(isset($data['wisskiDisamb']))  	    
          $url = $data['wisskiDisamb'];

        if(!empty($url)) {

          $entity_id = AdapterHelper::getDrupalIdForUri($url);
          
          if(!empty($adapter))
            $bundles = $adapter->getBundleIdsForEntityId($entity_id);
          else
            $bundles = NULL;
                      
          $bundle = NULL;
          if($only_use_topbundles) {
            $topbundletouse = array_intersect($bundles, $topBundles);
            if(!empty($topbundletouse))
              $bundle = current($topbundletouse);
          } else {
            $bundle = current($bundles);
          }

#          dpm($data);
          
          // hack if really no bundle was supplied... should never be called!
          if(empty($bundle)) {
            $entity =  WisskiEntity::load($entity_id);
            $bundle = $entity->bundle;
          }
#          dpm($entity);
          $url = 'wisski/navigate/' . $entity_id . '/view';
#          dpm($bundle);

          // special handling for paths with datatypes - use the value from there for reference
          // if you don't want this - use disamb directly!
          if($path->getDatatypeProperty() != "empty") {
          
            $out[] = array(
              '#type' => 'link',
#  	          '#title' => $data['target_id'],
              '#title' => $data['target_id'], //wisski_core_generate_title($entity_id, FALSE, $bundle),
              '#url' => Url::fromRoute('entity.wisski_individual.canonical', ['wisski_individual' => $entity_id]), 
            );
            $out[] = [ '#markup' => '</br>' ];

          } else {
            // By Mark: Here we need language handling...
            $title = wisski_core_generate_title($entity_id, NULL, FALSE, $bundle);

            // now we have in title an array... but this is not very happy for post-processing... we need to get
            // the correct title for the display language from this.
            
            $curr_lang = \Drupal::service('language_manager')->getCurrentLanguage()->getId();
            
            if(isset($title[$curr_lang])) {
              // if the current language is in there - take it
              $title = current($title[$curr_lang]);
              
              if(isset($title['value']))
                $title = $title['value'];

            } else {
             // if not, take any for now
             // @TODO: This assumption might be false! It might be better to take something different!
              $title = current(current($title));
              
              if(isset($title['value']))
                $title = $title['value'];
            }
            
            $out[] = array(
              '#type' => 'link',
#  	          '#title' => $data['target_id'],
              '#title' => $title,
              '#url' => Url::fromRoute('entity.wisski_individual.canonical', ['wisski_individual' => $entity_id]), 
              //Url::fromUri('internal:/' . $url . '?wisski_bundle=' . $bundle),
            );
            $out[] = [ '#markup' => '</br>' ];
          }
        } else {
          $out[] = array(
            '#type' => 'item',
            '#markup' =>  $data['target_id'],
          );
          $out[] = [ '#markup' => '</br>' ];
        }
        
      }  
    }
#    dpm($out, "out?");
    return $out;
  }

  public function getCacheTags() {
  
    $node = \Drupal::routeMatch()->getParameter('wisski_individual');

    // if the node is an object, reduce it to its id
    if(is_object($node))
      $node = $node->id();
    
    //With this when your node change your block will rebuild
    if ($node) {
      //if there is node add its cachetag
      return Cache::mergeTags(parent::getCacheTags(), array('wisski_individual:' . $node));
    } else {
      //Return default tags instead.
      return parent::getCacheTags();
    }
  }

  public function getCacheContexts() {
    //if you depend on \Drupal::routeMatch()
    //you must set context of this block with 'route' context tag.
    //Every new route this block will rebuild
    return Cache::mergeContexts(parent::getCacheContexts(), array('route'));
  }

}
