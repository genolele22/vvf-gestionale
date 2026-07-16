<?php
/**
 * Logica turni VVF — 4 turni (A,B,C,D) × 8 salti (1-8)
 *
 * Ancora verificata dallo screenshot:
 *   Venerdì 01/05/2026 → diurno: B4,  notturno: A4
 *   Giovedì 21/05/2026 → diurno: B1,  notturno: A1  ← conferma originale
 *
 * Regola:
 *   - Ogni giorno il turno DIURNO ruota A(0)→B(1)→C(2)→D(3) in ciclo di 4
 *   - Il NOTTURNO è lo stesso turno del giorno precedente (sfasato di 1)
 *   - Il salto (1-8) avanza di 1 ogni 4 giorni (ogni ciclo completo A→D)
 *   - Il salto ruota 1→8 (ciclo di 8), quindi il ciclo totale = 32 giorni
 *
 * Ancora: 2026-05-01 = giorno 0
 *   offset turno diurno = 1 (B=1 in A=0,B=1,C=2,D=3)
 *   salto diurno        = 4
 */

const TURNI_ANCHOR      = '2026-05-01';
const TURNI_ANCHOR_TURNO = 1;   // B=1 (A=0,B=1,C=2,D=3)
const TURNI_ANCHOR_SALTO = 4;   // salto 4

/**
 * Dato un numero di giorni dall'ancora, calcola turno e salto del DIURNO.
 * Restituisce ['turno'=>'B','salto'=>4]
 */
function calcolaDiurno(int $giorniDallAncora): array
{
    $lettere = ['A','B','C','D'];

    // Il turno ruota di 1 ogni giorno (ciclo 4)
    $idxTurno = ((TURNI_ANCHOR_TURNO + $giorniDallAncora) % 4 + 4) % 4;

    // Il salto avanza di 1 ogni 4 giorni
    // Quanti cicli completi di 4 giorni sono passati dall'ancora?
    // All'ancora siamo al salto 4. floor() e non intdiv(): intdiv tronca
    // verso zero e sbaglia di 1 il salto per le date PRECEDENTI l'ancora
    // (verificato contro il calendario storico del bot).
    $cicliCompleti = (int)floor(($giorniDallAncora + TURNI_ANCHOR_TURNO) / 4)
                   - (int)floor(TURNI_ANCHOR_TURNO / 4);
    // salto 1-8, partiamo da TURNI_ANCHOR_SALTO
    $salto = ((TURNI_ANCHOR_SALTO - 1 + $cicliCompleti) % 8 + 8) % 8 + 1;

    return [
        'turno' => $lettere[$idxTurno],
        'salto' => $salto,
    ];
}

/**
 * Dato un numero di giorni dall'ancora, calcola turno e salto del NOTTURNO.
 * Il notturno = diurno del giorno precedente.
 */
function calcolaNotte(int $giorniDallAncora): array
{
    return calcolaDiurno($giorniDallAncora - 1);
}

/**
 * Restituisce i giorni dall'ancora per una data.
 */
function giorniDallAncora(string $data): int
{
    $anchor = new DateTime(TURNI_ANCHOR);
    $target = new DateTime($data);
    return (int)$anchor->diff($target)->format('%r%a');
}

/**
 * Primo slot (data,tipo) in cui il turno B è EFFETTIVAMENTE in servizio,
 * a partire da $fromData inclusa, scandendo in avanti.
 *
 * B è in servizio: di giorno quando il diurno è B; di notte quando il notturno
 * è B (= il diurno del giorno prima era B). In un ciclo di 4 giorni capita 2
 * volte su giorni consecutivi (giorno X diurno, giorno X+1 notte), quindi una
 * scansione di pochi giorni trova sempre un match. Per ogni giorno controlla
 * prima D poi N (D è più presto nella giornata).
 *
 * Restituisce ['data'=>'Y-m-d', 'tipo'=>'D'|'N'].
 */
