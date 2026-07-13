<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/format.php';
richiediLogin();

$pdo   = getDB();
$TURNO = turnoAttivo();   // report di consultazione: segue il turno in vista

// ── Periodo (default: anno corrente fino a oggi). I conteggi hanno senso solo
//    sui servizi già lavorati: la fine è comunque limitata a oggi. ─────────────
$oggi = date('Y-m-d');
$valData = fn($v, $fb) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : $fb;
$da = $valData($_GET['da'] ?? '', date('Y') . '-01-01');
$a  = $valData($_GET['a']  ?? '', $oggi);
if ($a > $oggi) $a = $oggi;
if ($da > $a)   $da = $a;
$conSpec = isset($_GET['spec']);   // includi specialisti (default: esclusi dai conteggi operativi)

// ── Base: vigili attivi del turno (specialisti esclusi salvo richiesta) ──────
$sqlSpec = $conSpec ? '' : ' AND COALESCE(v.specialista,0) = 0';
$stV = $pdo->prepare(
    "SELECT v.id, v.cognome, v.disambiguatore, v.sede_id, q.codice AS qcodice,
            s.codice AS sede_codice, st.codice AS salto_codice
     FROM vigili v
     JOIN qualifiche q   ON q.id  = v.qualifica_id
     JOIN sedi s         ON s.id  = v.sede_id
     JOIN salti_turno st ON st.id = v.salto_id
     WHERE v.attivo = 1 AND v.turno = ?$sqlSpec
     ORDER BY v.cognome, v.disambiguatore"
);
$stV->execute([$TURNO]);
$vigili = [];
foreach ($stV->fetchAll() as $v) $vigili[(int)$v['id']] = $v;

// Quanti servizi (fogli) esistono nel periodo: se zero, i numeri sotto sono vuoti.
$stF = $pdo->prepare(
    "SELECT COUNT(*) FROM fogli_servizio WHERE turno=? AND data_servizio BETWEEN ? AND ?");
$stF->execute([$TURNO, $da, $a]);
$nFogli = (int)$stF->fetchColumn();

// ── 1) Turni lavorati / fuori sede per vigile ────────────────────────────────
// "Fuori sede" = assegnato a una posizione la cui sede ≠ sede del vigile
// (stessa regola della sigla sul foglio e sull'ODT).
$stT = $pdo->prepare(
    "SELECT a.vigile_id,
            COUNT(*)                                                        AS turni_tot,
            SUM(p.sede_id <> v.sede_id)                                     AS fuori_tot,
            MAX(CASE WHEN p.sede_id <> v.sede_id THEN f.data_servizio END)  AS ultimo_fuori
     FROM assegnazioni a
     JOIN fogli_servizio f ON f.id = a.foglio_id
     JOIN posizioni p      ON p.id = a.posizione_id
     JOIN vigili v         ON v.id = a.vigile_id
     WHERE f.turno=? AND f.data_servizio BETWEEN ? AND ?
     GROUP BY a.vigile_id"
);
$stT->execute([$TURNO, $da, $a]);
$turniPerVigile = [];
foreach ($stT->fetchAll() as $r) $turniPerVigile[(int)$r['vigile_id']] = $r;

// Spaccato per sede di destinazione (solo turni fuori sede)
$stD = $pdo->prepare(
    "SELECT a.vigile_id, sp.codice AS sede_dest, COUNT(*) AS n
     FROM assegnazioni a
     JOIN fogli_servizio f ON f.id = a.foglio_id
     JOIN posizioni p      ON p.id = a.posizione_id
     JOIN sedi sp          ON sp.id = p.sede_id
     JOIN vigili v         ON v.id = a.vigile_id
     WHERE f.turno=? AND f.data_servizio BETWEEN ? AND ? AND p.sede_id <> v.sede_id
     GROUP BY a.vigile_id, sp.codice"
);
$stD->execute([$TURNO, $da, $a]);
$destPerVigile = [];
foreach ($stD->fetchAll() as $r) $destPerVigile[(int)$r['vigile_id']][$r['sede_dest']] = (int)$r['n'];

