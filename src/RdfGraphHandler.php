<?php

namespace Drupal\rdf_entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Contains helper methods for managing the Rdf graphs.
 *
 * @package Drupal\rdf_entity
 */
class RdfGraphHandler {
  use StringTranslationTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $moduleHandler;

  /**
   * The active graphs for the entity.
   *
   * These graphs will be used to interact with the database.
   *
   * @var array
   */
  protected $enabledGraphs = ['default'];

  /**
   * The request graphs are the graphs that will be used for the request.
   *
   * This can differ from the enabled graphs as the enabled graphs hold all
   * available graphs of the entity type, while the request graphs only hold the
   * graphs for the storage operations.
   *
   * @code
   * $requestGraphs = [
   *    $entity_id => [
   *      graph1,
   *      graph2
   *    ]
   *    $entity_id2 => [
   *      graph1,
   *      graph2,
   *    ]
   *  ]
   * @code
   *
   * @var array
   */
  protected $requestGraphs;

  /**
   * Holds the graphs that the entity is going to be saved in.
   *
   * @var string|null
   */
  protected $targetGraph = NULL;

  /**
   * Constructs a QueryFactory object.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *    The entity type manager.
   */
  public function __construct(EntityManagerInterface $entity_manager) {
    $this->entityManager = $entity_manager;
    $this->moduleHandler = $this->getModuleHandlerService();

    // Allow altering the default active graph.
    $graph = $this->enabledGraphs;
    $this->moduleHandler->alter('rdf_default_active_graph', $entity_type, $graph);
    $this->enabledGraphs = $graph;
  }

  /**
   * Get the defined graph types for this entity type.
   *
   * A default graph is provided here already because there has to exist at
   * least one available graph for the entities to be saved in.
   *
   * @param string $entity_type_id
   *    The entity type machine name.
   *
   * @return array
   *    A structured array of graph definitions containing a title and a
   *    description. The array keys are the machine names of the graphs.
   */
  public function getGraphDefinitions($entity_type_id) {
    $graphs_definition = [];
    $graphs_definition['default'] = [
      'title' => $this->t('Default'),
      'description' => $this->t('The default graph used to store entities of this type.'),
    ];
    // @todo Consider turning this into an event. Advantages?

    $this->moduleHandler->alter('rdf_graph_definition', $entity_type_id, $graphs_definition);
    return $graphs_definition;
  }

  /**
   * Returns the active graphs as an array.
   *
   * @return array
   *    An array of graph machine names.
   */
  public function getEntityTypeEnabledGraphs() {
    return $this->enabledGraphs;
  }

  /**
   * Returns the graph uri for the passed bundle of the passed entity type.
   *
   * @param string $entity_type_bundle_key
   *    The bundle entity id of an entity type e.g. 'node_type'.
   * @param string $bundle
   *    The bundle machine name.
   * @param string $graph_name
   *    The graph type. Defaults to 'default'.
   *
   * @return string
   *    The uri of the requesteg graph.
   *
   * @throws \Exception
   *    Thrown when the passed graph cannot be determined.
   */
  public function getBundleGraphUri($entity_type_bundle_key, $bundle, $graph_name) {
    return $this->getBundleGraphUriFromSettings($entity_type_bundle_key, $bundle, $graph_name);
  }

  /**
   * Returns the graph uris for bundles of the passed entity type.
   *
   * @param string $entity_type_bundle_key
   *    The bundle entity id of an entity type e.g. 'node_type'.
   * @param array $graph_names
   *    The graph type. Defaults to 'default'.
   *
   * @return array
   *    An array of graphs uris with the graph uris as keys and the bundles as
   *   values.
   *
   * @throws \Exception
   *    Thrown when the passed graph cannot be determined.
   */
  public function getEntityTypeGraphUris($entity_type_bundle_key, $graph_names = []) {
    if (empty($graph_names)) {
      $graph_names = $this->getEntityTypeEnabledGraphs();
    }
    $bundle_entities = $this->entityManager->getStorage($entity_type_bundle_key)->loadMultiple();
    $graphs = [];
    foreach ($bundle_entities as $bundle_entity) {
      foreach ($graph_names as $graph_name) {
        $graph = $this->getBundleGraphUriFromSettings($entity_type_bundle_key, $bundle_entity->id(), $graph_name);
        $graphs[$bundle_entity->id()][$graph_name] = $graph;
      }
    }
    return $graphs;
  }

  /**
   * Returns a plain list of graphs related to the passed entity type.
   *
   * @param string $entity_type_bundle_key
   *    The entity type bundle key e.g. 'node_type'.
   * @param array $graph_names
   *    Optionally filter the graphs to be returned.
   *
   * @todo: Need to pass only the entity type id here.
   *
   * @return array
   *    A plain list of graph uris.
   */
  public function getEntityTypeGraphUrisList($entity_type_bundle_key, $graph_names = []) {
    if (empty($graph_names)) {
      $graph_names = $this->getEntityTypeEnabledGraphs();
    }
    $graph_list = [];
    $entity_graphs = $this->getEntityTypeGraphUris($entity_type_bundle_key, $graph_names);
    foreach ($entity_graphs as $bundle_id => $bundle_graphs) {
      foreach ($graph_names as $graph_name) {
        $graph_list[] = $entity_graphs[$bundle_id][$graph_name];
      }
    }

    return $graph_list;
  }

