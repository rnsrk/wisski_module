<?php

namespace Drupal\wisski_core\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for deleting a node revision.
 *
 * @internal
 */
class WisskiIndividualRevisionDeleteForm extends ConfirmFormBase {

  /**
   * The wisski revision.
   *
   * @var \Drupal\wisski_core\WisskiEntityInterface
   */
  protected $revision;

  /**
   * The wisski storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $wisskiStorage;

  /**
   * The wisski type storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $wisskiTypeStorage;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a new NodeRevisionDeleteForm.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $node_storage
   *   The node storage.
   * @param \Drupal\Core\Entity\EntityStorageInterface $node_type_storage
   *   The node type storage.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(EntityStorageInterface $wisski_storage, Connection $connection, DateFormatterInterface $date_formatter) {
    $this->wisskiStorage = $wisski_storage;
//    $this->nodeTypeStorage = $node_type_storage;
    $this->connection = $connection;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $entity_type_manager = $container->get('entity_type.manager');
    return new static(
      $entity_type_manager->getStorage('wisski_individual'),
#      $entity_type_manager->getStorage(''),
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wisski_individual_revision_delete_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Are you sure you want to delete the revision from %revision-date?', [
      '%revision-date' => $this->dateFormatter->format($this->revision->getRevisionCreationTime()),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.wisski_individual.version_history', ['wisski_individual' => $this->revision->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $wisski_individual_revision = NULL) {
    $this->revision = $this->wisskiStorage->loadRevision($wisski_individual_revision);
    $form = parent::buildForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->wisskiStorage->deleteRevision($this->revision->getRevisionId());

    $this->logger('content')->notice('@type: deleted %title revision %revision.', ['@type' => $this->revision->bundle(), '%title' => $this->revision->label(), '%revision' => $this->revision->getRevisionId()]);
#    $wisski_individual_type = $this->wisski_individualTypeStorage->load($this->revision->bundle())->label();
    $this->messenger()
      ->addStatus($this->t('Revision from %revision-date of @type %title has been deleted.', [
        '%revision-date' => $this->dateFormatter->format($this->revision->getRevisionCreationTime()),
        '@type' => $this->revision->bundle(),
        '%title' => $this->revision->label(),
      ]));
    $form_state->setRedirect(
      'entity.wisski_individual.canonical',
      ['wisski_individual' => $this->revision->id()]
    );
    if ($this->connection->query('SELECT COUNT(DISTINCT vid) FROM {wisski_data_revision} WHERE eid = :nid', [':nid' => $this->revision->id()])->fetchField() > 1) {
      $form_state->setRedirect(
        'entity.wisski_individual.version_history',
        ['wisski_individual' => $this->revision->id()]
      );
    }
  }

}
