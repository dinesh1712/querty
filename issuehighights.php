<?php
/**
 * @file
 * Contains \Drupal\journal_article_detail\Plugin\Block\CurrentIssuewithHighlights.
 */

namespace Drupal\journal_article_detail\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use Drupal\views\Element\View;
use Drupal\views\Views;

/**
 * Provides a block with the current journal issue details with highlights.
 *
 * @Block(
 *   id = "jnl_current_issue_highlights",
 *   admin_label = @Translation("Current Issue with Highlights"),
 *   category = @Translation("jnl"),
 * )
 */
class CurrentIssuewithHighlights extends BlockBase {
  /**
   * {@inheritdoc}
   */
  public function build() {
    $adminHeading = $this->configuration['label'];
    $build = [];
    $config = $this->getConfiguration();
    $node = \Drupal::routeMatch()->getParameter('node');
    $nid = '';
    // Determine the node ID based on the current route
    if ($node instanceof \Drupal\node\NodeInterface) {
      if ($node->bundle() == 'journal_issue') {
        $nid = $node->id();
      } elseif ($node->bundle() == 'journal_article') {
        $nid = $node->parent_issue->target_id;
      }
    }
    $request = \Drupal::request();
    $path_args = explode('/', trim($request->getPathInfo(), '/'));
    $volume = $path_args[1];
    $issue = $path_args[2];
    $index_author = $path_args[3];

    // If no node ID found, lookup the current issue node ID
    if (empty($nid)) {
      $lookup = \Drupal::service('highwire_content.lookup');
      $site_data = \Drupal::config('highwire_content.site_data')->get('highwire_content_site_data');
      $corpus = array_key_first($site_data);
      if ($corpus) {
        $corpus_apath = '/' . $corpus . '.atom';
        try {
            // Attempt to retrieve the journal node ID
            $journal_nid = $lookup->nidFromApath($corpus_apath);

            // Attempt to retrieve the current issue node ID
            try {
                $nid = $lookup->getCurrentIssueNidFromJournalNid($journal_nid);
            } catch (\Exception $e) {
                // Handle the exception for getCurrentIssueNidFromJournalNid
                \Drupal::logger('custom_module')->error('Error retrieving current issue nid: @message', ['@message' => $e->getMessage()]);
                $nid = NULL; // Set to NULL or handle as needed
            }
        } catch (\Exception $e) {
            // Handle the exception for nidFromApath
            \Drupal::logger('custom_module')->error('Error retrieving journal nid: @message', ['@message' => $e->getMessage()]);
            $nid = NULL; // Set to NULL or handle as needed
        }
      }
    }
    // If still no node ID found, return an empty build
    if (empty($nid)) {
      return $build;
    }
    // Load the current issue node
    $issue_node = Node::load($nid);
    // Get title for current issue block
    $prefix_title = $issue_node->get('journal_title')->getValue();
    $title = $prefix_title['0']['value'];
    $href = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $nid);
    $img_src = '';
    // Get the cover image URL
    $img_id = $issue_node->get('variant_cover_image')->target_id;
    if(isset($config['cover_photo']) && $config['cover_photo'] == 1){
      if (!empty($img_id)) {
        $file = File::load($img_id);
        if (is_object($file)) {
          $uri = $file->getFileUri();
          $img_src = \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
        }
      }
    }
    
    $pub_date = '';
    if(!empty($issue_node->cover_date->getValue())){
      $pub_date = $issue_node->cover_date->getValue()[0]['value'];
    }
    else{
      // Get the publication date
      $date_published = '';
      if (!empty($issue_node->date_ppub->getValue())) {
        $date_published = $issue_node->date_ppub->getValue()[0]['original'];
      } else {
        $date_published = $issue_node->date_epub_version->getValue()[0]['original'];
      }

      if(!empty($date_published)){
        $date_time = new \DateTime($date_published);
        $drupal_date_time = DrupalDateTime::createFromDateTime($date_time);
        $pub_date = $drupal_date_time->format('d M Y');
      }

    }
    $volume_id = $issue_node->get('volume')->value;
    $issue_id = $issue_node->get('issue')->value;
    // Prepare card description
    $card_des1 = '(Vol. ' . $issue_node->get('volume')->value . ' Issue ' . $issue_node->get('issue')->value .')';
    $card_des2 = $pub_date;
    $card_description = [
      ['paragraph' => ['text' => $card_des2 . ' ' .$card_des1]],
      // ['paragraph' => ['text' => $card_des1]],
    ];
    // Prepare card link
    $card_link = [
      [
        'href' => $href . '/',
        'text' => 'Table of Contents',
      ],
    ];

