<?php

namespace Drupal\wisski_pathbuilder;

require __DIR__ . '/../../vendor/autoload.php';

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity;
use Drupal\wisski_pathbuilder\Entity\WisskiPathEntity;
use Drupal\wisski_salz\Entity\Adapter;
use Drupal\wisski_salz\RdfSparqlUtil;

/**
 *
 */
class PathbuilderManager {

  use StringTranslationTrait;

  private static $pbsForAdapter = NULL;

  private static $pbsUsingBundle = NULL;

  private static $bundlesWithStartingConcept = NULL;

  private static $imagePaths = NULL;

  private static $pbs = NULL;

  private static $paths = NULL;

  /**
   * The FileRepositoryInterface service instance.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected FileRepositoryInterface $file;

  /**
   *
   */
  private $wisskiPathbuilderStorage;

  /**
   * Constructs form variables.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The translations service.
   * @param \Drupal\file\FileRepositoryInterface $file
   *   Performs file system operations and updates database records
   *   accordingly.
   */
  public function __construct(TranslationInterface $stringTranslation,
                              FileRepositoryInterface $file,
                              EntityTypeManagerInterface $entityTypeManager) {
    $this->stringTranslation = $stringTranslation;
    $this->file = $file;
    $this->wisskiPathbuilderStorage = $entityTypeManager->getStorage('wisski_pathbuilder');
  }

  /**
   * Reset the cached mappings.
   */
  public function reset() {
    self::$pbsForAdapter = NULL;
    self::$pbsUsingBundle = NULL;
    self::$imagePaths = NULL;
    self::$pbs = NULL;
    self::$paths = NULL;
    \Drupal::cache()->delete('wisski_pathbuilder_manager_pbs_for_adapter');
    \Drupal::cache()->delete('wisski_pathbuilder_manager_pbs_using_bundle');
    \Drupal::cache()->delete('wisski_pathbuilder_manager_image_paths');
  }

  /**
   * Get the pathbuilders that make use of a given adapter.
   *
   * @param adapter_id the ID of the adapter
   *
   * @return if adapter_id is empty, returns an array where the keys are
   *   adapter IDs and the values are arrays of corresponding
   *          pathbuilders. If adapter_id is given returns an array of
   *          corresponding pathbuilders.
   */
  public function getPbsForAdapter($adapter_id = NULL) {
    // Not yet fetched from cache?
    if (self::$pbsForAdapter === NULL) {
      if ($cache = \Drupal::cache()
        ->get('wisski_pathbuilder_manager_pbs_for_adapter')) {
        self::$pbsForAdapter = $cache->data;
      }
    }
    // Was reset.
    if (self::$pbsForAdapter === NULL) {
      self::$pbsForAdapter = [];
      // $pbs = entity_load_multiple('wisski_pathbuilder');
      $pbs = \Drupal::entityTypeManager()
        ->getStorage('wisski_pathbuilder')
        ->loadMultiple();

      foreach ($pbs as $pbid => $pb) {
        $aid = $pb->getAdapterId();
        $adapter = \Drupal::service('entity_type.manager')
          ->getStorage('wisski_salz_adapter')
          ->load($aid);
        if ($adapter) {
          if (!isset(self::$pbsForAdapter[$aid])) {
            self::$pbsForAdapter[$aid] = [];
          }
          self::$pbsForAdapter[$aid][$pbid] = $pbid;
        }
        else {
          \Drupal::messenger()
            ->addError(t('Pathbuilder %pb refers to non-existing adapter with ID %aid.', [
              '%pb' => $pb->getName(),
              '%aid' => $pb->getAdapterId(),
            ]));
        }
      }
      \Drupal::cache()
        ->set('wisski_pathbuilder_manager_pbs_for_adapter', self::$pbsForAdapter);
    }
    return empty($adapter_id)
      ? self::$pbsForAdapter
      // If there is no pb for this adapter there is no array key.
      : (isset(self::$pbsForAdapter[$adapter_id])
        ? self::$pbsForAdapter[$adapter_id]
        // ... thus we return an empty array
        : []);
  }

