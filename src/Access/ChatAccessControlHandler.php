<?php

namespace Drupal\private_chat\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

class ChatAccessControlHandler extends EntityAccessControlHandler
{
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account)
  {
    switch ($operation) {
      case 'view':
        $participants = [
          $entity->get('user1')->target_id,
          $entity->get('user2')->target_id,
        ];
        return AccessResult::allowedIf(in_array($account->id(), $participants));

      case 'delete':
        // Prüfe auf die neue Berechtigung für die "delete"-Operation.
        return AccessResult::allowedIfHasPermission($account, 'delete private chat entities');
    }
    // Für alle anderen Operationen neutral bleiben.
    return AccessResult::neutral();
  }
}
