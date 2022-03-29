<?php

namespace Drupal\wisski_doi;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Interface for the database CRUD service.
 */
interface WisskiDoiDbActionsInterface {

  /**
   * Establish database connection with query builder.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(Connection $connection, MessengerInterface $messenger, TranslationInterface $stringTranslation);

  /**
   * Write DOI data to DB.
   *
   * @param array $dbData
   *   Contains:
   *    * eid: The entity ID as eid.
   *    * doi: DOI string with prefix and suffix.
   *    * vid: The revision ID as vid.
   *    * state: The state of the DOI (draft, registered, findable).
   *    * revisionUrl: Full external URL of the revision.
   *    * isCurrent: 0|1.
   *    * created: The createion date.
   *
   * @throws \Exception
   */
  public function writeToDb(array $dbData);

  /**
   * Select the records corresponding to an entity.
   *
   * We parse the strClass $records to an array with the
   * json_decode/json_encode() functions. More transitions in
   * WisskiDoiAdministration::rowBuilder().
   *
   * @param int $eid
   *   The entity id.
   * @param int $did
   *   The internal DOI identifier from the wisski_doi table.
   * @param int $isCurrent
   *   If it is the current revision.
   *
   * @return array
   *   Dataset of corresponding DOIs to an entity.
   */
  public function readDoiRecords(int $eid, ?int $did = NULL, ?int $isCurrent = NULL);

  /**
   * Select the latest DOI corresponding to an entity.
   *
   * We parse the strClass $records to an array with the
   * json_decode/json_encode() functions.
   *
   * @param int $eid
   *   The entity id.
   * @param int $isCurrent
   *   If the DOI is for current revision.
   *
   * @return array
   *   Dataset of corresponding DOIs to an entity.
   */
  public function readLatestDoiRecords(int $eid, int $isCurrent);

  /**
   * Delete the DOI record.
   *
   * @param int $did
   *   The internal DOI id.
   *
   * @return int
   *   Dataset of corresponding DOIs to an entity.
   */
  public function deleteDoiRecord(?int $did = NULL);

  /**
   * Update the DOI record.
   *
   * @param string $state
   *   The internal DOI id.
   * @param int $did
   *   The internal DOI id.
   */
  public function updateDbRecord(string $state, ?int $did = NULL);

  /**
   * Select the individuals corresponding to a bundle.
   *
   * We parse the strClass $records to an array with the
   * json_decode/json_encode() functions.
   *
   * @param string $bundle_id
   *   The bundle id.
   *
   * @return array
   *   Dataset of corresponding DOIs to an entity.
   */
  public function readBundleRecords(string $bundle_id);

}
