<?php

namespace Drupal\wisski_core\Controller;

use Drupal\Core\Entity\Controller\EntityViewController;
use Drupal\Core\Link;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wisski_core\WisskiBundleInterface;
use Drupal\wisski_core\WisskiEntityInterface;
use Drupal\Core\Url;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\wisski_core\WisskiStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Basic Controller for WissKI entities and individuals.
 */
class WisskiEntityController extends ControllerBase {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * Constructs a NodeController object.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(DateFormatterInterface $date_formatter, RendererInterface $renderer, EntityRepositoryInterface $entity_repository) {
    $this->dateFormatter = $date_formatter;
    $this->renderer = $renderer;
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter'),
      $container->get('renderer'),
      $container->get('entity.repository')
    );
  }

  /**
   * This is a dummy function.
   */
  public function content() {
    $form = [];
    $form[] = [
      '#type' => 'markup',
      '#markup' => t('Hello World!'),
    ];
    $form[] = [
      '#type' => 'textfield',
      '#default_value' => 'murks',
    ];
    return $form;
  }

  /**
   * Add WissKI bundle form.
   */
  public function add(WisskiBundleInterface $wisski_bundle) {
    // dpm(microtime(), "before");.
    $entity = \Drupal::service('entity_type.manager')
      ->getStorage('wisski_individual')
      ->create([
        'bundle' => $wisski_bundle->id(),
      ]);
    // dpm(microtime(), "in");.
    // dpm(microtime(), "after");.
    return $this->entityFormBuilder()->getForm($entity);
  }

  /**
   * Displays a node revision.
   *
   * @param int $wisski_individual_revision
   *   The node revision ID.
   *
   * @return array
   *   An array suitable for \Drupal\Core\Render\RendererInterface::render().
   *
   * @throws \Exception
   */
  public function revisionShow(int $wisski_individual_revision) {
    $wisski_individual = $this->entityTypeManager()
      ->getStorage('wisski_individual')
      ->loadRevision($wisski_individual_revision);
    $wisski_individual = $this->entityRepository->getTranslationFromContext($wisski_individual);

    // dpm(serialize($wisski_individual), "wki_ind");.
    $wisski_individual_view_controller = new EntityViewController(\Drupal::service('entity_type.manager'), \Drupal::service('renderer'));
    $page = $wisski_individual_view_controller->view($wisski_individual);
    unset($page['nodes'][$wisski_individual->id()]['#cache']);
    return $page;
  }

  /**
   * Page title callback for a node revision.
   *
   * @param int $wisski_individual_revision
   *   The node revision ID.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   *
   * @throws \Exception
   */
  public function revisionPageTitle(int $wisski_individual_revision) {
    $wisski_individual_untrans = $this->entityTypeManager()
      ->getStorage('wisski_individual')
      ->loadRevision($wisski_individual_revision);
    $wisski_individual = $this->entityRepository->getTranslationFromContext($wisski_individual_untrans);

    /*
     * as a title we don't take the old one, as it lacks behind by one revision
     * and the user knows the current title anyway and not the old one.
     * so we take the current one from the title cache.
     */
    $title = wisski_core_generate_title($wisski_individual->id());
    // dpm(serialize($title), "title?");
    // dpm(serialize($wisski_individual->language()->getId()), "ind?");.
    $the_revision_langcode = $wisski_individual->language()->getId();

    $new_title = $wisski_individual->label();

    if (isset($the_revision_langcode) && isset($title[$the_revision_langcode])) {
      $new_title = $title[$the_revision_langcode];
      $new_title = $new_title[0]["value"];
      if (empty(trim($new_title))) {
        $new_title = $wisski_individual->label();
      }
    }

    // dpm(serialize($wisski_individual->getRevisionCreationTime()), "yay?");
    // dpm(serialize($wisski_individual), "yay?");.
    /*
     * The $wisski_individual->label() always is behind by one -
     * I don't really know why this is happening.
     */
    $creationtime = $wisski_individual->getRevisionCreationTime();
    if (!empty($creationtime)) {
      $date = $this->dateFormatter->format($wisski_individual->getRevisionCreationTime());
    }
    else {
      $date = "somewhen";
    }
    return $this->t('Revision of %title from %date',
      [
        '%title' => $new_title,
        '%date' => $date,
      ]);
  }

  /**
   * Generates an overview table of older revisions of a node.
   *
   * @param \Drupal\wisski_core\WisskiEntityInterface $wisski_individual
   *   A node object.
   *
   * @return array
   *   An array as expected by \Drupal\Core\Render\RendererInterface::render().
   *
   * @throws \Exception
   */
  public function revisionOverview(WisskiEntityInterface $wisski_individual) {

    // dpm(serialize($wisski_individual), "ind?");
    // dpm("yay?");
    // return array();
    $account = $this->currentUser();
    // dpm($wisski_individual->language(), "lang?");
    // $langcode = "und";
    // $langname = "unknown";.
    $langcode = $wisski_individual->language()->getId();
    $langname = $wisski_individual->language()->getName();
    $languages = $wisski_individual->getTranslationLanguages();
    // dpm($langcode);
    $has_translations = (count($languages) > 1);
    $wisski_individual_storage = $this->entityTypeManager()
      ->getStorage('wisski_individual');
    // $type = $wisski_individual->getType();
    $type = "wisski_individual";

    $build['#title'] = $has_translations ? $this->t('@langname revisions for %title', [
      '@langname' => $langname,
      '%title' => $wisski_individual->label(),
    ]) : $this->t('Revisions for %title',
      ['%title' => $wisski_individual->label()]
    );

    $header = [$this->t('Revision'), $this->t('Operations')];
    $revert_permission = (($account->hasPermission("revert $type revisions") || $account->hasPermission('revert all revisions') || $account->hasPermission('administer nodes')) && $wisski_individual->access('update'));
    $delete_permission = (($account->hasPermission("delete $type revisions") || $account->hasPermission('delete all revisions') || $account->hasPermission('administer nodes')) && $wisski_individual->access('delete'));

    $rows = [];
    $default_revision = $wisski_individual->getRevisionId();
    $current_revision_displayed = FALSE;
    $dateFormatter = \Drupal::service('date.formatter');
    $renderer = \Drupal::service('renderer');
    // Return array();
    // dpm(serialize(
    // dpm("yay?");.
    foreach ($this->getRevisionIds($wisski_individual, $wisski_individual_storage) as $vid) {
      /** @var \Drupal\node\NodeInterface $revision */
      // dpm("yay2?");.
      // dpm(serialize($wisski_individual), "ind?");
      // dpm(serialize($vid), "vid?");
      // dpm(serialize($wisski_individual_storage), "stor?");
      // return array();
      $revision = $wisski_individual_storage->loadRevision($vid);
      // dpm(serialize($revision), "rev?");
      // return array();
      /*
       * Only show revisions that are affected by the language that is being
       * displayed.
       */
      if ($revision->hasTranslation($langcode) && $revision->getTranslation($langcode)
        ->isRevisionTranslationAffected()) {
        $username = [
          '#theme' => 'username',
          '#account' => $revision->getRevisionUser(),
        ];

        $ts = $revision->revision_timestamp->value;
        // Use revision link to link to revisions that are not active.
        if (!empty($ts)) {
          $date = $dateFormatter->format($revision->revision_timestamp->value, 'short');
        }
        else {
          $date = "Somewhen before";
        }

        // We treat also the latest translation-affecting revision as current
        // revision, if it was the default revision, as its values for the
        // current language will be the same of the current default revision in
        // this case.
        $is_current_revision = $vid == $default_revision || (!$current_revision_displayed && $revision->wasDefaultRevision());
        if (!$is_current_revision) {
          $link = Link::fromTextAndUrl($date, new Url('entity.wisski_individual.revision', [
            'wisski_individual' => $wisski_individual->id(),
            'wisski_individual_revision' => $vid,
          ]))->toString();
        }
        else {
          $link = $wisski_individual->toLink($date)->toString();
          $current_revision_displayed = TRUE;
        }

        $row = [];
        $column = [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
            '#context' => [
              'date' => $link,
              'username' => $renderer->renderPlain($username),
              'message' => [
                '#markup' => $revision->revision_log->value,
                '#allowed_tags' => Xss::getHtmlTagList(),
              ],
            ],
          ],
        ];
        // @todo Simplify once https://www.drupal.org/node/2334319 lands.
        $renderer->addCacheableDependency($column['data'], $username);
        $row[] = $column;

        if ($is_current_revision) {
          /*
           * Operations Column in revision table if current revision.
           */
          $row[] = [
            'data' => [
              '#prefix' => '<em>',
              '#markup' => $this->t('Current revision'),
              '#suffix' => '</em>',
            ],
            'class' => ['revision-current'],
          ];
        }
        else {
          $links = [];
          if ($revert_permission) {
            $links['revert'] = [
              'title' => $vid < $wisski_individual->getRevisionId() ? $this->t('Revert') : $this->t('Set as current revision'),
              'url' => $has_translations ?
              Url::fromRoute('wisski_individual.revision_revert_translation_confirm', [
                'wisski_individual' => $wisski_individual->id(),
                'wisski_individual_revision' => $vid,
                'langcode' => $langcode,
              ]) :
              Url::fromRoute('wisski_individual.revision_revert_confirm', [
                'wisski_individual' => $wisski_individual->id(),
                'wisski_individual_revision' => $vid,
              ]),
            ];
          }

          if ($delete_permission) {
            $links['delete'] = [
              'title' => $this->t('Delete'),
              'url' => Url::fromRoute('wisski_individual.revision_delete_confirm', [
                'wisski_individual' => $wisski_individual->id(),
                'wisski_individual_revision' => $vid,
              ]),
            ];
          }

          /*
           * Operations column if multiple revisions.
           */
          $row[] = [
            'data' => [
              '#type' => 'operations',
              '#links' => $links,
            ],
          ];
        }
        // dpm($row);
        $rows[] = $row;
      }
    }

    $build['node_revisions_table'] = [
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
      '#attached' => [
        'library' => ['node/drupal.node.admin'],
      ],
      '#attributes' => ['class' => 'node-revision-table'],
    ];

    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }

  /**
   * The _title_callback for the node.add route.
   *
   * @param \Drupal\node\NodeTypeInterface wisski_individual_type
   *   The current node.
   *
   * @return string
   *   The page title.
   */
  // Public function addPageTitle(wisski_individual_type) {
  // return $this->t('Create @name', [
  // '@name' => wisski_individual_type->label()]);.
  // }

  /**
   * Gets a list of node revision IDs for a specific node.
   *
   * @param \Drupal\wisski_core\WisskiEntityInterface $wisski_individual
   *   The WissKI Individual.
   * @param \Drupal\wisski_core\WisskiStorageInterface $wisski_individual_storage
   *   The WissKI Individual Storage.
   *
   * @return int[]
   *   Node revision IDs (in descending order).
   */
  protected function getRevisionIds(WisskiEntityInterface $wisski_individual, WisskiStorageInterface $wisski_individual_storage) {
    // dpm(serialize($wisski_individual->id()), "id?");
    // dpm(serialize($wisski_individual->getEntityType()
    // ->getKey('id')), "idkey?");
    // dpm(serialize($wisski_individual->getEntityType()
    // ->getKey('revision')), "revkey?");.
    $database = $wisski_individual_storage->getDatabase();
    $revtab = $wisski_individual_storage->getRevisionTable();
    $query = $database
      ->select($revtab, 'base')
      ->fields('base', [
        $wisski_individual->getEntityType()
          ->getKey('revision'),
      ])
      ->condition('base.' . $wisski_individual->getEntityType()
        ->getKey('id'), $wisski_individual->id())
      ->orderBy('base.' . $wisski_individual->getEntityType()
        ->getKey('revision'), 'DESC');
    // dpm(serialize($query), "query?");.
    $result = $query->execute();

    $out = [];

    $vid = $wisski_individual->getEntityType()->getKey('revision');

    while ($value = $result->fetchAssoc()) {
      $out[] = $value[$vid];
    }

    // dpm(serialize($out), "res?");
    // dpm(serialize($revtab), "rev?");
    // $database->select();
    // dpm(serialize($parentQuery), "parent");
    // $query = $parentQuery
    // ->allRevisions()
    // ->condition($wisski_individual->getEntityType()
    // ->getKey('id'), $wisski_individual->id())
    // ->sort($wisski_individual->getEntityType()->getKey('revision'), 'DESC')
    // ->pager(50);
    // dpm("here");
    // dpm(serialize($query), "query?");
    // $result = $query->execute();
    // dpm(serialize($result), "res?");.
    return $out;
  }

}
