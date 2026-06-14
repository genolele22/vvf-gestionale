<?php
/**
 * FoglioRenderer — genera il Foglio di Servizio dal DB riempiendo il modulo ufficiale
 * (templates/modello.odt). Read-only sul DB. Output ODT (e in futuro HTML).
 *
 * Strategia: cammina la tabella del modello, traccia per ogni colonna il mezzo corrente
 * (dalle righe-header), e riempie dall'alto le celle sotto ogni header coi nomi del DB.
 * Niente clonazione righe: capienza = righe del modulo (come da esempio B4).
 */
class FoglioRenderer
{
    const TBL = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    const TXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    const STY = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
    const FO  = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';
    const OFF = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    // header testo modulo → codice posizione DB
    const HDR2CODE = [
        'Centrale operativa'=>'CENTR-OP','1A'=>'1A','2A'=>'2A','3A/Squadretta'=>'3A','4A'=>'4A',
        '1SMZ'=>'1SMZ','1B'=>'1B','2B/NBCR'=>'2B-NBCR','1FUN/Autoradio'=>'1FUN-AUTORADIO','5A'=>'5A',
        '3B'=>'3B','4B'=>'4B','1SOP/Autorimessa'=>'1SOP-AUTORIM','ML-1A'=>'ML-1A','GE-1A'=>'GE-1A',
        'BS-1A'=>'BS-1A','CH-1A'=>'CH-1A','Telefonista'=>'AP-TEL','GA-1NAU'=>'GA-1NAU','BL-1A'=>'BL-1A',
        'RP-1A'=>'RP-1A','CH-1B'=>'CH-1B','AP-1ROS'=>'AP-1ROS','AP-1ASA'=>'AP-1ASA','AP-1VI'=>'AP-1VI',
        'AP-2VI'=>'AP-2VI','ML-1NAU'=>'ML-1NAU','EL-1SMZ'=>'EL-1SMZ',
    ];
    // colonne sezione assenti (start-col nel modello)
    const FER_COG=0, FER_TUR=6, FER_DA=7, FER_A=9, RC_COG=12, RC_VAR=17;

    private PDO $pdo;
    private array $foglio;
    private string $dataStr, $tipoParam, $codSaltoRip, $dataLabel, $giornoLbl;
    private array $assByCode = [], $perTipo = [], $furieri = [];
    private ?array $capo = null, $vice = null;
    private array $scambioOut = [];
    public array $overflow = [];   // mezzi con più nomi che slot
    public array $noslot = [];     // mezzi con nomi ma 0 slot

    public function __construct(PDO $pdo, int $foglioId)
    {
        $this->pdo = $pdo;
        $st = $pdo->prepare("SELECT * FROM fogli_servizio WHERE id=?");
        $st->execute([$foglioId]);
        $f = $st->fetch();
        if (!$f) throw new RuntimeException("Foglio $foglioId inesistente");
        $this->foglio = $f;
        $this->dataStr   = $f['data_servizio'];
        $this->tipoParam = $f['tipo_turno'] === 'N' ? 'N' : 'D';
        $dt = new DateTime($this->dataStr);
        $this->dataLabel = $dt->format('d/m/Y');
        $gg = ['Sunday'=>'Domenica','Monday'=>'Lunedì','Tuesday'=>'Martedì','Wednesday'=>'Mercoledì',
               'Thursday'=>'Giovedì','Friday'=>'Venerdì','Saturday'=>'Sabato'];
        $this->giornoLbl = $gg[$dt->format('l')] ?? '';
        require_once __DIR__ . '/turni.php';
        $tg = getTurnoGiorno($this->dataStr);
        $rip = $this->tipoParam === 'D' ? $tg['notte'] : $tg['diurno'];
        $this->codSaltoRip = 'B' . $rip['salto'];
        $this->loadData($foglioId);
    }

