<?php

namespace Drupal\wisski_pathbuilder\Form;

use Drupal\file\FileRepositoryInterface;
use Drupal\wisski_salz\Entity\Adapter;
use Drupal\Core\File\FileSystemInterface;
use Drupal\wisski_pathbuilder\Entity\WisskiPathEntity;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Url;

use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity as Pathbuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class WisskiPathbuilderForm.
 *
 * Fom class for adding/editing WisskiPathbuilder config entities.
 */
class WisskiPathbuilderForm extends EntityForm {

  public $with_solr;

  /**
   * The FileRepositoryInterface service instance.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected FileRepositoryInterface $file;

  /**
   * Create service container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file.repository')
    );
  }

  /**
   * Constructs form variables.
   *
   * @param \Drupal\file\FileRepositoryInterface $file
   *   Performs file system operations and updates database records accordingly.
   */
  public function __construct(FileRepositoryInterface $file) {
    $this->file = $file;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    // What entity do we work on?
    $pathbuilder = $this->entity;

    // Is solr enabled?
    $moduleHandler = \Drupal::service('module_handler');
    if ($moduleHandler->moduleExists('search_api_solr')) {
      $this->with_solr = TRUE;
    }

    // dpm($pathbuilder->getEntityType());
    // Change page title for the edit operation.
    if ($this->operation == 'edit') {
      $form['#title'] = $this->t('Edit Pathbuilder: @id', ['@id' => $pathbuilder->id()]);
    }

    // Only show this in create mode.
    if ($this->operation == 'add') {
      $form['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#default_value' => $pathbuilder->getName(),
        '#description' => $this->t("Name of the Pathbuilder-Tree."),
        '#required' => TRUE,
      ];

      // We need an id.
      $form['id'] = [
        '#type' => 'machine_name',
        '#default_value' => $pathbuilder->id(),
        '#disabled' => !$pathbuilder->isNew(),
        '#machine_name' => [
          'source' => ['name'],
          'exists' => '\Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity::load',
        ],
      ];
    }

    // Load all adapters.
    $adapters = Adapter::loadMultiple();

    $adapterlist = [];

    // Generate a list of all adapters.
    foreach ($adapters as $adapter) {
      // drupal_set_message(serialize($adapters));
      $adapterlist[$adapter->id()] = $adapter->label();
    }

    $ns = NULL;
    if (!empty($pathbuilder->getAdapterId())) {
      $adapter = Adapter::load($pathbuilder->getAdapterId());
      $engine = $adapter->getEngine();
      $ns = $engine->getNamespaces();
    }

    // If we are in edit mode, the options are below so the table
    // is set more directly at the top. Furthermore in the create mode
    // the table is unnecessary.
    if ($this->operation == 'edit') {
      if ($this->with_solr) {
        $header = [
          $this->t("Title"),
          $this->t("Path"),
          $this->t("Solr"),
          ['data' => $this->t("Enabled"), 'class' => ['checkbox']],
          $this->t('Field&nbsp;Type'),
          $this->t('Cardinality'),
          "Weight",
          ['data' => $this->t('Operations'), 'colspan' => 11],
        ];
      }
      else {
        $header = [
          $this->t("Title"),
          $this->t("Path"),
          ['data' => $this->t("Enabled"), 'class' => ['checkbox']],
          $this->t('Field&nbsp;Type'),
          $this->t('Cardinality'),
          "Weight",
          ['data' => $this->t('Operations'), 'colspan' => 11],
        ];
      }

      $form['pathbuilder_table'] = [
        '#type' => 'table',
      // '#theme' => 'table__menu_overview',
        '#header' => $header,
      // '#rows' => $rows,
        '#attributes' => [
          'id' => 'wisski_pathbuilder_' . $pathbuilder->id(),
        ],

        '#attached' => [
          'library' => [
            'wisski_pathbuilder/wisski_pathbuilder_collapse',
          ],
        ],

        '#tabledrag' => [

          [
            'action' => 'match',
            'relationship' => 'parent',
            'group' => 'menu-parent',
            'subgroup' => 'menu-parent',
            'source' => 'menu-id',
            'hidden' => TRUE,
            'limit' => 9,
          ],
          [
            'action' => 'order',
            'relationship' => 'sibling',
            'group' => 'menu-weight',
          ],
        ],

      ];

      $pathforms = [];

      // Get all paths belonging to the respective pathbuilder.
      foreach ($pathbuilder->getPathTree() as $grouparray) {

        $pathforms = array_merge($pathforms, $this->recursive_render_tree($grouparray, 0, 0, 0, $ns));
      }

      $pbpaths = $pathbuilder->getPbPaths();

      // Iterate through all the pathforms and bring the forms in a tree together.
      foreach ($pathforms as $pathform) {

        $path = $pathform['#item'];

        if (empty($path)) {
          $this->messenger()->addError("There is an empty Path in " . serialize($pathform));
          continue;
        }

        if (!(empty($path->getDatatypeProperty()) || $path->getDatatypeProperty() == "empty") && ($pbpaths[$path->id()]['fieldtype'] == "entity_reference" || $path->isGroup())) {
          $this->messenger()->addError("Danger Zone: Path '" . $path->label() . "' has field type 'entity reference' but uses " . $path->getDatatypeProperty() . " as datatype property. Please remove the datatype property.");
        }

        $form['pathbuilder_table'][$path->id()]['#item'] = $pathform['#item'];

        // TableDrag: Mark the table row as draggable.
        $form['pathbuilder_table'][$path->id()]['#attributes'] = $pathform['#attributes'];
        $form['pathbuilder_table'][$path->id()]['#attributes']['class'][] = 'draggable';

        // TableDrag: Sort the table row according to its existing/configured weight.
        $form['pathbuilder_table'][$path->id()]['#weight'] = $pbpaths[$path->id()]['weight'];

        // Add special classes to be used for tabledrag.js.
        $pathform['parent']['#attributes']['class'] = ['menu-parent'];
        $pathform['weight']['#attributes']['class'] = ['menu-weight'];
        $pathform['id']['#attributes']['class'] = ['menu-id'];

        $form['pathbuilder_table'][$path->id()]['title'] = [
          [
            '#theme' => 'indentation',
            '#size' => $pathform['#item']->depth,
          ],
          $pathform['title'],
        ];

        // $form['pathbuilder_table'][$path->id()]['path'] = array('#type' => 'label', '#title' => 'Mu -> ha -> ha');
        $form['pathbuilder_table'][$path->id()]['path'] = $pathform['path'];
        if ($this->with_solr) {
          $form['pathbuilder_table'][$path->id()]['solr'] = $pathform['solr'];
        }
        $form['pathbuilder_table'][$path->id()]['enabled'] = $pathform['enabled'];
        $form['pathbuilder_table'][$path->id()]['enabled']['#wrapper_attributes']['class'] = ['checkbox', 'menu-enabled'];

        $form['pathbuilder_table'][$path->id()]['field_type_informative'] = $pathform['field_type_informative'];
        $form['pathbuilder_table'][$path->id()]['cardinality'] = $pathform['cardinality'];

        $form['pathbuilder_table'][$path->id()]['weight'] = $pathform['weight'];

        // Get bundle id, sometimes fields do not have bundle value.
        $bundle_id = $pbpaths[$path->id()]['bundle'];
        $bundle_id_from_parent = isset($pbpaths[$pbpaths[$path->id()]['parent']]['bundle']) ? $pbpaths[$pbpaths[$path->id()]['parent']]['bundle'] : NULL;
        if (empty($bundle_id)) {
          $bundle_id = $bundle_id_from_parent;
        }

        $field_id = $pbpaths[$path->id()]['field'];

        // An array of links that can be selected in the dropdown operations list
        // edit path.
        $links = [];
        $links['edit'] = [
          'title' => $this->t('Edit'),
        // 'url' => $path->urlInfo('edit-form', array('wisski_pathbuilder'=>$pathbuilder->getID())),
          'url' => Url::fromRoute('entity.wisski_path.edit_form')
            ->setRouteParameters(['wisski_pathbuilder' => $pathbuilder->id(), 'wisski_path' => $path->id()]),
        ];

        // Edit field config in pathbuilder environment.
        $links['fieldconfig'] = [
          'title' => $this->t('Configure Field'),
        // 'url' => $path->urlInfo('edit-form', array('wisski_pathbuilder'=>$pathbuilder->id())),
          'url' => Url::fromRoute('entity.wisski_pathbuilder.configure_field_form')
            ->setRouteParameters(['wisski_pathbuilder' => $pathbuilder->id(), 'wisski_path' => $path->id()]),
        ];

        /*
        if(!empty($pbpaths[$path->id()]) && !empty($bundle_id)) {
        $links['formedit'] = array(
        'title' => $this->t('Manage Form Display for Bundle'),
        # 'url' => $path->urlInfo('edit-form', array('wisski_pathbuilder'=>$pathbuilder->id())),
        'url' => Url::fromRoute('entity.entity_form_display.wisski_individual.default')
        ->setRouteParameters(array('wisski_bundle' => $bundle_id)),
        );
         */

        /*
        $links['displayedit'] = array(
        'title' => $this->t('Manage Display for Bundle'),
        # 'url' => $path->urlInfo('edit-form', array('wisski_pathbuilder'=>$pathbuilder->id())),
        'url' => Url::fromRoute('entity.entity_view_display.wisski_individual.default')
        ->setRouteParameters(array('wisski_bundle' => $bundle_id)),
        );
        }
         */

        $links['delete_local'] = [
          'title' => $this->t('Delete path only from this pathbuilder'),
          'url' => Url::fromRoute('entity.wisski_path.delete_local_form')
            ->setRouteParameters(['wisski_pathbuilder' => $pathbuilder->id(), 'wisski_path' => $path->id()]),
        ];

        $links['delete'] = [
          'title' => $this->t('Delete path completely'),
          'url' => Url::fromRoute('entity.wisski_path.delete_form')
            ->setRouteParameters(['wisski_pathbuilder' => $pathbuilder->id(), 'wisski_path' => $path->id()]),
        ];

        $links['duplicate'] = [
          'title' => $this->t('Duplicate'),
          'url' => Url::fromRoute('entity.wisski_path.duplicate_form')
            ->setRouteParameters(['wisski_pathbuilder' => $pathbuilder->id(), 'wisski_path' => $path->id()]),
        ];

        if (!empty($bundle_id)) {
          // Go to bundle structure.
          if ($path->isGroup()) {
            $links['bundleedit'] = [
              'title' => $this->t('Shortcut to Structure'),
            // 'url' => $path->urlInfo('edit-form', array('wisski_pathbuilder'=>$pathbuilder->id())),
              'url' => Url::fromRoute('entity.wisski_bundle.edit_form')
                ->setRouteParameters(['wisski_bundle' => $bundle_id]),
            ];

            // Go to field structure.
          }
          else {
            $links['fieldsedit'] = [
              'title' => $this->t('Shortcut to Structure'),
            // 'url' => $path->urlInfo('edit-form', array('wisski_pathbuilder'=>$pathbuilder->id())),
              'url' => Url::fromRoute('entity.field_config.wisski_individual_field_edit_form')
                ->setRouteParameters(['wisski_bundle' => $bundle_id, 'field_config' => 'wisski_individual.' . $bundle_id . '.' . $field_id]),
            ];
          }
        }

        // Operations (dropbutton) column.
        // $operations = parent::getDefaultOperations($pathbuilder);
        $operations = [
          '#type' => 'operations',
          '#links' => $links,
        ];

        $form['pathbuilder_table'][$path->id()]['operations'] = $operations;

        $form['pathbuilder_table'][$path->id()]['id'] = $pathform['id'];

        $form['pathbuilder_table'][$path->id()]['parent'] = $pathform['parent'];

        // If the parent is not part of this pathbuilder, the path should be attached to top.
        if (empty($form['pathbuilder_table'][$pathform['parent']['#value']])) {
          $form['pathbuilder_table'][$path->id()]['parent']['#value'] = 0;
        }

        if (!empty($pathform['bundle'])) {
          $form['pathbuilder_table'][$path->id()]['bundle'] = $pathform['bundle'];
        }
        if (!empty($pathform['field'])) {
          $form['pathbuilder_table'][$path->id()]['field'] = $pathform['field'];
        }
        if (!empty($pathform['fieldtype'])) {
          $form['pathbuilder_table'][$path->id()]['fieldtype'] = $pathform['fieldtype'];
        }
        if (!empty($pathform['displaywidget'])) {
          $form['pathbuilder_table'][$path->id()]['displaywidget'] = $pathform['displaywidget'];
        }
        if (!empty($pathform['formatterwidget'])) {
          $form['pathbuilder_table'][$path->id()]['formatterwidget'] = $pathform['formatterwidget'];
        }
        // drupal_set_message(serialize($form['pathbuilder_table'][$path->id()]));
        // dpm($form['pathbuilder_table'][$path->id()],'Path '.$path->id());
      }
    }

    // Additional information stored in a field set below.
    $form['additional'] = [
      '#type' => 'fieldset',
      '#tree' => FALSE,
      '#title' => $this->t('Additional Settings'),
    ];

    // Only show this in edit mode.
    if ($this->operation == 'edit') {
      $form['additional']['name'] = [
        '#type' => 'textfield',
        '#maxlength' => 2048,
        '#title' => $this->t('Name'),
        '#default_value' => $pathbuilder->getName(),
        '#description' => $this->t("Name of the Pathbuilder-Tree."),
        '#required' => TRUE,
      ];

      // We need an id.
      $form['additional']['id'] = [
        '#type' => 'machine_name',
        '#default_value' => $pathbuilder->id(),
        '#disabled' => !$pathbuilder->isNew(),
        '#machine_name' => [
          'source' => ['additional', 'name'],
          'exists' => '\Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity::load',
        ],
      ];

      if ($this->with_solr) {
        $form['additional']['with_solr'] = [
          '#type' => 'button',
          '#value' => $this->t('Show Solr Paths'),
          '#attributes' => [
            'onclick' => 'return false;',
          ],
          '#attached' => [
            'library' => [
              'wisski_pathbuilder/wisski_pathbuilder_solr',
            ],
          ],
        ];
      }
      // $form['additional']['with_solr'] = array(
      // '#type' => 'checkbox',
      // '#title' => $this->t('Show Solr Paths'),
      // '#default_value' => $this->with_solr,
      // '#description' => $this->t("Show the solr paths."),
      // );
    }

    // Change the adapter this pb belongs to?
    $form['additional']['adapter'] = [
      '#type' => 'select',
      '#description' => $this->t('Which adapter does this Pathbuilder belong to?'),
      '#default_value' => $pathbuilder->getAdapterId(),
    // array(0 => "Pathbuilder"),.
      '#options' => $adapterlist,
    ];
    // This is obsolete.
    /*
    // what is the create mode?
    $form['additional']['create_mode'] = array(
    '#type' => 'select',
    '#description' => $this->t('What should be generated on save?'),
    '#default_value' => empty($pathbuilder->getCreateMode()) ? 'wisski_bundle' : $pathbuilder->getCreateMode(),
    '#options' => array('field_collection' => 'field_collection', 'wisski_bundle' => 'wisski_bundle'),
    );
     */
    $form['import'] = [
      '#type' => 'fieldset',
      '#tree' => FALSE,
      '#title' => $this->t('Import Templates'),
    ];

    $form['import']['import'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pathbuilder Definition Import'),
      '#description' => $this->t('Path to a pathbuilder definition file.'),
      '#maxlength' => 2048,
    // '#default_value' => $pathbuilder->getCreateMode(),
    // '#options' => array('field_collection' => 'field_collection', 'wisski_bundle' => 'wisski_bundle'),
    ];

    $field_options = [
      'keep' => $this->t('Keep settings from import file'),
      Pathbuilder::CONNECT_NO_FIELD => $this->t('Do not create fields and bundles'),
      Pathbuilder::GENERATE_NEW_FIELD => $this->t('Create new fields and bundles'),
    ];

    $form['import']['import_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Set default mode to'),
      '#description' => $this->t('What should the fields and groups mode be set to?'),
      '#options' => $field_options,
      '#default_value' => 'keep',
    ];

