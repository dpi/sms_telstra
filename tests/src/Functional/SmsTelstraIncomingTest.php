<?php

namespace Drupal\Tests\sms_telstra\Functional;

use Drupal\Core\Url;
use Drupal\bootstrap\Utility\Unicode;
use Drupal\sms\Entity\SmsGateway;

/**
 * Incoming browser test.
 *
 * @group Telstra SMS
 */
class SmsFrameworkIncomingBrowserTest extends SmsTelstraBrowserTestBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * An Telstra gateway instance.
   *
   * @var \Drupal\sms\Entity\SmsGatewayInterface
   */
  protected $telstraGateway;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->httpClient = $this->container->get('http_client');

    $this->telstraGateway = SmsGateway::create([
      'id' => Unicode::strtolower($this->randomMachineName(16)),
      'plugin' => 'telstra',
      'label' => $this->randomString(),
    ]);
    $this->telstraGateway
      ->enable()
      ->setSkipQueue(TRUE)
      ->save();

    // Force SMS Framework route subscriber to create incoming route.
    $this->container
      ->get('router.builder')
      ->rebuild();
  }

  /**
   * Test incoming route endpoint provided by 'incoming' gateway.
   */
  public function testIncomingRouteEndpoint() {
    $url = Url::fromRoute('sms.incoming.receive.' . $this->telstraGateway->id())
      ->setRouteParameter('sms_gateway', $this->telstraGateway->id())
      ->setAbsolute()
      ->toString();

    $options = $this->incomingRequestOptions();
    $this->assertTrue(TRUE, sprintf('POST request to %s', $url));
    $response = $this->httpClient
      ->post($url, $options);

    $this->assertEquals(204, $response->getStatusCode(), 'HTTP code is 204');
    $this->assertEmpty((string) $response->getBody(), 'Response body is empty.');

    $incoming_messages = $this->getIncomingMessages($this->telstraGateway);
    $this->assertEquals(1, count($incoming_messages), 'There is 1 message');

    $message = reset($incoming_messages);
    $this->assertEquals('Reply message', $message->getMessage());
    $this->assertEquals('READ', $message->getOption('telstra_status'));

    $result = $message->getResult();
    $reports = $result->getReports();
    $this->assertEquals(1, count($reports), 'There is 1 report');

    /** @var \Drupal\sms\Message\SmsDeliveryReportInterface $report */
    $report = reset($reports);

    $this->assertEquals(1482000000, $report->getTimeDelivered());
    $this->assertEquals('ABCDEFGHIJKL1234ABCDEFGHIJKL1234', $report->getMessageId());
  }

}