// ── 2) Prima partenza (squadra 1A): volte, di cui capopartenza, ultima ───────
$stP = $pdo->prepare(
    "SELECT a.vigile_id, COUNT(*) AS n, SUM(a.ordine = 1) AS da_capo,
            MAX(f.data_servizio) AS ultima
     FROM assegnazioni a
     JOIN fogli_servizio f ON f.id = a.foglio_id
     JOIN posizioni p      ON p.id = a.posizione_id
     WHERE p.codice = '1A' AND f.turno=? AND f.data_servizio BETWEEN ? AND ?
     GROUP BY a.vigile_id"
);
$stP->execute([$TURNO, $da, $a]);
$primaPerVigile = [];
foreach ($stP->fetchAll() as $r) $primaPerVigile[(int)$r['vigile_id']] = $r;

// ── 3) Straordinari: turni STR (12h l'uno) ───────────────────────────────────
$stS = $pdo->prepare(
    "SELECT a.vigile_id, COUNT(*) AS n, MAX(f.data_servizio) AS ultimo
     FROM assegnazioni a
     JOIN fogli_servizio f ON f.id = a.foglio_id
     WHERE a.in_straordinario = 1 AND f.turno=? AND f.data_servizio BETWEEN ? AND ?
     GROUP BY a.vigile_id"
);
$stS->execute([$TURNO, $da, $a]);
$strPerVigile = [];
foreach ($stS->fetchAll() as $r) $strPerVigile[(int)$r['vigile_id']] = $r;

