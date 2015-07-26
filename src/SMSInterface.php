<?php

/**
 * @file
 * Contains \Drupal\telstrasms\SMSInterface.
 */

namespace Drupal\telstrasms;

interface SMSInterface {

  public function getPhoneNumber();

  public function setPhoneNumber($phone_number);

  public function getMessage();

  public function setMessage($message);

}