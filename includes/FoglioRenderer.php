<?php
/**
 * FoglioRenderer — genera il Foglio di Servizio dal DB riempiendo il modulo ufficiale
 * (templates/modello.odt). Read-only sul DB. Output ODT (e in futuro HTML).
 *
 * Strategia: cammina la tabella del modello, traccia per ogni colonna il mezzo corrente
 * (dalle righe-header), e riempie dall'alto le celle sotto ogni header coi nomi del DB.
 * Niente clonazione righe: capienza = righe del modulo (come da esempio B4).
 */
require_once __DIR__ . '/ferie_blocchi.php';
require_once __DIR__ . '/format.php';   // leggiParametri/validaStilePatente/suffissoGrado condivisi col web

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
    // colonne sezione assenti (start-col nel modello).
    // FER_SIG/RC_SIG/ASSENTI_SIG = colonna sigla sede (a destra del nome, già centrata
    // in verticale nel modello; l'allineamento orizzontale lo forza setSigla()).
    const FER_COG=0, FER_SIG=4, FER_TUR=6, FER_DA=7, FER_A=9, RC_COG=12, RC_SIG=15, RC_VAR=17;
    const ASSENTI_COG=0, ASSENTI_SIG=4;

    private PDO $pdo;
    private array $foglio;
    private string $dataStr, $tipoParam, $codSaltoRip, $dataLabel, $giornoLbl;
    private string $rigaData1 = '', $rigaData2 = '';   // inizio/fine servizio in intestazione
    private array $assByCode = [], $perTipo = [], $furieri = [];
    private ?array $capo = null, $vice = null;
    private array $scambioOut = [];
    private array $visite = [];   // visite mediche del giorno (#95): 5A → "Visita Medica"
    private int $centrSedeId = 1;   // sede "Centrale": non sigla nessuno
    private int $saltoRiposoId = 0;   // id del salto a riposo di oggi (0 = non trovato)
    public array $overflow = [];   // mezzi con più nomi che slot
    public array $noslot = [];     // mezzi con nomi ma 0 slot
    private static string $formatoNome = 'standard';  // parametro admin (parametri.foglio_formato_nome)
    // Stile patenti per turno (parametri admin): colore (storico), numero accanto
    // al nome, o entrambi; tinte selezionabili ('' in tabella = tinta storica ODT).
    private static string $stilePatente = 'colore';
    private static string $rossoPat = '#C00000';
    private static string $bluPat   = '#0000C0';

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
        $this->giornoLbl = self::giornoSettimana($dt);
        require_once __DIR__ . '/turni.php';
        $this->codSaltoRip = ($f['turno'] ?: 'B')
            . saltoRiposoNum($this->dataStr, $this->tipoParam);   // turno del foglio (multi-turno)
        self::caricaParametri($pdo, $f['turno'] ?: 'B');

        // Intestazione: inizio/fine servizio. Diurno: 8→20 stesso giorno.
        // Notturno: 20 del giorno → 8 del giorno dopo.
        $dtFine = (clone $dt)->modify('+1 day');
        if ($this->tipoParam === 'D') {
            $this->rigaData1 = self::dataEstesa($dt)     . ', ore 8.00';
            $this->rigaData2 = self::dataEstesa($dt)     . ', ore 20.00';
        } else {
            $this->rigaData1 = self::dataEstesa($dt)     . ', ore 20.00';
            $this->rigaData2 = self::dataEstesa($dtFine) . ', ore 8.00';
        }
        $this->loadData($foglioId);
    }

    private static function giornoSettimana(DateTime $d): string
    {
        $gg = ['Sunday'=>'Domenica','Monday'=>'Lunedì','Tuesday'=>'Martedì','Wednesday'=>'Mercoledì',
               'Thursday'=>'Giovedì','Friday'=>'Venerdì','Saturday'=>'Sabato'];
        return $gg[$d->format('l')] ?? '';
    }

    private static function dataEstesa(DateTime $d): string
    {
        $mm = [1=>'Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto',
               'Settembre','Ottobre','Novembre','Dicembre'];
        return self::giornoSettimana($d) . ' ' . (int)$d->format('d') . ' '
             . ($mm[(int)$d->format('n')] ?? '') . ' ' . $d->format('Y');
    }

    private function loadData(int $foglioId): void
    {
        $this->centrSedeId = (int)($this->pdo->query("SELECT id FROM sedi WHERE codice='C'")->fetchColumn() ?: 1);
        $this->saltoRiposoId = (int)($this->pdo->query(
            "SELECT id FROM salti_turno WHERE codice=" . $this->pdo->quote($this->codSaltoRip)
        )->fetchColumn() ?: 0);
        // scambi salto attivi di oggi: chi ha ceduto il proprio riposo (vigile_out)
        // lavora regolarmente oggi per accordo — non è un richiamo straordinario
        // dell'ultimo momento, va calcolato PRIMA di leggere le assegnazioni.
        $st = $this->pdo->prepare("SELECT vigile_out_id FROM salto_override WHERE data=? AND tipo=? AND attivo=1");
        $st->execute([$this->dataStr, $this->tipoParam]);
        foreach ($st->fetchAll() as $r) $this->scambioOut[(int)$r['vigile_out_id']] = true;
        $pat = "(SELECT MAX(p.tipo) FROM vigili_patenti vp JOIN patenti p ON p.id=vp.patente_id WHERE vp.vigile_id=v.id)";
        // assegnazioni per codice posizione
        $idByCode = [];
        foreach ($this->pdo->query("SELECT id,codice FROM posizioni")->fetchAll() as $p) $idByCode[(int)$p['id']] = $p['codice'];
        $st = $this->pdo->prepare(
            "SELECT a.posizione_id, a.ordine, a.in_straordinario, a.vigile_id, v.cognome, v.disambiguatore, v.salto_id,
                    v.specialista, q.codice AS qcodice, $pat AS patente_max,
                    v.sede_id AS vig_sede, p.sede_id AS pos_sede, s.codice AS sede_cod
             FROM assegnazioni a JOIN vigili v ON v.id=a.vigile_id JOIN qualifiche q ON q.id=v.qualifica_id
             JOIN posizioni p ON p.id=a.posizione_id JOIN sedi s ON s.id=v.sede_id
             WHERE a.foglio_id=? ORDER BY a.posizione_id, a.ordine");
        $st->execute([$foglioId]);
        foreach ($st->fetchAll() as $a) {
            $code = $idByCode[(int)$a['posizione_id']] ?? null;
            if (!$code) continue;
            // fuori sede = sempre sottolineato; sigla testuale = sede di appartenenza,
            // MA mai per chi è di Centrale (niente "C" scritto, in nessun caso).
            $a['fuori_sede'] = ((int)$a['vig_sede'] !== (int)$a['pos_sede']);
            $a['sigla'] = ($a['fuori_sede'] && (int)$a['vig_sede'] !== $this->centrSedeId) ? $a['sede_cod'] : null;
            // chi è nel salto a riposo di oggi ma è comunque assegnato in squadra
            // (richiamato) va trattato come straordinario, flag manuale o no —
            // MA non se il suo riposo di oggi è stato ceduto per cambio salto
            // (in tal caso lavora regolarmente, non è un richiamo).
            if ($this->saltoRiposoId > 0 && (int)($a['salto_id'] ?? 0) === $this->saltoRiposoId
                && !isset($this->scambioOut[(int)$a['vigile_id']])) {
                $a['in_straordinario'] = 1;
            }
            $this->assByCode[$code][] = $a;
        }
        // Nomi esterni al turno (non in `vigili`): si accodano alla posizione, poi
        // vengono riordinati insieme ai vigili per `ordine` (stessa colonna di
        // posizione sul gestionale) così l'ODT rispetta la stessa sequenza vista a
        // schermo — prima erano sempre in fondo, dopo tutti i vigili.
        // Tabella creata da foglio/nuovo.php; potrebbe non esistere su DB vecchi.
        try {
            $stx = $this->pdo->prepare(
                "SELECT posizione_id, ordine, nome, in_straordinario, patente_forzata
                   FROM assegnazioni_esterni WHERE foglio_id=? ORDER BY posizione_id, ordine"
            );
            $stx->execute([$foglioId]);
            foreach ($stx->fetchAll() as $e) {
                $code = $idByCode[(int)$e['posizione_id']] ?? null;
                if (!$code) continue;
                $this->assByCode[$code][] = [
                    'nome_libero'      => $e['nome'],
                    'ordine'           => (int)$e['ordine'],
                    'in_straordinario' => (int)$e['in_straordinario'],
                    // #204: sul foglio web il colore forzato colora già questi nomi
                    // (foglio/nuovo.php), qui mancava del tutto — sempre neri nell'odt.
                    'patente_max'      => $e['patente_forzata'] ?: null,
                    'sigla'            => null,
                ];
            }
        } catch (Throwable $ignored) { /* tabella assente: nessun esterno */ }
        foreach ($this->assByCode as $code => $list) {
            usort($list, fn($a, $b) => ((int)($a['ordine'] ?? 0)) <=> ((int)($b['ordine'] ?? 0)));
            $this->assByCode[$code] = $list;
        }
        // assenze per tipo
        $st = $this->pdo->prepare(
            "SELECT a.*, v.cognome, v.disambiguatore, v.specialista, q.codice AS qcodice, ta.codice AS tipo_codice, $pat AS patente_max,
                    v.sede_id AS vig_sede, s.codice AS sede_cod
             FROM assenze a JOIN vigili v ON v.id=a.vigile_id JOIN qualifiche q ON q.id=v.qualifica_id
             JOIN tipo_assenza ta ON ta.id=a.tipo_assenza_id JOIN sedi s ON s.id=v.sede_id
             WHERE a.foglio_id=? ORDER BY ta.id, v.cognome");
        $st->execute([$foglioId]);
        foreach ($st->fetchAll() as $a) {
            $a['sigla'] = $this->siglaAssenza($a);
            $this->perTipo[$a['tipo_codice']][] = $a;
        }
        $this->arricchisciFerie();   // nr_turni + periodo da–a sulle righe FER
        // capo / vice / furieri
        $this->capo = $this->vigById($this->foglio['capo_servizio_id'] ?? null);
        $this->vice = $this->vigById($this->foglio['vice_capo_id'] ?? null);
        $st = $this->pdo->prepare("SELECT v.cognome,v.disambiguatore,q.codice AS qcodice FROM foglio_furieri ff JOIN vigili v ON v.id=ff.vigile_id JOIN qualifiche q ON q.id=v.qualifica_id WHERE ff.foglio_id=? ORDER BY v.cognome");
        $st->execute([$foglioId]);
        $this->furieri = $st->fetchAll();

        // Visite mediche (#95), solo Diurni: la casella 5A diventa "Visita Medica"
        // e ospita i nomi in visita (duplicati: restano anche nella loro squadra).
        if ($this->tipoParam === 'D') {
            try {
                $st = $this->pdo->prepare(
                    "SELECT vm.vigile_id, v.cognome, v.disambiguatore, v.specialista, q.codice AS qcodice, $pat AS patente_max
                     FROM visite_mediche vm
                     JOIN vigili v     ON v.id = vm.vigile_id
                     JOIN qualifiche q ON q.id = v.qualifica_id
                     WHERE vm.data=? AND v.turno=? AND v.attivo=1 ORDER BY v.cognome");
                $st->execute([$this->dataStr, $this->foglio['turno'] ?: 'B']);
                $this->visite = $st->fetchAll();
            } catch (Throwable $ignored) { /* tabella assente: nessuna visita */ }
        }

        // SALTO TURNO = Riposo Compensativo: i riposanti effettivi vanno nella sezione RC.
        $this->aggiungiSaltoInRC($pat);
    }

    /** I riposanti del salto (canonico ± scambi, esclusi assegnati/assenti) → sezione RC */
    private function aggiungiSaltoInRC(string $pat): void
    {
        $saltoRiposoId = $this->saltoRiposoId;
        if (!$saltoRiposoId) return;

        // base: vigili attivi col salto di riposo di oggi
        $st = $this->pdo->prepare(
            "SELECT v.id, v.cognome, v.disambiguatore, v.specialista, q.codice AS qcodice, $pat AS patente_max,
                    v.sede_id AS vig_sede, s.codice AS sede_cod
             FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id JOIN sedi s ON s.id=v.sede_id
             WHERE v.attivo=1 AND v.salto_id=?");
        $st->execute([$saltoRiposoId]);
        $resters = [];
        foreach ($st->fetchAll() as $r) { $r['sigla'] = $this->siglaAssenza($r); $resters[(int)$r['id']] = $r; }

        // scambi: out esce, in entra con dicitura "Riposa per <out>"
        $st = $this->pdo->prepare("SELECT vigile_out_id, vigile_in_id FROM salto_override WHERE data=? AND tipo=? AND attivo=1");
        $st->execute([$this->dataStr, $this->tipoParam]);
        foreach ($st->fetchAll() as $o) {
            unset($resters[(int)$o['vigile_out_id']]);
            $vin = $this->vigFull((int)$o['vigile_in_id'], $pat);
            if ($vin) {
                $out = $this->vigFull((int)$o['vigile_out_id'], $pat);
                $vin['note'] = $out ? 'Riposa per ' . self::etichetta($out) : null;
                $resters[(int)$vin['id']] = $vin;
            }
        }

        // escludi chi è assegnato (in servizio) o assente (ferie/missione/...).
        // assByCode può contenere esterni al turno (nome_libero, senza vigile_id):
        // non sono vigili del salto, vanno ignorati qui. Chi è richiamato in
        // STRAORDINARIO invece resta nel riposo compensativo: va scritto in
        // entrambi i posti (squadra + elenco salto), non spostato dall'uno
        // all'altro — la sua riga qui viene marcata per lo stesso stile
        // grassetto/evidenziato che ha già in squadra.
        $occupati = []; $straordSet = [];
        foreach ($this->assByCode as $list) foreach ($list as $a) {
            if (empty($a['vigile_id'])) continue;
            if (!empty($a['in_straordinario'])) { $straordSet[(int)$a['vigile_id']] = true; continue; }
            $occupati[(int)$a['vigile_id']] = true;
        }
        foreach ($this->perTipo as $list) foreach ($list as $a) { if (!empty($a['vigile_id'])) $occupati[(int)$a['vigile_id']] = true; }
        // capo/vice servizio: trattati come le squadre — se richiamati dal riposo
        // di oggi (stesso controllo cambio-salto di inSaltoRiposo()) vanno anche
        // evidenziati nell'elenco RC, non solo nella loro casella in intestazione.
        foreach ([$this->capo, $this->vice] as $cv) {
            if ($cv !== null && !empty($cv['id']) && $this->inSaltoRiposo($cv)) {
                $straordSet[(int)$cv['id']] = true;
            }
        }

        foreach ($resters as $vid => $r) {
            if (isset($occupati[$vid])) continue;
            $r['sede_distaccata'] = $r['sede_distaccata'] ?? null;
            $r['note'] = $r['note'] ?? null;   // preserva "Riposa per <out>" se impostato
            if (isset($straordSet[$vid])) $r['in_straordinario'] = 1;
            $this->perTipo['RC'][] = $r;
        }
        if (!empty($this->perTipo['RC'])) {
            usort($this->perTipo['RC'], fn($a, $b) => strcmp($a['cognome'] ?? '', $b['cognome'] ?? ''));
        }
    }

    private function vigFull(int $id, string $pat): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT v.id, v.cognome, v.disambiguatore, v.specialista, q.codice AS qcodice, $pat AS patente_max,
                    v.sede_id AS vig_sede, s.codice AS sede_cod
             FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id JOIN sedi s ON s.id=v.sede_id WHERE v.id=?");
        $st->execute([$id]);
        $r = $st->fetch() ?: null;
        if ($r) $r['sigla'] = $this->siglaAssenza($r);
        return $r;
    }

    /**
     * Per ogni riga FERIE calcola nr turni + periodo (da–a) del blocco contiguo
     * di ferie del vigile che contiene la data di questo foglio. Stessa logica
     * dell'Agenda (includes/ferie_blocchi.php): un blocco = richieste con gap
     * <= 3 giorni; DN vale 2 turni.
     *
     * Fonte: assenze+fogli_servizio (tipo_assenza_id=1), non bot_requests — le
     * ferie assegnate d'ufficio direttamente dal foglio (azione 'ferie_ufficio'
     * in nuovo.php) creano l'assenza ma NESSUNA riga in bot_requests, quindi
     * restavano sempre senza turni/periodo. L'assenza copre sempre entrambe le
     * origini (bot e d'ufficio) ed è sempre presente/coerente: una richiesta
     * respinta non ha mai assenza (rimossa da feriaDeleteAssenza), quindi qui
     * non serve isolare uno stato "respinto" come fa bot_requests.
     */
    private function arricchisciFerie(): void
    {
        if (empty($this->perTipo['FER'])) return;
        $req = $this->pdo->prepare(
            "SELECT f.data_servizio AS data_richiesta, f.tipo_turno
               FROM assenze a JOIN fogli_servizio f ON f.id = a.foglio_id
              WHERE a.vigile_id=? AND a.tipo_assenza_id=1
              ORDER BY f.data_servizio, f.tipo_turno"
        );
        // #183 (4°): evidenziatore ferie estive/d'ufficio sull'odt — 'estiva' viene
        // da bot_requests.ferie_estiva; 'ufficio' quando NON esiste nessuna richiesta
        // bot per questo vigile/giorno/turno (assenza creata solo sul foglio).
        $estUff = $this->pdo->prepare(
            "SELECT ferie_estiva FROM bot_requests
              WHERE vigile_id=? AND data_richiesta=? AND tipo_turno IN (?,'DN') AND tipo_assenza_id=1
              ORDER BY id DESC LIMIT 1"
        );
        foreach ($this->perTipo['FER'] as $i => $a) {
            $req->execute([(int)$a['vigile_id']]);
            $righe = array_map(fn($r) => $r + ['stato' => 'approved'], $req->fetchAll());
            $blocchi = blocchiContigui($righe);
            foreach ($blocchi as $b) {
                $da = $b[0]['data_richiesta'];
                $aa = end($b)['data_richiesta'];
                if ($this->dataStr >= $da && $this->dataStr <= $aa) {
                    $this->perTipo['FER'][$i]['nr_turni'] = turniLabel($b);
                    $this->perTipo['FER'][$i]['data_da']  = $da;
                    $this->perTipo['FER'][$i]['data_a']   = $aa;
                    break;
                }
            }
            $estUff->execute([(int)$a['vigile_id'], $this->dataStr, $this->tipoParam]);
            $estivaVal = $estUff->fetchColumn();
            if ($estivaVal === false) {
                $this->perTipo['FER'][$i]['ferie_ufficio'] = true;
            } elseif ((int)$estivaVal === 1) {
                $this->perTipo['FER'][$i]['ferie_estiva'] = true;
            }
        }
    }

    /** sigla per le sezioni assenti: distacco manuale, o sede di appartenenza (se non Centrale) */
    private function siglaAssenza(array $a): ?string
    {
        if (!empty($a['sede_distaccata'])) return $a['sede_distaccata'];          // override manuale
        return ((int)($a['vig_sede'] ?? 0) !== $this->centrSedeId) ? ($a['sede_cod'] ?? null) : null;
    }

    /** true se il vigile (capo/vice) ha oggi il salto a riposo ed è stato richiamato in
     *  straordinario — non conta se il riposo di oggi è stato ceduto per cambio salto. */
    private function inSaltoRiposo(?array $v): bool
    {
        return $v !== null && $this->saltoRiposoId > 0 && (int)($v['salto_id'] ?? 0) === $this->saltoRiposoId
            && !isset($this->scambioOut[(int)($v['id'] ?? 0)]);
    }

    private function vigById($id): ?array
    {
        if (!$id) return null;
        $st = $this->pdo->prepare("SELECT v.id,v.cognome,v.disambiguatore,v.salto_id,q.codice AS qcodice FROM vigili v JOIN qualifiche q ON q.id=v.qualifica_id WHERE v.id=?");
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    /** Legge il formato nome dai parametri (resiliente: default 'standard' se manca). */
    /** Parametri admin del foglio in un giro solo: formato nome + stile patenti
     *  del turno (helper condivisi col web in includes/format.php). */
    private static function caricaParametri(PDO $pdo, string $turno): void
    {
        self::$formatoNome  = 'standard';
        self::$stilePatente = 'colore';
        self::$rossoPat = '#C00000';
        self::$bluPat   = '#0000C0';
        $p = leggiParametri($pdo, array_merge(['foglio_formato_nome'], chiaviStilePatente($turno)));
        $fmt = $p['foglio_formato_nome'] ?? '';
        if (in_array($fmt, ['standard', 'cognome_maiusc', 'tutto_maiusc'], true)) self::$formatoNome = $fmt;
        $v = validaStilePatente($p, $turno);
        if ($v['stile'] !== null) self::$stilePatente = $v['stile'];
        if ($v['rosso'] !== null) self::$rossoPat = $v['rosso'];
        if ($v['blu']   !== null) self::$bluPat   = $v['blu'];
    }

    /** " 3°" accanto al nome (stili numero/entrambi), '' altrimenti. */
    private static function suffissoPat(?string $t): string
    {
        return suffissoGrado($t, self::$stilePatente);
    }

    /** Maiuscola la prima lettera di ogni parola (spazi/apostrofi/trattini fanno da separatore): "d'amato" -> "D'Amato". */
    private static function capitalizzaParole(string $s): string
    {
        return (string)preg_replace_callback('/\p{L}+/u', function ($m) {
            return mb_strtoupper(mb_substr($m[0], 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($m[0], 1, null, 'UTF-8');
        }, $s);
    }

    /** Nome funzionario: in anagrafica è salvato tutto maiuscolo (es. "IA BAGHINO"),
     *  qui va nello stesso stile degli altri nomi sul foglio — stessa regola usata
     *  per i nomi esterni al turno (etichetta(), campo nome_libero). */
    /** #183 (2°): apostrofo dritto ' → tipografico ’ nei nomi (es. D'Amato), per
     *  compatibilità col file ferie LibreOffice ancora in uso. */
    private static function apostrofoTipografico(string $s): string
    {
        return str_replace("'", '’', $s);
    }

    private static function formattaFunzionario(string $nome): string
    {
        $nome = trim($nome);
        if ($nome === '') return '';
        $out = self::$formatoNome === 'tutto_maiusc'
            ? mb_strtoupper($nome, 'UTF-8')
            : self::capitalizzaParole(mb_strtolower($nome, 'UTF-8'));
        return self::apostrofoTipografico($out);
    }

    private static function etichetta(array $v): string
    {
        if (isset($v['nome_libero'])) {   // esterno al turno: stesso stile testo dei vigili
            $n = trim((string)$v['nome_libero']);
            $out = self::$formatoNome === 'tutto_maiusc'
                ? mb_strtoupper($n, 'UTF-8')
                : self::capitalizzaParole(mb_strtolower($n, 'UTF-8'));
            return self::apostrofoTipografico($out);
        }
        $q = $v['qcodice'] ?? '';
        $c = $v['cognome'] ?? '';
        switch (self::$formatoNome) {
            case 'cognome_maiusc':   // es. "Cs ROSSI"
                $q = ucfirst(strtolower($q)); $c = mb_strtoupper($c, 'UTF-8'); break;
            case 'tutto_maiusc':     // es. "CS ROSSI"
                $q = mb_strtoupper($q, 'UTF-8'); $c = mb_strtoupper($c, 'UTF-8'); break;
            default:                 // 'standard' — es. "Cs D'Amato"
                $q = ucfirst(strtolower($q)); $c = self::capitalizzaParole(mb_strtolower($c, 'UTF-8')); break;
        }
        return self::apostrofoTipografico(trim("$q $c")
             . (!empty($v['disambiguatore']) ? ' ' . (int)$v['disambiguatore'] : ''));
    }
    /**
     * Stile testo: colore patente + straordinario (giallo + grassetto) + sottolineato
     * (in servizio fuori sede) + evidenziato (ferie estiva/d'ufficio, solo lista ferie).
     * Ritorna SEMPRE uno stile esplicito, anche per il caso "nessun colore speciale":
     * senza, il nome eredita la formattazione della cella del modello.odt, che non è
     * uniforme cella per cella — un nome nero spostato in un'altra casella poteva
     * uscire blu o rosso (#67).
     *
     * $specialista: #185, solo odt — per gli specialisti la patente non conta, sempre
     * nero indipendentemente dal grado (il foglio web resta come oggi, non è toccato).
     */
    private static function nameStyle(?string $t, bool $straord, bool $und = false,
                                       bool $specialista = false, bool $evidenziato = false): string
    {
        $col = ($t === '3' || $t === '4') ? 'Rosso' : ($t === '2' ? 'Blu' : '');
        if (self::$stilePatente === 'numero') $col = '';   // il grado lo dice il suffisso
        if ($specialista) $col = '';
        return 'Nm' . ($straord ? 'S' : '') . ($und ? 'U' : '') . ($evidenziato ? 'E' : '') . $col;
        // es: Nm, NmRosso, NmS, NmSURosso, NmEBlu…
    }

    private function modelPath(): string { return __DIR__ . '/../templates/modello.odt'; }

    /**
     * Capienza (n. slot nome) per ogni posizione, letta dal modello.odt — stessa
     * geometria usata dal riempimento ODT. Ritorna [codice_posizione => n_slot].
     * Statica: dipende solo dal modulo, non dai dati del foglio.
     */
    public static function slotCapacities(): array
    {
        $path = __DIR__ . '/../templates/modello.odt';
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return [];
        $xml = $zip->getFromName('content.xml');
        $zip->close();
        if ($xml === false) return [];
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->loadXML($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('table', self::TBL);
        $table = $xp->query('//table:table')->item(0);
        if (!$table) return [];

        $rows = [];
        foreach ($table->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && $n->localName === 'table-row') $rows[] = $n;
        }
        // area servizio = righe prima di "PERSONALE ASSENTE"
        $pa = null;
        foreach ($rows as $i => $r) {
            if (strpos(trim($r->textContent), 'PERSONALE ASSENTE') !== false) { $pa = $i; break; }
        }
        $pa = $pa ?? count($rows);

        $colCode = [];   // colonna → codice mezzo corrente
        $cap = [];       // codice → n. slot
        for ($i = 0; $i < $pa; $i++) {
            $col = 0;
            foreach ($rows[$i]->childNodes as $n) {
                if ($n->nodeType !== XML_ELEMENT_NODE) continue;
                if ($n->localName === 'covered-table-cell') {
                    $col += max(1, (int)$n->getAttributeNS(self::TBL, 'number-columns-repeated') ?: 1);
                    continue;
                }
                if ($n->localName !== 'table-cell') continue;
                $txt = trim($n->textContent);
                if ($txt !== '' && isset(self::HDR2CODE[$txt])) {
                    $colCode[$col] = self::HDR2CODE[$txt];
                } elseif ($txt === '' && isset($colCode[$col])) {
                    $code = $colCode[$col];
                    $cap[$code] = ($cap[$code] ?? 0) + 1;
                }
                $col += max(1, (int)$n->getAttributeNS(self::TBL, 'number-columns-repeated') ?: 1);
            }
        }
        return $cap;
    }

    /** carica content.xml del modello, lo riempie dal DB, ritorna il DOMDocument */
    private function buildFilledDoc(): DOMDocument
    {
        $zip = new ZipArchive();
        if ($zip->open($this->modelPath()) !== true) throw new RuntimeException('modello.odt non apribile');
        $xml = $zip->getFromName('content.xml');
        $zip->close();
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->loadXML($xml);
        $this->fill($doc);
        return $doc;
    }

    /** Genera i bytes .odt */
    public function odt(): string
    {
        $doc = $this->buildFilledDoc();
        $xml = $doc->saveXML();
        $tmp = tempnam(sys_get_temp_dir(), 'odt');
        copy($this->modelPath(), $tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new RuntimeException('modello.odt non apribile');
        $zip->deleteName('content.xml');
        $zip->addFromString('content.xml', $xml);
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /** Riempie il DOMDocument del modello coi dati DB (mezzi, assenti, intestazione) */
    private function fill(DOMDocument $doc): void
    {
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
        // Riempimento sequenziale dall'alto: non segue la riga esatta vista a
        // schermo (che può avere buchi voluti), l'ODT compatta sempre in cima.
        // Scelta esplicita: la stampa deve restare pulita, i buchi sono solo
        // uno strumento di lavoro sullo schermo.
        $colCode  = [];         // start-col → codice mezzo corrente
        $queue    = [];         // codice → lista nomi DB da piazzare (in ordine)
        $lastCell = [];         // codice → ultima cella riempita (per overflow)
        foreach ($this->assByCode as $code => $list) { $queue[$code] = $list; }
        // #95: i nomi in visita medica vanno nella casella 5A (dopo eventuali
        // assegnati a mano; di norma la 5A non si forma nei giorni con visite)
        if ($this->visite) $queue['5A'] = array_merge($queue['5A'] ?? [], $this->visite);

        for ($i = 0; $i < $pa; $i++) {
            $cells = $this->rowCells($rows[$i]);
            foreach ($cells as $j => [$col, $cell]) {
                $txt = trim($cell->textContent);
                if ($txt !== '' && isset(self::HDR2CODE[$txt])) {      // header mezzo
                    $colCode[$col] = self::HDR2CODE[$txt];
                    // #95: con visite in corso l'intestazione 5A cambia nome
                    if ($txt === '5A' && $this->visite) $this->setText($doc, $cell, 'Visita Medica');
                    continue;
                }
                // cella vuota sotto un mezzo → piazza prossimo nome
                if ($txt === '' && isset($colCode[$col])) {
                    $code = $colCode[$col];
                    if (!empty($queue[$code])) {
                        $a = array_shift($queue[$code]);
                        // fuori sede: sigla nella colonna adiacente se il modello ne
                        // prevede una per questo mezzo (cella stretta, subito dopo,
                        // non assegnata ad alcun mezzo — non tutti i mezzi ce l'hanno:
                        // fallback in coda al nome, come prima, se manca) + nome SEMPRE
                        // sottolineato se fuori sede, sigla o no (mai per chi è di Centrale)
                        $sfx = '';
                        $siglaCell = null;
                        if (!empty($a['sigla'])) {
                            $next = $cells[$j + 1] ?? null;
                            if ($next !== null) {
                                [$ncol, $ncell] = $next;
                                $span = max(1, (int)$ncell->getAttributeNS(self::TBL, 'number-columns-spanned') ?: 1);
                                if (trim($ncell->textContent) === '' && !isset($colCode[$ncol]) && $span <= 2) {
                                    $siglaCell = $ncell;
                                }
                            }
                            if ($siglaCell === null) $sfx = ' ' . $a['sigla'];
                        }
                        $this->writeName($doc, $cell, $a, $sfx, '', !empty($a['fuori_sede']));
                        if ($siglaCell !== null) $this->setSigla($doc, $siglaCell, $a['sigla']);
                        $lastCell[$code] = $cell;
                    }
                }
            }
        }
        // nomi in eccedenza: accodali all'ultima cella del mezzo (niente perdita dati)
        foreach ($queue as $code => $rest) {
            if (empty($rest)) continue;
            if (isset($lastCell[$code])) {
                foreach ($rest as $a) $this->appendName($doc, $lastCell[$code], $a, !empty($a['fuori_sede']));
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
                // sigla sede nella colonna dedicata (a destra del nome, centrata), non in coda al nome
                $this->writeName($doc, $byCol[self::FER_COG], $a);
                if (isset($byCol[self::FER_SIG])) $this->setSigla($doc, $byCol[self::FER_SIG], $a['sigla'] ?? '');
                if (isset($byCol[self::FER_TUR])) $this->setText($doc, $byCol[self::FER_TUR], $a['nr_turni'] ? (string)(int)$a['nr_turni'] : '');
                if (isset($byCol[self::FER_DA]))  $this->setText($doc, $byCol[self::FER_DA], $a['data_da'] ? date('j', strtotime($a['data_da'])) : '');
                if (isset($byCol[self::FER_A]))   $this->setText($doc, $byCol[self::FER_A], $a['data_a'] ? date('j', strtotime($a['data_a'])) : '');
            }
            if ($ri < count($rc) && isset($byCol[self::RC_COG])) {
                $a = $rc[$ri++];
                $this->writeName($doc, $byCol[self::RC_COG], $a);
                if (isset($byCol[self::RC_SIG])) $this->setSigla($doc, $byCol[self::RC_SIG], $a['sigla'] ?? '');
                if (isset($byCol[self::RC_VAR])) $this->setText($doc, $byCol[self::RC_VAR], $a['note'] ?? '');  // solo "Riposa per X"
            }
        }

        // ── MISSIONE / MALATTIA (prima colonna) ──────────────────────────────────
        $this->fillLista($doc, $rows, $miss + 1, $mal, array_merge($this->perTipo['MISS'] ?? [], $this->perTipo['PERM'] ?? []));
        $this->fillLista($doc, $rows, $mal + 1, count($rows), array_merge($this->perTipo['MAL'] ?? [], $this->perTipo['INF'] ?? []));

        // ── INTESTAZIONE ─────────────────────────────────────────────────────────
        $this->fillHeader($doc, $rows, $pa);
    }

    private function fillLista(DOMDocument $doc, array $rows, int $from, int $to, array $items): void
    {
        $k = 0;
        for ($i = $from; $i < $to && $k < count($items); $i++) {
            $byCol = $this->rowCellsByCol($rows[$i]);
            if (isset($byCol[self::ASSENTI_COG])) {
                $it = $items[$k++];
                // sigla sede nella colonna dedicata (a destra del nome, come in FERIE), non in coda al nome
                $this->writeName($doc, $byCol[self::ASSENTI_COG], $it);
                if (isset($byCol[self::ASSENTI_SIG])) $this->setSigla($doc, $byCol[self::ASSENTI_SIG], $it['sigla'] ?? '');
            }
        }
    }

    private function fillHeader(DOMDocument $doc, array $rows, int $pa): void
    {
        $furTxt = $this->furieri ? implode(', ', array_map([self::class, 'etichetta'], $this->furieri)) : '';
        $dateCells = [];
        for ($i = 0; $i < $pa; $i++) {
            $cells = $this->rowCells($rows[$i]);
            for ($j = 0; $j < count($cells); $j++) {
                [$col, $cell] = $cells[$j];
                $t = trim($cell->textContent);
                if ($t === '') continue;
                // badge salto: segnaposto "ST" (Salto Turno) nel modello pulito, o un
                // esempio tipo "B4"/"A4" lasciato in una copia già compilata — in
                // entrambi i casi va sostituito col salto vero del giorno (es. A7).
                if (preg_match('/^(ST|[A-D]\s*\d+)$/i', $t)) { $this->setText($doc, $cell, $this->codSaltoRip); continue; }
                if (stripos($t, 'ore') !== false) { $dateCells[] = [$i, $cell]; continue; }                     // riga data
                $tl = mb_strtolower($t);
                // stile esplicito anche qui (mai ereditato dalla cella, #67): capo/vice
                // in straordinario se richiamati dal salto a riposo di oggi (#92).
                if (strpos($tl, 'vice') !== false && strpos($tl, 'capo') !== false && isset($cells[$j+1]))
                    $this->setText($doc, $cells[$j+1][1], $this->vice ? self::etichetta($this->vice) : '',
                        self::nameStyle(null, $this->inSaltoRiposo($this->vice)));
                elseif (strpos($tl, 'capo servizio') !== false && isset($cells[$j+1]))
                    $this->setText($doc, $cells[$j+1][1], $this->capo ? self::etichetta($this->capo) : '',
                        self::nameStyle(null, $this->inSaltoRiposo($this->capo)));
                elseif (strpos($tl, 'funzionario') !== false && isset($cells[$j+1]))
                    $this->setText($doc, $cells[$j+1][1], self::formattaFunzionario($this->foglio['funzionario'] ?? ''),
                        self::nameStyle(null, false));
            }
        }
        // due righe data: la più in alto = inizio servizio, la sotto = fine
        usort($dateCells, fn($a, $b) => $a[0] <=> $b[0]);
        // peso normale esplicito: la 2ª cella data nel modello eredita il grassetto
        if (isset($dateCells[0])) $this->setText($doc, $dateCells[0][1], $this->rigaData1, 'NmReg');
        if (isset($dateCells[1])) $this->setText($doc, $dateCells[1][1], $this->rigaData2, 'NmReg');
        // nomi furieri: riga 3, prima cella
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

    /** scrive un nome (colore patente + giallo straord. + sottolineato se fuori sede) in una cella */
    private function writeName(DOMDocument $doc, DOMElement $cell, array $a, string $suffix = '', string $prefix = '', bool $underline = false): void
    {
        $label = $prefix . self::etichetta($a) . self::suffissoPat($a['patente_max'] ?? null) . $suffix;
        $style = self::nameStyle($a['patente_max'] ?? null, !empty($a['in_straordinario']), $underline,
            !empty($a['specialista']), !empty($a['ferie_estiva']) || !empty($a['ferie_ufficio']));
        $this->setText($doc, $cell, $label, $style);
    }

    /** accoda un nome (a capo) nell'ultima cella di un mezzo pieno (overflow) */
    private function appendName(DOMDocument $doc, DOMElement $cell, array $a, bool $underline = false): void
    {
        $p = null;
        foreach ($cell->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && $n->localName === 'p') { $p = $n; break; }
        }
        if ($p === null) { $p = $doc->createElementNS(self::TXT, 'text:p'); $cell->appendChild($p); }
        $p->appendChild($doc->createElementNS(self::TXT, 'text:line-break'));
        $label = self::etichetta($a) . self::suffissoPat($a['patente_max'] ?? null)
               . (!empty($a['sigla']) ? ' ' . $a['sigla'] : '');
        $style = self::nameStyle($a['patente_max'] ?? null, !empty($a['in_straordinario']), $underline,
            !empty($a['specialista']));
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

    /** scrive una sigla in una colonna dedicata: verticale-centro (già nella cella
     *  del modello) + orizzontale-destra (forzato: non tutte le celle-sigla del
     *  modello ce l'hanno già impostato, es. nell'area servizio). */
    private function setSigla(DOMDocument $doc, DOMElement $cell, string $text): void
    {
        $this->setText($doc, $cell, $text);
        if ($text === '') return;
        foreach ($cell->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && $n->localName === 'p') {
                $n->setAttributeNS(self::TXT, 'text:style-name', 'PSiglaDx');
                break;
            }
        }
    }

    // ── ANTEPRIMA HTML (stesso modello → identico all'ODT) ───────────────────────

    /** Pagina HTML stampabile: converte la tabella ODF riempita in HTML fedele. */
    public function html(): string
    {
        $doc = $this->buildFilledDoc();
        $tab = $this->tableToHtml($doc);
        $titolo = htmlspecialchars($this->giornoLbl . ' ' . $this->dataLabel . ' ' . $this->tipoParam);
        return '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">'
            . '<title>Foglio di Servizio — ' . $titolo . '</title><style>'
            . 'body{margin:0;background:#e9eaed;font-family:Arial,"Liberation Sans",sans-serif}'
            . '.toolbar{position:sticky;top:0;z-index:10;background:#1f2937;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center}'
            . '.toolbar .sp{flex:1}.toolbar button,.toolbar a{font:inherit;font-size:13px;border:none;border-radius:6px;padding:8px 14px;cursor:pointer;text-decoration:none}'
            . '.tb-print{background:#0a58ca;color:#fff}.tb-close{background:#374151;color:#fff}.toolbar .meta{font-size:13px;opacity:.85}'
            . '.sheet{width:210mm;min-height:297mm;margin:14px auto;background:#fff;padding:8mm 7mm;box-shadow:0 2px 12px rgba(0,0,0,.2)}'
            . 'table.foglio{border-collapse:collapse;table-layout:fixed;width:100%;font-size:9.5px}'
            . 'table.foglio td{overflow:hidden;padding:0 2px;word-wrap:break-word}'
            . '@media print{body{background:#fff}.toolbar{display:none}.sheet{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none}@page{size:A4 portrait;margin:8mm}}'
            . '</style></head><body>'
            . '<div class="toolbar"><strong>Anteprima di stampa</strong>'
            . '<span class="meta">— ' . $titolo . ' · salto ' . htmlspecialchars($this->codSaltoRip) . '</span>'
            . '<span class="sp"></span>'
            . '<button class="tb-print" onclick="window.print()">🖨️ Stampa</button>'
            // #169: prima era un link "Torna al foglio" che navigava DENTRO
            // questa stessa scheda (aperta con target=_blank) verso il
            // gestionale, lasciandone due identiche aperte nel browser.
            // window.close() chiude davvero la scheda — funziona perché ora
            // viene aperta via window.open() (vedi foglio/nuovo.php), non più
            // con un link semplice target=_blank.
            . '<button class="tb-close" onclick="window.close()">✖️ Chiudi anteprima</button></div>'
            . '<div class="sheet">' . $tab . '</div></body></html>';
    }

    /** Converte la table:table ODF (riempita) in una <table> HTML preservando layout/stili. */
    private function tableToHtml(DOMDocument $doc): string
    {
        [$cellSt, $paraSt, $rowH, $colW, $textCol] = $this->readStyles($doc);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('table', self::TBL);
        $table = $xp->query('//table:table')->item(0);

        // larghezze colonne (cm) → percentuali
        $widths = [];
        foreach ($table->childNodes as $n) {
            if ($n->nodeType !== XML_ELEMENT_NODE || $n->localName !== 'table-column') continue;
            $rep = max(1, (int)$n->getAttributeNS(self::TBL, 'number-columns-repeated') ?: 1);
            $w = $colW[$n->getAttributeNS(self::TBL, 'style-name')] ?? '0cm';
            for ($k = 0; $k < $rep; $k++) $widths[] = (float)$w;
        }
        $tot = array_sum($widths) ?: 1;
        $cols = '';
        foreach ($widths as $w) $cols .= '<col style="width:' . round($w / $tot * 100, 3) . '%">';

        $html = '<table class="foglio"><colgroup>' . $cols . '</colgroup>';
        foreach ($table->childNodes as $r) {
            if ($r->nodeType !== XML_ELEMENT_NODE || $r->localName !== 'table-row') continue;
            $h = $rowH[$r->getAttributeNS(self::TBL, 'style-name')] ?? null;
            $html .= $h ? '<tr style="height:' . $h . '">' : '<tr>';
            foreach ($r->childNodes as $c) {
                if ($c->nodeType !== XML_ELEMENT_NODE) continue;
                if ($c->localName === 'covered-table-cell') continue;
                if ($c->localName !== 'table-cell') continue;
                $cs = (int)$c->getAttributeNS(self::TBL, 'number-columns-spanned') ?: 1;
                $rs = (int)$c->getAttributeNS(self::TBL, 'number-rows-spanned') ?: 1;
                $attr = ($cs > 1 ? ' colspan="' . $cs . '"' : '') . ($rs > 1 ? ' rowspan="' . $rs . '"' : '');
                $style = $cellSt[$c->getAttributeNS(self::TBL, 'style-name')] ?? '';
                $html .= '<td' . $attr . ' style="' . $style . '">' . $this->cellHtml($c, $paraSt, $textCol) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /** contenuto HTML di una cella: <text:p> → div, <text:span> colore, <text:line-break> → br */
    private function cellHtml(DOMElement $cell, array $paraSt, array $textCol): string
    {
        $out = '';
        foreach ($cell->childNodes as $p) {
            if ($p->nodeType !== XML_ELEMENT_NODE || $p->localName !== 'p') continue;
            $css = $paraSt[$p->getAttributeNS(self::TXT, 'style-name')] ?? '';
            $out .= '<div style="margin:0;line-height:1.25;' . $css . '">' . $this->inlineHtml($p, $textCol) . '</div>';
        }
        return $out !== '' ? $out : '&nbsp;';
    }

    private function inlineHtml(DOMNode $node, array $textCol): string
    {
        $h = '';
        foreach ($node->childNodes as $n) {
            if ($n->nodeType === XML_TEXT_NODE) {
                $h .= htmlspecialchars($n->nodeValue);
            } elseif ($n->nodeType === XML_ELEMENT_NODE && $n->localName === 'line-break') {
                $h .= '<br>';
            } elseif ($n->nodeType === XML_ELEMENT_NODE && $n->localName === 'span') {
                $css = $textCol[$n->getAttributeNS(self::TXT, 'style-name')] ?? '';
                $inner = $this->inlineHtml($n, $textCol);
                $h .= $css !== '' ? '<span style="' . $css . '">' . $inner . '</span>' : $inner;
            } elseif ($n->nodeType === XML_ELEMENT_NODE) {
                $h .= $this->inlineHtml($n, $textCol);
            }
        }
        return $h;
    }

    /** legge dagli automatic-styles: [cellCss, paraCss, rowHeight, colWidth, textColor] per nome-stile */
    private function readStyles(DOMDocument $doc): array
    {
        $cell = []; $para = []; $rowH = []; $colW = []; $textCol = [];
        $auto = $doc->getElementsByTagNameNS(self::OFF, 'automatic-styles')->item(0);
        if (!$auto) return [$cell, $para, $rowH, $colW, $textCol];
        foreach ($auto->getElementsByTagNameNS(self::STY, 'style') as $s) {
            $name = $s->getAttributeNS(self::STY, 'name');
            $fam  = $s->getAttributeNS(self::STY, 'family');
            if ($fam === 'table-cell') {
                $p = $s->getElementsByTagNameNS(self::STY, 'table-cell-properties')->item(0);
                if (!$p) continue;
                $css = [];
                $b = $p->getAttributeNS(self::FO, 'border');
                if ($b) $css[] = 'border:' . $b;
                foreach (['left','right','top','bottom'] as $side) {
                    $v = $p->getAttributeNS(self::FO, 'border-' . $side);
                    if ($v) $css[] = 'border-' . $side . ':' . $v;
                }
                $bg = $p->getAttributeNS(self::FO, 'background-color');
                if ($bg && $bg !== 'transparent') $css[] = 'background:' . $bg;
                $va = $p->getAttributeNS(self::STY, 'vertical-align');
                $css[] = 'vertical-align:' . (in_array($va, ['middle','bottom','top']) ? $va : 'top');
                $cell[$name] = implode(';', $css);
            } elseif ($fam === 'paragraph') {
                $css = [];
                $pp = $s->getElementsByTagNameNS(self::STY, 'paragraph-properties')->item(0);
                if ($pp) { $a = $pp->getAttributeNS(self::FO, 'text-align'); if ($a) $css[] = 'text-align:' . ($a === 'end' ? 'right' : ($a === 'center' ? 'center' : ($a === 'start' ? 'left' : $a))); }
                $tp = $s->getElementsByTagNameNS(self::STY, 'text-properties')->item(0);
                if ($tp) {
                    $fs = $tp->getAttributeNS(self::FO, 'font-size'); if ($fs) $css[] = 'font-size:' . $fs;
                    $fw = $tp->getAttributeNS(self::FO, 'font-weight'); if ($fw) $css[] = 'font-weight:' . $fw;
                    $c  = $tp->getAttributeNS(self::FO, 'color'); if ($c) $css[] = 'color:' . $c;
                }
                $para[$name] = implode(';', $css);
            } elseif ($fam === 'table-row') {
                $p = $s->getElementsByTagNameNS(self::STY, 'table-row-properties')->item(0);
                if ($p) { $h = $p->getAttributeNS(self::STY, 'row-height'); if ($h) $rowH[$name] = $h; }
            } elseif ($fam === 'table-column') {
                $p = $s->getElementsByTagNameNS(self::STY, 'table-column-properties')->item(0);
                if ($p) { $w = $p->getAttributeNS(self::STY, 'column-width'); if ($w) $colW[$name] = $w; }
            } elseif ($fam === 'text') {
                $p = $s->getElementsByTagNameNS(self::STY, 'text-properties')->item(0);
                if ($p) {
                    $css = [];
                    $c = $p->getAttributeNS(self::FO, 'color'); if ($c) $css[] = 'color:' . $c;
                    $bg = $p->getAttributeNS(self::FO, 'background-color'); if ($bg) $css[] = 'background:' . $bg;
                    $us = $p->getAttributeNS(self::STY, 'text-underline-style'); if ($us && $us !== 'none') $css[] = 'text-decoration:underline';
                    $fw = $p->getAttributeNS(self::FO, 'font-weight'); if ($fw) $css[] = 'font-weight:' . $fw;
                    if ($css) $textCol[$name] = implode(';', $css);
                }
            }
        }
        return [$cell, $para, $rowH, $colW, $textCol];
    }

    private function ensureColorStyles(DOMDocument $doc): void
    {
        $auto = $doc->getElementsByTagNameNS(self::OFF, 'automatic-styles')->item(0);
        if (!$auto) return;
        $have = [];
        foreach ($auto->getElementsByTagNameNS(self::STY, 'style') as $s) $have[$s->getAttributeNS(self::STY, 'name')] = true;
        // stile peso-normale: forza il non-grassetto dove il modello lo eredita (riga data)
        if (!isset($have['NmReg'])) {
            $st = $doc->createElementNS(self::STY, 'style:style');
            $st->setAttributeNS(self::STY, 'style:name', 'NmReg');
            $st->setAttributeNS(self::STY, 'style:family', 'text');
            $tp = $doc->createElementNS(self::STY, 'style:text-properties');
            $tp->setAttributeNS(self::FO, 'fo:font-weight', 'normal');
            $tp->setAttributeNS(self::FO, 'fo:font-size', '10pt');   // le due righe data devono avere la stessa dimensione (una ereditava 10.5pt dal modello)
            $st->appendChild($tp);
            $auto->appendChild($st);
        }
        // stile paragrafo per le colonne sigla (fuori sede): verticale-centro è già
        // nella cella del modello ovunque, orizzontale-destra no sempre (le colonne
        // adiacenti nell'area servizio non ce l'hanno) — lo forziamo qui, uguale per
        // ferie/riposo/assenti/area servizio invece di dipendere dal modello.
        // font-size esplicito a 10pt: senza, eredita da Table_20_Contents ->
        // Standard, che nel modello è 10.5pt — disallineato dal resto del foglio.
        if (!isset($have['PSiglaDx'])) {
            $st = $doc->createElementNS(self::STY, 'style:style');
            $st->setAttributeNS(self::STY, 'style:name', 'PSiglaDx');
            $st->setAttributeNS(self::STY, 'style:family', 'paragraph');
            $st->setAttributeNS(self::STY, 'style:parent-style-name', 'Table_20_Contents');
            $pp = $doc->createElementNS(self::STY, 'style:paragraph-properties');
            $pp->setAttributeNS(self::FO, 'fo:text-align', 'end');
            $tp = $doc->createElementNS(self::STY, 'style:text-properties');
            $tp->setAttributeNS(self::FO, 'fo:font-size', '10pt');
            $st->appendChild($pp);
            $st->appendChild($tp);
            $auto->appendChild($st);
        }
        $YEL = '#FFFF66';
        // #183 (4°): evidenziatore ferie estive/d'ufficio — colore diverso da quello
        // dello straordinario ($YEL) per non confonderli a colpo d'occhio.
        $EVI = '#AEE3E8';
        // matrice: colore patente × straordinario (giallo) × sottolineato (fuori sede)
        // × evidenziato (ferie estiva/ufficio). '' → nero esplicito (#000000), MAI
        // lasciato senza stile: altrimenti il nome eredita la formattazione della
        // cella del modello.odt, che non è uniforme (#67).
        $colors = ['' => '#000000', 'Rosso' => self::$rossoPat, 'Blu' => self::$bluPat];
        foreach ([false, true] as $straord) {
            foreach ([false, true] as $und) {
                foreach ([false, true] as $evid) {
                    foreach ($colors as $cName => $col) {
                        $name = 'Nm' . ($straord ? 'S' : '') . ($und ? 'U' : '') . ($evid ? 'E' : '') . $cName;
                        if (isset($have[$name])) continue;
                        $st = $doc->createElementNS(self::STY, 'style:style');
                        $st->setAttributeNS(self::STY, 'style:name', $name);
                        $st->setAttributeNS(self::STY, 'style:family', 'text');
                        $tp = $doc->createElementNS(self::STY, 'style:text-properties');
                        if ($col)     $tp->setAttributeNS(self::FO, 'fo:color', $col);
                        // peso SEMPRE esplicito (mai assente): senza, un nome normale può
                        // ereditare il grassetto della cella del modello.odt se quella cella
                        // lo porta (successo con GE-1A nel modello B0, #67-bis) — stesso
                        // principio già usato per NmReg sulle righe data.
                        $tp->setAttributeNS(self::FO, 'fo:font-weight', $straord ? 'bold' : 'normal');
                        if ($straord) {
                            $tp->setAttributeNS(self::FO, 'fo:background-color', $YEL);
                        } elseif ($evid) {
                            $tp->setAttributeNS(self::FO, 'fo:background-color', $EVI);
                        }
                        if ($und) {
                            $tp->setAttributeNS(self::STY, 'style:text-underline-style', 'solid');
                            $tp->setAttributeNS(self::STY, 'style:text-underline-width', 'auto');
                            $tp->setAttributeNS(self::STY, 'style:text-underline-color', 'font-color');
                        }
                        $st->appendChild($tp);
                        $auto->appendChild($st);
                    }
                }
            }
        }
    }
}
