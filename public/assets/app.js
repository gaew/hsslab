(() => {
  'use strict';
  const status = document.getElementById('network-status');
  const installButton = document.getElementById('install-app');
  let installPrompt = null;

  function showStatus(message, offline = false) {
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('offline', offline);
    status.classList.add('show');
    window.setTimeout(() => status.classList.remove('show'), 3200);
  }

  window.addEventListener('offline', () => showStatus('ออฟไลน์ — กำลังใช้ข้อมูลที่บันทึกไว้', true));
  window.addEventListener('online', () => showStatus('กลับมาออนไลน์แล้ว'));
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    installPrompt = event;
    if (installButton) installButton.hidden = false;
  });
  installButton?.addEventListener('click', async () => {
    if (!installPrompt) return;
    await installPrompt.prompt();
    installPrompt = null;
    installButton.hidden = true;
  });
  window.addEventListener('appinstalled', () => {
    if (installButton) installButton.hidden = true;
    showStatus('ติดตั้ง QDocs เรียบร้อยแล้ว');
  });

  document.querySelectorAll('table').forEach(table => {
    const labels = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
    table.querySelectorAll('tbody tr').forEach(row => {
      [...row.children].forEach((cell, index) => cell.dataset.label = labels[index] || '');
    });
  });

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('service-worker.js'));
  }
})();

