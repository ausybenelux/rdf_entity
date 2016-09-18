<?php

namespace Drupal\rdf_entity;


use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

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
   * @var array
   */
  protected $requestGraphs;

  /**
   * Holds the graphs that the entity is going to be saved in.
   *
   * @var string|null
   */
  protected $targetGraphs = NULL;

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

    // By default, all graphs are available.
    $this->requestGraphs = $this->enabledGraphs;
  }

  /**
   * Get the defined graph types for this entity type.
   *
   * A default graph is provided here already because there has to exist at
   * least one available graph for the entities to be saved in.
   *
   * @param string $entity_type_id
   *    The entity type machine name.
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
   * @todo: Rename this to getRequestGraphs as they refer to the graphs
   *    that will interact with the database for the specific request.
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
   * @param $bundle
   *    The bundle machine name.
   * @param string $graph_name
   *    The graph type. Defaults to 'default'
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
   *    The graph type. Defaults to 'default'
   *
   * @return array
   *    An array of graphs uris with the graph uris as keys and the bundles as
   * values.
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
        $graphs[$graph][] = $bundle_entity->id();
      }
    }
    return $graphs;
  }

  /**
   * Returns the request graphs stored in the service.
   *
   * @return array
   *    The request graphs.
   */
  public function getRequestGraphs() {
    return $this->requestGraphs;
  }

  /**
   * Set the graph type to use when interacting with entities.
   *
   * @todo: Need to check whether a new instance is created when multiple types
   * are being loaded e.g. when an entity with entity references are loaded.
   * In this case, each entity might have a different graph definition from
   * where it needs to be loaded.
   *
   * @param array $graph_names
   *    An array of graph machine names.
   * @param string $entity_type_id
   *    The entity type machine name.
   * @todo: This occurs in almost every method. Can we inject the entity type?
   *
   * @throws \Exception
   *    Thrown if there is an invalid graph in the argument array or if the
   *    final array is empty as there must be at least one active graph.
   */
  public function setRequestGraphs($entity_type_id, array $graph_names) {
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
    $this->requestGraphs = array_unique($graphs_array);
  }

  /**
   * Returns the stored target graph.
   *
   * @return string
   *    The target graph to save to.
   */
  public function getTargetGraphs() {
    return $this->targetGraphs;
  }

  /**
   * Sets the target graph.
   *
   * The target graph is the graph that the entity is going to be saved in.
   *
   * @param string $target_graphs
   *    The target graph machine name.
   */
  public function setTargetGraphs($target_graphs) {
    $this->targetGraphs = $target_graphs;
  }

  /**
   * Retrieves the uri of a bundle's graph from the settings.
   *
   * @param $bundle_type_key
   *    The bundle type key. E.g. 'node_type'.
   * @param $bundle_id
   *    The bundle machine name.
   * @param $graph_name
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