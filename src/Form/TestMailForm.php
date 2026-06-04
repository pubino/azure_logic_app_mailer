<?php

namespace Drupal\azure_logic_app_mailer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TestMailForm extends FormBase {

  public function __construct(protected MailManagerInterface $mailManager) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('plugin.manager.mail'));
  }

  public function getFormId() {
    return 'azure_logic_app_mailer_test_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient Email Address'),
      '#required' => TRUE,
      '#description' => $this->t('Enter the email address you want to send a test message to.'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Test Email'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $recipient = $form_state->getValue('recipient');
    
    $params = [
      'subject' => 'OGSDB Azure Mailer Test',
      'body' => 'This is a test email sent from the OGSDB Drupal instance using the Azure Logic App & O365 connector integration.',
    ];

    $result = $this->mailManager->mail(
      'azure_logic_app_mailer',
      'test_send',
      $recipient,
      \Drupal::currentUser()->getPreferredLangcode(),
      $params
    );

    if ($result['result'] === TRUE) {
      $this->messenger()->addStatus($this->t('Test email successfully queued and sent to %recipient.', ['%recipient' => $recipient]));
    }
    else {
      $this->messenger()->addError($this->t('Failed to send test email to %recipient. Check the Drupal Watchdog logs for details.', ['%recipient' => $recipient]));
    }
  }
}
