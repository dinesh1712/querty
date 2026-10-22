<?php

namespace Drupal\jnl_core\Plugin\HWClient;

use Drupal\highwire_client\ConfigurableClientBase;
use HighWire\Clients\ClientFactory;
use Drupal\Core\Form\FormStateInterface;
use Drupal\highwire_client\ClientFactory as DrupalClientFactory;
use Psr\Log\LoggerInterface;

/**
 * HWAlerts plugin for alerts service.
 *
 * This plugin overrides the derivative provided by highwire_hwphp.
 *
 * @HWClient(
 *   id = "hwphpclient:legacyfs_rss",
 *   label = @Translation("HW Legacy rss"),
 *   description = @Translation("HW Legacy Fs Rss"),
 *   timeout = 5,
 * )
 */
class HWLegacyfsRss extends ConfigurableClientBase {

  /**
   * The HWPHP Client Config Array.
   *
   * @var array
   */
  protected $clientConfig;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerInterface $logger,
    DrupalClientFactory $client_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger, $client_factory);
    $this->clientConfig = ClientFactory::getClientConfig('legacyfs_rss');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnvironmentBaseUrls() {
    return $this->clientConfig['environmentBaseUrls'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultTimeout() {
    return !empty($this->clientConfig['timeout']) ? $this->clientConfig['timeout'] : parent::getDefaultTimeout();
  }

  /**
   * {@inheritdoc}
   */
  public function getClient(array $config = []) {
    return ClientFactory::get('legacyfs_rss', $config);
  }

}
