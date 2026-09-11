/*
 * Müller FLOW – Formularhilfen ohne Framework (ADR-002)
 *
 * Keine Abhängigkeiten, kein eval, keine Inline-Handler (CSP verbietet das).
 * Jede Interaktion funktioniert auch ohne dieses Skript grundlegend.
 * Verbindlicher Vertrag: docs/ui-klassen.md, Abschnitt "Verhalten".
 */
(function () {
  'use strict';

  /**
   * data-confirm="Text": Bestätigungsdialog vor Formularabsendung.
   */
  function initConfirm() {
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('submit', function (event) {
        var text = el.getAttribute('data-confirm') || 'Sind Sie sicher?';
        if (!window.confirm(text)) {
          event.preventDefault();
        }
      });
    });

    document.querySelectorAll('button[data-confirm], a[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (event) {
        var text = el.getAttribute('data-confirm') || 'Sind Sie sicher?';
        if (!window.confirm(text)) {
          event.preventDefault();
        }
      });
    });
  }

  /**
   * data-warmmiete: berechnet die Warmmiete zur Anzeige nach den Regeln aus
   * docs/datenvertrag.md Abschnitt 3. Der Serverwert bleibt maßgeblich, diese
   * Anzeige ist nur eine Hilfe während der Eingabe.
   */
  function parseCentInput(value) {
    if (!value) {
      return 0;
    }
    var normalised = String(value).replace(/\./g, '').replace(',', '.');
    var parsed = parseFloat(normalised);
    if (isNaN(parsed)) {
      return 0;
    }
    return Math.round(parsed * 100);
  }

  function formatCentAsEuro(cent) {
    var euro = cent / 100;
    return euro.toLocaleString('de-DE', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }) + ' EUR';
  }

  function initWarmmiete() {
    document.querySelectorAll('[data-warmmiete]').forEach(function (container) {
      var output = container.querySelector('[data-warmmiete-output]');
      if (!output) {
        return;
      }

      var kaltmiete = container.querySelector('[name="kaltmiete"]');
      var nebenkosten = container.querySelector('[name="nebenkosten"]');
      var heizkosten = container.querySelector('[name="heizkosten"]');
      var enthalten = container.querySelector('[name="heizkosten_in_nebenkosten_enthalten"]');
      var versorgungFelder = container.querySelectorAll('[name="heizkosten_versorgung"]');

      function aktuelleVersorgung() {
        if (versorgungFelder.length === 0) {
          return null;
        }
        if (versorgungFelder.length === 1 && versorgungFelder[0].tagName === 'SELECT') {
          return versorgungFelder[0].value || null;
        }
        var gefunden = null;
        versorgungFelder.forEach(function (feld) {
          if (feld.checked) {
            gefunden = feld.value;
          }
        });
        return gefunden;
      }

      function berechnen() {
        var k = parseCentInput(kaltmiete ? kaltmiete.value : '');
        var n = parseCentInput(nebenkosten ? nebenkosten.value : '');
        var h = parseCentInput(heizkosten ? heizkosten.value : '');
        var eIst = !!(enthalten && enthalten.checked);
        var versorgung = aktuelleVersorgung();

        var hinweis = '';
        var warmmiete;

        if (versorgung === 'dezentral') {
          warmmiete = k + n;
          hinweis = 'Heizkosten werden direkt mit dem Versorger abgerechnet und sind nicht enthalten.';
        } else if (eIst) {
          warmmiete = k + n;
        } else if (h > 0) {
          warmmiete = k + n + h;
        } else {
          warmmiete = k + n;
          hinweis = 'Heizkosten nicht angegeben.';
        }

        output.textContent = formatCentAsEuro(warmmiete);

        var hinweisElement = container.querySelector('[data-warmmiete-hinweis]');
        if (hinweisElement) {
          hinweisElement.textContent = hinweis;
          hinweisElement.hidden = hinweis === '';
        }
      }

      [kaltmiete, nebenkosten, heizkosten, enthalten].forEach(function (feld) {
        if (feld) {
          feld.addEventListener('input', berechnen);
          feld.addEventListener('change', berechnen);
        }
      });

      versorgungFelder.forEach(function (feld) {
        feld.addEventListener('change', berechnen);
      });

      berechnen();
    });
  }

  /**
   * data-sortable: Bildraster mit Sortierung über Pfeiltasten/Schaltflächen.
   * Aktualisiert versteckte Sortierungsfelder, keine Drag-Bibliothek.
   */
  function initSortable() {
    document.querySelectorAll('[data-sortable]').forEach(function (grid) {
      function refreshOrder() {
        var items = Array.prototype.slice.call(grid.querySelectorAll('.thumb'));
        items.forEach(function (item, index) {
          var hidden = item.querySelector('input[type="hidden"][data-order-input]');
          if (hidden) {
            hidden.value = String(index);
          }
          var up = item.querySelector('[data-move-up]');
          var down = item.querySelector('[data-move-down]');
          if (up) {
            up.disabled = index === 0;
          }
          if (down) {
            down.disabled = index === items.length - 1;
          }
        });
      }

      grid.addEventListener('click', function (event) {
        var button = event.target.closest('[data-move-up], [data-move-down]');
        if (!button || !grid.contains(button)) {
          return;
        }
        event.preventDefault();

        var thumb = button.closest('.thumb');
        if (!thumb) {
          return;
        }

        if (button.hasAttribute('data-move-up')) {
          var previous = thumb.previousElementSibling;
          if (previous) {
            grid.insertBefore(thumb, previous);
          }
        } else {
          var next = thumb.nextElementSibling;
          if (next) {
            grid.insertBefore(next, thumb);
          }
        }

        refreshOrder();
      });

      refreshOrder();
    });
  }

  /**
   * data-autosubmit: Auswahlfeld, das das umgebende Formular absendet.
   */
  function initAutosubmit() {
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
      el.addEventListener('change', function () {
        var form = el.closest('form');
        if (form) {
          form.submit();
        }
      });
    });
  }

  /**
   * data-toggle="#id": Ein- und Ausblenden eines Bereichs.
   */
  function initToggle() {
    document.querySelectorAll('[data-toggle]').forEach(function (el) {
      el.addEventListener('click', function (event) {
        var selector = el.getAttribute('data-toggle');
        if (!selector) {
          return;
        }
        var target = document.querySelector(selector);
        if (!target) {
          return;
        }
        event.preventDefault();
        target.hidden = !target.hidden;
      });
    });
  }

  function init() {
    initConfirm();
    initWarmmiete();
    initSortable();
    initAutosubmit();
    initToggle();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
