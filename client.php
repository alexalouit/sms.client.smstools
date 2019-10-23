#!/usr/bin/php
<?php
/*
 * SMS client API
 *
 * cron eg: * * * * * php /root/scripts/sms/client.php >> /root/scripts/cron.log
 * eventhandler in smstools configuration: eventhandler = /root/scripts/sms/client.php
 * require execution permission (chmod +x /root/scripts/sms/client.php)
 */

class sms {
  private $config;

  public function __construct($args) {
    // load configuration
    $this->config = (array) parse_ini_file(__DIR__ . '/config.ini', true, INI_SCANNER_TYPED);

    $data['action'] = 'outgoing';

    switch (@$args[1]) {
      case 'RECEIVED':

        if (! is_file($args[2]))
          break;

        $file = file_get_contents($args[2]);

        $message = $this->parse($file);

        $data = array();
        $data['action'] = 'incoming';
        $data['message'] = $message['content'];
        $data['from'] = $message['from'];
        $data['timestamp'] = $message['receive'];

        unlink($args[2]);

        break;

      case 'SENT':

        if (! is_file($args[2]))
          break;

        if (
          $this->is_uuid(
            str_replace(
              $this->config['dir']['sent'] . DIRECTORY_SEPARATOR,
              '',
              $args[2]
            )
          )
        ) {
          $data['action'] = 'send_status';
          $data['status'] = 'sent';
          $data['id'] = str_replace($this->config['dir']['sent'] . DIRECTORY_SEPARATOR, '', $args[2]);
        } else {
          $file = file_get_contents($args[2]);
          $message = $this->parse($file);
          $data = array();
          $data['action'] = 'forward_sent';
          $data['message'] = $message['content'];
          $data['to'] = $message['to'];
          $data['timestamp'] = $message['sent'];
        }

        unlink($args[2]);

        break;

      case 'FAILED':

        if (! is_file($args[2]))
          break;

        if (
          ! $this->is_uuid(
            str_replace(
              $this->config['dir']['failed'] . DIRECTORY_SEPARATOR,
              '',
              $args[2]
            )
          )
        )
          return false;

        $data['action'] = 'send_status';
        $data['status'] = 'failed';
        $data['id'] = str_replace($this->config['dir']['failed'] . DIRECTORY_SEPARATOR, '', $args[2]);

        unlink($args[2]);

        break;

      case 'REPORT':
        break;

      case 'CALL':
        break;

      case 'test':

        $data['action'] = 'test';

        break;
    }

    $result = $this->request($data);

    if (! $result = json_decode($result, true))
      return false;

    foreach ($result as $events) {
      foreach ($events as $event) {
        switch (@$event['event']) {

          // send a message
          case 'send':

            foreach ($event['messages'] as $message) {
        /*
                  'priority' => (int) $message['priority'], // integer priority, higher numbers will be sent first
                  'type' => 'sms' // sms/mms/call
        */

              // send failed request if not conform
    //          $this->request(array('action' => 'send_status', 'status' => 'failed', 'id' => $message['id']));

              file_put_contents(
                $this->config['dir']['outgoing'] . DIRECTORY_SEPARATOR . $message['id'],
                'To: ' . str_replace('+', '', $message['to']) . PHP_EOL . PHP_EOL . $message['message']
              );

              // send queue request
              $this->request(array('action' => 'send_status', 'status' => 'queued', 'id' => $message['id']));
            }

            break;

          // cancel a message
          case 'cancel':

            $message = $event['messages']['id'];

            // be sure $message is present
            if (
              file_exists($this->config['dir']['outgoing'] . DIRECTORY_SEPARATOR . $message['id']) &&
              unlink($this->config['dir']['outgoing'] . DIRECTORY_SEPARATOR . $message['id'])
            ) {
              // send cancelled request
              $this->request(array('action' => 'send_status', 'status' => 'cancelled', 'id' => $message));
            }

            break;

          // purge all queued
          case 'cancel_all':

            $messages = scandir($this->config['dir']['outgoing']);
            $messages = array_filter($messages, is_file);

            // send cancelled request for each message
            foreach ($messages as $message) {
              if (
                file_exists($this->config['dir']['outgoing'] . DIRECTORY_SEPARATOR . $message) &&
                unlink($this->config['dir']['outgoing'] . DIRECTORY_SEPARATOR . $message)
              ) {
                $this->request(array('action' => 'send_status', 'status' => 'cancelled', 'id' => $message));
              }
            }

            break;
        }
      }
    }
  }

  /*
   * do request
   * @params: (array) data
   * @return: (string) response / (bool) state
   */
  private function request($data) {
    $data['phone_number'] = $this->config['phone_number'];
    $signature = $this->signature($data);
    $data = http_build_query($data);

    $context = array(
      'http' => array(
        'method' => 'POST',
        'ignore_errors' => true,
        'timeout' => 5,
        'header' => array(
          "Content-Type: application/x-www-form-urlencoded",
          "User-Agent: " . $this->config['user-agent'],
          "x-request-signature: " . $signature,
          "Content-Length: " . strlen($data)
        ),
        'content' => $data
      )
    );

    return @file_get_contents($this->config['endpoint'], false, stream_context_create($context));
  }

  /*
   * parse a message
   * @params: (string) message
   * @return: (array) parsed message
   */
  private function parse($input) {
    $lines = explode(PHP_EOL, $input);
    $data = array();

    foreach ($lines as $line) {
      if (
        $line == '' &&
        ! array_key_exists('content', $data)
      ) {
        $data['content'] = null;
      } else if (array_key_exists('content', $data)) {
        $data['content'] .= $line;
      } else if (preg_match("/^(?:From: ?)([0-9]+)$/i", $line, $tmp)) {
        $data['from'] = '+' . $tmp[1];
      } else if (preg_match("/^(?:To: ?)([0-9]+)$/i", $line, $tmp)) {
        $data['to'] = '+' . $tmp[1];
      } else if (preg_match("/^(?:Sent: ?)([\-\:\ 0-9]+)$/i", $line, $tmp)) {
        $data['sent'] = strtotime(trim($tmp[1]));
      } else if (preg_match("/^(?:Received: ?)([\-\:\ 0-9]+)$/i", $line, $tmp)) {
        $data['receive'] = strtotime(trim($tmp[1]));
      } else if (preg_match("/^(?:Length: ?)([0-9]+)$/i", $line, $tmp)) {
        $data['length'] = (int) $tmp[1];
      }
    }

    return (array) $data;
  }

  /*
   * compute signature
   * @params: (array) data
   * @return: (string) signature
   */
  private function signature($data) {
    if (! is_array($data))
      return false;

    ksort($data);

    $signature = array($this->config['endpoint']);

    foreach ($data as $key => $value) {
      $signature[] = sprintf('%s=%s', $key, $value);
    }

    unset($data);

    $signature[] = $this->config['token'];

    $signature = implode(",", $signature);

    return base64_encode(sha1($signature, true));
  }

  /*
   * generate uuid
   * @params: void
   * @return: (string) uuid
   */
  private function uuid() {
    return sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0xffff)
  );
  }

  /*
   * check is a valid uuid
   * @params: (string) input text
   * @return: (bool) state
   */
  private function is_uuid($input) {
    return preg_match(
      '/^([a-z0-9]{8})-([a-z0-9]{4})-([a-z0-9]{4})-([a-z0-9]{4})-([a-z0-9]{12})$/',
      (string) $input
    );
  }
}

new sms(@$argv);
