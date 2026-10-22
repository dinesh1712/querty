<?php

namespace Drupal\jnl_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use HighWire\Clients\HWAlerts\HWAlerts;
use HighWire\Clients\ClientFactory;
use Symfony\Component\HttpFoundation\Response;

class JnlRssXml extends ControllerBase {

    public function RssXml($path) {
        $site_data = \Drupal::config("highwire_content.site_data")->get( "highwire_content_site_data" );
        $corpus = array_key_first($site_data);
        $uri = '/' . $corpus . '/legacyrss/' . $path;
        $client = ClientFactory::get("legacyfs_rss");
        $data = $client->LegacyfsRssAsync($uri);  
        $data = '';
        if(!empty($data)){
           $xml_content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml_content .= $data;
               // Create the Response object with XML content
            $response = new Response($xml_content);

            // Set the response header to indicate XML content
            $response->headers->set('Content-Type', 'application/xml');
            return $response;
        }
        
        return [
            '#markup' => "<h1>Page Not Found</h1>",
        ];
    }
}