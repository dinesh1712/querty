<?php

namespace Drupal\jnl_content_display\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\highwire_content\Lookup as HighWireContentLookup;
use Drupal\highwire_content\TocLookup as HighWireTocLookup;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block to display the pager for a chapter/section.
 *
 * @Block(
 *   id = "prevnext_toc_pager",
 *   admin_label = @Translation("TOC Pager"),
 *   category = @Translation("Pager"),
 *   context_definitions = {
 *     "node" = @ContextDefinition(
 *       "entity:node",
 *       label = @Translation("Current Node")
 *     )
 *   }
 * )
 */
class TOCPager extends BlockBase implements ContainerFactoryPluginInterface
{
  /**
   * The node the block is displayed on.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected $contextNode;
  /**
   * Lookup for getting nids from apaths.
   *
   * @var \Drupal\highwire_content\Lookup
   */
  protected $hwContentLookup;
  /**
   * Lookup for getting toc structure data.
   *
   * @var \Drupal\highwire_content\TocLookup
   */
  protected $hwTocLookup;

  /**
   * The currently active route match object.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Create block to display the previous and next links for chapter/section.
   *
   * @param array $configuration
   *   The plugin configuration, i.e. an array with configuration values keyed
   *   by configuration option name. The special key 'context' may be used to
   *   initialize the defined contexts by setting it to an array of context
   *   values keyed by context names.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\highwire_content\Lookup $hw_content_lookup
   *   Lookup helper to find nids from apaths.
   * @param \Drupal\highwire_content\TocLookup $hw_toc_lookup
   *   Lookup helper to find toc structure data.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The currently active route match object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition,
    HighWireContentLookup $hw_content_lookup,
    HighWireTocLookup $hw_toc_lookup,
    RouteMatchInterface $route_match
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->hwContentLookup = $hw_content_lookup;
    $this->hwTocLookup = $hw_toc_lookup;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('highwire_content.lookup'),
      $container->get('highwire_content.toc_lookup'),
      $container->get('current_route_match')
    );
  }

 /**
   * {@inheritdoc}
   */
  public function build() {
    // Get the node from context data.
    $node = $this->getContextValue('node');
    $build = [];

    // Check for required fields we need to get the prev/next item.
    // if we don't have any one of these, return an empty render array.
    $req_fields = ['apath', 'parent'];
    foreach ($req_fields as $field) {
      if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
        return $build;
      }
    }

    $current_path = \Drupal::service("path.current")->getPath();
    $current_alias = \Drupal::service("path_alias.manager")->getAliasByPath($current_path);

    $arg = !empty($current_alias) ? explode("/", trim($current_alias, "/")) : [];
    $content = $arg[0] ?? null;

    // Check for early access pattern: /content/early/yyyy/mm/dd/article-id
    if ($content == "content" && isset($arg[1]) && $arg[1] == "early" && count($arg) >= 6) {

      // Check early-release field (assuming field name is `early_release`).
      if (!$node->hasField('early_release') || $node->get('early_release')->isEmpty() || !$node->get('early_release')->value) {
        return $build;
      }

      $atomxClient = \Drupal::service('hwphp.atomx');
      $search_query = '{
        "size": 5000,
        "_source": ["apath"],
        "query": {
          "bool": {
            "filter": [{
              "term": { "early-release": true }
            },
            {
              "term": { "version-current" : true }
            },
            {
              "term": {"content-type": "periodical-article"}
            }]
          }
        },
        "sort": [
          { "date-epub.value": { "order": "desc" } }
        ]
      }';
      
