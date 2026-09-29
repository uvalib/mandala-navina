/**
 * @file
 * AV12: browser-direct chunked upload to Kaltura.
 *
 * Custom implementation rather than vendoring Kaltura's reference
 * `chunked-file-upload-jquery` widget (blueimp/jQuery-File-Upload lineage,
 * the same family D7's kaltura_chunked_uploader used) -- this codebase has
 * zero jQuery-File-Upload code today, and the actual flow is 3 REST calls,
 * not enough surface to justify the dependency weight of that whole
 * family. See Sprint 3 AV12's row and Spike 7 for the confirmed D7
 * reference behavior this reproduces.
 *
 * Flow, all calls going straight from the browser to the Kaltura REST API
 * (never through Drupal/PHP -- the KS from AV11's endpoint is the only
 * thing Drupal hands the browser):
 *   1. GET  /api/kaltura/upload-session         (Drupal, AV11) -> {ks, partner_id, expires}
 *   2. POST {server}/api_v3/service/uploadtoken/action/add           -> uploadTokenId
 *   3. POST {server}/api_v3/service/uploadtoken/action/upload (N times, chunked) -> final chunk closes the token
 *   4. POST {server}/api_v3/service/media/action/add                 -> entryId (empty KalturaMediaEntry)
 *   5. POST {server}/api_v3/service/media/action/addContent          -> attaches the upload token's content to the entry
 *
 * Step 3's exact request shape (nested object params serialized as
 * `entry[prop]`/`resource[prop]`) mirrors how the official PHP SDK
 * (vendor/kaltura/api-client-library) serializes KalturaObjectBase
 * subclasses -- see Base::addParam() there -- since the REST API underneath
 * is the same for both. This has NOT been exercised against a real Kaltura
 * partner account yet (tracked as an open verification item, not assumed
 * done); if request shapes drift from what a live sandbox expects, this is
 * the file to correct.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  // 5MB chunks: large enough to keep request overhead low, small enough to
  // keep memory bounded and give a real per-chunk retry point on a large
  // AV master file.
  var CHUNK_SIZE = 5 * 1024 * 1024;

  Drupal.behaviors.mandalaKalturaUpload = {
    attach: function (context) {
      once('mandala-kaltura-upload', '.mandala-kaltura-upload', context).forEach(function (wrapper) {
        initUploadWidget(wrapper);
      });
    }
  };

  function initUploadWidget(wrapper) {
    var fileInput = wrapper.querySelector('input[type="file"]');
    var statusEl = wrapper.querySelector('.mandala-kaltura-upload-status');
    var entryIdField = wrapper.querySelector('[data-kaltura-field="entry_id"]');
    var partnerIdField = wrapper.querySelector('[data-kaltura-field="partner_id"]');
    var uiconfIdField = wrapper.querySelector('[data-kaltura-field="uiconf_id"]');
    var domainField = wrapper.querySelector('[data-kaltura-field="domain"]');

    var mediaType = wrapper.dataset.kalturaMediaType === 'audio' ? 5 : 1; // MediaType::AUDIO / VIDEO
    var playerUiconfId = wrapper.dataset.kalturaPlayerUiconfId || '';
    var uploadSessionUrl = drupalSettings.mandalaKaltura && drupalSettings.mandalaKaltura.uploadSessionUrl;
    var form = wrapper.closest('form');

    if (!fileInput || !uploadSessionUrl) {
      return;
    }

    fileInput.addEventListener('change', function () {
      var file = fileInput.files[0];
      if (!file) {
        return;
      }
      setStatus(statusEl, Drupal.t('Uploading @name…', {'@name': file.name}));
      fileInput.disabled = true;
      beginFormUpload(form);

      runUpload(file, uploadSessionUrl, mediaType, function (progressMessage) {
        setStatus(statusEl, progressMessage);
      })
        .then(function (result) {
          entryIdField.value = result.entryId;
          partnerIdField.value = result.partnerId;
          uiconfIdField.value = playerUiconfId;
          domainField.value = result.domain;
          setStatus(statusEl, Drupal.t('Upload complete.'));
        })
        .catch(function (error) {
          setStatus(statusEl, Drupal.t('Upload failed: @error', {'@error': error.message || error}));
        })
        .finally(function () {
          fileInput.disabled = false;
          endFormUpload(form);
        });
    });
  }

  function setStatus(statusEl, message) {
    if (statusEl) {
      statusEl.textContent = message;
    }
  }

  /**
   * Blocks saving the node while an upload is in flight.
   *
   * Without this, clicking Save mid-upload persists the node with an
   * empty `kaltura` field while the upload keeps running in the
   * background -- the resulting entry is never linked to any node once
   * it completes (real gap found by review). Disabling the file input
   * alone (above) isn't enough: it stops a second upload from starting,
   * it does nothing to stop the form's own Save button.
   *
   * Tracks an active-upload count on the form element itself (not a
   * module-level variable) so multiple upload widgets on the same form
   * -- unlikely today at field cardinality 1, but not assumed away --
   * are all accounted for before re-enabling Save.
   */
  function beginFormUpload(form) {
    if (!form) {
      return;
    }
    form.mandalaKalturaActiveUploads = (form.mandalaKalturaActiveUploads || 0) + 1;
    setSubmitButtonsDisabled(form, true);

    once('mandala-kaltura-upload-guard', form).forEach(function (guardedForm) {
      guardedForm.addEventListener('submit', function (event) {
        if (guardedForm.mandalaKalturaActiveUploads > 0) {
          event.preventDefault();
          event.stopImmediatePropagation();
        }
      });
    });
  }

  function endFormUpload(form) {
    if (!form) {
      return;
    }
    form.mandalaKalturaActiveUploads = Math.max(0, (form.mandalaKalturaActiveUploads || 1) - 1);
    if (form.mandalaKalturaActiveUploads === 0) {
      setSubmitButtonsDisabled(form, false);
    }
  }

  function setSubmitButtonsDisabled(form, disabled) {
    form.querySelectorAll('[type="submit"]').forEach(function (button) {
      button.disabled = disabled;
    });
  }

  async function runUpload(file, uploadSessionUrl, mediaType, onProgress) {
    var sessionResponse = await fetch(uploadSessionUrl, {credentials: 'same-origin'});
    if (!sessionResponse.ok) {
      throw new Error('could not obtain an upload session');
    }
    var session = await sessionResponse.json();
    var serviceUrl = normalizeServiceUrl(session.server_url);
    var domain = new URL(serviceUrl).hostname;

    onProgress(Drupal.t('Preparing upload…'));
    var uploadTokenId = await createUploadToken(serviceUrl, session.ks);

    var chunkCount = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
    for (var i = 0; i < chunkCount; i++) {
      var start = i * CHUNK_SIZE;
      var chunk = file.slice(start, start + CHUNK_SIZE);
      var isFirst = i === 0;
      var isLast = i === chunkCount - 1;
      onProgress(Drupal.t('Uploading… (@current/@total)', {'@current': i + 1, '@total': chunkCount}));
      await uploadChunk(serviceUrl, session.ks, uploadTokenId, chunk, !isFirst, isLast, isFirst ? -1 : start);
    }

    onProgress(Drupal.t('Finalizing…'));
    var entryId = await createMediaEntry(serviceUrl, session.ks, mediaType, file.name);
    await attachUploadedContent(serviceUrl, session.ks, entryId, uploadTokenId);

    return {entryId: entryId, partnerId: session.partner_id, domain: domain};
  }

  function normalizeServiceUrl(serverUrl) {
    // mandala_kaltura.settings stores this as a protocol-relative URL
    // (e.g. "//www.kaltura.com"), matching D7's stored value -- resolve it
    // against the current page's protocol.
    if (serverUrl.indexOf('//') === 0) {
      return window.location.protocol + serverUrl;
    }
    return serverUrl;
  }

  async function kalturaApiCall(serviceUrl, service, action, formData) {
    formData.append('format', '1'); // JSON response.
    var response = await fetch(serviceUrl + '/api_v3/service/' + service + '/action/' + action, {
      method: 'POST',
      body: formData
    });
    var body = await response.json();
    if (body && body.code) {
      // Kaltura API errors are JSON objects with `code`/`message`, not an
      // HTTP error status -- the endpoint itself returns 200.
      throw new Error(body.message || body.code);
    }
    return body;
  }

  async function createUploadToken(serviceUrl, ks) {
    var formData = new FormData();
    formData.append('ks', ks);
    var token = await kalturaApiCall(serviceUrl, 'uploadtoken', 'add', formData);
    return token.id;
  }

  async function uploadChunk(serviceUrl, ks, uploadTokenId, chunk, resume, finalChunk, resumeAt) {
    var formData = new FormData();
    formData.append('ks', ks);
    formData.append('uploadTokenId', uploadTokenId);
    formData.append('resume', resume ? '1' : '0');
    formData.append('finalChunk', finalChunk ? '1' : '0');
    formData.append('resumeAt', String(resumeAt));
    formData.append('fileData', chunk, 'chunk');
    return kalturaApiCall(serviceUrl, 'uploadtoken', 'upload', formData);
  }

  async function createMediaEntry(serviceUrl, ks, mediaType, fileName) {
    var formData = new FormData();
    formData.append('ks', ks);
    formData.append('entry[objectType]', 'KalturaMediaEntry');
    formData.append('entry[name]', fileName);
    formData.append('entry[mediaType]', String(mediaType));
    var entry = await kalturaApiCall(serviceUrl, 'media', 'add', formData);
    return entry.id;
  }

  async function attachUploadedContent(serviceUrl, ks, entryId, uploadTokenId) {
    var formData = new FormData();
    formData.append('ks', ks);
    formData.append('entryId', entryId);
    formData.append('resource[objectType]', 'KalturaUploadedFileTokenResource');
    formData.append('resource[token]', uploadTokenId);
    return kalturaApiCall(serviceUrl, 'media', 'addContent', formData);
  }

})(Drupal, drupalSettings, once);