    $form['import']['importbutton'] = [
      '#type' => 'submit',
      '#value' => 'Import',
      '#submit' => ['::import'],
    // '#description' => $this->t('Path to a pathbuilder definition file.'),
    // '#default_value' => $pathbuilder->getCreateMode(),
    // '#options' => array('field_collection' => 'field_collection', 'wisski_bundle' => 'wisski_bundle'),
    ];

    $form['export'] = [
      '#type' => 'fieldset',
      '#tree' => FALSE,
      '#title' => $this->t('Export Templates'),
    ];

    $export_path = 'public://wisski_pathbuilder/export/';

    \Drupal::service('file_system')->prepareDirectory($export_path, FileSystemInterface::CREATE_DIRECTORY);

    $files = \Drupal::service('file_system')->scanDirectory($export_path, '/.*/');

    ksort($files);

    $items = [];

    foreach ($files as $file) {
      // $form['export']['export'][] = array('#type' => 'link', '#title' => $file->filename, '#url' => Url::fromUri(file_create_url($file->uri)));
      $items[] = ['#type' => 'link', '#title' => $file->filename, '#url' => Url::fromUri(file_create_url($file->uri))];
    }

    $form['export']['export'] = [
      '#theme' => 'item_list',
    // '#title' => 'Existing exports',
      '#items' => $items,
      '#type' => 'ul',
      '#attributes' => ['class' => 'pb_export'],
    ];