  /**
   *
   */
  public function getPbsUsingBundle($bundle_id = NULL) {
    // Not yet fetched from cache?
    if (self::$pbsUsingBundle === NULL) {
      if ($cache = \Drupal::cache()
        ->get('wisski_pathbuilder_manager_pbs_using_bundle')) {
        self::$pbsUsingBundle = $cache->data;
      }
    }
    // Was reset, recalculate.
    if (self::$pbsUsingBundle === NULL) {
      $this->calculateBundlesAndStartingConcepts();
    }
    return empty($bundle_id)
      // If no bundle given, return all.
      ? self::$pbsUsingBundle
      : (isset(self::$pbsUsingBundle[$bundle_id])
        // If bundle given and we know it, return only for this.
        ? self::$pbsUsingBundle[$bundle_id]
        // If bundle is unknown, return empty array.
        : []);

  }

  /**
   *
   */
  public function getPreviewImage($entity_id, $bundle_id, $adapter) {
    $pbs_and_paths = $this->getImagePathsAndPbsForBundle($bundle_id);

    // dpm($pbs_and_paths, "yay!");.
    foreach ($pbs_and_paths as $pb_id => $paths) {

      if (empty(self::$pbs)) {
        $pbs = WisskiPathbuilderEntity::loadMultiple();
        self::$pbs = $pbs;
      }
      else {
        $pbs = self::$pbs;
      }

      $pb = $pbs[$pb_id];

      /* Get the correct adapter so we dont do wrong queries... */
      if ($pb->getAdapterId() != $adapter->id()) {
        // dpm("wrong adapter");
        // dpm($adapter, "adap?");.
        continue;
      }

      $the_pathid = NULL;
      // Beat this ...
      $weight = 99999999999;

      // Go through all paths and look for the lowest weight.
      foreach ($paths as $key => $pathid) {
        $pbp = $pb->getPbPath($pathid);

        if (empty($pbp['enabled'])) {
          continue;
        }

        if (isset($pbp['weight'])) {
          if ($pbp['weight'] < $weight) {
            // Only take this if the weight is better or the same.
            $the_pathid = $pathid;
            $weight = $pbp['weight'];
            // $or_paths[$key] = $pathid;
          }
        }
        elseif (empty($the_pathid)) {
          // If there was nothing before, something is better at least.
          $the_pathid = $pathid;
        }
      }

      // dpm($pathid, "assa");.
      // Nothing found?
      if (empty($the_pathid)) {
        return [];
      }

      if (empty(self::$paths)) {
        $paths = WisskiPathEntity::loadMultiple();
        self::$paths = $paths;
      }
      else {
        $paths = self::$paths;
      }

      $path = $paths[$the_pathid];
      // dpm(microtime(), "ptr?");.
      $values = $adapter->getEngine()
        ->pathToReturnValue($path, $pb, $entity_id, 0, NULL, FALSE);
      // dpm(microtime(), "ptr!");.
      // Check for empty strings...
      if (!empty($values) && !empty(current($values))) {
        return $values;
      }
      else {
        // If we did not find anything in the "primary" path we will have to look at others...
        /*
        foreach($paths as $key => $pathid) {
        if($pathid == $the_pathid)
        continue; // we already had that

        $path = $paths[$key];


        #          dpm($pathid, "looking at id ");
        #          dpm(serialize($path), "path is");
        if(!empty($path))
        $values = $adapter->getEngine()->pathToReturnValue($path, $pb, $entity_id, 0, NULL, FALSE);

        if(!empty($values) && !empty(current($values)))
        return $values;
        }
         */
      }

    }
    return [];
  }

  /**
   *
   */
  public function getImagePathsAndPbsForBundle($bundle_id) {

    // Not yet fetched from cache?
    if (self::$imagePaths === NULL) {
      if ($cache = \Drupal::cache()
        ->get('wisski_pathbuilder_manager_image_paths')) {
        self::$imagePaths = $cache->data;
      }
    }
    // Was reset, recalculate.
    if (self::$imagePaths === NULL) {
      $this->calculateImagePaths();
    }

    if (isset(self::$imagePaths[$bundle_id])) {
      return self::$imagePaths[$bundle_id];
    }

    return [];

  }

