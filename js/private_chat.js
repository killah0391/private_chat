(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.privateChatMobile = {
    attach: function (context, settings) {

      // 1. Handler für den Haupt-Toggle-Button (via Event-Delegation)
      once('privateChatMobileContainer', '.chat-ui-container', context).forEach(function (container) {
        var $chatContainer = $(container);
        $chatContainer.on('click.privateChat.toggle', '.chat-toggle-sidebar-btn', function (e) {
          e.preventDefault();
          $chatContainer.toggleClass('sidebar-is-visible');
        });
      });

      // 2. Handler für die Chat-Links (via direkter Bindung)
      // Das Ergebnis von once() wird hier in ein jQuery-Objekt umgewandelt.
      var $chatLinks = $(once('privateChatMobileLink', '.chat-ui-sidebar .chat-list-item a', context)); // <--- HIER IST DIE KORREKTUR

      $chatLinks.on('click.privateChatLink', function () {
        // Prüfen, ob wir in der mobilen Ansicht sind.
        if (window.innerWidth < 992) {
          // Den nächstgelegenen Container finden und die Sidebar schließen.
          $(this).closest('.chat-ui-container').removeClass('sidebar-is-visible');
        }
      });

    }
  };

})(jQuery, Drupal, once);
