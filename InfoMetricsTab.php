<?php
/**
 * @file
 * Contains \Drupal\journal_article_detail\Plugin\Block\InfoMetricsTab.
 */
namespace Drupal\journal_article_detail\Plugin\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\Component\Utility\Html;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a block that displays Info & Metrics tab: JCOREX-102
 *
 * @Block(
 *  id = "jnl_info_metrics_tab",
 *  admin_label = @Translation("Info & Metrics"),
 *  context_definitions = {
 *    "node" = @ContextDefinition(
 *      "entity:node",
 *      label = @Translation("Current Node")
 *    )
 *  }
 * )
 */
class InfoMetricsTab extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The node the block is displayed on.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected $contextNode;
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
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The currently active route match object.
   */

  
  protected $requestStack;

  /**
   * Constructor to inject both services.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->requestStack = $request_stack;
  }

 /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),  // Inject RouteMatch
      $container->get('request_stack')  // Inject RequestStack
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    try {
      // Attempt to retrieve the node object from the context.
      $node = $this->getContextValue('node');
    }
    catch (\Exception $e) {
      // Return an empty build array if the node context is not available.
      return $build;
    }
    // Journal author variables
    $authors = $node->get('authors_full_name')->getValue();
	  $authors_check = [];
    // Journal author name and author affilation store in array
    foreach ($authors as $key => $value) {
      // Check whather the author-aff is present or not
      if (array_key_exists('author-aff',$value)) {
        $authors_check[] = [
        'authorname' => $value['author-name'],
        'authoraff' => $value['author-aff']
        ];
      }
      // If not present then store author name in author affilation field
      else {
        $authors_check[] = [
        'authorname' => $value['author-name'],
        'authoraff' => $value['author-name']
        ];
      }
    }
	  $author_infotab_aff = [];
    // Author information affilation field
    foreach ($authors as $key => $value) {
      if (array_key_exists('author-aff',$value)) {
        $author_infotab_aff[$key] = $value['author-aff'];
      }
    }
    $default_article_config_form = \Drupal::config('journal_article_detail.settings');
   
    // Journal description variables
    $article_info['journal_doi'] = $node->get('doi')->getString();
    //New way to display DOI
    if (!empty($article_info['journal_doi'])) {
        $article_info['doi_link'] = 'https://doi.org/' . $article_info['journal_doi'];
    }
    // Retrieve journal title variables.
    $article_info['journal_title'] = $node->get('journal_title')->getValue()['0']['value'];
    // Retrieve eissn variables.
    $article_info['eissn'] = $node->get('issn_epub')->getString();
    // Retrieve pissn variables.
    if($default_article_config_form->get('print_issn') == 1){
      $article_info['pissn'] = $node->get('issn_ppub')->getString();
    }
    // Retrieve issue variables.
    $article_info['issue'] = $node->get('issue')->getValue();
    $article_info['issue'] =  $article_info['issue'][0]['value'];
    // Retrieve volume variables.
    $article_info['volume'] = !$node->get('volume')->isEmpty() ? $node->get('volume')->getString() : '';
    // Retrieve slug variables.
    $article_info['slug'] = !$node->get('slug')->isEmpty() ? $node->get('slug')->getString() : '';
    // Retrieve slug variables.
  
    $node_id = $node->get('parent_journal')->getString();
    $node_publisher_name = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
    
    if(($default_article_config_form->get('info_published') == 1)){
      $article_info['publisher_name'] = !empty($node_publisher_name->get('journal_publisher')->getString()) ? Html::decodeEntities($node_publisher_name->get('journal_publisher')->getString()) : '';
      $article_info['publisher_url'] = Url::fromRoute('<front>')->toString();
    }
    // Retrieve date released variables.
    // Article History Published Online date.
    $date_select = $default_article_config_form->get('date_select');

    $article_info['history_date_format'] = $default_article_config_form->get('history_date_format');
    $dates = [];
    if(!empty($date_select['epubdate'])){
      $date_epub_version = $node->get('date_epub_version')->getValue();
      $date_epub_original = $node->get('date_epub_original')->getValue();
      $date_epub = $node->get('date_epub')->getValue();
      $article_info['label_epubdate'] = $default_article_config_form->get('label_epubdate');
      
      $date_source = $date_epub_version[0]['original']
      ?? $date_epub[0]['original']
      ?? null;
    

      if (!empty($date_source)) {
          $date_format = $default_article_config_form->get('history_date_format') ?? 'F j Y';
          try {
              $datetime = new \DateTime($date_source);
              $dates['epubdate'] = $datetime->format($date_format);
          } catch (\Exception $e) {
              $dates['epubdate'] = '';
          }
      } else {
          $dates['epubdate'] = '';
      }
    }
    //Accepted and received label
    $article_info['label_received'] = $default_article_config_form->get('label_received');
    $article_info['label_accepted'] = $default_article_config_form->get('label_accepted');

    // Hide ppub date for early articles
    if ($get_early == '1') {
      unset($date_select['ppubdate']);
    }

    // print ppub if Available.
    if (!empty($date_select['ppubdate'])) {
      $date_ppub_release = $node->get('date_released')->getValue();
      $date_ppub = $node->get('date_ppub')->getValue();
      $article_info['label_ppubdate'] = $default_article_config_form->get('label_ppubdate');
    
      // Use null coalescing operator to pick the first available date
      $date_source = $date_ppub_release[0]['value']
        ?? $date_ppub[0]['value']
        ?? null;
    
      if (!empty($date_source)) {
        $date_format = $default_article_config_form->get('history_date_format') ?? 'F j, Y';
        try {
          $datetime = new \DateTime($date_source);
          $formatted_date = $datetime->format($date_format);
          $dates['ppubdate'] = $formatted_date;
        } catch (\Exception $e) {
          $dates['ppubdate'] = '';
        }
      } else {
        $dates['ppubdate'] = '';
      }
    }
    
    if(!empty($date_select['fpubdate'])){
      $date_epub_version = $node->get('date_epub_version')->getValue();
      $date_epub_original = $node->get('date_epub_original')->getValue();
      $date_epub = $node->get('date_epub')->getValue();
      $article_info['label_fpubdate'] = $default_article_config_form->get('label_fpubdate');
      
      $date_source = $date_epub_original[0]['original']
      ?? $date_epub_version[0]['original']
      ?? $date_epub[0]['original']
      ?? null;

      if (!empty($date_source)) {
          $date_format = $default_article_config_form->get('history_date_format') ?? 'F j Y';
          try {
              $datetime = new \DateTime($date_source);
              $dates['fpubdate'] = $datetime->format($date_format);
          } catch (\Exception $e) {
              $dates['fpubdate'] = '';
          }
      } else {
          $dates['fpubdate'] = '';
      }
    }
    $date_markup = '';
    $active_count = count(array_filter($date_select));

    if ($active_count == 3) {
      $dates = array_unique($dates);
      // If all dates are same the show only a date.
      if (count($dates) == 1) {
        $date_markup = '<li class="published"><span class="published-label">' . $default_article_config_form->get('label_combine') . '</span> ' . current($dates) . '.</li>';
      }
      // Otherwise show the common one.
      else {
        foreach ($dates as $key => $date) {
          if (!empty($dates)) {
            $label = 'label_' . $key;
            $date_markup .= '<li class="published"><span class="published-label">' .$default_article_config_form->get($label) . '</span> ' . $date . '.</li>';
          }
        }
      }
    }
    else{
      foreach ($dates as $key => $date) {
        if (!empty($dates)) {
          $label = 'label_' . $key;
          $date_markup .= '<li class="published"><span class="published-label">' .$default_article_config_form->get($label) . '</span> ' . $date . '.</li>';
        }
      }
    }
   
    $article_info['date_markup'] = $date_markup;
    // Retrieve copyright info variables.
    $article_info['copyright_info'] = $node->get('copyright_info')->getString();
    // Retrieve license info variables.
    $article_info['license_info'] = $this->convertExtLinksToHtml($node->get('license_info')->getString());
    // Retrieve date received variables.
    $article_info['date_received'] = $node->get('date_received')->getValue()['0']['original'];
    // Retrieve date Revision variables.
    $article_info['date_revision'] = $node->get('date_revision')->getValue()['0']['original'];
    // Retrieve date accepted variables.
    $article_info['date_accepted'] = $node->get('date_accepted')->getValue()['0']['original'];
    $article_info['section_heading'] = 'Article Information';
    // Retrieve First page variables.
    if ($node->hasField('fpage')) {
      $first_page = !$node->get('fpage')->isEmpty() ? $node->get('fpage')->getString() : '';
    }
    // Retrieve last page number variables.
    if ($node->hasField('lpage')) {
      $last_page = !$node->get('lpage')->isEmpty() ? $node->get('lpage')->getString() : '';
    }
    // Retrieve eissn url variables.
    if (!empty($article_info['eissn'])) {
      $article_info['eissn_url'] = 'http://www.worldcat.org/issn/' . $article_info['eissn'];
    }
    // Retrieve pissn url variables.
    if ($default_article_config_form->get('print_issn') == 1 && !empty($article_info['pissn'])) {
      $article_info['pissn_url'] = 'http://www.worldcat.org/issn/' . $article_info['pissn'];
    }
    // Retrieve page number variables.
    if (!empty($first_page) && !empty($last_page)) {
      $article_info['elocation_id_or_page_id'] = $first_page . "-" . $last_page;
    }
    else{
      $article_info['elocation_id_or_page_id'] = $article_info['slug'];
    }
    // Retrieve Pubmed variables.
    if ($default_article_config_form->get('info_pubmed') == 1 && $node->hasField('pubmed_id')) {
      $article_info['pubmed_id'] = !$node->get('pubmed_id')->isEmpty() ? $node->get('pubmed_id')->getString() : '';
      $article_info['pubmed_url'] = 'http://www.ncbi.nlm.nih.gov/pubmed/'.$article_info['pubmed_id'];
    }
    
    $link = null;
    $get_early = $node->get('early_release')->getValue();
    $early_release = $get_early[0]['value'];
    if ($early_release) {
      $latest_nid = $node->get('current_article')->target_id;
      if ($latest_nid != null) {
        $url = Url::fromRoute('entity.node.canonical', ['node' => $latest_nid]);
        $link = $url->toString();
      }
    }
    else {
     
      try {
        $cur_nid = $node->id(); 
        $apath = $node->get('apath')->getString();
        $atomxClient = \Drupal::service('hwphp.atomx');
        $search_query = '{
        "size": 5000,
        "_source": ["apath"],
        "query": {
          "bool": {
          "filter": [
          {
            "term": {"current-article.keyword": "'.$apath.'"}
          },
          {
            "term": {"content-type": "periodical-article"}
          }]
          }
        }
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
        return [];
        }
        // Set indexes for AtomX client.
        $atomxClient->setIndexes([$index]);
        $elastic_results = $atomxClient->search($search_query);
  
        $lookup = \Drupal::service('highwire_content.lookup');
        $apath_list = [];
        foreach ($elastic_results as $result) {
          if (!empty($result['apath'])) {
            $apath_list[] = $result['apath'];
          }
        }
      //   if (!empty($apath_list)) {
      //     // Use the first apath value.
      //     $first_apath = reset($apath_list);
      //     $anid = $lookup->nidFromApath($first_apath);
      //     $url = Url::fromRoute('entity.node.canonical', ['node' => $anid]);
      //     $link = $url->toString();
      //   }
      //   else {
      //     $link = null;
        
      // } 





      if (!empty($apath_list)) {
          $links = [];

          foreach ($apath_list as $apath) {
              $anid = $lookup->nidFromApath($apath);
              if (!empty($anid)) {
                  $url = Url::fromRoute('entity.node.canonical', ['node' => $anid])->toString();
                  $article_node = Node::load($anid);

                  $date_value = NULL;
                  if ($article_node) {
                      // Use date_epub_original first if available
                      if (!$article_node->get('date_epub_version')->isEmpty()) {
                        $field = $article_node->get('date_epub_version')->getValue()[0];
                        // Keep the exact stored timestamp with time
                        $date_value = $field['value']; // preserves stored time
                    }
                    elseif (!$article_node->get('date_epub_original')->isEmpty()) {
                          $field = $article_node->get('date_epub_original')->getValue()[0];
                          // Keep the exact stored timestamp with time
                          $date_value = $field['value']; // preserves stored time
                      }
                      // Otherwise fallback to date_epub
                      elseif (!$article_node->get('date_epub')->isEmpty()) {
                          $field = $article_node->get('date_epub')->getValue()[0];
                          $date_value = $field['value']; // preserves original datetime with timezone
                      }
                  }

                  $links[] = [
                      'nid' => $anid,
                      'url' => $url,
                      'date' => $date_value,
                  ];
              }
          }
        } else {
            $links = [];
        }
    }
      catch (\Exception $e) {
        // Log the exception and return an empty link.
        \Drupal::logger('journal_article_detail')->error($e->getMessage());
        $link = null;
      }
    }

    $version_date = NULL;
    if (!empty($links)) {
        // Sort links by date descending
        usort($links, function ($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
        });
        // Take the first (latest) date
        $version_date = $links[0]['date'];
    }
    // Fallback to current node date if no links
    if (!$version_date && !$node->get('date_epub')->isEmpty()) {
        $version_date = $node->get('date_epub')->getValue()[0]['value'];
    }

    $article_version_data = [];
    $default_article_config = \Drupal::config('journal_article_detail.settings');
     // For access article version values
    if(($default_article_config->get('article_version_checkbox') == 1))
    {
      $article_version_data = [
        'latest_article' =>  $links,
        'version_heading' => $default_article_config->get('article_version_heading'),
        'version_title' => $default_article_config->get('article_version_title'),
        'version_date_format' => $default_article_config->get('version_date_format'),
        'version_description' => $default_article_config->get('article_version_description'),
        'version_date' => $version_date,
        'url_check' => $early_release,
      ];
    }
    $build = [
        '#theme' => 'jnl_info_metrics_tab',
		    '#authors_check' => $authors_check,
		    '#author_info_tab' => $author_infotab_aff,
        '#article_info' => $article_info,
        '#article_version_data' => $article_version_data,
    ];
    return $build;
  }

  public function convertExtLinksToHtml($input) {
    // Define the regular expression pattern to match the <ext-link> element and capture its attributes and pattern
    $pattern = '/<ext-link[^>]+xlink:href="([^"]+)"[^>]*>(.*?)<\/ext-link>/';
    // Use preg_replace_callback to replace the matched <ext-link> with <a> tag.
    $output = preg_replace_callback($pattern, function ($matches) {
      $href = $matches[1]; // Capture the xlink:href attribute value.
      $text = $matches[2]; // Capture the inner text of the <ext-link> element.
      // Return the <a> tag replacement.
      return '<a href="' . $href . '">' . $text . '</a>';
    }, $input);
    return $output;
  }
}