function prossimoSlotTurnoB(string $fromData, string $turno = 'B'): array
{
    $d = new DateTime($fromData);
    for ($i = 0; $i < 8; $i++) {
        $ds = $d->format('Y-m-d');
        $tg = getTurnoGiorno($ds);
        if ($tg['diurno']['turno'] === $turno) return ['data' => $ds, 'tipo' => 'D'];
        if ($tg['notte']['turno']  === $turno) return ['data' => $ds, 'tipo' => 'N'];
        $d->modify('+1 day');
    }
    // Non dovrebbe mai accadere (ciclo di 4 giorni): fallback prudente.
    return ['data' => $fromData, 'tipo' => 'D'];
}

/**
 * Servizio del turno B immediatamente SUCCESSIVO ($dir=+1) o PRECEDENTE ($dir=-1)
 * a (data,tipo), scandendo il calendario turni — a prescindere che il foglio esista
 * già. Entro la giornata D precede N. Ritorna ['data'=>'Y-m-d','tipo'=>'D'|'N'] o
 * null se non trovato (non dovrebbe: B è in servizio ogni pochi giorni).
 */
function servizioAdiacenteB(string $data, string $tipo, int $dir, string $turno = 'B'): ?array
{
    $ordine = ['D' => 0, 'N' => 1];
    $cur    = new DateTime($data);
    for ($i = 0; $i < 40; $i++) {
        $ds    = $cur->format('Y-m-d');
        $tg    = getTurnoGiorno($ds);
        $slots = $dir > 0 ? ['D', 'N'] : ['N', 'D'];
        foreach ($slots as $t) {
            // salta il corrente e tutto ciò che sta dalla parte sbagliata
            $cmp = ($ds <=> $data) ?: ($ordine[$t] <=> $ordine[$tipo]);
            if ($dir > 0 ? $cmp <= 0 : $cmp >= 0) continue;
            if ($tg[$t === 'D' ? 'diurno' : 'notte']['turno'] === $turno) {
                return ['data' => $ds, 'tipo' => $t];
            }
        }
        $cur->modify(($dir > 0 ? '+' : '-') . '1 day');
    }
    return null;
}

/**
 * Restituisce turno+salto diurno e notturno per una data.
 * Es: ['diurno'=>['turno'=>'B','salto'=>1], 'notte'=>['turno'=>'A','salto'=>1]]
 */
function getTurnoGiorno(string $data): array
{
    $giorni = giorniDallAncora($data);
    return [
        'diurno' => calcolaDiurno($giorni),
        'notte'  => calcolaNotte($giorni),
    ];
}

/**
 * ── Scambio salto turno: occorrenze di riposo generiche (A/B/C/D) ───────────
 * Il salto di un foglio segue saltoRiposoNum. L'occorrenza di riposo di uno
 * slot NON è sempre la coppia (D, N del giorno dopo): vale per i turni B e C;
 * per A e D è (N di un servizio, D del servizio successivo, 3 giorni dopo) —
 * verificato sui fogli reali (A6 in salto su 12/07 N e 15/07 D, D7 su 15/07 N).
 * Per questo le funzioni lavorano per scansione dei servizi del turno, senza
 * assumere coppie consecutive, e restituiscono occorrenze TIPIZZATE:
 *     [['Y-m-d', 'D'|'N'], ['Y-m-d', 'D'|'N']]   in ordine cronologico.
 * Un blocco = giro completo slot 1→8 (32 giorni), cadenza uguale per tutti.
 */

/**
 * Slot (1..8) a riposo per un foglio (data,tipo). Stessa convenzione di
 * nuovo.php: foglio D → salto del NOTTURNO; foglio N → salto del DIURNO.
 */
function saltoRiposoNum(string $data, string $tipo): int
{
    $tg = getTurnoGiorno($data);
    return (int)($tipo === 'D' ? $tg['notte']['salto'] : $tg['diurno']['salto']);
}

