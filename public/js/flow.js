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
      // Neue Kostenstruktur (Masterprompt-Abgleich B.1 Schritt 4): drei
      // Kacheln enthalten/zusaetzlich/eigener_vertrag ersetzen die
      // Versorgungsauswahl fachlich, siehe App\Enums\HeizkostenStruktur.
      var strukturFelder = container.querySelectorAll('[name="heizkosten_struktur"]');
      var stellplatzMiete = container.querySelector('[name="stellplatz_miete"]');
      var stellplatzModusFelder = container.querySelectorAll('[name="stellplatz_modus"]');

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

      function aktuelleStruktur() {
        var gefunden = null;
        strukturFelder.forEach(function (feld) {
          if (feld.checked) {
            gefunden = feld.value;
          }
        });
        return gefunden;
      }

      function aktuellerStellplatzModus() {
        var gefunden = 'keiner';
        stellplatzModusFelder.forEach(function (feld) {
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
        var struktur = aktuelleStruktur();
        var versorgung = struktur ? (struktur === 'eigener_vertrag' ? 'dezentral' : 'zentral') : aktuelleVersorgung();
        if (struktur) {
          eIst = struktur === 'enthalten';
        }

        var hinweis = '';
        var warmmiete;

        if (versorgung === 'dezentral') {
          warmmiete = k + n;
          hinweis = 'Miete einschließlich Betriebskosten, zuzüglich separat zu zahlender Heizkosten.';
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

        var stellplatzZeile = container.querySelector('[data-warmmiete-stellplatz]');
        if (stellplatzZeile) {
          var modus = aktuellerStellplatzModus();
          var stellplatzCent = parseCentInput(stellplatzMiete ? stellplatzMiete.value : '');
          var zeigen = (modus === 'optional' || modus === 'pflicht_zusaetzlich') && stellplatzCent > 0;
          stellplatzZeile.hidden = !zeigen;
          if (zeigen) {
            var stellplatzOutput = stellplatzZeile.querySelector('[data-warmmiete-stellplatz-output]');
            if (stellplatzOutput) {
              stellplatzOutput.textContent = formatCentAsEuro(stellplatzCent);
            }
          }
        }

        var gesamtZeile = container.querySelector('[data-warmmiete-gesamt]');
        if (gesamtZeile) {
          gesamtZeile.textContent = formatCentAsEuro(warmmiete);
        }
      }

      [kaltmiete, nebenkosten, heizkosten, enthalten, stellplatzMiete].forEach(function (feld) {
        if (feld) {
          feld.addEventListener('input', berechnen);
          feld.addEventListener('change', berechnen);
        }
      });

      versorgungFelder.forEach(function (feld) {
        feld.addEventListener('change', berechnen);
      });
      strukturFelder.forEach(function (feld) {
        feld.addEventListener('change', berechnen);
      });
      stellplatzModusFelder.forEach(function (feld) {
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
   * data-reveals="#ziel": auf einem Radio- oder Checkbox-Eingabefeld
   * innerhalb einer .kachel. Ist das Feld markiert, wird das Ziel
   * eingeblendet; alle anderen Ziele derselben Feldgruppe (gleicher
   * "name") werden ausgeblendet. Ohne dieses Attribut bleibt ein Ziel von
   * seiner Gruppe unberührt. Trägt ein Element .kachel die Klasse
   * is-selected zusätzlich zum :has()-Fallback in flow.css (ADR-002,
   * Müller FLOW Welle 2).
   */
  function initKachelUndReveal() {
    var gruppen = {};

    document.querySelectorAll('.kachel input[type="radio"], .kachel input[type="checkbox"]').forEach(function (feld) {
      var name = feld.getAttribute('name');
      if (name) {
        gruppen[name] = gruppen[name] || [];
        gruppen[name].push(feld);
      }
    });

    function aktualisiere(feld) {
      var kachel = feld.closest('.kachel');
      if (kachel) {
        if (feld.type === 'checkbox') {
          kachel.classList.toggle('is-selected', feld.checked);
        } else {
          var gruppe = gruppen[feld.getAttribute('name')] || [feld];
          gruppe.forEach(function (andere) {
            var andereKachel = andere.closest('.kachel');
            if (andereKachel) {
              andereKachel.classList.toggle('is-selected', andere.checked);
            }
          });
        }
      }

      var name = feld.getAttribute('name');
      var gruppe = gruppen[name] || [feld];

      gruppe.forEach(function (eintrag) {
        var ziel = eintrag.getAttribute('data-reveals');
        if (!ziel) {
          return;
        }
        var element = document.querySelector(ziel);
        if (element) {
          element.hidden = !eintrag.checked;
        }
      });
    }

    Object.keys(gruppen).forEach(function (name) {
      gruppen[name].forEach(function (feld) {
        feld.addEventListener('change', function () {
          aktualisiere(feld);
        });
        if (feld.checked) {
          aktualisiere(feld);
        }
      });
    });
  }

  /**
   * data-tabs: Reiterleiste für Kategorien (z. B. Medien in Schritt 6).
   * Jeder Reiter ist ein button[data-tab-target="#id"], jedes Panel ein
   * Element mit passender ID. Ohne JavaScript bleiben alle Panels sichtbar
   * (kein hidden im Markup), erst hier wird auf das erste Panel reduziert.
   */
  function initTabs() {
    document.querySelectorAll('[data-tabs]').forEach(function (leiste) {
      var buttons = Array.prototype.slice.call(leiste.querySelectorAll('[data-tab-target]'));
      if (buttons.length === 0) {
        return;
      }

      var panels = buttons.map(function (button) {
        return document.querySelector(button.getAttribute('data-tab-target'));
      });

      function aktiviere(index) {
        buttons.forEach(function (button, i) {
          button.classList.toggle('is-active', i === index);
          button.setAttribute('aria-selected', i === index ? 'true' : 'false');
          if (panels[i]) {
            panels[i].hidden = i !== index;
          }
        });
      }

      buttons.forEach(function (button, index) {
        button.addEventListener('click', function (event) {
          event.preventDefault();
          aktiviere(index);
        });
      });

      var startindex = buttons.findIndex(function (button) {
        return button.classList.contains('is-active');
      });
      aktiviere(startindex >= 0 ? startindex : 0);
    });
  }

  /**
   * data-autosave="URL": Formular, das 1,5 Sekunden nach der letzten
   * Eingabe und beim Verlassen eines Feldes (blur) automatisch per PATCH
   * gespeichert wird (Masterprompt-Abgleich B.1, Autosave-Vertrag). Die
   * Anzeige erfolgt im Element mit data-autosave-status im selben
   * Formular: "Wird gespeichert" während der Anfrage, danach "Gespeichert
   * um HH:MM" oder "Nicht gespeichert" mit der ersten Fehlermeldung.
   * Autosave ändert nie den Veröffentlichungsstatus und löst nie eine
   * Übertragung aus (das entscheidet ausschließlich der Server).
   */
  function initAutosave() {
    var VERZOEGERUNG_MS = 1500;

    document.querySelectorAll('form[data-autosave]').forEach(function (form) {
      var url = form.getAttribute('data-autosave');
      if (!url) {
        return;
      }

      var status = form.querySelector('[data-autosave-status]');
      var timer = null;
      var tokenFeld = form.querySelector('input[name="_token"]');
      var csrfToken = tokenFeld ? tokenFeld.value : '';

      function anzeige(text, art) {
        if (!status) {
          return;
        }
        status.textContent = text;
        status.classList.remove('is-saving', 'is-saved', 'is-error');
        if (art) {
          status.classList.add(art);
        }
      }

      function formDatenAlsObjekt() {
        // Bei "hidden 0" plus Checkbox derselben Feldbezeichnung (Laravel-
        // Muster für boolesche Felder) liefert FormData beide Einträge in
        // Dokumentreihenfolge; der letzte gewinnt bewusst (Checkbox
        // angehakt überschreibt den verborgenen Standardwert 0).
        var daten = {};
        new FormData(form).forEach(function (wert, schluessel) {
          if (schluessel === '_token' || schluessel === 'aktion') {
            return;
          }
          daten[schluessel] = wert;
        });
        return daten;
      }

      function speichern() {
        anzeige('Wird gespeichert', 'is-saving');

        fetch(url, {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify(formDatenAlsObjekt()),
          credentials: 'same-origin',
        })
          .then(function (antwort) {
            if (antwort.status === 422) {
              return antwort.json().then(function (koerper) {
                throw new Error(ersteFehlermeldung(koerper) || 'Die Eingabe konnte nicht gespeichert werden.');
              });
            }
            if (!antwort.ok) {
              throw new Error('Die Verbindung zum Server ist fehlgeschlagen.');
            }
            return antwort.json();
          })
          .then(function (koerper) {
            var zeitpunkt = (koerper && koerper.gespeichert_at) ? koerper.gespeichert_at.slice(0, 5) : nowAsHHMM();
            anzeige('Gespeichert um ' + zeitpunkt, 'is-saved');
          })
          .catch(function (fehler) {
            anzeige('Nicht gespeichert: ' + fehler.message, 'is-error');
          });
      }

      function ersteFehlermeldung(koerper) {
        if (!koerper || !koerper.errors) {
          return null;
        }
        var schluessel = Object.keys(koerper.errors)[0];
        if (!schluessel) {
          return null;
        }
        var liste = koerper.errors[schluessel];
        return Array.isArray(liste) ? liste[0] : String(liste);
      }

      function nowAsHHMM() {
        var jetzt = new Date();
        function zwei(n) { return (n < 10 ? '0' : '') + n; }
        return zwei(jetzt.getHours()) + ':' + zwei(jetzt.getMinutes());
      }

      function geplantSpeichern() {
        if (timer) {
          window.clearTimeout(timer);
        }
        timer = window.setTimeout(speichern, VERZOEGERUNG_MS);
      }

      form.addEventListener('input', geplantSpeichern);
      form.addEventListener('change', geplantSpeichern);

      form.querySelectorAll('input, select, textarea').forEach(function (feld) {
        feld.addEventListener('blur', function () {
          if (timer) {
            window.clearTimeout(timer);
            timer = null;
          }
          speichern();
        });
      });
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
    initKachelUndReveal();
    initTabs();
    initAutosave();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