      // Define AtomX index based on content settings.
      $content_config = \Drupal::service('config.factory')->get('highwire_content.settings')->get('highwire_content_configuration');
      $index = NULL;
      foreach ($content_config['content'] as $policy => $corpus) {
        $index = $policy . ':' . $corpus[0];
        break;
      }
      if (!$index) {
        // Handle if no index is found.
        return $build;
      }
      // Set indexes for AtomX client.
      $atomxClient->setIndexes([$index]);
      $elastic_results = $atomxClient->search($search_query);
      $lookup = \Drupal::service('highwire_content.lookup');
      $items = [];
      // Attempt to retrieve the journal node ID
      foreach ($elastic_results as $elastic_result) {
        try{
          $nid = $lookup->nidFromApath($elastic_result['apath']);
        }
        catch (\Exception $e) {
          // Handle the exception for nidFromApath
          \Drupal::logger('custom_module')->error('Error retrieving journal nid: @message', ['@message' => $e->getMessage()]);
          $nid = NULL; // Set to NULL or handle as needed
        }
        $items[] = [
          'nid' => $nid,
          'apath' => $elastic_result['apath'],
        ];
      }
      // Now find current nid and Prev and next nids in list.
      $target_nid = $node->id();
      $previous_item = null;
      $next_item = null;
      $found_item = null;
      foreach ($items as $index => $item) {
        if ($item['nid'] == $target_nid) {
          $found_item = $item;

          // next if exists
          if ($index > 0) {
            $next_item = $items[$index - 1];
          }

          // previous if exists
          if (isset($items[$index + 1])) {
            $previous_item = $items[$index + 1];
          }
          break;
        }
      }
      $prev_nid = $previous_item['nid'] ?? null;
      $next_nid = $next_item['nid'] ?? null;
      if (!$node->get('version_current')->value){
        $prev_nid = null;
        $next_nid = end($items)['nid'] ?? null;
        reset($items);
      }
    }
    else{
      // Get apath of context node.
      
      $current_apath = $node->get('apath')->getString();
      $parent_nid = $node->get('parent')->getString();
      
      if ($parent_nid) {
        $parent_node = \Drupal::entityTypeManager()->getStorage('node')->load($parent_nid);
      }
      $children = [];
      if($node->bundle() == 'journal_issue') {
        $prev_nid_issue = $node->get('prev_atom')->isEmpty() ? NULL : $node->get('prev_atom')->target_id;
        if($prev_nid_issue){
          $prev_nid = $prev_nid_issue;
        }else{
          $prev_nid = NULL;

          if ($parent_node->bundle() == 'journal'){
            foreach ($parent_node->get('children')->getValue() as $childval) {
              $children[] = $childval['apath'];
            }
            foreach ($children as $key => $apath) {
              if ($apath == $current_apath) {
                if ($key != 0) {
                  try {
                    $prev_nid_volume = $this->hwContentLookup->nidFromApath($children[$key-1]);
                    $prev_pub = \Drupal::entityTypeManager()->getStorage('node')->load($prev_nid_volume);
                    
                    if ($prev_pub->bundle() == 'journal_volume'){
                      $jnl_vol_pre = $prev_pub->get('children')->getValue();
                      $prev_nid = end($jnl_vol_pre)['target_id'];
                    }
                  }
                  catch (ApathNotFoundException $e) {
                  }
                }
              }
            }
          }
          elseif($parent_node->bundle() == 'journal_volume') {
            foreach ($parent_node->get('children')->getValue() as $childval) {
              $children[] = $childval['apath'];
            }
            if ($current_apath == reset($children)){
              $parent_apath = $parent_node->get('apath')->getString();
              $volume_parent = $parent_node->get('parent')->getString();
              if ($volume_parent) {
                $volume_parent_node = \Drupal::entityTypeManager()->getStorage('node')->load($volume_parent);
                $vol_children = $volume_parent_node->get('children')->getValue();
                
                foreach ($vol_children as $key => $vol_childval) {
                  if ($vol_childval['apath'] == $parent_apath ){
                    if ($key != 0) {
                      $prev_nid_volume = $this->hwContentLookup->nidFromApath($vol_children[$key-1]['apath']);
                      $prev_pub_volume = \Drupal::entityTypeManager()->getStorage('node')->load($prev_nid_volume);
                      
                      if ($prev_pub_volume->bundle() == 'journal_volume'){
                        $jnl_vol_prev = $prev_pub_volume->get('children')->getValue();
                        $last_child = end($jnl_vol_prev);
                        $prev_nid = $last_child['target_id'] ?? NULL;
                      }
                      if ($prev_pub_volume->bundle() == 'journal_issue'){
                        $prev_nid = $prev_pub_volume->id();
                      }
                    }
                  }
                }
              }
            }  
          }
          else{
            $prev_nid = NULL;
          }
          
        }
        $next_nid_issue = $node->get('next_atom')->isEmpty() ? NULL : $node->get('next_atom')->target_id;
        if($next_nid_issue){
          $next_nid = $next_nid_issue;
        }else{
          $next_nid = NULL;
          if ($parent_node->bundle() == 'journal'){
            foreach ($parent_node->get('children')->getValue() as $childval) {
              $children[] = $childval['apath'];
            }
            foreach ($children as $key => $apath) {
              if ($apath == $current_apath) {
                if ($key != 0) {
                  try {
                    $next_nid_volume = $this->hwContentLookup->nidFromApath($children[$key+1]);
                    $next_pub = \Drupal::entityTypeManager()->getStorage('node')->load($next_nid_volume);
                    
                    if ($next_pub->bundle() == 'journal_volume'){
                      $jnl_vol_next = $next_pub->get('children')->getValue();
                      $first_child = reset($jnl_vol_next);
                      $next_nid = $first_child['target_id'] ?? NULL;
                    }
                  }
                  catch (ApathNotFoundException $e) {
                  }
                }
              }
            }
          }
          elseif($parent_node->bundle() == 'journal_volume') {
            foreach ($parent_node->get('children')->getValue() as $childval) {
              $children[] = $childval['apath'];
            }
            if ($current_apath == end($children)){
              $parent_apath = $parent_node->get('apath')->getString();
              $volume_parent = $parent_node->get('parent')->getString();
              if ($volume_parent) {
                $volume_parent_node = \Drupal::entityTypeManager()->getStorage('node')->load($volume_parent);
                $vol_children = $volume_parent_node->get('children')->getValue();
                foreach ($vol_children as $key => $vol_childval) {
                  if ($vol_childval['apath'] == $parent_apath ){
                    if ($key != 0) {
                      $next_nid_volume = $this->hwContentLookup->nidFromApath($vol_children[$key+1]['apath']);
                      $next_pub_volume = \Drupal::entityTypeManager()->getStorage('node')->load($next_nid_volume);
                      
                      if ($next_pub_volume->bundle() == 'journal_volume'){
                        $jnl_vol_next = $next_pub_volume->get('children')->getValue();
                        $first_child = reset($jnl_vol_next);
                        $next_nid = $first_child['target_id'] ?? NULL;
                      }
                      if ($next_pub_volume->bundle() == 'journal_issue'){
                        $next_nid = $next_pub_volume->id();
                      }
                    }
                  }
                }
              }
            }  
          }
          else{
            $next_nid = NULL;
          }
        }
      }
      else {
        if ($parent_node && $parent_node->hasField('children')) {
          $children_parent = $parent_node->get('children')->getValue();
          usort($children_parent, function($a, $b) {
            preg_match('/\.(\d+)\.atom$/', $a['apath'], $matchA);
            preg_match('/\.(\d+)\.atom$/', $b['apath'], $matchB);
            return intval($matchA[1]) <=> intval($matchB[1]);
          });
          
          $min = reset($children_parent);
          $max = end($children_parent);
          $modified_apath = '';
          foreach ($children_parent as $pkey => $child) {
            $type = $this->hwContentLookup->getTypeFromNID($child['target_id']);
            if (($type !== 'item_cover') && ($type !== 'journal_fragment')) {
              $children[] = $child['apath'];
            }
            if ($type == "journal_article") {
            if(($min['apath'] == $current_apath) && ($min['target_id'] == $child['target_id'])) {
                $condi = 'prev';
                $modified_apath = $parent_node->get('apath')->getString();
            } elseif (($max['apath'] ==  $current_apath) &&  ($max['target_id'] == $child['target_id'])) {
              $condi = 'next';
              $modified_apath = $parent_node->get('apath')->getString();
            }
          } elseif (($type == 'journal_fragment') && ($pkey == 0)) {
              if ($parent_node->hasField('prev_atom') && !$parent_node->get('prev_atom')->isEmpty()) {
                $prevAtom_issue = $parent_node->get('prev_atom')->getValue()[0]['target_id'];
                $prevAtomnode = \Drupal::entityTypeManager()->getStorage('node')->load($prevAtom_issue);
                if (($prevAtomnode instanceof \Drupal\node\NodeInterface) && ($prevAtomnode->hasField('children')) ){
                  $prevAtomlast = $prevAtomnode->get('children')->getValue();
                  $lastarticle =  end($prevAtomlast);
                  $nonfragprev = $lastarticle['target_id'];
                  $voltype = $this->hwContentLookup->getTypeFromNID($nonfragprev);
                  if ($voltype == "journal_fragment") {
                    foreach($prevAtomlast as $volkey => $maxchild) {
                      if ($maxchild['target_id'] == $nonfragprev) {
                        $currentKey = $volkey;
                      }
                    }
                    $nonfragprev = $prevAtomlast[$currentKey-1]['target_id'];
                  }
                }
              } else {
                  if ($parent_node->hasField('parent_journal') && !$parent_node->get('parent_journal')->isEmpty()) {
                      $current_vol = $parent_node->get('parent_volume')->getValue()[0]['target_id']; 
                    $prtJournal = $parent_node->get('parent_journal')->getValue()[0]['target_id'];
                      $prejnlAtom = \Drupal::entityTypeManager()->getStorage('node')->load($prtJournal);
                      if (($prejnlAtom instanceof \Drupal\node\NodeInterface) && ($prejnlAtom->hasField('children')) ){
                      $prevVolAtomlast = $prejnlAtom->get('children')->getValue();
                      foreach($prevVolAtomlast as $fkey => $eachvol) {
                          if ($eachvol['target_id'] == $current_vol ) {
                            $current_key = $fkey;
                            break;
                          }
                        }
                        $prevol = $prevVolAtomlast[$current_key - 1]['target_id'];

                        $prevVolNode = \Drupal::entityTypeManager()->getStorage('node')->load($prevol);
                        if ( $prevVolNode instanceof \Drupal\node\NodeInterface && $prevVolNode->hasField('children') && !$prevVolNode->get('children')->isEmpty()) {
                          $prevNodelast = $prevVolNode->get('children')->getValue();
                          $prevVoldlastIssue = end($prevNodelast);
                          $prevVolNodeart = \Drupal::entityTypeManager()->getStorage('node')->load($prevVoldlastIssue['target_id']);
                          if ($prevVolNodeart instanceof \Drupal\node\NodeInterface  && $prevVolNodeart->hasField('children') && !$prevVolNodeart->get('children')->isEmpty()) {
                            $prevIssueArt = $prevVolNodeart->get('children')->getValue();
                            $prevnidlastIssue = end($prevIssueArt);
                            $prevVollastnid = $prevnidlastIssue['target_id'];
                            $lastKey = count($prevIssueArt) - 1;
                            $voltype = $this->hwContentLookup->getTypeFromNID($prevnidlastIssue['target_id']);
                            if ($voltype == "journal_fragment") {
                              $prevVollastnid = $prevIssueArt[$lastKey-1]['target_id'];
                            }
                          }
                        }
                      }
                  }
              }
          }
          elseif (($type == 'journal_fragment') && ($pkey > 0)) {
            if ($parent_node->hasField('next_atom') && !$parent_node->get('next_atom')->isEmpty()) {
              $prevAtom_issue = $parent_node->get('next_atom')->getValue()[0]['target_id'];
              $prevAtomnode = \Drupal::entityTypeManager()->getStorage('node')->load($prevAtom_issue);
              if (($prevAtomnode instanceof \Drupal\node\NodeInterface) && ($prevAtomnode->hasField('children')) ){
                $prevAtomlast = $prevAtomnode->get('children')->getValue();
                $lastarticle =  reset($prevAtomlast);
                $nonfragprevnext = $lastarticle['target_id'];
                $voltype = $this->hwContentLookup->getTypeFromNID($nonfragprevnext);
                    if ($voltype == "journal_fragment") {
                      foreach($prevAtomlast as $volkey => $maxchild) {
                        if ($maxchild['target_id'] == $nonfragprevnext) {
                          $currentKey = $volkey;
                        }
                      }
                      $nonfragprevnext = $prevAtomlast[$currentKey + 1]['target_id'];
                    }
              }
            } else {
                if ($parent_node->hasField('parent_journal') && !$parent_node->get('parent_journal')->isEmpty()) {
                    $current_vol = $parent_node->get('parent_volume')->getValue()[0]['target_id']; 
                    $prtJournal = $parent_node->get('parent_journal')->getValue()[0]['target_id'];
                    $prejnlAtom = \Drupal::entityTypeManager()->getStorage('node')->load($prtJournal);
                    if (($prejnlAtom instanceof \Drupal\node\NodeInterface) && ($prejnlAtom->hasField('children')) ){
                    $prevVolAtomlast = $prejnlAtom->get('children')->getValue();
                    foreach($prevVolAtomlast as $fkey => $eachvol) {
                        if ($eachvol['target_id'] == $current_vol ) {
                          $current_key = $fkey;
                          break;
                        }
                      }
                      $prevol = $prevVolAtomlast[$current_key + 1]['target_id'];
                      $prevVolNode = \Drupal::entityTypeManager()->getStorage('node')->load($prevol);
                      if ($prevVolNode instanceof \Drupal\node\NodeInterface  && $prevVolNode->hasField('children') && !$prevVolNode->get('children')->isEmpty()) {
                        $prevNodelast = $prevVolNode->get('children')->getValue();
                        $prevVoldlastIssue = reset($prevNodelast);
                        $prevVolNodeart = \Drupal::entityTypeManager()->getStorage('node')->load($prevVoldlastIssue['target_id']);
                        if ($prevVolNodeart instanceof \Drupal\node\NodeInterface  && $prevVolNodeart->hasField('children') && !$prevVolNodeart->get('children')->isEmpty()) {
                          $prevIssueArt = $prevVolNodeart->get('children')->getValue();
                          $current_key = null;
                          foreach($prevIssueArt as $nkey => $nexty) {
                            $voltype = $this->hwContentLookup->getTypeFromNID($nexty['target_id']);
                            if ($voltype == 'journal_fragment') {
                                $currentkey = $nkey;
                                break;
                            }
                          }
                          $nextVollastnid = $prevIssueArt[$currentkey + 1]['target_id'];
                        }
                      }
                    }
                }
            }
          }
          }
          $parent_vol = $parent_node->get('parent_volume')->entity;
          if (($parent_vol instanceof \Drupal\node\NodeInterface) && ($parent_vol->hasField('children'))) {
            $parchild =  $parent_vol->get('children');
            foreach($parchild as $vkey => $volchild) {
            if ($modified_apath === $volchild->getValue()['apath']){
              if($condi == 'prev') {
                if ($vkey > 0 && isset($parchild[$vkey - 1])) {
                  $prev_issue = $parchild[$vkey - 1]->getValue()['target_id'];
                  $change_issues = \Drupal::entityTypeManager()->getStorage('node')->load($prev_issue);
                  if($change_issues->hasField('children')){
                    $volmax = end($change_issues->get('children')->getValue());
                    $volmaxchild = $change_issues->get('children')->getValue();
                    $change_issue_nid = $volmax['target_id'];
                    $voltype = $this->hwContentLookup->getTypeFromNID($change_issue_nid);
                    if ($voltype == "journal_fragment") {
                      foreach($volmaxchild as $volkey => $maxchild) {
                        if ($maxchild['target_id'] == $change_issue_nid) {
                          $currentKey = $volkey;
                        }
                      }
                      $change_issue_nid = $volmaxchild[$currentKey - 1]['target_id'];
                    }
                  }
                } else {
                  $grand_vol = $parent_vol->get('parent')->entity;
                  if ($grand_vol->hasField('children')) {
                    $prapath = $parent_vol->get('apath')->getString();
                    $grand_vols = $grand_vol->get('children')->getValue();
                    $endvol = reset($grand_vols);
                    foreach($grand_vols as $keys => $grand_dots) {
                      if (($grand_dots['apath'] === $prapath) && ($grand_dots['apath'] !== $endvol['apath']) ) { 
                        $nextvol_tid = $grand_vols[$keys-1]['target_id'];
                        $change_vol = \Drupal::entityTypeManager()->getStorage('node')->load($nextvol_tid);
                        if($change_vol->hasField('children')){
                          $last_issue = end($change_vol->get('children')->getValue());
                          $last_art = \Drupal::entityTypeManager()->getStorage('node')->load($last_issue['target_id']);

                          if ($last_art->hasField('children')) {
                            $child_art_last = end($last_art->get('children')->getValue());
                            $parent_vol_nid = $child_art_last['target_id'];
                          }
                        }
                      }
                    }
                  }
                }
              }
              if ($condi == 'next') {
                $pcount = count($parchild);
                if ($vkey <= $pcount  && isset($parchild[$vkey + 1])) {
                  $prev_issue = $parchild[$vkey+1]->getValue()['target_id'];
                  $change_issues = \Drupal::entityTypeManager()->getStorage('node')->load($prev_issue);
                  if($change_issues->hasField('children')){
                    $volmax = reset($change_issues->get('children')->getValue());
                    $volmaxchild = $change_issues->get('children')->getValue();
                    $next_issue_nid = $volmax['target_id'];
                    $voltype = $this->hwContentLookup->getTypeFromNID($next_issue_nid);
                    if ($voltype == "journal_fragment") {
                      foreach($volmaxchild as $volkey => $maxchild) {
                        if ($maxchild['target_id'] == $next_issue_nid) {
                          $currentKey = $volkey;
                        }
                      }
                      $next_issue_nid = $volmaxchild[$currentKey + 1]['target_id'];
                    }
                  }
                } else {
                  $grand_vol = $parent_vol->get('parent')->entity;
                  if ($grand_vol->hasField('children')) {
                    $prapath = $parent_vol->get('apath')->getString();
                    $grand_vols = $grand_vol->get('children')->getValue();
                    $endvol = end($grand_vols);
                    foreach($grand_vols as $keys => $grand_dots) {
                      if (($grand_dots['apath'] === $prapath) && ($endvol['apath'] != $grand_dots['apath'])) {
                        $nextvol_tid = $grand_vols[$keys+1]['target_id'];
                        $change_vol = \Drupal::entityTypeManager()->getStorage('node')->load($nextvol_tid);
                        if($change_vol->hasField('children')){
                          $last_issue = reset($change_vol->get('children')->getValue());
                          $last_art = \Drupal::entityTypeManager()->getStorage('node')->load($last_issue['target_id']);
                          if ($last_art->hasField('children')) {
                            $child_art_last = reset($last_art->get('children')->getValue());
                            $childgroup = $last_art->get('children')->getValue();
                            $next_vol_nid = $child_art_last['target_id'];
                            $voltype = $this->hwContentLookup->getTypeFromNID($next_vol_nid);
                            if ($voltype  == "journal_fragment") {
                              foreach($childgroup as $lkey => $last_art_each) {
                                if ($last_art_each['target_id'] == $next_vol_nid) {
                                    $currentKey = $lkey;  
                                }
                              }
                              $next_vol_nid = $childgroup[$currentKey + 1]['target_id']; 
                            }
                          }
                        }
                      }
                    }
                  }
                }
              }
              }
            }
          }
        }
      }
      
    }

    

    // Build render array with empty links as default values.
    $build += [
      '#theme' => 'prevnext_toc_pager',
      '#previous_url' => '',//Url::fromRoute('<nolink>'),
      '#next_url' => '',//Url::fromRoute('<nolink>'),
      '#previous_title' => '',
      '#next_title' => '',
    ];

    // Add url for previous node.
    if ($prev_nid) {
      $build['#previous_url'] = $this->getItemUrl($prev_nid);
    } elseif(!empty($change_issue_nid) && empty($prev_nid)) {
      $prev_nid = $change_issue_nid;
      $build['#previous_url'] = $this->getItemUrl($prev_nid);
    } elseif (empty($change_issue_nid) && empty($prev_nid) && !empty($parent_vol_nid)) {
      $prev_nid = $parent_vol_nid;
      $build['#previous_url'] = $this->getItemUrl($prev_nid);
    } elseif (empty($change_issue_nid) && empty($prev_nid) && empty($parent_vol_nid) && !empty($nonfragprev)) {
      $prev_nid = $nonfragprev;
      $build['#previous_url'] = $this->getItemUrl($prev_nid);
    }elseif (empty($change_issue_nid) && empty($prev_nid) && empty($parent_vol_nid) && empty($nonfragprev) && !empty($prevVollastnid)) {
      $prev_nid = $prevVollastnid;
      $build['#previous_url'] = $this->getItemUrl($prev_nid);
    }
    if ($prev_nid) {
      $build['#previous_title'] = $this->getNodeNavigationTitle($prev_nid);
    }
    // Add url for next node.
    if ($next_nid) {
      $build['#next_url'] = $this->getItemUrl($next_nid);
    } elseif (!empty($next_issue_nid) && empty($next_nid)) {
      $next_nid = $next_issue_nid;
      $build['#next_url'] = $this->getItemUrl($next_nid);
    } elseif (!empty($next_vol_nid) && empty($next_nid) && empty($next_issue_nid)) {
      $next_nid = $next_vol_nid;
      $build['#next_url'] = $this->getItemUrl($next_nid);
    }elseif (empty($next_vol_nid) && empty($next_nid) && empty($next_issue_nid) && empty($next_vol_nid) && !empty($nonfragprevnext)) {
      $next_nid = $nonfragprevnext;
      $build['#next_url'] = $this->getItemUrl($next_nid);
    }elseif (empty($next_vol_nid) && empty($next_nid) && empty($next_issue_nid) && empty($next_vol_nid) && empty($nonfragprevnext) && !empty($nextVollastnid) ) {
      $next_nid = $nextVollastnid;
      $build['#next_url'] = $this->getItemUrl($next_nid);
    }
    if ($next_nid) {
      $build['#next_title'] = $this->getNodeNavigationTitle($next_nid);
    }

    $route_name = $this->routeMatch->getRouteName();
    return $build;
  }

  /**
   * Get the url for a pager item.
   *
   * @param string $nid
   *   The node id of the item for which to get the Url.
   *
   * @return \Drupal\Core\Url
   *   A Url object for the given item nid.
   */
  protected function getItemUrl($nid) {
    if ($this->routeMatch->getRouteName() == 'highwire_entity_view') {
      $route_name = $this->routeMatch->getRouteName();
      $route_params = $this->routeMatch->getParameters()->all();
      $route_params['entity_id'] = $nid;
    }
    else {
      $route_name = 'entity.node.canonical';
      $route_params = ['node' => $nid];
    }
    return Url::fromRoute($route_name, $route_params);
  }
  protected function getNodeNavigationTitle($nid) {
    $title = '';
  
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $node = $node_storage->load($nid);
  
    if (!$node) {
      return $title;
    }
 
    // Check if node type is 'journal_issue'
    if ($node->getType() === 'journal_issue') {
      $volume = '';
      $issue = '';
      $journal_title = '';
  
      $volume_field = $node->get('volume');
      if (!$volume_field->isEmpty()) {
        $volume = $volume_field->value;
      }
  
      $issue_field = $node->get('issue');
      if (!$issue_field->isEmpty()) {
        $issue = '(' . $issue_field->value . ')';
      }
  
      $journal_title_field = $node->get('journal_title');
      if (!$journal_title_field->isEmpty()) {
        $journal_title = $journal_title_field->value;
      }
  
      $title = "{$journal_title}: {$volume} {$issue}";
    }
    else {
      // Default to node title for all other content types
      $title_field = $node->get('title');
      if (!$title_field->isEmpty()) {
        $title = $title_field->value;
      }
    }
  
    return $title;
  }
  
}
