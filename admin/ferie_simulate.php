<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediAdmin();
require_once __DIR__ . '/../includes/turni.php';          // getTurnoGiorno()
require_once __DIR__ . '/../includes/ferie_assenze.php';  // feriaSyncAssenza()
$TURNO = turnoAmministrazione();   // fisso sul turno di casa (admin/user niente switch qui)

$pdo = getDB();

// ── AJAX: crea le voci ferie (come richieste BOT, stato pending) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!puoModificareTurno($TURNO)) { echo json_encode(['ok' => false, 'errore' => 'Turno in sola lettura.']); exit; }
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'crea_voci') {
        $vigili = json_decode($_POST['vigili'] ?? '[]', true);
        $voci   = json_decode($_POST['voci']   ?? '[]', true);   // [{data:'Y-m-d', tipo:'D'|'N'}]
        $vigili = is_array($vigili)
            ? array_values(array_filter(array_map('intval', $vigili), fn($i) => $i > 0)) : [];
        $voci = is_array($voci) ? $voci : [];
        // #141: visto "ferie estiva" applicato a tutte le voci create in questo giro
        // (solo GIU-SET, stessa regola di toggle_estiva sopra — fuori stagione ignorato,
        // non blocca il resto del batch).
        $estiva = !empty($_POST['estiva']);

        if (!$vigili || !$voci) {
            echo json_encode(['ok' => false, 'errore' => 'Seleziona almeno un vigile e un giorno.']);
            exit;
        }

        // Slot reale del turno attivo per una data: 'D', 'N' o null (non in servizio).
        $slotTurnoB = function (string $data) use ($TURNO): ?string {
            $tg = getTurnoGiorno($data);
            if (($tg['diurno']['turno'] ?? '') === $TURNO) return 'D';
            if (($tg['notte']['turno']  ?? '') === $TURNO) return 'N';
            return null;
        };

        $insReq = $pdo->prepare(
            "INSERT IGNORE INTO bot_requests (vigile_id, data_richiesta, tipo_turno, stato, processed_at, ferie_estiva)
             VALUES (?, ?, ?, 'pending', NULL, ?)"
        );

        $creati = 0; $duplicati = 0; $scartati = 0;
        $pdo->beginTransaction();
        try {
            foreach ($voci as $v) {
                $data = (string)($v['data'] ?? '');
                $tipo = (string)($v['tipo'] ?? '');
                // valida data e coerenza col ciclo turno B
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !in_array($tipo, ['D', 'N'], true)) {
                    $scartati++; continue;
                }
                if ($slotTurnoB($data) !== $tipo) { $scartati++; continue; }

                $mese     = (int)date('n', strtotime($data));
                $isEstiva = $estiva && in_array($mese, [6, 7, 8, 9], true) ? 1 : 0;

                foreach ($vigili as $vid) {
                    $insReq->execute([$vid, $data, $tipo, $isEstiva]);
                    if ($insReq->rowCount() > 0) $creati++; else $duplicati++;
                    // assenza sul foglio giusto (crea il foglio se non esiste): pending
                    feriaSyncAssenza($pdo, $vid, $data, $tipo, 'pending');
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB: ' . $e->getMessage()]);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'creati' => $creati, 'duplicati' => $duplicati, 'scartati' => $scartati,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'errore' => 'Azione sconosciuta']);
    exit;
}

// ── Vigili attivi per il dropdown ────────────────────────────────────────────
$vigili = $pdo->query(
    "SELECT id, cognome, nome, disambiguatore
       FROM vigili WHERE attivo = 1 AND turno = '$TURNO'
   ORDER BY cognome ASC, nome ASC, disambiguatore ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Ferie simulate (BETA)</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<style>
  .fs-beta { display:inline-block; background:#b9770e; color:#fff; font-size:.66rem; font-weight:800;
             letter-spacing:.5px; padding:2px 8px; border-radius:6px; vertical-align:middle; margin-left:8px; }
  .fs-wrap { display:grid; grid-template-columns:280px 1fr; gap:18px; align-items:start; }
  @media (max-width:900px){ .fs-wrap{ grid-template-columns:1fr; } }
  .fs-box { background:#fff; border:1px solid #e4e7ea; border-radius:10px; padding:16px; box-shadow:var(--shadow); }
  .fs-box h3 { margin:0 0 10px; font-size:.95rem; color:var(--rosso); text-transform:uppercase; letter-spacing:.5px; }
  .fs-search { width:100%; padding:7px 10px; border:1px solid #ccc; border-radius:6px; margin-bottom:8px; font-size:.9rem; }
  .fs-vigili { max-height:380px; overflow:auto; border:1px solid #eee; border-radius:6px; }
  .fs-vrow { display:flex; align-items:center; gap:8px; padding:6px 10px; border-bottom:1px solid #f1f1f1; cursor:pointer; font-size:.88rem; }
  .fs-vrow:hover { background:#f8f9fa; }
  .fs-vrow input { width:16px; height:16px; }
  .fs-vactions { display:flex; gap:8px; margin-bottom:8px; }
  .fs-cal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
  .fs-cal-head h4 { margin:0; font-size:1.05rem; }
  .fs-nav { background:var(--rosso); color:#fff; border:none; border-radius:6px; padding:6px 12px; cursor:pointer; font-size:1rem; }
  .fs-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
  .fs-dow { text-align:center; font-size:.7rem; font-weight:700; color:var(--grigio-md); text-transform:uppercase; padding:4px 0; }
  .fs-cell { min-height:54px; border:1px solid #e8e8e8; border-radius:6px; padding:4px 6px; background:#fafafa; position:relative; }
  .fs-cell .gn { font-size:.8rem; font-weight:700; color:var(--grigio-sc); }
  .fs-cell.off { background:#f3f3f3; color:#bbb; }
  .fs-cell.off .gn { color:#bbb; }
  .fs-cell.slot { cursor:pointer; }
  .fs-cell.slot:hover { border-color:var(--rosso); }
  .fs-cell .ico { display:block; font-size:1.1rem; line-height:1; margin-top:4px; }
  .fs-cell.sel { background:#fde8e6; border-color:var(--rosso); box-shadow:0 0 0 2px rgba(192,57,43,.25); }
  .fs-cell.sel::after { content:'✓'; position:absolute; top:2px; right:5px; color:var(--rosso); font-weight:800; font-size:.8rem; }
  .fs-foot { margin-top:14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
  .fs-count { font-size:.85rem; color:var(--grigio-md); font-weight:600; }
  .fs-estiva { display:flex; align-items:center; gap:6px; font-size:.85rem; font-weight:600;
               color:var(--grigio-sc); cursor:pointer; margin-left:auto; }
  .fs-estiva input { width:16px; height:16px; cursor:pointer; }
  .fs-go { background:#1e7e34; color:#fff; border:none; border-radius:8px; padding:10px 22px; font-size:.95rem; font-weight:700; cursor:pointer; }
  .fs-go:disabled { opacity:.5; cursor:not-allowed; }
  .fs-msg { margin-top:12px; padding:10px 14px; border-radius:8px; font-size:.9rem; display:none; }
  .fs-msg.ok { background:#e6f4ea; color:#1e7e34; display:block; }
  .fs-msg.err { background:#fdecea; color:#c0392b; display:block; }
  .fs-hint { font-size:.78rem; color:var(--grigio-md); margin:0 0 10px; line-height:1.4; }
</style>
</head>
<body>
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno <?= htmlspecialchars($TURNO) ?></p>
    </div>
    <div class="header-badge">TURNO&nbsp;<?= htmlspecialchars($TURNO) ?></div>
  </div>
</header>
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🚒 Home</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Nuovo Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="index.php"           class="nav-btn active">⚙️ Amministrazione</a>
    <span style="margin-left:auto"><?= turnoComandoHtml() ?></span>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>🏖️ Caricamento ferie <span class="fs-beta">BETA</span></h2>
  </div>
  <p class="fs-hint">
    Strumento interno per caricare le ferie (estive e dell'agenda cartacea) come se fossero
    arrivate dal bot: crea la voce in <strong>Agenda</strong> (stato <em>pending</em>) e l'assenza
    sul <strong>foglio del giorno giusto</strong>. Nessuna mail inviata.<br>
    Ogni giorno mostra lo slot reale del turno <?= htmlspecialchars($TURNO) ?>: ☀️ diurno, 🌙 notturno.
    I giorni in cui il turno non è in servizio non sono selezionabili.
  </p>

  <div class="fs-wrap">

    <!-- ── Vigili ── -->
    <div class="fs-box">
      <h3>Vigili</h3>
      <input type="text" class="fs-search" id="vSearch" placeholder="Cerca per cognome…">
      <div class="fs-vactions">
        <button type="button" class="btn btn-sm btn-grigio" onclick="selVigili(true)">Tutti (visibili)</button>
        <button type="button" class="btn btn-sm btn-grigio" onclick="selVigili(false)">Nessuno</button>
      </div>
      <div class="fs-vigili" id="vList">
        <?php foreach ($vigili as $v):
            $lbl = strtoupper($v['cognome']) . ' ' . $v['nome'];
            if (!empty($v['disambiguatore'])) $lbl .= ' (' . $v['disambiguatore'] . ')';
        ?>
        <label class="fs-vrow" data-nome="<?= htmlspecialchars(strtolower($lbl)) ?>">
          <input type="checkbox" class="vchk" value="<?= (int)$v['id'] ?>">
          <span><?= htmlspecialchars($lbl) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── Calendario ── -->
    <div class="fs-box">
      <div class="fs-cal-head">
        <button type="button" class="fs-nav" onclick="cambiaMese(-1)">‹</button>
        <h4 id="calTitolo"></h4>
        <button type="button" class="fs-nav" onclick="cambiaMese(1)">›</button>
      </div>
      <div class="fs-grid" id="calGrid"></div>

      <div class="fs-foot">
        <button type="button" class="fs-go" id="btnCrea" onclick="creaVoci()">Crea voci ferie</button>
        <span class="fs-count" id="contatori">0 vigili · 0 giorni</span>
        <label class="fs-estiva" title="Contrassegna come ferie estive (solo giugno–settembre)">
          <input type="checkbox" id="chkEstiva"> 🏖️ Ferie estiva
        </label>
      </div>
      <div class="fs-msg" id="msg"></div>
    </div>

  </div>
</main>

<script>
// ── Ciclo turni (porta di includes/turni.php) ────────────────────────────────
// Ancora 2026-05-01 = giorno 0, diurno turno B (indice 1 in A,B,C,D).
const ANCHOR = Date.UTC(2026, 4, 1);   // 2026-05-01
const ANCHOR_TURNO = 1;                // B
function giorniDallAncora(y, m, d) {   // m: 1-12
  return Math.round((Date.UTC(y, m - 1, d) - ANCHOR) / 86400000);
}
function turnoDiurno(giorni) {         // lettera del diurno (A,B,C,D)
  const idx = (((ANCHOR_TURNO + giorni) % 4) + 4) % 4;
  return ['A', 'B', 'C', 'D'][idx];
}
const TURNO_ATTIVO = '<?= $TURNO ?>';
// Slot del turno attivo in una data: 'D', 'N' o null (non in servizio)
function slotTurnoB(y, m, d) {
  const g = giorniDallAncora(y, m, d);
  if (turnoDiurno(g)     === TURNO_ATTIVO) return 'D';   // diurno
  if (turnoDiurno(g - 1) === TURNO_ATTIVO) return 'N';   // notturno = diurno del giorno prima
  return null;
}

const MESI = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
              'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
const DOW  = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];

let viewY, viewM;                       // mese visualizzato (m: 1-12)
const selezione = new Set();            // "Y-m-d:tipo"

function pad(n){ return String(n).padStart(2,'0'); }

function renderCal() {
  document.getElementById('calTitolo').textContent = MESI[viewM-1] + ' ' + viewY;
  const grid = document.getElementById('calGrid');
  grid.innerHTML = '';
  DOW.forEach(d => {
    const h = document.createElement('div'); h.className = 'fs-dow'; h.textContent = d; grid.appendChild(h);
  });
  // primo giorno (Lun=1..Dom=7)
  const primo = new Date(Date.UTC(viewY, viewM-1, 1)).getUTCDay(); // 0=Dom
  const offset = (primo === 0 ? 6 : primo - 1);
  for (let i = 0; i < offset; i++) {
    const e = document.createElement('div'); grid.appendChild(e);
  }
  const ultimo = new Date(Date.UTC(viewY, viewM, 0)).getUTCDate();
  for (let g = 1; g <= ultimo; g++) {
    const slot = slotTurnoB(viewY, viewM, g);
    const cell = document.createElement('div');
    cell.className = 'fs-cell' + (slot ? ' slot' : ' off');
    cell.innerHTML = '<span class="gn">' + g + '</span>';
    if (slot) {
      const key = `${viewY}-${pad(viewM)}-${pad(g)}:${slot}`;
      cell.innerHTML += '<span class="ico">' + (slot === 'D' ? '☀️' : '🌙') + '</span>';
      if (selezione.has(key)) cell.classList.add('sel');
      cell.onclick = () => {
        if (selezione.has(key)) { selezione.delete(key); cell.classList.remove('sel'); }
        else { selezione.add(key); cell.classList.add('sel'); }
        aggiornaContatori();
      };
    }
    grid.appendChild(cell);
  }
}

function cambiaMese(delta) {
  viewM += delta;
  if (viewM < 1)  { viewM = 12; viewY--; }
  if (viewM > 12) { viewM = 1;  viewY++; }
  renderCal();
}

function vigiliSelezionati() {
  return [...document.querySelectorAll('.vchk:checked')].map(c => parseInt(c.value));
}
function aggiornaContatori() {
  const nv = vigiliSelezionati().length;
  const ng = selezione.size;
  document.getElementById('contatori').textContent =
    `${nv} vigil${nv===1?'e':'i'} · ${ng} giorn${ng===1?'o':'i'}`;
  document.getElementById('btnCrea').disabled = (nv === 0 || ng === 0);
}

function selVigili(on) {
  document.querySelectorAll('.fs-vrow').forEach(r => {
    if (r.style.display === 'none') return;       // solo i visibili dal filtro
    r.querySelector('.vchk').checked = on;
  });
  aggiornaContatori();
}

document.getElementById('vSearch').addEventListener('input', e => {
  const t = e.target.value.toLowerCase().trim();
  document.querySelectorAll('.fs-vrow').forEach(r => {
    r.style.display = (!t || r.dataset.nome.includes(t)) ? '' : 'none';
  });
});
document.getElementById('vList').addEventListener('change', aggiornaContatori);

async function creaVoci() {
  const vigili = vigiliSelezionati();
  const voci = [...selezione].map(k => {
    const [data, tipo] = k.split(':');
    return { data, tipo };
  });
  const btn = document.getElementById('btnCrea');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('azione', 'crea_voci');
  fd.append('vigili', JSON.stringify(vigili));
  fd.append('voci',   JSON.stringify(voci));
  fd.append('estiva', document.getElementById('chkEstiva').checked ? '1' : '');
  const msg = document.getElementById('msg');
  try {
    const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
    if (res.ok) {
      msg.className = 'fs-msg ok';
      msg.textContent = `✅ Create ${res.creati} voci`
        + (res.duplicati ? ` · ${res.duplicati} già presenti` : '')
        + (res.scartati ? ` · ${res.scartati} scartate` : '') + '.';
      selezione.clear();
      renderCal();
      // #141: reset dei visti dopo la creazione, per non dimenticarne uno attivo
      // e ricrearci ferie non richieste al giro successivo.
      document.querySelectorAll('.vchk').forEach(c => c.checked = false);
      document.getElementById('chkEstiva').checked = false;
    } else {
      msg.className = 'fs-msg err';
      msg.textContent = '⚠️ ' + (res.errore || 'Errore.');
    }
  } catch (err) {
    msg.className = 'fs-msg err';
    msg.textContent = '⚠️ Errore di rete.';
  }
  aggiornaContatori();
}

// init sul mese corrente
const oggi = new Date();
viewY = oggi.getFullYear();
viewM = oggi.getMonth() + 1;
renderCal();
aggiornaContatori();
</script>
</body>
</html>
