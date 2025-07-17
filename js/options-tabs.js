document.addEventListener('DOMContentLoaded', function() {
  const tabs = document.querySelectorAll('#blc-tabs .ui-tabs-nav a');
  const panels = document.querySelectorAll('#blc-tabs .blc-section');

  tabs.forEach(tab => {
    tab.addEventListener('click', function(e) {
      e.preventDefault();
      // Tabs aktiv setzen
      tabs.forEach(t => t.parentElement.classList.remove('ui-tabs-active'));
      this.parentElement.classList.add('ui-tabs-active');
      // Panels ein-/ausblenden
      panels.forEach(panel => panel.style.display = 'none');
      const activePanel = document.querySelector(this.getAttribute('href'));
      if (activePanel) activePanel.style.display = 'block';
    });
  });

  // Standard: erstes Panel anzeigen
  if (panels.length > 0) {
    panels.forEach(panel => panel.style.display = 'none');
    panels[0].style.display = 'block';
  }
});
