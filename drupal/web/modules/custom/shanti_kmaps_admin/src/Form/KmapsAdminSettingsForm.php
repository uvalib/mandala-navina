<?php

namespace Drupal\shanti_kmaps_admin\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for KMaps server settings.
 */
class KmapsAdminSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['shanti_kmaps_admin.settings'];
  }

  public function getFormId(): string {
    return 'shanti_kmaps_admin_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('shanti_kmaps_admin.settings');

    $form['kmaps_servers'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('KMaps API Servers'),
    ];
    foreach (['subjects', 'places', 'terms'] as $domain) {
      $form['kmaps_servers']['server_' . $domain] = [
        '#type' => 'textfield',
        '#title' => $this->t('KMaps @d Server', ['@d' => ucfirst($domain)]),
        '#default_value' => $config->get('server_' . $domain),
        '#required' => TRUE,
        '#maxlength' => 255,
      ];
    }

    $form['solr_servers'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Solr Indexes'),
    ];
    $form['solr_servers']['server_solr_terms'] = [
      '#type' => 'textfield',
      '#title' => $this->t('KMaps Terms Solr Index (kmterms)'),
      '#description' => $this->t('Used for autocomplete search in field widgets. Requires VPN in local development.'),
      '#default_value' => $config->get('server_solr_terms'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['solr_servers']['server_solr'] = [
      '#type' => 'textfield',
      '#title' => $this->t('KMaps Assets Solr Index (kmassets)'),
      '#description' => $this->t('Read-only. Used by Search API Solr for content search.'),
      '#default_value' => $config->get('server_solr'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['root_ids'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Root KMap IDs (optional — leave empty to allow all)'),
    ];
    foreach (['subjects', 'places', 'terms'] as $domain) {
      $form['root_ids']['root_' . $domain . '_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Root @d IDs', ['@d' => ucfirst($domain)]),
        '#description' => $this->t('Space-separated list of root KMap IDs. Leave empty for no restriction.'),
        '#default_value' => $config->get('root_' . $domain . '_id'),
        '#required' => FALSE,
      ];
    }

    $form['explorer_urls'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('KMaps Explorer URLs'),
      '#description' => $this->t('Use <code>__KMAPID__</code> as a placeholder for the numeric KMap ID.'),
    ];
    foreach (['subjects', 'places', 'terms'] as $domain) {
      $form['explorer_urls']['explorer_' . $domain] = [
        '#type' => 'textfield',
        '#title' => $this->t('@d Explorer URL', ['@d' => ucfirst($domain)]),
        '#default_value' => $config->get('explorer_' . $domain),
        '#required' => FALSE,
        '#maxlength' => 255,
      ];
    }

    $form['service_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Service Name'),
      '#description' => $this->t('Identifier for this Mandala site in the kmassets Solr index (e.g. audio-video, texts, images).'),
      '#default_value' => $config->get('service_name'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $keys = [
      'server_subjects', 'server_places', 'server_terms',
      'server_solr_terms', 'server_solr',
      'root_subjects_id', 'root_places_id', 'root_terms_id',
      'explorer_subjects', 'explorer_places', 'explorer_terms',
      'service_name',
    ];
    $config = $this->config('shanti_kmaps_admin.settings');
    foreach ($keys as $key) {
      $config->set($key, $form_state->getValue($key));
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
