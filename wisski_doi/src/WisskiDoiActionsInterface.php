<?php

namespace Drupal\wisski_doi;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\wisski_core\WisskiEntityInterface;
use Drupal\wisski_doi\WisskiDoiDbActions;
use Drupal\wisski_doi\WisskiDoiDataciteRestActions;

/**
 * Provides Interface for manage DOI metadata actions.
 *
 *   Receive metadata from DOI provider, and get DOI for
 *   static and current revision, both for single and multiple datasets.
 */
interface WisskiDoiActionsInterface {

  /**
   * Constructs a new form to request a DOI for a static revision.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The translations service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\wisski_doi\WisskiDoiDataciteRestActions $wisskiDoiRestActions
   *   The WissKi DOI Rest service.
   * @param \Drupal\wisski_doi\WisskiDoiDbActions $wisskiDoiDbActions
   *   The WissKI DOI database service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The Drupal entity type manager service.
   */
  public function __construct(TranslationInterface         $stringTranslation,
                              DateFormatterInterface       $date_formatter,
                              TimeInterface                $time,
                              WisskiDoiDataciteRestActions $wisskiDoiRestActions,
                              WisskiDoiDbActions           $wisskiDoiDbActions,
                              EntityTypeManagerInterface   $entityTypeManager
  );

  /**
   * Assembles metadata from WissKI individual.
   *
   * @param \Drupal\wisski_core\WisskiEntityInterface $wisskiIndividual
   *   The WissKI Individual.
   *
   * @return array
   *   The metadata of the WissKI individual.
   */
  public function getWisskiIndividualMetadata(WisskiEntityInterface $wisskiIndividual);

  /**
   * Requests a DOI for a static revision.
   *
   * Saves two revisions, to yield a static revision,
   * requests a DOI for that revision and saves DOI data
   * to local database.
   *
   * @param \Drupal\wisski_core\WisskiEntityInterface $wisskiIndividual
   *   The WissKI individual.
   * @param array $doiMetadata
   *   The DOI metadata.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function getStaticDoi(WisskiEntityInterface $wisskiIndividual, array $doiMetadata);

  /**
   * Requests a DOI for a Current revision.
   *
   * Just requests a DOI for the current revision and saves DOI data
   * to local database.
   *
   * @param \Drupal\wisski_core\WisskiEntityInterface $wisskiIndividual
   *   The WissKI individual.
   * @param array $doiMetadata
   *   The DOI metadata.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function getCurrentDoi(WisskiEntityInterface $wisskiIndividual, array $doiMetadata);

  /**
   * Loops over all selected WissKI and requests DOIs.
   *
   * @param bool $current
   *   DOI for current or static revision.
   * @param array $wisskiIndividualsBatch
   *   The batch set with individuals that should get a DOI.
   * @param array $doiMetaData
   *   The metadata for the DOI.
   * @param string $individualsInBatchStateName
   *   The name of the config store.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function batchLoop(bool $current, array $wisskiIndividualsBatch, array $doiMetaData, string $individualsInBatchStateName);

}
