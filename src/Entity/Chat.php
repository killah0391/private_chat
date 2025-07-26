<?php
// private_chat/src/Entity/Chat.php

namespace Drupal\private_chat\Entity;

use Drupal\user\UserInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Definiert die Chat-Entität.
 *
 * @ContentEntityType(
 * id = "chat",
 * label = @Translation("Chat"),
 * handlers = {
 * "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 * "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 * "access" = "Drupal\private_chat\Access\ChatAccessControlHandler",
 * "views_data" = "Drupal\private_chat\ChatViewsData",
 * "route_provider" = {
 * "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 * },
 * },
 * base_table = "chat",
 * admin_permission = "administer site configuration",
 * entity_keys = {
 * "id" = "id",
 * "uuid" = "uuid",
 * "label" = "id",
 * },
 * links = {
 * "canonical" = "/chat/{chat_uuid}",
 * "delete-form" = "/chat/{chat}/delete",
 * },
 * translatable = FALSE,
 * )
 */

class Chat extends ContentEntityBase
{
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type)
  {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['user1'] = BaseFieldDefinition::create('entity_reference')->setLabel(t('Benutzer 1'))->setSetting('target_type', 'user')->setRequired(TRUE);
    $fields['user2'] = BaseFieldDefinition::create('entity_reference')->setLabel(t('Benutzer 2'))->setSetting('target_type', 'user')->setRequired(TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(t('Erstellt'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(t('Geändert'));
    return $fields;
  }
  public function getOtherParticipant(UserInterface $user)
  {
    if ($this->get('user1')->target_id == $user->id()) {
      return $this->get('user2')->entity;
    }
    if ($this->get('user2')->target_id == $user->id()) {
      return $this->get('user1')->entity;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities)
  {
    parent::preDelete($storage, $entities);

    // Sammle die IDs aller Chats, die gelöscht werden sollen.
    $chat_ids = [];
    foreach ($entities as $entity) {
      $chat_ids[] = $entity->id();
    }

    if (empty($chat_ids)) {
      return;
    }

    // Finde alle Nachrichten, die zu einem dieser Chats gehören,
    // in einer einzigen, effizienten Datenbankabfrage.
    $message_storage = \Drupal::entityTypeManager()->getStorage('message');
    $message_ids = $message_storage->getQuery()
      ->condition('chat_id', $chat_ids, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($message_ids)) {
      $messages = $message_storage->loadMultiple($message_ids);
      $message_storage->delete($messages);
    }
  }
}