  /**
   *
   */
  public function calculateImagePaths() {
    $info = [];

    // $pbs = entity_load_multiple('wisski_pathbuilder');
    if (empty(self::$pbs)) {
      $pbs = WisskiPathbuilderEntity::loadMultiple();
      self::$pbs = $pbs;
    }
    else {
      $pbs = self::$pbs;
    }

    foreach ($pbs as $pbid => $pb) {
      $groups = $pb->getMainGroups();

      foreach ($groups as $group) {
        $bundleid = $pb->getPbPath($group->id())['bundle'];
        $paths = $pb->getImagePathIDsForGroup($group->id());

        if (!empty($paths)) {
          self::$imagePaths[$bundleid][$pbid] = $paths;
        }

        // foreach($paths as $pathid) {
        // $path = \Drupal\wisski_pathbuilder\Entity\WisskiPathEntity::load($pathid);
        // $info[$bundleid][$pbid][$pathid] = $pathid;
        // }.
      }
    }

    \Drupal::cache()
      ->set('wisski_pathbuilder_manager_image_paths', self::$imagePaths);
  }

  /**
   *
   */
  public function getBundlesWithStartingConcept($concept_uri = NULL) {
    // Not yet fetched from cache?
    if (self::$bundlesWithStartingConcept === NULL) {
      if ($cache = \Drupal::cache()
        ->get('wisski_pathbuilder_manager_bundles_with_starting_concept')) {
        self::$bundlesWithStartingConcept = $cache->data;
      }
    }
    // Was reset, recalculate.
    if (self::$bundlesWithStartingConcept === NULL) {
      $this->calculateBundlesAndStartingConcepts();
    }
    return empty($concept_uri)
      // If no concept given, return all.
      ? self::$bundlesWithStartingConcept
      : (isset(self::$bundlesWithStartingConcept[$concept_uri])
        // If concept given and we know it, return only for this.
        ? self::$bundlesWithStartingConcept[$concept_uri]
        // If concept is unknown, return empty array.
        : []);

  }

  /**
   *
   */
  private function calculateBundlesAndStartingConcepts() {
    self::$pbsUsingBundle = [];
    self::$bundlesWithStartingConcept = [];

    if (empty(self::$pbs)) {
      $pbs = WisskiPathbuilderEntity::loadMultiple();
      self::$pbs = $pbs;
    }
    else {
      $pbs = self::$pbs;
    }

    foreach ($pbs as $pbid => $pb) {
      foreach ($pb->getAllGroups() as $group) {
        $pbpath = $pb->getPbPath($group->getID());
        $bid = $pbpath['bundle'];
        if (!empty($bid)) {
          if (!isset(self::$pbsUsingBundle[$bid])) {
            self::$pbsUsingBundle[$bid] = [];
          }
          $adapter = \Drupal::service('entity_type.manager')
            ->getStorage('wisski_salz_adapter')
            ->load($pb->getAdapterId());
          if ($adapter) {
            // Struct for pbsUsingBundle.
            if (!isset(self::$pbsUsingBundle[$bid][$pbid])) {
              $engine = $adapter->getEngine();
              $info = [
                'pb_id' => $pbid,
                'adapter_id' => $adapter->id(),
                'writable' => $engine->isWritable(),
                'preferred_local' => $engine->isPreferredLocalStore(),
                'engine_plugin_id' => $engine->getPluginId(),
                // Filled below.
                'main_concept' => [],
                // Filled below.
                'is_top_concept' => [],
                // Filled below.
                'groups' => [],
              ];
              self::$pbsUsingBundle[$bid][$pbid] = $info;
            }
            $path_array = $group->getPathArray();
            // The last concept is the main concept.
            $main_concept = end($path_array);
            self::$pbsUsingBundle[$bid][$pbid]['main_concept'][$main_concept] = $main_concept;
            if (empty($pbpath['parent'])) {
              self::$pbsUsingBundle[$bid][$pbid]['is_top_concept'][$main_concept] = $main_concept;
            }
            self::$pbsUsingBundle[$bid][$pbid]['groups'][$group->getID()] = $main_concept;

            // Struct for bundlesWithStartingConcept.
            if (!isset(self::$bundlesWithStartingConcept[$main_concept])) {
              self::$bundlesWithStartingConcept[$main_concept] = [];
            }
            if (!isset(self::$bundlesWithStartingConcept[$main_concept][$bid])) {
              self::$bundlesWithStartingConcept[$main_concept][$bid] = [
                'bundle_id' => $bid,
                'is_top_bundle' => FALSE,
                'pb_ids' => [],
                'adapter_ids' => [],
              ];
            }
            self::$bundlesWithStartingConcept[$main_concept][$bid]['pb_ids'][$pbid] = $pbid;
            self::$bundlesWithStartingConcept[$main_concept][$bid]['adapter_ids'][$adapter->id()] = $adapter->id();
            if (empty($pbpath['parent'])) {
              self::$bundlesWithStartingConcept[$main_concept][$bid]['is_top_bundle'] = TRUE;
            }

          }
          else {
            \Drupal::messenger()
              ->addError(t('Pathbuilder %pb refers to non-existing adapter with ID %aid.', [
                '%pb' => $pb->getName(),
                '%aid' => $pb->getAdapterId(),
              ]));
          }
        }
      }
    }
    \Drupal::cache()
      ->set('wisski_pathbuilder_manager_pbs_using_bundle', self::$pbsUsingBundle);
    \Drupal::cache()
      ->set('wisski_pathbuilder_manager_bundles_with_starting_concept', self::$bundlesWithStartingConcept);
  }

