<?php
// Formattazione vigile/sede per le pagine del gestionale (display web).
// L'ODT ha le sue regole in FoglioRenderer::etichetta() (parametro admin
// foglio_formato_nome): NON unificare — qui è solo la resa a schermo.

if (!function_exists('etichettaVigile')) {

/**
 * Stile patenti sul foglio (parametri admin, per turno): 'colore' (storico),
 * 'numero' (grado accanto al nome, niente colore) o 'entrambi'. Default =
 * storico: solo nuovo.php chiama initStilePatente(), le altre pagine che
 * includono questo file (ferie, liste) restano su colore/tinte standard.
 */
function stilePatenteCfg(?array $set = null): array {
    static $cfg = ['stile' => 'colore', 'rosso' => '#c0392b', 'blu' => '#2471a3'];
    if ($set !== null) $cfg = array_merge($cfg, $set);
    return $cfg;
}

/** Stili selezionabili (valore => etichetta admin) — unica whitelist. */
function stiliPatente(): array {
    return ['colore' => 'Solo colore', 'numero' => 'Solo numero (2°, 3°, 4°)', 'entrambi' => 'Colore + numero'];
}

/** Legge un set di chiavi da `parametri` in un giro solo ([chiave => valore], assenti escluse). */
function leggiParametri(PDO $pdo, array $chiavi): array {
    try {
        $ph = implode(',', array_fill(0, count($chiavi), '?'));
        $st = $pdo->prepare("SELECT chiave, valore FROM parametri WHERE chiave IN ($ph)");
        $st->execute(array_values($chiavi));
        $out = [];
        foreach ($st->fetchAll() as $r) $out[$r['chiave']] = trim((string)$r['valore']);
        return $out;
    } catch (Throwable $e) { return []; }   // tabella assente: nessun parametro
}

function chiaviStilePatente(string $turno): array {
    return ["foglio_stile_patente_$turno", "foglio_rosso_patente_$turno", "foglio_blu_patente_$turno"];
}

/** Valida i valori grezzi delle chiavi del turno; null = non impostato/invalido,
 *  il chiamante tiene il default del suo medium. Unica validazione, usata da
 *  web (initStilePatente) e ODT (FoglioRenderer). */
function validaStilePatente(array $p, string $turno): array {
    $stile = $p["foglio_stile_patente_$turno"] ?? '';
    $out = ['stile' => isset(stiliPatente()[$stile]) ? $stile : null];
    foreach (['rosso', 'blu'] as $c) {
        $hex = $p["foglio_{$c}_patente_$turno"] ?? '';
        $out[$c] = preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex : null;
    }
    return $out;
}

/**
 * #182: colori di evidenziazione (sfondo dietro al nome) su ODT — straordinario,
 * ferie estive, ferie d'ufficio. Solo ODT, il foglio web non li usa (decisione
 * esplicita: restano incoerenti come per gli specialisti, #185). Ogni chiave:
 * '' = tinta classica del medium, 'none' = nessuna evidenziazione (il nome resta
 * nero, valgono solo le altre regole — es. il grassetto dello straordinario),
 * '#rrggbb' = tinta scelta in amministrazione.
 */
function chiaviStileEvidenziazioni(string $turno): array {
    return ["foglio_col_straord_$turno", "foglio_col_ferie_estiva_$turno", "foglio_col_ferie_ufficio_$turno"];
}

/** null = non impostato/invalido (il chiamante tiene il default), 'none' = spento,
 *  altrimenti '#rrggbb'. Stessa idea di validaStilePatente(). */
function validaStileEvidenziazioni(array $p, string $turno): array {
    $out = [];
    foreach (['straord' => 'straord', 'estiva' => 'ferie_estiva', 'ufficio' => 'ferie_ufficio'] as $k => $suffix) {
        $v = $p["foglio_col_{$suffix}_$turno"] ?? '';
        $out[$k] = ($v === 'none' || preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? $v : null;
    }
    return $out;
}

/** Carica da `parametri` lo stile patenti del turno (chiavi *_A/B/C/D). */
function initStilePatente(PDO $pdo, string $turno): void {
    $v = validaStilePatente(leggiParametri($pdo, chiaviStilePatente($turno)), $turno);
    $set = array_filter($v, fn($x) => $x !== null);
    if ($set) stilePatenteCfg($set);
}

/** Regola pura del suffisso grado: " 3°" se lo stile lo prevede, '' altrimenti. */
function suffissoGrado(?string $patente, string $stile): string {
    if ($stile === 'colore') return '';
    return in_array($patente, ['2', '3', '4'], true) ? " {$patente}°" : '';
}

/** Suffisso grado con lo stile corrente della pagina web. */
function suffissoPatente(?string $patente): string {
    return suffissoGrado($patente, stilePatenteCfg()['stile']);
}

/** "Cs Rossi 2" — qualifica + cognome + eventuale disambiguatore (+ grado patente se attivo). */
function etichettaVigile(array $v): string {
    return ucfirst(strtolower($v['qcodice'] ?? ''))
         . ' ' . ucfirst(strtolower($v['cognome'] ?? ''))
         . (!empty($v['disambiguatore']) ? ' ' . (int)$v['disambiguatore'] : '')
         . suffissoPatente($v['patente_max'] ?? null);
}

/** Sigla sede per il badge (solo visualizzazione): Centrale → "C". */
function siglaSede(?string $c): string {
    return $c === 'CENTR' ? 'C' : (string)$c;
}

/** Colore del nome in base alla patente più alta (3/4 rosso, 2 blu).
 *  Con stile 'numero' il colore sparisce: il grado lo dice il suffisso. */
function colorePatentePHP(?string $patente): string {
    $cfg = stilePatenteCfg();
    if ($cfg['stile'] === 'numero') return '#2c3e50';
    switch ($patente) {
        case '4':
        case '3': return $cfg['rosso'];
        case '2': return $cfg['blu'];
        default:  return '#2c3e50';
    }
}

}
