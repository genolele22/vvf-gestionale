<?php
/**
 * ANTEPRIMA DI STAMPA del Foglio di Servizio — replica del modulo ODT.
 * Read-only: NON pre-popola, NON modifica nulla. Riproduce il layout del modulo
 * ufficiale (template B1-B8.odt) riempito con i dati VERI del foglio dal DB.
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
        case '3': return '#c00000';
        case '2': return '#0000c0';
        default:  return '#000';
    }
}

// ── Scambi salto attivi (badge) ─────────────────────────────────────────────────
$scambioIn = []; $scambioOut = [];
$st = $pdo->prepare("SELECT vigile_in_id, vigile_out_id FROM salto_override
                     WHERE data=? AND tipo=? AND attivo=1");
$st->execute([$dataStr, $tipoParam]);
foreach ($st->fetchAll() as $r) {
    $scambioIn[(int)$r['vigile_in_id']]   = true;
    $scambioOut[(int)$r['vigile_out_id']] = true;
}

// ── Posizioni: mappa codice → assegnati ─────────────────────────────────────────
$posizioni = $pdo->query(
    "SELECT p.id, p.codice, p.sede_id, s.codice AS sede_codice, s.nome AS sede_nome
     FROM posizioni p JOIN sedi s ON s.id=p.sede_id ORDER BY p.sede_id, p.ordine"
)->fetchAll();
$posIdByCode = [];
foreach ($posizioni as $p) $posIdByCode[$p['codice']] = (int)$p['id'];

$st = $pdo->prepare(
    "SELECT a.*, v.cognome, v.disambiguatore, q.codice AS qcodice,
            s.codice AS sede_codice, s.nome AS sede_nome, $PATENTE_SUB AS patente_max
     FROM assegnazioni a
     JOIN vigili v     ON v.id = a.vigile_id
     JOIN qualifiche q ON q.id = v.qualifica_id
     JOIN sedi s       ON s.id = v.sede_id
     WHERE a.foglio_id = ? ORDER BY a.posizione_id, a.ordine"
);
$st->execute([$foglioId]);
$assPerPosId = [];
foreach ($st->fetchAll() as $a) $assPerPosId[(int)$a['posizione_id']][] = $a;

function assegnatiDi(string $code): array {
    global $posIdByCode, $assPerPosId;
    $id = $posIdByCode[$code] ?? 0;
    return $assPerPosId[$id] ?? [];
}

// ── Assenze per tipo ────────────────────────────────────────────────────────────
$st = $pdo->prepare(
    "SELECT a.*, v.cognome, v.disambiguatore, q.codice AS qcodice,
            ta.codice AS tipo_codice, s.nome AS sede_nome, $PATENTE_SUB AS patente_max
     FROM assenze a
     JOIN vigili v        ON v.id = a.vigile_id
     JOIN qualifiche q    ON q.id = v.qualifica_id
     JOIN tipo_assenza ta ON ta.id = a.tipo_assenza_id
     JOIN sedi s          ON s.id = v.sede_id
     WHERE a.foglio_id = ? ORDER BY ta.id, v.cognome"
);
$st->execute([$foglioId]);
$perTipo = [];
foreach ($st->fetchAll() as $a) $perTipo[$a['tipo_codice']][] = $a;

// ── Capo servizio / Vice / Furieri ──────────────────────────────────────────────
function vigileById(PDO $pdo, $id) {
    if (!$id) return null;
    $st = $pdo->prepare("SELECT v.cognome, v.disambiguatore, q.codice AS qcodice
                         FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id WHERE v.id=?");
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}
$capoServizio = vigileById($pdo, $foglio['capo_servizio_id'] ?? null);
$viceCapo     = vigileById($pdo, $foglio['vice_capo_id'] ?? null);
$st = $pdo->prepare("SELECT v.cognome, v.disambiguatore, q.codice AS qcodice
                     FROM foglio_furieri ff JOIN vigili v ON v.id=ff.vigile_id
                     JOIN qualifiche q ON q.id=v.qualifica_id WHERE ff.foglio_id=? ORDER BY v.cognome");
$st->execute([$foglioId]);
$furieri = $st->fetchAll();

// ── Layout modulo ───────────────────────────────────────────────────────────────
// Etichette come sul modulo ODT
$LABEL = [
  'CENTR-OP'        => 'Centrale operativa',
  '3A'              => '3A/Squadretta',
  '2B-NBCR'         => '2B/NBCR',
  '1FUN-AUTORADIO'  => '1FUN/Autoradio',
  '1SOP-AUTORIM'    => '1SOP/Autorimessa',
  'GA-1NAU'         => 'NAUTICA',
  'AP-TEL'          => 'Telefonista',
];
function labelPos(string $code): string {
    global $LABEL;
    return $LABEL[$code] ?? $code;
}

// Centrale: griglia 5 colonne × 3 bande (come il modulo)
$centrale = [
  ['CENTR-OP','1A','2A','3A','4A'],
  ['1SMZ','1B','2B-NBCR','1FUN-AUTORADIO','5A'],
  ['','3B','4B','1SOP-AUTORIM',''],
];
// Distaccamenti: box per sede (codice sede → posizioni)
$distacc = [
  'MULTEDO'    => ['ML-1A','ML-1NAU'],
  'NAUTICA'    => ['GA-1NAU'],
  'GENOVA EST' => ['GE-1A'],
  'BOLZANETO'  => ['BL-1A'],
  'BUSALLA'    => ['BS-1A'],
  'RAPALLO'    => ['RP-1A'],
  'CHIAVARI'   => ['CH-1A','CH-1B'],
];
// Aeroporto + Reparto Volo
$aeroporto = ['AP-TEL','AP-1ROS','AP-1ASA','AP-1VI','AP-2VI'];
$repVolo   = ['EL-1SMZ'];

// Renderizza i nomi di una posizione
function renderNomi(string $code): string {
    global $scambioOut;
    $out = '';
    foreach (assegnatiDi($code) as $a) {
        $vid = (int)$a['vigile_id'];
        $col = colorePatente($a['patente_max'] ?? null);
        $out .= '<div class="nome" style="color:' . $col . '">' . htmlspecialchars(etichettaVigile($a));
        if (isset($scambioOut[$vid])) $out .= '<span class="b-sc" title="Ha ceduto il salto">🔄</span>';
        if (!empty($a['in_straordinario'])) $out .= '<span class="b-str">STR</span>';
        $out .= '</div>';
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Foglio di Servizio — <?= htmlspecialchars($dataLabel) ?> <?= $tipoParam ?></title>
<style>
  * { box-sizing:border-box; }
  body { margin:0; font-family:"Times New Roman", Georgia, serif; color:#000;
         background:#e9eaed; font-size:10px; }

  .toolbar { position:sticky; top:0; z-index:10; background:#1f2937; color:#fff;
             padding:10px 16px; display:flex; gap:10px; align-items:center;
             font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
  .toolbar .sp { flex:1; }
  .toolbar button, .toolbar a { font:inherit; font-size:13px; border:none; border-radius:6px;
             padding:8px 14px; cursor:pointer; text-decoration:none; }
  .tb-print { background:#0a58ca; color:#fff; }
  .tb-close { background:#374151; color:#fff; }
  .toolbar .meta { font-size:13px; opacity:.85; }

  .sheet { width:210mm; min-height:297mm; margin:14px auto; background:#fff;
           padding:8mm 7mm; box-shadow:0 2px 12px rgba(0,0,0,.2); }

  /* Intestazione ufficiale */
  .intest { text-align:center; line-height:1.2; }
  .intest .dip { font-size:9px; text-transform:uppercase; }
  .intest .com { font-size:11px; font-weight:700; text-transform:uppercase; margin-top:2px; }
  .intest .fog { font-size:13px; font-weight:700; text-transform:uppercase; margin-top:3px;
                 border-top:1.5px solid #000; border-bottom:1.5px solid #000; padding:3px 0; }
  .intest .data { font-size:11px; font-weight:700; margin-top:3px; }

  table.mod { width:100%; border-collapse:collapse; table-layout:fixed; margin-top:5px; }
  table.mod td, table.mod th { border:1px solid #000; padding:1px 3px; vertical-align:top;
                               overflow:hidden; }
  .hdr { background:#d9d9d9; font-weight:700; text-align:center; font-size:9px; }
  .hdrline td { font-weight:700; background:#eee; }

  .band-title { background:#000; color:#fff; font-weight:700; text-transform:uppercase;
                font-size:10px; padding:2px 5px; text-align:center; }

  .pos-h { background:#d9d9d9; font-weight:700; text-align:center; font-size:8.5px;
           border:1px solid #000; padding:1px 2px; }
  .pos-b { border:1px solid #000; border-top:none; padding:2px; min-height:30px; }
  .nome { font-size:9.5px; font-weight:600; line-height:1.45; white-space:nowrap;
          overflow:hidden; text-overflow:ellipsis; }
  .b-str { font-size:7px; color:#b8860b; font-weight:700; margin-left:3px; }
  .b-sc  { font-size:8px; margin-left:2px; }

  .grid5 { display:grid; grid-template-columns:repeat(5,1fr); gap:0; }
  .grid5 > div { border:1px solid #000; margin:-0.5px 0 0 -0.5px; }
  .cell { padding:0; }
  .cell .pos-h { border:none; border-bottom:1px solid #000; }
  .cell .pos-b { border:none; }
  .cell.empty { background:#f3f3f3; }

  .dist-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:0; }
  .dist-box { border:1px solid #000; margin:-0.5px 0 0 -0.5px; }
  .dist-h { background:#c9c9c9; font-weight:700; text-align:center; font-size:9px;
            text-transform:uppercase; border-bottom:1px solid #000; padding:1px; }

  .assenti-wrap { display:grid; grid-template-columns:1fr 1fr; gap:6px; margin-top:6px; }
  table.sec { width:100%; border-collapse:collapse; table-layout:fixed; }
  table.sec td, table.sec th { border:1px solid #000; padding:1px 4px; font-size:9.5px; }
  table.sec th { background:#000; color:#fff; font-size:9.5px; text-transform:uppercase; }
  table.sec .sub th { background:#d9d9d9; color:#000; font-weight:700; }
  .firme { display:flex; justify-content:space-around; gap:18px; margin-top:22px; }
  .firma { flex:1; text-align:center; font-size:10px; }
  .firma .line { border-top:1px solid #000; margin-top:28px; padding-top:2px; }

  @media print {
    body { background:#fff; }
    .toolbar { display:none; }
    .sheet { width:auto; min-height:auto; margin:0; padding:0; box-shadow:none; }
    @page { size:A4 portrait; margin:8mm; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <strong>Anteprima di stampa</strong>
  <span class="meta">— <?= htmlspecialchars($giornoLbl.' '.$dataLabel) ?> ·
        <?= $tipoParam==='D'?'Diurno':'Notturno' ?> · salto <?= htmlspecialchars($codSaltoRip) ?></span>
  <span class="sp"></span>
  <button class="tb-print" onclick="window.print()">🖨️ Stampa</button>
  <a class="tb-close" href="nuovo.php?data=<?= urlencode($dataStr) ?>&tipo=<?= $tipoParam ?>">← Torna al foglio</a>
</div>

<div class="sheet">

  <!-- INTESTAZIONE -->
  <div class="intest">
    <div class="dip">Dipartimento dei Vigili del Fuoco del Soccorso Pubblico e della Difesa Civile</div>
    <div class="com">Comando Provinciale Vigili del Fuoco di Genova</div>
    <div class="fog">Foglio di Servizio &nbsp;—&nbsp; <?= htmlspecialchars($giornoLbl.' '.$dataLabel) ?>
        &nbsp;—&nbsp; <?= $tipoParam==='D'?'Diurno ☀':'Notturno 🌙' ?> <?= $oraLabel ?></div>
    <div class="data">Salto a riposo: <?= htmlspecialchars($codSaltoRip) ?>
        &nbsp;·&nbsp; In servizio: <?= htmlspecialchars($turnoAttivo['turno'].$turnoAttivo['salto']) ?></div>
  </div>

  <table class="mod">
    <tr class="hdrline">
      <td style="width:55%">Furieri:
        <?= $furieri ? htmlspecialchars(implode(', ', array_map('etichettaVigile',$furieri))) : '—' ?>
      </td>
      <td style="width:45%; text-align:right">Anno <?= htmlspecialchars($dt->format('Y')) ?> &nbsp; Turno B</td>
    </tr>
    <tr>
      <td>Capo Servizio: <b><?= $capoServizio ? htmlspecialchars(etichettaVigile($capoServizio)) : '—' ?></b>
          &nbsp;&nbsp; Vice: <b><?= $viceCapo ? htmlspecialchars(etichettaVigile($viceCapo)) : '—' ?></b></td>
      <td style="text-align:right">Funzionario: <b><?= htmlspecialchars($foglio['funzionario'] ?? '') ?: '—' ?></b></td>
    </tr>
    <?php if (!empty($foglio['note_generali'])): ?>
    <tr><td colspan="2">Note: <?= htmlspecialchars($foglio['note_generali']) ?></td></tr>
    <?php endif; ?>
  </table>

  <!-- SEDE CENTRALE -->
  <div class="band-title" style="margin-top:5px">Sede Centrale</div>
  <div class="grid5">
    <?php foreach ($centrale as $banda): foreach ($banda as $code):
      if ($code === ''): ?>
        <div class="cell empty"></div>
      <?php else: ?>
        <div class="cell">
          <div class="pos-h"><?= htmlspecialchars(labelPos($code)) ?></div>
          <div class="pos-b"><?= renderNomi($code) ?></div>
        </div>
      <?php endif;
    endforeach; endforeach; ?>
  </div>

  <!-- DISTACCAMENTI -->
  <div class="band-title" style="margin-top:6px">Distaccamenti</div>
  <div class="dist-grid">
    <?php foreach ($distacc as $nome => $codes): ?>
      <div class="dist-box">
        <div class="dist-h"><?= htmlspecialchars($nome) ?></div>
        <?php foreach ($codes as $code): ?>
          <div class="pos-h" style="background:#e6e6e6"><?= htmlspecialchars(labelPos($code)) ?></div>
          <div class="pos-b"><?= renderNomi($code) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- AEROPORTO + REPARTO VOLO -->
  <div class="band-title" style="margin-top:6px">Aeroporto &amp; Reparto Volo</div>
  <div class="grid5">
    <?php foreach ($aeroporto as $code): ?>
      <div class="cell">
        <div class="pos-h"><?= htmlspecialchars(labelPos($code)) ?></div>
        <div class="pos-b"><?= renderNomi($code) ?></div>
      </div>
    <?php endforeach; ?>
    <?php foreach ($repVolo as $code): ?>
      <div class="cell">
        <div class="pos-h">Reparto Volo — <?= htmlspecialchars(labelPos($code)) ?></div>
        <div class="pos-b"><?= renderNomi($code) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- PERSONALE ASSENTE -->
  <div class="band-title" style="margin-top:7px">Personale Assente</div>
  <div class="assenti-wrap">

    <!-- FERIE -->
    <table class="sec">
      <tr><th colspan="4">Ferie</th></tr>
      <tr class="sub"><th>Cognome</th><th style="width:12%">Turni</th><th style="width:16%">Da</th><th style="width:16%">A</th></tr>
      <?php $fer = $perTipo['FER'] ?? []; foreach ($fer as $a): ?>
        <tr>
          <td style="color:<?= colorePatente($a['patente_max'] ?? null) ?>">
            <?= htmlspecialchars(etichettaVigile($a)) ?>
            <?php if (!empty($a['sede_distaccata'])): ?>(<?= htmlspecialchars($a['sede_distaccata']) ?>)<?php endif; ?>
          </td>
          <td style="text-align:center"><?= !empty($a['nr_turni']) ? (int)$a['nr_turni'] : '' ?></td>
          <td><?= !empty($a['data_da']) ? date('d/m', strtotime($a['data_da'])) : '' ?></td>
          <td><?= !empty($a['data_a']) ? date('d/m', strtotime($a['data_a'])) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php for ($i=count($fer); $i<6; $i++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td></tr><?php endfor; ?>
    </table>

    <!-- RIPOSO COMPENSATIVO -->
    <table class="sec">
      <tr><th colspan="2">Riposo Compensativo</th></tr>
      <tr class="sub"><th>Cognome</th><th style="width:40%">Variazioni</th></tr>
      <?php $rc = $perTipo['RC'] ?? []; foreach ($rc as $a): ?>
        <tr>
          <td><?= htmlspecialchars(etichettaVigile($a)) ?></td>
          <td><?= htmlspecialchars($a['sede_distaccata'] ?? '') ?: htmlspecialchars($a['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php for ($i=count($rc); $i<6; $i++): ?><tr><td>&nbsp;</td><td></td></tr><?php endfor; ?>
    </table>
  </div>

  <!-- MISSIONE / PERMESSO -->
  <table class="sec" style="margin-top:6px">
    <tr><th>Missione o Permesso</th></tr>
    <?php $mp = array_merge($perTipo['MISS'] ?? [], $perTipo['PERM'] ?? []);
    foreach ($mp as $a): ?>
      <tr><td><?= htmlspecialchars(etichettaVigile($a)) ?>
        — <?= htmlspecialchars($a['tipo_codice']==='MISS'?'Missione':'Permesso') ?>
        <?= !empty($a['note']) ? '· '.htmlspecialchars($a['note']) : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$mp): ?><tr><td>&nbsp;</td></tr><?php endif; ?>
  </table>

  <!-- MALATTIA / INFORTUNIO -->
  <table class="sec" style="margin-top:4px">
    <tr><th>Malattia o Infortunio</th></tr>
    <?php $mi = array_merge($perTipo['MAL'] ?? [], $perTipo['INF'] ?? []);
    foreach ($mi as $a): ?>
      <tr><td><?= htmlspecialchars(etichettaVigile($a)) ?>
        — <?= htmlspecialchars($a['tipo_codice']==='MAL'?'Malattia':'Infortunio') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$mi): ?><tr><td>&nbsp;</td></tr><?php endif; ?>
  </table>

  <div class="firme">
    <div class="firma"><div class="line">Il Capo Servizio</div></div>
    <div class="firma"><div class="line">Il Funzionario di Guardia</div></div>
  </div>

</div>
</body>
</html>
</content>
