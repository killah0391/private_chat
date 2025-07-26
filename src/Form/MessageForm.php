<?php

namespace Drupal\private_chat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\private_chat\Entity\Chat;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MessageForm extends FormBase
{
  protected $entityTypeManager;
  public function __construct(EntityTypeManagerInterface $entity_type_manager)
  {
    $this->entityTypeManager = $entity_type_manager;
  }
  public static function create(ContainerInterface $container)
  {
    return new static($container->get('entity_type.manager'));
  }
  public function getFormId()
  {
    return 'private_chat_message_form';
  }
  public function buildForm(array $form, FormStateInterface $form_state, Chat $chat = NULL)
  {
    $form_state->set('chat', $chat);

    // Der Wrapper, der durch AJAX ersetzt wird.
    $form['#prefix'] = '<div id="private-chat-form-wrapper">';
    $form['#suffix'] = '</div>';

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Deine Nachricht'),
      '#title_display' => 'invisible',
      '#placeholder' => $this->t('Schreibe eine Nachricht...'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Senden'),
      // Hier wird der Button als AJAX-Button konfiguriert
      '#ajax' => [
        'callback' => '::ajaxSubmitCallback',
        'wrapper' => 'private-chat-form-wrapper',
        // 'effect' => 'ease-in',
      ],
      '#attached' => [
        'library' => ['private_chat/chat-styling'], // Optional: eine CSS-Bibliothek hinzufügen
      ],
    ];

    return $form;
  }

  /**
   * Die AJAX-Callback-Funktion.
   */
  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();
    $chat = $form_state->get('chat');

    // Wir bauen hier die Nachrichtenliste exakt so neu auf, wie es der Controller tut.
    // Dadurch wird sichergestellt, dass das HTML identisch ist.
    $message_ids = $this->entityTypeManager->getStorage('message')->getQuery()->condition('chat_id', $chat->id())->sort('created', 'ASC')->accessCheck(FALSE)->execute();
    $messages = $this->entityTypeManager->getStorage('message')->loadMultiple($message_ids);

    $themed_messages = [];
    // Wir benötigen den DateFormatter Service hier. Da wir ihn nicht per DI injiziert haben,
    // verwenden wir ausnahmsweise den globalen Service-Aufruf.
    $date_formatter = \Drupal::service('date.formatter');

    foreach ($messages as $message) {
      $author_entity = $message->get('author')->entity;
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
        '#time' => $date_formatter->format($message->get('created')->value, 'custom', 'H:i'),
        '#sent_received' => ($author_entity->id() == $this->currentUser()->id()) ? 'sent' : 'received',
      ];
    }

    // Wir bauen das Render-Array für die *gesamte* Seite neu auf.
    $page_content = [
      '#theme' => 'private_chat_page',
      '#messages' => $themed_messages,
      '#form' => $form,
    ];

    // Und ersetzen den gesamten Wrapper mit dem neu aufgebauten Inhalt.
    $response->addCommand(new ReplaceCommand('#private-chat-wrapper', $page_content));

    $response->addCommand(new InvokeCommand('textarea[name="message"]', 'val', ['']));

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
    $message = $form_state->getValue('message');

    if(!(trim($message))) {
      $form_state->setErrorByName('message', $this->t('Du musst eine Nachricht eingeben.'));
    }
  }
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Diese Funktion speichert die Nachricht. Sie wird VOR dem AJAX-Callback ausgeführt.
    $chat = $form_state->get('chat');
    $this->entityTypeManager->getStorage('message')->create([
      'chat_id' => $chat->id(),
      'author' => $this->currentUser()->id(),
      'message' => $form_state->getValue('message'),
    ])->save();

    // Wichtig für AJAX: Das Formular zurücksetzen, damit das Textfeld nach dem Senden leer ist.
    $form_state->setRebuild();
  }
}
