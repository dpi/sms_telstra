<?php

/**
 * @file
 * Contains \Drupal\telstrasms\Plugin\IdentityChannel\TelstraSMS\User.
 */

namespace Drupal\telstrasms\Plugin\IdentityChannel\TelstraSMS;


use Drupal\courier\Plugin\IdentityChannel\IdentityChannelPluginInterface;
use Drupal\courier\ChannelInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Supports core user entities.
 *
 * @IdentityChannel(
 *   id = "identity:user:telstra_sms",
 *   label = @Translation("Drupal user to telstra_sms"),
 *   channel = "telstra_sms",
 *   identity = "user",
 *   weight = 10
 * )
 */
class User implements IdentityChannelPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function applyIdentity(ChannelInterface &$message, EntityInterface $identity) {
    /** @var \Drupal\user\UserInterface $identity */
    /** @var \Drupal\telstrasms\Entity\SMS $message */
    $message->setPhoneNumber('0400000000');//@todo get phone number from user entity
  }

}