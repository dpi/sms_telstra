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
use GuzzleHttp\Exception\RequestException;

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
  public function defaultConfiguration() {
    return [
      'consumer_key' => '',
      'consumer_secret' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $config = $this->getConfiguration();

    $form['help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('API keys can be found at <a href="https://dev.telstra.com/user/me/apps">https://dev.telstra.com/user/me/apps</a>.'),
    ];

    $form['consumer_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Consumer Key'),
      '#default_value' => $config['consumer_key'],
    ];

    $form['consumer_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Consumer Secret'),
      '#default_value' => $config['consumer_secret'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $this->configuration['consumer_key'] = $form_state->getValue('consumer_key');
    $this->configuration['consumer_secret'] = $form_state->getValue('consumer_secret');
  }

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms, array $options) {
    $client = \Drupal::httpClient();
    $base_uri = 'https://api.telstra.com/v1/';

    $access_token = $this->telstra_authenticate();

    foreach ($sms->getRecipients() as $recipient) {
      try {
        $result = $client->post($base_uri . 'sms/messages', [
          'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
          ],
          'json' => [
            'to' => $recipient,
            'body' => $sms->getMessage(),
          ],
        ]);
//        $response = Json::decode($result->getBody());
//        $response['messageId'];
      }
      catch (RequestException $e) {
        // Throws 400 for invalid numbers.
        return new SmsMessageResult(['status' => FALSE]);
      }

    }

    return new SmsMessageResult(['status' => TRUE]);
  }

  /**
   * Authenticate.
   *
   * @return string|FALSE
   *   Access token for use in auth requests or FALSE if failed.
   */
  private function telstra_authenticate() {
    $client = \Drupal::httpClient();

    $base_uri = 'https://api.telstra.com/v1/';
    $client_id = $this->configuration['consumer_key'];
    $secret = $this->configuration['consumer_secret'];

    try {
      $result = $client->post($base_uri . 'oauth/token', [
        'form_params' => [
          'client_id' => $client_id,
          'client_secret' => $secret,
          'grant_type' => 'client_credentials',
          'scope' => 'SMS'
        ],
      ]);
      $response = Json::decode($result->getBody());
      return $response['access_token'];
    }
    catch (RequestException $e) {
      return FALSE;
    }
  }

}
