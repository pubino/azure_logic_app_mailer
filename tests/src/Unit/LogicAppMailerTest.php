<?php

namespace Drupal\Tests\azure_logic_app_mailer\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\azure_logic_app_mailer\Plugin\Mail\LogicAppMailer;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\azure_logic_app_mailer\Plugin\Mail\LogicAppMailer
 * @group azure_logic_app_mailer
 */
class LogicAppMailerTest extends TestCase {

  /**
   * Mock HTTP Client.
   *
   * @var \GuzzleHttp\ClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $httpClient;

  /**
   * Mock File System.
   *
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileSystem;

  /**
   * Mock Logger Factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * Mock Logger Channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(\GuzzleHttp\Client::class);
    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->loggerFactory->method('get')->willReturn($this->logger);

    // Setup Drupal container
    $container = new ContainerBuilder();
    $container->set('logger.factory', $this->loggerFactory);
    $container->set('file_system', $this->fileSystem);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('IS_LOCAL_DEV');
    putenv('AZURE_LOGIC_APP_MAIL_URL');
    parent::tearDown();
  }

  /**
   * Tests mail sending in local development scenario.
   */
  public function testMailLocalDev() {
    putenv('IS_LOCAL_DEV=true');

    $this->logger->expects($this->once())
      ->method('info')
      ->with($this->stringContains('Local Mail Mocked.'));

    $this->fileSystem->expects($this->once())
      ->method('prepareDirectory')
      ->with('temporary://drupal-mails', FileSystemInterface::CREATE_DIRECTORY)
      ->willReturn(TRUE);

    $this->fileSystem->expects($this->once())
      ->method('saveData')
      ->with(
        $this->stringContains('To: user@example.com'),
        $this->stringContains('temporary://drupal-mails/mail-'),
        FileSystemInterface::EXISTS_REPLACE
      )
      ->willReturn('/tmp/mail-123.txt');

    $mailer = new LogicAppMailer($this->httpClient);

    $message = [
      'to' => 'user@example.com',
      'subject' => 'Test Subject',
      'body' => ['Test Line 1', 'Test Line 2'],
    ];

    $result = $mailer->mail($message);
    $this->assertTrue($result);
  }

  /**
   * Tests mail sending in production (Azure Logic App integration) scenario.
   */
  public function testMailProductionSuccess() {
    putenv('IS_LOCAL_DEV=false');
    putenv('AZURE_LOGIC_APP_MAIL_URL=https://logic-app-endpoint.azure.com');

    // Mock response from Logic App
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(202);

    $this->httpClient->expects($this->once())
      ->method('post')
      ->with(
        'https://logic-app-endpoint.azure.com',
        $this->callback(function ($options) {
          return $options['headers']['Authorization'] === 'Bearer mock-token' &&
            $options['json']['to'] === 'prod@example.com' &&
            $options['json']['subject'] === 'Prod Subject' &&
            $options['json']['body'] === "Prod Line 1\nProd Line 2";
        })
      )
      ->willReturn($response);

    // Mock LogicAppMailer to stub getManagedIdentityToken
    $mailer = $this->getMockBuilder(LogicAppMailer::class)
      ->setConstructorArgs([$this->httpClient])
      ->onlyMethods(['getManagedIdentityToken'])
      ->getMock();

    $mailer->expects($this->once())
      ->method('getManagedIdentityToken')
      ->willReturn('mock-token');

    $message = [
      'to' => 'prod@example.com',
      'subject' => 'Prod Subject',
      'body' => ["Prod Line 1", "Prod Line 2"],
    ];

    $result = $mailer->mail($message);
    $this->assertTrue($result);
  }

  /**
   * Tests mail sending failure in production.
   */
  public function testMailProductionFailure() {
    putenv('IS_LOCAL_DEV=false');
    putenv('AZURE_LOGIC_APP_MAIL_URL=https://logic-app-endpoint.azure.com');

    // Mock failure response
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(500);

    $this->httpClient->expects($this->once())
      ->method('post')
      ->willReturn($response);

    $this->logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Logic App mail error:'));

    $mailer = $this->getMockBuilder(LogicAppMailer::class)
      ->setConstructorArgs([$this->httpClient])
      ->onlyMethods(['getManagedIdentityToken'])
      ->getMock();

    $mailer->expects($this->once())
      ->method('getManagedIdentityToken')
      ->willReturn('mock-token');

    $message = [
      'to' => 'fail@example.com',
      'subject' => 'Fail Subject',
      'body' => ['Fail Body'],
    ];

    $result = $mailer->mail($message);
    $this->assertFalse($result);
  }

}
