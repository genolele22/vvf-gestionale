<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediAdmin();
require_once __DIR__ . '/../includes/turni.php';               // getTurnoGiorno()
require_once __DIR__ . '/../includes/ferie_assenze.php';       // feriaSyncAssenza()
require_once __DIR__ . '/../includes/bot_requests_schema.php'; // assicuraSchemaRichiesteAssenza()
require_once __DIR__ . '/../includes/scambio_salto.php';       // scambioConflittiRighe()
$TURNO = turnoAmministrazione();   // fisso sul turno di casa (admin/user niente switch qui)

$pdo = getDB();
assicuraSchemaRichiesteAssenza($pdo);   // idempotente: garantisce tipo_assenza_id/range_da/range_a

// Missione/Malattia/Infortunio: id di tipo_assenza già hardcoded così in tutto il
// progetto (finalize_ferie.php, ferie/index.php). Il PERMESSO (id 4) è fuori
// scope qui: gestito a parte in admin/ferie_simulate.php.
$MAPPA_TIPO_ASSENZA = ['MISS' => 3, 'MAL' => 5, 'INF' => 6];
$MAPPA_LABEL_TIPO   = ['MISS' => 'missione', 'MAL' => 'malattia', 'INF' => 'infortunio'];

// ── AJAX: crea le voci (come richieste BOT, già "approved" — mirror di
//    TIPI_AUTO_APPROVATI in database.py: missione/malattia/infortunio si
//    registrano da sole) ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!puoModificareTurno($TURNO)) { echo json_encode(['ok' => false, 'errore' => 'Turno in sola lettura.']); exit; }
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'crea_voci') {
        $vigiliRaw = json_decode($_POST['vigili'] ?? '[]', true);
        $vigili = is_array($vigiliRaw)
            ? array_values(array_filter(array_map('intval', $vigiliRaw), fn($i) => $i > 0)) : [];
        $rangeDa = (string)($_POST['rangeDa'] ?? '');
        $rangeA  = (string)($_POST['rangeA']  ?? '');
        $tipoStr = (string)($_POST['tipo']    ?? '');

        $dataValida = function (string $s) {
            return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)
                && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
        };

        if (!$vigili) {
            echo json_encode(['ok' => false, 'errore' => 'Seleziona almeno un vigile.']); exit;
        }
        if (!isset($MAPPA_TIPO_ASSENZA[$tipoStr])) {
            echo json_encode(['ok' => false, 'errore' => 'Seleziona il tipo di assenza.']); exit;
        }
        if (!$dataValida($rangeDa) || !$dataValida($rangeA)) {
            echo json_encode(['ok' => false, 'errore' => 'Seleziona un intervallo di date valido.']); exit;
        }
        if ($rangeDa > $rangeA) {
            echo json_encode(['ok' => false, 'errore' => 'La data di fine deve essere successiva (o uguale) a quella di inizio.']); exit;
        }
        $nGiorni = (int)(new DateTime($rangeDa))->diff(new DateTime($rangeA))->days + 1;
        if ($nGiorni > 180) {
            echo json_encode(['ok' => false, 'errore' => 'Intervallo troppo lungo (max 180 giorni): controlla le date.']); exit;
        }

        $tipoAssenzaId = $MAPPA_TIPO_ASSENZA[$tipoStr];

        // Slot reale del turno attivo per una data: 'D', 'N' o null (non in servizio).
        $slotTurnoB = function (string $data) use ($TURNO): ?string {
            $tg = getTurnoGiorno($data);
            if (($tg['diurno']['turno'] ?? '') === $TURNO) return 'D';
            if (($tg['notte']['turno']  ?? '') === $TURNO) return 'N';
            return null;
        };

        $insReq = $pdo->prepare(
            "INSERT IGNORE INTO bot_requests
                (vigile_id, data_richiesta, tipo_turno, tipo_assenza_id, stato, processed_at, range_da, range_a)
             VALUES (?, ?, ?, ?, 'approved', NOW(), ?, ?)"
        );
        // Duplicato su uq_req (vigile_id, data_richiesta, tipo_turno): la chiave
        // NON include tipo_assenza_id, quindi va controllato il tipo della riga
        // già presente prima di ri-sincronizzare l'assenza sul foglio.
        $chkTipo = $pdo->prepare(
            "SELECT tipo_assenza_id FROM bot_requests WHERE vigile_id=? AND data_richiesta=? AND tipo_turno=?"
        );

        $creati = 0; $duplicati = 0; $fuoriServizio = 0;
        $righeSincronizzate = [];   // [vigile_id, data, tipo] — per l'avviso scambio salto
        $pdo->beginTransaction();
        try {
            $cur  = new DateTime($rangeDa);
            $fine = new DateTime($rangeA);
            while ($cur <= $fine) {
                $data      = $cur->format('Y-m-d');
                $tipoTurno = $slotTurnoB($data);
                if ($tipoTurno === null) {           // turno non in servizio quel giorno: salta silenziosamente
                    $fuoriServizio++;
                    $cur->modify('+1 day');
                    continue;
                }
                foreach ($vigili as $vid) {
                    $insReq->execute([$vid, $data, $tipoTurno, $tipoAssenzaId, $rangeDa, $rangeA]);
                    if ($insReq->rowCount() > 0) {
                        $creati++;
                        feriaSyncAssenza($pdo, $vid, $data, $tipoTurno, 'approved', $tipoAssenzaId);
                        $righeSincronizzate[] = [$vid, $data, $tipoTurno];
                    } else {
                        $duplicati++;
                        $chkTipo->execute([$vid, $data, $tipoTurno]);
                        if ((int)$chkTipo->fetchColumn() === $tipoAssenzaId) {
                            feriaSyncAssenza($pdo, $vid, $data, $tipoTurno, 'approved', $tipoAssenzaId);
                            $righeSincronizzate[] = [$vid, $data, $tipoTurno];
                        }
                        // tipo diverso (es. ferie già presenti quel giorno): non si tocca nulla.
                    }
                }
                $cur->modify('+1 day');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB: ' . $e->getMessage()]);
            exit;
        }

        // Missione/malattia/infortunio hanno la precedenza sullo scambio salto
        // (si registrano comunque) — solo un avviso, mai un blocco: stesso
        // principio usato per ferie/permesso in admin/ferie_simulate.php,
        // decide la fureria.
        $avvisi = $righeSincronizzate
            ? scambioConflittiRighe($pdo, $righeSincronizzate, $MAPPA_LABEL_TIPO[$tipoStr])
            : [];

        echo json_encode([
            'ok' => true,
            'creati' => $creati, 'duplicati' => $duplicati, 'fuoriServizio' => $fuoriServizio,
            'avvisi' => $avvisi,
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
<title>VVF – Assenze simulate (BETA)</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<script src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .fs-beta { display:inline-block; background:#b9770e; color:#fff; font-size:.66rem; font-weight:800;
             letter-spacing:.5px; padding:2px 8px; border-radius:6px; vertical-align:middle; margin-left:8px; }
  .fs-wrap { display:grid; grid-template-columns:280px 1fr; gap:18px; align-items:start; }
  @media (max-width:900px){ .fs-wrap{ grid-template-columns:1fr; } }
  .fs-col { display:flex; flex-direction:column; gap:18px; }
  .fs-box { background:#fff; border:1px solid #e4e7ea; border-radius:10px; padding:16px; box-shadow:var(--shadow); }
  .fs-box h3 { margin:0 0 10px; font-size:.95rem; color:var(--rosso); text-transform:uppercase; letter-spacing:.5px; }
  .fs-search { width:100%; padding:7px 10px; border:1px solid #ccc; border-radius:6px; margin-bottom:8px; font-size:.9rem; }
  .fs-vigili { max-height:380px; overflow:auto; border:1px solid #eee; border-radius:6px; }
  .fs-vrow { display:flex; align-items:center; gap:8px; padding:6px 10px; border-bottom:1px solid #f1f1f1; cursor:pointer; font-size:.88rem; }
  .fs-vrow:hover { background:#f8f9fa; }
  .fs-vrow input { width:16px; height:16px; }
  .fs-vactions { display:flex; gap:8px; margin-bottom:8px; }

  .fs-chips { display:flex; gap:10px; }
  .fs-chip { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px;
             padding:12px 8px; border:2px solid #e4e7ea; border-radius:10px; background:#fafafa;
             cursor:pointer; font-size:.82rem; font-weight:700; color:var(--grigio-sc);
             user-select:none; transition:border-color .15s, background .15s; }
  .fs-chip .ico { font-size:1.5rem; line-height:1; }
  .fs-chip:hover { border-color:var(--rosso); }
  .fs-chip.active { border-color:var(--rosso); background:#fde8e6; color:var(--rosso); }

  .fs-cal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
  .fs-cal-head h4 { margin:0; font-size:1.05rem; }
  .fs-nav { background:var(--rosso); color:#fff; border:none; border-radius:6px; padding:6px 12px; cursor:pointer; font-size:1rem; }
  .fs-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
  .fs-dow { text-align:center; font-size:.7rem; font-weight:700; color:var(--grigio-md); text-transform:uppercase; padding:4px 0; }
  .fs-cell { min-height:54px; border:1px solid #e8e8e8; border-radius:6px; padding:4px 6px; background:#fafafa;
             position:relative; cursor:pointer; }
  .fs-cell:hover { border-color:var(--rosso); }
  .fs-cell .gn { font-size:.8rem; font-weight:700; color:var(--grigio-sc); }
  .fs-cell.rmid, .fs-cell.rstart, .fs-cell.rend, .fs-cell.rsingle, .fs-cell.rpending { background:#fde8e6; }
  .fs-cell.rstart, .fs-cell.rend, .fs-cell.rsingle, .fs-cell.rpending {
    border-color:var(--rosso); box-shadow:0 0 0 2px rgba(192,57,43,.25);
  }
  .fs-cell.rstart, .fs-cell.rsingle, .fs-cell.rpending { border-top-left-radius:10px; border-bottom-left-radius:10px; }
  .fs-cell.rend, .fs-cell.rsingle { border-top-right-radius:10px; border-bottom-right-radius:10px; }
  .fs-cell.rstart::after, .fs-cell.rpending::after {
    content:'IN'; position:absolute; top:2px; right:5px; color:var(--rosso); font-weight:800; font-size:.6rem;
  }
  .fs-cell.rend::after {
    content:'FINE'; position:absolute; top:2px; right:5px; color:var(--rosso); font-weight:800; font-size:.6rem;
  }
  .fs-cell.rsingle::after {
    content:'✓'; position:absolute; top:2px; right:5px; color:var(--rosso); font-weight:800; font-size:.8rem;
  }
  .fs-cal-actions { display:flex; justify-content:flex-end; margin-top:8px; }
  .fs-clear { background:#eee; color:#555; border:none; border-radius:6px; padding:5px 12px; font-size:.78rem; cursor:pointer; }
  .fs-clear:hover { background:#e2e2e2; }

  .fs-foot { margin-top:14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
  .fs-count { font-size:.85rem; color:var(--grigio-md); font-weight:600; }
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
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="../report/index.php" class="nav-btn">📊 Reportistica</a>
    <a href="index.php"           class="nav-btn active">⚙️ Amministrazione</a>
    <a href="../logbook/index.php" class="nav-btn">📓 Logbook</a>
    <a href="../cambia_password.php" class="nav-btn ml-auto">🔑 Password</a>
    <?= turnoComandoHtml() ?>
    <a href="../logout.php"       class="nav-btn">🚪 Esci</a>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>🚑 Caricamento assenze <span class="fs-beta">BETA</span></h2>
  </div>
  <p class="fs-hint">
    Strumento interno per caricare missione, malattia e infortunio (agenda cartacea) come se
    fossero arrivate dal bot: nascono già <strong>approvate</strong> (nessuna accettazione in
    fureria), con voce in <strong>Agenda</strong> e assenza sul <strong>foglio del giorno giusto</strong>.
    Nessuna mail inviata.<br>
    Scegli il tipo, poi l'intervallo di date <strong>dichiarato</strong> (primo click = inizio,
    secondo click = fine): i giorni in cui il turno <?= htmlspecialchars($TURNO) ?> non è in
    servizio vengono saltati automaticamente, senza bisogno di indicarli a mano.<br>
    Il permesso non è gestito qui: vedi lo strumento «Caricamento ferie».
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

    <!-- ── Tipo + Calendario ── -->
    <div class="fs-col">

      <div class="fs-box">
        <h3>Tipo assenza</h3>
        <div class="fs-chips">
          <div class="fs-chip" data-tipo="MISS"><span class="ico">✈️</span><span>Missione</span></div>
          <div class="fs-chip" data-tipo="MAL"><span class="ico">🤒</span><span>Malattia</span></div>
          <div class="fs-chip" data-tipo="INF"><span class="ico">🤕</span><span>Infortunio</span></div>
        </div>
      </div>

      <div class="fs-box">
        <div class="fs-cal-head">
          <button type="button" class="fs-nav" onclick="cambiaMese(-1)">‹</button>
          <h4 id="calTitolo"></h4>
          <button type="button" class="fs-nav" onclick="cambiaMese(1)">›</button>
        </div>
        <div class="fs-grid" id="calGrid"></div>
        <div class="fs-cal-actions">
          <button type="button" class="fs-clear" onclick="cancellaIntervallo()">Cancella intervallo</button>
        </div>

        <div class="fs-foot">
          <button type="button" class="fs-go" id="btnCrea" onclick="creaVoci()">Crea voci assenza</button>
          <span class="fs-count" id="contatori">0 vigili · nessun intervallo</span>
        </div>
        <div class="fs-msg" id="msg"></div>
      </div>

    </div>

  </div>
</main>

<script>
const MESI = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
              'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
const DOW  = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];

let viewY, viewM;                       // mese visualizzato (m: 1-12)
let rangeStart = null, rangeEnd = null; // 'Y-m-d' o null; selezione "stile booking"
let tipoSelezionato = null;             // 'MISS' | 'MAL' | 'INF'

function pad(n){ return String(n).padStart(2,'0'); }
function fmtData(s) {
  if (!s) return '';
  const [y, m, d] = s.split('-');
  return `${d}/${m}/${y}`;
}

function classeGiorno(dateStr) {
  if (rangeStart && !rangeEnd) return dateStr === rangeStart ? 'rpending' : '';
  if (!rangeStart || !rangeEnd) return '';
  if (dateStr === rangeStart && dateStr === rangeEnd) return 'rsingle';
  if (dateStr === rangeStart) return 'rstart';
  if (dateStr === rangeEnd) return 'rend';
  if (dateStr > rangeStart && dateStr < rangeEnd) return 'rmid';
  return '';
}

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
    grid.appendChild(document.createElement('div'));
  }
  const ultimo = new Date(Date.UTC(viewY, viewM, 0)).getUTCDate();
  for (let g = 1; g <= ultimo; g++) {
    const dateStr = `${viewY}-${pad(viewM)}-${pad(g)}`;
    const cls = classeGiorno(dateStr);
    const cell = document.createElement('div');
    cell.className = 'fs-cell' + (cls ? ' ' + cls : '');
    cell.innerHTML = '<span class="gn">' + g + '</span>';
    cell.onclick = () => onDayClick(dateStr);
    grid.appendChild(cell);
  }
}

// Selezione a intervallo "stile booking": 1° click = inizio, 2° click = fine
// (swap automatico se il 2° click è prima del 1°), 3° click ricomincia.
function onDayClick(dateStr) {
  if (rangeStart && !rangeEnd) {
    let a = rangeStart, b = dateStr;
    if (b < a) { [a, b] = [b, a]; }
    rangeStart = a; rangeEnd = b;
  } else {
    rangeStart = dateStr; rangeEnd = null;
  }
  renderCal();
  aggiornaContatori();
}

function cancellaIntervallo() {
  rangeStart = null; rangeEnd = null;
  renderCal();
  aggiornaContatori();
}

function cambiaMese(delta) {
  viewM += delta;
  if (viewM < 1)  { viewM = 12; viewY--; }
  if (viewM > 12) { viewM = 1;  viewY++; }
  renderCal();
}

document.querySelectorAll('.fs-chip').forEach(chip => {
  chip.addEventListener('click', () => {
    document.querySelectorAll('.fs-chip').forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    tipoSelezionato = chip.dataset.tipo;
    aggiornaContatori();
  });
});

function vigiliSelezionati() {
  return [...document.querySelectorAll('.vchk:checked')].map(c => parseInt(c.value));
}
function aggiornaContatori() {
  const nv = vigiliSelezionati().length;
  let testoRange = 'nessun intervallo';
  if (rangeStart && rangeEnd) {
    const giorni = Math.round((new Date(rangeEnd) - new Date(rangeStart)) / 86400000) + 1;
    testoRange = `dal ${fmtData(rangeStart)} al ${fmtData(rangeEnd)} (${giorni} giorn${giorni===1?'o':'i'})`;
  } else if (rangeStart) {
    testoRange = `inizio ${fmtData(rangeStart)} — scegli la fine`;
  }
  document.getElementById('contatori').textContent =
    `${nv} vigil${nv===1?'e':'i'} · ${testoRange}`;
  document.getElementById('btnCrea').disabled = !(nv > 0 && rangeStart && rangeEnd && tipoSelezionato);
}

// Solo avviso, mai un blocco (missione/malattia/infortunio hanno la
// precedenza sullo scambio salto, si registrano comunque): stesso principio
// già in uso nel foglio (#188) e in admin/ferie_simulate.php.
function mostraAvvisiScambioSalto(avvisi) {
  if (!avvisi || !avvisi.length) return;
  chiediConferma({
    titolo:  '⚠️ Assenza e scambio salto',
    testo:   'Attenzione — questi vigili risultano assenti ma anche in uno ' +
             'scambio salto attivo per lo stesso turno:<br><br>' +
             avvisi.map(a => '• ' + a).join('<br>') +
             '<br><br>Puoi sistemare il foglio o lasciarlo così.',
    okLabel: 'Ho capito',
    okStyle: 'background:#b7950b;color:#fff',
    soloOk:  true,   // #199: solo avviso, niente da annullare
  });
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
  const btn = document.getElementById('btnCrea');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('azione', 'crea_voci');
  fd.append('vigili', JSON.stringify(vigili));
  fd.append('rangeDa', rangeStart || '');
  fd.append('rangeA', rangeEnd || '');
  fd.append('tipo', tipoSelezionato || '');
  const msg = document.getElementById('msg');
  try {
    const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
    if (res.ok) {
      msg.className = 'fs-msg ok';
      msg.textContent = `✅ Create ${res.creati} voci`
        + (res.duplicati ? ` · ${res.duplicati} già presenti` : '')
        + (res.fuoriServizio ? ` · ${res.fuoriServizio} giorni fuori servizio saltati` : '') + '.';
      rangeStart = null; rangeEnd = null;
      renderCal();
      document.querySelectorAll('.vchk').forEach(c => c.checked = false);
      document.querySelectorAll('.fs-chip').forEach(c => c.classList.remove('active'));
      tipoSelezionato = null;
      mostraAvvisiScambioSalto(res.avvisi);
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
<?php require __DIR__ . '/../includes/logbook_widget.php'; ?>
</body>
</html>
