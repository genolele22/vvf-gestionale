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
    // All'ancora siamo al salto 4, quindi:
    $cicliCompleti = intdiv($giorniDallAncora + TURNI_ANCHOR_TURNO, 4)
                   - intdiv(TURNI_ANCHOR_TURNO, 4);
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
 * Restituisce il label del salto a riposo per il turno B in una data+tipo.
 * Per il gestionale del turno B ci interessa solo chi del turno B è a riposo.
 *
 * "A riposo" = il salto che NON lavora né di giorno né di notte.
 * Ogni giorno lavorano 2 salti del turno B (uno diurno, uno notturno),
 * quindi i salti B in riposo quel giorno sono gli altri 6... ma per il
 * foglio di servizio ci interessa il salto UFFICIALE di riposo (salto turno),
 * che corrisponde al salto del turno B che ha il suo giorno di riposo canonico.
 *
 * Per semplicità: il "salto in riposo" visualizzato nel calendario
 * è il salto che il turno B ha come DIURNO (è il suo turno di riferimento).
 */
function getSaltoRiposo(string $data, string $tipo): int
{
    $giorni = giorniDallAncora($data);
    $info   = ($tipo === 'D') ? calcolaDiurno($giorni) : calcolaNotte($giorni);
    // Restituisce il salto del turno B in quel slot
    // Se il turno attivo non è B, calcoliamo quale salto B è a riposo
    return $info['salto'];
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
function prossimoSlotTurnoB(string $fromData): array
{
    $d = new DateTime($fromData);
    for ($i = 0; $i < 8; $i++) {
        $ds = $d->format('Y-m-d');
        $tg = getTurnoGiorno($ds);
        if ($tg['diurno']['turno'] === 'B') return ['data' => $ds, 'tipo' => 'D'];
        if ($tg['notte']['turno']  === 'B') return ['data' => $ds, 'tipo' => 'N'];
        $d->modify('+1 day');
    }
    // Non dovrebbe mai accadere (ciclo di 4 giorni): fallback prudente.
    return ['data' => $fromData, 'tipo' => 'D'];
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
 * ── Scambio salto turno: blocchi ciclici B1→B8 ──────────────────────────────
 * Replica della logica del bot (calendar_turni.py) sul modello algoritmico.
 * Verificata contro data/calendario.json del bot: 100% sugli slot-dates
 * nella finestra operativa (i confini divergono solo ai bordi del json, dove
 * l'algoritmo estrapola correttamente).
 *
 * Un'occorrenza di riposo dello slot S = (data_D, data_N = data_D + 1 giorno).
 * I giorni di riposo-D del turno B ricorrono ogni 4 giorni; lo slot a riposo
 * avanza +1 ad ogni occorrenza. Un blocco = giro completo B1→B8 (32 giorni).
 */

/**
 * Slot (1..8) a riposo per un foglio (data,tipo). Stessa convenzione di
 * nuovo.php: foglio D → salto del NOTTURNO; foglio N → salto del DIURNO.
 * (NON usa getSaltoRiposo, che diverge sui giorni di confine.)
 */
function saltoRiposoNum(string $data, string $tipo): int
{
    $tg = getTurnoGiorno($data);
    return (int)($tipo === 'D' ? $tg['notte']['salto'] : $tg['diurno']['salto']);
}

/**
 * Confini del blocco B1→B8 che contiene $data:
 * ['Y-m-d' di B1 (data_D), 'Y-m-d' di B8 (data_N)].
 */
function bloccoConfini(string $data): array
{
    $off   = giorniDallAncora($data);
    $mod   = (($off % 4) + 4) % 4;                              // dist. dal rest-D corrente
    $restD = (new DateTime($data))->modify("-{$mod} day");      // rest-D dell'occorrenza
    $slot  = saltoRiposoNum($restD->format('Y-m-d'), 'D');      // slot di quel rest-D
    $inizio = (clone $restD)->modify('-' . (($slot - 1) * 4) . ' day');  // B1 data_D
    $fineN  = (clone $inizio)->modify('+29 day');               // B8 data_N (28 + 1)
    return [$inizio->format('Y-m-d'), $fineN->format('Y-m-d')];
}

/**
 * (data_D, data_N) dello slot dentro il blocco di $data, come ['Y-m-d','Y-m-d'],
 * o null se slot fuori range.
 */
function slotDatesInBlocco(int $slot, string $data): ?array
{
    if ($slot < 1 || $slot > 8) return null;
    [$inizio, ] = bloccoConfini($data);
    $dD = (new DateTime($inizio))->modify('+' . (($slot - 1) * 4) . ' day');
    $dN = (clone $dD)->modify('+1 day');
    return [$dD->format('Y-m-d'), $dN->format('Y-m-d')];
}

/**
 * Restituisce label completa per un giorno: "B1 / A1"
 */
function getLabelGiorno(string $data): string
{
    $t = getTurnoGiorno($data);
    return $t['diurno']['turno'] . $t['diurno']['salto']
         . ' / '
         . $t['notte']['turno']  . $t['notte']['salto'];
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

/**
 * Dice se un dato salto del turno B è a riposo in una certa data.
 * Utile per evidenziare nel calendario il salto selezionato.
 * @param string $data   'Y-m-d'
 * @param int    $salto  1-8
 */
function isSaltoRiposo(string $data, int $salto): bool
{
    $t = getTurnoGiorno($data);
    $saltoD = ($t['diurno']['turno'] === 'B') ? $t['diurno']['salto'] : null;
    $saltoN = ($t['notte']['turno']  === 'B') ? $t['notte']['salto']  : null;
    // È a riposo se non è né diurno né notturno quel giorno
    return ($salto !== $saltoD && $salto !== $saltoN);
}

/**
 * Restituisce l'etichetta 'B1'...'B8' del salto B a riposo oggi.
 * Se entrambi i turni B lavorano (non dovrebbe mai accadere), restituisce '—'.
 */
function getSaltoLabel(string $data, string $tipo): string
{
    $t = getTurnoGiorno($data);
    if ($tipo === 'D' && $t['diurno']['turno'] === 'B') {
        return 'B' . $t['diurno']['salto'];
    }
    if ($tipo === 'N' && $t['notte']['turno'] === 'B') {
        return 'B' . $t['notte']['salto'];
    }
    return '—';
}
