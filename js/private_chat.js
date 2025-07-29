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
      var $sendButton = $(once('private-chat-send-button', 'form.private-chat-message-form .form-submit', context));
      $sendButton.on('click', function () {
        // DEBUG: Diese Nachricht sollte in der Konsole erscheinen, wenn du klickst.
        console.log('Sende-Button geklickt! Füge Markierung hinzu.');

        // Fügt die Klasse hinzu, BEVOR der AJAX-Request startet.
        $(this).closest('.chat-ui-container').find('.chat-messages').addClass('is-sending-message');
      });

    }
  };

  /**
   * Behavior für das automatische Scrollen im Chat-Thread.
   * FINALE VERSION
   */
  Drupal.behaviors.privateChatScrolling = {
    attach: function (context, settings) {
      once('privateChatScroll', '.chat-messages', context).forEach(function (threadElement) {
        const $chatThread = $(threadElement);

        // Finde die erste ungelesene Nachricht.
        const $firstUnread = $chatThread.find('.chat-message.unread').first();

        // FALL 1: Es gibt ungelesene Nachrichten
        if ($firstUnread.length) {
          // Wir springen SOFORT zur Position der ersten ungelesenen Nachricht.
          console.log('Ungelesene Nachricht gefunden. Springe sofort dorthin.');

          const containerTop = $chatThread.scrollTop();
          const elementTop = $firstUnread.position().top;

          // Der Sprung erfolgt ohne Animation. Ein kleiner Abstand von 30px bleibt.
          $chatThread.scrollTop(containerTop + elementTop - 30);
        }
        // FALL 2: Es gibt KEINE ungelesenen Nachrichten
        else {
          // Wir springen SOFORT und OHNE Verzögerung ans Ende.
          $chatThread.scrollTop($chatThread.prop("scrollHeight"));
          console.log('Keine ungelesenen Nachrichten. Springe sofort ans Ende.');
        }
      });
    }
  };

  /**
 * Behavior für das Nachladen von Nachrichten beim Scrollen ("Lazy Loading").
 */
  Drupal.behaviors.privateChatLazyLoad = {
    attach: function (context, settings) {
      once('privateChatLazyLoad', '.chat-messages', context).forEach(function (threadElement) {
        const $chatThread = $(threadElement);
        let isLoading = false; // Verhindert mehrfaches Laden

        $chatThread.on('scroll', function () {
          // Prüfen, ob der Benutzer ganz oben ist und gerade nicht schon geladen wird.
          if ($chatThread.scrollTop() === 0 && !isLoading) {
            isLoading = true;
            console.log('Oben erreicht, lade mehr Nachrichten...');

            const $firstMessage = $chatThread.find('.chat-message').first();
            if (!$firstMessage.length) {
              isLoading = false;
              return;
            }
            const oldestMessageId = $firstMessage.attr('id').replace('message-', '');

            // 1. VOR dem Laden: Alte Höhe des scrollbaren Inhalts speichern.
            // Wir verwenden $chatThread[0], um auf das reine DOM-Element zuzugreifen.
            const oldScrollHeight = $chatThread[0].scrollHeight;

            const chatWrapper = document.getElementById('private-chat-wrapper');
            // Die UUID muss im Twig-Template private-chat-page.html.twig im data-Attribut stehen:
            // <div id="private-chat-wrapper" data-chat-uuid="{{ chat.uuid }}">
            const chatUuid = chatWrapper ? chatWrapper.dataset.chatUuid : null;

            if (!chatUuid) {
              console.error('Chat-UUID nicht im data-chat-uuid Attribut gefunden.');
              isLoading = false;
              return;
            }

            const ajaxUrl = `/chat/${chatUuid}/load-more?oldest_message_id=${oldestMessageId}`;
            const ajax = Drupal.ajax({ url: ajaxUrl });

            // Wir "patchen" die success-Methode, um nach der DOM-Änderung eingreifen zu können.
            const originalSuccess = ajax.options.success;
            ajax.options.success = (response, status, xhr) => {
              // Führe die originale Drupal-Logik aus (diese fügt das neue HTML ein).
              originalSuccess(response, status, xhr);

              // WICHTIG: Wir warten mit einem minimalen Timeout.
              // Das gibt dem Browser Zeit, das neue HTML zu rendern und die Höhe neu zu berechnen.
              setTimeout(() => {
                // 2. NACH dem Laden: Neue Höhe des Inhalts abfragen.
                const newScrollHeight = $chatThread[0].scrollHeight;

                // 3. Die Differenz ist die Höhe des neu geladenen Inhalts.
                const heightDifference = newScrollHeight - oldScrollHeight;

                // 4. Setze die Scrollbar genau um diese Differenz nach unten.
                $chatThread.scrollTop(heightDifference);

                console.log('Nachrichten geladen und Scroll-Position korrigiert.');
                isLoading = false; // Laden für die nächste Interaktion freigeben.
              }, 10); // Ein kleiner Wert (sogar 0) reicht meist aus.
            };

            ajax.execute();
          }
        });
      });
    }
  };

})(jQuery, Drupal, once);
