<?php

namespace Drupal\private_chat\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\private_chat\Entity\Chat;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller für die Anzeige einer einzelnen Chat-Seite.
 */
class ChatController extends ControllerBase {

  /**
   * Der Entity Type Manager.
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Der Date Formatter Service.
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Der aktuelle Benutzer.
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  protected $renderer;

  /**
   * ChatController constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, DateFormatterInterface $date_formatter, RendererInterface $renderer) {
    // Wir übernehmen die drei Dienste aus der services.yml-Datei.
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->dateFormatter = $date_formatter;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Wir holen die drei Dienste aus dem Container.
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('date.formatter'),
      $container->get('renderer'),
    );
  }

  /**
   * Startet einen neuen Chat oder leitet zu einem bestehenden weiter.
   */
  public function startChat(\Drupal\user\UserInterface $user) {
    $currentUserId = $this->currentUser->id();
    $targetUserId = $user->id();
    if ($currentUserId == $targetUserId) {
      throw new NotFoundHttpException();
    }
    $query = $this->entityTypeManager->getStorage('chat')->getQuery();
    $group = $query->orConditionGroup()->condition($query->andConditionGroup()->condition('user1', $currentUserId)->condition('user2', $targetUserId))->condition($query->andConditionGroup()->condition('user1', $targetUserId)->condition('user2', $currentUserId));
    $query->condition($group);
    $chat_ids = $query->accessCheck(FALSE)->execute();
    if (!empty($chat_ids)) {
      $chat = $this->entityTypeManager->getStorage('chat')->load(reset($chat_ids));
    } else {
      $chat = Chat::create(['user1' => $currentUserId, 'user2' => $targetUserId]);
      $chat->save();
    }
    // Hinweis: Die Route 'entity.chat.canonical' existiert in unserer neuen UI nicht mehr direkt.
    // Wir leiten stattdessen zur neuen UI-Seite weiter.
    return $this->redirect('private_chat.ui', ['chat_uuid' => $chat->uuid()]);
  }