  /**
   * Resets the request graphs on the fly.
   *
   * This method resets the request graphs back to NULL. Since GraphHandler
   * class is a service, the request graphs persist along the complete request.
   * That means that if more entities with the same entity type are being loaded
   * at the same request, the request graphs will be inherited. Cases like this,
   * is when the entity has entity reference fields or there is a block in the
   * page showing entities like the one requested.
   *
   * @todo: Is there a better way for this? It is called after the loading of
   * the entity but maybe it would be better to initialize it with the query
   * factory. That way, it will be able to get overridden but will reset for
   * every new entity.
   */
  public function resetRequestGraphs() {
    $this->requestGraphs = NULL;
  }

  /**
   * Returns the request graphs stored in the service.
   *
   * @param string $entity_id
   *    The entity id associated with the requested graphs.
   *
   * @return array
   *    The request graphs.
   */
  public function getRequestGraphs($entity_id) {
    if (!isset($this->requestGraphs[$entity_id])) {
      $this->requestGraphs[$entity_id] = $this->getEntityTypeEnabledGraphs();
    }
    return $this->requestGraphs[$entity_id];
  }

  /**
   * Set the graph type to use when interacting with entities.
   *
   * @param string $entity_id
   *    The entity id associated with the requested graphs.
   * @param string $entity_type_id
   *    The entity type machine name.
   * @param array $graph_names
   *    An array of graph machine names.
   *
   * @todo: This occurs in almost every method. Can we inject the entity type?
   *
   * @todo: Need to check whether a new instance is created when multiple types
   * are being loaded e.g. when an entity with entity references are loaded.
   * In this case, each entity might have a different graph definition from
   * where it needs to be loaded.
   *
   * @throws \Exception
   *    Thrown if there is an invalid graph in the argument array or if the
   *    final array is empty as there must be at least one active graph.
   */
  public function setRequestGraphs($entity_id, $entity_type_id, array $graph_names) {
    $definitions = $this->getGraphDefinitions($entity_type_id);
    $graphs_array = [];
    foreach ($graph_names as $graph_name) {
      if (!isset($definitions[$graph_name])) {
        throw new \Exception('Unknown graph type ' . $graph_name);
      }
      $graphs_array[] = $graph_name;
    }

    // @todo: Should we have the default one set if the result set is empty?
    if (empty($graphs_array)) {
      throw new \Exception("There must be at least one active graph.");
    }

    // Remove duplicates as there might be occurrences after the loop above.
    $this->requestGraphs[$entity_id] = array_unique($graphs_array);
  }

  /**
   * Returns the stored target graph.
   *
   * @return string
   *    The target graph to save to.
   */
  public function getTargetGraph() {
    return $this->targetGraph;
  }

  /**
   * Sets the target graph.
   *
   * The target graph is the graph that the entity is going to be saved in.
   *
   * @param string $target_graph
   *    The target graph machine name.
   */
  public function setTargetGraph($target_graph) {
    $this->targetGraph = $target_graph;
  }

  /**
   * Returns the save graph for the entity.
   *
   * The priority of the graphs is:
   *  - If there is only one graph enabled for the requested entity type, return
   * this graph.
   *  - If there is a target graph set, this is used. This allows other modules
   * to interact with the graphs.
   *  - The graph from where the entity is loaded.
   *  - The default graph from the enabled.
   *  - The first available graph.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *    The entity to determine the save graph for.
   *
   * @return string
   *    The graph id.
   */
  public function getTargetGraphFromEntity(EntityInterface $entity) {
    if (!empty($this->getTargetGraph())) {
      return $this->getTargetGraph();
    }
    elseif ($entity->graph && !empty($entity->graph->first()->getValue()['value'])) {
      return $entity->graph->first()->getValue()['value'];
    }
    else {
      $enabled_graphs = $this->getEntityTypeEnabledGraphs();
      if (in_array('default', $enabled_graphs)) {
        return 'default';
      }
      else {
        return reset($enabled_graphs);
      }
    }
  }

  /**
   * Returns the graph machine name, given the graph uri.
   *
   * This is basically a reverse search to get the id of the graph.
   *
   * @param string $entity_type_bundle_key
   *    The entity type bundle key e.g. 'node_type'.
   * @param string $bundle_id
   *    The for which we are searching a graph. This is mandatory as multiple
   *   bundles can use the same graph.
   * @param string $graph_uri
   *    The uri of the graph.
   *
   * @return string
   *    The id of the graph.
   */
  public function getBundleGraphId($entity_type_bundle_key, $bundle_id, $graph_uri) {
    $graphs = $this->getEntityTypeGraphUris($entity_type_bundle_key);
    return array_search($graph_uri, $graphs[$bundle_id]);
  }

  /**
   * Retrieves the uri of a bundle's graph from the settings.
   *
   * @param string $bundle_type_key
   *    The bundle type key. E.g. 'node_type'.
   * @param string $bundle_id
   *    The bundle machine name.
   * @param string $graph_name
   *    The graph name.
   *
   * @return string
   *    The graph uri.
   *
   * @throws \Exception
   *    Thrown if the graph is not found.
   */
  protected function getBundleGraphUriFromSettings($bundle_type_key, $bundle_id, $graph_name) {
    $bundle = $this->entityManager->getStorage($bundle_type_key)->load($bundle_id);
    $graph = $bundle->getThirdPartySetting('rdf_entity', 'graph_' . $graph_name, FALSE);
    if (!$graph) {
      throw new \Exception(format_string('Unable to determine graph %graph for bundle %bundle', [
        '%graph' => $graph_name,
        '%bundle' => $bundle->id(),
      ]));
    }
    return $graph;
  }

  /**
   * Returns the module handler service object.
   *
   * @todo: Check how we can inject this.
   */
  protected function getModuleHandlerService() {
    return \Drupal::moduleHandler();
  }

}
