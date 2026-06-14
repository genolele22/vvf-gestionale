<?php
/**
 * ANTEPRIMA DI STAMPA del Foglio di Servizio.
 * Read-only: NON pre-popola, NON modifica nulla. Mostra lo stato reale del foglio
 * (assegnazioni, salto con scambi, assenze) in un layout A4 stampabile.
 * Parametri: ?id=<foglio_id>  oppure  ?data=YYYY-MM-DD&tipo=D|N
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/turni.php';

$pdo = getDB();

// ── Risolve il foglio (per id o per data+tipo) ──────────────────────────────────
$foglio = null;
if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
    $st = $pdo->prepare("SELECT * FROM fogli_servizio WHERE id=?");
    $st->execute([(int)$_GET['id']]);
    $foglio = $st->fetch();
} else {
    $dataParam = $_GET['data'] ?? date('Y-m-d');
    $tipoParam = (($_GET['tipo'] ?? 'D') === 'N') ? 'N' : 'D';
    try { $dt = new DateTime($dataParam); } catch (Exception $e) { $dt = new DateTime('today'); }
    $st = $pdo->prepare("SELECT * FROM fogli_servizio WHERE data_servizio=? AND tipo_turno=?");
    $st->execute([$dt->format('Y-m-d'), $tipoParam]);
    $foglio = $st->fetch();
}

if (!$foglio) {
    http_response_code(404);
    exit('Foglio non trovato. Crealo o compilalo prima dalla pagina del servizio.');
}

$foglioId  = (int)$foglio['id'];
$dataStr   = $foglio['data_servizio'];
$tipoParam = $foglio['tipo_turno'] === 'N' ? 'N' : 'D';
$dt        = new DateTime($dataStr);
$dataLabel = $dt->format('d/m/Y');
$giorniIt  = ['Sunday'=>'Domenica','Monday'=>'Lunedì','Tuesday'=>'Martedì','Wednesday'=>'Mercoledì',
              'Thursday'=>'Giovedì','Friday'=>'Venerdì','Saturday'=>'Sabato'];
$giornoLbl = $giorniIt[$dt->format('l')] ?? '';
$oraLabel  = $tipoParam === 'D' ? '08:00 → 20:00' : '20:00 → 08:00';

$turnoGiorno = getTurnoGiorno($dataStr);
$turnoAttivo = $tipoParam === 'D' ? $turnoGiorno['diurno'] : $turnoGiorno['notte'];
$turnoRiposo = $tipoParam === 'D' ? $turnoGiorno['notte']  : $turnoGiorno['diurno'];
$codSaltoRip = 'B' . $turnoRiposo['salto'];

$saltoRiposoId = (int)($pdo->query(
    "SELECT id FROM salti_turno WHERE codice=" . $pdo->quote($codSaltoRip)
)->fetchColumn() ?: 0);

// ── Helpers ─────────────────────────────────────────────────────────────────────
$PATENTE_SUB = "(SELECT MAX(p.tipo) FROM vigili_patenti vp
                JOIN patenti p ON p.id = vp.patente_id
                WHERE vp.vigile_id = v.id)";

function etichettaVigile(array $v): string {
    return ucfirst(strtolower($v['qcodice'] ?? ''))
         . ' ' . ucfirst(strtolower($v['cognome'] ?? ''))
         . (!empty($v['disambiguatore']) ? ' ' . (int)$v['disambiguatore'] : '');
}
function colorePatente(?string $t): string {
    switch ($t) {
        case '4':
        case '3': return '#c0392b';
        case '2': return '#2471a3';
        default:  return '#2c3e50';
    }
}

// ── Riposanti effettivi (canonico ± scambi salto) ───────────────────────────────
$scambioIn = []; $scambioOut = [];
$st = $pdo->prepare("SELECT vigile_in_id, vigile_out_id FROM salto_override
                     WHERE data=? AND tipo=? AND attivo=1");
$st->execute([$dataStr, $tipoParam]);
foreach ($st->fetchAll() as $r) {
    $scambioIn[(int)$r['vigile_in_id']]   = true;
    $scambioOut[(int)$r['vigile_out_id']] = true;
}

$restersBase = [];
$st = $pdo->prepare("SELECT id FROM vigili WHERE attivo=1 AND salto_id=?");
$st->execute([$saltoRiposoId]);
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) $restersBase[(int)$vid] = true;
foreach ($scambioOut as $vid => $_) unset($restersBase[$vid]);
foreach ($scambioIn as $vid => $_) $restersBase[$vid] = true;

// ── Posizioni e sedi ────────────────────────────────────────────────────────────
$sedi = $pdo->query("SELECT * FROM sedi ORDER BY ordine")->fetchAll();
$posizioni = $pdo->query(
    "SELECT p.*, s.codice AS sede_codice FROM posizioni p
     JOIN sedi s ON s.id = p.sede_id ORDER BY p.sede_id, p.ordine"
)->fetchAll();
$posizioniPerSede = [];
foreach ($posizioni as $pos) $posizioniPerSede[$pos['sede_id']][] = $pos;

// ── Assegnazioni ────────────────────────────────────────────────────────────────
$st = $pdo->prepare(
    "SELECT a.*, v.cognome, v.nome, v.disambiguatore, q.codice AS qcodice,
            s.codice AS sede_codice, s.nome AS sede_nome, $PATENTE_SUB AS patente_max
     FROM assegnazioni a
     JOIN vigili v     ON v.id = a.vigile_id
     JOIN qualifiche q ON q.id = v.qualifica_id
     JOIN sedi s       ON s.id = v.sede_id
     WHERE a.foglio_id = ? ORDER BY a.posizione_id, a.ordine"
);
$st->execute([$foglioId]);
$assegnazioni = $st->fetchAll();
$assPerPosizione = [];
foreach ($assegnazioni as $a) $assPerPosizione[$a['posizione_id']][] = $a;
$vigiliAssegnati = array_map('intval', array_column($assegnazioni, 'vigile_id'));

// ── Assenze ─────────────────────────────────────────────────────────────────────
$st = $pdo->prepare(
    "SELECT a.*, v.cognome, v.nome, v.disambiguatore, q.codice AS qcodice,
            ta.nome AS tipo_nome, ta.codice AS tipo_codice,
            s.codice AS sede_codice, s.nome AS sede_nome, $PATENTE_SUB AS patente_max
     FROM assenze a
     JOIN vigili v        ON v.id = a.vigile_id
     JOIN qualifiche q    ON q.id = v.qualifica_id
     JOIN tipo_assenza ta ON ta.id = a.tipo_assenza_id
     JOIN sedi s          ON s.id = v.sede_id
     WHERE a.foglio_id = ? ORDER BY ta.id, v.cognome"
);
$st->execute([$foglioId]);
$assenze = $st->fetchAll();
$assenzePerTipo = [];
foreach ($assenze as $a) $assenzePerTipo[$a['tipo_codice']][] = $a;
$vigiliAssenti = array_map('intval', array_column($assenze, 'vigile_id'));
$setAssenti = array_flip($vigiliAssenti);
$setAssegnati = array_flip($vigiliAssegnati);

// ── Salto: riposanti effettivi + salto_servizio manuale, meno assenti ───────────
$st = $pdo->prepare(
    "SELECT ss.vigile_id, ss.richiamato FROM salto_servizio ss WHERE ss.foglio_id = ?"
);
$st->execute([$foglioId]);
$saltoManuale = [];
foreach ($st->fetchAll() as $r) $saltoManuale[(int)$r['vigile_id']] = (int)$r['richiamato'];

$inSaltoSet = []; $saltoRichiamato = [];
foreach (array_keys($restersBase) as $vid) {
    if (isset($setAssenti[$vid])) continue;
    $inSaltoSet[$vid] = true;
    $saltoRichiamato[$vid] = isset($setAssegnati[$vid]) ? 1 : 0;
}
foreach ($saltoManuale as $vid => $rich) {
    if (isset($setAssenti[$vid]) || isset($inSaltoSet[$vid])) continue;
    $inSaltoSet[$vid] = true;
    $saltoRichiamato[$vid] = $rich ? 1 : (isset($setAssegnati[$vid]) ? 1 : 0);
}
// Dati anagrafici dei vigili in salto
$vigiliInSalto = [];
if ($inSaltoSet) {
    $ph = implode(',', array_fill(0, count($inSaltoSet), '?'));
    $st = $pdo->prepare(
        "SELECT v.id, v.cognome, v.disambiguatore, q.codice AS qcodice,
                s.nome AS sede_nome, s.codice AS sede_codice, $PATENTE_SUB AS patente_max
         FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id JOIN sedi s ON s.id=v.sede_id
         WHERE v.id IN ($ph) ORDER BY v.cognome"
    );
    $st->execute(array_keys($inSaltoSet));
    $vigiliInSalto = $st->fetchAll();
}

// ── Capo servizio / Vice / Furieri ──────────────────────────────────────────────
function vigileById(PDO $pdo, $id) {
    if (!$id) return null;
    $st = $pdo->prepare(
        "SELECT v.cognome, v.disambiguatore, q.codice AS qcodice
         FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id WHERE v.id=?"
    );
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}
$capoServizio = vigileById($pdo, $foglio['capo_servizio_id'] ?? null);
$viceCapo     = vigileById($pdo, $foglio['vice_capo_id'] ?? null);

$st = $pdo->prepare(
    "SELECT v.cognome, v.disambiguatore, q.codice AS qcodice
     FROM foglio_furieri ff JOIN vigili v ON v.id=ff.vigile_id
     JOIN qualifiche q ON q.id=v.qualifica_id WHERE ff.foglio_id=? ORDER BY v.cognome"
);
$st->execute([$foglioId]);
$furieri = $st->fetchAll();

// Tipo testata posizione (colore)
function tipoHead(string $codice): string {
    $c = strtolower($codice);
    if (str_contains($c,'nbcr')) return 'tipo-nbcr';
    if (str_contains($c,'nau'))  return 'tipo-nau';
    if (str_contains($c,'smz'))  return 'tipo-smz';
    if (str_contains($c,'fun') || str_contains($c,'sop')) return 'tipo-fun';
    if (str_contains($c,'ap-'))  return 'tipo-ap';
    if (str_contains($c,'el-'))  return 'tipo-el';
    if (str_contains($c,'op'))   return 'tipo-op';
    if (str_contains($c,'b'))    return 'tipo-b';
    return 'tipo-a';
}

// Ordine sedi: Centrale → distaccamenti → Aeroporto
$cen = array_values(array_filter($sedi, fn($s) => $s['codice'] === 'CENTR'));
$ap  = array_values(array_filter($sedi, fn($s) => $s['codice'] === 'AP'));
$dis = array_values(array_filter($sedi, fn($s) => !in_array($s['codice'], ['CENTR','AP'])));
$sediOrdinati = array_merge($cen, $dis, $ap);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Stampa Foglio di Servizio — <?= htmlspecialchars($dataLabel) ?> <?= $tipoParam ?></title>
<style>
  * { box-sizing: border-box; }
  body { margin:0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
         color:#1a1a1a; background:#e9eaed; font-size:11px; }

  /* Toolbar (nascosta in stampa) */
  .toolbar { position:sticky; top:0; z-index:10; background:#1f2937; color:#fff;
             padding:10px 16px; display:flex; gap:10px; align-items:center; }
  .toolbar .sp { flex:1; }
  .toolbar button, .toolbar a { font:inherit; font-size:13px; border:none; border-radius:6px;
             padding:8px 14px; cursor:pointer; text-decoration:none; }
  .tb-print { background:#0a58ca; color:#fff; }
  .tb-close { background:#374151; color:#fff; }
  .toolbar .meta { font-size:13px; opacity:.85; }

  /* Pagina A4 */
  .sheet { width:210mm; min-height:297mm; margin:14px auto; background:#fff; padding:10mm 9mm;
           box-shadow:0 2px 12px rgba(0,0,0,.2); }

  /* Intestazione */
  .head { display:flex; justify-content:space-between; align-items:flex-start;
          border-bottom:2px solid #111; padding-bottom:6px; margin-bottom:8px; }
  .head h1 { font-size:15px; margin:0; }
  .head .sub { font-size:10px; color:#555; }
  .head .databox { text-align:right; }
  .head .databox .d { font-size:18px; font-weight:800; }
  .head .databox .t { font-size:11px; font-weight:700; }
  .head .databox .t.D { color:#b8860b; }
  .head .databox .t.N { color:#3b3b8f; }
  .head .salto { font-size:10px; color:#333; }
  .head .salto b { color:#c0392b; }

  .infoline { display:flex; flex-wrap:wrap; gap:4px 22px; font-size:10.5px;
              margin-bottom:8px; padding:5px 8px; background:#f5f6f8; border-radius:5px; }
  .infoline b { color:#000; }
  .infoline .note { flex-basis:100%; }

  /* Sezione titolo */
  .sec-title { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em;
               margin:10px 0 4px; padding:2px 6px; background:#111; color:#fff; border-radius:3px; }

  /* Griglia sedi */
  .sedi { display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px; }
  .sede.full { grid-column:1 / -1; }
  .sede { border:1px solid #cfd3da; border-radius:5px; overflow:hidden; break-inside:avoid; }
  .sede-h { background:#dde3ea; font-weight:700; font-size:10px; padding:3px 6px; }
  .sede.full .sede-h { background:#c3ccd6; }
  .sede-b { padding:4px; display:grid; gap:4px; }
  .sede.full .sede-b { grid-template-columns:repeat(4, 1fr); }

  .pos { border:1px solid #e0e3e8; border-radius:4px; break-inside:avoid; }
  .pos-h { font-size:8.5px; font-weight:700; color:#fff; padding:2px 5px; }
  .pos-b { padding:3px 5px; min-height:14px; }
  .pos-row { font-weight:600; line-height:1.45; }
  .dot { display:inline-block; width:6px; height:6px; border-radius:50%; background:#888;
         margin-right:3px; vertical-align:middle; }
  .badge { font-size:7.5px; font-weight:700; margin-left:3px; }
  .b-str { color:#b8860b; }
  .b-sc  { color:#0a58ca; }
  .sede-tag { font-size:8px; color:#666; font-weight:600; }

  /* testate colore */
  .tipo-a{background:#5b6b7a}.tipo-b{background:#41607a}.tipo-op{background:#2e7d52}
  .tipo-nbcr{background:#7a3b3b}.tipo-nau{background:#2c6e7a}.tipo-smz{background:#6a5b8a}
  .tipo-fun{background:#8a6a2c}.tipo-ap{background:#3a4a7a}.tipo-el{background:#7a5a2c}

  /* Assenti */
  .assenti { display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; margin-top:4px; }
  .acol { border:1px solid #cfd3da; border-radius:5px; break-inside:avoid; }
  .acol-h { font-size:9.5px; font-weight:700; padding:3px 6px; }
  .ac-salto{background:#fff3cd;color:#7d5a00}.ac-ferie{background:#d9f0e1;color:#1e6b3f}
  .ac-rc{background:#e2ecfb;color:#1c4f9c}.ac-miss{background:#f3e2d2;color:#8a5a2c}
  .acol-b { padding:3px 6px; }
  .arow { line-height:1.5; }
  .arow .info { color:#666; font-size:9px; margin-left:3px; }

  .firme { display:flex; justify-content:space-between; gap:18px; margin-top:18px; }
  .firma { flex:1; text-align:center; font-size:10px; color:#444; }
  .firma .line { border-top:1px solid #888; margin-top:26px; padding-top:3px; }

  @media print {
    body { background:#fff; font-size:10.5px; }
    .toolbar { display:none; }
    .sheet { width:auto; min-height:auto; margin:0; padding:0; box-shadow:none; }
    @page { size:A4 portrait; margin:9mm; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <strong>Anteprima di stampa</strong>
  <span class="meta">— <?= htmlspecialchars($giornoLbl) ?> <?= htmlspecialchars($dataLabel) ?>
        · <?= $tipoParam==='D'?'Diurno':'Notturno' ?> · salto <?= htmlspecialchars($codSaltoRip) ?></span>
  <span class="sp"></span>
  <button class="tb-print" onclick="window.print()">🖨️ Stampa</button>
  <a class="tb-close" href="nuovo.php?data=<?= urlencode($dataStr) ?>&tipo=<?= $tipoParam ?>">← Torna al foglio</a>
</div>

<div class="sheet">

  <div class="head">
    <div>
      <h1>Comando Provinciale VV.F. di Genova</h1>
      <div class="sub">Foglio di Servizio del Soccorso — Turno B</div>
    </div>
    <div class="databox">
      <div class="d"><?= htmlspecialchars($giornoLbl) ?> <?= htmlspecialchars($dataLabel) ?></div>
      <div class="t <?= $tipoParam ?>"><?= $tipoParam==='D'?'☀ Diurno':'🌙 Notturno' ?> <?= $oraLabel ?></div>
      <div class="salto">Salto a riposo: <b><?= htmlspecialchars($codSaltoRip) ?></b>
        &nbsp;·&nbsp; In servizio: <b><?= htmlspecialchars($turnoAttivo['turno'].$turnoAttivo['salto']) ?></b></div>
    </div>
  </div>

  <div class="infoline">
    <span><b>Capo Servizio:</b> <?= $capoServizio ? htmlspecialchars(etichettaVigile($capoServizio)) : '—' ?></span>
    <span><b>Vice Capo Servizio:</b> <?= $viceCapo ? htmlspecialchars(etichettaVigile($viceCapo)) : '—' ?></span>
    <span><b>Funzionario:</b> <?= htmlspecialchars($foglio['funzionario'] ?? '') ?: '—' ?></span>
    <span><b>Furieri:</b>
      <?php if ($furieri): ?>
        <?= htmlspecialchars(implode(', ', array_map('etichettaVigile', $furieri))) ?>
      <?php else: ?>—<?php endif; ?>
    </span>
    <?php if (!empty($foglio['note_generali'])): ?>
      <span class="note"><b>Note:</b> <?= htmlspecialchars($foglio['note_generali']) ?></span>
    <?php endif; ?>
  </div>

  <div class="sec-title">Personale in servizio</div>
  <div class="sedi">
    <?php foreach ($sediOrdinati as $sede):
      $posSede = $posizioniPerSede[$sede['id']] ?? [];
      if (!$posSede) continue;
      $full = in_array($sede['codice'], ['CENTR','AP']);
    ?>
    <div class="sede <?= $full ? 'full' : '' ?>">
      <div class="sede-h">🏠 <?= htmlspecialchars($sede['nome']) ?></div>
      <div class="sede-b">
        <?php foreach ($posSede as $pos):
          $assQui = $assPerPosizione[$pos['id']] ?? []; ?>
          <div class="pos">
            <div class="pos-h <?= tipoHead($pos['codice']) ?>"><?= htmlspecialchars($pos['codice']) ?></div>
            <div class="pos-b">
              <?php foreach ($assQui as $a):
                $vid = (int)$a['vigile_id'];
                $mostraSede = (!empty($a['sede_nome']) && $a['sede_nome'] !== 'CENTRALE'); ?>
                <div class="pos-row" style="color:<?= colorePatente($a['patente_max'] ?? null) ?>">
                  <span class="dot"></span><?= htmlspecialchars(etichettaVigile($a)) ?>
                  <?php if ($mostraSede): ?><span class="sede-tag"><?= htmlspecialchars($a['sede_codice']) ?></span><?php endif; ?>
                  <?php if (isset($scambioOut[$vid])): ?><span class="badge b-sc" title="Ha ceduto il salto">🔄</span><?php endif; ?>
                  <?php if (!empty($a['in_straordinario'])): ?><span class="badge b-str">STR</span><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="sec-title">Personale assente / a riposo</div>
  <div class="assenti">

    <div class="acol">
      <div class="acol-h ac-salto">😴 Salto <?= htmlspecialchars($codSaltoRip) ?></div>
      <div class="acol-b">
        <?php foreach ($vigiliInSalto as $v): $vid=(int)$v['id'];
          $str = !empty($saltoRichiamato[$vid]); ?>
          <div class="arow" style="color:<?= colorePatente($v['patente_max'] ?? null) ?>">
            <?= htmlspecialchars(etichettaVigile($v)) ?>
            <?php if (!empty($v['sede_nome']) && $v['sede_nome']!=='CENTRALE'): ?><span class="sede-tag"><?= htmlspecialchars($v['sede_codice']) ?></span><?php endif; ?>
            <?php if (isset($scambioIn[$vid])): ?><span class="badge b-sc" title="A riposo per scambio">🔄</span><?php endif; ?>
            <?php if ($str): ?><span class="badge b-str">★ STR</span><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$vigiliInSalto): ?><div class="arow" style="color:#aaa">—</div><?php endif; ?>
      </div>
    </div>

    <div class="acol">
      <div class="acol-h ac-ferie">🏖️ Ferie</div>
      <div class="acol-b">
        <?php $fer = $assenzePerTipo['FER'] ?? []; foreach ($fer as $a): ?>
          <div class="arow" style="color:<?= colorePatente($a['patente_max'] ?? null) ?>">
            <?= htmlspecialchars(etichettaVigile($a)) ?>
            <span class="info">
              <?= !empty($a['nr_turni']) ? (int)$a['nr_turni'].'T' : '' ?>
              <?= !empty($a['data_da']) ? date('d/m', strtotime($a['data_da'])) : '' ?><?= !empty($a['data_a']) ? '→'.date('d/m', strtotime($a['data_a'])) : '' ?>
            </span>
          </div>
        <?php endforeach; ?>
        <?php if (!$fer): ?><div class="arow" style="color:#aaa">—</div><?php endif; ?>
      </div>
    </div>

    <div class="acol">
      <div class="acol-h ac-rc">🔄 Riposo Compensativo</div>
      <div class="acol-b">
        <?php $rc = $assenzePerTipo['RC'] ?? []; foreach ($rc as $a): ?>
          <div class="arow"><?= htmlspecialchars(etichettaVigile($a)) ?>
            <?php if (!empty($a['sede_distaccata'])): ?><span class="info"><?= htmlspecialchars($a['sede_distaccata']) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$rc): ?><div class="arow" style="color:#aaa">—</div><?php endif; ?>
      </div>
    </div>

    <div class="acol">
      <div class="acol-h ac-miss">✈️ Missione / Permesso</div>
      <div class="acol-b">
        <?php $mp = array_merge($assenzePerTipo['MISS'] ?? [], $assenzePerTipo['PERM'] ?? []); foreach ($mp as $a): ?>
          <div class="arow"><?= htmlspecialchars(etichettaVigile($a)) ?>
            <span class="info"><?= htmlspecialchars($a['tipo_codice']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$mp): ?><div class="arow" style="color:#aaa">—</div><?php endif; ?>
      </div>
    </div>

  </div>

  <div class="firme">
    <div class="firma"><div class="line">Il Capo Servizio</div></div>
    <div class="firma"><div class="line">Il Funzionario di Guardia</div></div>
  </div>

</div>
</body>
</html>
</content>
