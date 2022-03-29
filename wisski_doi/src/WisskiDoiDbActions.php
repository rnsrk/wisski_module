<?php

namespace Drupal\wisski_doi;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Service for database CRUD actions.
 */
class WisskiDoiDbActions implements WisskiDoiDbActionsInterface {
  use StringTranslationTrait;

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $connection;

  /**
   * The Drupal messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  private MessengerInterface $messenger;

  /**
   * {@inheritDoc}
   */
  public function __construct(Connection $connection, MessengerInterface $messenger, TranslationInterface $stringTranslation) {
    $this->connection = $connection;
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritDoc}
   */
  public function writeToDb(array $dbData) {
    return $this->connection->insert('wisski_doi')
      ->fields([
        'eid' => $dbData['eid'],
        'doi' => $dbData['doi'],
        'vid' => $dbData['vid'] ?? NULL,
        'state' => $dbData['state'],
        'revisionUrl' => $dbData['revisionUrl'],
        'isCurrent' => empty($dbData['vid']) ? 1 : 0,
        'created' => $dbData['created'],
      ])
      ->execute();
  }

  /**
   * {@inheritDoc}
   */
  public function readDoiRecords(int $eid, ?int $did = NULL, ?int $isCurrent = NULL) {
    $query = $this->connection
      ->select('wisski_doi')
      ->fields('wisski_doi', [
        'did',
        'eid',
        'doi',
        'vid',
        'state',
        'revisionUrl',
        'isCurrent',
        'created',
      ])
      ->condition('eid', $eid, '=');

    if ($did) {
      $query = $query->condition('did', $did, '=');
    }

    if ($isCurrent) {
      $query = $query->condition('isCurrent', $isCurrent, '=');
    }

    $result = $query->orderBy('did', 'DESC')->execute()->fetchAll();

    // $result is stdClass Object, this returns an array of the results.
    return array_map(function ($record) {
      return json_decode(json_encode($record), TRUE);
    }, $result);
  }

  /**
   * {@inheritDoc}
   */
  public function readLatestDoiRecords(int $eid, int $isCurrent) {
    $query = $this->connection
      ->select('wisski_doi')
      ->fields('wisski_doi', [
        'eid',
        'doi',
        'state',
        'isCurrent',
        'created',
      ])
      ->condition('eid', $eid, '=')
      ->condition('isCurrent', $isCurrent, '=');
    $result = $query->orderBy('created', 'DESC')->execute()->fetch();

    // $result is stdClass Object, this returns an array of the results.
    return json_decode(json_encode($result), TRUE);
  }

  /**
   * {@inheritDoc}
   */
  public function deleteDoiRecord(?int $did = NULL) {
    $result = $this->connection->delete('wisski_doi')
      ->condition('did', $did)
      ->execute();
    $this->messenger->addStatus($this->t('Deleted DOI record from DB.'));
    return $result;
  }

  /**
   * {@inheritDoc}
   */
  public function updateDbRecord(string $state, ?int $did = NULL) {
    if (!$did) {
      $this->messenger->addError($this->t('There is no did.'));
      return NULL;
    }
    $this->messenger->addStatus($this->t('Updated DOI record from DB.'));
    return $this->connection->update('wisski_doi')
      ->fields([
        'state' => $state,
      ])->condition('did', $did)->execute();
  }

  /**
   * {@inheritDoc}
   */
  public function readBundleRecords(string $bundle_id) {
    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $individualsPerBundle = [];
    // Query all individuals.
    $wisskiIndividualQuery = \Drupal::entityQuery('wisski_individual')
      ->condition('bundle', [$bundle_id]);
    $wisskiIndividualResults = $wisskiIndividualQuery->execute();
    foreach ($wisskiIndividualResults as $result => $eid) {
      $title = wisski_core_generate_title($eid);
      $entityLink = \Drupal::request()->getSchemeAndHttpHost() . '/wisski/navigate/' . $eid . '/doi';
      $individualsPerBundle[$eid] = [
        'eid' => $eid,
        'label' => $title[$language][0]['value'],
        'link' => ['data' => $this->t('<a href=":entityLink" class="wisski-entity-link">:entityLink</a>', [':entityLink' => $entityLink])],
      ];
    }
    return $individualsPerBundle;
  }

}
