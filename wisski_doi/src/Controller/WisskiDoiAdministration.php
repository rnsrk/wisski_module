<?php

namespace Drupal\wisski_doi\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\wisski_doi\WisskiDoiDbActions;
use Drupal\wisski_doi\WisskiDoiDbActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller to render DOI Administration.
 */
class WisskiDoiAdministration extends ControllerBase {

  /**
   * The service to interact with the database.
   *
   * @var \Drupal\wisski_doi\WisskiDoiDbActionsInterface
   */
  private WisskiDoiDbActionsInterface $wisskiDOiDbActions;

  /**
   * Construct the WisskiDoiAdministration class.
   */
  public function __construct(WisskiDoiDbActionsInterface $wisskiDOiDbActions) {
    $this->wisskiDOiDbActions = $wisskiDOiDbActions;
  }

  /**
   * Get the services from the container.
   */
  public static function create(ContainerInterface $container) {
    $wisskiDOiDbActions = $container->get('wisski_doi.wisski_doi_db_actions');

    return new static($wisskiDOiDbActions);
  }

  /**
   * Returns a render-able array for the DOI administration page.
   *
   * @param string $wisski_individual
   *   The wisski_individual (it's an integer actually)
   *
   * @return array
   *   The render array of the connected DOIs from the DB table
   *   wisski_doi as a table.
   */
  public function overview(string $wisski_individual) {
    $wisski_individual = intval($wisski_individual);
    // Read data from wisski_doi.
    $dbRows = $this->wisskiDOiDbActions->readDoiRecords($wisski_individual) ?? NULL;

    if ($dbRows) {
      // Populate raw database values to more readable thinks.
      $rows = array_map(function ($row) use ($wisski_individual) {
        return $this->rowBuilder($row, $wisski_individual);
      }, $dbRows);
      // Build table.
      $build['table'] = [
        '#type' => 'table',
        '#header' => ['ID',
          'DOI',
          'State',
          'RevisionURL',
          'State',
          'Created',
          'Operations',
        ],
        '#rows' => $rows,
        '#description' => $this->t('DOI information'),
        '#weight' => 1,
        '#cache' => ['max-age' => 0],
      ];
    }
    else {
      // Found no DOIs in database table wisski_doi.
      $build = [
        '#markup' => '<p>' . $this->t('No DOIs associated with the entity.') . '</p>',
        '#cache' => ['max-age' => 0],
      ];
    }
    return $build;
  }

  /**
   * Transform raw db data in links and meaningful terms.
   *
   * @param array $row
   *   The array item from readDoiRecords().
   * @param string $wisski_individual
   *   The wisski_individual (it's an integer actually)
   */
  public function rowBuilder(array $row, string $wisski_individual) {
    // Assemble DOI Link.
    //$row['created'] = (new DateTime($row['created']))->format('Y-m-d H:i:s');
    $doiLink = 'https://doi.org/' . $row['doi'];
    $row['doi'] = ['data' => $this->t('<a href=":doiLink" class="wisski-doi-link">:doiLink</a>', [':doiLink' => $doiLink])];
    // Revision Link.
    $row['revisionUrl'] = ['data' => $this->t('<a href=":revisionLink">:revisionLink</a>', [':revisionLink' => $row['revisionUrl']])];

    // IsCurrent column.
    $row['isCurrent'] ? $row['isCurrent'] = 'current' : $row['isCurrent'] = 'static';

    // Populate Options Menu.
    $links = [];
    $links['edit'] = [
      'title' => $this->t('Edit'),
      'url' => Url::fromRoute('wisski_individual.doi.edit_metadata', [
        'did' => $row['did'],
        'wisski_individual' => $wisski_individual,
      ]),
    ];
    if ($row['state'] == 'draft') {
      $links['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('wisski_individual.doi.delete', [
          'did' => $row['did'],
          'wisski_individual' => $wisski_individual,
        ]),
      ];
    }

    // Operations column.
    $row['Operations'] =
      [
        'data' => [
          '#type' => 'operations',
          '#links' => $links,
        ],
      ];

    // Remove unnecessary columns.
    unset($row['eid']);
    unset($row['vid']);
    return $row;
  }

}
