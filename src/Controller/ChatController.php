<?php

namespace Drupal\private_chat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\private_chat\Entity\Chat;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

  /**
   * ChatController constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, DateFormatterInterface $date_formatter) {
    // Wir übernehmen die drei Dienste aus der services.yml-Datei.
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Wir holen die drei Dienste aus dem Container.
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('date.formatter')
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
    // === KORREKTUR START ===
    // Wir ersetzen loadByProperties durch eine explizite entityQuery,
    // um die implizite Zugriffskontrolle zu umgehen, die im AJAX-Kontext fehlschlägt.
    $chat_ids = $this->entityTypeManager->getStorage('chat')->getQuery()
      ->condition('uuid', $chat_uuid)
      ->accessCheck(FALSE) // Deaktiviert die problematische Zugriffskontrolle an DIESER Stelle
      ->range(0, 1)
      ->execute();

    if (empty($chat_ids)) {
      // Wenn wirklich kein Chat mit dieser UUID existiert, erzeugen wir den 404-Fehler.
      throw new NotFoundHttpException();
    }

    $chat = $this->entityTypeManager->getStorage('chat')->load(reset($chat_ids));
    // === KORREKTUR ENDE ===

    // Diese manuelle Zugriffskontrolle bleibt bestehen und sichert den Chat ab!
    $participants = [$chat->get('user1')->target_id, $chat->get('user2')->target_id];
    if (!in_array($this->currentUser->id(), $participants)) {
      throw new AccessDeniedHttpException();
    }

    $currentUserEntity = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    $message_ids = $this->entityTypeManager->getStorage('message')->getQuery()->condition('chat_id', $chat->id())->sort('created', 'ASC')->accessCheck(FALSE)->execute();
    $messages = $this->entityTypeManager->getStorage('message')->loadMultiple($message_ids);

    $save_needed = FALSE;
    foreach ($messages as $message) {
      // Nur Nachrichten als gelesen markieren, die nicht vom aktuellen Benutzer stammen und ungelesen sind.
      if ($message->get('author')->target_id != $this->currentUser->id() && !$message->get('is_read')->value) {
        $message->set('is_read', TRUE);
        $message->save();
      }
    }

    $themed_messages = [];
    foreach ($messages as $message) {
      $author_entity = $message->get('author')->entity;
      $status_class = ''; // Standardmäßig keine Klasse
      if ($author_entity->id() == $this->currentUser->id()) {
        // Wenn der aktuelle Benutzer der Autor ist, prüfe den Lesestatus.
        $status_class = $message->get('is_read')->value ? 'is-read' : 'is-unread';
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
        '#message_id' => $message->id(),
      ];
    }

    $form = $this->formBuilder()->getForm('\Drupal\private_chat\Form\MessageForm', $chat);

    return [
      '#theme' => 'private_chat_page',
      '#messages' => $themed_messages,
      '#form' => $form,
      '#title' => $this->t('Chat mit @username', ['@username' => $chat->getOtherParticipant($currentUserEntity)->getDisplayName()]),
      '#attached' => [
        'library' => ['private_chat/chat-styling'], // Optional: eine CSS-Bibliothek hinzufügen
      ],
      '#cache' => [
        // Diese Zeile sagt Drupal: "Der Inhalt dieser Seite ist pro Benutzer unterschiedlich."
        'contexts' => ['user'],
        'tags' => ['message_list:' . $chat->id()],
      ],
    ];
  }
}
