<?php

declare(strict_types=1);

namespace Drupal\private_chat\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Private Chat settings for this site.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'private_chat_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['private_chat.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['chat_settings']['allow_uploads'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow image uploads for Chats.'),
      '#description' => $this->t('Images are saved in private folder. Only participants have access to uploaded images and users with permission "administer users".'),
      '#default_value' => $this->config('private_chat.settings')->get('allow_uploads'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // @todo Validate the form here.
    // Example:
    // @code
    //   if ($form_state->getValue('example') === 'wrong') {
    //     $form_state->setErrorByName(
    //       'message',
    //       $this->t('The value is not correct.'),
    //     );
    //   }
    // @endcode
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('private_chat.settings')
      ->set('allow_uploads', $form_state->getValue('allow_uploads'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
