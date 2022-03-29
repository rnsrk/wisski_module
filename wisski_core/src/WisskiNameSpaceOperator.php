<?php

namespace Drupal\wisski_core;

use Drupal\Core\Database\ConnectionInterface;
use Drupal\wisski_core\Entity\WisskiBundle;
use Drupal\wisski_pathbuilder\WisskiPathInterface;
use Drupal\wisski_salz\Query\WisskiQueryBase;

/**
 * This class manages single namespaces.
 *   The functions edit and delete of single namespaces are defined here.
 */
class WisskiNameSpaceOperator {

    /**
     * Database connection.
     *
     * @var Drupal\Core\Database\ConnectionInterface 
     */
    private ConnectionInterface $connection;

    public function __construct(ConnectionInterface $connection) {
        $this->connection = $connection;
    }

    public function editSingleNamespace($namespace, $new_shortname) {
        $namespaceTable = $this->connection->select('wisski_core_ontology_namespaces', 'f')
        ->fields('f', array('short_name', 'long_name'))->condition('short_name', $new_shortname)->execute()->fetchAll();
        if(!is_null($namespaceTable) && count($namespaceTable) >= 1){
            \Drupal::messenger()->addError('Namespace is already in use - please select another one.');
            return;
        }
       
        // if short name is not already in use
        $this->connection->update('wisski_core_ontology_namespaces')
        ->fields(array('short_name' => $new_shortname))    
        ->condition('short_name',$namespace)
        ->execute();

        \Drupal::messenger()->addStatus('Namespace successfully changed to '.$new_shortname . '.');
    }

    /**
     * Deletes a given namespace.
     *   Build a connection with the database, search the given namespace
     *   and delete it from the database.
     * 
     * @param string $namespace
     *   the namespace that should be deleted.
     */
    public function deleteSingleNamespace($namespace) {
        $this->connection->delete('wisski_core_ontology_namespaces')     
       ->condition('short_name',$namespace)
       ->execute();
      /* $namespaceTable = $this->connection->db_delete('wisski_core_ontology_namespaces', 'f')     
       ->condition('f.short_name',$namespace,'=')
       ->execute()->fetchAll();*/
       #  ->fields('f', array('short_name', 'long_name'))
       \Drupal::messenger()->addStatus('Namespace ' . $namespace . ' deleted.');
    }

}