  /**
   *
   */
  public function getOrphanedPaths() {

    // $pba = entity_load_multiple('wisski_pathbuilder');
    // $pa = entity_load_multiple('wisski_path');
    $pba = \Drupal::entityTypeManager()
      ->getStorage('wisski_pathbuilder')
      ->loadMultiple();
    $pa = \Drupal::entityTypeManager()
      ->getStorage('wisski_path')
      ->loadMultiple();

    // Filled in big loop.
    $tree_path_ids = [];

    // Here go regular paths, ie. that are in a pb's path tree.
    $home = [];
    // Here go paths that are listed in a pb but not in its path tree (are "hidden")
    $semiorphaned = [];
    // Here go paths that aren't mentioned in any pb.
    $orphaned = [];

    foreach ($pa as $pid => $p) {
      $is_orphaned = TRUE;
      foreach ($pba as $pbid => $pb) {
        if (!isset($tree_path_ids[$pbid])) {
          $tree_path_ids[$pbid] = $this->getPathIdsInPathTree($pb);
        }
        $pbpath = $pb->getPbPath($pid);
        if (isset($tree_path_ids[$pbid][$pid])) {
          $home[$pid][$pbid] = $pbid;
          $is_orphaned = FALSE;
        }
        elseif (!empty($pbpath)) {
          $semiorphaned[$pid][$pbid] = $pbid;
          $is_orphaned = FALSE;
        }
      }
      if ($is_orphaned) {
        $orphaned[$pid] = $pid;
      }
    }
    return [
      'home' => $home,
      'semiorphaned' => $semiorphaned,
      'orphaned' => $orphaned,
    ];

  }

  /**
   *
   */
  public function getPathIdsInPathTree($pb) {
    $ids = [];
    $agenda = $pb->getPathTree();
    while ($node = array_shift($agenda)) {
      $ids[$node['id']] = $node['id'];
      $agenda = array_merge($agenda, $node['children']);
    }
    return $ids;
  }