// ── Helper display ────────────────────────────────────────────────────────────
$ggFa = function (?string $data): string {
    if (!$data) return '—';
    $gg = (int)((strtotime(date('Y-m-d')) - strtotime($data)) / 86400);
    return date('d/m/Y', strtotime($data)) . " <small>($gg gg fa)</small>";
};
$ggFaNum = fn(?string $data) => $data
    ? (int)((strtotime(date('Y-m-d')) - strtotime($data)) / 86400)
    : 99999;   // mai andato → in cima all'ordinamento "giorni senza"
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Reportistica</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<style>
  .rep-filtri { display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;
                background:var(--bianco); border-radius:var(--radius); box-shadow:var(--shadow);
                padding:14px 18px; margin-bottom:18px; }
  .rep-filtri label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--grigio-md); display:block; margin-bottom:4px; }
  .rep-filtri input[type=date] { padding:6px 8px; border:1px solid #d5d8dc; border-radius:5px; font:inherit; font-size:.85rem; }
  .rep-filtri .chk { display:flex; align-items:center; gap:6px; font-size:.82rem; font-weight:600; color:var(--grigio-sc); padding-bottom:7px; }
  .rep-nota { font-size:.78rem; color:var(--grigio-md); margin:-8px 0 16px 4px; }
  .rep-tabs { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
  .rep-tab  { font:inherit; font-size:.85rem; font-weight:700; padding:8px 16px; border-radius:8px;
              border:1px solid #d5d8dc; background:#fff; cursor:pointer; }
  .rep-tab.attivo { background:var(--rosso); color:#fff; border-color:var(--rosso); }
  .rep-sezione { display:none; }
  .rep-sezione.attiva { display:block; }
  table.rep th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
  table.rep th.sortable::after { content:' ⇅'; opacity:.35; font-size:.8em; }
  table.rep th.sortable[aria-sort="ascending"]::after  { content:' ▲'; opacity:.9; }
  table.rep th.sortable[aria-sort="descending"]::after { content:' ▼'; opacity:.9; }
  .badge-dest { display:inline-block; background:#eef1f4; border:1px solid #d5d8dc; color:var(--grigio-sc);
                font-size:.68rem; font-weight:700; border-radius:3px; padding:1px 6px; margin:1px 2px 1px 0; white-space:nowrap; }
  .n-zero { color:#bbb; }
</style>
</head>
<body>
<!-- HEADER -->
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
<!-- NAVBAR -->
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🚒 Home</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Nuovo Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="index.php"           class="nav-btn active">📊 Reportistica</a>
    <a href="../admin/index.php"  class="nav-btn">⚙️ Amministrazione</a>
    <span style="margin-left:auto"><?= turnoComandoHtml() ?></span>
    <a href="../logout.php"       class="nav-btn">🚪 Esci</a>
  </div>
</nav>
<!-- MAIN -->
<main class="main">
  <div class="page-title">
    <h2>📊 Reportistica — Turno <?= htmlspecialchars($TURNO) ?></h2>
  </div>

  <!-- Filtri periodo -->
  <form method="GET" class="rep-filtri">
    <div>
      <label>Dal</label>
      <input type="date" name="da" value="<?= htmlspecialchars($da) ?>" max="<?= $oggi ?>">
    </div>
    <div>
      <label>Al</label>
      <input type="date" name="a" value="<?= htmlspecialchars($a) ?>" max="<?= $oggi ?>">
    </div>
    <label class="chk">
      <input type="checkbox" name="spec" value="1" <?= $conSpec ? 'checked' : '' ?>>
      Includi specialisti
    </label>
    <button type="submit" class="btn btn-rosso btn-sm">🔍 Applica</button>
    <a href="index.php" class="btn btn-grigio btn-sm">↺ Anno corrente</a>
  </form>
  <p class="rep-nota">
    Periodo <?= date('d/m/Y', strtotime($da)) ?> → <?= date('d/m/Y', strtotime($a)) ?> ·
    <?= $nFogli ?> servizi a sistema<?= $conSpec ? '' : ' · specialisti esclusi' ?>.
    I conteggi valgono dai fogli compilati nel gestionale in poi.
  </p>

  <!-- Selettore sezione -->
  <div class="rep-tabs">
    <button type="button" class="rep-tab attivo" data-sez="fuori">🏠 Fuori sede</button>
    <button type="button" class="rep-tab" data-sez="prima">🚨 Prima partenza</button>
    <button type="button" class="rep-tab" data-sez="str">⏱️ Straordinari</button>
  </div>

  <!-- ══ 1. FUORI SEDE ══════════════════════════════════════════ -->
  <div class="rep-sezione attiva" id="sez-fuori">
    <div class="tabella-wrap">
      <div class="tabella-head"><span>🏠 Turni fuori sede — chi e dove</span></div>
      <table class="rep" id="tabFuori">
        <thead>
          <tr>
            <th class="sortable" data-col="0">Vigile</th>
            <th class="sortable" data-col="1">Sede</th>
            <th class="sortable" data-col="2">Turni lavorati</th>
            <th class="sortable" data-col="3">Fuori sede</th>
            <th>Dove</th>
            <th class="sortable" data-col="5" aria-sort="descending">Ultimo fuori sede</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($vigili as $vid => $v):
            $t    = $turniPerVigile[$vid] ?? null;
            $tot  = (int)($t['turni_tot'] ?? 0);
            $fuo  = (int)($t['fuori_tot'] ?? 0);
            $ult  = $t['ultimo_fuori'] ?? null;
            $dest = $destPerVigile[$vid] ?? [];
            ksort($dest);
        ?>
          <tr>
            <td data-sort="<?= htmlspecialchars(strtolower($v['cognome']) . sprintf('%03d', (int)$v['disambiguatore'])) ?>">
              <span class="badge-qual badge-<?= htmlspecialchars($v['qcodice']) ?>"><?= htmlspecialchars(ucfirst(strtolower($v['qcodice']))) ?></span>
              <strong><?= htmlspecialchars(ucfirst(strtolower($v['cognome']))) ?></strong>
              <?= $v['disambiguatore'] ? ' ' . (int)$v['disambiguatore'] : '' ?>
            </td>
            <td data-sort="<?= htmlspecialchars($v['sede_codice']) ?>"><?= htmlspecialchars(siglaSede($v['sede_codice'])) ?></td>
            <td data-sort="<?= $tot ?>" class="<?= $tot ? '' : 'n-zero' ?>"><?= $tot ?></td>
            <td data-sort="<?= $fuo ?>" class="<?= $fuo ? '' : 'n-zero' ?>"><strong><?= $fuo ?></strong></td>
            <td>
              <?php if (!$dest): ?><span class="n-zero">—</span>
              <?php else: foreach ($dest as $sc => $n): ?>
                <span class="badge-dest"><?= htmlspecialchars(siglaSede($sc)) ?> ×<?= $n ?></span>
              <?php endforeach; endif; ?>
            </td>
            <td data-sort="<?= $ggFaNum($ult) ?>"><?= $ult ? $ggFa($ult) : '<span class="n-zero">mai</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="pag-info">"Fuori sede" = in servizio in una sede diversa dalla propria (stessa regola della sigla sul foglio). Clic sulle intestazioni per ordinare — "Ultimo fuori sede" in ordine decrescente = chi non va fuori da più tempo.</div>
    </div>
  </div>

  <!-- ══ 2. PRIMA PARTENZA ══════════════════════════════════════ -->
  <div class="rep-sezione" id="sez-prima">
    <div class="tabella-wrap">
      <div class="tabella-head"><span>🚨 Prima partenza (squadra 1A)</span></div>
      <table class="rep" id="tabPrima">
        <thead>
          <tr>
            <th class="sortable" data-col="0">Vigile</th>
            <th class="sortable" data-col="1">Sede</th>
            <th class="sortable" data-col="2" aria-sort="descending">Volte in 1A</th>
            <th class="sortable" data-col="3">di cui capopartenza</th>
            <th class="sortable" data-col="4">Ultima volta</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($vigili as $vid => $v):
            $p = $primaPerVigile[$vid] ?? null;
            $n = (int)($p['n'] ?? 0);
        ?>
          <tr>
            <td data-sort="<?= htmlspecialchars(strtolower($v['cognome']) . sprintf('%03d', (int)$v['disambiguatore'])) ?>">
              <span class="badge-qual badge-<?= htmlspecialchars($v['qcodice']) ?>"><?= htmlspecialchars(ucfirst(strtolower($v['qcodice']))) ?></span>
              <strong><?= htmlspecialchars(ucfirst(strtolower($v['cognome']))) ?></strong>
              <?= $v['disambiguatore'] ? ' ' . (int)$v['disambiguatore'] : '' ?>
            </td>
            <td data-sort="<?= htmlspecialchars($v['sede_codice']) ?>"><?= htmlspecialchars(siglaSede($v['sede_codice'])) ?></td>
            <td data-sort="<?= $n ?>" class="<?= $n ? '' : 'n-zero' ?>"><strong><?= $n ?></strong></td>
            <td data-sort="<?= (int)($p['da_capo'] ?? 0) ?>" class="<?= ($p['da_capo'] ?? 0) ? '' : 'n-zero' ?>"><?= (int)($p['da_capo'] ?? 0) ?></td>
            <td data-sort="<?= $ggFaNum($p['ultima'] ?? null) ?>"><?= ($p['ultima'] ?? null) ? $ggFa($p['ultima']) : '<span class="n-zero">mai</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="pag-info">Capopartenza = prima casella della 1A. Il conteggio parte dai fogli compilati nel gestionale.</div>
    </div>
  </div>

  <!-- ══ 3. STRAORDINARI ════════════════════════════════════════ -->
  <div class="rep-sezione" id="sez-str">
    <div class="tabella-wrap">
      <div class="tabella-head"><span>⏱️ Straordinari (turni con marcatura STR)</span></div>
      <table class="rep" id="tabStr">
        <thead>
          <tr>
            <th class="sortable" data-col="0">Vigile</th>
            <th class="sortable" data-col="1">Sede</th>
            <th class="sortable" data-col="2" aria-sort="descending">Turni STR</th>
            <th class="sortable" data-col="3">Ore (12h a turno)</th>
            <th class="sortable" data-col="4">Ultimo STR</th>
          </tr>
        </thead>
        <tbody>
        <?php $qualcheStr = false;
        foreach ($vigili as $vid => $v):
            $s = $strPerVigile[$vid] ?? null;
            if (!$s) continue;   // qui solo chi ha almeno un turno STR
            $qualcheStr = true;
        ?>
          <tr>
            <td data-sort="<?= htmlspecialchars(strtolower($v['cognome']) . sprintf('%03d', (int)$v['disambiguatore'])) ?>">
              <span class="badge-qual badge-<?= htmlspecialchars($v['qcodice']) ?>"><?= htmlspecialchars(ucfirst(strtolower($v['qcodice']))) ?></span>
              <strong><?= htmlspecialchars(ucfirst(strtolower($v['cognome']))) ?></strong>
              <?= $v['disambiguatore'] ? ' ' . (int)$v['disambiguatore'] : '' ?>
            </td>
            <td data-sort="<?= htmlspecialchars($v['sede_codice']) ?>"><?= htmlspecialchars(siglaSede($v['sede_codice'])) ?></td>
            <td data-sort="<?= (int)$s['n'] ?>"><strong><?= (int)$s['n'] ?></strong></td>
            <td data-sort="<?= (int)$s['n'] * 12 ?>"><?= (int)$s['n'] * 12 ?> h</td>
            <td data-sort="<?= $ggFaNum($s['ultimo']) ?>"><?= $ggFa($s['ultimo']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$qualcheStr): ?>
          <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--grigio-md)">
            Nessun turno in straordinario nel periodo selezionato.
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      <div class="pag-info">Un turno STR = 12 ore. Conta la marcatura STR sul foglio (richiamati dal salto, scambi, esterni al proprio orario).</div>
    </div>
  </div>

</main>

<script>
// ── Tab sezioni ──────────────────────────────────────────────
document.querySelectorAll('.rep-tab').forEach(t => {
    t.addEventListener('click', () => {
        document.querySelectorAll('.rep-tab').forEach(x => x.classList.remove('attivo'));
        document.querySelectorAll('.rep-sezione').forEach(x => x.classList.remove('attiva'));
        t.classList.add('attivo');
        document.getElementById('sez-' + t.dataset.sez).classList.add('attiva');
    });
});

// ── Ordinamento client (stesso schema di Personale: data-sort numerico o testo) ──
document.querySelectorAll('table.rep').forEach(tab => {
    const tbody = tab.tBodies[0];
    const headers = tab.querySelectorAll('th.sortable');
    const chiave = (tr, col) => {
        const td = tr.children[col];
        if (!td) return '';
        return td.dataset.sort != null ? td.dataset.sort : td.textContent.trim();
    };
    const ordina = (col, asc) => {
        const righe = Array.from(tbody.querySelectorAll('tr')).filter(r => r.children.length > 1);
        righe.sort((a, b) => {
            const va = chiave(a, col), vb = chiave(b, col);
            const na = parseFloat(va), nb = parseFloat(vb);
            let cmp;
            if (!isNaN(na) && !isNaN(nb)) cmp = na - nb;
            else cmp = va.localeCompare(vb, 'it', { sensitivity: 'base' });
            return asc ? cmp : -cmp;
        });
        righe.forEach(r => tbody.appendChild(r));
    };
    headers.forEach(th => {
        th.addEventListener('click', () => {
            const col = parseInt(th.dataset.col, 10);
            const asc = th.getAttribute('aria-sort') !== 'ascending';
            headers.forEach(h => h.removeAttribute('aria-sort'));
            th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
            ordina(col, asc);
        });
    });
    // ordinamento iniziale dichiarato nel markup (aria-sort sul th)
    const init = tab.querySelector('th.sortable[aria-sort]');
    if (init) ordina(parseInt(init.dataset.col, 10), init.getAttribute('aria-sort') === 'ascending');
});
</script>

</body>
</html>
