<?php
/**
 * contains Drupal\wisski_core\Controller\WisskiEntityListController
 */

namespace Drupal\wisski_core\Controller;
 
use Drupal\Core\Entity\Controller\EntityListController;
 
class WisskiEntityListController extends EntityListController {

  public function listing($wisski_bundle=NULL,$wisski_individual=NULL) {
#    dpm($this->getDestinationArray());
#    dpm(func_get_args(), "yay");

    # if a bundle was provided, render the individual
    if (!is_null($wisski_bundle)) {
      return static::entityTypeManager()->getListBuilder('wisski_individual')->render($wisski_bundle,$wisski_individual);
    }

    # check if we are 'create' or 'navigate'
    if(strpos($this->getDestinationArray()['destination'], 'create') !== FALSE) {
      $type = WisskiBundleListBuilder::CREATE;
    } else {
      $type = WisskiBundleListBuilder::NAVIGATE;
    }

    return static::entityTypeManager()->getListBuilder('wisski_bundle')->render($type);
  }
}
