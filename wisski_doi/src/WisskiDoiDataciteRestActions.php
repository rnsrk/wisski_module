<?php

namespace Drupal\wisski_doi;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\wisski_doi\Exception\WisskiDoiSettingsNotFoundException;
use Drupal\wisski_doi\Form\WisskiDoiRepositorySettings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Handles the communication with DataCite DOI REST API.
 *
 * Contains function to create, read, update and delete DOIs
 * with the REST API of Datacite.
 */
class WisskiDoiDataciteRestActions implements WisskiDoiRestActionsInterface {
  use StringTranslationTrait;

  /**
   * The translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected $stringTranslation;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  private MessengerInterface $messenger;

  /**
   * Guzzle\Client instance.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The config service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Settings from DOI Configuration page.
   *
   * @var array
   */

  private array $doiSettings;

  /**
   * {@inheritDoc}
   */
  public function __construct(TranslationInterface $stringTranslation, MessengerInterface $messenger, ClientInterface $httpClient, ConfigFactoryInterface $configFactory) {
    $this->stringTranslation = $stringTranslation;
    $this->messenger = $messenger;
    $this->httpClient = $httpClient;
    $settings = $configFactory
      ->getEditable(WisskiDoiRepositorySettings::DOI_SETTINGS);

    $this->doiSettings = [
      "baseUri" => $settings->get('doiSettings.doi_base_uri'),
      "doiRepositoryId" => $settings->get('doiSettings.doi_repository_id'),
      "doiSchemaVersion" => $settings->get('doiSettings.doi_schema_version'),
      "doiPrefix" => $settings->get('doiSettings.doi_prefix'),
      "doiShoulder" => $settings->get('doiSettings.doi_shoulder'),
      "doiShoulderSuffixDelimiter" => $settings->get('doiSettings.doi_shoulder_suffix_delimiter'),
      "doiRepositoryPassword" => $settings->get('doiSettings.doi_repository_password'),
    ];
    try {
      (new WisskiDoiSettingsNotFoundException)->checkDoiSetting($this->doiSettings);
    }
    catch (WisskiDoiSettingsNotFoundException $error) {
      $this->messenger->addError($error->getMessage());
    }

  }

  /**
   * {@inheritDoc}
   */
  public function createOrUpdateDoi(array $doiInfo, bool $update = FALSE) {

    // Create a new DOI ID and check if the ID is already taken.
    $response = FALSE;
    $counter = 0;
    while ($response != '404') {
      $prefix = $this->doiSettings['doiPrefix'] . '/';
      $shoulder = empty($this->doiSettings['doiShoulder']) ?: $this->doiSettings['doiShoulder'] . $this->doiSettings['doiShoulderSuffixDelimiter'];
      $suffix = uniqid();
      $doi = $prefix . $shoulder . $suffix;
      $response = $this->readMetadata($doi);
      $counter++;
      if ($counter == 4) {
        return [
          'dbDate' => NULL,
          'responseStatus' => 'Can not create unique suffix, please try again',
        ];
      }
    }
    // Escape if there is no doi.
    if (!isset($doi)) {
      return [
        'dbDate' => NULL,
        'responseStatus' => 'Could not create DOI ID. Maybe there are missing DOI settings.',
      ];
    }

    // Future request body as array.
    $body = [
      "data" => [
        "attributes" => [
          "doi" => $doi,
          "event" => $doiInfo['event'],
          "creators" => [
            [
              "name" => $doiInfo['author'],
            ],
          ],
          "contributors" => $doiInfo['contributors'],
          "titles" => [
            [
              "title" => $doiInfo['title'],
            ],
          ],
          "dates" => [
            [
              "dateType" => 'Created',
              "dateInformation" => $doiInfo['creationDate'],
            ],
          ],
          "publisher" => $doiInfo['publisher'],
          "publicationYear" => substr($doiInfo['creationDate'], 6, 4),
          "language" => $doiInfo['language'],
          "types" => [
            "resourceTypeGeneral" => "Dataset",
          ],
          "url" => $doiInfo['revisionUrl'],
          "schemaVersion" => $this->doiSettings['doiSchemaVersion'],
        ],
      ],
    ];
    // Encode to json.
    $json_body = json_encode($body);
    // dpm(base64_encode($this->doiRepositoryId.":".$this->doiRepositoryPassword));.
    try {
      if ($update) {
        // If it is an update, use PUT method.
        $method = 'PUT';
        $uri = $this->doiSettings['baseUri'] . '/' . $doiInfo['doi'];
      }
      else {
        // Else POST.
        $method = 'POST';
        $uri = $this->doiSettings['baseUri'];
      }
      // Sending request.
      $response = $this->httpClient->request($method, $uri, [
        'body' => $json_body,
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($this->doiSettings['doiRepositoryId'] . ":" . $this->doiSettings['doiRepositoryPassword']),
          'Content-Type' => 'application/vnd.api+json',
        ],
        // Error handling on.
        'http_errors' => TRUE,
      ]);
      // Decode response to array.
      $responseContent = json_decode($response->getBody()->getContents(), TRUE);
      // Messaging.
      $action = $update ? 'updated' : 'requested';
      $this->messenger->addStatus($this->t('DOI has been %action', ['%action' => $action]));
      return [
        'dbData' => [
          "doi" => $responseContent['data']['id'],
          "vid" => $doiInfo['revisionId'] ?? NULL,
          "eid" => $doiInfo['entityId'],
          "state" => $responseContent['data']['attributes']['state'],
          "revisionUrl" => $doiInfo['revisionUrl'],
          "created" => $responseContent['data']['attributes']['created'],
        ],
        'responseStatus' => $response->getStatusCode(),
      ];
    }
    /* Try to catch the GuzzleException. This indicates a failed
     * response from the remote API.
     */
    catch (RequestException $error) {
      \Drupal::logger('wisski_doi')
        ->error($this->t('Request error: @error', ['@error' => $error->getMessage()]));
      // $errorCode = $this->errorResponse($error)->getStatusCode() ?? '500';
      return [
        'dbDate' => NULL,
        'responseStatus' => $this->errorResponse($error),
      ];
    }

