<?php

namespace Drupal\sms_telstra\Plugin\SmsGateway;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sms\Direction;
use Drupal\sms\Entity\SmsGatewayInterface;
use Drupal\sms\Message\SmsDeliveryReport;
use Drupal\sms\Message\SmsMessageResultStatus;
use Drupal\sms\Message\SmsMessageReportStatus;
use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\sms\Message\SmsMessage;
use Drupal\sms\SmsProcessingResponse;

/**
 * @SmsGateway(
 *   id = "telstra2",
 *   label = @Translation("Telstra API v2"),
 *   outgoing_message_max_recipients = 1,
 *   incoming = TRUE,
 *   incoming_route = TRUE,
 *   reports_pull = TRUE,
 *   reports_push = TRUE,
 * )
 */
class Telstra2 extends SmsGatewayPluginBase implements ContainerFactoryPluginInterface{
  
  /**
   * Telstra Authentication API.
   * 
   * @var \Telstra_messaging\Api\AuthenticationApi
   */
  protected $authenticationApi;
  
  /**
   * Telstra Messaging API.
   * 
   * @var \Telstra_Messaging\Api\MessagingApi
   */
  protected $messagingApi;
  
  /**
   * Telstra Provisioning API.
   * 
   * @var \Telstra_Messaging\Api\ProvisioningApi
   */
  protected $provisioningApi;
  
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
   * @param \Telstra_Messaging\Api\AuthenticationApi $authentication_api
   *   Telstra Authentication API.
   * @param \Telstra_Messaging\Api\MessagingApi $messaging_api
   *   Telstra Messaging API.
   * @param \Telstra_Messaging\Api\ProvisioningAPI $provisioning_api
   *   Telstra Provisioning API
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client, $authentication_api, $messaging_api, $provisioning_api) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
    $this->authenticationApi = $authentication_api;
    $this->messagingApi = $messaging_api;
    $this->provisioningApi = $provisioning_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('sms_telstra.authentication_api'),
      $container->get('sms_telstra.messaging_api'),
      $container->get('sms_telstra.provisioning_api')
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
    
    $provisioned_number = $this->telstra_provision();

    $result = new SmsMessageResult();

    try {
      $access_token = $this->telstra_authenticate();
    }
    catch (RequestException $e) {
      $result
        ->setError(SmsMessageResultStatus::AUTHENTICATION)
        ->setErrorMessage($e->getMessage());
      return $result;
    }
    
    $this->messagingApi->getConfig()->setAccessToken($access_token);    

    $body = $sms_message->getMessage();

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

    $payload = new \Telstra_Messaging\Model\SendSMSRequest(['to' => $recipient, 'body' => $body]);

    try {
      $response = $this->messagingApi->sendSMS($payload);
    }
    catch (\Telstra_Messaging\ApiException $e) {
      $response_body = Json::decode($e->getResponseBody());
      $status = $e->getCode();
      $status_prefix = substr($status, 0, 1);
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
      elseif (in_array($status, [500, 503])) {
        // 500: An internal error occurred when processing the request
        // 503: The service requested is currently unavailable  
        // @todo inform framework to try again later.
        $result->setError(SmsMessageResultStatus::ERROR);
        $result->setErrorMessage((string) $this->t('Try again later.'));
      }
      else {
        $result->setError(SmsMessageResultStatus::ERROR);
        $result->setErrorMessage((string) $this->t("Response code '@code'", [
            '@code' => $status,
        ]));
      }

      return $result;
    }

    $messages = $response->getMessages();
    $message_id = reset($messages)->getMessageId();

    $report
      ->setRecipient($recipient)
      ->setMessageId($message_id)
      ->setStatus(SmsMessageReportStatus::QUEUED)
      ->setTimeQueued(REQUEST_TIME);
    $result->setReports([$report]);

    return $result;
  }

  /**
   * Process an incoming message POST request.
   *
   * Telstra only passes one message per request.
   *
   * API documentation:
   *   https://dev.telstra.com/content/sms-getting-started#callback
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param \Drupal\sms\Entity\SmsGatewayInterface $sms_gateway
   *   The gateway instance.
   *
   * @return \Drupal\sms\SmsProcessingResponse
   *   A SMS processing response task.
   */
  function processIncoming(Request $request, SmsGatewayInterface $sms_gateway) {
    $messages = [];

    $json = Json::decode($request->getContent());

    $time_atom = $json['acknowledgedTimestamp'];
    $time = DrupalDateTime::createFromFormat(DATE_ATOM, $time_atom);

    $report = (new SmsDeliveryReport())
      ->setMessageId($json['messageId'])
      ->setTimeDelivered($time->format('U'));

    $result = (new SmsMessageResult())
      ->setReports([$report]);

    $message = (new SmsMessage())
      ->setMessage($json['content'])
      ->setDirection(Direction::INCOMING)
      ->setGateway($sms_gateway)
      ->setResult($result)
      ->setOption('telstra_status', $json['status']);

    $response = new Response('', 204);
    $task = (new SmsProcessingResponse())
      ->setResponse($response)
      ->setMessages([$message]);

    return $task;
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
    $client_id = $this->configuration['consumer_key'];
    $secret = $this->configuration['consumer_secret'];

    $result = $this->authenticationApi->authToken($client_id, $secret, 'client_credentials');

    return $result->getAccessToken();
  }

  /**
   * Provision a number.
   *
   * @return string|FALSE
   *   Phone number provisioned to app.
   *
   * @throws RequestException
   *   Throws on failed HTTP request.
   */
  private function telstra_provision() {
    $this->provisioningApi->getConfig()->setAccessToken($this->telstra_authenticate());    
    $provision_request = new \Telstra_Messaging\Model\ProvisionNumberRequest();

    $provision_response = $this->provisioningApi->createSubscription($provision_request);

    return $provision_response->getDestinationAddress();
  }

}
