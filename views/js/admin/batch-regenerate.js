(function () {
  'use strict';

  function initBatchRegenerator() {
    var root = document.getElementById('regeneratethumbnails-batch-tool');
    if (!root) {
      return;
    }

    if (root.getAttribute('data-rt-bound') === '1') {
      return;
    }
    root.setAttribute('data-rt-bound', '1');

    var ajaxUrl = root.getAttribute('data-ajax-url');
    var startBtn = document.getElementById('rt-start');
    var scopeInput = document.getElementById('rt-scope');
    var typeInput = document.getElementById('rt-type');
    var eraseInput = document.getElementById('rt-erase');
    var batchSizeInput = document.getElementById('rt-batch-size');
    var progressBar = document.getElementById('rt-progress-bar');
    var statusEl = document.getElementById('rt-status');
    var logEl = document.getElementById('rt-log');

    if (!startBtn || !scopeInput || !typeInput || !eraseInput || !batchSizeInput || !progressBar || !statusEl || !logEl) {
      return;
    }

    function setBusy(isBusy) {
      startBtn.disabled = isBusy;
      scopeInput.disabled = isBusy;
      typeInput.disabled = isBusy;
      eraseInput.disabled = isBusy;
      batchSizeInput.disabled = isBusy;
    }

    function setProgress(progress) {
      var pct = Math.max(0, Math.min(100, Number(progress || 0)));
      progressBar.style.width = pct.toFixed(2) + '%';
      progressBar.textContent = pct.toFixed(2) + '%';
    }

    function setStatus(text) {
      statusEl.textContent = text;
    }

    function appendLog(text) {
      var line = '[' + new Date().toLocaleTimeString() + '] ' + text;
      logEl.textContent += (logEl.textContent ? '\n' : '') + line;
      logEl.scrollTop = logEl.scrollHeight;
    }

    function post(action, payload) {
      var params = new URLSearchParams();
      params.append('ajax', '1');
      params.append('action', action);

      Object.keys(payload || {}).forEach(function (key) {
        params.append(key, payload[key]);
      });

      return fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: params.toString()
      }).then(function (response) {
        return response.json();
      });
    }

    function processJob(jobId) {
      post('processBatchRegeneration', { job_id: jobId })
        .then(function (response) {
          if (!response.success) {
            throw new Error(response.error || 'Unknown AJAX error');
          }

          var data = response.data || {};
          setProgress(data.progress);

          var status = 'Processed ' + data.processed + ' / ' + data.total;
          if (data.current_scope) {
            status += ' (scope: ' + data.current_scope + ')';
          }
          setStatus(status);

          appendLog(
            'Step processed ' + data.processed_step +
            ' items, progress ' + Number(data.progress || 0).toFixed(2) + '%'
          );

          if (data.complete) {
            setBusy(false);
            setStatus('Batch regeneration completed. Processed ' + data.processed + ' items.');
            appendLog('Job complete.');
            return;
          }

          window.setTimeout(function () {
            processJob(jobId);
          }, 100);
        })
        .catch(function (error) {
          setBusy(false);
          setStatus('Error: ' + error.message);
          appendLog('Error: ' + error.message);
        });
    }

    startBtn.addEventListener('click', function () {
      setBusy(true);
      setProgress(0);
      setStatus('Starting batch regeneration...');
      logEl.textContent = '';

      post('initBatchRegeneration', {
        image_scope: scopeInput.value,
        image_type: typeInput.value,
        rease_previous: eraseInput.checked ? '1' : '0',
        batch_size: batchSizeInput.value || '50'
      })
        .then(function (response) {
          if (!response.success) {
            throw new Error(response.error || 'Unknown AJAX error');
          }

          var data = response.data || {};
          setProgress(data.progress);
          setStatus('Batch job started.');
          appendLog('Job ' + data.job_id + ' initialized.');

          if (data.complete) {
            setBusy(false);
            setStatus('Nothing to process for selected filters.');
            appendLog('No pending images found.');
            return;
          }

          processJob(data.job_id);
        })
        .catch(function (error) {
          setBusy(false);
          setStatus('Error: ' + error.message);
          appendLog('Error: ' + error.message);
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBatchRegenerator);
  } else {
    initBatchRegenerator();
  }
})();
