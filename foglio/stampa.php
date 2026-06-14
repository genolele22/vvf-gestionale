<?php
/**
 * ANTEPRIMA DI STAMPA del Foglio di Servizio — replica fedele del modulo ODT (B1-B8).
 * Read-only: NON modifica nulla. Riproduce la griglia ufficiale (5 colonne, ogni
 * colonna = pila di mezzi con intestazione grigia + nomi) riempita coi dati del DB.
 * Parametri: ?id=<foglio_id>  oppure  ?data=YYYY-MM-DD&tipo=D|N
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/turni.php';

$pdo = getDB();

// ── Risolve il foglio ───────────────────────────────────────────────────────────
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
if (!$foglio) { http_response_code(404); exit('Foglio non trovato. Compilalo prima dalla pagina del servizio.'); }

$foglioId  = (int)$foglio['id'];
$dataStr   = $foglio['data_servizio'];
$tipoParam = $foglio['tipo_turno'] === 'N' ? 'N' : 'D';
$dt        = new DateTime($dataStr);
$dataLabel = $dt->format('d/m/Y');
$giorniIt  = ['Sunday'=>'Domenica','Monday'=>'Lunedì','Tuesday'=>'Martedì','Wednesday'=>'Mercoledì',
              'Thursday'=>'Giovedì','Friday'=>'Venerdì','Saturday'=>'Sabato'];
$giornoLbl = $giorniIt[$dt->format('l')] ?? '';
$oraLabel  = $tipoParam === 'D' ? '08:00 / 20:00' : '20:00 / 08:00';

$turnoGiorno = getTurnoGiorno($dataStr);
$turnoAttivo = $tipoParam === 'D' ? $turnoGiorno['diurno'] : $turnoGiorno['notte'];
$turnoRiposo = $tipoParam === 'D' ? $turnoGiorno['notte']  : $turnoGiorno['diurno'];
$codSaltoRip = 'B' . $turnoRiposo['salto'];

// ── Helpers ─────────────────────────────────────────────────────────────────────
$PATENTE_SUB = "(SELECT MAX(p.tipo) FROM vigili_patenti vp
                JOIN patenti p ON p.id = vp.patente_id WHERE vp.vigile_id = v.id)";
function etichettaVigile(array $v): string {
    return ucfirst(strtolower($v['qcodice'] ?? ''))
         . ' ' . ucfirst(strtolower($v['cognome'] ?? ''))
         . (!empty($v['disambiguatore']) ? ' ' . (int)$v['disambiguatore'] : '');
}
function colorePatente(?string $t): string {
    switch ($t) { case '4': case '3': return '#c00000'; case '2': return '#0000c0'; default: return '#000'; }
}

// ── Scambi salto (badge) ────────────────────────────────────────────────────────
$scambioIn = []; $scambioOut = [];
$st = $pdo->prepare("SELECT vigile_in_id, vigile_out_id FROM salto_override WHERE data=? AND tipo=? AND attivo=1");
$st->execute([$dataStr, $tipoParam]);
foreach ($st->fetchAll() as $r) { $scambioIn[(int)$r['vigile_in_id']]=true; $scambioOut[(int)$r['vigile_out_id']]=true; }

// ── Posizioni: codice → assegnati ───────────────────────────────────────────────
$posIdByCode = [];
foreach ($pdo->query("SELECT id, codice FROM posizioni")->fetchAll() as $p) $posIdByCode[$p['codice']] = (int)$p['id'];
$st = $pdo->prepare(
    "SELECT a.*, v.cognome, v.disambiguatore, q.codice AS qcodice, $PATENTE_SUB AS patente_max
     FROM assegnazioni a JOIN vigili v ON v.id=a.vigile_id JOIN qualifiche q ON q.id=v.qualifica_id
     WHERE a.foglio_id=? ORDER BY a.posizione_id, a.ordine");
$st->execute([$foglioId]);
$assByPos = [];
foreach ($st->fetchAll() as $a) $assByPos[(int)$a['posizione_id']][] = $a;
function assegnatiDi(string $code): array {
    global $posIdByCode, $assByPos;
    return $assByPos[$posIdByCode[$code] ?? 0] ?? [];
}

// ── Assenze per tipo ────────────────────────────────────────────────────────────
$st = $pdo->prepare(
    "SELECT a.*, v.cognome, v.disambiguatore, q.codice AS qcodice, ta.codice AS tipo_codice, $PATENTE_SUB AS patente_max
     FROM assenze a JOIN vigili v ON v.id=a.vigile_id JOIN qualifiche q ON q.id=v.qualifica_id
     JOIN tipo_assenza ta ON ta.id=a.tipo_assenza_id WHERE a.foglio_id=? ORDER BY ta.id, v.cognome");
$st->execute([$foglioId]);
$perTipo = [];
foreach ($st->fetchAll() as $a) $perTipo[$a['tipo_codice']][] = $a;

// ── Capo / Vice / Furieri ───────────────────────────────────────────────────────
function vigileById(PDO $pdo, $id) {
    if (!$id) return null;
    $st=$pdo->prepare("SELECT v.cognome,v.disambiguatore,q.codice AS qcodice FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id WHERE v.id=?");
    $st->execute([(int)$id]); return $st->fetch() ?: null;
}
$capoServizio = vigileById($pdo, $foglio['capo_servizio_id'] ?? null);
$viceCapo     = vigileById($pdo, $foglio['vice_capo_id'] ?? null);
$st=$pdo->prepare("SELECT v.cognome,v.disambiguatore,q.codice AS qcodice FROM foglio_furieri ff JOIN vigili v ON v.id=ff.vigile_id JOIN qualifiche q ON q.id=v.qualifica_id WHERE ff.foglio_id=? ORDER BY v.cognome");
$st->execute([$foglioId]); $furieri=$st->fetchAll();

// ── Layout modulo ───────────────────────────────────────────────────────────────
$LABEL = [
  'CENTR-OP'=>'Centrale operativa','3A'=>'3A/Squadretta','2B-NBCR'=>'2B/NBCR',
  '1FUN-AUTORADIO'=>'1FUN/Autoradio','1SOP-AUTORIM'=>'1SOP/Autorimessa',
  'GA-1NAU'=>'GA-1NAU','AP-TEL'=>'Telefonista',
];
$SEDE = [
  'ML-1A'=>'Multedo','GA-1NAU'=>'Nautica (Gadda)','ML-1NAU'=>'Multedo Nautica',
  'GE-1A'=>'Genova Est','BL-1A'=>'Bolzaneto','BS-1A'=>'Busalla','RP-1A'=>'Rapallo',
  'CH-1A'=>'Chiavari','CH-1B'=>'Chiavari','EL-1SMZ'=>'Reparto Volo',
  'AP-TEL'=>'Aeroporto','AP-1ROS'=>'Aeroporto','AP-1ASA'=>'Aeroporto','AP-1VI'=>'Aeroporto','AP-2VI'=>'Aeroporto',
];
function lab(string $c): string { global $LABEL; return $LABEL[$c] ?? $c; }

// Le 5 colonne (come il modulo): ogni colonna è una pila di mezzi
$CENTRALE = [
  ['CENTR-OP','1SMZ'],
  ['1A','1B','3B'],
  ['2A','2B-NBCR','4B'],
  ['3A','1FUN-AUTORADIO','1SOP-AUTORIM'],
  ['4A','5A'],
];
$DISTACC = [
  ['ML-1A','GA-1NAU','ML-1NAU'],
  ['GE-1A','BL-1A'],
  ['BS-1A','RP-1A'],
  ['CH-1A','CH-1B','EL-1SMZ'],
  ['AP-TEL','AP-1ROS','AP-1ASA','AP-1VI','AP-2VI'],
];

// Render di un mezzo (header grigio + nomi)
function renderMezzo(string $code, bool $conSede=false): string {
    global $scambioOut, $SEDE;
    $h = '';
    if ($conSede && isset($SEDE[$code])) $h .= '<div class="sede-cap">'.htmlspecialchars($SEDE[$code]).'</div>';
    $h .= '<div class="mz-h">'.htmlspecialchars(lab($code)).'</div><div class="mz-b">';
    $nomi = assegnatiDi($code);
    foreach ($nomi as $a) {
        $vid=(int)$a['vigile_id'];
        $h .= '<div class="nm" style="color:'.colorePatente($a['patente_max'] ?? null).'">'.htmlspecialchars(etichettaVigile($a));
        if (isset($scambioOut[$vid])) $h .= '<span class="sc">🔄</span>';
        if (!empty($a['in_straordinario'])) $h .= '<span class="str">STR</span>';
        $h .= '</div>';
    }
    if (!$nomi) $h .= '<div class="nm vuoto">&nbsp;</div>';
    $h .= '</div>';
    return $h;
}
function renderColonna(array $codes, bool $conSede=false): string {
    $h='';
    foreach ($codes as $c) $h .= '<div class="mezzo">'.renderMezzo($c,$conSede).'</div>';
    return $h;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Foglio di Servizio — <?= htmlspecialchars($dataLabel) ?> <?= $tipoParam ?></title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:Arial,"Liberation Sans",Helvetica,sans-serif;color:#000;background:#e9eaed;font-size:9.5px}
  .toolbar{position:sticky;top:0;z-index:10;background:#1f2937;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center}
  .toolbar .sp{flex:1}
  .toolbar button,.toolbar a{font:inherit;font-size:13px;border:none;border-radius:6px;padding:8px 14px;cursor:pointer;text-decoration:none}
  .tb-print{background:#0a58ca;color:#fff}.tb-close{background:#374151;color:#fff}
  .toolbar .meta{font-size:13px;opacity:.85}

  .sheet{width:210mm;min-height:297mm;margin:14px auto;background:#fff;padding:7mm 6mm;box-shadow:0 2px 12px rgba(0,0,0,.2)}

  /* Intestazione */
  .intest{text-align:center;line-height:1.18}
  .intest .dip{font-size:8px;text-transform:uppercase;letter-spacing:.02em}
  .intest .com{font-size:10.5px;font-weight:700;text-transform:uppercase}
  .intest .fog{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;margin:2px 0}

  table.top{width:100%;border-collapse:collapse;margin-top:4px;font-size:9.5px}
  table.top td{border:1px solid #000;padding:2px 5px}
  table.top .lbl{background:#d9d9d9;font-weight:700;white-space:nowrap;width:1%}

  .bar{background:#000;color:#fff;font-weight:700;text-transform:uppercase;text-align:center;
       font-size:10px;letter-spacing:.05em;padding:2px;margin:5px 0 0}

  /* Griglia 5 colonne */
  .grid5{display:grid;grid-template-columns:repeat(5,1fr);border-left:1px solid #000;border-top:1px solid #000}
  .col5{border-right:1px solid #000}
  .mezzo{border-bottom:1px solid #000}
  .sede-cap{background:#bfbfbf;font-weight:700;text-transform:uppercase;font-size:8px;text-align:center;
            padding:1px;border-bottom:1px solid #000}
  .mz-h{background:#d9d9d9;font-weight:700;text-align:center;font-size:8.5px;padding:1px 2px;border-bottom:1px solid #000}
  .mz-b{padding:1px 3px;min-height:13px}
  .nm{font-size:9.5px;font-weight:600;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .nm.vuoto{min-height:11px}
  .str{font-size:6.5px;color:#b8860b;font-weight:700;margin-left:3px}
  .sc{font-size:8px;margin-left:2px}

  /* Personale assente */
  .assenti{display:grid;grid-template-columns:46% 54%;gap:0;border:1px solid #000;border-top:none}
  table.sec{width:100%;border-collapse:collapse}
  table.sec td,table.sec th{border:1px solid #000;padding:1px 4px;font-size:9.5px;vertical-align:top}
  table.sec thead th{background:#d9d9d9;font-size:8.5px;text-transform:uppercase}
  .sez-h{background:#404040;color:#fff;font-weight:700;text-transform:uppercase;font-size:9px;
         text-align:center;padding:2px;border:1px solid #000;border-top:none}
  table.full{width:100%;border-collapse:collapse;border:1px solid #000;border-top:none}
  table.full td{border:1px solid #000;padding:1px 5px;font-size:9.5px}

  .firme{display:flex;justify-content:space-around;gap:20px;margin-top:16px}
  .firma{flex:1;text-align:center;font-size:9.5px}
  .firma .line{border-top:1px solid #000;margin-top:26px;padding-top:2px}

  @media print{
    body{background:#fff}.toolbar{display:none}
    .sheet{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none}
    @page{size:A4 portrait;margin:7mm}
  }
</style>
</head>
<body>

<div class="toolbar">
  <strong>Anteprima di stampa</strong>
  <span class="meta">— <?= htmlspecialchars($giornoLbl.' '.$dataLabel) ?> · <?= $tipoParam==='D'?'Diurno':'Notturno' ?> · salto <?= htmlspecialchars($codSaltoRip) ?></span>
  <span class="sp"></span>
  <button class="tb-print" onclick="window.print()">🖨️ Stampa</button>
  <a class="tb-close" href="nuovo.php?data=<?= urlencode($dataStr) ?>&tipo=<?= $tipoParam ?>">← Torna al foglio</a>
</div>

<div class="sheet">

  <div class="intest">
    <div class="dip">Dipartimento dei Vigili del Fuoco del Soccorso Pubblico e della Difesa Civile</div>
    <div class="com">Comando Provinciale Vigili del Fuoco di Genova</div>
    <div class="fog">Foglio di Servizio</div>
  </div>

  <table class="top">
    <tr>
      <td class="lbl">Furieri</td>
      <td><?= $furieri ? htmlspecialchars(implode(', ', array_map('etichettaVigile',$furieri))) : '&nbsp;' ?></td>
      <td class="lbl">Data</td>
      <td><?= htmlspecialchars($giornoLbl.' '.$dataLabel) ?></td>
      <td class="lbl">Turno</td>
      <td>B<?= $tipoParam==='D'?' · Diurno':' · Notturno' ?> (<?= $oraLabel ?>)</td>
    </tr>
    <tr>
      <td class="lbl">Capo Servizio</td>
      <td><?= $capoServizio ? htmlspecialchars(etichettaVigile($capoServizio)) : '&nbsp;' ?></td>
      <td class="lbl">Vice Capo Servizio</td>
      <td><?= $viceCapo ? htmlspecialchars(etichettaVigile($viceCapo)) : '&nbsp;' ?></td>
      <td class="lbl">Funzionario</td>
      <td><?= htmlspecialchars($foglio['funzionario'] ?? '') ?: '&nbsp;' ?></td>
    </tr>
    <?php if (!empty($foglio['note_generali'])): ?>
    <tr><td class="lbl">Note</td><td colspan="5"><?= htmlspecialchars($foglio['note_generali']) ?></td></tr>
    <?php endif; ?>
  </table>

  <!-- SEDE CENTRALE -->
  <div class="bar">Sede Centrale</div>
  <div class="grid5">
    <?php foreach ($CENTRALE as $colonna): ?>
      <div class="col5"><?= renderColonna($colonna) ?></div>
    <?php endforeach; ?>
  </div>

  <!-- DISTACCAMENTI -->
  <div class="bar">Distaccamenti &amp; Aeroporto</div>
  <div class="grid5">
    <?php foreach ($DISTACC as $colonna): ?>
      <div class="col5"><?= renderColonna($colonna, true) ?></div>
    <?php endforeach; ?>
  </div>

  <!-- PERSONALE ASSENTE -->
  <div class="bar" style="margin-top:6px">Personale Assente</div>
  <div class="assenti">
    <table class="sec">
      <thead><tr><th colspan="4">Ferie</th></tr>
        <tr><th>Cognome</th><th style="width:14%">Turni</th><th style="width:17%">Da</th><th style="width:17%">A</th></tr></thead>
      <tbody>
      <?php $fer=$perTipo['FER']??[]; foreach ($fer as $a): ?>
        <tr>
          <td style="color:<?= colorePatente($a['patente_max']??null) ?>"><?= htmlspecialchars(etichettaVigile($a)) ?><?php if(!empty($a['sede_distaccata'])): ?> (<?= htmlspecialchars($a['sede_distaccata']) ?>)<?php endif; ?></td>
          <td style="text-align:center"><?= !empty($a['nr_turni'])?(int)$a['nr_turni']:'' ?></td>
          <td><?= !empty($a['data_da'])?date('d/m',strtotime($a['data_da'])):'' ?></td>
          <td><?= !empty($a['data_a'])?date('d/m',strtotime($a['data_a'])):'' ?></td>
        </tr>
      <?php endforeach; for($i=count($fer);$i<8;$i++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td></tr><?php endfor; ?>
      </tbody>
    </table>
    <table class="sec">
      <thead><tr><th colspan="2">Riposo Compensativo</th></tr>
        <tr><th>Cognome</th><th style="width:42%">Variazioni</th></tr></thead>
      <tbody>
      <?php $rc=$perTipo['RC']??[]; foreach ($rc as $a): ?>
        <tr><td><?= htmlspecialchars(etichettaVigile($a)) ?></td><td><?= htmlspecialchars($a['sede_distaccata']??'') ?: htmlspecialchars($a['note']??'') ?></td></tr>
      <?php endforeach; for($i=count($rc);$i<8;$i++): ?><tr><td>&nbsp;</td><td></td></tr><?php endfor; ?>
      </tbody>
    </table>
  </div>

  <!-- MISSIONE / PERMESSO -->
  <div class="sez-h">Missione o Permesso</div>
  <table class="full">
    <?php $mp=array_merge($perTipo['MISS']??[],$perTipo['PERM']??[]); foreach ($mp as $a): ?>
      <tr><td><?= htmlspecialchars(etichettaVigile($a)) ?> — <?= $a['tipo_codice']==='MISS'?'Missione':'Permesso' ?><?= !empty($a['note'])?' · '.htmlspecialchars($a['note']):'' ?></td></tr>
    <?php endforeach; if(!$mp): ?><tr><td>&nbsp;</td></tr><?php endif; ?>
  </table>

  <!-- MALATTIA / INFORTUNIO -->
  <div class="sez-h">Malattia o Infortunio</div>
  <table class="full">
    <?php $mi=array_merge($perTipo['MAL']??[],$perTipo['INF']??[]); foreach ($mi as $a): ?>
      <tr><td><?= htmlspecialchars(etichettaVigile($a)) ?> — <?= $a['tipo_codice']==='MAL'?'Malattia':'Infortunio' ?></td></tr>
    <?php endforeach; if(!$mi): ?><tr><td>&nbsp;</td></tr><?php endif; ?>
  </table>

  <div class="firme">
    <div class="firma"><div class="line">Il Capo Servizio</div></div>
    <div class="firma"><div class="line">Il Funzionario di Guardia</div></div>
  </div>

</div>
</body>
</html>
</content>
