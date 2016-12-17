<?php

namespace Drupal\Tests\sms_telstra\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;
use Drupal\sms\Tests\SmsFrameworkTestTrait;

/**
 * Base test class for functional browser tests.
 */
abstract class SmsTelstraBrowserTestBase extends BrowserTestBase {

  use SmsFrameworkTestTrait;

  public static $modules = [
    'sms',
    'sms_telstra',
    'sms_test_gateway',
    'telephone',
    'dynamic_entity_reference',
  ];

  /**
   * Return Guzzle options for a sample incoming request.
   *
   * @return array
   *   Options for a Guzzle POST request.
   */
  protected function incomingRequestOptions() {
    $json = Json::decode(file_get_contents(__DIR__ . '/../../fixtures/incoming_request.body.json'));

    $options = [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'json' => $json,
    ];

    return $options;
  }

}
