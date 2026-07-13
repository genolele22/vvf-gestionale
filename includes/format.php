<?php
// Formattazione vigile/sede per le pagine del gestionale (display web).
// L'ODT ha le sue regole in FoglioRenderer::etichetta() (parametro admin
// foglio_formato_nome): NON unificare — qui è solo la resa a schermo.

if (!function_exists('etichettaVigile')) {

/** "Cs Rossi 2" — qualifica + cognome + eventuale disambiguatore. */
function etichettaVigile(array $v): string {
    return ucfirst(strtolower($v['qcodice'] ?? ''))
         . ' ' . ucfirst(strtolower($v['cognome'] ?? ''))
         . (!empty($v['disambiguatore']) ? ' ' . (int)$v['disambiguatore'] : '');
}

/** Sigla sede per il badge (solo visualizzazione): Centrale → "C". */
function siglaSede(?string $c): string {
    return $c === 'CENTR' ? 'C' : (string)$c;
}

/** Colore del nome in base alla patente più alta (3/4 rosso, 2 blu). */
function colorePatentePHP(?string $patente): string {
    switch ($patente) {
        case '4':
        case '3': return '#c0392b';
        case '2': return '#2471a3';
        default:  return '#2c3e50';
    }
}

}
