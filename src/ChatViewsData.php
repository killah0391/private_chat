<?php

namespace Drupal\private_chat;

use Drupal\views\EntityViewsData;

/**
 * Stellt Views-Daten für die Chat-Entität bereit.
 */
class ChatViewsData extends EntityViewsData
{

  /**
   * {@inheritdoc}
   */
  public function getViewsData()
  {
    $data = parent::getViewsData();

    // Hier könnten wir zusätzliche, komplexere Felder oder Beziehungen für Views definieren.
    // Für unsere Zwecke reicht die Standard-Implementierung von EntityViewsData aus,
    // um die Basis-Felder verfügbar zu machen.

    return $data;
  }
}
