<?php

namespace Drupal\wisski_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\ConnectionInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;

class WisskiNamespaceController extends ControllerBase {

    /**
     * Database connection.
     *
     * @var Drupal\Core\Database\ConnectionInterface 
     */
    private Connection $connection;

    public function __construct(Connection $connection) {
        $this->connection = $connection;
    }
  
    // the order of the containers here has to be the same as for __construct
    public static function create(ContainerInterface $container) {
      // Instantiates this form class.
      return new static(
      // Load the service required to construct this class.
        $container->get('database')
      );
    }

    public function loadNamespaceTable(){
        $ns = "";
        $ns = $this->connection->select('wisski_core_ontology_namespaces', 'f')
        ->fields('f', array('short_name', 'long_name'))->execute()->fetchAll();

        // $ns = $engine->getNamespaces();
        $table = [];

        $ns_new = [];
        foreach($ns as $key => $value){
            $ns_new[$value->short_name] = $value->long_name;
        }
        if (count($ns_new) > 0) {
            foreach($ns_new as $key => $value) {
                $tablens[] = [$key,$value, $this->forwardNamespace($key)];
            }

            $table['stores']['ns_table'] = array(
                '#type' => 'table',
                '#header' => ['short name (prefix label)', 'long name (IRI)', 'options'],
                '#rows' => $tablens,
                '#cache' => ['max-age' => 0],
            );

            // Button for deleting the namespaces from the corresponding table
            $table['stores']['delete_all_ns'] = array(
                '#type' => 'submit',
                '#button_type' => 'primary',
                '#name' => 'Namespaces',
                '#value' => 'Delete Namespaces',
                '#submit' => [[$this, 'deleteAllNamespaces']],
            );
        }
        return $table;
    }

    public function forwardNamespace($namespace){
        // MyFi: define a button to delete and edit namespaces
        // later this button will added to each row of the namespace list
        $links['edit'] = [
          'title' => $this->t('Edit'),
          'url' => Url::fromRoute('wisski.wisski_ontology.namespace.edit_confirm', ['namespace' => $namespace])
        ];
        $links['delete'] = [
          'title' => $this->t('Delete'),
          'url' => Url::fromRoute('wisski.wisski_ontology.namespace.delete_confirm', ['namespace' => $namespace])
        ];
    
        $ns_operations = [
          'data' => [
            '#type' => 'operations',
            '#links' => $links,
            ]
          ];
    
        return $ns_operations;
      }
}