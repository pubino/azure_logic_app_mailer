<?php

namespace Drupal\azure_logic_app_mailer\Plugin\Mail;

use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;

/**
 * Provides a Drupal Mail plugin for Azure Logic Apps.
 *
 * @Mail(
 *   id = "logic_app_mailer",
 *   label = @Translation("Azure Logic App Mailer")
 * )
 */
class LogicAppMailer implements MailInterface, ContainerFactoryPluginInterface {

  public function __construct(protected ClientInterface $httpClient) {}

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($container->get('http_client'));
  }

  public function format(array $message) {
    return $message;
  }

  public function mail(array $message) {
    $isLocal = getenv('IS_LOCAL_DEV') === 'true';
    $endpoint = getenv('AZURE_LOGIC_APP_MAIL_URL');

    if ($isLocal || empty($endpoint)) {
      // --- LOCAL DEVELOPMENT FALLBACK ---
      $body = is_array($message['body']) ? implode("\n", $message['body']) : $message['body'];
      \Drupal::logger('mail_fallback')->info('Local Mail Mocked. To: @to, Subject: @subject, Body: @body', [
        '@to' => $message['to'],
        '@subject' => $message['subject'],
        '@body' => substr($body, 0, 500) . (strlen($body) > 500 ? '...' : ''),
      ]);

      // Write to local directory
      $dir = 'temporary://drupal-mails';
      if (\Drupal::service('file_system')->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY)) {
        $filename = $dir . '/mail-' . time() . '-' . uniqid() . '.txt';
        $content = "To: " . $message['to'] . "\nSubject: " . $message['subject'] . "\n\n" . $body;
        \Drupal::service('file_system')->saveData($content, $filename, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
      }
      return TRUE;
    }

    // --- PRODUCTION LOGIC APP SEND ---
    try {
      $token = $this->getManagedIdentityToken();
      if (!$token) {
        throw new \Exception('Could not retrieve Managed Identity token.');
      }

      $body = is_array($message['body']) ? implode("\n", $message['body']) : $message['body'];
      $response = $this->httpClient->post($endpoint, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'to' => $message['to'],
          'subject' => $message['subject'],
          'body' => $body,
        ],
        'timeout' => 5.0,
      ]);

      if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
        return TRUE;
      }
      throw new \Exception('Logic App returned HTTP status code ' . $response->getStatusCode());
    }
    catch (\Exception $e) {
      \Drupal::logger('logic_app_mailer')->error('Logic App mail error: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  protected function getManagedIdentityToken() {
    $endpoint = getenv('IDENTITY_ENDPOINT');
    $header = getenv('IDENTITY_HEADER');

    if (empty($endpoint) || empty($header)) {
      // Fallback to standard VM IMDS endpoint
      $url = 'http://169.254.169.254/metadata/identity/oauth2/token?api-version=2018-02-01&resource=https://management.azure.com/';
      $headers = ['Metadata: true'];
    } else {
      $url = $endpoint . '?api-version=2019-08-01&resource=https://management.azure.com/';
      $headers = ["X-Identity-Header: $header"];
    }

    try {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 3);
      $response = curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($http_code === 200 && !empty($response)) {
        $data = json_decode($response, true);
        return $data['access_token'] ?? NULL;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('logic_app_mailer')->error('Managed Identity token fetch error: @message', ['@message' => $e->getMessage()]);
    }
    return NULL;
  }
}
