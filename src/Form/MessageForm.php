<?php

namespace Drupal\private_chat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\private_chat\Entity\Chat;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\private_chat\Controller\ChatUIController;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MessageForm extends FormBase
{
  protected $entityTypeManager;
  protected $chatUiController;
  protected $dateFormatter;
  protected $currentUser;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, ChatUIController $chat_ui_controller, DateFormatterInterface $date_formatter, AccountInterface $current_user)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->chatUiController = $chat_ui_controller;
    $this->dateFormatter = $date_formatter;
    $this->currentUser = $current_user;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('private_chat.chat_ui_controller'),
      $container->get('date.formatter'),
      $container->get('current_user')
    );
  }
  public function getFormId()
  {
    return 'private_chat_message_form';
  }
  public function buildForm(array $form, FormStateInterface $form_state, Chat $chat = NULL)
  {
    $form_state->set('chat', $chat);

    // Der Wrapper, der durch AJAX ersetzt wird.
    $form['#prefix'] = '<div id="private-chat-form-wrapper">';
    $form['#suffix'] = '</div>';

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Deine Nachricht'),
      '#title_display' => 'invisible',
      '#placeholder' => $this->t('Schreibe eine Nachricht...'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Senden'),
      // Hier wird der Button als AJAX-Button konfiguriert
      '#ajax' => [
        'callback' => '::ajaxSubmitCallback',
        'wrapper' => 'private-chat-form-wrapper',
        // 'effect' => 'ease-in',
      ],
      '#attached' => [
        'library' => ['private_chat/chat-styling'], // Optional: eine CSS-Bibliothek hinzufügen
      ],
    ];

    return $form;
  }

  /**
   * Die AJAX-Callback-Funktion.
   */
  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();
    $chat = $form_state->get('chat');

    // 1. Nachrichtenliste neu aufbauen
    $message_ids = $this->entityTypeManager->getStorage('message')->getQuery()->condition('chat_id', $chat->id())->sort('created', 'ASC')->accessCheck(FALSE)->execute();
    $messages = $this->entityTypeManager->getStorage('message')->loadMultiple($message_ids);

    $themed_messages = [];
    foreach ($messages as $message) {
      $author_entity = $message->get('author')->entity;
      $status_class = ''; // Standardmäßig keine Klasse
      if ($author_entity->id() == $this->currentUser->id()) {
        // Wenn der aktuelle Benutzer der Autor ist, prüfe den Lesestatus.
        $status_class = $message->get('is_read')->value ? 'is-read' : 'is-unread';
      }
      $status_receiver_class = ''; // Standardmäßig keine Klasse
      if ($author_entity->id() !== $this->currentUser->id()) {
        // Wenn der Empfänger die Nachricht gelesen hat, füge die Klasse 'read' hinzu.
        $status_receiver_class = $message->get('is_read')->value ? 'read' : 'unread';
      }
      $timestamp = $message->get('created')->value;
      $now = \Drupal::time()->getRequestTime();
      $difference = $now - $timestamp;

      $formatted_time = '';
      if ($difference < 1800) { // Weniger als 30 Minuten
        $formatted_time = $this->t('vor @time', ['@time' => $this->dateFormatter->formatInterval($difference, 1)]);
      } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', $now)) { // Heute
        $formatted_time = $this->t('@time', ['@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i')]);
      } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', strtotime('-1 day', $now))) { // Gestern
        $formatted_time = $this->t('Gestern, @time', ['@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i')]);
      } else { // Älter
        $formatted_time = $this->dateFormatter->format($timestamp, 'medium', 'H:i');
      }
      $themed_messages[] = [
        '#theme' => 'private_chat_message',
        '#author_name' => $author_entity->getDisplayName(),
        '#author_picture' => $author_entity->get('field_profile_picture')->view([
          'label' => 'hidden',
          'type' => 'image',
          'settings' => ['image_style' => 'chat_small'],
        ]),
        '#body' => [
          '#type' => 'processed_text',
          '#text' => $message->get('message')->value,
          '#format' => $message->get('message')->format,
        ],
        '#time' => $formatted_time,
        '#sent_received' => ($author_entity->id() == $this->currentUser->id()) ? 'sent' : 'received',
        '#status_class' => $status_class,
        '#status_receiver_class' => $status_receiver_class,
        '#message_id' => $message->id(),
      ];
    }

    $messages_render_array = [
      '#theme' => 'private_chat_page',
      '#messages' => $themed_messages,
      '#form' => $form,
       // Das Formular wird hier nicht mehr benötigt, da es nicht neu gerendert wird.
    ];

    // 2. Seitenleiste neu aufbauen
    $chat_list_render_array = $this->chatUiController->_buildChatListRenderArray($chat->uuid());

    // BEFEHL 1: Ersetze nur den Nachrichten-Container.
    $response->addCommand(new ReplaceCommand('.private-chat-container', $messages_render_array));
    // BEFEHL 2: Ersetze die Seitenleiste.
    $response->addCommand(new HtmlCommand('#chat-list-wrapper', $chat_list_render_array));
    // BEFEHL 3: Leere das Textfeld.
    $response->addCommand(new InvokeCommand('textarea[name="message"]', 'val', ['']));

    // Fehlerbehandlung bleibt unverändert.
    if ($form_state->hasAnyErrors()) {
      // Transfer FormState errors to messenger to use the deleteAll() pattern.
      foreach ($form_state->getErrors() as $error_message_text) {
        $this->messenger()->addError($error_message_text);
      }

      $ajax_error_messages = \Drupal::messenger()->deleteAll(); // Retrieve and clear all messages.
      if (!empty($ajax_error_messages)) {
        $first_message_in_batch = TRUE;
        foreach ($ajax_error_messages as $type => $messages_of_type) {
          foreach ($messages_of_type as $individual_message_text) {
            $response->addCommand(new MessageCommand(
              $individual_message_text,
              NULL,
              ['type' => $type], // Options for Drupal.message().add()
              $first_message_in_batch // clearPrevious flag for the command
            ));
            $first_message_in_batch = FALSE;
          }
        }
      }
    }

    return $response;
  }
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $message = $form_state->getValue('message');

    if(!(trim($message))) {
      $form_state->setErrorByName('message', $this->t('Du musst eine Nachricht eingeben.'));
    }
  }
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Diese Funktion speichert die Nachricht. Sie wird VOR dem AJAX-Callback ausgeführt.
    $chat = $form_state->get('chat');
    $this->entityTypeManager->getStorage('message')->create([
      'chat_id' => $chat->id(),
      'author' => $this->currentUser()->id(),
      'message' => $form_state->getValue('message'),
    ])->save();

    // Wichtig für AJAX: Das Formular zurücksetzen, damit das Textfeld nach dem Senden leer ist.
    $form_state->setRebuild();
  }
}