    // A non-Guzzle error occurred. The type of exception
    // is unknown, so a generic log item is created.
    catch (\Exception $error) {
      // Log the error.
      \Drupal::logger('wisski_doi')
        ->error($this->t('An unknown error occurred while trying to connect to the remote API. This is not a Guzzle error, nor an error in the remote API, rather a generic local error occurred. The reported error was @error', ['@error' => $error->getMessage()]));
      return [
        'dbDate' => NULL,
        'responseStatus' => $this->errorResponse($error),
      ];
    }
    catch (GuzzleException $error) {
    }
    // Log the error.
    \Drupal::logger('wisski_doi')
      ->error($this->t('An unknown error occurred while trying to connect to the remote API. This is not a Guzzle error, nor an error in the remote API, rather a generic local error occurred. The reported error was @error', ['@error' => $error->getMessage()]));
    return [
      'dbDate' => NULL,
      'responseStatus' => $this->errorResponse($error),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function readMetadata(string $doi) {
    try {
      $url = $this->doiSettings['baseUri'] . '/' . $doi;
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Accept' => 'application/vnd.api+json',
        ],
      ]);

      $this->messenger->addStatus($this->t('Reached DOI provider, got data.'));
      return json_decode($response->getBody()->getContents(), TRUE);
    }
    /* Try to catch the GuzzleException. This indicates a failed
     * response from the remote API.
     */
    catch (RequestException $error) {
      return '404';
    }
  }

  /**
   * {@inheritDoc}
   */
  public function deleteDoi(string $doi) {
    try {
      $url = $this->doiSettings['baseUri'] . '/' . $doi;

      $response = $this->httpClient->request('DELETE', $url, [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($this->doiSettings['doiRepositoryId'] . ":" . $this->doiSettings['doiRepositoryPassword']),
        ],
      ]);

      $this->messenger->addStatus($this->t('Deleted DOI from provider.'));
      return $response->getStatusCode();
    }
    /* Try to catch the GuzzleException. This indicates a failed
     * response from the remote API.
     */
    catch (RequestException $error) {
      return $this->errorResponse($error);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function errorResponse(RequestException $error) {
    // Get the original response.
    $response = $error->getResponse();
    // Get the info returned from the remote server.
    $error_content = empty($response) ? ['errors' => [['status' => "500"]]] : json_decode($response->getBody()
      ->getContents(), TRUE);
    /*
     * Match only works in PHP 8
     * $error_tip = match ($error_content['errors'][0]['status']) {
     * "400" => 'Your used doi scheme or content data may be faulty.',
     *  default => 'Sorry, no tip for this error code.',
     * };
     */
    if (!isset($error_content['errors'][0]['status'])) {
      $error_content['errors'][0]['status'] = '500';
      $error_tip = 'Sorry, no tip for this error code.';
    }
    else {
      switch ($error_content['errors'][0]['status']) {
        case "400":
          $error_tip = 'Provider can not read your request. Your content data or scheme may be faulty.';
          break;

        case "401":
          $error_tip = 'Check your username and password.';
          break;

        case "403":
          $error_tip = 'Have you the full permissions to delete something? Check the Username!';
          break;

        case "404":
          $error_tip = 'Seems you have a typo in your DOI credentials,
          watch out for leading or trailing whitespaces.';
          break;

        case "405":
          $error_tip = 'Are you trying to delete a registered or findable DOI?';
          break;

        case "422":
          $error_tip = 'Your JSON values are not accepted, check your schema version!';
          break;

        case "500":
          $error_tip = 'There was no response at all, have you defined the base uri? Or maybe it is a timeout';
          break;

        default:
          $error_tip = 'Sorry, no tip for this error code.';
      }
    }

    // Error Code and Message.
    $message = $this->t('API connection error. Error code: %error_code. Error message: %error_message %error_tip', [
      '%error_code' => $error_content['errors'][0]['status'],
      '%error_message' => $error_content['errors'][0]['title'] ?? 'no message',
      '%error_tip' => $error_tip,
    ]);
    $this->messenger->addError($message);
    // Log the error.
    \Drupal::logger('wisski_doi')->error($message);
    return $error_content['errors'][0]['status'];
  }

}
