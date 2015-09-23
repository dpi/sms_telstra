<?php
/**
 * @file
 * Contains \Drupal\telstra_sms\Plugin\Gateway\Telstra
 */

namespace Drupal\telstra_sms\Plugin\Gateway;

use Drupal\sms\Gateway\GatewayBase;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\Component\Serialization\Json;

/**
 * @SmsGateway(
 *   id = "telstra",
 *   label = @Translation("Telstra"),
 *   configurable = false,
 * )
 */
class Telstra extends GatewayBase {

  /**
   * Construct a new Telstra plugin.
   *
   * @param array $configuration
   *   The configuration to use and build the SMS gateway.
   * @param string $plugin_id
   *   The gateway id.
   * @param mixed $plugin_definition
   *   The gateway plugin definition.
   */
//  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
//    parent::__construct($configuration, $plugin_id, $plugin_definition);
//  }

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms, array $options) {
    // Log sms message to drupal logger.
//    $this->logger()->notice('SMS message sent to %number with the text: @message',
//      ['%number' => implode(', ', $sms->getRecipients()), '@message' => $sms->getMessage()]);



    // Configuration
    // @todo move to module config.
    $base_uri = 'https://api.telstra.com/v1/';
    $client_id = '';
    $secret = '';
    $result = \Drupal::httpClient()->get($base_uri . 'oauth/token', [
      'query' => [
        'client_id' => $client_id,
        'client_secret' => $secret,
        'grant_type' => 'client_credentials',
        'scope' => 'SMS'
      ]
    ]);
    $response = Json::decode($result->getBody());
    $headers = [
      'Authorization' => 'Bearer '. $response->access_token,
      'Content-Type' => 'application/json',
    ];

    foreach ($sms->getRecipients() as $recipient) {
      $result = \Drupal::httpClient()
        ->post($base_uri . 'sms/messages', [
          'headers' => $headers,
          'json' => [
            'to' => $recipient,
            'body' => $sms->getMessage(),
          ]
        ]);
      $response = Json::decode($result->getBody());
    }

    return new SmsMessageResult(['status' => TRUE]);
  }

}
