<?php

namespace Drupal\private_chat\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * @ContentEntityType(
 * id = "message",
 * label = @Translation("Nachricht"),
 * handlers = {
 * "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 * "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 * "form" = {
 * "default" = "Drupal\Core\Entity\ContentEntityForm",
 * "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 * },
 * "route_provider" = {
 * "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 * },
 * },
 * base_table = "message",
 * admin_permission = "administer site configuration",
 * entity_keys = {
 * "id" = "id",
 * "uuid" = "uuid",
 * },
 * links = {
 * "canonical" = "/message/{message}",
 * "delete-form" = "/message/{message}/delete",
 * "collection" = "/admin/content/messages",
 * },
 * )
 */
class Message extends ContentEntityBase implements ContentEntityInterface
{
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type)
  {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['chat_id'] = BaseFieldDefinition::create('entity_reference')->setLabel(t('Chat'))->setSetting('target_type', 'chat')->setRequired(TRUE)->setDisplayConfigurable('form', TRUE)->setDisplayConfigurable('view', TRUE);
    $fields['author'] = BaseFieldDefinition::create('entity_reference')->setLabel(t('Autor'))->setSetting('target_type', 'user')->setRequired(TRUE)->setDisplayConfigurable('form', TRUE)->setDisplayConfigurable('view', TRUE);
    $fields['message'] = BaseFieldDefinition::create('text_long')->setLabel(t('Nachricht'))->setRequired(TRUE)->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'text_default', 'weight' => 0])->setDisplayConfigurable('form', TRUE)->setDisplayConfigurable('view', TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(t('Erstellt'))->setDisplayConfigurable('view', TRUE);
    $fields['is_read'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Gelesen'))
      ->setDescription(t('Gibt an, ob die Nachricht vom Empfänger gelesen wurde.'))
      ->setDefaultValue(FALSE)
      ->setRequired(TRUE);
    $fields['images'] = BaseFieldDefinition::create('image')
      ->setLabel(t('Images'))
      ->setDescription(t('Images attached to the message.'))
      ->setCardinality(3) // Erlaubt bis zu 3 Bilder
      ->setSetting('file_extensions', 'png jpg jpeg gif')
      ->setSetting('alt_field', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'image',
        'weight' => 1,
        'settings' => ['image_style' => 'medium'], // Passen Sie den Bildstil an
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }
}
