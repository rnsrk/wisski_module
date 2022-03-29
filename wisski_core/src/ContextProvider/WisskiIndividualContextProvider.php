<?php

namespace Drupal\wisski_core\ContextProvider;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\Core\Plugin\Context\ContextProviderInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Class WisskiIndividualContextProvider
 */
class WisskiIndividualContextProvider implements ContextProviderInterface {

  use StringTranslationTrait;
  
  /**
   * The route match object.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;


  /**
   * Constructs a new NodeRouteContext.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match object.
   */
  public function __construct(RouteMatchInterface $route_match) {
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public function getRuntimeContexts(array $unqualified_context_ids) {
    $result = [];
    $context_definition = EntityContextDefinition::create('wisski_individual')->setRequired(FALSE);
    $value = NULL;
   
    $entity = NULL;  
    
    // see if we have a route object  
    if (($route_object = $this->routeMatch->getRouteObject())) {
      $route_contexts = $route_object->getOption('parameters');
            
      // do we have an individual in the route?
      // if so we get the entity.
      if(isset($route_contexts['wisski_individual']))
        $entity = $this->routeMatch->getParameter('wisski_individual');
        
    }
            
    $cacheability = new CacheableMetadata();
    $cacheability->setCacheContexts(['route']);

    $context = new Context($context_definition, $entity);
    $context->addCacheableDependency($cacheability);
    $result['wisski_individual'] = $context;

    return $result;

  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableContexts() {
    $context = EntityContext::fromEntityTypeId('wisski_individual', $this->t('Entity ID from URL'));
    return ['wisski_individual' => $context];
  }

}