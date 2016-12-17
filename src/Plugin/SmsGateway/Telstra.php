<?php

namespace Drupal\sms_telstra\Plugin\SmsGateway;

use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sms\Message\SmsDeliveryReport;
use Drupal\sms\Message\SmsMessageResultStatus;
use Drupal\sms\Message\SmsMessageReportStatus;
use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResult;

/**
 * @SmsGateway(
 *   id = "telstra",
 *   label = @Translation("Telstra"),
 *   outgoing_message_max_recipients = 1,
 *   reports_pull = TRUE,
 *   reports_push = TRUE,
 * )
 */
class Telstra extends SmsGatewayPluginBase implements ContainerFactoryPluginInterface{

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a Telstra object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The default HTTP client.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client')
    );
  }

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
    $this->configuration['consumer_key'] = trim($form_state->getValue('consumer_key'));
    $this->configuration['consumer_secret'] = trim($form_state->getValue('consumer_secret'));
  }

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms_message) {
    $result = new SmsMessageResult();

    $base_uri = 'https://api.telstra.com/v1/';

    try {
      $access_token = $this->telstra_authenticate();
    }
    catch (RequestException $e) {
      $result
        ->setError(SmsMessageResultStatus::AUTHENTICATION)
        ->setErrorMessage($e->getMessage());
      return $result;
    }

    $settings = [
      'headers' => [
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'body' => $sms_message->getMessage(),
      ],
      'connect_timeout' => 10,
      'debug' => 'true',
    ];

    $recipients = $sms_message->getRecipients();

    if (count($recipients) != 1) {
      $result
        ->setError(SmsMessageResultStatus::ERROR)
        ->setErrorMessage((string) $this->t('This gateway can only take one recipient per request.'));
      return $result;
    }

    $recipient = $recipients[0];
    $report = (new SmsDeliveryReport())
      ->setRecipient($recipient);

    if (substr($recipient, 0, 1) == '+') {
      // Remove plus prefix.
      $recipient = substr($recipient, 1);
    }

    if (substr($recipient, 0, 2) != '04') {
      $report
        ->setStatus(SmsMessageReportStatus::INVALID_RECIPIENT)
        ->setStatusMessage((string) $this->t('Phone number must begin with 04.'));
      return $result->setReports([$report]);
    }

    if (strlen($recipient) != 10) {
      $report
        ->setStatus(SmsMessageReportStatus::INVALID_RECIPIENT)
        ->setStatusMessage((string) $this->t('Phone number must be ten digits.'));
      return $result->setReports([$report]);
    }

    if (Unicode::strlen($sms_message->getMessage()) > 160) {
      $report
        ->setStatus(SmsMessageReportStatus::CONTENT_INVALID)
        ->setStatusMessage((string) $this->t('Maximum message length is 160 characters.'));
      return $result->setReports([$report]);
    }


    $settings['json']['to'] = $recipient;
    try {
      $response = $this->httpClient
        ->request('post', $base_uri . 'sms/messages', $settings);

    }
    catch (RequestException $e) {
      return $result
        ->setError(SmsMessageResultStatus::ERROR)
        ->setErrorMessage($e->getMessage());
    }

    $status = $response->getStatusCode();
    $status_prefix = substr($status, 0, 1);
    if ($status_prefix == 2) {
      $response = Json::decode($response->getBody());
      $message_id = isset($response['messageId']) ? $response['messageId'] : '';

      $report
        ->setRecipient($recipient)
        ->setMessageId($message_id)
        ->setStatus(SmsMessageReportStatus::QUEUED)
        ->setTimeQueued(REQUEST_TIME);
      $result->setReports([$report]);
    }
    elseif ($status_prefix == 4) {
      if ($status == 401) {
        $result->setError(SmsMessageResultStatus::AUTHENTICATION);
        $result->setErrorMessage((string) $this->t('Incorrect authentication details.'));
      }
      elseif ($status == 403) {
        $result->setError(SmsMessageResultStatus::AUTHENTICATION);
        $result->setErrorMessage((string) $this->t('Account does not have permission.'));
      }
      elseif ($status == 429) {
        // 429: Too many requests in a given amount of time.
        // @todo inform framework to try again later.
        $result->setError(SmsMessageResultStatus::EXCESSIVE_REQUESTS);
        $result->setErrorMessage((string) $this->t('Too many requests, try again later.'));
      }
    }
    elseif ($status_prefix == 5) {
      $result->setError(SmsMessageResultStatus::ERROR);
      if (in_array($status, [500, 503])) {
        // 500: An internal error occurred when processing the request
        // 503: The service requested is currently unavailable
        // @todo inform framework to try again later.
        $result->setErrorMessage((string) $this->t('Try again later.'));
      }
    }
    else {
      $result->setError(SmsMessageResultStatus::ERROR);
      $result->setErrorMessage((string) $this->t("Response code '@code'", [
        '@code' => $status,
      ]));
    }

    return $result;
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
    $base_uri = 'https://api.telstra.com/v1/';
    $client_id = $this->configuration['consumer_key'];
    $secret = $this->configuration['consumer_secret'];

    $options = [
      'form_params' => [
        'client_id' => $client_id,
        'client_secret' => $secret,
        'grant_type' => 'client_credentials',
        'scope' => 'SMS'
      ],
    ];

    $result = $this->httpClient
      ->request('post', $base_uri . 'oauth/token', $options);
    $response = Json::decode($result->getBody());

    return $response['access_token'];
  }

}