    private function loadData(int $foglioId): void
    {
        $pat = "(SELECT MAX(p.tipo) FROM vigili_patenti vp JOIN patenti p ON p.id=vp.patente_id WHERE vp.vigile_id=v.id)";
        // assegnazioni per codice posizione
        $idByCode = [];
        foreach ($this->pdo->query("SELECT id,codice FROM posizioni")->fetchAll() as $p) $idByCode[(int)$p['id']] = $p['codice'];
        $st = $this->pdo->prepare(
            "SELECT a.posizione_id, a.in_straordinario, a.vigile_id, v.cognome, v.disambiguatore,
                    q.codice AS qcodice, $pat AS patente_max
             FROM assegnazioni a JOIN vigili v ON v.id=a.vigile_id JOIN qualifiche q ON q.id=v.qualifica_id
             WHERE a.foglio_id=? ORDER BY a.posizione_id, a.ordine");
        $st->execute([$foglioId]);
        foreach ($st->fetchAll() as $a) {
            $code = $idByCode[(int)$a['posizione_id']] ?? null;
            if ($code) $this->assByCode[$code][] = $a;
        }
        // assenze per tipo
        $st = $this->pdo->prepare(
            "SELECT a.*, v.cognome, v.disambiguatore, q.codice AS qcodice, ta.codice AS tipo_codice, $pat AS patente_max
             FROM assenze a JOIN vigili v ON v.id=a.vigile_id JOIN qualifiche q ON q.id=v.qualifica_id
             JOIN tipo_assenza ta ON ta.id=a.tipo_assenza_id WHERE a.foglio_id=? ORDER BY ta.id, v.cognome");
        $st->execute([$foglioId]);
        foreach ($st->fetchAll() as $a) $this->perTipo[$a['tipo_codice']][] = $a;
        // capo / vice / furieri
        $this->capo = $this->vigById($this->foglio['capo_servizio_id'] ?? null);
        $this->vice = $this->vigById($this->foglio['vice_capo_id'] ?? null);
        $st = $this->pdo->prepare("SELECT v.cognome,v.disambiguatore,q.codice AS qcodice FROM foglio_furieri ff JOIN vigili v ON v.id=ff.vigile_id JOIN qualifiche q ON q.id=v.qualifica_id WHERE ff.foglio_id=? ORDER BY v.cognome");
        $st->execute([$foglioId]);
        $this->furieri = $st->fetchAll();
        // scambi salto (badge "ha ceduto")
        $st = $this->pdo->prepare("SELECT vigile_out_id FROM salto_override WHERE data=? AND tipo=? AND attivo=1");
        $st->execute([$this->dataStr, $this->tipoParam]);
        foreach ($st->fetchAll() as $r) $this->scambioOut[(int)$r['vigile_out_id']] = true;
    }

    private function vigById($id): ?array
    {
        if (!$id) return null;
        $st = $this->pdo->prepare("SELECT v.cognome,v.disambiguatore,q.codice AS qcodice FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id WHERE v.id=?");
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    private static function etichetta(array $v): string
    {
        return ucfirst(strtolower($v['qcodice'] ?? '')) . ' ' . ucfirst(strtolower($v['cognome'] ?? ''))
             . (!empty($v['disambiguatore']) ? ' ' . (int)$v['disambiguatore'] : '');
    }
    private static function colorStyle(?string $t): ?string
    {
        if ($t === '3' || $t === '4') return 'ColRosso';
        if ($t === '2') return 'ColBlu';
        return null;
    }

    /** Genera i bytes .odt */
    public function odt(): string
    {
        $modelPath = __DIR__ . '/../templates/modello.odt';
        $tmp = tempnam(sys_get_temp_dir(), 'odt');
        copy($modelPath, $tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new RuntimeException('modello.odt non apribile');
        $xml = $zip->getFromName('content.xml');
        $xml = $this->fillContentXml($xml);
        $zip->deleteName('content.xml');
        $zip->addFromString('content.xml', $xml);
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    private function fillContentXml(string $xml): string
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->loadXML($xml);
        $this->ensureColorStyles($doc);

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('table', self::TBL);
        $xp->registerNamespace('text', self::TXT);

        $table = $xp->query('//table:table')->item(0);
        $rows = [];
        foreach ($table->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && $n->localName === 'table-row') $rows[] = $n;
        }

        // indice riga PERSONALE ASSENTE / MISSIONE / MALATTIA / Cognome
        $rowText = function(DOMElement $r): string {
            return trim($r->textContent);
        };
        $pa = $miss = $mal = $cogHdr = null;
        foreach ($rows as $i => $r) {
            $t = $rowText($r);
            if ($pa === null && strpos($t, 'PERSONALE ASSENTE') !== false) $pa = $i;
            elseif ($pa !== null && $cogHdr === null && strpos($t, 'Cognome') !== false) $cogHdr = $i;
            elseif ($pa !== null && $miss === null && strpos($t, 'MISSIONE') !== false) $miss = $i;
            elseif ($miss !== null && $mal === null && strpos($t, 'MALATTIA') !== false) $mal = $i;
        }
        $pa = $pa ?? count($rows); $miss = $miss ?? $pa; $mal = $mal ?? $miss;

        // ── AREA SERVIZIO: riempi i mezzi ───────────────────────────────────────
        $colCode  = [];         // start-col → codice mezzo corrente
        $queue    = [];         // codice → lista nomi DB da piazzare (in ordine)
        $lastCell = [];         // codice → ultima cella riempita (per overflow)
        foreach ($this->assByCode as $code => $list) { $queue[$code] = $list; }

        for ($i = 0; $i < $pa; $i++) {
            foreach ($this->rowCells($rows[$i]) as [$col, $cell]) {
                $txt = trim($cell->textContent);
                if ($txt !== '' && isset(self::HDR2CODE[$txt])) {      // header mezzo
                    $colCode[$col] = self::HDR2CODE[$txt];
                    continue;
                }
                // cella vuota sotto un mezzo → piazza prossimo nome
                if ($txt === '' && isset($colCode[$col])) {
                    $code = $colCode[$col];
                    if (!empty($queue[$code])) {
                        $a = array_shift($queue[$code]);
                        $this->writeName($doc, $cell, $a);
                        $lastCell[$code] = $cell;
                    }
                }
            }
        }
        // nomi in eccedenza: accodali all'ultima cella del mezzo (niente perdita dati)
        foreach ($queue as $code => $rest) {
            if (empty($rest)) continue;
            if (isset($lastCell[$code])) {
                foreach ($rest as $a) $this->appendName($doc, $lastCell[$code], $a);
                $this->overflow[] = $code . ' (+' . count($rest) . ')';
            } else {
                $this->noslot[] = $code . ' (' . count($rest) . ')';
            }
        }

        // ── FERIE (sx) e RIPOSO COMPENSATIVO (dx) ────────────────────────────────
        $fer = $this->perTipo['FER'] ?? [];
        $rc  = $this->perTipo['RC'] ?? [];
        $fi = 0; $ri = 0;
        for ($i = ($cogHdr ?? $pa) + 1; $i < $miss; $i++) {
            $byCol = $this->rowCellsByCol($rows[$i]);
            if ($fi < count($fer) && isset($byCol[self::FER_COG])) {
                $a = $fer[$fi++];
                $this->writeName($doc, $byCol[self::FER_COG], $a, !empty($a['sede_distaccata']) ? ' (' . $a['sede_distaccata'] . ')' : '');
                if (isset($byCol[self::FER_TUR])) $this->setText($doc, $byCol[self::FER_TUR], $a['nr_turni'] ? (string)(int)$a['nr_turni'] : '');
                if (isset($byCol[self::FER_DA]))  $this->setText($doc, $byCol[self::FER_DA], $a['data_da'] ? date('d/m', strtotime($a['data_da'])) : '');
                if (isset($byCol[self::FER_A]))   $this->setText($doc, $byCol[self::FER_A], $a['data_a'] ? date('d/m', strtotime($a['data_a'])) : '');
            }
            if ($ri < count($rc) && isset($byCol[self::RC_COG])) {
                $a = $rc[$ri++];
                $this->writeName($doc, $byCol[self::RC_COG], $a);
                if (isset($byCol[self::RC_VAR])) $this->setText($doc, $byCol[self::RC_VAR], $a['sede_distaccata'] ?? ($a['note'] ?? ''));
            }
        }

        // ── MISSIONE / MALATTIA (prima colonna) ──────────────────────────────────
        $this->fillLista($doc, $rows, $miss + 1, $mal, array_merge($this->perTipo['MISS'] ?? [], $this->perTipo['PERM'] ?? []));
        $this->fillLista($doc, $rows, $mal + 1, count($rows), array_merge($this->perTipo['MAL'] ?? [], $this->perTipo['INF'] ?? []));

        // ── INTESTAZIONE ─────────────────────────────────────────────────────────
        $this->fillHeader($doc, $rows, $pa);

        return $doc->saveXML();
    }

    private function fillLista(DOMDocument $doc, array $rows, int $from, int $to, array $items): void
    {
        $k = 0;
        for ($i = $from; $i < $to && $k < count($items); $i++) {
            $byCol = $this->rowCellsByCol($rows[$i]);
            if (isset($byCol[0])) { $this->writeName($doc, $byCol[0], $items[$k]); $k++; }
        }
    }

    private function fillHeader(DOMDocument $doc, array $rows, int $pa): void
    {
        // Furieri (riga subito sotto "Furieri"), Capo/Vice/Funzionario (valore dopo la label), data
        $furTxt = $this->furieri ? implode(', ', array_map([self::class, 'etichetta'], $this->furieri)) : '';
        for ($i = 0; $i < $pa; $i++) {
            $cells = $this->rowCells($rows[$i]);
            for ($j = 0; $j < count($cells); $j++) {
                [$col, $cell] = $cells[$j];
                $t = trim($cell->textContent);
                if ($t === 'Capo servizio' && isset($cells[$j+1])) $this->setText($doc, $cells[$j+1][1], $this->capo ? self::etichetta($this->capo) : '');
                elseif ($t === 'Vice capo servizi' && isset($cells[$j+1])) $this->setText($doc, $cells[$j+1][1], $this->vice ? self::etichetta($this->vice) : '');
                elseif ($t === 'Funzionario' && isset($cells[$j+1])) $this->setText($doc, $cells[$j+1][1], $this->foglio['funzionario'] ?? '');
                elseif ($t === 'Furieri' && isset($cells[$j+1])) $this->setText($doc, $cells[$j+1][1], $furTxt);
            }
        }
        // riga 3 (sotto Furieri) come fallback per i nomi furieri
        if (isset($rows[3])) {
            $bc = $this->rowCellsByCol($rows[3]);
            if (isset($bc[0]) && trim($bc[0]->textContent) === '') $this->setText($doc, $bc[0], $furTxt);
        }
    }

    /** elenco [start_col, DOMElement cell] della riga (covered contano per la colonna) */
    private function rowCells(DOMElement $row): array
    {
        $out = []; $col = 0;
        foreach ($row->childNodes as $n) {
            if ($n->nodeType !== XML_ELEMENT_NODE) continue;
            if ($n->localName === 'table-cell') {
                $out[] = [$col, $n];
                $col += max(1, (int)$n->getAttributeNS(self::TBL, 'number-columns-repeated') ?: 1);
            } elseif ($n->localName === 'covered-table-cell') {
                $col += max(1, (int)$n->getAttributeNS(self::TBL, 'number-columns-repeated') ?: 1);
            }
        }
        return $out;
    }
    private function rowCellsByCol(DOMElement $row): array
    {
        $m = [];
        foreach ($this->rowCells($row) as [$col, $cell]) $m[$col] = $cell;
        return $m;
    }

    /** scrive un nome (con colore patente) in una cella */
    private function writeName(DOMDocument $doc, DOMElement $cell, array $a, string $suffix = ''): void
    {
        $label = self::etichetta($a) . $suffix;
        $style = self::colorStyle($a['patente_max'] ?? null);
        $this->setText($doc, $cell, $label, $style);
    }

    /** accoda un nome (a capo) nell'ultima cella di un mezzo pieno (overflow) */
    private function appendName(DOMDocument $doc, DOMElement $cell, array $a): void
    {
        $p = null;
        foreach ($cell->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && $n->localName === 'p') { $p = $n; break; }
        }
        if ($p === null) { $p = $doc->createElementNS(self::TXT, 'text:p'); $cell->appendChild($p); }
        $p->appendChild($doc->createElementNS(self::TXT, 'text:line-break'));
        $label = self::etichetta($a);
        $style = self::colorStyle($a['patente_max'] ?? null);
        if ($style) {
            $span = $doc->createElementNS(self::TXT, 'text:span');
            $span->setAttributeNS(self::TXT, 'text:style-name', $style);
            $span->appendChild($doc->createTextNode($label));
            $p->appendChild($span);
        } else {
            $p->appendChild($doc->createTextNode($label));
        }
    }

    /** imposta il testo di una cella nel primo <text:p>, opz. avvolto in span colore */
    private function setText(DOMDocument $doc, DOMElement $cell, string $text, ?string $colorStyle = null): void
    {
        // trova/crea il primo text:p
        $p = null;
        foreach ($cell->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && $n->localName === 'p') { $p = $n; break; }
        }
        if ($p === null) {
            $p = $doc->createElementNS(self::TXT, 'text:p');
            $cell->appendChild($p);
        }
        while ($p->firstChild) $p->removeChild($p->firstChild);
        if ($text === '') return;
        if ($colorStyle) {
            $span = $doc->createElementNS(self::TXT, 'text:span');
            $span->setAttributeNS(self::TXT, 'text:style-name', $colorStyle);
            $span->appendChild($doc->createTextNode($text));
            $p->appendChild($span);
        } else {
            $p->appendChild($doc->createTextNode($text));
        }
    }

    private function ensureColorStyles(DOMDocument $doc): void
    {
        $auto = $doc->getElementsByTagNameNS(self::OFF, 'automatic-styles')->item(0);
        if (!$auto) return;
        $have = [];
        foreach ($auto->getElementsByTagNameNS(self::STY, 'style') as $s) $have[$s->getAttributeNS(self::STY, 'name')] = true;
        $add = ['ColRosso' => '#C00000', 'ColBlu' => '#0000C0'];
        foreach ($add as $name => $hex) {
            if (isset($have[$name])) continue;
            $st = $doc->createElementNS(self::STY, 'style:style');
            $st->setAttributeNS(self::STY, 'style:name', $name);
            $st->setAttributeNS(self::STY, 'style:family', 'text');
            $tp = $doc->createElementNS(self::STY, 'style:text-properties');
            $tp->setAttributeNS(self::FO, 'fo:color', $hex);
            $st->appendChild($tp);
            $auto->appendChild($st);
        }
    }
}
