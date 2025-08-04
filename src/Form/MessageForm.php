<?php

namespace Drupal\private_chat\Form;

use Drupal\file\Entity\File;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\private_chat\Entity\Chat;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\private_chat\Controller\ChatController;
use Drupal\private_chat\Controller\ChatUIController;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MessageForm extends FormBase
{
  protected $entityTypeManager;
  protected $chatUiController;
  protected $dateFormatter;
  protected $currentUser;
  protected $formBuilder;
  protected $chatController;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, ChatUIController $chat_ui_controller, DateFormatterInterface $date_formatter, AccountInterface $current_user, FormBuilderInterface $form_builder, ChatController $chat_controller)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->chatUiController = $chat_ui_controller;
    $this->dateFormatter = $date_formatter;
    $this->currentUser = $current_user;
    $this->formBuilder = $form_builder;
    $this->chatController = $chat_controller;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('private_chat.chat_ui_controller'),
      $container->get('date.formatter'),
      $container->get('current_user'),
      $container->get('form_builder'),
      $container->get('private_chat.chat_controller')
    );
  }
  public function getFormId()
  {
    return 'private_chat_message_form';
  }
  public function buildForm(array $form, FormStateInterface $form_state, Chat $chat = NULL, array $block_info = NULL)
  {
    if (!$chat) {
      return [];
    }
    $form_state->set('chat', $chat);
    $current_user_id = $this->currentUser()->id();

    // Der Wrapper, der durch AJAX ersetzt wird.
    $form['#prefix'] = '<div id="private-chat-form-wrapper">';
    $form['#suffix'] = '</div>';

    $user1_id = $chat->get('user1')->target_id;
    $is_user1 = ($current_user_id == $user1_id);

    $my_consent_field = $is_user1 ? 'user1_consent' : 'user2_consent';
    $other_consent_field = $is_user1 ? 'user2_consent' : 'user1_consent';

    $i_have_consented = $chat->get($my_consent_field)->value;
    $other_has_consented = $chat->get($other_consent_field)->value;
    $uploads_allowed = $i_have_consented && $other_has_consented;

    $config = $this->config('private_chat.settings');
    $global_uploads_enabled = (bool) $config->get('allow_uploads');

    // Die Variable $uploads_allowed kommt vermutlich bereits aus Ihrer bestehenden Logik.
    // Beispiel: $uploads_allowed = $chat->userHasUploadPermission();

    // 2. Kombinieren Sie die globale Einstellung mit der lokalen Berechtigung.
    $final_access = $global_uploads_enabled && $uploads_allowed;

    $form['message_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['message-input-container', 'position-relative', 'mb-0']],
    ];

    // $form['message_container']['consent'] = [
    //   '#type' => 'checkbox',
    //   '#title' => $this->t('Allow image uploads for this Chat.'),
    //   '#default_value' => $i_have_consented,
    //   '#weight' => 100,
    //   '#ajax' => [
    //     'callback' => '::ajaxConsentCallback',
    //     'wrapper' => 'private-chat-form-wrapper', // Das ganze Formular neu laden
    //   ],
    //   '#access' => $global_uploads_enabled,
    // ];
    // if ($uploads_allowed) {
    //   $form['message_container']['consent']['#description'] = $this->t('Image uploads active. Remove checkbox to disable.');
    // } elseif ($i_have_consented && !$other_has_consented) {
    //   $form['message_container']['consent']['#description'] = $this->t('You have accepted. Wait for other user to accept.');
    // }



    // 2. Upload-Feld
    $form['message_container']['images'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Bilder anhängen'),
      '#upload_location' => 'private://private_chat/'.$chat->uuid(),
      '#multiple' => TRUE,
      '#weight' => 200,
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg gif'],
        'file_validate_image_resolution' => ['800x600'],
      ],
      '#access' => $final_access, // Nur anzeigen, wenn beide zugestimmt haben
      // '#theme' => 'image_widget',
      // '#preview_image_style' => 'chat_small',
    ];

    $form['image_previews'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'chat-image-previews-' . $chat->id()],
      '#weight' => -5, // Place it above the file input element.
      '#access' => $final_access,
    ];

    // Hidden field to track FIDs of removed images.
    // JS should populate this with a comma-separated list of FIDs.
    $form['images_to_remove'] = [
      '#type' => 'hidden',
      '#default_value' => '',
      '#attributes' => ['id' => 'edit-images-to-remove-' . $chat->id()],
      '#access' => $final_access,
    ];

    $is_blocked = $block_info['is_blocked'] ?? FALSE;


    $form['message_container']['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your message'),
      '#rows' => 3,
      '#title_display' => 'invisible',
      '#placeholder' => $is_blocked ? $this->t('This user is blocked! To send a message, unblock them.') : $this->t('Send a message...'),
      '#disabled' => $is_blocked,
      // '#prefix' => '<div class="chat-block-notification">',
      // '#suffix' => '</div>',
    ];

    // if($is_blocked == TRUE) {
    //   $form['blocked'] = ['#description' => $block_info['block_message'],
    //     '#prefix' => '<div class="chat-block-notification">',
    //     '#suffix' => '</div>',];
    // }

    $form['message_container']['actions']['#type'] = 'actions';
    $form['message_container']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
      // Hier wird der Button als AJAX-Button konfiguriert
      '#ajax' => [
        'callback' => '::ajaxSubmitCallback',
        'wrapper' => 'private-chat-form-wrapper',
        // 'effect' => 'ease-in',
      ],
      '#attached' => [
        'library' => ['private_chat/chat-styling'], // Optional: eine CSS-Bibliothek hinzufügen
      ],
      '#disabled' => $is_blocked,
    ];

    if (isset($form['images'])) {
      $form['message_container']['images']['#disabled'] = $is_blocked;
    }

    return $form;
  }

  /**
   * AJAX Callback für die Zustimmungs-Checkbox.
   */
  public function ajaxConsentCallback(array &$form, FormStateInterface $form_state)
  {
    /** @var \Drupal\private_chat\Entity\Chat $chat */
    $chat = $form_state->get('chat');
    $current_user_id = $this->currentUser()->id();
    $is_user1 = ($current_user_id == $chat->get('user1')->target_id);
    $my_consent_field = $is_user1 ? 'user1_consent' : 'user2_consent';

    $consent_value = (bool) $form_state->getValue('consent');

    $chat->set($my_consent_field, $consent_value);
    $chat->save();

    $rebuild_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#private-chat-form-wrapper', $rebuild_form));

    return $response;
  }

  /**
   * Die AJAX-Callback-Funktion.
   */
  // In killah0391/private_chat/private_chat-421bae0c4a3b761c99009a1c41b3d7b8fb8461c0/src/Form/MessageForm.php

  /**
   * Die AJAX-Callback-Funktion (vereinfachte Version).
   */
  // Ersetzen Sie die ajaxSubmitCallback in src/Form/MessageForm.php

  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();
    $chat = $form_state->get('chat');

    // 1. Hole das gerenderte HTML für die neuen Nachrichten.
    $messages_render_array = $this->chatController->buildMessagesRenderArray($chat);

    // 2. Hole das gerenderte HTML für die aktualisierte Seitenleiste.
    $chat_list_render_array = $this->chatUiController->_buildChatListRenderArray($chat->uuid());

    // 4. Erstelle die AJAX-Befehle, um die Seite zu aktualisieren.
    // Ersetzt den Inhalt des ul-Elements mit den neuen Nachrichten.
    $response->addCommand(new HtmlCommand('.chat-messages ul', $messages_render_array));

    // Ersetzt die Chat-Liste in der Seitenleiste.
    $response->addCommand(new HtmlCommand('#chat-list-wrapper', $chat_list_render_array));

    // Ersetzt das gesamte Formular-Wrapper durch die neu aufgebaute, leere Version.
    // $rebuilt_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    $response->addCommand(new ReplaceCommand('#private-chat-form-wrapper', $form));

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
    $chat = $form_state->get('chat');
    $currentUser = $this->entityTypeManager->getStorage('user')->load($this->currentUser()->id());
    $otherParticipant = $chat->getOtherParticipant($currentUser);

    /** @var \Drupal\user_blocker\BlockManager $blockManager */
    // Service-Namen aktualisieren
    $blockManager = \Drupal::service('user_blocker.block_manager');
    $blocker = $blockManager->getBlocker($currentUser, $otherParticipant);

    if ($blocker) {
      $form_state->setErrorByName('message', $this->t('This conversation is blocked.'));
    }

    $message = $form_state->getValue('message');

    // 1. Holen Sie die FIDs, die durch das JS zum Entfernen markiert wurden.
    $fids_to_remove_raw = $form_state->getValue('images_to_remove');
    $fids_to_remove = !empty($fids_to_remove_raw) ? explode(',', $fids_to_remove_raw) : [];

    // 2. Holen Sie alle FIDs, die das managed_file-Element aktuell enthält.
    $submitted_fids = $form_state->getValue('images');

    // 3. Berechnen Sie die finale Liste der Bilder, indem Sie die zu entfernenden abziehen.
    $final_fids = array_diff($submitted_fids, $fids_to_remove);

    // 4. Führen Sie die Validierung gegen die BEREINIGTE Liste der Bilder durch.
    if (empty(trim($message)) && empty($final_fids)) {
      $form_state->setErrorByName('message', $this->t('You need to enter a message or upload atleast one image.'));
    }
  }
  // public function submitForm(array &$form, FormStateInterface $form_state)
  // {
  //   // Diese Funktion speichert die Nachricht. Sie wird VOR dem AJAX-Callback ausgeführt.
  //   $chat = $form_state->get('chat');
  //   $this->entityTypeManager->getStorage('message')->create([
  //     'chat_id' => $chat->id(),
  //     'author' => $this->currentUser()->id(),
  //     'message' => $form_state->getValue('message'),
  //   ])->save();

  //   // Wichtig für AJAX: Das Formular zurücksetzen, damit das Textfeld nach dem Senden leer ist.
  //   $form_state->setRebuild();
  // }
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    /** @var \Drupal\private_chat\Entity\Chat $chat */
    $chat = $form_state->get('chat');
    $message_text = $form_state->getValue('message');
    $current_user_id = $this->currentUser()->id();

    // Nachricht erstellen
    $message = $this->entityTypeManager->getStorage('message')->create([
      'chat_id' => $chat->id(),
      'author' => $current_user_id,
      'message' => $message_text,
    ]);

    $fids_to_remove_raw = $form_state->getValue('images_to_remove');
    $fids_to_remove = !empty($fids_to_remove_raw) ? explode(',', $fids_to_remove_raw) : [];

    // 2. Holen Sie die Liste aller ursprünglich hochgeladenen FIDs aus dem managed_file Element.
    // Der Schlüssel 'images' muss dem Namen Ihres file-Elements entsprechen.
    $submitted_fids = $form_state->getValue('images');

    // 3. Filtern Sie die zu entfernenden FIDs aus der Liste der übermittelten FIDs.
    // Das Ergebnis ist die endgültige Liste der FIDs, die gespeichert werden sollen.
    $final_fids = array_diff($submitted_fids, $fids_to_remove);

    // 4. (Optional, aber empfohlen) Sorgen Sie dafür, dass die entfernten Dateien
    // als temporär markiert bleiben, damit sie später von Drupal bereinigt werden können.
    // Wenn Sie die Dateien sofort löschen wollen, seien Sie vorsichtig.
    if (!empty($fids_to_remove)) {
      $files_to_delete = File::loadMultiple($fids_to_remove);
      foreach ($files_to_delete as $file) {
        // Setzen Sie den Status auf temporär, damit Cron sie löschen kann,
        // oder rufen Sie $file->delete() auf, um sie sofort zu löschen.
        // Das Setzen auf temporär ist sicherer.
        $file->setTemporary();
        $file->save();
      }
    }

    if (empty(trim($message_text)) && empty($final_fids)) {
      // Nichts zu senden, den Rebuild trotzdem signalisieren, um Fehler zu vermeiden.
      $form_state->setRebuild(TRUE);
      return;
    }

    // Hochgeladene Bilder verarbeiten (Ihre Logik ist hier korrekt)
    if (!empty($final_fids)) {
      $files = \Drupal\file\Entity\File::loadMultiple($final_fids);
      foreach ($files as $file) {
        $file->setPermanent();
        $file->save();
      }
      $message->set('images', $final_fids);
    }

    $message->save();

    // WICHTIG: Formularwerte zurücksetzen und Neuaufbau signalisieren.
    $form_state->setValue('images', []);
    $form_state->setValue('message', '');
    $form_state->setRebuild(TRUE);
    $user_input = $form_state->getUserInput();
    unset($user_input['images']);
    unset($user_input['message']);
    $form_state->setUserInput($user_input);
  }
}
