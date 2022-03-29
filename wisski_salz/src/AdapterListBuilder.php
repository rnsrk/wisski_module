<?php

/**
 * @file
 * Contains \Drupal\wisski_salz\AdapterListBuilder.
 */

namespace Drupal\wisski_salz;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Provides a listing of WissKI Salz Adapter entities.
 */
class AdapterListBuilder extends ConfigEntityListBuilder {

/**
 * {@inheritdoc}
 */
  public function getOperations(EntityInterface $entity) {
    $operations = $this->getDefaultOperations($entity);

    // add an operation for querying the endpoint
    $operations['query'] = array(
      'title' => $this->t('Query'),
      'weight' => 50,
      'url' => Url::fromRoute('wisski_adapter_sparql11_pb.wisski_endpoint', array('endpoint_id' => $entity->id())),
    );

    // keep things sorted, so that it shows up in the middle
    uasort($operations, '\\Drupal\\Component\\Utility\\SortArray::sortByWeightElement');
    return $operations;
}

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('WissKI Salz Adapter');
    $header['id'] = $this->t('Machine name');
    $header['is_preferred_local'] = $this->t('Preferred Local Store');
    $header['is_writable'] = $this->t('Writable');
    $header['is_federatable'] = $this->t('Federatable');
    $header['description'] = $this->t('Description');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['is_preferred_local_store'] = $this->tickMark($entity->getEngine()->isPreferredLocalStore());
    $row['is_writable'] = $this->tickMark($entity->getEngine()->isWritable());
    $row['is_federatable'] = $this->tickMark($entity->getEngine()->supportsFederation(NULL));
    $row['description'] = $entity->getDescription();
    // You probably want a few more properties here...
    return $row + parent::buildRow($entity);
  }
  
  private function tickMark($check) {
    
    if ($check) return $this->t('&#10004;');
    return $this->t('&#10008;');
  }

}