/** Fogli [data, tipo] in cui $turno è in servizio, in [$da, $a] inclusi. */
function serviziTurno(string $turno, string $da, string $a): array
{
    $out  = [];
    $cur  = new DateTime($da);
    $fine = new DateTime($a);
    while ($cur <= $fine) {
        $ds = $cur->format('Y-m-d');
        $tg = getTurnoGiorno($ds);
        if ($tg['diurno']['turno'] === $turno) $out[] = [$ds, 'D'];
        if ($tg['notte']['turno']  === $turno) $out[] = [$ds, 'N'];
        $cur->modify('+1 day');
    }
    return $out;
}

/**
 * Prime $n occorrenze di riposo dello slot per $turno il cui PRIMO foglio
 * cade in data >= $da. I 2 fogli di un'occorrenza si raggruppano per
 * prossimità (gap <= 4 giorni); tra occorrenze passano 32 giorni.
 */
function occorrenzeSlotDa(string $turno, int $slot, string $da, int $n): array
{
    $inizioScan = (new DateTime($da))->modify('-5 day')->format('Y-m-d');
    $fineScan   = (new DateTime($da))->modify('+' . (32 * ($n + 1) + 10) . ' day')->format('Y-m-d');
    $fogli = array_values(array_filter(
        serviziTurno($turno, $inizioScan, $fineScan),
        fn($f) => saltoRiposoNum($f[0], $f[1]) === $slot
    ));
    $occ = [];
    $i = 0;
    while ($i + 1 < count($fogli) && count($occ) < $n) {
        $gap = (new DateTime($fogli[$i][0]))->diff(new DateTime($fogli[$i + 1][0]))->days;
        if ($gap > 4) { $i++; continue; }      // foglio orfano ai margini dello scan
        if ($fogli[$i][0] >= $da) $occ[] = [$fogli[$i], $fogli[$i + 1]];
        $i += 2;
    }
    return $occ;
}

/**
 * Confini del blocco slot 1→8 di $turno che contiene $data:
 * ['Y-m-d' prima data dell'occ. slot 1, 'Y-m-d' ultima data dell'occ. slot 8].
 */
function bloccoConfini(string $data, string $turno = 'B'): array
{
    $scanDa = (new DateTime($data))->modify('-33 day')->format('Y-m-d');
    $occ1   = occorrenzeSlotDa($turno, 1, $scanDa, 2);
    $inizio = null;
    foreach ($occ1 as $o) {
        if ($o[0][0] <= $data) $inizio = $o[0][0];
    }
    if ($inizio === null) {  // $data precede la prima occ trovata: blocco precedente
        $inizio = (new DateTime($occ1[0][0][0]))->modify('-32 day')->format('Y-m-d');
    }
    $occ8 = occorrenzeSlotDa($turno, 8, $inizio, 1);
    return [$inizio, $occ8[0][1][0]];
}

/**
 * Occorrenza tipizzata dello slot dentro il blocco di $data
 * ([['Y-m-d',tipo], ['Y-m-d',tipo]]), o null se slot fuori range.
 */
function slotDatesInBlocco(int $slot, string $data, string $turno = 'B'): ?array
{
    if ($slot < 1 || $slot > 8) return null;
    [$inizio, ] = bloccoConfini($data, $turno);
    $occ = occorrenzeSlotDa($turno, $slot, $inizio, 1);
    if ($occ && (new DateTime($inizio))->diff(new DateTime($occ[0][0][0]))->days < 32) {
        return $occ[0];
    }
    return null;
}

/**
 * Restituisce tutti i turni del mese indicizzati per data.
 */
function getTurniMese(int $anno, int $mese): array
{
    $giorni = cal_days_in_month(CAL_GREGORIAN, $mese, $anno);
    $result = [];
    for ($g = 1; $g <= $giorni; $g++) {
        $data      = sprintf('%04d-%02d-%02d', $anno, $mese, $g);
        $gg        = giorniDallAncora($data);
        $diurno    = calcolaDiurno($gg);
        $notte     = calcolaNotte($gg);
        $result[$data] = [
            'D' => $diurno,
            'N' => $notte,
        ];
    }
    return $result;
}
