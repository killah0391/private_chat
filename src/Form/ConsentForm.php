<?php

namespace Drupal\private_chat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\private_chat\Entity\Chat;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles the consent checkbox form with AJAX updates.
 */
class ConsentForm extends FormBase
{

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;
  protected $entityTypeManager;

  /**
   * Constructs a new ConsentForm.
   */
  public function __construct(FormBuilderInterface $form_builder, EntityTypeManagerInterface $entity_type_manager)
  {
    $this->formBuilder = $form_builder;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'private_chat_consent_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Chat $chat = NULL)
  {
    if (!$chat) {
      return [];
    }

    // Eindeutige Wrapper-ID für dieses Formular.
    $wrapper_id = 'private-chat-consent-form-wrapper';
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';

    $form_state->set('chat', $chat);
    $current_user_id = $this->currentUser()->id();
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

    $form['dropdown_wrapper_id'] = ['#type' => 'hidden', '#value' => $wrapper_id];

    $form['consent'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow image uploads in this chat.'),
      '#default_value' => $i_have_consented,
      '#ajax' => [
        'callback' => '::ajaxUpdateFormsCallback',
        'wrapper' => $wrapper_id, // Wichtig: Der Callback zielt auf seinen *eigenen* Wrapper
        'event' => 'change',
      ],
      '#access' => $global_uploads_enabled
    ];

    if ($uploads_allowed) {
      $form['consent']['#description'] = $this->t('Image uploads active. Remove checkbox to disable.');
    } elseif ($i_have_consented && !$other_has_consented) {
      $form['consent']['#description'] = $this->t('You have accepted. Wait for other user to accept.');
    }

    return $form;
  }

  /**
   * AJAX Callback: Speichert die Zustimmung und baut beide Formulare neu.
   */
  public function ajaxUpdateFormsCallback(array &$form, FormStateInterface $form_state)
  {
    /** @var \Drupal\private_chat\Entity\Chat $chat */
    $chat = $form_state->get('chat');

    // 1. Wert in der Datenbank speichern
    $consent_value = (bool) $form_state->getValue('consent');
    $my_consent_field = ($this->currentUser()->id() == $chat->get('user1')->target_id) ? 'user1_consent' : 'user2_consent';
    $chat->set($my_consent_field, $consent_value);
    $chat->save();

    // 2. AJAX-Antwort erstellen
    $response = new AjaxResponse();

    // 3. Haupt-Nachrichtenformular neu bauen und zum Ersetzen hinzufügen
    // Wir holen uns die block_info erneut, falls sie sich geändert hat.
    $currentUserEntity = $this->entityTypeManager->getStorage('user')->load($this->currentUser()->id());
    $otherParticipant = $chat->getOtherParticipant($currentUserEntity);
    $blocker = \Drupal::service('user_blocker.block_manager')->getBlocker($currentUserEntity, $otherParticipant);
    $block_info = [
      'is_blocked' => (bool) $blocker,
      // ... (weitere block_info-Daten könnten hier geladen werden)
    ];
    $messageForm = $this->formBuilder->getForm('\Drupal\private_chat\Form\MessageForm', $chat, $block_info);
    $response->addCommand(new ReplaceCommand('#private-chat-form-wrapper', $messageForm));

    // 4. Dieses (Consent) Formular neu bauen und zum Ersetzen hinzufügen
    // (um sicherzustellen, dass sein Zustand auch aktuell ist)
    $this_popover_form_wrapper_id = $form_state->getValue('dropdown_wrapper_id', $form['#ajax']['wrapper'] ?? '');
    $rebuilt_consent_form = $this->formBuilder->rebuildForm($this->getFormId(), $form_state, $form);
    $response->addCommand(new ReplaceCommand('#' . $this_popover_form_wrapper_id, $rebuilt_consent_form));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Nicht benötigt.
  }
}
