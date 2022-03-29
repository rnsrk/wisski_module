<?php

/**
 * @file:
 * Contains Drupal\wisski_core\WisskiEntityInterface
 */

namespace Drupal\wisski_core;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;


interface WisskiEntityInterface extends ContentEntityInterface, EntityChangedInterface, RevisionLogInterface, EntityPublishedInterface {
  
}