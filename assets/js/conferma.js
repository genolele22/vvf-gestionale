/* Modale di conferma condiviso — centrato e in linea con il cruscotto.
   Sostituisce i confirm() nativi del browser.

   Uso:
     chiediConferma({
       titolo: 'Elimina',
       testo:  'Sicuro? <br>Non reversibile.',
       okLabel:'Elimina',
       okStyle:'background:var(--rosso);color:#fff',
       onOk:   () => { ... }
     });

   Per i form:  onsubmit="return confermaSubmit(this, 'Testo?')"
*/
(function () {
  let _cb = null;

  function init() {
    if (document.getElementById('confermaModal')) return;
    const div = document.createElement('div');
    div.id = 'confermaModal';
    div.className = 'modal-overlay';
    div.style.display = 'none';
    div.innerHTML =
      '<div class="modal-box">' +
        '<div class="modal-titolo" id="confermaTitolo">Conferma</div>' +
        '<div class="modal-testo" id="confermaTesto"></div>' +
        '<div class="modal-azioni">' +
          '<button type="button" class="btn btn-grigio btn-sm" id="confermaAnnulla">Annulla</button>' +
          '<button type="button" class="btn btn-sm" id="confermaOk">Conferma</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(div);

    div.addEventListener('click', function (e) { if (e.target === div) chiudiConferma(); });
    document.getElementById('confermaAnnulla').addEventListener('click', chiudiConferma);
    document.getElementById('confermaOk').addEventListener('click', function () {
      const cb = _cb;
      chiudiConferma();
      if (cb) cb();
    });
    document.addEventListener('keydown', function (e) {
      const m = document.getElementById('confermaModal');
      if (e.key === 'Escape' && m && m.style.display === 'flex') chiudiConferma();
    });
  }

  window.chiediConferma = function (opts) {
    init();
    const o = (typeof opts === 'string') ? { testo: opts } : (opts || {});
    document.getElementById('confermaTitolo').textContent = o.titolo || 'Conferma';
    document.getElementById('confermaTesto').innerHTML = o.testo || '';
    const ok = document.getElementById('confermaOk');
    ok.textContent = o.okLabel || 'Conferma';
    ok.style.cssText = o.okStyle || 'background:var(--rosso);color:#fff';
    _cb = o.onOk || null;
    document.getElementById('confermaModal').style.display = 'flex';
  };

  window.chiudiConferma = function () {
    const m = document.getElementById('confermaModal');
    if (m) m.style.display = 'none';
    _cb = null;
  };

  // Helper per i form: blocca il submit, chiede conferma, poi invia
  window.confermaSubmit = function (form, testo, opts) {
    const o = opts || {};
    chiediConferma({
      titolo:  o.titolo  || 'Conferma',
      testo:   testo,
      okLabel: o.okLabel || 'Conferma',
      okStyle: o.okStyle,
      onOk:    function () { form.submit(); }
    });
    return false;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
