<?php

namespace Drupal\sms_telstra\Plugin\SmsGateway;

use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\Component\Serialization\Json;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Form\FormStateInterface;

/**
 * @SmsGateway(
 *   id = "telstra",
 *   label = @Translation("Telstra"),
 *   outgoing_message_max_recipients = 1,
 * )
 */
class Telstra extends SmsGatewayPluginBase {

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
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $config = $this->getConfiguration();

    $form['telstra'] = [
      '#type' => 'details',
      '#title' => $this->t('Telstra'),
      '#open' => TRUE,
    ];

    $form['telstra']['help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('API keys can be found at <a href="https://dev.telstra.com/user/me/apps">https://dev.telstra.com/user/me/apps</a>.'),
    ];

    $form['telstra']['consumer_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Consumer Key'),
      '#default_value' => $config['consumer_key'],
    ];

    $form['telstra']['consumer_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Consumer Secret'),
      '#default_value' => $config['consumer_secret'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['consumer_key'] = $form_state->getValue('consumer_key');
    $this->configuration['consumer_secret'] = $form_state->getValue('consumer_secret');
  }

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms_message) {
    $client = \Drupal::httpClient();
    $base_uri = 'https://api.telstra.com/v1/';

    try {
      $access_token = $this->telstra_authenticate();
    }
    catch (RequestException $e) {
      return new SmsMessageResult([
        'error_message' => $e->getMessage(),
        'status' => FALSE,
      ]);
    }

    $settings = [
      'headers' => [
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'body' => $sms->getMessage(),
      ],
      'connect_timeout' => 10,
      'debug' => 'true',
    ];

    foreach ($sms->getRecipients() as $recipient) {
      $settings['json']['to'] = $recipient;
      try {
        $result = $client->post($base_uri . 'sms/messages', $settings);
//        $response = Json::decode($result->getBody());
//        $response['messageId'];
      }
      catch (RequestException $e) {
        return new SmsMessageResult([
          'error_message' => $e->getMessage(),
          'status' => FALSE,
        ]);
      }

    }

    return new SmsMessageResult(['status' => TRUE]);
  }

  /**
   * Authenticate.
   *
   * @return string|FALSE
   *   Access token for use in auth requests or FALSE if failed.
   *
   * @throws RequestException
   *   Throws on failed HTTP request.
   */
  private function telstra_authenticate() {
    $client = \Drupal::httpClient();

    $base_uri = 'https://api.telstra.com/v1/';
    $client_id = $this->configuration['consumer_key'];
    $secret = $this->configuration['consumer_secret'];

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

}