    // Isset block cover Photo
    // if(isset($config['cover_photo']) && $config['cover_photo'] == 1) {
    //   $entity_ids = $issue_node->get('variant_cover_image')->target_id;
    //   $cover_file = File::load($entity_ids);
    //   if(is_object($cover_file)){
    //     $file_uri = $cover_file->getFileUri();
    //     $page_url = '/content/' .$volume_id .'/' . $issue_id . '/cover-expansion';
    //     // $file_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file_uri);
    //     $cover_photo_title = 'Cover (Photo)';
        
    //     if(isset($config['cover_photo_title']) && !empty($config['cover_photo_title'])) {
    //       $cover_title = $config['cover_photo_title'];
    //     }
    //     $cover_picture = [
    //       'href' =>  $page_url,
    //       'text' => $cover_photo_title,
    //     ];
    //     array_push($card_link, $cover_picture);
    //   }
    // }
    
    // Check if "Issue Highlights" is enabled
    if (isset($config['issue_highlights']) && $config['issue_highlights'] == 1) {
      
      $view = Views::getView('related_article');
      if ($view) {
        // Set up the view for block_1 display.
        $view->setDisplay('block_2');
        $contextual_filters = [$nid];
        $view->setArguments($contextual_filters);
        // Build the renderable array.
        $result = $view->buildRenderable('block_2');

        // Pre-render the view element.
        $output = View::preRenderViewElement($result);

        // If view_build is empty, set output cache.
        if (empty($output['view_build'])) {
          $output = ['#cache' => $output['#cache']];
        }
      }

      // Prepare the current issue block content
      $issue_data = [
        'title' => 'Issue highlights',
        'output' => $output,
      ];
    }
    // Prepare card heading lin
    $card_heading_link = [
      ['anchorHead' => [
        'href' => $href,
        'title' => $title,
        'text' => $title,
      ]],
    ];

    // Prepare card list
    $card_list = [
      [
        'leftimage' => [
          'href' => $href,
          'src' => $img_src,
          'alt' => $title,
          'title' => $title,
          'class' => 'img-fluid',
        ],
        // 'card_heading_link' => $card_heading_link,
        'card_description' => $card_description,
        'card_link' => $card_link,
      ],
    ];
   
    // Prepare the current issue block content
    $current_issue = [
      'card_title' => ['text' => 'Current Issue'],
      'card_list' => $card_list,
    ];
    // Build the render array
    $build = [
      '#theme' => 'current_issue_highlights',
      '#current_issue' => $current_issue,
      '#issue_data' => $issue_data,
    ];
    
    return $build;
  }
  
  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    // Retrieve the current configuration for the block.
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();
    
    // Add form fields for configuration settings.
    
    
    
    $form['cover_photo'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cover Photo'),
      '#description' => $this->t('Select the check box to use for display the Cover Photo link.'),
      '#default_value' =>!empty($config['cover_photo']) ? $config['cover_photo'] : 0,
    ];

    $form['issue_highlights'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Issue Highlights'),
      '#description' => $this->t('Check this box to display the Issue Highlights view.'),
      '#default_value' => !empty($config['issue_highlights']) ? $config['issue_highlights'] : 0,
    ];    
    
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    // Save the configuration settings when the form is submitted.
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    //Save the value of Table title and Table pdf
    
    $this->configuration['cover_photo'] = $values['cover_photo'];
    $this->configuration['issue_highlights'] = $values['issue_highlights'];
 }
}
