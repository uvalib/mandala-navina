<?php

namespace Drupal\shanti_iiif\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for the Shanti IIIF server.
 */
class SettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['shanti_iiif.settings'];
  }

  public function getFormId(): string {
    return 'shanti_iiif_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('shanti_iiif.settings');

    $form['view_url'] = [
      '#type' => 'url',
      '#title' => $this->t('IIIF view URL'),
      '#description' => $this->t('Base URL of the IIIF server, including protocol, no trailing slash. Example: <code>https://iiif.lib.virginia.edu</code>.'),
      '#default_value' => $config->get('view_url'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['view_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IIIF view path'),
      '#description' => $this->t('URL path between the server and the IIIF identifier, with leading and trailing slashes. Example: <code>/mandala/</code>. The server also exposes the canonical IIIF 2.x path <code>/iiif/2/</code>; we use <code>/mandala/</code> to match D7 behavior.'),
      '#default_value' => $config->get('view_path'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $url = rtrim((string) $form_state->getValue('view_url'), '/');
    $form_state->setValue('view_url', $url);

    $path = trim((string) $form_state->getValue('view_path'), '/');
    $form_state->setValue('view_path', '/' . $path . '/');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('shanti_iiif.settings')
      ->set('view_url', $form_state->getValue('view_url'))
      ->set('view_path', $form_state->getValue('view_path'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
