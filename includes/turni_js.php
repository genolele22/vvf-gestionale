<?php
/**
 * Porta JavaScript del ciclo turni — sorgente unica delle costanti.
 *
 * Le pagine con un calendario navigabile (admin/ferie_simulate.php,
 * admin/assenze_simulate.php) devono sapere in ogni mese quale slot copre il
 * turno attivo, senza un giro sul server a ogni cambio mese. Invece di
 * ricopiare l'ancora in ogni pagina (una copia inline della logica turni ha
 * già causato un bug in passato), l'ancora viene emessa qui una volta sola
 * dalle costanti di includes/turni.php.
 *
 * Espone:
 *   TURNO_ATTIVO            lettera del turno della pagina
 *   slotTurnoAttivo(y,m,d)  'D' | 'N' | null   (m: 1-12)
 *   saltoDiurnoJs(y,m,d)    numero di salto 1-8 del diurno di quel giorno
 */
require_once __DIR__ . '/turni.php';

function turniJsHtml(string $turno): string
{
    [$ay, $am, $ad] = array_map('intval', explode('-', TURNI_ANCHOR));
    $t  = json_encode($turno);
    $at = TURNI_ANCHOR_TURNO;
    $as = TURNI_ANCHOR_SALTO;
    return <<<HTML
<script>
// Generato da includes/turni_js.php — non ricopiare qui l'ancora a mano.
const TURNI_ANCHOR_MS    = Date.UTC($ay, $am - 1, $ad);
const TURNI_ANCHOR_TURNO = $at;
const TURNI_ANCHOR_SALTO = $as;
const TURNO_ATTIVO       = $t;

function giorniDallAncoraJs(y, m, d) {   // m: 1-12
  return Math.round((Date.UTC(y, m - 1, d) - TURNI_ANCHOR_MS) / 86400000);
}
function turnoDiurnoJs(giorni) {         // lettera del diurno (A,B,C,D)
  const idx = (((TURNI_ANCHOR_TURNO + giorni) % 4) + 4) % 4;
  return ['A', 'B', 'C', 'D'][idx];
}
function saltoDiurnoDaGiorni(giorni) {   // 1-8, mirror di calcolaDiurno()
  const cicli = Math.floor((giorni + TURNI_ANCHOR_TURNO) / 4)
              - Math.floor(TURNI_ANCHOR_TURNO / 4);
  return ((TURNI_ANCHOR_SALTO - 1 + cicli) % 8 + 8) % 8 + 1;
}
function saltoDiurnoJs(y, m, d) { return saltoDiurnoDaGiorni(giorniDallAncoraJs(y, m, d)); }

// Slot del turno attivo in una data: 'D', 'N' o null (non in servizio).
// Il notturno di un giorno è il diurno del giorno precedente.
function slotTurnoAttivo(y, m, d) {
  const g = giorniDallAncoraJs(y, m, d);
  if (turnoDiurnoJs(g)     === TURNO_ATTIVO) return 'D';
  if (turnoDiurnoJs(g - 1) === TURNO_ATTIVO) return 'N';
  return null;
}
</script>
HTML;
}
