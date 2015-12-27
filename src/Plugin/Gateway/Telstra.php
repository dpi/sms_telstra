<?php
/**
 * @file
 * Contains \Drupal\sms_telstra\Plugin\Gateway\Telstra
 */

namespace Drupal\sms_telstra\Plugin\Gateway;

use Drupal\sms\Gateway\GatewayBase;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\Component\Serialization\Json;

/**
 * @SmsGateway(
 *   id = "telstra",
 *   label = @Translation("Telstra"),
 *   configurable = true,
 * )
 */
class Telstra extends GatewayBase {

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms, array $options) {

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
