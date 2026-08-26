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
    // 'qual' (#220): colore per qualifica Cr/Cs/Vp, null = nessun colore (default).
    // Vale SOLO con stile 'numero' — vedi colorePatentePHP() per il perché.
    static $cfg = ['stile' => 'colore', 'rosso' => '#c0392b', 'blu' => '#2471a3',
                   'qual' => ['Cr' => null, 'Cs' => null, 'Vp' => null]];
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

/** '' o valore ignoto = non impostato → default del medium; 'none' = spento (null);
 *  altrimenti la tinta scelta. Unico posto dove si scioglie la convenzione dei
 *  colori configurabili (#182, #220) invece di ripeterla a ogni chiamante. */
function risolviColore(?string $raw, ?string $default): ?string {
    if ($raw === null || $raw === '') return $default;
    return $raw === 'none' ? null : $raw;
}

/**
 * #220 — colore del nome per qualifica (Capo Reparto / Capo Squadra / Vigile).
 * I codici sono quelli veri della tabella `qualifiche`: Cr, Cs, Vp (verificati
 * sul DB di produzione, sono solo questi tre). Convenzione dei valori identica
 * alle evidenziazioni: '' = non impostato, 'none' = nessun colore, '#rrggbb'.
 * Default = nessun colore, cioè il comportamento precedente a #220.
 */
function qualificheColorabili(): array {
    return ['Cr' => 'Capo Reparto', 'Cs' => 'Capo Squadra', 'Vp' => 'Vigile'];
}

function chiaviStileQualifica(string $turno): array {
    return ["foglio_col_qual_cr_$turno", "foglio_col_qual_cs_$turno", "foglio_col_qual_vp_$turno"];
}

/** Valori grezzi per qualifica: ['Cr' => 'none'|'#rrggbb'|null, ...]. */
function validaStileQualifica(array $p, string $turno): array {
    $out = [];
    foreach (array_keys(qualificheColorabili()) as $q) {
        $v = $p['foglio_col_qual_' . strtolower($q) . "_$turno"] ?? '';
        $out[$q] = ($v === 'none' || preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? $v : null;
    }
    return $out;
}

/** ['Cr' => '#rrggbb'|null, ...] già risolti (null = nessun colore). */
function coloriQualificaRisolti(array $p, string $turno): array {
    $out = [];
    foreach (validaStileQualifica($p, $turno) as $q => $raw) $out[$q] = risolviColore($raw, null);
    return $out;
}

/**
 * #220 — controlli che valgono SOLO sull'ODT: colori dei nomi accesi/spenti,
 * stile del testo per straordinario e per fuori sede, evidenziazione fuori sede.
 * Booleani salvati come '1'/'0'; chiave assente = default storico (= comportamento
 * di prima di #220), così un turno mai configurato non cambia aspetto.
 */
function chiaviStileOdt(string $turno): array {
    return ["foglio_colori_odt_$turno",
            "foglio_str_grassetto_$turno", "foglio_str_corsivo_$turno",
            "foglio_col_fuorisede_$turno", "foglio_fs_grassetto_$turno",
            "foglio_fs_corsivo_$turno",    "foglio_fs_sottolineato_$turno"];
}

/** Tutti i valori già risolti (mai null): i default sono noti e condivisi. */
function validaStileOdt(array $p, string $turno): array {
    $bool = function (string $k, bool $def) use ($p, $turno): bool {
        $v = $p["{$k}_$turno"] ?? '';
        return ($v === '1' || $v === '0') ? $v === '1' : $def;
    };
    $fs = $p["foglio_col_fuorisede_$turno"] ?? '';
    if ($fs !== 'none' && !preg_match('/^#[0-9a-fA-F]{6}$/', $fs)) $fs = '';
    return [
        'colori_odt' => $bool('foglio_colori_odt', true),      // storico: i colori vanno anche sull'ODT
        'str_bold'   => $bool('foglio_str_grassetto', true),   // storico: straordinario in grassetto
        'str_italic' => $bool('foglio_str_corsivo', false),
        'fs_bold'    => $bool('foglio_fs_grassetto', false),
        'fs_italic'  => $bool('foglio_fs_corsivo', false),
        'fs_under'   => $bool('foglio_fs_sottolineato', true), // storico: fuori sede sempre sottolineato
        'fs_col'     => risolviColore($fs, null),              // storico: nessuna evidenziazione
        'fs_col_raw' => $fs,
    ];
}

/** Carica da `parametri` lo stile patenti del turno (chiavi *_A/B/C/D). */
function initStilePatente(PDO $pdo, string $turno): void {
    $p = leggiParametri($pdo, array_merge(chiaviStilePatente($turno), chiaviStileQualifica($turno)));
    $v = validaStilePatente($p, $turno);
    $set = array_filter($v, fn($x) => $x !== null);
    $set['qual'] = coloriQualificaRisolti($p, $turno);   // #220
    stilePatenteCfg($set);
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

/** Colore configurato per una qualifica ('Cr'/'Cs'/'Vp'), null se nessuno. */
function coloreQualificaPHP(?string $qcodice): ?string {
    if ($qcodice === null || trim($qcodice) === '') return null;
    return stilePatenteCfg()['qual'][ucfirst(strtolower(trim($qcodice)))] ?? null;
}

/** Colore del nome in base alla patente più alta (3/4 rosso, 2 blu).
 *  Con stile 'numero' il colore sparisce: il grado lo dice il suffisso —
 *  ed è lì, e solo lì, che entra il colore per qualifica (#220).
 *
 *  Il conflitto segnalato da Moli (stesso nome, due colori possibili: patente e
 *  qualifica) è risolto qui, in un punto solo e lato server: i due colori non
 *  coesistono mai perché sono rami alternativi dello stesso if. L'interfaccia
 *  disabilita il riquadro Qualifica fuori dallo stile 'numero', ma anche se
 *  qualcuno forzasse il POST i colori qualifica resterebbero inerti. */
function colorePatentePHP(?string $patente, ?string $qcodice = null): string {
    $cfg = stilePatenteCfg();
    if ($cfg['stile'] === 'numero') return coloreQualificaPHP($qcodice) ?? '#2c3e50';
    switch ($patente) {
        case '4':
        case '3': return $cfg['rosso'];
        case '2': return $cfg['blu'];
        default:  return '#2c3e50';
    }
}

}