  /**
   * Baut das Render-Array für eine einzelne Chat-Seite.
   */
  public function chatPage(string $chat_uuid)
  {
    $chat_ids = $this->entityTypeManager->getStorage('chat')->getQuery()
      ->condition('uuid', $chat_uuid)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (empty($chat_ids)) {
      throw new NotFoundHttpException();
    }

    $chat = $this->entityTypeManager->getStorage('chat')->load(reset($chat_ids));

    $participants = [$chat->get('user1')->target_id, $chat->get('user2')->target_id];
    if (!in_array($this->currentUser->id(), $participants)) {
      throw new AccessDeniedHttpException();
    }

    $currentUserEntity = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    // === SCHRITT 1: NACHRICHTEN LADEN ===
    $message_ids = $this->entityTypeManager->getStorage('message')->getQuery()
      ->condition('chat_id', $chat->id())
      ->sort('created', 'DESC') // Absteigend sortieren
      ->range(0, 20) // Nur 20 Ergebnisse
      ->accessCheck(FALSE)
      ->execute();
    $messages = $this->entityTypeManager->getStorage('message')->loadMultiple(array_reverse($message_ids));

    // === SCHRITT 2: ANZEIGE ZUERST RENDERN ===
    // Wir erstellen das HTML, während ungelesene Nachrichten noch den Status 'FALSE' haben.
    // So wird die 'unread'-Klasse korrekt zugewiesen.
    $themed_messages = [];
    $messages_to_mark_as_read = []; // Wir sammeln hier Nachrichten, die wir später speichern.

    foreach ($messages as $message) {
      $author_entity = $message->get('author')->entity;

      // Prüfen, ob die Nachricht als gelesen markiert werden muss.
      $is_receiver_and_unread = ($author_entity->id() != $this->currentUser->id() && !$message->get('is_read')->value);
      if ($is_receiver_and_unread) {
        // Wir merken uns diese Nachricht für später.
        $messages_to_mark_as_read[] = $message;
      }

      // Logik für die Anzeige-Klassen (Sender vs. Empfänger)
      $status_class = '';
      if ($author_entity->id() == $this->currentUser->id()) {
        $status_class = $message->get('is_read')->value ? 'is-read' : 'is-unread';
      }

      $status_receiver_class = '';
      if ($author_entity->id() !== $this->currentUser->id()) {
        // Hier wird jetzt 'unread' korrekt angewendet, da wir noch nichts gespeichert haben.
        $status_receiver_class = $is_receiver_and_unread ? 'unread' : 'read';
      }

      // Zeitformatierung (unverändert)
      $timestamp = $message->get('created')->value;
      $now = \Drupal::time()->getRequestTime();
      $difference = $now - $timestamp;

      $formatted_time = '';
      if ($difference < 1800) {
        $formatted_time = $this->t('vor @time', ['@time' => $this->dateFormatter->formatInterval($difference, 1)]);
      } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', $now)) {
        $formatted_time = $this->t('@time', ['@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i')]);
      } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', strtotime('-1 day', $now))) {
        $formatted_time = $this->t('Gestern, @time', ['@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i')]);
      } else {
        $formatted_time = $this->dateFormatter->format($timestamp, 'medium', 'H:i');
      }

      $themed_messages[] = [
        '#theme' => 'private_chat_message',
        '#author_name' => $author_entity->getDisplayName(),
        '#author_picture' => $author_entity->get('field_profile_picture')->view(['label' => 'hidden', 'type' => 'image', 'settings' => ['image_style' => 'chat_small']]),
        '#body' => ['#type' => 'processed_text', '#text' => $message->get('message')->value, '#format' => $message->get('message')->format],
        '#time' => $formatted_time,
        '#sent_received' => ($author_entity->id() == $this->currentUser->id()) ? 'sent' : 'received',
        '#status_class' => $status_class,
        '#status_receiver_class' => $status_receiver_class,
        '#message_id' => $message->id(),
      ];
    }

    // === SCHRITT 3: NACH DEM RENDERN SPEICHERN ===
    // Jetzt, wo die Anzeige fertig ist, aktualisieren wir die Datenbank.
    foreach ($messages_to_mark_as_read as $message) {
      $message->set('is_read', TRUE);
      $message->save();
    }

    $form = $this->formBuilder()->getForm('\Drupal\private_chat\Form\MessageForm', $chat);

    return [
      '#theme' => 'private_chat_page',
      '#messages' => $themed_messages,
      '#chat_uuid' => $chat->uuid(),
      '#form' => $form,
      '#title' => $this->t('Chat mit @username', ['@username' => $chat->getOtherParticipant($currentUserEntity)->getDisplayName()]),
      '#attached' => ['library' => ['private_chat/chat-styling']],
      '#cache' => ['contexts' => ['user'], 'tags' => ['message_list:' . $chat->id()]],
    ];
  }

  /**
   * Lädt ältere Nachrichten für den "Infinite Scroll".
   * NEUE METHODE
   */
  public function loadMoreMessages(Request $request, string $chat_uuid)
  {
    // Chat laden und Sicherheitsprüfung durchführen (wie in chatPage)
    $chat_ids = $this->entityTypeManager->getStorage('chat')->getQuery()->condition('uuid', $chat_uuid)->accessCheck(FALSE)->range(0, 1)->execute();
    if (empty($chat_ids)) {
      throw new NotFoundHttpException();
    }
    $chat = $this->entityTypeManager->getStorage('chat')->load(reset($chat_ids));
    $participants = [$chat->get('user1')->target_id, $chat->get('user2')->target_id];
    if (!in_array($this->currentUser->id(), $participants)) {
      throw new AccessDeniedHttpException();
    }

    // Die ID der ältesten Nachricht aus der URL holen
    $oldest_message_id = $request->query->get('oldest_message_id');
    $oldest_message = $this->entityTypeManager->getStorage('message')->load($oldest_message_id);

    if (!$oldest_message) {
      // Wenn die Nachricht nicht existiert, gibt es nichts zu laden.
      return new AjaxResponse();
    }

    // Die nächsten 20 Nachrichten laden, die ÄLTER sind als die gegebene.
    $message_ids = $this->entityTypeManager->getStorage('message')->getQuery()
      ->condition('chat_id', $chat->id())
      ->condition('created', $oldest_message->get('created')->value, '<')
      ->sort('created', 'DESC')
      ->range(0, 20)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($message_ids)) {
      // Keine älteren Nachrichten gefunden
      return new AjaxResponse();
    }

    $messages = $this->entityTypeManager->getStorage('message')->loadMultiple(array_reverse($message_ids));

    // Nachrichten mit derselben Logik wie in chatPage rendern
    $themed_messages = [];
    foreach ($messages as $message) {
      $author_entity = $message->get('author')->entity;

      $status_class = '';
      if ($author_entity->id() == $this->currentUser->id()) {
        $status_class = $message->get('is_read')->value ? 'is-read' : 'is-unread';
      }

      $status_receiver_class = '';
      if ($author_entity->id() !== $this->currentUser->id()) {
        // Für ältere Nachrichten ist der Status irrelevant, wir können ihn einfach auf 'read' setzen.
        $status_receiver_class = 'read';
      }

      $timestamp = $message->get('created')->value;
      $now = \Drupal::time()->getRequestTime();
      $difference = $now - $timestamp;

      $formatted_time = '';
      if ($difference < 1800) {
        $formatted_time = $this->t('vor @time', ['@time' => $this->dateFormatter->formatInterval($difference, 1)]);
      } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', $now)) {
        $formatted_time = $this->t('@time', ['@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i')]);
      } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', strtotime('-1 day', $now))) {
        $formatted_time = $this->t('Gestern, @time', ['@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i')]);
      } else {
        $formatted_time = $this->dateFormatter->format($timestamp, 'medium', 'H:i');
      }

      $themed_messages[] = [
        '#theme' => 'private_chat_message',
        '#author_name' => $author_entity->getDisplayName(),
        '#author_picture' => $author_entity->get('field_profile_picture')->view(['label' => 'hidden', 'type' => 'image', 'settings' => ['image_style' => 'chat_small']]),
        '#body' => ['#type' => 'processed_text', '#text' => $message->get('message')->value, '#format' => $message->get('message')->format],
        '#time' => $formatted_time,
        '#sent_received' => ($author_entity->id() == $this->currentUser->id()) ? 'sent' : 'received',
        '#status_class' => $status_class,
        '#status_receiver_class' => $status_receiver_class,
        '#message_id' => $message->id(),
      ];
    }

    $build = [
      '#theme' => 'private_chat_messages_container', // Ein einfaches Twig-Template, das nur die Nachrichten rendert
      '#messages' => $themed_messages,
    ];

    $html = $this->renderer->renderRoot($build);

    // Das gerenderte HTML an den Anfang des Containers einfügen
    $response = new AjaxResponse();
    $response->addCommand(new PrependCommand('.chat-messages ul', $html));
    return $response;
  }
}
