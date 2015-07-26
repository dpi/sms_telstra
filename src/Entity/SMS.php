<?php

/**
 * @file
 * Contains \Drupal\telstrasms\Entity\SMS.
 */

namespace Drupal\telstrasms\Entity;

use Drupal\courier\ChannelBase;
use Drupal\telstrasms\SMSInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Component\Serialization\Json;

/**
 * Defines storage for a SMS.
 *
 * @ContentEntityType(
 *   id = "telstra_sms",
 *   label = @Translation("Telstra SMS"),
 *   handlers = {
 *     "form" = {
 *       "default" = "Drupal\telstrasms\Form\TelstraSMS",
 *       "add" = "Drupal\telstrasms\Form\TelstraSMS",
 *       "edit" = "Drupal\telstrasms\Form\TelstraSMS",
 *       "delete" = "Drupal\telstrasms\Form\TelstraSMSDelete",
 *     },
 *   },
 *   base_table = "telstra_sms",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "id",
 *   },
 *   links = {
 *     "canonical" = "/telstra/sms/{telstra_sms}/edit",
 *     "edit-form" = "/telstra/sms/{telstra_sms}/edit",
 *     "delete-form" = "/telstra/sms/{telstra_sms}/delete",
 *   }
 * )
 */
class SMS extends ChannelBase implements SMSInterface {

  /**
   * {@inheritdoc}
   */
  public function getPhoneNumber() {
    return $this->get('phone')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setPhoneNumber($phone_number) {
    $this->set('phone', ['value' => $phone_number]);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMessage() {
    return $this->get('message')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setMessage($message) {
    $this->set('message', ['value' => $message]);
  }

  /**
   * {@inheritdoc}
   */
  static public function sendMessages(array $messages, $options = []) {
    /** @var static[] $messages */

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
      'Content-Type' => 'application/json'
    ];

    foreach ($messages as $message) {
      $result = \Drupal::httpClient()
        ->post($base_uri . 'sms/messages', [
          'headers' => $headers,
          'json' => [
            'to' => $message->getPhoneNumber(),
            'body' => $message->getMessage(),
          ]
        ]);
      $response = Json::decode($result->getBody());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function sendMessage(array $options = []) {
    $this->sendMessages([$this], $options);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('SMS ID'))
      ->setDescription(t('The SMS ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['phone'] = BaseFieldDefinition::create('telephone')
      ->setLabel(t('Phone'))
      ->setDescription(t('Phone number.'))
      ->setDefaultValue('')
      ->setDisplayOptions('form', [
        'type' => 'hidden',
      ]);

    $fields['message'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Message'))
      ->setDescription(t('The SMS message.'))
      ->setDefaultValue('')
      ->setSetting('max_length', 160)
      ->setSetting('is_ascii', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 50,
      ]);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language code'))
      ->setDescription(t('The language code.'));

    return $fields;
  }

}