  /**
   * Prepare export directory structure.
   *
   * @param string $exportRootDir
   *   The directory path, where to store the export files,
   *   i.e. defined as a const in ExportAllConfirmForm.
   *
   * @return string
   *   The relative path to the current export directory.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function prepareExportDirectories(string $exportRootDir) {
    // Prepare file structure.
    $relativeExportDir = $exportRootDir . date('Ymd') . '/';
    $instanceDir = $relativeExportDir . \Drupal::request()->getHost();
    $ontologiesDir = $instanceDir . '/ontologies/';
    $pathbuildersDir = $instanceDir . '/pathbuilders/';
    $directoryTree = [
      'relativeExportDir' => $relativeExportDir,
      'instanceDir' => $instanceDir,
      'ontologiesDir' => $ontologiesDir,
      'pathbuilderDir' => $pathbuildersDir,
    ];
    foreach ($directoryTree as $key => $value) {
      $preparedExportDirectory = \Drupal::service('file_system')
        ->prepareDirectory($value, FileSystemInterface::CREATE_DIRECTORY);
      // If folder is not writable, escape.
      if (!$preparedExportDirectory) {
        \Drupal::service('messenger')
          ->addError($this->t('Could not create archive at %relativeExportDirectory. Do you have the right permissions?', ['%relativeExportDirectory' => $relativeExportDir]));
        return FALSE;
      }
    }

    return $directoryTree;
  }

  /**
   * Saves all ontologies.
   *
   * @param string $ontologiesDir
   *   The directory path, where to store the export files,
   *   i.e. defined as a const in ExportAllConfirmForm.
   *
   * @return bool
   *   Sucess of the export.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function exportAllOntologies(string $ontologiesDir) {

    // Counter for existing ontologies.
    $count = 0;

    // Load adapters.
    $adapters = Adapter::loadMultiple();

    // Iterate over adapters and find the ontologies.
    foreach ($adapters as $adapter) {
      // Load the engine of the adapter.
      $engine = $adapter->getEngine();

      $query = '
      PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
      PREFIX owl: <http://www.w3.org/2002/07/owl#>
      SELECT ?s1 ?p1 ?o1 ?g
        WHERE {
          GRAPH ?g { ?s rdf:type owl:Ontology .
          } .
          GRAPH ?g { ?s1 ?p1 ?o1 .
          }
        }';
      $results = $engine->directQuery($query);
      $count += 1;

      // Create a nq-file for every Ontology.
      foreach ($results as $nquad) {
        // Parse stdClass to php array.
        $sparqlEntityArray = [];
        foreach ($nquad as $sparqlEntity) {
          $sparqlEntityArray[] = $sparqlEntity;
        }
        // Convert SPARQL entities to statement string and add to array.
        $quadStatement[] = implode(" ", array_map('self::sparqlEntityStringlifier', $sparqlEntityArray));
      }
      if (empty($quadStatement)) {
        \Drupal::service('messenger')
          ->addWarning($this->t('Found no statements, do you have an ontology?'));
        return FALSE;
      }
      // Parse statement array to string.
      $nQuads = implode(" . \n", $quadStatement) . ' .';

      // Write file to disk.
      $fileName = 'ontology_' . $count . '.nq';
      $export_path = $ontologiesDir . $fileName;
      $this->file->writeData($nQuads, $export_path, FileSystemInterface::EXISTS_REPLACE);
    }
    \Drupal::service('messenger')
      ->addMessage($this->t('Exported all ontologies.'));
    return TRUE;
  }

  /**
   * Zips all ontologies and pathbuilders.
   *
   * Takes a list of files and add them to a zip archive.
   *
   * @param string $relativeExportDir
   *   The relative export Dir.
   * @param array $zipFiles
   *   List of files to zip.
   */
  public function zipPathbuildersAndOntologies(string $relativeExportDir, array $zipFiles) {
    // If there is no export dir or zip files escape.
    if (!$relativeExportDir || !$zipFiles) {
      return FALSE;
    }
    $absoluteZipDirPath = \Drupal::service('file_system')
      ->realpath($relativeExportDir);

    // Open zip file.
    $zipPath = $absoluteZipDirPath . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    foreach ($zipFiles as $zipFile) {
      $internalZipPath = explode('/', $zipFile, 5)[4];
      $absoluteZipFilePath = \Drupal::service('file_system')
        ->realpath($zipFile);
      $zip->addFile(\Drupal::service('file_system')
        ->realpath($absoluteZipFilePath), $internalZipPath);
      \Drupal::service('messenger')
        ->addMessage($this->t('Zipped files in archive %zipPath.', ['%zipPath' => $zipPath]));

    }
    $zip->close();
    return TRUE;
  }

