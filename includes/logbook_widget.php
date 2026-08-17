<?php
/**
 * "Qui non va" — tasto flottante sempre nello stesso punto su ogni pagina del
 * gestionale, per scrivere una nota nel logbook senza smettere di fare quello
 * che si stava facendo. Porta identico dalla versione in The Crew
 * (components/logbook-popup.tsx): pagina corrente auto-compilata, link alla
 * lista completa. Qui in PHP puro, incluso a fondo pagina.
 *
 * Visibile solo a chi vede il Logbook (isLogbookUser(), vedi includes/auth.php)
 * — richiede che auth.php sia già stato incluso dalla pagina chiamante.
 */
if (!function_exists('isLogbookUser') || !isLogbookUser()) return;

$lbPagina = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES);
?>
<style>
  #lbWidget { position: fixed; bottom: 18px; right: 18px; z-index: 9999; font-family: inherit; }
  #lbWidget * { box-sizing: border-box; }
  @media print { #lbWidget { display: none !important; } }
  #lbBtn { width: 48px; height: 48px; border-radius: 50%; border: 2px solid #1a1a1a;
           background: #f5c518; font-size: 1.25rem; cursor: pointer;
           box-shadow: 0 4px 14px rgba(0,0,0,.25); display: flex; align-items: center;
           justify-content: center; }
  #lbBtn:hover { filter: brightness(.96); }
  #lbPanel { display: none; width: 300px; margin-bottom: 10px; padding: 14px;
             background: #fff; border: 1px solid #ccc; border-radius: 10px;
             box-shadow: 0 8px 24px rgba(0,0,0,.2); font-size: .85rem; }
  #lbPanel.aperto { display: block; }
  #lbPanel .lb-head { display: flex; align-items: center; justify-content: space-between; }
  #lbPanel .lb-head strong { font-size: .9rem; }
  #lbPanel .lb-chiudi { background: none; border: none; cursor: pointer; font-size: 1rem;
             color: #999; padding: 2px 6px; }
  #lbPanel .lb-path { font-size: .72rem; color: #999; margin: 2px 0 8px;
             white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  #lbPanel textarea { width: 100%; min-height: 4.2rem; padding: 7px 9px; border: 1px solid #ccc;
             border-radius: 8px; font: inherit; font-size: .85rem; resize: vertical; }
  #lbPanel .lb-riga { display: flex; align-items: center; justify-content: space-between;
             margin-top: 8px; gap: 8px; }
  #lbPanel .lb-vedi { font-size: .75rem; color: var(--rosso, #c0392b); text-decoration: none; }
  #lbPanel .lb-vedi:hover { text-decoration: underline; }
  #lbPanel .lb-salva { background: #0a58ca; color: #fff; border: none; border-radius: 8px;
             padding: 6px 14px; font-size: .82rem; font-weight: 600; cursor: pointer; }
  #lbPanel .lb-salva:disabled { opacity: .5; cursor: not-allowed; }
  #lbPanel .lb-esito { margin-top: 6px; font-size: .78rem; display: none; }
  #lbPanel .lb-esito.ok  { color: #1e7e34; display: block; }
  #lbPanel .lb-esito.err { color: #c0392b; display: block; }
</style>
<div id="lbWidget">
  <div id="lbPanel">
    <div class="lb-head">
      <strong>Qui non va</strong>
      <button type="button" class="lb-chiudi" onclick="lbChiudi()" aria-label="Chiudi">✕</button>
    </div>
    <div class="lb-path" title="<?= $lbPagina ?>"><?= $lbPagina ?></div>
    <textarea id="lbTesto" placeholder="Cosa non va in questa pagina?"></textarea>
    <div class="lb-riga">
      <a class="lb-vedi" href="/logbook/index.php">Vedi tutte le note</a>
      <button type="button" class="lb-salva" id="lbSalva" onclick="lbSalva()">Salva nota</button>
    </div>
    <div class="lb-esito" id="lbEsito"></div>
  </div>
  <button type="button" id="lbBtn" onclick="lbToggle()" aria-label="Scrivi una nota sul logbook"
          title="Qui non va — scrivi una nota">📝</button>
</div>
<script>
(function () {
  function chiudiSeFuori(e) {
    var w = document.getElementById('lbWidget');
    if (w && !w.contains(e.target)) lbChiudi();
  }
  window.lbToggle = function () {
    var p = document.getElementById('lbPanel');
    var aperto = p.classList.toggle('aperto');
    if (aperto) {
      document.addEventListener('mousedown', chiudiSeFuori);
      document.getElementById('lbTesto').focus();
    } else {
      document.removeEventListener('mousedown', chiudiSeFuori);
    }
  };
  window.lbChiudi = function () {
    document.getElementById('lbPanel').classList.remove('aperto');
    document.removeEventListener('mousedown', chiudiSeFuori);
  };
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') lbChiudi();
  });
  window.lbSalva = async function () {
    var testo = document.getElementById('lbTesto').value.trim();
    var esito = document.getElementById('lbEsito');
    if (!testo) { esito.className = 'lb-esito err'; esito.textContent = 'Scrivi qualcosa prima.'; return; }
    var btn = document.getElementById('lbSalva');
    btn.disabled = true;
    var fd = new FormData();
    fd.append('testo', testo);
    fd.append('pagina_url', <?= json_encode($lbPagina) ?>);
    try {
      var res = await fetch('/logbook/aggiungi.php', { method: 'POST', body: fd }).then(r => r.json());
      if (res.ok) {
        esito.className = 'lb-esito ok'; esito.textContent = 'Salvata.';
        document.getElementById('lbTesto').value = '';
        setTimeout(lbChiudi, 900);
      } else {
        esito.className = 'lb-esito err'; esito.textContent = res.errore || 'Errore.';
      }
    } catch (e) {
      esito.className = 'lb-esito err'; esito.textContent = 'Errore di rete.';
    }
    btn.disabled = false;
  };
})();
</script>
