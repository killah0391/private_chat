<?php

namespace Drupal\private_chat\Controller;

use Drupal\Core\Url;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ChatUIController extends ControllerBase
{

  protected $chatController;
  protected $currentUser;
  protected $requestStack;
  protected $dateFormatter;

  public function __construct(ChatController $chat_controller, AccountInterface $current_user, RequestStack $request_stack, DateFormatterInterface $date_formatter)
  {
    $this->chatController = $chat_controller;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
    $this->dateFormatter = $date_formatter;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('private_chat.chat_controller'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('date.formatter')
    );
  }

  /**
   * Baut die Haupt-UI auf.
   */
  public function buildUi(string $chat_uuid = NULL)
  {
    $session = $this->requestStack->getSession();
    $active_chat_uuid = $chat_uuid;

    // Rufe die Hilfsfunktion auf, um die Chat-Liste zu erstellen.
    $chat_list = $this->_buildChatListRenderArray($active_chat_uuid);

    // $chat_content = ['#markup' => '<div class="private-chat-container"><div class="chat-messages no-chat-selected fs-4 d-flex flex-wrap align-content-center justify-content-center">' . $this->t('Select a chat from the list.') . '</div></div>'];
    if ($active_chat_uuid && $this->entityTypeManager()->getStorage('chat')->loadByProperties(['uuid' => $active_chat_uuid])) {
      $chat_content = $this->chatController->chatPage($active_chat_uuid);
    }

    return [
      '#theme' => 'private_chat_ui_page',
      '#chat_list' => $chat_list,
      '#chat_content' => $chat_content,
      '#cache' => ['contexts' => ['user'], 'tags' => ['message_list']],
      // ### KORREKTUR HIER ###
      // Hänge die AJAX-Bibliothek immer an, um sicherzustellen, dass sie verfügbar ist.
      '#attached' => [
        'library' => [
          'private_chat/chat-styling',
          'private_chat/mobile-ui',
        ],
      ],
    ];
  }

  /**
   * AJAX-Callback zum Laden eines Chats.
   */
  public function loadChatAjax(string $chat_uuid)
  {
    // $session = $this->requestStack->getSession();
    // $session->set('private_chat_last_selected_uuid', $chat_uuid);

    // 1. Hole den neuen Hauptinhalt.
    $chat_page_render_array = $this->chatController->chatPage($chat_uuid);

    // 2. Hole die aktualisierte Seitenleiste.
    $chat_list_render_array = $this->_buildChatListRenderArray($chat_uuid);

    $response = new AjaxResponse();

    // Die restlichen Befehle ersetzen den Inhalt wie gewohnt.
    $response->addCommand(new HtmlCommand('#chat-content-wrapper', $chat_page_render_array));
    $response->addCommand(new HtmlCommand('#chat-list-wrapper', $chat_list_render_array));

    return $response;
  }

  /**
   * Private Hilfsfunktion, um das Render-Array der Chat-Liste zu erstellen.
   */
  public function _buildChatListRenderArray(string $active_chat_uuid = NULL)
  {
    $current_user_id = $this->currentUser->id();
    $current_user_entity = $this->entityTypeManager()->getStorage('user')->load($current_user_id);
    $storage = $this->entityTypeManager()->getStorage('chat');

    $query = $storage->getQuery();
    $group = $query->orConditionGroup()
      ->condition('user1', $current_user_id)
      ->condition('user2', $current_user_id);
    $query->condition($group);
    $query->accessCheck(FALSE)->sort('changed', 'DESC');
    $chat_ids = $query->execute();
    $chats = $storage->loadMultiple($chat_ids);

    $chat_list_items = [];
    foreach ($chats as $chat) {
      $other_participant = $chat->getOtherParticipant($current_user_entity);
      if ($other_participant) {
        $last_message_data = $this->getLastMessageData($chat);
        $chat_list_items[] = [
          '#theme' => 'private_chat_list_item',
          '#other_participant_name' => $other_participant->getDisplayName(),
          '#last_message' => $last_message_data,
          '#url' => Url::fromRoute('private_chat.load_ajax', ['chat_uuid' => $chat->uuid()]),
          '#is_active' => ($chat->uuid() === $active_chat_uuid),
        ];
      }
    }
    return $chat_list_items;
  }

  /**
   * Private Hilfsfunktion, um die Daten der letzten Nachricht zu holen und zu formatieren.
   */
  private function getLastMessageData($chat)
  {
    $last_message_query = $this->entityTypeManager()->getStorage('message')->getQuery()->condition('chat_id', $chat->id())->sort('created', 'DESC')->range(0, 1)->accessCheck(FALSE);
    $last_message_ids = $last_message_query->execute();
    $last_message = !empty($last_message_ids) ? $this->entityTypeManager()->getStorage('message')->load(reset($last_message_ids)) : NULL;

    $unread_count_query = $this->entityTypeManager()->getStorage('message')->getQuery()
      ->condition('chat_id', $chat->id())
      ->condition('is_read', FALSE)
      ->condition('author', $this->currentUser->id(), '<>') // Nachrichten von anderen
      ->accessCheck(FALSE);
    $unread_count = $unread_count_query->count()->execute();

    if (!$last_message) {
      return ['text' => 'No message at the moment.', 'time' => $this->dateFormatter->formatDiff($chat->get('created')->value, NULL, ['granularity' => 1])];
    }

    $timestamp = $last_message->get('created')->value;
    $now = \Drupal::time()->getRequestTime();
    $difference = $now - $timestamp;

    $formatted_time = '';
    if ($difference < 1800) {
      $formatted_time = $this->t('@time ago', ['@time' => $this->dateFormatter->formatInterval($difference, 1)]);
    } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', $now)) {
      $formatted_time = $this->t('@time', ['@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i')]);
    } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', strtotime('-1 day', $now))) {
      $formatted_time = $this->t('Yesterday, @time', ['@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i')]);
    } elseif ($timestamp >= strtotime('-7 days', $now)) {
      $formatted_time = $this->t('@day, @time', ['@day' => $this->dateFormatter->format($timestamp, 'custom', 'l'), '@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i')]);
    } else {
      $formatted_time = $this->dateFormatter->format($timestamp, 'medium', 'H:i');
    }

    $prefix = ($last_message->get('author')->target_id == $this->currentUser->id()) ? $this->t('You: ') : '';

    // 1. Prüfen, ob Bilder und/oder Text vorhanden sind.
    //    Ersetzen Sie 'images' mit dem Maschinennamen Ihres Dateifeldes!
    $has_images = !$last_message->get('images')->isEmpty();
    $message_text = $last_message->get('message')->value;
    $has_text = !empty(trim($message_text)); // trim() stellt sicher, dass Leerzeichen nicht als Text zählen

    $preview_content = '';

    // 2. Den passenden Vorschautext basierend auf dem Inhalt erstellen.
    if ($has_images && $has_text) {
      // Szenario: Bilder und Text
      $image_count = count($last_message->get('images'));
      $preview_content = $this->formatPlural($image_count, '1 🖼️ ', '@count 🖼️') . $message_text;
    } elseif ($has_images) {
      // Szenario: Nur Bilder
      // Optional: Anzahl der Bilder anzeigen
      $image_count = count($last_message->get('images'));
      $preview_content = $this->formatPlural($image_count, '1 🖼️', '@count 🖼️');
    } elseif ($has_text) {
      // Szenario: Nur Text (Ihr bisheriger Code)
      $preview_content = $message_text;
    }

    $text = $prefix . mb_strimwidth($preview_content, 0, 25, '...');

    return ['text' => $text, 'time' => $formatted_time, 'unread_count' => $unread_count,];
  }
}