  /**
   * Convert EasyRdf objects to strings.
   *
   * @param object $sparqlEntity
   *   Ether EasyRdf\Resource or EasyRdf\Literal.
   */
  public function sparqlEntityStringlifier(object $sparqlEntity) {
    // If it is a ressource bracket it in angle brackets.
    if (get_class($sparqlEntity) == "EasyRdf\Resource") {
      return "<" . $sparqlEntity->getUri() . ">";
    }
    else {
      // Quote it and add language flag.
      $literal = $sparqlEntity->getValue();
      $escapedLiteral = (new RdfSparqlUtil)->escapeSparqlLiteral($literal);
      return '"' . $escapedLiteral . '"' . (empty($sparqlEntity->getLang()) ? '' : "@" . $sparqlEntity->getLang());
    }
  }

  /**
   * Exports pathbuilder structure.
   *
   * @param \Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity $pathbuilderEntity
   *   The pathbuilder entity.
   * @param string $pathbuildersDir
   *   A folder with the current date inside the EXPORT_ROOT_DIR
   *   containing ontologies and pathbuilders.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function exportPathbuilder(WisskiPathbuilderEntity $pathbuilderEntity, string $pathbuildersDir) {
    // Create initial XML tree.
    $xmlTree = new \SimpleXMLElement("<pathbuilderinterface></pathbuilderinterface>");

    // Get the paths.
    $paths = $pathbuilderEntity->getPbPaths();

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

    // Create XML DOM.
    $dom = dom_import_simplexml($xmlTree)->ownerDocument;
    $dom->formatOutput = TRUE;

    // Save the files.
    $export_path = $pathbuildersDir . 'pathbuilder_' . $pathbuilderEntity->id();
    $this->file->writeData($dom->saveXML(), $export_path, FileSystemInterface::EXISTS_REPLACE);
  }

  /**
   * Exports all pathbuilders.
   *
   * @param string $relativeExportDirectory
   *   The directory path, where to store the export files,
   *   i.e. defined as a const in ExportAllConfirmForm.
   *
   * @return bool
   *   Sucess of the export.
   *
   * @throws \Exception
   */
  public function exportAllPathbuilders(string $relativeExportDirectory) {
    $wisskiPathbuilderIds = \Drupal::entityQuery('wisski_pathbuilder')
      ->execute();
    foreach ($wisskiPathbuilderIds as $pathbuilderId) {
      $pathbuilderEntity = ($this->wisskiPathbuilderStorage->load($pathbuilderId));
      $this->exportPathbuilder($pathbuilderEntity, $relativeExportDirectory);
    }
    \Drupal::service('messenger')
      ->addMessage($this->t('Exported all pathbuilders.'));
    return TRUE;
  }

  /**
   * Removes Directories recursively.
   *
   * @param string $dir
   *   The directory to remove.
   */
  public function rRmDir(string $dir) {
    if (is_dir($dir)) {
      $objects = scandir($dir);
      foreach ($objects as $object) {
        if ($object != "." && $object != "..") {
          if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object)) {
            $this->rRmDir($dir . DIRECTORY_SEPARATOR . $object);
          }
          else {
            unlink($dir . DIRECTORY_SEPARATOR . $object);
          }
        }
      }
      rmdir($dir);
      \Drupal::service('messenger')
        ->addMessage($this->t('Removed temporary files and folders.'));
    }
  }

  /**
   * Collect files for Zip archive.
   *
   * @param string $dir
   *   The directory to search for files to zip.
   * @param array $zipFiles
   *   The files to add to the zip archive.
   */
  public function collectZipDirs(string $dir, array &$zipFiles) {
    if (is_dir($dir)) {
      $objects = scandir($dir);
      foreach ($objects as $object) {
        if ($object != "." && $object != "..") {
          $this->collectZipDirs($dir . DIRECTORY_SEPARATOR . $object, $zipFiles);
          if (is_file($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object)) {
            $zipFiles[] = $dir . DIRECTORY_SEPARATOR . $object;
          }
        }
      }
    }
  }

}
