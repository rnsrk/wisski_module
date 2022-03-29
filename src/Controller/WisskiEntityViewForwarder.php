<?php

namespace Drupal\wisski_core\Controller;

use Drupal\wisski_core\Entity\WisskiEntity;
use Drupal\Core\Entity\ContentEntityStorageInterface;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Controller\EntityViewController;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


class WisskiEntityViewForwarder extends EntityViewController {

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;
 
 /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;
 
  /** Creates an WisskiEntityViewForwarder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, AccountInterface $current_user, EntityRepositoryInterface $entity_repository) {
    parent::__construct($entity_type_manager, $renderer);
    $this->currentUser = $current_user;
    $this->entityRepository = $entity_repository;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('current_user'),
      $container->get('entity.repository')
    );
  }

  public function forward(WisskiEntity $wisski_individual = NULL) {

#    dpm($wisski_individual,__METHOD__);
    $storage = \Drupal::service('entity_type.manager')->getStorage('wisski_individual');
    //let's see if the user provided us with a bundle, if not, the storage will try to guess the right one
    $match = \Drupal::request();
    $bundle_id = $match->query->get('wisski_bundle');
    if ($bundle_id) $storage->writeToCache($wisski_individual->id(),$bundle_id);

    if(empty($wisski_individual))
      $wisski_individual = $storage->load($wisski_individual);
    //dpm($entity,__FUNCTION__);
    if (empty($wisski_individual)) {
      throw new NotFoundHttpException();
    }
    $entity_type = $storage->getEntityType();
    $view_builder_class = $entity_type->getViewBuilderClass();
    $view_builder = $view_builder_class::createInstance(\Drupal::getContainer(),$entity_type);
//    dpm($view_builder);
    return $view_builder->view($wisski_individual);
  }

  public function title(WisskiEntity $wisski_individual = NULL) {
    //dpm(serialize($wisski_individual), "??");
#    $langcode = \Drupal::service('language_manager')->getCurrentLanguage()->getId();
    
#    $title = $wisski_individual->label();

    return $this->entityRepository->getTranslationFromContext($wisski_individual)->label();
    
#    dpm($title, "tit?");
#    dpm($langcode, "langcode?");
#    dpm($entity->getTranslation(
    
  }
  
}