    $form['export']['exportbutton'] = [
      '#type' => 'submit',
      '#value' => 'Create Exportfile',
      '#submit' => ['::export'],
    // '#description' => $this->t('Path to a pathbuilder definition file.'),
    // '#default_value' => $pathbuilder->getCreateMode(),
    // '#options' => array('field_collection' => 'field_collection', 'wisski_bundle' => 'wisski_bundle'),
    ];

    $form['#attached'] = [
      'library' => [
        'wisski_pathbuilder/wisski_pathbuilder',
      ],
    ];

    // dpm($form);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {

    $element = parent::actions($form, $form_state);
    $element['#type'] = '#dropbutton';

    // Only add this to "normal" ones...
    if ($this->entity->getType() != "linkblock" && strpos($this->entity->getName(), "(Linkblock)") === FALSE && $this->entity->getName() != "WissKI Linkblock PB" && !is_null($this->entity->id())) {
      $element['generate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save and generate bundles and fields'),
        '#submit' => ['::submitForm', '::save_and_generate_forms'],
        '#weight' => -10,
        '#dropbutton' => 'save',
      ];
    }

    $element['submit']['#value'] = $this->t('Save without form generation');
    $element['submit']['#dropbutton'] = 'save';
    return $element;
  }

  /**
   * Exports pathbuilder structure.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @throws \Exception
   */
  public function export(array &$form, FormStateInterface $form_state, ?array $paths = NULL) {
    // Create initial XML tree.
    $xmlTree = new \SimpleXMLElement("<pathbuilderinterface></pathbuilderinterface>");

    // Get the pathbuilderEntity.
    $pathbuilderEntity = $this->entity;

    // Fetch the paths.
    !$paths ? $paths = $form_state->getValue('pathbuilder_table') : $paths;
    // Iterate over every path.
    foreach ($paths as $key => $path) {
      $pathbuilder = $pathbuilderEntity->getPbPath($path['id']);
      $pathChild = $xmlTree->addChild("path");
      $pathObject = WisskiPathEntity::load($path['id']);

      foreach ($pathbuilder as $subkey => $value) {

        if (in_array($subkey, ['relativepath'])) {
          continue;
        }

        if ($subkey == "parent") {
          $subkey = "group_id";
        }

        $pathChild->addChild($subkey, htmlspecialchars($value));
      }

      $pathArray = $pathChild->addChild('path_array');
      foreach ($pathObject->getPathArray() as $subkey => $value) {
        $pathArray->addChild($subkey % 2 == 0 ? 'x' : 'y', $value);
      }

      $pathChild->addChild('datatype_property', htmlspecialchars($pathObject->getDatatypeProperty()));
      $pathChild->addChild('short_name', htmlspecialchars($pathObject->getShortName()));
      $pathChild->addChild('disamb', htmlspecialchars($pathObject->getDisamb()));
      $pathChild->addChild('description', htmlspecialchars($pathObject->getDescription()));
      $pathChild->addChild('uuid', htmlspecialchars($pathObject->uuid()));
      if ($pathObject->getType() == "Group" || $pathObject->getType() == "Smartgroup") {
        $pathChild->addChild('is_group', "1");
      }
      else {
        $pathChild->addChild('is_group', "0");
      }
      $pathChild->addChild('name', htmlspecialchars($pathObject->getName()));

    }

    $dom = dom_import_simplexml($xmlTree)->ownerDocument;
    $dom->formatOutput = TRUE;

    $export_path = 'public://wisski_pathbuilder/export/' . $pathbuilderEntity->id() . date('_Ymd\THis');
    $this->file->writeData($dom->saveXML(), $export_path, FileSystemInterface::EXISTS_RENAME);
  }

  /**
   *
   */
  public function import(array &$form, FormStateInterface $form_state) {

    $importfile = $form_state->getValue('import');

    $importmode = $form_state->getValue('import_mode');

    // dpm($importfile, "importfile!!");.
    $xmldoc = new \SimpleXMLElement($importfile, 0, TRUE);

    // dpm($xmldoc, "doc!");.
    $pb = $this->entity;

    foreach ($xmldoc->path as $path) {
      $parentid = html_entity_decode((string) $path->group_id);

      // if($parentid != 0)
      // $parentid = wisski_pathbuilder_check_parent($parentid, $xmldoc);.
      $uuid = html_entity_decode((string) $path->uuid);

      // if(empty($uuid))
      // Check if path already exists.
      $path_in_wisski = WisskiPathEntity::load((string) $path->id);

      // It exists, skip this...
      if (!empty($path_in_wisski)) {
        $this->messenger()->addStatus("Path with id " . $uuid . " was already existing - skipping.");

        $pb->addPathToPathTree($path_in_wisski->id(), $parentid, $path_in_wisski->isGroup());

        // continue;.
      }
      // Normal case - import the path!
      else {

        $path_array = [];
        $count = 0;
        foreach ($path->path_array->children() as $n) {
          $path_array[$count] = html_entity_decode((string) $n);
          $count++;
        }

        // It does not exist, create one!
        $pathdata = [
          'id' => html_entity_decode((string) $path->id),
          'name' => html_entity_decode((string) $path->name),
          'path_array' => $path_array,
          'datatype_property' => html_entity_decode((string) $path->datatype_property),
          'short_name' => html_entity_decode((string) $path->short_name),
          'length' => html_entity_decode((string) $path->length),
          'disamb' => html_entity_decode((string) $path->disamb),
          'description' => html_entity_decode((string) $path->description),
          'type' => (((int) $path->is_group) === 1) ? 'Group' : 'Path',
        // 'field' => Pathbuilder::GENERATE_NEW_FIELD,
        ];

        // In D8 we do no longer allow a path/group without a name.
        // we have to set it to a dummy value.
        if ($pathdata['name'] == '') {
          $pathdata['name'] = "_empty_";
          $this->messenger()->addWarning(t('Path with id @id (@uuid) has no name. Name has been set to "_empty_".', ['@id' => $pathdata['id'], '@uuid' => $uuid]));
        }

        $path_in_wisski = WisskiPathEntity::create($pathdata);

        $path_in_wisski->save();

        $pb->addPathToPathTree($path_in_wisski->id(), $parentid, $path_in_wisski->isGroup());
      }

      // Check enabled or disabled.
      $pbpaths = $pb->getPbPaths();

      $pbpaths[$path_in_wisski->id()]['enabled'] = html_entity_decode((string) $path->enabled);
      $pbpaths[$path_in_wisski->id()]['weight'] = html_entity_decode((string) $path->weight);
      $pbpaths[$path_in_wisski->id()]['cardinality'] = html_entity_decode((string) $path->cardinality);
      if (html_entity_decode((string) $importmode) != "keep") {
        if (((int) $path->is_group) === 1) {
          $pbpaths[$path_in_wisski->id()]['bundle'] = html_entity_decode((string) $importmode);
        }
        else {
          $pbpaths[$path_in_wisski->id()]['field'] = html_entity_decode((string) $importmode);
        }
      }
      else {
        $pbpaths[$path_in_wisski->id()]['bundle'] = html_entity_decode((string) $path->bundle);
        $pbpaths[$path_in_wisski->id()]['field'] = html_entity_decode((string) $path->field);

        if ($path->fieldtype) {
          $pbpaths[$path_in_wisski->id()]['fieldtype'] = html_entity_decode((string) $path->fieldtype);
        }

        if ($path->displaywidget) {
          $pbpaths[$path_in_wisski->id()]['displaywidget'] = html_entity_decode((string) $path->displaywidget);
        }

        if ($path->formatterwidget) {
          $pbpaths[$path_in_wisski->id()]['formatterwidget'] = html_entity_decode((string) $path->formatterwidget);
        }
      }
      $pb->setPbPaths($pbpaths);

    }

    $pb->save();

    // drupal_set_message(serialize($pb->getPbPaths()));
  }

  /**
   *
   */
  private function recursive_render_tree($grouparray, $parent = 0, $delta = 0, $depth = 0, $namespaces = NULL, $solr = "") {
    // dpm(microtime(), "1");
    // First we have to get any additional fields because we just got the tree-part
    // and not the real data-fields.
    $pbpath = $this->entity->getPbPath($grouparray['id']);

    // If we did not get something, stop.
    if (empty($pbpath)) {
      return [];
    }

    // if(empty($namespaces))
    // $namespaces =.
    // Merge it into the grouparray.
    $grouparray = array_merge($grouparray, $pbpath);

    if (!isset($grouparray['cardinality'])) {
      $grouparray['cardinality'] = -1;
    }

    // What to add to solr in this case?
    $group_solr = isset($grouparray['field']) ? $grouparray['field'] : $grouparray['bundle'];

    if (empty($solr)) {
      $group_solr = "entity:wisski_individual/";
    }
    elseif ($solr == "entity:wisski_individual/") {
      // Special case if it is the first thingie.
      $group_solr = $group_solr;
    }
    else {
      $group_solr = ":entity:" . $group_solr;
    }

    $pathform[$grouparray['id']] = $this->pb_render_path($grouparray['id'], $grouparray['enabled'], $grouparray['weight'], $depth, $parent, $grouparray['bundle'], $grouparray['field'], $grouparray['fieldtype'], $grouparray['displaywidget'], $grouparray['formatterwidget'], $grouparray['cardinality'], $namespaces, $solr . $group_solr);

    if (is_null($pathform[$grouparray['id']])) {
      unset($pathform[$grouparray['id']]);
      return [];
    }

    $subforms = [];

    $children = [];
    $weights = [];

    foreach ($grouparray['children'] as $key => $child) {
      $pbp = $this->entity->getPbPath($key);
      // $solrs[$key] = isset($pbp['field']) ? $pbp['field'] : $pbp['group'];
      $weights[$key] = $pbp['weight'];
    }

    $children = $grouparray['children'];

    array_multisort($weights, $children);

    if (empty($pathform[$grouparray['id']]['#item'])) {
      return [];
    }

    $mypath = $pathform[$grouparray['id']]['#item']->getPathArray();

    // $origpf = $pathform;
    foreach ($children as $childpath) {
      $subform = $this->recursive_render_tree($childpath, $grouparray['id'], $delta, $depth + 1, $namespaces, $solr . $group_solr);

      // Check if the group is correct.
      foreach ($subform as $sub) {

        if (empty($sub['#item'])) {
          continue;
        }

        $subpath = $sub['#item']->getPathArray();

        // Calculate the diff between the subpath and the group.
        $diff = array_diff($subpath, $mypath);

        // And do it the primitive way.
        $difflength = count($subpath) - count($mypath);

        // If these differ there is something fishy!
        if (count($diff) > $difflength) {
          $subname = $sub['#item']->getName();
          $pathname = $pathform[$grouparray['id']]['#item']->getName();

          $this->messenger()->addError("Path " . $subname . " conflicts with definition of group " . $pathname . ". Please check.");
          $pathform[$grouparray['id']]['#attributes'] = ['style' => ['background-color: red']];
        }

      }
      // dpm($pathform, "before");
      // dpm($subform, "before2");.
      if (!empty($subform)) {
        $pathform = $pathform + $subform;
      }

      // dpm($pathform, "after");
      // return;.
    }
    // dpm(microtime(), "2");.
    return $pathform;

  }

  /**
   *
   */
  private function wisski_weight_sort($a, $b) {
    // dpm("I am alive!");.
    if (intval($a['weight']['#default_value']) == intval($b['weight']['#default_value'])) {
      return 0;
    }

    return (intval($a['weight']['#default_value']) < intval($b['weight']['#default_value'])) ? -1 : 1;
  }

  /**
   *
   */
  private function pb_render_path($pathid, $enabled, $weight, $depth, $parent, $bundle, $field, $fieldtype, $displaywidget, $formatterwidget, $cardinality, $namespaces, $solr) {
    $path = \Drupal::service('entity_type.manager')->getStorage('wisski_path')->load($pathid);

    if (is_null($path)) {
      return NULL;
    }

    $pathform = [];

    $item = [];

    // $item['#title'] = $path->getName();
    $path->depth = $depth;

    $pathform['#item'] = $path;

    $pathform['#attributes'] = $enabled ? ['class' => ['menu-enabled']] : ['class' => ['menu-disabled']];

    // $pathform['title'] = '<a href="/dev/contact" data-drupal-selector="edit-links-menu-plugin-idcontactsite-page-title-1" id="edit-links-menu-plugin-idcontactsite-page-title-1" class="menu-item__link">Contact</a>';
    // $path->name;
    $pathform['title'] = [
      '#type' => 'label',
      '#title' =>
      $path->getName(),
      '#attributes' => $path->isGroup() ? ['data-pathbuilder-group' => 'true', 'style' => 'font-weight: bold;'] : ['data-pathbuilder-group' => 'false', 'style' => 'font-weight: normal; font-style:italic;'],
    ];

    if (!$enabled) {
      $pathform['title']['#suffix'] = ' (' . $this->t('disabled') . ')';
    }

    /*
    $pathform['path'] = array(
    '#type' => 'item',
    '#markup' => $path->printPath($namespaces),
    );
     */

    $pathform['path'] = [
      '#markup' => $path->printPath($namespaces),
      '#allowed_tags' => ['span'],
    ];

    if (!$this->with_solr) {
      // $pathform['solr']['#type'] = 'hidden';
      // $pathform['solr']['#value'] = $solr;
    }
    else {
      $pathform['solr'] = [
        '#markup' => "<span class = 'wki-pb-solr'>" . $solr . "</span>",
        '#allowed_tags' => ['span'],
      ];
    }

    // If it is a group, mark it as such.
    if ($path->isGroup()) {
      $pathform['path']['#markup']  = 'Group [' . $pathform['path']['#markup'];
      $pathform['path']['#markup'] .= ']';
    }

    $pathform['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable @title path', ['@title' => $path->getName()]),
      '#title_display' => 'invisible',
      '#default_value' => $enabled,
    ];

    $field_type_label = $fieldtype ? \Drupal::service('plugin.manager.field.field_type')->getDefinition($fieldtype)['label'] : '';
    $pathform['field_type_informative'] = [
      '#type' => 'item',
      '#value' => $fieldtype,
      '#markup' => $field_type_label,
    ];

    $pathform['cardinality'] = [
      '#type' => 'item',
      '#markup' => WisskiPathbuilderConfigureFieldForm::cardinalityOptions()[$cardinality],
      '#value' => $cardinality,
      '#title' => $this->t('Field Cardinality'),
      '#title_display' => 'attribute',
    ];

    $pathform['weight'] = [
    // '#type' => 'weight',
      '#type' => 'textfield',
    // '#delta' => 100, # Do something more cute here $delta,
      '#default_value' => $weight,
      '#title' => $this->t('Weight for @title', ['@title' => $path->getName()]),
      '#title_display' => 'invisible',
    ];

    $pathform['id'] = [
    // '#type' => 'value',
      '#type' => 'hidden',
      '#value' => $path->id(),
    ];

    $pathform['parent'] = [
    // '#type' => 'value',
      '#type' => 'hidden',
      '#value' => $parent,
    ];

    // All this information is not absolutely necessary for the pb - so we skip it here.
    // if we don't do this max_input_vars and max_input_nesting overflows.
    /*
    $pathform['bundle'] = array(
    #      '#type' => 'value',
    '#type' => 'hidden',
    '#value' => $bundle,
    );

    $pathform['field'] = array(
    '#type' => 'value',
    #      '#type' => 'hidden',
    '#value' => $field,
    '#markup' => $field,
    );

    $pathform['fieldtype'] = array(
    #      '#type' => 'value',
    '#type' => 'hidden',
    '#value' => $fieldtype,
    );

    $pathform['displaywidget'] = array(
    #     '#type' => 'value',
    '#type' => 'hidden',
    '#value' => $displaywidget,
    );

    $pathform['formatterwidget'] = array(
    #      '#type' => 'value',
    '#type' => 'hidden',
    '#value' => $formatterwidget,
    );
     */
    return $pathform;
  }

  /**
   *
   */
  public function save_and_generate_forms(array $form, FormStateInterface $form_state) {
    // Get the pathbuilder.
    $pathbuilder = $this->entity;

    // Fetch the paths.
    $paths = $form_state->getValue('pathbuilder_table');

    $pathtree = [];
    $map = [];

    $this->save($form, $form_state);

    // Iterate all pbpaths and look for damaged ones
    // delete these before save.
    $pbpaths = $pathbuilder->getPbPaths();
    foreach ($pbpaths as $key => $pbpath) {
      if (empty($pbpath)) {
        unset($pbpaths[$key]);
      }
    }
    $pathbuilder->setPbPaths($pbpaths);

    // dpm($paths, "paths");.
    if (!empty($paths)) {
      foreach ($paths as $key => $path) {

        if ($path['enabled'] == 0) {

          $pathob = WisskiPathEntity::load($path['id']);

          $pbpath = $pathbuilder->getPbPath($pathob->id());

          $field = NULL;

          if ($pathob->isGroup()) {
            $field = $pbpath['bundle'];
          }
          else {
            $field = $pbpath['field'];
          }

          // Delete old fields.
          $field_storages = \Drupal::service('entity_type.manager')->getStorage('field_storage_config')->loadByProperties(['field_name' => $field]);
          if (!empty($field_storages)) {
            foreach ($field_storages as $field_storage) {
              $field_storage->delete();
            }
          }

          $field_objects = \Drupal::service('entity_type.manager')->getStorage('field_config')->loadByProperties(['field_name' => $field]);
          if (!empty($field_objects)) {
            foreach ($field_objects as $field_object) {
              $field_object->delete();
            }
          }

          continue;

        }

        if (!empty($path['parent']) && $paths[$path['parent']]['enabled'] == 0) {
          // Take it with us if the parent is disabled down the tree.
          $paths[$key]['enabled'] = 0;

          continue;
        }

        // drupal_set_message($path['id']);.
        // Generate fields!
        $pathob = WisskiPathEntity::load($path['id']);

        if ($pathob->isGroup()) {
          // Save the original bundle id because
          // if it is overwritten in create process
          // we won't have it anymore.
          $pbpaths = $pathbuilder->getPbPaths();

          // Which group should I handle?
          $my_group = $pbpaths[$pathob->id()];

          // Original bundle.
          $ori_bundle = $my_group['bundle'];

          $pathbuilder->generateBundleForGroup($pathob->id());

          if (!in_array($pathob->id(), array_keys($pathbuilder->getMainGroups()))) {
            $pathbuilder->generateFieldForSubGroup($pathob->id(), $pathob->getName(), $ori_bundle);
          }
        }
        else {
          // dpm($pathob,'$pathob');.
          $pathbuilder->generateFieldForPath($pathob->id(), $pathob->getName());
        }

      }
      $pathbuilder->save();
    }

  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {

    // Get the pathbuilder.
    $pathbuilder = $this->entity;

    // Fetch the paths.
    $paths = $form_state->getValue('pathbuilder_table');

    $pathtree = [];
    $map = [];

    // Regardless of what it is - we have to save it properly to the pbpaths.
    $pbpaths = $pathbuilder->getPbPaths();

    if (!empty($paths)) {
      foreach ($paths as $key => $path) {
        // $pathtree = array_merge($pathtree, $this->recursive_build_tree(array($key => $path)));
        // It has parents... we have to add it somewhere.
        if (!empty($path['parent'])) {
          $map[$path['parent']]['children'][$path['id']] = ['id' => $path['id'], 'children' => []];
          $map[$path['id']] = &$map[$path['parent']]['children'][$path['id']];
        }
        // It has no parent - so it is a main thing.
        else {
          $pathtree[$path['id']] = ['id' => $path['id'], 'children' => []];
          $map[$path['id']] = &$pathtree[$path['id']];
        }

        // mark: I don't know what dorian wanted to do here. If somebody can explain to me
        // it might be useful - as long as nobody can, revert.
        // $pbpaths[$path['id']] = \Drupal\wisski_core\WisskiHelper::array_merge_nonempty($pbpaths[$path['id']],$path); #array('id' => $path['id'], 'weight' => $path['weight'], 'enabled' => $path['enabled'], 'children' => array(), 'bundle' => $path['bundle'], 'field' => $path['field']);.
        // This works, but for performance reason we try to have nothing in the pathbuilder
        // overview what not absolutely has to be there.
        // $pbpaths[$path['id']] = $path;.
        // dpm($pbpaths[$path['id']], 'old');
        // dpm($path, 'new');
        // dpm(array_merge($pbpaths[$path['id']], $path), 'merged');.
        $pbpaths[$path['id']] = array_merge($pbpaths[$path['id']], $path);
      }
    }

    // Save the path.
    $pathbuilder->setPbPaths($pbpaths);

    // dpm(array('old' => $pathbuilder->getPathTree(),'new' => $pathtree, 'form paths' => $paths),'Path trees');
    // Save the tree.
    $pathbuilder->setPathTree($pathtree);

    // $pathbuilder->setWithSolr($form_state->getValue("with_solr"));
    $status = $pathbuilder->save();

    if ($status) {
      // Setting the success message.
      $this->messenger()->addStatus($this->t('Saved the pathbuilder: @id.', [
        '@id' => $pathbuilder->id(),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('The Pathbuilder @id could not be saved.', [
        '@id' => $pathbuilder->id(),
      ]));
    }
    // dpm($pathbuilder, "Pb");
    // if(!is_null($pathbuilder->id()))
    $form_state->setRedirect('entity.wisski_pathbuilder.edit_form', ['wisski_pathbuilder' => $this->entity->id()]);
    // else
    // $form_state->setRedirect('entity.wisski_pathbuilder.collection');.
  }

}
