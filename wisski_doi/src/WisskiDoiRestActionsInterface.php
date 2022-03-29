<?php

namespace Drupal\wisski_doi;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Handles the communication with DOI REST API.
 *
 * Contains function to create, read, and delete DOIs.
 */
interface WisskiDoiRestActionsInterface {

  /**
   * Construct instance with DOI settings and check them.
   *
   * Create a GuzzleClient locally (may a service injection is better?)
   * Take settings from wisski_doi_settings form
   * (Configuration->[WISSKI]->WissKI DOI Settings)
   * Checks if settings are missing.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The REST request service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config storage service.
   */
  public function __construct(TranslationInterface $stringTranslation,
                              MessengerInterface $messenger,
                              ClientInterface $httpClient,
                              ConfigFactoryInterface $configFactory);

  /**
   * Receive DOIs from repo or update existing.
   *
   * @param array $doiInfo
   *   The DOI Schema for the provider.
   * @param bool $update
   *   True, if it is a update.
   *
   * @return array
   *   Data to write to DB.
   *   Contains dbData:
   *     eid: The entity ID as eid.
   *     doi: DOI string with prefix and suffix.
   *     vid: The revision ID as vid.
   *     state: The state of the DOI (draft, registered, findable).
   *     revisionUrl: Full external URL of the revision.
   *   and responseStatus with responseCode.
   *
   * @throws \GuzzleHttp\Exception\RequestException
   *   Throws exception when response status 40x.
   */
  public function createOrUpdateDoi(array $doiInfo, bool $update = FALSE);

  /**
   * Read the metadata from DOI provider.
   *
   * @param string $doi
   *   The DOI, like 10.82102/rhwt-d19.
   *
   * @return string
   *   The response status code.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function readMetadata(string $doi);

  /**
   * Delete DOI from provider DB.
   *
   * @param string $doi
   *   The DOI.
   *
   * @return string
   *   The response status code.
   *
   * @throws \GuzzleHttp\Exception\RequestException
   */
  public function deleteDoi(string $doi);

  /**
   * Provide some readable information of errors.
   *
   * @param \GuzzleHttp\Exception\RequestException $error
   *   The GuzzleHttp error response.
   *
   * @return string
   *   Error status code.
   */
  public function errorResponse(RequestException $error);

}
