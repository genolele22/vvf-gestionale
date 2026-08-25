<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/turni.php';
require_once __DIR__ . '/../includes/scambio_salto.php';
require_once __DIR__ . '/../includes/FoglioRenderer.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/format.php';
richiediLogin();

$TURNO = turnoAttivo();   // turno su cui si lavora (A/B/C/D); default B. Multi-turno.

$pdo = getDB();
initStilePatente($pdo, $TURNO);   // colore/numero/entrambi + tinte (parametri admin)
require_once __DIR__ . '/../includes/bot_requests_schema.php';
assicuraSchemaRichiesteAssenza($pdo);

// Capienza per posizione = slot del modello.odt + override editor. La mappa
// codice→slot vive in scambioCapPos() (includes/scambio_salto.php): qui solo
// la traduzione posizione_id→codice, con cache statica.
function capPos(int $posId): int {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (getDB()->query("SELECT id, codice FROM posizioni") as $p) {
            $map[(int)$p['id']] = scambioCapPos($p['codice']);
        }
    }
    return $map[$posId] ?? 7;
}

// ── "Nuovo Foglio" nudo (GET senza ?data): apre il foglio del turno B ────────
// Se oggi il turno B non è in servizio, salta al primo slot successivo in cui
// lo è (diurno del suo giorno o notturno del giorno dopo). Redirect alla URL
// canonica così refresh/segnalibri e le chiamate AJAX ereditano data+tipo.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['data'])) {
    $slot = prossimoSlotTurnoB(date('Y-m-d'), $TURNO);
    header('Location: nuovo.php?data=' . $slot['data'] . '&tipo=' . $slot['tipo']);
    exit;
}

// ── Parametri URL ────────────────────────────────────────────
$dataParam = $_GET['data'] ?? date('Y-m-d');
$tipoParam = ($_GET['tipo'] ?? 'D') === 'N' ? 'N' : 'D';

try {
    $dtFoglio = new DateTime($dataParam);
} catch (Exception $e) {
    $dtFoglio = new DateTime('today');
}
$dataStr   = $dtFoglio->format('Y-m-d');
$dataLabel = $dtFoglio->format('d/m/Y');
$oraLabel  = $tipoParam === 'D' ? '08:00 → 20:00' : '20:00 → 08:00';

// ── Turno del giorno ─────────────────────────────────────────
$turnoGiorno  = getTurnoGiorno($dataStr);
$turnoAttivo  = $tipoParam === 'D' ? $turnoGiorno['diurno'] : $turnoGiorno['notte'];
$codSaltoRip  = $TURNO . $turnoAttivo['salto'];

// ── Helper: chi riposa davvero su questo foglio ──────────────
// Default = vigili col salto_id del giorno. Poi applica gli scambi salto
// approvati (salto_override) per quella data/turno: il vigile "out" torna a
// lavorare, il vigile "in" va a riposo. Ritorna [vigile_id => true].
function resterEffettivi(PDO $pdo, string $dataStr, string $tipoParam, int $saltoRiposoId): array {
    $resters = [];
    $st = $pdo->prepare("SELECT id FROM vigili WHERE attivo=1 AND salto_id=?");
    $st->execute([$saltoRiposoId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        $resters[(int)$vid] = true;
    }
    $so = $pdo->prepare(
        "SELECT vigile_out_id, vigile_in_id FROM salto_override
         WHERE data=? AND tipo=? AND attivo=1"
    );
    $so->execute([$dataStr, $tipoParam]);
    foreach ($so->fetchAll() as $r) {
        unset($resters[(int)$r['vigile_out_id']]);
        $resters[(int)$r['vigile_in_id']] = true;
    }
    return $resters;
}

// ── Helper: pre-popola assegnazioni e salto per un foglio ────
// COMPOSITORE DI DEFAULT (regole fisse del comando). Due famiglie di sedi:
//
//  A) SEDI NON CENTRALI (distaccamenti, aeroporto, sommozzatori, nautici):
//     il personale della sede riempie le posizioni di quella sede, in ordine di
//     ruolo (Cr→Cs→Vp) e cognome, traboccando alla posizione successiva quando
//     la corrente è piena (capienza ODT). Mappa sedePosizioni() (scambio_salto.php).
//
//  B) CENTRALE (sede 'C'):
//     - 1A  = era in salto il turno precedente (precedenza su tutto) — fisso
//     - 2A  = priorità a chi ha fatto 1A nel BLOCCO precedente (D+N), se presente
//             oggi — fisso, ancorato al blocco (vale identico su D e N, #92)
//     - tutto il resto (CENTR-OP, fasce Vp per patente, Cr/Cs) è gestito dalle
//       regole in tabella `regole_squadra` (Amministrazione → Criteri squadra,
//       per-turno: ogni admin gestisce le proprie), editabili senza toccare il
//       codice — vedi motore più sotto
//     - 1B/2B-NBCR restano vuote (manuali / fisse)
//
// Override unico = assegnazioni_fisse (Amministrazione), priorità massima.
// Una persona = una posizione: chi è assegnato esce dalla pool.
// $resters = riposanti (salto ± scambi); assenti sul foglio = fuori servizio.
function prepopolaAssegnazioni(PDO $pdo, int $foglioId, int $saltoRiposoId, array $resters,
                               string $dataStr = '', string $tipoParam = ''): void {
    $pdo->prepare("DELETE FROM assegnazioni WHERE foglio_id=?")->execute([$foglioId]);

    // mappe codice → id (posizioni) e id → codice (sedi)
    $posIdByCode = [];
    foreach ($pdo->query("SELECT id, codice FROM posizioni") as $p) $posIdByCode[$p['codice']] = (int)$p['id'];
    $sedeCodById = [];
    foreach ($pdo->query("SELECT id, codice FROM sedi") as $s) $sedeCodById[(int)$s['id']] = $s['codice'];

    // Override: assegnazioni FISSE (DN oppure il turno corrente)
    $fisse = [];
    try {
        $stF = $pdo->prepare(
            "SELECT vigile_id, posizione_id FROM assegnazioni_fisse WHERE tipo_turno='DN' OR tipo_turno=?"
        );
        $stF->execute([$tipoParam !== '' ? $tipoParam : 'DN']);
        foreach ($stF->fetchAll() as $r) $fisse[(int)$r['vigile_id']] = (int)$r['posizione_id'];
    } catch (Throwable $e) { /* tabella assente: nessuna regola fissa */ }

    // Chi era a riposo (salto) nel PRECEDENTE servizio del turno B → centrale in 1A.
    // Il salto di riposo avanza +1 a ogni occorrenza, quindi il salto precedente
    // = salto di oggi − 1 (ciclo 1..8). Sono i vigili (centrali) con quel salto_id,
    // che oggi rientrano in servizio. [vid => true]
    $eraInSaltoPrima = [];
    if ($dataStr !== '' && $tipoParam !== '') {
        $saltoOggi = saltoRiposoNum($dataStr, $tipoParam);          // 1..8
        $prevNum   = (($saltoOggi - 2 + 8) % 8) + 1;                // −1 con wrap 1..8
        $stPrevS = $pdo->prepare("SELECT id FROM salti_turno WHERE codice=?");
        $stPrevS->execute([turnoAttivo() . $prevNum]);
        $prevSaltoId = (int)($stPrevS->fetchColumn() ?: 0);
        if ($prevSaltoId > 0) {
            $stPv = $pdo->prepare("SELECT id FROM vigili WHERE attivo=1 AND salto_id=?");
            $stPv->execute([$prevSaltoId]);
            foreach ($stPv->fetchAll(PDO::FETCH_COLUMN) as $vid) $eraInSaltoPrima[(int)$vid] = true;
        }
    }

    // Chi ha fatto 1A nel BLOCCO precedente (stesso turno) → priorità su 2A oggi.
    // Ancorato al Diurno del blocco (non al foglio) così il risultato è identico
    // sia generando il Diurno che il Notturno di oggi: un blocco = Diurno(gg X) +
    // Notturno(gg X+1), date di calendario diverse (calcolaNotte = calcolaDiurno
    // del giorno prima, includes/turni.php) — la query "servizio precedente" presa
    // alla lettera darebbe risultati diversi tra D e N dello stesso blocco.
    //
    // servizioAdiacenteB() NON filtra per D/N: il parametro tipo serve solo a
    // capire da dove partire la scansione, il risultato è il servizio (D o N) del
    // turno più vicino nel passato. Per come è fatto il ciclo (blocco = D poi N il
    // giorno dopo, poi un vuoto di giorni fino al blocco successivo) quel servizio
    // più vicino è SEMPRE il Notturno del blocco precedente — mai la sua Diurna,
    // che è un giorno più lontana. Va quindi usato così com'è, senza ricostruire
    // altre date: un tentativo precedente lo trattava per errore come una Diurna e
    // calcolava date sbagliate, che non trovavano mai un foglio esistente (#92).
    $fece1APrima = [];
    if ($dataStr !== '' && $tipoParam !== '' && isset($posIdByCode['1A'])) {
        $meD = ($tipoParam === 'N')
            ? (new DateTime($dataStr))->modify('-1 day')->format('Y-m-d')
            : $dataStr;
        $prevServ = servizioAdiacenteB($meD, 'D', -1, turnoAttivo());
        if ($prevServ) {
            // Fallback sulla Diurna dello stesso blocco precedente se per qualche
            // motivo il suo Notturno non è mai stato creato come foglio.
            $candidati = [[$prevServ['data'], $prevServ['tipo']]];
            if ($prevServ['tipo'] === 'N') {
                $diurnoStesso = (new DateTime($prevServ['data']))->modify('-1 day')->format('Y-m-d');
                $candidati[] = [$diurnoStesso, 'D'];
            }
            $prevBloccoFoglioId = 0;
            $stPF = $pdo->prepare("SELECT id FROM fogli_servizio WHERE turno=? AND data_servizio=? AND tipo_turno=?");
            foreach ($candidati as [$d, $t]) {
                $stPF->execute([turnoAttivo(), $d, $t]);
                $fid = (int)($stPF->fetchColumn() ?: 0);
                if ($fid > 0) { $prevBloccoFoglioId = $fid; break; }
            }
            if ($prevBloccoFoglioId > 0) {
                // ORDER BY ordine: la squadra passa pari pari, l'ordine di ieri in 1A
                // va preservato (array associativo → l'ordine di inserimento resta
                // quello di lettura, usato sotto per riempire 2A senza risort alfabetico).
                $st1A = $pdo->prepare("SELECT vigile_id FROM assegnazioni WHERE foglio_id=? AND posizione_id=? ORDER BY ordine");
                $st1A->execute([$prevBloccoFoglioId, $posIdByCode['1A']]);
                foreach ($st1A->fetchAll(PDO::FETCH_COLUMN) as $vid) $fece1APrima[(int)$vid] = true;
            }
        }
    }

    // #95: nei giorni con visita medica la 5A non si forma (la casella diventa
    // il contenitore "Visita Medica") → il compositore la salta nelle regole.
    $salta5A = false;
    if ($tipoParam === 'D' && $dataStr !== '') {
        try {
            $stVis = $pdo->prepare(
                "SELECT 1 FROM visite_mediche vm JOIN vigili v ON v.id=vm.vigile_id
                 WHERE vm.data=? AND v.turno=? LIMIT 1");
            $stVis->execute([$dataStr, turnoAttivo()]);
            $salta5A = (bool)$stVis->fetchColumn();
        } catch (Throwable $e) { /* tabella assente: nessuna visita */ }
    }

    // Assenti registrati su questo foglio → fuori servizio
    $assentiIds = [];
    $stAb = $pdo->prepare("SELECT vigile_id FROM assenze WHERE foglio_id=?");
    $stAb->execute([$foglioId]);
    foreach ($stAb->fetchAll(PDO::FETCH_COLUMN) as $aid) $assentiIds[(int)$aid] = true;

    // Pool: attivi, con i dati per le regole (patente più alta posseduta).
    $patMax = "(SELECT MAX(p.tipo) FROM vigili_patenti vp JOIN patenti p ON p.id=vp.patente_id WHERE vp.vigile_id=v.id)";
    $rows = $pdo->query(
        "SELECT v.id, v.cognome, v.sede_id, v.qualifica_id, $patMax AS patente_max
         FROM vigili v WHERE v.attivo=1 AND v.turno='" . turnoAttivo() . "'"
    )->fetchAll();

    // Abilitazioni possedute per vigile (usate dalle regole di regole_squadra,
    // es. 'SO' per l'operatore Centrale) — mappa vid → [codici...].
    $abilByVid = [];
    foreach ($pdo->query(
        "SELECT va.vigile_id, ab.codice FROM vigili_abilitazioni va
         JOIN abilitazioni ab ON ab.id = va.abilitazione_id"
    ) as $ar) {
        $abilByVid[(int)$ar['vigile_id']][] = $ar['codice'];
    }

    // Candidati = non a riposo, non assenti
    $cand = [];
    foreach ($rows as $r) {
        $vid = (int)$r['id'];
        if (isset($resters[$vid]) || isset($assentiIds[$vid])) continue;
        $r['abilitazioni'] = $abilByVid[$vid] ?? [];
        $cand[$vid] = $r;
    }

    // Inserimento
    $nextAssId = nextId($pdo, 'assegnazioni');
    $stA = $pdo->prepare("INSERT IGNORE INTO assegnazioni (id,foglio_id,posizione_id,vigile_id,ordine,in_straordinario) VALUES (?,?,?,?,?,0)");
    $conteggio = [];   // posId → n attuale
    $assegnati = [];   // vid → true
    $assegna = function(int $vid, ?int $posId) use (&$conteggio, &$assegnati, &$nextAssId, $stA, $foglioId): void {
        if (!$posId) return;
        $conteggio[$posId] = ($conteggio[$posId] ?? 0) + 1;
        $stA->execute([$nextAssId++, $foglioId, $posId, $vid, $conteggio[$posId]]);
        $assegnati[$vid] = true;
    };

    // Ordine ruolo (Cr>Cs>Vp = qualifica_id desc) + cognome
    $ordina = function(array $list): array {
        usort($list, fn($a, $b) =>
            [-(int)$a['qualifica_id'], strtolower($a['cognome'])]
            <=> [-(int)$b['qualifica_id'], strtolower($b['cognome'])]);
        return $list;
    };
    // Disponibili (non già assegnati) che soddisfano $cond, ordinati
    $disp = function(callable $cond) use (&$cand, &$assegnati, $ordina): array {
        $out = [];
        foreach ($cand as $vid => $r) {
            if (isset($assegnati[$vid])) continue;
            if ($cond($r)) $out[] = $r;
        }
        return $ordina($out);
    };
    $sedeCod = fn(array $r): string => $sedeCodById[(int)$r['sede_id']] ?? '';

    // Riempie le posizioni $codes (in ordine) con $lista, traboccando alla
    // successiva quando una è piena. NON supera MAI la capienza (capPos): gli
    // esuberi oltre l'ultima posizione restano disponibili.
    $riempi = function(array $codes, array $lista) use (&$conteggio, $assegna, $posIdByCode): void {
        $ci = 0;
        foreach ($lista as $r) {
            while ($ci < count($codes)
                   && ($conteggio[$posIdByCode[$codes[$ci]] ?? 0] ?? 0) >= capPos($posIdByCode[$codes[$ci]] ?? 0))
                $ci++;
            if ($ci >= count($codes)) break;   // tutte piene → esubero disponibile
            $assegna((int)$r['id'], $posIdByCode[$codes[$ci]] ?? null);
        }
    };

    // ── 0. Assegnazioni fisse ────────────────────────────────────────────────
    foreach ($cand as $vid => $r) {
        if (isset($fisse[$vid])) $assegna($vid, $fisse[$vid]);
    }

    // ── A. Sedi NON centrali: personale della sede → posizioni della sede ─────
    // Riempimento sequenziale con trabocco a capienza piena. Una posizione = una
    // sede (la centrale 'C' è gestita a parte). Mappa condivisa sedePosizioni()
    // in includes/scambio_salto.php.
    foreach (sedePosizioni() as $sc => $codes)
        $riempi($codes, $disp(fn($r) => $sedeCod($r) === $sc));

    // ── B. CENTRALE (sede 'C') ───────────────────────────────────────────────
    // Tutte le posizioni rispettano la capienza ODT (riempi): mai sforata.
    $isC = fn(array $r): bool => $sedeCod($r) === 'C';

    // 1A — era a riposo il servizio precedente (precedenza su tutto)
    $riempi(['1A'], $disp(fn($r) => $isC($r) && isset($eraInSaltoPrima[(int)$r['id']])));

    // 2A — priorità a chi ha fatto 1A nel blocco precedente (#92), prima di
    // qualunque regola generica su 2A qui sotto. Ripresi PARI PARI nell'ordine
    // che avevano in 1A (non alfabetico): è un passaggio di squadra, non un
    // riempimento libero — unico punto che NON passa da $disp()/$ordina().
    $lista2A = [];
    foreach ($fece1APrima as $vid => $_) {
        if (isset($assegnati[$vid]) || !isset($cand[$vid]) || !$isC($cand[$vid])) continue;
        $lista2A[] = $cand[$vid];
    }
    $riempi(['2A'], $lista2A);

    // Motore regole configurabili (Amministrazione → Criteri squadra, tabella
    // regole_squadra): copre CENTR-OP, fasce Vp per patente, Cr/Cs. Tabella
    // assente/vuota = nessuna regola extra, degradazione morbida (come
    // assegnazioni_fisse più sopra).
    try {
        $qualCodById = [];
        foreach ($pdo->query("SELECT id, codice FROM qualifiche") as $q) $qualCodById[(int)$q['id']] = $q['codice'];
        $patTipoById = [];
        foreach ($pdo->query("SELECT id, tipo FROM patenti") as $p) $patTipoById[(int)$p['id']] = (string)$p['tipo'];
        $abilCodById = [];
        foreach ($pdo->query("SELECT id, codice FROM abilitazioni") as $a) $abilCodById[(int)$a['id']] = $a['codice'];
        $posCodeById = array_flip($posIdByCode);

        $csvToSet = fn(string $csv, array $map): array => array_values(array_filter(array_map(
            fn($id) => $map[(int)$id] ?? null,
            array_filter(array_map('trim', explode(',', $csv)), fn($v) => $v !== '')
        )));

        $stRg = $pdo->prepare("SELECT * FROM regole_squadra WHERE attiva=1 AND turno=? ORDER BY ordine, id");
        $stRg->execute([turnoAttivo()]);
        $regole = $stRg->fetchAll();
        foreach ($regole as $rg) {
            $sedeRg = $sedeCodById[(int)$rg['sede_id']] ?? 'C';
            $qualiRg = $csvToSet((string)($rg['qualifiche_ids'] ?? ''), $qualCodById);
            $patRg   = $csvToSet((string)($rg['patenti_ids'] ?? ''), $patTipoById);
            $abilRg  = $rg['abilitazione_id'] ? ($abilCodById[(int)$rg['abilitazione_id']] ?? null) : null;
            $posRg   = $csvToSet((string)($rg['posizioni_ids'] ?? ''), $posCodeById);
            if ($salta5A) $posRg = array_values(array_diff($posRg, ['5A']));   // #95
            if (!$posRg) continue;

            $cond = function (array $r) use ($sedeCod, $sedeRg, $qualiRg, $patRg, $abilRg, $qualCodById): bool {
                if ($sedeCod($r) !== $sedeRg) return false;
                if ($qualiRg && !in_array($qualCodById[(int)$r['qualifica_id']] ?? '', $qualiRg, true)) return false;
                if ($patRg && !in_array((string)$r['patente_max'], $patRg, true)) return false;
                if ($abilRg !== null && !in_array($abilRg, $r['abilitazioni'] ?? [], true)) return false;
                return true;
            };
            $riempi($posRg, $disp($cond));
        }
    } catch (Throwable $e) { /* tabella assente: nessuna regola extra */ }
}

function prepopolaFoglio(PDO $pdo, int $foglioId, int $saltoRiposoId, array $resters,
                         string $dataStr = '', string $tipoParam = ''): void {
    $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=?")->execute([$foglioId]);

    $personale   = $pdo->query("SELECT v.id FROM vigili v WHERE v.attivo=1 AND v.turno='" . turnoAttivo() . "'")->fetchAll();
    $nextSaltoId = nextId($pdo, 'salto_servizio');
    $stS         = $pdo->prepare("INSERT IGNORE INTO salto_servizio (id,foglio_id,vigile_id,richiamato) VALUES (?,?,?,0)");

    foreach ($personale as $vp) {
        if (isset($resters[(int)$vp['id']])) {
            $stS->execute([$nextSaltoId++, $foglioId, (int)$vp['id']]);
        }
    }

    prepopolaAssegnazioni($pdo, $foglioId, $saltoRiposoId, $resters, $dataStr, $tipoParam);
    prepopolaRuoli($pdo, $foglioId, $resters);
}

// ── Helper: capo/vice (pool ordinato) + furieri (set fisso) ──
// Capo = primo del pool presente in servizio; vice = secondo presente.
// Furieri = tutti i furieri fissi presenti. "Presente" = attivo, non in salto
// (resters) e senza assenza registrata sul foglio. Metodo 1: impostati d'ufficio
// alla creazione/reset del foglio (poi modificabili a mano dall'intestazione).
function prepopolaRuoli(PDO $pdo, int $foglioId, array $resters): void {
    $assenti = [];
    $stAb = $pdo->prepare("SELECT vigile_id FROM assenze WHERE foglio_id=?");
    $stAb->execute([$foglioId]);
    foreach ($stAb->fetchAll(PDO::FETCH_COLUMN) as $aid) $assenti[(int)$aid] = true;
    $presente = fn(int $vid): bool => !isset($resters[$vid]) && !isset($assenti[$vid]);

    // Capo / Vice dal pool ordinato (solo attivi)
    try {
        $pool = $pdo->query(
            "SELECT cp.vigile_id FROM capi_pool cp JOIN vigili v ON v.id = cp.vigile_id
             WHERE v.attivo = 1 AND v.turno = '" . turnoAttivo() . "' ORDER BY cp.ordine, cp.id"
        )->fetchAll(PDO::FETCH_COLUMN);
        $capo = null; $vice = null;
        foreach ($pool as $vid) {
            $vid = (int)$vid;
            if (!$presente($vid)) continue;
            if      ($capo === null) $capo = $vid;
            elseif  ($vice === null) { $vice = $vid; break; }
        }
        $pdo->prepare("UPDATE fogli_servizio SET capo_servizio_id=?, vice_capo_id=? WHERE id=?")
            ->execute([$capo, $vice, $foglioId]);

        // prepopolaAssegnazioni li ha già messi in squadra: capo/vice non stanno
        // anche nelle squadre, vanno tolti (come al salvataggio manuale).
        foreach ([$capo, $vice] as $rid) {
            if (!$rid) continue;
            $pdo->prepare("DELETE FROM assegnazioni  WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $rid]);
            $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $rid]);
        }
    } catch (Throwable $e) { /* tabella capi_pool assente: nessun automatismo */ }

    // Furieri dal set fisso: sull'ODT vanno SEMPRE segnati tutti (anche se in
    // ferie/salto), sono un ruolo amministrativo. Solo attivi.
    try {
        $pdo->prepare("DELETE FROM foglio_furieri WHERE foglio_id=?")->execute([$foglioId]);
        $fur = $pdo->query(
            "SELECT ff.vigile_id FROM furieri_fissi ff JOIN vigili v ON v.id = ff.vigile_id
             WHERE v.attivo = 1 AND v.turno = '" . turnoAttivo() . "' ORDER BY v.cognome"
        )->fetchAll(PDO::FETCH_COLUMN);
        $ins = $pdo->prepare("INSERT IGNORE INTO foglio_furieri (foglio_id, vigile_id) VALUES (?,?)");
        foreach ($fur as $vid) {
            $ins->execute([$foglioId, (int)$vid]);
        }
    } catch (Throwable $e) { /* tabella furieri_fissi assente */ }
}

// Ferie PENDING ancora da approvare su questo foglio (vigili in FER con richiesta
// bot pending per questa data/turno: N→N/DN, D→D/DN). Stessa logica di finalize_ferie.
function contaFeriePending(PDO $pdo, int $foglioId, string $dataStr, string $tipoParam): int {
    $tipi = $tipoParam === 'N' ? ['N', 'DN'] : ['D', 'DN'];
    $ph   = implode(',', array_fill(0, count($tipi), '?'));
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT a.vigile_id)
           FROM assenze a
           JOIN bot_requests r ON r.vigile_id = a.vigile_id AND r.data_richiesta = ?
                AND r.stato = 'pending' AND r.tipo_turno IN ($ph) AND r.tipo_assenza_id = 1
          WHERE a.foglio_id = ? AND a.tipo_assenza_id = 1"
    );
    $st->execute(array_merge([$dataStr], $tipi, [$foglioId]));
    return (int)$st->fetchColumn();
}

// Tutte le comunicazioni ferie ancora da inviare su questo foglio (stessa logica
// di finalize_ferie): approvande (FER + richiesta pending), d'ufficio (FER senza
// richiesta), negate (richiesta rejected). Esclude ciò che è già stato notificato
// (bot_outbox.ctx). Ritorna il dettaglio per il popup di conferma.
function contaFerieDaNotificare(PDO $pdo, int $foglioId, string $dataStr, string $tipoParam): array {
    $tipi = $tipoParam === 'N' ? ['N', 'DN'] : ['D', 'DN'];
    $ph   = implode(',', array_fill(0, count($tipi), '?'));
    $hasCtx = $pdo->prepare("SELECT 1 FROM bot_outbox WHERE ctx=? LIMIT 1");

    // approvande: FER sul foglio con richiesta NON respinta la cui notifica
    // (ctx ferie:<id>) non è ancora stata accodata. NB: si conta per PRESENZA sul
    // foglio + registro bot_outbox, NON per stato 'pending'. Una richiesta già
    // 'approved' in Agenda ma mai comunicata deve comunque risultare "da inviare":
    // altrimenti la mail resta invisibile (bug turni collegati #94). Stessa scelta
    // della richiesta di finalizeFerie: ultima non-rejected, id DESC.
    $stFer = $pdo->prepare(
        "SELECT DISTINCT vigile_id FROM assenze WHERE foglio_id=? AND tipo_assenza_id=1"
    );
    $stFer->execute([$foglioId]);
    $findReq = $pdo->prepare(
        "SELECT id FROM bot_requests
          WHERE vigile_id=? AND data_richiesta=? AND tipo_turno IN ($ph) AND tipo_assenza_id=1
            AND stato NOT IN ('rejected','declined')
          ORDER BY id DESC LIMIT 1"
    );
    $approvaIds = [];
    foreach ($stFer->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        $findReq->execute(array_merge([(int)$vid, $dataStr], $tipi));
        $rid = $findReq->fetchColumn();
        if (!$rid) continue;                       // nessuna richiesta → è d'ufficio (sotto)
        $hasCtx->execute(['ferie:' . (int)$rid]);
        if (!$hasCtx->fetchColumn()) $approvaIds[] = (int)$vid;   // decisa ma non ancora comunicata
    }

    // d'ufficio: FER sul foglio senza richiesta pending/approved (una richiesta
    // rejected + FER a mano = d'ufficio, non negata → stessa logica di finalize)
    $stU = $pdo->prepare(
        "SELECT a.vigile_id FROM assenze a
          WHERE a.foglio_id=? AND a.tipo_assenza_id=1
            AND NOT EXISTS (SELECT 1 FROM bot_requests r
                            WHERE r.vigile_id=a.vigile_id AND r.data_richiesta=?
                              AND r.tipo_turno IN ($ph) AND r.tipo_assenza_id=1
                              AND r.stato NOT IN ('rejected','declined'))"
    );
    $stU->execute(array_merge([$foglioId, $dataStr], $tipi));
    $ufficioIds = [];
    foreach ($stU->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        $hasCtx->execute(['ferie_uff:' . $foglioId . ':' . (int)$vid]);
        if (!$hasCtx->fetchColumn()) $ufficioIds[] = (int)$vid;
    }

    // negate: richieste declined (respinte non ancora comunicate) per quella
    // data/turno, per chi NON è in FER sul foglio — stessa condizione di finalize_ferie.
    $stN = $pdo->prepare(
        "SELECT r.id, r.vigile_id FROM bot_requests r
          WHERE r.data_richiesta=? AND r.stato='declined' AND r.tipo_turno IN ($ph) AND r.tipo_assenza_id=1
            AND NOT EXISTS (SELECT 1 FROM assenze a
                            WHERE a.foglio_id=? AND a.vigile_id=r.vigile_id AND a.tipo_assenza_id=1)"
    );
    $stN->execute(array_merge([$dataStr], $tipi, [$foglioId]));
    $negateIds = [];
    foreach ($stN->fetchAll() as $r) {
        $hasCtx->execute(['ferie_neg:' . (int)$r['id']]);
        if (!$hasCtx->fetchColumn()) $negateIds[] = (int)$r['vigile_id'];
    }

    // Nomi per il popup di conferma (#109): un'unica query su tutti gli id coinvolti.
    $tuttiIds = array_unique(array_merge($approvaIds, $ufficioIds, $negateIds));
    $nomiById = [];
    if ($tuttiIds) {
        $phv = implode(',', array_fill(0, count($tuttiIds), '?'));
        $stNomi = $pdo->prepare("SELECT id, cognome, disambiguatore FROM vigili WHERE id IN ($phv)");
        $stNomi->execute($tuttiIds);
        foreach ($stNomi->fetchAll() as $v) {
            $nomiById[(int)$v['id']] = ucfirst(strtolower($v['cognome']))
                . ($v['disambiguatore'] ? ' ' . $v['disambiguatore'] : '');
        }
    }
    $nomi = fn(array $ids) => array_values(array_map(fn($id) => $nomiById[$id] ?? '?', $ids));

    return ['approva' => count($approvaIds), 'ufficio' => count($ufficioIds), 'negate' => count($negateIds),
            'tot' => count($approvaIds) + count($ufficioIds) + count($negateIds),
            'nomi' => ['approva' => $nomi($approvaIds), 'ufficio' => $nomi($ufficioIds), 'negate' => $nomi($negateIds)]];
}

// ── Recupera o crea il foglio (identità: turno + data + tipo) ─
$stmtF = $pdo->prepare(
    "SELECT * FROM fogli_servizio WHERE turno=? AND data_servizio=? AND tipo_turno=?"
);
$stmtF->execute([$TURNO, $dataStr, $tipoParam]);
$foglio = $stmtF->fetch();

$stSalto = $pdo->prepare("SELECT id FROM salti_turno WHERE codice=?");
$stSalto->execute([$codSaltoRip]);
$saltoRiposoId = (int)($stSalto->fetchColumn() ?: 1);

if (!$foglio) {
    $nextId = nextId($pdo, 'fogli_servizio');
    $pdo->prepare(
        "INSERT INTO fogli_servizio (id,turno,data_servizio,tipo_turno,salto_riposo_id,creato_da) VALUES (?,?,?,?,?,?)"
    )->execute([$nextId, $TURNO, $dataStr, $tipoParam, $saltoRiposoId, 'sistema']);
    $stmtF->execute([$TURNO, $dataStr, $tipoParam]);
    $foglio = $stmtF->fetch();
    $foglioId = (int)$foglio['id'];
    prepopolaFoglio($pdo, $foglioId, $saltoRiposoId, resterEffettivi($pdo, $dataStr, $tipoParam, $saltoRiposoId), $dataStr, $tipoParam);
}
$foglioId = (int)$foglio['id'];

// Se il foglio esiste ma non ha capo (es. pool capi popolato DOPO la creazione),
// applica ora capo/vice + furieri dal pool/set fisso, senza toccare le squadre.
if (empty($foglio['capo_servizio_id'])) {
    prepopolaRuoli($pdo, $foglioId, resterEffettivi($pdo, $dataStr, $tipoParam, $saltoRiposoId));
    $stmtF->execute([$TURNO, $dataStr, $tipoParam]);
    $foglio = $stmtF->fetch();
}

// ── Servizio precedente (per il tasto "Servizio precedente") ──
// Il più recente foglio prima di questo: stessa data ma turno D (per un N),
// altrimenti la data anteriore più vicina (D < N nello stesso giorno).
$stPrev = $pdo->prepare(
    "SELECT id, data_servizio, tipo_turno FROM fogli_servizio
      WHERE turno = ? AND (data_servizio < ? OR (data_servizio = ? AND tipo_turno < ?))
      ORDER BY data_servizio DESC, tipo_turno DESC
      LIMIT 1"
);
$stPrev->execute([$TURNO, $dataStr, $dataStr, $tipoParam]);
$foglioPrec = $stPrev->fetch();

// ── Frecce di navigazione (#88): servizio prec/succ del TURNO ATTIVO nel
// calendario turni, a prescindere che il foglio esista già (creato al GET). ──
$servPrec = servizioAdiacenteB($dataStr, $tipoParam, -1, $TURNO);
$servSucc = servizioAdiacenteB($dataStr, $tipoParam, +1, $TURNO);
$navLabel = function (?array $s): string {
    if (!$s) return '';
    return date('d/m/Y', strtotime($s['data'])) . ' ' . ($s['tipo'] === 'N' ? 'Notturno' : 'Diurno');
};

// ── Stato blocco foglio (condiviso, lato server) ─────────────
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS fogli_lock (
        foglio_id  INT PRIMARY KEY,
        bloccato   TINYINT NOT NULL DEFAULT 0,
        updated_at DATETIME
     )"
);
$stLock = $pdo->prepare("SELECT bloccato FROM fogli_lock WHERE foglio_id=?");
$stLock->execute([$foglioId]);
$foglioBloccato = (bool)$stLock->fetchColumn();

// ── Personale esterno al turno (turno C in straordinario, sommozzatori,
// nautici in prestito): nomi liberi NON in `vigili`, legati a foglio+posizione.
// Tabella a parte perché assegnazioni.vigile_id ha FK su vigili(id). ──────────
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS assegnazioni_esterni (
        id               INT UNSIGNED NOT NULL PRIMARY KEY,
        foglio_id        INT UNSIGNED NOT NULL,
        posizione_id     SMALLINT UNSIGNED NOT NULL,
        nome             VARCHAR(80) NOT NULL,
        in_straordinario TINYINT(1) NOT NULL DEFAULT 1,
        patente_forzata  CHAR(1) NULL,
        ordine           TINYINT UNSIGNED NOT NULL DEFAULT 0,
        KEY idx_foglio (foglio_id),
        KEY idx_foglio_pos (foglio_id, posizione_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Occupanti di una posizione = vigili assegnati + esterni (per la capienza).
function countOccupanti(PDO $pdo, int $foglioId, int $posId, int $exclVigile = 0): int {
    $st = $pdo->prepare(
        "SELECT (SELECT COUNT(*) FROM assegnazioni
                  WHERE foglio_id=? AND posizione_id=? AND vigile_id<>?)
              + (SELECT COUNT(*) FROM assegnazioni_esterni
                  WHERE foglio_id=? AND posizione_id=?)"
    );
    $st->execute([$foglioId, $posId, $exclVigile, $foglioId, $posId]);
    return (int)$st->fetchColumn();
}

// Righe (campo `ordine`) già occupate in una posizione, vigili+esterni (per
// piazzare senza scavalcare nessuno: niente compattazione automatica, #logbook).
function ordiniOccupati(PDO $pdo, int $foglioId, int $posId, int $exclVigile = 0, int $exclExt = 0): array {
    $st = $pdo->prepare(
        "SELECT ordine FROM assegnazioni WHERE foglio_id=? AND posizione_id=? AND vigile_id<>?
         UNION ALL
         SELECT ordine FROM assegnazioni_esterni WHERE foglio_id=? AND posizione_id=? AND id<>?"
    );
    $st->execute([$foglioId, $posId, $exclVigile, $foglioId, $posId, $exclExt]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// Prima riga libera 1..cap non in $occupate (fallback quando non è stata
// richiesta una riga precisa, o quella richiesta risulta già occupata).
function primaRigaLibera(int $cap, array $occupate): int {
    for ($i = 1; $i <= $cap; $i++) if (!in_array($i, $occupate, true)) return $i;
    return $cap + 1;   // non dovrebbe succedere (countOccupanti blocca prima)
}

// ── AJAX: salva intestazione ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';
    header('Content-Type: application/json');

    // Sola lettura: user, oppure admin che guarda un turno non suo. Nessuna azione
    // AJAX di modifica passa dal server per il turno in vista.
    if (soloLetturaAttivo()) {
        echo json_encode(['ok' => false, 'errore' => 'Turno in sola lettura per il tuo profilo.']);
        exit;
    }

    // Cambia lo stato di blocco condiviso del foglio
    if ($azione === 'set_blocco') {
        $val = (!empty($_POST['bloccato']) && $_POST['bloccato'] !== '0') ? 1 : 0;
        $pdo->prepare(
            "INSERT INTO fogli_lock (foglio_id, bloccato, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE bloccato=VALUES(bloccato), updated_at=NOW()"
        )->execute([$foglioId, $val]);
        echo json_encode(['ok' => true, 'bloccato' => (bool)$val]);
        exit;
    }

    // Guardia: se il foglio è bloccato, nessuna operazione di modifica passa
    $azioniModifica = ['salva_intestazione', 'assegna', 'rimuovi', 'assenza',
                       'rimuovi_assenza', 'metti_salto', 'richiama_salto', 'reset_foglio',
                       'ferie_ufficio', 'rimuovi_ufficio', 'scambia_salto', 'annulla_scambio',
                       'sposta_riga', 'copia_da_diurno', 'scambia_posizioni',
                       'assegna_esterno', 'rimuovi_esterno', 'rimuovi_da_posizione'];
    if ($foglioBloccato && in_array($azione, $azioniModifica, true)) {
        echo json_encode(['ok' => false, 'bloccato' => true,
                          'errore' => 'Foglio bloccato. Sblocca per modificare.']);
        exit;
    }

    if ($azione === 'salva_intestazione') {
        $csId  = (int)($_POST['capo_servizio_id'] ?? 0) ?: null;
        $vcsId = (int)($_POST['vice_capo_id']     ?? 0) ?: null;
        $fun   = trim($_POST['funzionario']        ?? '');
        $note  = trim($_POST['note_generali']      ?? '');
        // #145: straordinario per capo/vice = era in salto (riposo) quando è stato
        // messo qui, stessa logica delle posizioni normali (calcolata lato client).
        $csStr  = $csId  ? (int)!empty($_POST['capo_straordinario']) : 0;
        $vcsStr = $vcsId ? (int)!empty($_POST['vice_straordinario']) : 0;

        $pdo->prepare(
            "UPDATE fogli_servizio
             SET capo_servizio_id=?, capo_straordinario=?, vice_capo_id=?, vice_straordinario=?,
                 funzionario=?, note_generali=?
             WHERE id=?"
        )->execute([$csId, $csStr, $vcsId, $vcsStr, $fun, $note, $foglioId]);

        // Capo e vice non possono stare anche in squadra/salto/assenza: chi finisce
        // in un riquadro dell'intestazione sparisce dal resto del servizio.
        foreach ([$csId, $vcsId] as $rid) {
            if (!$rid) continue;
            $pdo->prepare("DELETE FROM assegnazioni   WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $rid]);
            $pdo->prepare("DELETE FROM salto_servizio  WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $rid]);
            $pdo->prepare("DELETE FROM assenze         WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $rid]);
        }

        // Comunicazioni ferie ancora da inviare su questo foglio (approvande +
        // d'ufficio + negate), per proporre l'invio dal tasto ✉️ con conferma client.
        $ferieN = contaFerieDaNotificare($pdo, $foglioId, $dataStr, $tipoParam);
        echo json_encode(['ok' => true,
            'ferie_pending'  => $ferieN['approva'],   // retrocompat
            'ferie_notifiche' => $ferieN]);
        exit;
    }

    // ── AJAX: approva le ferie del foglio + accoda notifiche (Telegram+mail) ──
    // Sostituisce il vecchio automatismo alla generazione ODT: ora avviene solo
    // qui, dal tasto Salva, dopo conferma esplicita dell'operatore.
    if ($azione === 'finalizza_ferie') {
        require_once __DIR__ . '/../includes/finalize_ferie.php';
        try {
            $cnt    = finalizeFerie($pdo, $foglioId, $dataStr, $tipoParam);
            $cntP   = finalizePermesso($pdo, $foglioId, $dataStr, $tipoParam);
            $cntPO  = finalizePermessoOrario($pdo, $dataStr, $tipoParam);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'errore' => 'Errore comunicazioni ferie: ' . $e->getMessage()]);
            exit;
        }
        // I contatori del popup restano quelli di FER (comportamento invariato);
        // permesso/permesso orario vengono comunicati con lo stesso gesto ma non
        // hanno ancora un riepilogo dedicato nel popup di conferma.
        echo json_encode(['ok' => true,
            'approvate' => $cnt['approvate'] + $cntP['approvate'] + $cntPO['approvate'],
            'ufficio'   => $cnt['ufficio']   + $cntP['ufficio'],
            'negate'    => $cnt['negate']    + $cntP['negate']    + $cntPO['negate'],
            'tot'       => $cnt['approvate'] + $cnt['ufficio'] + $cnt['negate']
                          + $cntP['approvate'] + $cntP['ufficio'] + $cntP['negate']
                          + $cntPO['approvate'] + $cntPO['negate']]);
        exit;
    }

    // ── AJAX: assegna vigile a posizione ─────────────────────
    if ($azione === 'assegna') {
        $vigileId    = (int)($_POST['vigile_id']     ?? 0);
        $posizioneId = (int)($_POST['posizione_id']  ?? 0);
        $straord     = (int)($_POST['straordinario'] ?? 0);
        $rigaReq     = (int)($_POST['ordine']        ?? 0);   // riga scelta col drop, 0 = nessuna preferenza

        // Limite = capienza ODT della posizione: controlla PRIMA di toccare i dati,
        // escludendo il vigile stesso (caso di ri-drop nella stessa squadra)
        if ($posizioneId > 0) {
            $cap = capPos($posizioneId);
            if (countOccupanti($pdo, $foglioId, $posizioneId, $vigileId) >= $cap) {
                echo json_encode(['ok' => false, 'pieno' => true,
                                  'errore' => "Posizione al completo (max $cap)."]);
                exit;
            }
        }

        // Rimuove assegnazioni, salto e assenze precedenti dello stesso vigile.
        // Assegnare a una squadra supera lo stato precedente OVUNQUE fosse:
        // in posizione, in salto, o in ferie/permesso/missione/malattia
        // (simmetrico ad assenza/ferie_ufficio/metti_salto, che già fanno così).
        $pdo->prepare(
            "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        $ordine = null;
        if ($posizioneId > 0) {
            // Riga esatta dove è stato lasciato cadere: niente riallineamento
            // automatico. Se quella riga è nel frattempo occupata (o non è
            // stata indicata), prima libera come ripiego.
            $occupate = ordiniOccupati($pdo, $foglioId, $posizioneId, $vigileId);
            $ordine = ($rigaReq >= 1 && $rigaReq <= $cap && !in_array($rigaReq, $occupate, true))
                ? $rigaReq
                : primaRigaLibera($cap, $occupate);

            $nextAssId = nextId($pdo, 'assegnazioni');
            $pdo->prepare(
                "INSERT INTO assegnazioni
                 (id, foglio_id, posizione_id, vigile_id, ordine, in_straordinario)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$nextAssId, $foglioId, $posizioneId, $vigileId, $ordine, $straord]);
        }

        echo json_encode(['ok' => true, 'ordine' => $ordine]);
        exit;
    }

    // ── AJAX: togli un vigile IN STRAORDINARIO da una posizione, senza
    //    toccare salto_servizio/assenze (#143) — a differenza di 'rimuovi',
    //    che libera da TUTTO. Chi era richiamato dal salto torna in salto
    //    (resterEffettivi lo ritrova da solo, non essendo più in assegnazioni).
    if ($azione === 'rimuovi_da_posizione') {
        $vigileId    = (int)($_POST['vigile_id']    ?? 0);
        $posizioneId = (int)($_POST['posizione_id'] ?? 0);
        $pdo->prepare(
            "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=? AND posizione_id=?"
        )->execute([$foglioId, $vigileId, $posizioneId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: scambia due vigili tra le loro posizioni ──────────
    // Trascinando una card su un'altra card (in posizione diversa) i due si
    // scambiano di posto: A prende posto/ordine di B e viceversa. Capienza
    // invariata (il numero per posizione non cambia), quindi nessun "pieno".
    if ($azione === 'scambia_posizioni') {
        $aId = (int)($_POST['vigile_a_id'] ?? 0);
        $bId = (int)($_POST['vigile_b_id'] ?? 0);
        if (!$aId || !$bId || $aId === $bId) {
            echo json_encode(['ok' => false, 'errore' => 'Selezione non valida.']); exit;
        }
        $st = $pdo->prepare(
            "SELECT vigile_id, posizione_id, ordine
             FROM assegnazioni WHERE foglio_id=? AND vigile_id IN (?,?)"
        );
        $st->execute([$foglioId, $aId, $bId]);
        $rows = [];
        foreach ($st->fetchAll() as $r) $rows[(int)$r['vigile_id']] = $r;
        if (!isset($rows[$aId], $rows[$bId])) {
            echo json_encode(['ok' => false, 'errore' => 'Uno dei due vigili non è assegnato a una posizione.']); exit;
        }
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare(
                "UPDATE assegnazioni SET posizione_id=?, ordine=? WHERE foglio_id=? AND vigile_id=?"
            );
            $up->execute([(int)$rows[$bId]['posizione_id'], (int)$rows[$bId]['ordine'], $foglioId, $aId]);
            $up->execute([(int)$rows[$aId]['posizione_id'], (int)$rows[$aId]['ordine'], $foglioId, $bId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB: ' . $e->getMessage()]); exit;
        }
        echo json_encode(['ok' => true]); exit;
    }

    // ── AJAX: sposta un vigile già assegnato su una riga VUOTA ──────
    // Stessa squadra o un'altra: aggiorna solo posizione+riga del vigile
    // trascinato, non tocca nessun altro. Niente riallineamento automatico:
    // se la riga di destinazione è già occupata, rifiuta (va usato lo scambio).
    if ($azione === 'sposta_riga') {
        $vigileId    = (int)($_POST['vigile_id']    ?? 0);
        $posizioneId = (int)($_POST['posizione_id'] ?? 0);
        $riga        = (int)($_POST['ordine']       ?? 0);
        if (!$vigileId || $posizioneId <= 0 || $riga < 1) {
            echo json_encode(['ok' => false, 'errore' => 'Dati mancanti.']); exit;
        }
        $cap = capPos($posizioneId);
        if ($riga > $cap) {
            echo json_encode(['ok' => false, 'errore' => 'Riga fuori capienza.']); exit;
        }
        $occupate = ordiniOccupati($pdo, $foglioId, $posizioneId, $vigileId);
        if (in_array($riga, $occupate, true)) {
            echo json_encode(['ok' => false, 'errore' => 'Riga già occupata.']); exit;
        }
        $st = $pdo->prepare(
            "UPDATE assegnazioni SET posizione_id=?, ordine=? WHERE foglio_id=? AND vigile_id=?"
        );
        $st->execute([$posizioneId, $riga, $foglioId, $vigileId]);
        if ($st->rowCount() === 0) {
            echo json_encode(['ok' => false, 'errore' => 'Vigile non assegnato a una posizione.']); exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: rimuovi assegnazione ────────────────────────────
    if ($azione === 'rimuovi') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        // Libera il vigile da QUALUNQUE stato sul foglio → torna disponibile
        // (posizione, salto e anche assenze: usato da "Centrale (disponibili)"
        //  del menu tasto destro su chi è in ferie/missione/ecc.).
        $pdo->prepare(
            "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: assegna un NOME ESTERNO a una posizione ─────────
    // Crea (o sposta) un nome libero non presente in `vigili`. Default STR.
    if ($azione === 'assegna_esterno') {
        $posizioneId = (int)($_POST['posizione_id'] ?? 0);
        $nome        = trim($_POST['nome'] ?? '');
        $straord     = (int)($_POST['straordinario'] ?? 1) ? 1 : 0;
        $patForzata  = in_array($_POST['patente_forzata'] ?? '', ['2', '3'], true) ? $_POST['patente_forzata'] : null;
        $extId       = (int)($_POST['ext_id'] ?? 0);   // >0 = sposta esistente
        $rigaReq     = (int)($_POST['ordine'] ?? 0);    // riga scelta col drop, 0 = nessuna preferenza
        $nome        = mb_substr($nome, 0, 80);
        if ($posizioneId <= 0 || $nome === '') {
            echo json_encode(['ok' => false, 'errore' => 'Nome o posizione mancanti.']); exit;
        }
        // Capienza: escludi sé stesso se è uno spostamento nella stessa posizione
        $cap = capPos($posizioneId);
        $occ = countOccupanti($pdo, $foglioId, $posizioneId, 0);
        if ($extId > 0) {
            $stP = $pdo->prepare("SELECT posizione_id FROM assegnazioni_esterni WHERE id=? AND foglio_id=?");
            $stP->execute([$extId, $foglioId]);
            if ((int)$stP->fetchColumn() === $posizioneId) $occ--;   // già qui dentro
        }
        if ($occ >= $cap) {
            echo json_encode(['ok' => false, 'pieno' => true, 'errore' => "Posizione al completo (max $cap)."]); exit;
        }

        // Riga esatta dove è stato lasciato cadere (non solo tra gli altri
        // esterni: anche i vigili della stessa squadra occupano una riga).
        // Se occupata o non indicata, prima libera come ripiego (#123).
        $occupateExt = ordiniOccupati($pdo, $foglioId, $posizioneId, 0, $extId);
        $ordine = ($rigaReq >= 1 && $rigaReq <= $cap && !in_array($rigaReq, $occupateExt, true))
            ? $rigaReq
            : primaRigaLibera($cap, $occupateExt);

        if ($extId > 0) {   // sposta
            $pdo->prepare(
                "UPDATE assegnazioni_esterni SET posizione_id=?, ordine=?, in_straordinario=?, patente_forzata=?
                 WHERE id=? AND foglio_id=?"
            )->execute([$posizioneId, $ordine, $straord, $patForzata, $extId, $foglioId]);
        } else {            // crea
            $extId = nextId($pdo, 'assegnazioni_esterni');
            $pdo->prepare(
                "INSERT INTO assegnazioni_esterni (id, foglio_id, posizione_id, nome, in_straordinario, patente_forzata, ordine)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$extId, $foglioId, $posizioneId, $nome, $straord, $patForzata, $ordine]);
        }
        echo json_encode(['ok' => true, 'ext_id' => $extId, 'ordine' => $ordine]);
        exit;
    }

    // ── AJAX: rimuovi un NOME ESTERNO ─────────────────────────
    if ($azione === 'rimuovi_esterno') {
        $extId = (int)($_POST['ext_id'] ?? 0);
        $pdo->prepare("DELETE FROM assegnazioni_esterni WHERE id=? AND foglio_id=?")
            ->execute([$extId, $foglioId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: una FERIE oggi per questo vigile sarebbe "d'ufficio" (NESSUNA
    // richiesta Telegram/forzata dietro, di NESSUNO stato)? Sola lettura,
    // usata PRIMA di agire per decidere se chiedere "quanti turni" (#144) —
    // stessa domanda sia da drag su Ferie sia da tasto destro.
    // IMPORTANTE: qualunque stato conta (anche rejected/declined), non solo
    // pending/approved — se esiste una richiesta respinta per questa data,
    // NON è "d'ufficio da zero": deve passare dal percorso normale
    // (azione 'assenza' più sotto) che la riapprova, non aprire un nuovo
    // blocco multi-turno ignorando quella richiesta (bug segnalato da Lele).
    if ($azione === 'check_ferie_ufficio') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        $stU = $pdo->prepare(
            "SELECT 1 FROM bot_requests WHERE vigile_id=? AND data_richiesta=? AND tipo_assenza_id=1 LIMIT 1"
        );
        $stU->execute([$vigileId, $dataStr]);
        $ufficio = !(bool) $stU->fetchColumn();
        echo json_encode(['ok' => true, 'ufficio' => $ufficio]);
        exit;
    }

    // ── AJAX: aggiungi assenza ────────────────────────────────

    if ($azione === 'assenza') {
        $vigileId      = (int)($_POST['vigile_id']       ?? 0);
        $tipoAssenzaId = (int)($_POST['tipo_assenza_id'] ?? 0);
        $sedeDist      = trim($_POST['sede_distaccata']  ?? '');
        $notaAss       = trim($_POST['note']             ?? '');
        $dataDa        = trim($_POST['data_da']          ?? '') ?: null;
        $dataA         = trim($_POST['data_a']           ?? '') ?: null;
        $nrTurni       = (int)($_POST['nr_turni']        ?? 0) ?: null;

        // Rimuove da posizioni e salto
        $pdo->prepare(
            "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        // Rimuove assenza precedente (stessa giornata)
        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        $nextAssenzaId = nextId($pdo, 'assenze');
        $pdo->prepare(
            "INSERT INTO assenze
             (id, foglio_id, vigile_id, tipo_assenza_id,
              sede_distaccata, data_da, data_a, nr_turni, note)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            $nextAssenzaId, $foglioId, $vigileId, $tipoAssenzaId,
            $sedeDist ?: null, $dataDa, $dataA, $nrTurni, $notaAss ?: null
        ]);

        // Se è una FERIE (tipo=1) e c'era una richiesta respinta/decisa-negativamente
        // per questa data, riportala ad approved → sulla pagina ferie torna flaggata "accetto".
        if ($tipoAssenzaId === 1) {
            $stReq = $pdo->prepare(
                "SELECT tipo_turno FROM bot_requests
                 WHERE vigile_id=? AND data_richiesta=? AND stato IN ('rejected','declined') AND tipo_assenza_id=1
                 ORDER BY id DESC LIMIT 1"
            );
            $stReq->execute([$vigileId, $dataStr]);
            $reqTipo = $stReq->fetchColumn();

            if ($reqTipo !== false) {
                $pdo->prepare(
                    "UPDATE bot_requests SET stato='approved', processed_at=NOW()
                     WHERE vigile_id=? AND data_richiesta=? AND stato IN ('rejected','declined') AND tipo_assenza_id=1"
                )->execute([$vigileId, $dataStr]);

                // Richiesta DN → ripristina la ferie anche sull'altro turno del giorno
                if ($reqTipo === 'DN') {
                    $tipoPaired = ($tipoParam === 'D') ? 'N' : 'D';
                    $stF = $pdo->prepare(
                        "SELECT id FROM fogli_servizio WHERE turno=? AND data_servizio=? AND tipo_turno=?"
                    );
                    $stF->execute([$TURNO, $dataStr, $tipoPaired]);
                    $fidPaired = $stF->fetchColumn();
                    if (!$fidPaired) {
                        $fidPaired = nextId($pdo, 'fogli_servizio');
                        $pdo->prepare(
                            "INSERT INTO fogli_servizio (id,turno,data_servizio,tipo_turno,salto_riposo_id,creato_da)
                             VALUES (?,?,?,?,1,'ferie')"
                        )->execute([$fidPaired, $TURNO, $dataStr, $tipoPaired]);
                    }
                    $stChk = $pdo->prepare(
                        "SELECT id FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
                    );
                    $stChk->execute([$fidPaired, $vigileId]);
                    if (!$stChk->fetchColumn()) {
                        $nid = nextId($pdo, 'assenze');
                        $pdo->prepare(
                            "INSERT INTO assenze (id,foglio_id,vigile_id,tipo_assenza_id) VALUES (?,?,?,1)"
                        )->execute([$nid, $fidPaired, $vigileId]);
                    }
                }
            }
        }

        // Per le ferie: è "a mano" (d'ufficio) se non c'è una richiesta Telegram dietro.
        // Serve al client per mostrarla anche nel box "Ferie d'ufficio" (ridondanza).
        $isUfficio = false;
        $avvisiScambioFerie = [];
        if ($tipoAssenzaId === 1) {
            $stU = $pdo->prepare(
                "SELECT 1 FROM bot_requests
                 WHERE vigile_id=? AND data_richiesta=? AND stato IN ('pending','approved')
                   AND tipo_assenza_id=1 LIMIT 1"
            );
            $stU->execute([$vigileId, $dataStr]);
            $isUfficio = !(bool) $stU->fetchColumn();
            // #188: la ferie appena assegnata potrebbe riguardare un vigile
            // coinvolto in uno scambio salto attivo su questo turno.
            $avvisiScambioFerie = scambioConflittoFerie($pdo, $foglioId, $dataStr, $tipoParam);
        }

        echo json_encode(['ok' => true, 'ufficio' => $isUfficio, 'avvisi_scambio_ferie' => $avvisiScambioFerie]);
        exit;
    }

    // ── AJAX: ferie d'ufficio (assenza ferie diretta, senza richiesta) ──
    // #144: N turni consecutivi a partire da questo servizio, non solo il foglio
    // corrente. Nessun bot_request creato: restano "d'ufficio" per costruzione,
    // separate da quelle Telegram (che sono l'UNICA cosa che genera comunicazioni/
    // mail — vedi contaFerieDaNotificare, invariata).
    if ($azione === 'ferie_ufficio') {
        require_once __DIR__ . '/../includes/ferie_assenze.php';   // feriaGetOrCreateFoglio
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        $nTurni   = max(1, min(60, (int)($_POST['n_turni'] ?? 1)));
        if ($vigileId <= 0) { echo json_encode(['ok' => false]); exit; }

        // Libera da posizioni/salto/assenze sul foglio corrente
        $pdo->prepare("DELETE FROM assegnazioni  WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $vigileId]);
        $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $vigileId]);
        $pdo->prepare("DELETE FROM assenze        WHERE foglio_id=? AND vigile_id=?")->execute([$foglioId, $vigileId]);

        // N slot consecutivi del turno, a partire da questo servizio.
        $slots = [['data' => $dataStr, 'tipo' => $tipoParam]];
        $cur   = $slots[0];
        for ($i = 1; $i < $nTurni; $i++) {
            $next = servizioAdiacenteB($cur['data'], $cur['tipo'], 1, $TURNO);
            if (!$next) break;   // fine calendario noto: si ferma qui, non blocca
            $slots[] = $next;
            $cur = $next;
        }
        $nEff   = count($slots);
        $dataDa = $slots[0]['data'];
        $dataA  = $slots[$nEff - 1]['data'];

        $avvisiScambioFerie = [];
        foreach ($slots as $s) {
            $fid = ($s['data'] === $dataStr && $s['tipo'] === $tipoParam)
                ? $foglioId
                : feriaGetOrCreateFoglio($pdo, $s['data'], $s['tipo'], $TURNO);
            $stEx = $pdo->prepare(
                "SELECT id FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
            );
            $stEx->execute([$fid, $vigileId]);
            $aid = $stEx->fetchColumn();
            if ($aid) {
                $pdo->prepare("UPDATE assenze SET nr_turni=?, data_da=?, data_a=? WHERE id=?")
                    ->execute([$nEff, $dataDa, $dataA, $aid]);
            } else {
                $nid = nextId($pdo, 'assenze');
                $pdo->prepare(
                    "INSERT INTO assenze (id, foglio_id, vigile_id, tipo_assenza_id, nr_turni, data_da, data_a)
                     VALUES (?, ?, ?, 1, ?, ?, ?)"
                )->execute([$nid, $fid, $vigileId, $nEff, $dataDa, $dataA]);
            }
            // #188: ferie d'ufficio su un turno dove il vigile è anche coinvolto
            // in uno scambio salto attivo — solo avviso, decide la fureria.
            $avvisiScambioFerie = array_merge(
                $avvisiScambioFerie, scambioConflittoFerie($pdo, (int)$fid, $s['data'], $s['tipo'])
            );
        }
        $avvisiScambioFerie = array_values(array_unique($avvisiScambioFerie));

        echo json_encode(['ok' => true, 'n_turni' => $nEff, 'data_da' => $dataDa, 'data_a' => $dataA,
                           'avvisi_scambio_ferie' => $avvisiScambioFerie]);
        exit;
    }

    // ── AJAX: togli ferie d'ufficio ───────────────────────────
    if ($azione === 'rimuovi_ufficio') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
        )->execute([$foglioId, $vigileId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: rimuovi assenza ─────────────────────────────────
    if ($azione === 'rimuovi_assenza') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);

        // È una ferie (tipo=1)?
        $stCheck = $pdo->prepare(
            "SELECT 1 FROM assenze WHERE foglio_id=? AND vigile_id=? AND tipo_assenza_id=1"
        );
        $stCheck->execute([$foglioId, $vigileId]);
        $isFeria = (bool) $stCheck->fetchColumn();

        // C'è una richiesta Telegram di FERIE dietro? (ferie a mano = nessuna
        // richiesta). Filtro tipo_assenza_id=1: senza, una richiesta pending di
        // un ALTRO tipo (permesso/malattia) sulla stessa data faceva scattare per
        // errore il ramo sotto e la respingeva (bug scoperto su un permesso
        // orario respinto per errore mentre si toglieva una ferie d'ufficio).
        $stReq = $pdo->prepare(
            "SELECT 1 FROM bot_requests
             WHERE vigile_id=? AND data_richiesta=? AND stato IN ('pending','approved')
               AND tipo_assenza_id=1 LIMIT 1"
        );
        $stReq->execute([$vigileId, $dataStr]);
        $eraRichiesta = (bool) $stReq->fetchColumn();

        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        // Solo le ferie DA RICHIESTA vanno "respinte" (pulizia DN + stato declined:
        // decisa ma non ancora comunicata, come da Agenda — diventa rejected solo
        // all'invio vero, finalizeFerie).
        if ($isFeria && $eraRichiesta) {
            $tipoPaired = ($tipoParam === 'D') ? 'N' : 'D';
            $pdo->prepare(
                "DELETE a FROM assenze a
                 JOIN fogli_servizio f ON f.id = a.foglio_id
                 WHERE a.vigile_id=? AND f.data_servizio=? AND f.tipo_turno=? AND a.tipo_assenza_id=1"
            )->execute([$vigileId, $dataStr, $tipoPaired]);

            $pdo->prepare(
                "UPDATE bot_requests SET stato='declined', processed_at=NULL
                 WHERE vigile_id=? AND data_richiesta=? AND stato IN ('pending','approved') AND tipo_assenza_id=1"
            )->execute([$vigileId, $dataStr]);
        }

        echo json_encode(['ok' => true, 'era_feria' => $isFeria, 'era_richiesta' => $eraRichiesta]);
        exit;
    }

    // ── AJAX: metti in salto ──────────────────────────────────
    if ($azione === 'metti_salto') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);

        $pdo->prepare(
            "DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM assenze WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);
        $pdo->prepare(
            "DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        $nextSaltoId = nextId($pdo, 'salto_servizio');
        $pdo->prepare(
            "INSERT INTO salto_servizio (id, foglio_id, vigile_id, richiamato)
             VALUES (?,?,?,0)"
        )->execute([$nextSaltoId, $foglioId, $vigileId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: richiama da salto (straordinario) ───────────────
    if ($azione === 'richiama_salto') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        $pdo->prepare(
            "UPDATE salto_servizio SET richiamato=1
             WHERE foglio_id=? AND vigile_id=?"
        )->execute([$foglioId, $vigileId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: cambio salto turno (bidirezionale, bypass Telegram) ──
    // Replica la logica del bot (approva_scambio): scrive bot_scambi_salto
    // 'approvato' + 4 righe salto_override (D+N di A, D+N di B) e patcha i
    // fogli esistenti. A = chi cede il salto (riposa su questo foglio),
    // B = controparte di un altro slot col riposo nello stesso blocco.
    if ($azione === 'scambia_salto') {
        $aId = (int)($_POST['vigile_a_id'] ?? 0);
        $bId = (int)($_POST['vigile_b_id'] ?? 0);
        if (!$aId || !$bId || $aId === $bId) {
            echo json_encode(['ok' => false, 'errore' => 'Selezione non valida.']); exit;
        }

        // Slot (1..8) dei due vigili
        $stV = $pdo->prepare(
            "SELECT v.id, v.attivo, CAST(SUBSTRING(st.codice,2) AS UNSIGNED) AS slot
             FROM vigili v JOIN salti_turno st ON st.id = v.salto_id
             WHERE v.id IN (?,?)"
        );
        $stV->execute([$aId, $bId]);
        $info = [];
        foreach ($stV->fetchAll() as $r) $info[(int)$r['id']] = $r;
        if (!isset($info[$aId], $info[$bId]) || !$info[$aId]['attivo'] || !$info[$bId]['attivo']) {
            echo json_encode(['ok' => false, 'errore' => 'Vigile inesistente o non attivo.']); exit;
        }
        $slotA = (int)$info[$aId]['slot'];
        $slotB = (int)$info[$bId]['slot'];

        if ($slotA !== saltoRiposoNum($dataStr, $tipoParam)) {
            echo json_encode(['ok' => false, 'errore' => 'Il primo vigile non è a riposo su questo foglio.']); exit;
        }
        if ($slotA === $slotB) {
            echo json_encode(['ok' => false, 'errore' => 'I due vigili sono dello stesso salto.']); exit;
        }

        $aOcc = slotDatesInBlocco($slotA, $dataStr, $TURNO);
        $bOcc = slotDatesInBlocco($slotB, $dataStr, $TURNO);
        if (!$aOcc || !$bOcc) {
            echo json_encode(['ok' => false, 'errore' => 'Controparte fuori dal blocco.']); exit;
        }
        // Scambio a ritroso (riposo controparte già passato): solo admin.
        if ($bOcc[0][0] <= date('Y-m-d') && !isAdmin()) {
            echo json_encode(['ok' => false, 'errore' => 'Il riposo della controparte è già passato.']); exit;
        }

        [$bloccoIni, $bloccoFin] = bloccoConfini($dataStr, $TURNO);

        // 4 righe override: (data, tipo, vigile_out, vigile_in) — i tipi D/N
        // vengono dall'occorrenza reale (per i turni A e D il riposo è N + D
        // del giro dopo, non la coppia D+N consecutiva del turno B).
        $rows = [
            [$aOcc[0][0], $aOcc[0][1], $aId, $bId],
            [$aOcc[1][0], $aOcc[1][1], $aId, $bId],
            [$bOcc[0][0], $bOcc[0][1], $bId, $aId],
            [$bOcc[1][0], $bOcc[1][1], $bId, $aId],
        ];

        // Guardia: nessuno scambio attivo già presente su queste righe coi due vigili
        $stDup = $pdo->prepare(
            "SELECT COUNT(*) FROM salto_override
             WHERE attivo=1 AND data=? AND tipo=?
               AND (vigile_in_id IN (?,?) OR vigile_out_id IN (?,?))"
        );
        foreach ($rows as [$d, $t, , ]) {
            $stDup->execute([$d, $t, $aId, $bId, $aId, $bId]);
            if ((int)$stDup->fetchColumn() > 0) {
                echo json_encode(['ok' => false,
                    'errore' => 'Esiste già uno scambio attivo per uno dei due vigili in questo blocco.']); exit;
            }
        }

        $pdo->beginTransaction();
        try {
            $sid = nextId($pdo, 'bot_scambi_salto');
            $pdo->prepare(
                "INSERT INTO bot_scambi_salto
                    (id, vigile_a_id, vigile_b_id, slot_a, slot_b,
                     blocco_inizio, blocco_fine, stato, creato_da, approvato_da)
                 VALUES (?,?,?,?,?,?,?, 'approvato', ?, ?)"
            )->execute([$sid, $aId, $bId, $slotA, $slotB, $bloccoIni, $bloccoFin, $aId, $aId]);

            scambioScriviOverride($pdo, $sid, $rows);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB: ' . $e->getMessage()]); exit;
        }

        echo json_encode(['ok' => true, 'scambio_id' => $sid]); exit;
    }

    // ── AJAX: annulla cambio salto turno (reversibile) ──────────
    // Inverte lo scambio: per ogni riga override attiva del scambio rimette
    // vigile_out in salto e toglie vigile_in dai fogli esistenti, poi
    // disattiva le righe override e marca lo scambio 'annullato'.
    if ($azione === 'annulla_scambio') {
        $sid = (int)($_POST['scambio_id'] ?? 0);
        if (!$sid) {
            echo json_encode(['ok' => false, 'errore' => 'Scambio non valido.']); exit;
        }
        $stA = $pdo->prepare("SELECT vigile_a_id FROM bot_scambi_salto WHERE id=?");
        $stA->execute([$sid]);
        $vigileAId = (int)$stA->fetchColumn();

        $pdo->beginTransaction();
        try {
            $fatto = scambioAnnulla($pdo, $sid);
            // Avvisa i due vigili (Telegram + il bot compone il messaggio): la
            // validazione dello scambio è solo gestionale, la fureria non riceve
            // nulla su Telegram, ma i vigili sì (stesso canale di approva/rifiuta).
            if ($fatto && $vigileAId) {
                scambioEnqueueOutbox($pdo, $vigileAId, 'scambio_annullato', "scambio:$sid:annullato");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'errore' => 'Errore DB: ' . $e->getMessage()]); exit;
        }
        if (!$fatto) {
            echo json_encode(['ok' => false, 'errore' => 'Scambio già annullato o inesistente.']); exit;
        }

        echo json_encode(['ok' => true]); exit;
    }

    // ── AJAX: reset foglio ───────────────────────────────────
    // Ricostruisce salti e assegnazioni dallo stato CANONICO:
    //   - salti = salto_id ± scambi salto APPROVATI (resterEffettivi)
    //   - ferie respinte tornano richieste (rejected → pending) e riavranno l'assenza
    //   - le assenze GIÀ INSERITE (ferie, ferie d'ufficio, malattia, missione,
    //     permessi) RESTANO: il reset non le tocca, aggiunge solo le ferie bot
    //     attive eventualmente senza assenza (es. quelle appena tornate pending)
    if ($azione === 'reset_foglio') {
        $tipiTurno = ($tipoParam === 'D') ? ['D', 'DN'] : ['N', 'DN'];
        $phTt      = implode(',', array_fill(0, count($tipiTurno), '?'));

        // 1. Le richieste RESPINTE (comunicate o solo decise) su questo turno
        //    tornano richieste (annulla la decisione manuale della fureria): →
        //    pending. Tutti i tipi (ferie/missione/permesso/malattia/infortunio)
        //    — richiesta esplicita di Lele: il reset deve risincronizzare
        //    l'Agenda per intero, non solo le ferie (comportamento storico,
        //    limitato a tipo_assenza_id=1 per via di un bug ormai risolto: un
        //    permesso orario respinto veniva riportato 'pending' per errore
        //    perché all'epoca la query non filtrava affatto per tipo — qui il
        //    filtro resta rimosso di proposito, non è la stessa svista).
        $pdo->prepare(
            "UPDATE bot_requests SET stato='pending', processed_at=NULL
             WHERE data_richiesta=? AND stato IN ('rejected','declined')
               AND tipo_turno IN ($phTt)"
        )->execute(array_merge([$dataStr], $tipiTurno));

        // 2. Richieste bot attive (pending/approved) sul turno, di qualunque
        //    tipo: chi non ha ancora un'assenza su questo foglio riceve
        //    l'assenza del tipo della SUA richiesta (non più sempre FER
        //    hardcoded). Le assenze esistenti restano com'erano.
        //    ora_da IS NULL: il permesso ORARIO non tocca mai `assenze` (il
        //    vigile resta assegnato al turno, vedi permessoOrarioSync) — qui
        //    andrebbe escluso, altrimenti gli si crea un'assenza per un'intera
        //    giornata che non ha mai chiesto.
        $stReq = $pdo->prepare(
            "SELECT DISTINCT vigile_id, tipo_assenza_id FROM bot_requests
             WHERE data_richiesta=? AND stato IN ('pending','approved')
               AND tipo_turno IN ($phTt) AND ora_da IS NULL"
        );
        $stReq->execute(array_merge([$dataStr], $tipiTurno));
        $richiesteAttive = $stReq->fetchAll();

        $stAss = $pdo->prepare("SELECT vigile_id FROM assenze WHERE foglio_id=?");
        $stAss->execute([$foglioId]);
        $giaAssenti = array_flip(array_map('intval', $stAss->fetchAll(PDO::FETCH_COLUMN)));

        $mancanti = array_values(array_filter($richiesteAttive,
            fn($r) => !isset($giaAssenti[(int)$r['vigile_id']])));
        if ($mancanti) {
            $nid = nextId($pdo, 'assenze');
            $insAss = $pdo->prepare(
                "INSERT INTO assenze (id, foglio_id, vigile_id, tipo_assenza_id) VALUES (?,?,?,?)"
            );
            foreach ($mancanti as $r) {
                $insAss->execute([$nid++, $foglioId, (int)$r['vigile_id'], (int)$r['tipo_assenza_id']]);
            }
        }

        // 3. Ricostruisci salti + assegnazioni dallo stato canonico.
        //    prepopolaAssegnazioni salta chi ha un'assenza (di qualunque tipo).
        prepopolaFoglio($pdo, $foglioId, $saltoRiposoId,
            resterEffettivi($pdo, $dataStr, $tipoParam, $saltoRiposoId), $dataStr, $tipoParam);

        // #188: il ricarico dopo reset (location.reload()) rifà il controllo
        // ferie/scambio salto da zero — vedi AVVISI_SCAMBIO_FERIE più sotto.
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: copia il servizio dal DIURNO dello STESSO SALTO (= ieri) ──
    // Solo sul notturno: il notturno di oggi è lo stesso turno/salto del DIURNO
    // del giorno PRECEDENTE (vedi turni.php: "il notturno è lo stesso turno del
    // giorno precedente"). Importo assegnazioni + salti manuali da quel diurno
    // come base. Le assenze del notturno restano; chi è assente in N non si importa.
    if ($azione === 'copia_da_diurno') {
        if ($tipoParam !== 'N') {
            echo json_encode(['ok' => false, 'errore' => 'Disponibile solo sul turno notturno.']); exit;
        }
        $dataPrec = (new DateTime($dataStr))->modify('-1 day')->format('Y-m-d');
        $stD = $pdo->prepare("SELECT id FROM fogli_servizio WHERE turno=? AND data_servizio=? AND tipo_turno='D'");
        $stD->execute([$TURNO, $dataPrec]);
        $diurnoId = (int)($stD->fetchColumn() ?: 0);
        if ($diurnoId <= 0) {
            echo json_encode(['ok' => false,
                'errore' => 'Nessun diurno dello stesso salto (' . date('d/m', strtotime($dataPrec)) . ') da copiare.']); exit;
        }
        // Assenti nel notturno (ferie/permessi/missione/malattia di stanotte): non importarli
        $stAbs = $pdo->prepare("SELECT vigile_id FROM assenze WHERE foglio_id=?");
        $stAbs->execute([$foglioId]);
        $setAssN = array_flip(array_map('intval', array_column($stAbs->fetchAll(), 'vigile_id')));

        // Pulisci il notturno (assegnazioni + salti manuali); le assenze restano
        $pdo->prepare("DELETE FROM assegnazioni  WHERE foglio_id=?")->execute([$foglioId]);
        $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=?")->execute([$foglioId]);

        // Copia assegnazioni dal diurno (saltando chi è assente nel notturno)
        $stSrcA = $pdo->prepare("SELECT posizione_id, vigile_id, ordine, in_straordinario
                                 FROM assegnazioni WHERE foglio_id=?");
        $stSrcA->execute([$diurnoId]);
        $nextA = nextId($pdo, 'assegnazioni');
        $insA  = $pdo->prepare("INSERT INTO assegnazioni
                    (id,foglio_id,posizione_id,vigile_id,ordine,in_straordinario) VALUES (?,?,?,?,?,?)");
        $nCopiati = 0;
        foreach ($stSrcA->fetchAll() as $r) {
            if (isset($setAssN[(int)$r['vigile_id']])) continue;
            $insA->execute([$nextA++, $foglioId, $r['posizione_id'], $r['vigile_id'],
                            $r['ordine'], $r['in_straordinario']]);
            $nCopiati++;
        }

        // Copia salti manuali dal diurno (saltando assenti nel notturno)
        $stSrcS = $pdo->prepare("SELECT vigile_id, richiamato FROM salto_servizio WHERE foglio_id=?");
        $stSrcS->execute([$diurnoId]);
        $nextS = nextId($pdo, 'salto_servizio');
        $insS  = $pdo->prepare("INSERT INTO salto_servizio (id,foglio_id,vigile_id,richiamato) VALUES (?,?,?,?)");
        foreach ($stSrcS->fetchAll() as $r) {
            if (isset($setAssN[(int)$r['vigile_id']])) continue;
            $insS->execute([$nextS++, $foglioId, $r['vigile_id'], $r['richiamato']]);
        }

        echo json_encode(['ok' => true, 'copiati' => $nCopiati]);
        exit;
    }

    echo json_encode(['ok' => false, 'errore' => 'Azione non riconosciuta']);
    exit;
}

// ── Auto-popolamento fogli "nudi" creati dal bot ─────────────
// Quando un vigile chiede ferie, il bot crea il foglio (+ assenza) ma NON popola
// salti/assegnazioni: restavano vuoti finché la fureria non faceva un reset a mano.
// Alla prima APERTURA (GET), se il foglio non è mai stato popolato (0 assegnazioni
// e 0 salto_servizio) e nasce dal flusso ferie (bot/ferie), lo popoliamo ora.
// Le ferie sono preservate: prepopolaAssegnazioni salta chi è già assente.
if (!$foglioBloccato && in_array(($foglio['creato_da'] ?? ''), ['bot', 'ferie'], true)) {
    $stChkA = $pdo->prepare("SELECT COUNT(*) FROM assegnazioni  WHERE foglio_id=?");
    $stChkA->execute([$foglioId]);
    $stChkS = $pdo->prepare("SELECT COUNT(*) FROM salto_servizio WHERE foglio_id=?");
    $stChkS->execute([$foglioId]);
    if ((int)$stChkA->fetchColumn() === 0 && (int)$stChkS->fetchColumn() === 0) {
        prepopolaFoglio($pdo, $foglioId, $saltoRiposoId,
            resterEffettivi($pdo, $dataStr, $tipoParam, $saltoRiposoId), $dataStr, $tipoParam);
    }
}

// ── Carica dati per la pagina ────────────────────────────────

// Sedi con le loro posizioni
$sedi = $pdo->query(
    "SELECT * FROM sedi ORDER BY ordine"
)->fetchAll();

$posizioni = $pdo->query(
    "SELECT p.*, s.codice AS sede_codice
     FROM posizioni p
     JOIN sedi s ON s.id = p.sede_id
     ORDER BY p.sede_id, p.ordine"
)->fetchAll();

// Raggruppa posizioni per sede
$posizioniPerSede = [];
foreach ($posizioni as $pos) {
    $posizioniPerSede[$pos['sede_id']][] = $pos;
}

// Tutto il personale attivo con qualifica e salto
$tuttoPersonale = $pdo->query(
    "SELECT v.*, q.codice AS qcodice,
            st.codice AS salto_codice, st.id AS salto_id_val,
            s.codice AS sede_codice, s.nome AS sede_nome,
            (
                SELECT MAX(p.tipo) FROM vigili_patenti vp
                JOIN patenti p ON p.id = vp.patente_id
                WHERE vp.vigile_id = v.id
            ) AS patente_max
     FROM vigili v
     JOIN qualifiche q   ON q.id  = v.qualifica_id
     JOIN salti_turno st ON st.id = v.salto_id
     JOIN sedi s         ON s.id  = v.sede_id
     WHERE v.attivo = 1 AND v.turno = '" . $TURNO . "'
     ORDER BY v.cognome ASC, v.disambiguatore ASC"
)->fetchAll();


// Assegnazioni esistenti per questo foglio
$assegnazioni = $pdo->prepare(
    "SELECT a.*, v.cognome, v.nome, v.disambiguatore,
            q.codice AS qcodice,
            st.codice AS salto_codice,
            s.codice AS sede_codice,
            s.nome AS sede_nome,
            (
                SELECT MAX(p.tipo) FROM vigili_patenti vp
                JOIN patenti p ON p.id = vp.patente_id
                WHERE vp.vigile_id = v.id
            ) AS patente_max
     FROM assegnazioni a
     JOIN vigili v       ON v.id  = a.vigile_id
     JOIN qualifiche q   ON q.id  = v.qualifica_id
     JOIN salti_turno st ON st.id = v.salto_id
     JOIN sedi s         ON s.id  = v.sede_id
     WHERE a.foglio_id = ?
     ORDER BY a.posizione_id, a.ordine"
);

$assegnazioni->execute([$foglioId]);
$assegnazioni = $assegnazioni->fetchAll();

// Capo/vice non compaiono nelle squadre anche se un vecchio foglio li avesse
// ancora assegnati (creato prima del fix che li rimuove al salvataggio).
$ruoliIntestazione = array_filter([
    (int)($foglio['capo_servizio_id'] ?? 0),
    (int)($foglio['vice_capo_id']     ?? 0),
]);
if ($ruoliIntestazione) {
    $assegnazioni = array_values(array_filter(
        $assegnazioni,
        fn($a) => !in_array((int)$a['vigile_id'], $ruoliIntestazione, true)
    ));
}

// Raggruppa assegnazioni per posizione
$assPerPosizione = [];
foreach ($assegnazioni as $ass) {
    $assPerPosizione[$ass['posizione_id']][] = $ass;
}

// Nomi esterni (turno C/sommozzatori/nautici) per posizione
$esterniPerPosizione = [];
$stE = $pdo->prepare(
    "SELECT id, posizione_id, nome, in_straordinario, patente_forzata, ordine
       FROM assegnazioni_esterni WHERE foglio_id=? ORDER BY posizione_id, ordine"
);
$stE->execute([$foglioId]);
foreach ($stE->fetchAll() as $e) {
    $esterniPerPosizione[(int)$e['posizione_id']][] = $e;
}

// Vigili assegnati (set di id)
$vigiliAssegnati = array_unique(array_column($assegnazioni, 'vigile_id'));

// Assenze
$stmtAss = $pdo->prepare(
    "SELECT a.*, v.cognome, v.nome, v.disambiguatore,
            q.codice AS qcodice,
            ta.nome AS tipo_nome, ta.codice AS tipo_codice,
            s.codice AS sede_codice, s.nome AS sede_nome,
            (
                SELECT MAX(p.tipo) FROM vigili_patenti vp
                JOIN patenti p ON p.id = vp.patente_id
                WHERE vp.vigile_id = v.id
            ) AS patente_max,
            (
                SELECT MAX(r.ferie_estiva) FROM bot_requests r
                WHERE r.vigile_id = a.vigile_id AND r.data_richiesta = ?
                  AND r.tipo_turno IN (?, 'DN') AND r.stato IN ('pending','approved')
            ) AS ferie_estiva
     FROM assenze a
     JOIN vigili v        ON v.id  = a.vigile_id
     JOIN qualifiche q    ON q.id  = v.qualifica_id
     JOIN tipo_assenza ta ON ta.id = a.tipo_assenza_id
     JOIN sedi s          ON s.id  = v.sede_id
     WHERE a.foglio_id = ?
     ORDER BY ta.id, v.cognome"
);

$stmtAss->execute([$dataStr, $tipoParam, $foglioId]);
$assenze = $stmtAss->fetchAll();


// Raggruppa assenze per tipo
$assenzePerTipo = [];
foreach ($assenze as $ass) {
    $assenzePerTipo[$ass['tipo_codice']][] = $ass;
}

// #159: turni consecutivi anche per le ferie ORDINARIE (da richiesta bot) —
// le ferie d'ufficio (#144) hanno già nr_turni scritto in assenze, qui si
// calcola SOLO dove manca. Sola lettura, stesso metodo di
// FoglioRenderer::arricchisciFerie() (blocchiContigui su assenze), nessuna
// scrittura: non tocca il flusso di approvazione in Agenda.
if (!empty($assenzePerTipo['FER'])) {
    // #224: spezza_dopo, stessa logica/join di FoglioRenderer::arricchisciBlocco().
    $stBlocco = $pdo->prepare(
        "SELECT f.data_servizio AS data_richiesta, f.tipo_turno,
                COALESCE(r.spezza_dopo, 0) AS spezza_dopo
           FROM assenze a JOIN fogli_servizio f ON f.id = a.foglio_id
           LEFT JOIN bot_requests r ON r.vigile_id = a.vigile_id
                AND r.data_richiesta = f.data_servizio
                AND (r.tipo_turno = f.tipo_turno OR r.tipo_turno = 'DN')
          WHERE a.vigile_id=? AND a.tipo_assenza_id=1
          ORDER BY f.data_servizio, f.tipo_turno"
    );
    foreach ($assenzePerTipo['FER'] as $i => $a) {
        if (!empty($a['nr_turni'])) continue;   // già valorizzato (ferie d'ufficio)
        $stBlocco->execute([(int)$a['vigile_id']]);
        $righe = array_map(fn($r) => $r + ['stato' => 'approved'], $stBlocco->fetchAll());
        $blocchi = blocchiContigui($righe);
        foreach ($blocchi as $b) {
            $da = $b[0]['data_richiesta'];
            $aa = end($b)['data_richiesta'];
            if ($dataStr >= $da && $dataStr <= $aa) {
                // #171: turni RESTANTI da questo foglio in poi (incluso), non
                // il totale fisso del blocco — così il numero scende foglio
                // dopo foglio invece di restare identico su tutta la ferie.
                // Nessun numero sull'ultimo foglio del blocco (resterebbe 1).
                $idxCorrente = null;
                foreach ($b as $bi => $br) {
                    if ($br['data_richiesta'] === $dataStr
                        && ($br['tipo_turno'] === $tipoParam || $br['tipo_turno'] === 'DN')) {
                        $idxCorrente = $bi;
                        break;
                    }
                }
                $restanti = turniLabel($idxCorrente !== null ? array_slice($b, $idxCorrente) : $b);
                $assenzePerTipo['FER'][$i]['nr_turni'] = $restanti > 1 ? $restanti : null;
                $assenzePerTipo['FER'][$i]['data_da']  = $da;
                $assenzePerTipo['FER'][$i]['data_a']   = $aa;
                break;
            }
        }
    }
}

// Vigili assenti (set di id)
$vigiliAssenti = array_unique(array_column($assenze, 'vigile_id'));

// Vigili in salto
$stmtSalto = $pdo->prepare(
    "SELECT ss.*, v.cognome, v.nome, v.disambiguatore,
            q.codice AS qcodice, st.codice AS salto_codice
     FROM salto_servizio ss
     JOIN vigili v       ON v.id  = ss.vigile_id
     JOIN qualifiche q   ON q.id  = v.qualifica_id
     JOIN salti_turno st ON st.id = v.salto_id
     WHERE ss.foglio_id = ?
     ORDER BY v.cognome"
);
$stmtSalto->execute([$foglioId]);
$vigiliInSalto = $stmtSalto->fetchAll();

// Chi è in salto su questo foglio — calcolo ROBUSTO. NON dipende dal fatto che
// salto_servizio sia stato pre-popolato: quella tabella resta vuota per i fogli
// creati dal bot (richiesta ferie), per quelli mai pre-popolati e dopo un reset
// → prima i nomi sparivano dal riquadro salto al ricaricamento. Ora:
//   base = riposanti di diritto (canonico ± scambi salto) via resterEffettivi
//   + chi è stato messo in salto a mano (salto_servizio)
//   − chi è in ferie/assenza (se assente non è a riposo)
// Scambi salto attivi per questa data/turno: chi ENTRA (prende il salto) e chi CEDE
// (torna in servizio). Servono per marcare le card con un badge 🔄 e — sotto — per
// non ri-agganciare alla colonna salto chi ha CEDUTO il riposo (vigile_out).
$scambioIn = []; $scambioOut = [];
$stSc = $pdo->prepare(
    "SELECT vigile_in_id, vigile_out_id FROM salto_override
     WHERE data=? AND tipo=? AND attivo=1"
);
$stSc->execute([$dataStr, $tipoParam]);
foreach ($stSc->fetchAll() as $r) {
    $scambioIn[(int)$r['vigile_in_id']]   = true;
    $scambioOut[(int)$r['vigile_out_id']] = true;
}

// STR (richiamato) = riposante assegnato a una posizione, o flag su salto_servizio.
$setAssegnati    = array_flip(array_map('intval', $vigiliAssegnati));
$setAssenti      = array_flip(array_map('intval', $vigiliAssenti));
$inSaltoSet      = [];
$saltoRichiamato = [];
foreach (array_keys(resterEffettivi($pdo, $dataStr, $tipoParam, $saltoRiposoId)) as $vid) {
    $vid = (int)$vid;
    if (isset($setAssenti[$vid])) continue;
    $inSaltoSet[$vid]      = true;
    $saltoRichiamato[$vid] = isset($setAssegnati[$vid]) ? 1 : 0;
}
foreach ($vigiliInSalto as $r) {
    $vid = (int)$r['vigile_id'];
    if (isset($setAssenti[$vid]) || isset($inSaltoSet[$vid])) continue;
    // Chi ha CEDUTO il salto con uno scambio (vigile_out) lavora: non è in straordinario
    // e non va in colonna salto, anche se la vecchia riga salto_servizio è rimasta.
    if (isset($scambioOut[$vid])) continue;
    $inSaltoSet[$vid]      = true;
    $saltoRichiamato[$vid] = (int)$r['richiamato'] ? 1 : (isset($setAssegnati[$vid]) ? 1 : 0);
}
$idVigiliInSalto = array_keys($inSaltoSet);

// Vigili disponibili (non assegnati, non assenti, non in salto)
$vigiliOccupati = array_unique(array_merge(
    $vigiliAssegnati,
    $vigiliAssenti,
    $idVigiliInSalto
));

// Vigili con salto canonico di riposo oggi
$stSaltoCanon = $pdo->prepare("SELECT id FROM salti_turno WHERE codice=?");
$stSaltoCanon->execute([$codSaltoRip]);
$idSaltoRiposo = (int)($stSaltoCanon->fetchColumn() ?: 0);

// Vigili con ferie RESPINTA (comunicata o solo decisa) per questo turno →
// casella dedicata, fuori dall'organico.
$tipiRespinte = ($tipoParam === 'D') ? ['D', 'DN'] : ['N', 'DN'];
$phResp = implode(',', array_fill(0, count($tipiRespinte), '?'));
$stResp = $pdo->prepare(
    "SELECT DISTINCT vigile_id FROM bot_requests
     WHERE data_richiesta=? AND stato IN ('rejected','declined') AND tipo_turno IN ($phResp) AND tipo_assenza_id=1"
);
$stResp->execute(array_merge([$dataStr], $tipiRespinte));
$idFerieRespinte = array_map('intval', array_column($stResp->fetchAll(), 'vigile_id'));

// ── Visite mediche del giorno (#95): solo sui fogli DIURNI ──
// $visitaIds = badge 🚑 sulla card del vigile (che resta in squadra);
// $visiteFoglio = nomi duplicati nella casella 5A→"Visita Medica".
$visitaIds = []; $visiteFoglio = [];
if ($tipoParam === 'D') {
    try {
        $stVm = $pdo->prepare(
            "SELECT vm.vigile_id, v.cognome, v.disambiguatore, q.codice AS qcodice,
                    (SELECT MAX(p.tipo) FROM vigili_patenti vp
                     JOIN patenti p ON p.id = vp.patente_id
                     WHERE vp.vigile_id = v.id) AS patente_max
             FROM visite_mediche vm
             JOIN vigili v     ON v.id = vm.vigile_id
             JOIN qualifiche q ON q.id = v.qualifica_id
             WHERE vm.data = ? AND v.turno = ? AND v.attivo = 1
             ORDER BY v.cognome"
        );
        $stVm->execute([$dataStr, $TURNO]);
        $visiteFoglio = $stVm->fetchAll();
        foreach ($visiteFoglio as $vm) $visitaIds[(int)$vm['vigile_id']] = true;
    } catch (Throwable $e) { /* tabella assente: nessuna visita */ }
}

// ── Permessi orari del giorno: badge 🕐 sulla card, il vigile RESTA in squadra
// (mai un'assenza — vedi permessoOrarioSync in ferie_assenze.php). A differenza
// delle visite mediche vale sia su D che su N, filtrato per tipo_turno così un
// permesso notturno non compare anche sul foglio diurno dello stesso giorno.
$permessoOrarioMap = [];
try {
    $stPo = $pdo->prepare(
        "SELECT po.vigile_id, po.ora_da, po.ora_a, po.note,
                v.cognome, v.disambiguatore, q.codice AS qcodice,
                s.nome AS sede_nome, s.codice AS sede_codice
         FROM permessi_orari po
         JOIN vigili v     ON v.id = po.vigile_id
         JOIN qualifiche q ON q.id = v.qualifica_id
         JOIN sedi s       ON s.id = v.sede_id
         WHERE po.data = ? AND po.tipo_turno = ? AND v.turno = ?"
    );
    $stPo->execute([$dataStr, $tipoParam, $TURNO]);
    foreach ($stPo->fetchAll() as $po) $permessoOrarioMap[(int)$po['vigile_id']] = $po;
} catch (Throwable $e) { /* tabella assente: nessun permesso orario */ }

// Vigili con ferie DA RICHIESTA bot (pending O approved) per questo turno.
// Solo le ferie SENZA richiesta attiva sono "d'ufficio" (a mano) → coerente con
// $isUfficio. Una ferie da richiesta sta SOLO in colonna Ferie, mai nel box ufficio
// (la ridondanza nel box vale solo per le ferie a mano).
$stApp = $pdo->prepare(
    "SELECT DISTINCT vigile_id FROM bot_requests
     WHERE data_richiesta=? AND stato IN ('pending','approved') AND tipo_turno IN ($phResp) AND tipo_assenza_id=1"
);
$stApp->execute(array_merge([$dataStr], $tipiRespinte));
$idFerieRichiesta = array_map('intval', array_column($stApp->fetchAll(), 'vigile_id'));

// La colonna Ferie mostra tutte le FER ($ferieTutte); il box "Ferie d'ufficio"
// solo quelle SENZA richiesta bot attiva (= a mano).
$ferieTutte   = $assenzePerTipo['FER'] ?? [];
$ferieUfficio = array_values(array_filter($ferieTutte,
    fn($a) => !in_array((int)$a['vigile_id'], $idFerieRichiesta)));

// Tipi assenza (RC escluso: il riposo compensativo è il salto turno → gestito
// dal cambio salto nel riquadro Salto, non come assenza separata)
$tipiAssenza = $pdo->query(
    "SELECT * FROM tipo_assenza WHERE codice <> 'RC' ORDER BY id"
)->fetchAll();

// Sedi per select sede distaccata
$sediSelect = $pdo->query(
    "SELECT codice, nome FROM sedi ORDER BY ordine"
)->fetchAll();

// ── Cambio salto turno (inserimento dal gestionale, bypass Telegram) ──
// A = chi cede il salto (a riposo su questo foglio); B = controparte di un
// altro slot col riposo (data_D) ancora futuro, nello stesso blocco B1→B8.
$slotRiposoOggi  = saltoRiposoNum($dataStr, $tipoParam);
$oggiStr         = date('Y-m-d');
// Admin (comando/admin) possono scambiare anche con uno slot il cui riposo è
// GIÀ PASSATO (scambio a ritroso); gli altri solo con riposi futuri.
$scambioRitroso  = isAdmin();
$contropartiSlot = [];   // slot => ['occ' (tipizzata), 'passato']
for ($k = 1; $k <= 8; $k++) {
    if ($k === $slotRiposoOggi) continue;
    $sd = slotDatesInBlocco($k, $dataStr, $TURNO);
    if (!$sd) continue;
    $passato = ($sd[0][0] <= $oggiStr);
    if ($passato && !$scambioRitroso) continue;
    $contropartiSlot[$k] = ['occ' => $sd, 'passato' => $passato];
}
// Vigili attivi raggruppati per slot (per i due menu del form)
$vigiliPerSlot = [];
$stVPS = $pdo->query(
    "SELECT v.id, v.cognome, v.nome, v.disambiguatore, q.codice AS qcodice,
            CAST(SUBSTRING(st.codice,2) AS UNSIGNED) AS slot
     FROM vigili v
     JOIN salti_turno st ON st.id = v.salto_id
     JOIN qualifiche  q  ON q.id  = v.qualifica_id
     WHERE v.attivo=1 AND v.turno = '" . $TURNO . "'
     ORDER BY v.cognome, v.nome"
);
foreach ($stVPS as $r) {
    $vigiliPerSlot[(int)$r['slot']][] = $r;
}
$cambioSaltoOk = !empty($contropartiSlot) && !empty($vigiliPerSlot[$slotRiposoOggi] ?? []);

// Scambi salto ATTIVI che toccano questo turno (per il tasto annulla)
$stSA = $pdo->prepare(
    "SELECT DISTINCT s.id, s.slot_a, s.slot_b,
            va.cognome AS a_cognome, va.disambiguatore AS a_disambiguatore,
            vb.cognome AS b_cognome, vb.disambiguatore AS b_disambiguatore
     FROM salto_override o
     JOIN bot_scambi_salto s ON s.id = o.scambio_id
     JOIN vigili va ON va.id = s.vigile_a_id
     JOIN vigili vb ON vb.id = s.vigile_b_id
     WHERE o.data=? AND o.tipo=? AND o.attivo=1 AND s.stato='approvato'
     ORDER BY s.id"
);
$stSA->execute([$dataStr, $tipoParam]);
$scambiAttivi = $stSA->fetchAll();

// Quante ferie PENDING verrebbero approvate generando l'ODT (per la conferma).
// Stessa logica di finalize_ferie: vigili in FER sul foglio con richiesta pending
// per questa data e turno (N→N/DN, D→D/DN).
$nFerieDaApprovare = contaFeriePending($pdo, $foglioId, $dataStr, $tipoParam);

// Furieri del foglio
// Nomi capo/vice per le drop-zone (qualsiasi vigile, non solo Centrale).
// #152: patente_max serve per colorare il nome come le altre card (stessa regola).
$patMaxCV = "(SELECT MAX(pt.tipo) FROM vigili_patenti vp JOIN patenti pt ON pt.id=vp.patente_id WHERE vp.vigile_id=v.id)";
$stCV = $pdo->prepare(
    "SELECT v.cognome, v.disambiguatore, q.codice AS qcodice, $patMaxCV AS patente_max
     FROM vigili v JOIN qualifiche q ON q.id = v.qualifica_id WHERE v.id = ?"
);
$ruoloInfo = function ($id) use ($stCV): array {
    $id = (int)$id;
    if ($id <= 0) return ['nome' => '', 'colore' => colorePatentePHP(null)];
    $stCV->execute([$id]);
    $r = $stCV->fetch();
    return $r
        ? ['nome' => etichettaVigile($r), 'colore' => colorePatentePHP($r['patente_max'] ?? null)]
        : ['nome' => '', 'colore' => colorePatentePHP(null)];
};
$capoInfo   = $ruoloInfo($foglio['capo_servizio_id'] ?? 0);
$viceInfo   = $ruoloInfo($foglio['vice_capo_id'] ?? 0);
$capoNome   = $capoInfo['nome'];
$viceNome   = $viceInfo['nome'];
$capoColore = $capoInfo['colore'];
$viceColore = $viceInfo['colore'];
$capoId   = (int)($foglio['capo_servizio_id'] ?? 0);
$viceId   = (int)($foglio['vice_capo_id'] ?? 0);

// Funzionari per la tendina dell'intestazione (anagrafica admin/funzionari)
$funzionariOpt = $pdo->query("SELECT nome FROM funzionari ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
$funzCorrente  = trim($foglio['funzionario'] ?? '');

// Helper: etichetta vigile "CS Rossi 4"
?>

<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Foglio di Servizio <?= $dataLabel ?></title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/foglio.css?v=<?= @filemtime(__DIR__.'/../assets/css/foglio.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
</head>
<body>

<!-- HEADER -->
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno <?= htmlspecialchars($TURNO) ?></p>
    </div>
    <div class="header-badge">TURNO&nbsp;<?= htmlspecialchars($TURNO) ?></div>
  </div>
</header>

<!-- NAVBAR -->
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🚒 Home</a>
    <a href="nuovo.php"           class="nav-btn active">📋 Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="../report/index.php" class="nav-btn">📊 Reportistica</a>
    <a href="../admin/index.php"  class="nav-btn">⚙️ Amministrazione</a>
    <a href="../logbook/index.php" class="nav-btn">📓 Logbook</a>
    <a href="../cambia_password.php" class="nav-btn ml-auto">🔑 Password</a>
    <?= turnoComandoHtml() ?>
    <a href="../logout.php"       class="nav-btn">🚪 Esci</a>
  </div>
</nav>

<div class="foglio-page">

  <!-- ══ INTESTAZIONE FOGLIO ══════════════════════════════════ -->
  <div class="foglio-header">

    <div class="foglio-header-top">
      <!-- Giorno/Notte, frecce, data, salto: stessa altezza, allineati a sinistra (#128) -->
      <div class="foglio-header-left">
        <!-- Badge turno -->
        <div class="foglio-turno-badge <?= $tipoParam==='D' ? 'diurno':'notturno' ?>">
          <span class="badge-tipo">
            <?= $tipoParam==='D' ? '☀️ Giorno':'🌙 Notte' ?>
          </span>
          <span class="badge-ora"><?= $oraLabel ?></span>
        </div>

        <!-- Frecce navigazione servizio (#88) -->
        <div class="fh-frecce">
          <?php if ($servPrec): ?>
          <a href="nuovo.php?data=<?= $servPrec['data'] ?>&tipo=<?= $servPrec['tipo'] ?>"
             class="btn btn-grigio"
             title="Servizio precedente (<?= htmlspecialchars($navLabel($servPrec)) ?>)">◀</a>
          <?php endif; ?>
          <?php if ($servSucc): ?>
          <a href="nuovo.php?data=<?= $servSucc['data'] ?>&tipo=<?= $servSucc['tipo'] ?>"
             class="btn btn-grigio"
             title="Servizio successivo (<?= htmlspecialchars($navLabel($servSucc)) ?>)">▶</a>
          <?php endif; ?>
        </div>

        <!-- Salto (#166: riquadro quadrato) -->
        <div class="fh-info-box fh-salto-box">
          <span class="fh-main"><?= htmlspecialchars($turnoAttivo['turno'].$turnoAttivo['salto']) ?></span>
        </div>

        <!-- Copia dal diurno (#161 accanto al salto, solo sul notturno; #166 su due righe) -->
        <?php if ($tipoParam === 'N'): ?>
        <div class="fh-info-box fh-info-btn">
          <button type="button" onclick="apriModalCopiaDiurno()"
                  title="Importa le squadre dal diurno dello stesso salto (il giorno prima) come base per il notturno">
            📋 Copia<br>dal diurno
          </button>
        </div>
        <?php endif; ?>
      </div>

      <!-- Data (#166: colonna centrale della griglia = centro esatto della riga) -->
      <div class="fh-info-box fh-data-box">
        <a href="../ferie/index.php?anno=<?= date('Y', strtotime($dataStr)) ?>&mese=<?= date('n', strtotime($dataStr)) ?>&goto=<?= urlencode($dataStr) ?>"
           title="Apri l'agenda su questo giorno" class="fh-main" style="text-decoration:none">
          <?= $dataLabel ?>
        </a>
      </div>

        <!-- Pulsanti, allineati a destra. Ordine (#170): Invia, Scarica .odt,
             Stampa, Precedente, Blocca/Sblocca, Reset. -->
        <div class="foglio-header-btns">
          <?php if (!soloLetturaAttivo()): ?>
          <button onclick="salvaIntestazioneAjax(true)"
                  class="btn btn-verde btn-sm">✉️ Invia</button>
          <?php else: ?>
          <span class="btn btn-sm" style="background:#5b6b7b;color:#fff;cursor:default">👁️ Sola lettura</span>
          <?php endif; ?>
          <a href="scarica_odt.php?data=<?= $dataStr ?>&tipo=<?= $tipoParam ?>&turno=<?= $TURNO ?>"
             class="btn btn-grigio btn-sm"
             data-nferie="<?= (int)$nFerieDaApprovare ?>"
             onclick="return scaricaOdt(this)">📄 Scarica .odt</a>
          <button type="button" onclick="window.open('stampa.php?id=<?= $foglioId ?>','_blank')"
                  class="btn btn-grigio btn-sm">🖨️ Stampa</button>
          <?php if ($foglioPrec):
              $precLabel = date('d/m/Y', strtotime($foglioPrec['data_servizio']))
                         . ' ' . ($foglioPrec['tipo_turno'] === 'N' ? 'Notturno' : 'Diurno'); ?>
          <button type="button" class="btn btn-grigio btn-sm"
                  onclick="window.open('stampa.php?id=<?= (int)$foglioPrec['id'] ?>','servPrec','width=1000,height=1000,scrollbars=yes,resizable=yes')"
                  title="Apre la stampa del servizio precedente (<?= htmlspecialchars($precLabel) ?>)">
            🕑 Precedente
          </button>
          <?php endif; ?>
          <?php if (!soloLetturaAttivo()): ?>
          <button id="btnBlocco" onclick="toggleBlocco()" class="btn btn-sm"></button>
          <?php endif; ?>
          <button onclick="apriModalReset()"
                  class="btn btn-sm" style="background:#c0392b;color:#fff">↺ Reset</button>
        </div>

        <!-- Modale reset -->
        <div id="modalReset" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
             z-index:9999;align-items:center;justify-content:center">
          <div style="background:#fff;border-radius:10px;padding:28px 32px;max-width:360px;
                      width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25);text-align:center">
            <div style="font-size:1.4rem;font-weight:700;margin-bottom:8px">Reset servizio</div>
            <div style="color:#555;margin-bottom:24px">
                Tutte le assegnazioni verranno cancellate e ricostruite da zero.<br><br>
              <strong>Le assenze inserite (ferie, malattie, missioni, permessi) restano invariate.</strong><br><br>
              Continuare?
            </div>
            <div style="display:flex;gap:12px;justify-content:center">
              <button onclick="chiudiModalReset()"
                      class="btn btn-grigio btn-sm">Annulla</button>
              <button onclick="eseguiReset()"
                      class="btn btn-sm" style="background:#c0392b;color:#fff">Sì, reset</button>
            </div>
          </div>
        </div>

        <?php if ($tipoParam === 'N'): ?>
        <!-- Modale copia dal diurno -->
        <div id="modalCopiaDiurno" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
             z-index:9999;align-items:center;justify-content:center">
          <div style="background:#fff;border-radius:10px;padding:28px 32px;max-width:380px;
                      width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25);text-align:center">
            <div style="font-size:1.4rem;font-weight:700;margin-bottom:8px">Copia dal diurno</div>
            <div style="color:#555;margin-bottom:24px">
              Importo le squadre e i salti dal <strong>diurno dello stesso salto</strong>
              (il giorno prima) come base per il notturno.<br><br>
              Le assegnazioni attuali del notturno vengono <strong>sostituite</strong>; chi è in
              ferie/permesso/missione stanotte <strong>non</strong> viene importato.<br><br>
              Continuare?
            </div>
            <div style="display:flex;gap:12px;justify-content:center">
              <button onclick="chiudiModalCopiaDiurno()" class="btn btn-grigio btn-sm">Annulla</button>
              <button onclick="eseguiCopiaDiurno()"
                      class="btn btn-sm" style="background:#1a5276;color:#fff">Sì, copia</button>
            </div>
          </div>
        </div>
        <?php endif; ?>
    </div><!-- /.foglio-header-top -->

  </div><!-- /.foglio-header -->

  <div id="msgBox"></div>

  <!-- ══ LAYOUT PRINCIPALE ════════════════════════════════════ -->
  <div class="foglio-layout">

    <!-- ── COLONNA SINISTRA (organico + ferie respinte) ────── -->
    <div class="col-sinistra">

    <!-- ── PANNELLO ORGANICO ──────────────────────────────── -->
    <div class="organico-panel">
      <div class="organico-head">
        <span>👥 Organico</span>
        <span id="contatore-liberi" style="font-size:.75rem;opacity:.8"></span>
      </div>
      <input type="text" class="organico-cerca"
             id="cercaOrganico"
             placeholder="🔍 Trova vigile nel foglio…"
             oninput="filtraOrganico(this.value)">

      <!-- Aggiungi un nome NON del turno (turno C in straord., sommozzatori, nautici) -->
      <div class="aggiungi-esterno">
        <input type="text" id="esternoNome" maxlength="80"
               placeholder="➕ Aggiungi esterno (es. CS MICELI)"
               onkeydown="if(event.key==='Enter'){event.preventDefault();aggiungiEsterno();}">
        <div class="aggiungi-esterno-row">
          <label title="Segna come straordinario (giallo sull'ODT)">
            <input type="checkbox" id="esternoStr" checked> straordinario
          </label>
          <button type="button" class="btn btn-sm btn-verde" onclick="aggiungiEsterno()">Aggiungi</button>
        </div>
        <div class="aggiungi-esterno-row">
          <label title="Colora il nome come patente 2ª categoria">
            <input type="checkbox" id="esternoPat2" onchange="if(this.checked)document.getElementById('esternoPat3').checked=false"> 2 cat.
          </label>
          <label title="Colora il nome come patente 3ª/4ª categoria">
            <input type="checkbox" id="esternoPat3" onchange="if(this.checked)document.getElementById('esternoPat2').checked=false"> 3 cat.
          </label>
        </div>
      </div>

      <div class="organico-list" id="organicoList">
  <?php foreach ($tuttoPersonale as $v):
    $vid          = $v['id'];
    // Chi ha CEDUTO il salto con uno scambio è in servizio anche se il suo salto
    // canonico è quello di riposo oggi: NON va escluso dall'organico (torna fra i
    // disponibili, sempre — senza bisogno di resettare il foglio).
    $isSaltoCanon = ((int)$v['salto_id'] === $idSaltoRiposo) && !isset($scambioOut[$vid]);
    if ($isSaltoCanon) continue;

    $isAssegnato = in_array($vid, $vigiliAssegnati);
    $isAssente   = in_array($vid, $vigiliAssenti);
    $isInSalto   = in_array($vid, $idVigiliInSalto);
    $isCapoR     = ((int)$vid === $capoId && $capoId > 0);
    $isViceR     = ((int)$vid === $viceId && $viceId > 0);
    $occupato    = $isAssegnato || $isAssente || $isInSalto || $isCapoR || $isViceR;
    // Il vigile con ferie respinta RESTA in organico (disponibile, trascinabile):
    // la casella "Ferie respinte" è solo un promemoria parallelo, non lavorabile.
    $classeCard  = 'persona-card' . ($occupato ? ' assente' : '');
    $label       = etichettaVigile($v);
    $colore      = colorePatentePHP($v['patente_max'] ?? null);

    // Badge sede: solo per distaccamenti, calcolato QUI dentro il foreach
    $mostraSede  = (!empty($v['sede_nome']) && $v['sede_nome'] !== 'CENTRALE');
?>
    <div class="<?= $classeCard ?>"
         id="pers-<?= $vid ?>"
         data-id="<?= $vid ?>"
         data-nome="<?= htmlspecialchars($label) ?>"
         data-cognome="<?= htmlspecialchars($v['cognome']) ?>"
         data-qualifica="<?= htmlspecialchars($v['qcodice']) ?>"
         data-salto="<?= htmlspecialchars($v['salto_codice']) ?>"
         data-salto-id="<?= (int)$v['salto_id'] ?>"
         data-salto-canon="0"
         data-spec="<?= (int)($v['specialista'] ?? 0) ?>"
         <?= $occupato ? '' : 'draggable="true"' ?>>

        <span class="qual-dot <?= htmlspecialchars($v['qcodice']) ?>"></span>

        <span class="persona-nome"
              style="color:<?= $colore ?>">
            <?= htmlspecialchars($label) ?>
            <?php if ($isCapoR): ?>
                <small style="color:var(--grigio-md)">capo servizio</small>
            <?php elseif ($isViceR): ?>
                <small style="color:var(--grigio-md)">vice capo</small>
            <?php elseif ($isAssegnato): ?>
                <small style="color:var(--grigio-md)">in servizio</small>
            <?php elseif ($isAssente): ?>
                <small style="color:var(--grigio-md)">assente</small>
            <?php elseif ($isInSalto): ?>
                <small style="color:var(--grigio-md)">in salto</small>
            <?php endif; ?>
            <?php if (isset($scambioOut[$vid])): ?>
                <span class="scambio-badge"
                      style="font-size:.65rem;color:#0a58ca;font-weight:700;margin-left:4px"
                      title="Ha ceduto il salto per scambio turno">🔄 ha ceduto</span>
            <?php endif; ?>
        </span>

        <?php if ($mostraSede): ?>
            <span class="persona-salto">
                <?= htmlspecialchars(siglaSede($v['sede_codice'])) ?>
            </span>
        <?php endif; ?>

    </div>
<?php endforeach; ?>

</div><!-- /.organico-list -->

    </div><!-- /.organico-panel -->

    <!-- ── PANNELLO FERIE RESPINTE ─────────────────────────── -->
    <?php
      // Promemoria: TUTTI i vigili con ferie respinta per questo turno (anche se
      // poi assegnati in servizio o altrove). Escono solo se la ferie torna approvata
      // (allora lo stato non è più 'rejected' e spariscono da $idFerieRespinte).
      $respinti = array_filter($tuttoPersonale, function ($v) use (
          $idSaltoRiposo, $idFerieRespinte
      ) {
          if ((int)$v['salto_id'] === $idSaltoRiposo) return false;
          return in_array($v['id'], $idFerieRespinte);
      });
    ?>
    <div class="ferie-respinte-panel">
      <div class="ferie-respinte-head">
        <span>🚫 Ferie respinte</span>
        <span id="ferieRespinteCount" style="font-size:.75rem;opacity:.85"><?= count($respinti) ?></span>
      </div>
      <div class="ferie-respinte-list" id="ferieRespinteList">
      <?php if (empty($respinti)): ?>
        <div class="ferie-respinte-vuoto">Nessuna ferie respinta per questo turno.</div>
      <?php else: ?>
        <?php foreach ($respinti as $v):
          $vid    = $v['id'];
          $label  = etichettaVigile($v);
          $colore = colorePatentePHP($v['patente_max'] ?? null);
          $mostraSede = (!empty($v['sede_nome']) && $v['sede_nome'] !== 'CENTRALE');
        ?>
        <div class="persona-card"
             id="resp-<?= $vid ?>"
             data-id="<?= $vid ?>"
             style="cursor:default">
            <span class="qual-dot <?= htmlspecialchars($v['qcodice']) ?>"></span>
            <span class="persona-nome" style="color:<?= $colore ?>">
                <?= htmlspecialchars($label) ?>
                <small style="color:var(--rosso)">ferie respinta</small>
            </span>
            <?php if ($mostraSede): ?>
                <span class="persona-salto"><?= htmlspecialchars(siglaSede($v['sede_codice'])) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>
    </div><!-- /.ferie-respinte-panel -->

    <!-- ── PANNELLO FERIE D'UFFICIO (drop zone) ─────────────── -->
    <div class="ferie-ufficio-panel" data-drop-zone="colFerieUfficio">
      <div class="ferie-ufficio-head">
        <span>🏛️ Ferie d'ufficio</span>
        <span id="ferieUfficioCount" style="font-size:.75rem;opacity:.85"><?= count($ferieUfficio) ?></span>
      </div>
      <div class="ferie-ufficio-list" id="colFerieUfficio">
        <?php if (empty($ferieUfficio)): ?>
          <div class="ferie-ufficio-vuoto" id="ferieUfficioVuoto">Trascina qui un vigile per dare ferie d'ufficio.</div>
        <?php endif; ?>
        <?php foreach ($ferieUfficio as $a):
          $colore = colorePatentePHP($a['patente_max'] ?? null);
        ?>
        <div class="assente-row" data-vigile-id="<?= $a['vigile_id'] ?>" data-cognome="<?= htmlspecialchars($a['cognome']) ?>"
             draggable="true" style="cursor:grab">
            <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
            <span class="assente-nome" style="color:<?= $colore ?>">
                <?= htmlspecialchars(etichettaVigile($a)) ?>
                <?php if (!empty($a['sede_nome']) && $a['sede_nome'] !== 'CENTRALE'): ?>
                    <span class="persona-salto"><?= htmlspecialchars(siglaSede($a['sede_codice'])) ?></span>
                <?php endif; ?>
            </span>
            <?php if ($a['nr_turni']): ?>
                <span class="assente-info" style="font-size:.65rem;color:var(--grigio-md)"
                      title="<?= $a['data_da'] ? date('d/m', strtotime($a['data_da'])) . '→' . date('d/m', strtotime($a['data_a'])) : '' ?>">
                    <?= (int)$a['nr_turni'] ?>T
                </span>
            <?php endif; ?>
            <button class="assente-del"
                    onclick="rimuoviFerieUfficio(<?= $a['vigile_id'] ?>)"
                    title="Togli ferie d'ufficio">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
    </div><!-- /.ferie-ufficio-panel -->

    </div><!-- /.col-sinistra -->

    <!-- Slot opzionale: il pannello Ferie può essere spostato qui (tra Disponibili
         e Centrale). Preferenza salvata sul browser del singolo operatore. -->
    <div id="ferieMiddleSlot" class="ferie-middle-slot" style="display:none"></div>

    <!-- ── GRIGLIA POSIZIONI (destra) ─────────────────────── -->
    <div class="griglia-wrapper" id="grigliaPosizioni">

      <?php
      // Riquadro unico: Centrale + Distaccamenti in un'unica griglia a 7 colonne,
      // Aeroporto in colonna 7 (sotto 1SMZ). L'ordine delle posizioni nella griglia
      // è dato dal posizionamento esplicito più sotto ($cellaGriglia), non dal DB.
      $posCentrale = []; $posDistacc = []; $posAeroporto = [];
      foreach ($sedi as $sede) {
          $p = $posizioniPerSede[$sede['id']] ?? [];
          if (!$p) continue;
          if      ($sede['codice'] === 'CENTR') $posCentrale  = array_merge($posCentrale,  $p);
          elseif  ($sede['codice'] === 'AP')    $posAeroporto = array_merge($posAeroporto, $p);
          else                                  $posDistacc   = array_merge($posDistacc,   $p);
      }
      // Card-renderer condiviso (Centrale + Distaccamenti + Aeroporto).
      // $gridStyle = posizionamento esplicito su griglia (es. "grid-column:6;grid-row:2").
      $renderPosCard = function (array $pos, string $gridStyle = '') use (&$assPerPosizione, &$esterniPerPosizione, $scambioOut, $visiteFoglio, $visitaIds, $permessoOrarioMap) {
          $assQui = $assPerPosizione[$pos['id']] ?? [];
          $extQui = $esterniPerPosizione[$pos['id']] ?? [];
          $codPos = strtolower($pos['codice']);
          $tipoHead = 'tipo-a';
          if (str_contains($codPos,'nbcr'))    $tipoHead = 'tipo-nbcr';
          elseif (str_contains($codPos,'nau'))  $tipoHead = 'tipo-nau';
          elseif (str_contains($codPos,'smz'))  $tipoHead = 'tipo-smz';
          elseif (str_contains($codPos,'fun') || str_contains($codPos,'sop')) $tipoHead = 'tipo-fun';
          elseif (str_contains($codPos,'ap-'))  $tipoHead = 'tipo-ap';
          elseif (str_contains($codPos,'el-'))  $tipoHead = 'tipo-el';
          elseif (str_contains($codPos,'op'))   $tipoHead = 'tipo-op';
          elseif (str_contains($codPos,'b'))    $tipoHead = 'tipo-b';
          ?>
          <div class="pos-card"
               id="pos-<?= $pos['id'] ?>"
               data-pos-id="<?= $pos['id'] ?>"
               data-sede="<?= htmlspecialchars($pos['sede_codice'] ?? '') ?>"
               <?= $gridStyle ? 'style="'.htmlspecialchars($gridStyle).'"' : '' ?>
               ondragover="event.preventDefault();this.classList.add('drag-over')"
               ondragleave="this.classList.remove('drag-over')"
               ondrop="onDropPosizione(event,<?= $pos['id'] ?>)">

            <?php // #95: nei giorni con visite mediche la casella 5A cambia veste ?>
            <?php $is5AVisita = ($pos['codice'] === '5A' && $visiteFoglio); ?>
            <div class="pos-head <?= $tipoHead ?>">
              <?= $is5AVisita ? '🚑 VISITA MEDICA' : htmlspecialchars($pos['codice']) ?>
            </div>
            <?php if ($is5AVisita): ?>
              <?php foreach ($visiteFoglio as $vm): ?>
                <div class="ass-card" style="cursor:default;background:#fdecea"
                     title="In visita medica (resta anche nella sua squadra)">
                  <span class="qual-dot <?= htmlspecialchars($vm['qcodice']) ?>"></span>
                  <span class="ass-nome" style="color:<?= colorePatentePHP($vm['patente_max'] ?? null) ?>">
                    <span class="ass-nome-txt"><?= htmlspecialchars(etichettaVigile($vm)) ?> *</span>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php
              // Righe indicizzate per `ordine`: niente compattazione, un vigile
              // lasciato alla riga 3 resta alla riga 3 anche a squadra per il resto
              // vuota (1 e 2 restano slot-empty finché non ci si mette qualcuno).
              $cap = capPos((int)$pos['id']);
              $righe = []; $fuoriRange = [];
              foreach (array_merge(
                  array_map(fn($a) => ['tipo'=>'v','d'=>$a], $assQui),
                  array_map(fn($e) => ['tipo'=>'e','d'=>$e], $extQui)
              ) as $r) {
                  $o = (int)$r['d']['ordine'];
                  // `ordine` storico fuori 1..cap o in collisione (dati pre-esistenti
                  // a questa modifica): non si perde nessuno, va nella prima riga
                  // libera invece di sparire dal foglio.
                  if ($o >= 1 && $o <= $cap && !isset($righe[$o])) $righe[$o] = $r;
                  else $fuoriRange[] = $r;
              }
              // Prima le righe libere 1..cap; se non bastano (dati storici con più
              // occupanti della capienza attuale) si aggiungono righe extra oltre
              // cap — meglio una riga in più che perdere qualcuno dal foglio.
              $prossimaExtra = $cap + 1;
              foreach ($fuoriRange as $r) {
                  $posto = null;
                  for ($o = 1; $o <= $cap; $o++) if (!isset($righe[$o])) { $posto = $o; break; }
                  if ($posto === null) { $posto = $prossimaExtra++; }
                  $righe[$posto] = $r;
              }
              $maxRiga = max($cap, $righe ? max(array_keys($righe)) : $cap);
              // #148: le prime n_richiesti caselle vuote sono evidenziate in rosso
              // (personale mancante); oltre n_richiesti è capienza extra, normale.
              $nRichiesti = (int)($pos['n_richiesti'] ?? 0);
            ?>
            <div class="pos-body" id="body-<?= $pos['id'] ?>" data-cap="<?= $cap ?>" data-n-richiesti="<?= $nRichiesti ?>">
              <?php
              for ($riga = 1; $riga <= $maxRiga; $riga++):
                  $r = $righe[$riga] ?? null;
                  if ($r === null):
                      $mancante = $riga <= $nRichiesti; ?>
                <div class="slot-empty<?= $mancante ? ' slot-mancante' : '' ?>" data-ordine="<?= $riga ?>"
                     <?= $mancante ? 'title="Manca personale: previsti ' . $nRichiesti . ' in questa squadra"' : '' ?>></div>
              <?php elseif ($r['tipo'] === 'v'):
                  $ass = $r['d'];
                  $colore = colorePatentePHP($ass['patente_max'] ?? null);
                  // Sigla sede SOLO se il vigile è fuori dalla propria sede (come ODT).
                  $mostraSede = (!empty($ass['sede_codice']) && $ass['sede_codice'] !== ($pos['sede_codice'] ?? null));
                  // #178: prima mancava il suffisso col numero patente (stile
                  // "numero"/"entrambi") sui nomi già assegnati alla generazione/
                  // reset del foglio — compariva solo dopo un drag manuale (che
                  // passa dal rebuild JS, l'unico posto che lo aggiungeva).
                  // etichettaVigile() è la stessa funzione già usata per il
                  // personale disponibile: stesso formato ovunque.
                  $nomeCompleto = etichettaVigile($ass);
              ?>
                <div class="ass-card"
                     id="ass-<?= $ass['vigile_id'] ?>"
                     data-vigile-id="<?= $ass['vigile_id'] ?>"
                     data-pos-id="<?= $pos['id'] ?>"
                     data-ordine="<?= $riga ?>"
                     data-straord="<?= (int)$ass['in_straordinario'] ?>"
                     draggable="true">
                  <span class="qual-dot <?= htmlspecialchars($ass['qcodice']) ?>"></span>
                  <span class="ass-nome" style="color:<?= $colore ?>"
                        title="<?= htmlspecialchars($nomeCompleto) ?>">
                    <span class="ass-nome-txt"><?= htmlspecialchars($nomeCompleto) ?></span>
                    <?php if ($mostraSede): ?>
                        <span class="persona-salto"><?= htmlspecialchars(siglaSede($ass['sede_codice'])) ?></span>
                    <?php endif; ?>
                    <?php if (isset($scambioOut[(int)$ass['vigile_id']])): ?>
                        <span class="scambio-badge"
                              style="font-size:.6rem;color:#0a58ca;font-weight:700"
                              title="Ha ceduto il salto per scambio turno">🔄</span>
                    <?php endif; ?>
                    <?php if ($ass['in_straordinario']): ?>
                        <span style="font-size:.6rem;color:var(--giallo);font-weight:700">STR</span>
                    <?php endif; ?>
                    <?php if (isset($visitaIds[(int)$ass['vigile_id']])): ?>
                        <span title="Visita medica" style="font-size:.7rem">🚑</span>
                    <?php endif; ?>
                    <?php if (isset($permessoOrarioMap[(int)$ass['vigile_id']])):
                        $po = $permessoOrarioMap[(int)$ass['vigile_id']]; ?>
                        <span title="Permesso <?= htmlspecialchars(substr($po['ora_da'],0,5)) ?>–<?= htmlspecialchars(substr($po['ora_a'],0,5)) ?><?= $po['note'] ? ' — '.htmlspecialchars($po['note']) : '' ?>"
                              style="font-size:.7rem">🕐</span>
                    <?php endif; ?>
                  </span>
                  <?php if ($ass['in_straordinario']): ?>
                      <button class="remove-btn"
                              onclick="rimuoviStraordinarioPos(<?= (int)$ass['vigile_id'] ?>, <?= (int)$pos['id'] ?>)"
                              title="Togli dalla squadra, torna in salto">✕</button>
                  <?php endif; ?>
                </div>
              <?php else: // esterno al turno (non in `vigili`)
                  $e = $r['d']; ?>
                <div class="ass-card esterno"
                     id="extass-<?= (int)$e['id'] ?>"
                     data-ext-id="<?= (int)$e['id'] ?>"
                     data-ext-nome="<?= htmlspecialchars($e['nome']) ?>"
                     data-straord="<?= (int)$e['in_straordinario'] ?>"
                     data-pat="<?= htmlspecialchars((string)$e['patente_forzata']) ?>"
                     data-pos-id="<?= $pos['id'] ?>"
                     data-ordine="<?= $riga ?>"
                     draggable="true">
                  <span class="esterno-dot" title="Personale esterno al turno">★</span>
                  <span class="ass-nome" title="<?= htmlspecialchars($e['nome']) ?> (esterno)">
                    <span class="ass-nome-txt" style="color:<?= colorePatentePHP($e['patente_forzata']) ?>"><?= htmlspecialchars($e['nome'] . suffissoPatente($e['patente_forzata'])) ?></span>
                    <?php if ((int)$e['in_straordinario']): ?>
                        <span style="font-size:.6rem;color:var(--giallo);font-weight:700">STR</span>
                    <?php endif; ?>
                  </span>
                  <button class="remove-btn"
                          onclick="rimuoviEsternoPos(<?= (int)$e['id'] ?>)"
                          title="Rimuovi">✕</button>
                </div>
              <?php endif; ?>
              <?php endfor; ?>
            </div>
          </div><!-- /.pos-card -->
          <?php
      };

      // Indice posizioni per codice (Centrale + Distaccamenti in un'unica griglia)
      $byCode = [];
      foreach ($posCentrale as $p) $byCode[$p['codice']] = $p;
      foreach ($posDistacc  as $p) $byCode[$p['codice']] = $p;
      $apByCode = [];
      foreach ($posAeroporto as $pa) $apByCode[$pa['codice']] = $pa;

      // Posizionamento esplicito su griglia 7 colonne (col, riga):
      //   r1 Centrale: CENTR-OP 1A 2A 3A 4A 5A 1SMZ
      //   r2 Centrale: 1B 2B-NBCR 3B 4B 1FUN 1SOP   (Aeroporto parte in col7, sotto 1SMZ)
      //   r3 Distacc.: ML-1A GE-1A BS-1A BL-1A RP-1A CH-1A
      //   r4         : ML-1NAU GA-1NAU EL-1SMZ ... CH-1B (sotto Chiavari)
      $cellaGriglia = [
          'CENTR-OP'=>[1,1],'1A'=>[2,1],'2A'=>[3,1],'3A'=>[4,1],'4A'=>[5,1],'5A'=>[6,1],'1SMZ'=>[7,1],
          '1B'=>[1,2],'2B-NBCR'=>[2,2],'3B'=>[3,2],'4B'=>[4,2],'1FUN-AUTORADIO'=>[5,2],'1SOP-AUTORIM'=>[6,2],
          'ML-1A'=>[1,3],'GE-1A'=>[2,3],'BS-1A'=>[3,3],'BL-1A'=>[4,3],'RP-1A'=>[5,3],'CH-1A'=>[6,3],
          'ML-1NAU'=>[1,4],'GA-1NAU'=>[2,4],'EL-1SMZ'=>[3,4],'CH-1B'=>[6,4],
      ];
      $ordineAP = ['AP-TEL','AP-1ROS','AP-1ASA','AP-1VI','AP-2VI'];
      ?>

      <!-- RIQUADRO UNICO: scritta "Centrale" sopra, poi tutte le squadre della
           Centrale + i distaccamenti in un'unica griglia a 7 colonne.
           L'Aeroporto parte in 7ª colonna direttamente sotto 1SMZ. -->
      <div class="sede-block sede-full">
        <div class="sede-head">🏠 Centrale</div>

        <!-- Campi intestazione (CS/VCS/Funzionario): sotto la scritta "Centrale",
             sopra le prime squadre — stesso raggruppamento visivo del foglio. -->
        <form id="formIntestazione">
        <div class="foglio-header-fields">

          <!-- Capo Servizio (drag: trascina qui un vigile) -->
          <div class="fh-field">
            <div class="fh-label">Capo Servizio</div>
            <div class="fh-value fh-dropzone" id="dropCapoServizio" title="Trascina qui un vigile">
              <input type="hidden" name="capo_servizio_id" id="csId"
                     value="<?= (int)($foglio['capo_servizio_id'] ?? 0) ?: '' ?>">
              <input type="hidden" name="capo_straordinario" id="csStraordInput"
                     value="<?= (int)($foglio['capo_straordinario'] ?? 0) ?>">
              <span id="csNome" class="dz-nome<?= $capoNome ? '' : ' vuoto' ?>"
                    style="<?= $capoNome ? 'color:' . $capoColore : '' ?>">
                <?= $capoNome ? htmlspecialchars($capoNome) : 'Trascina qui…' ?>
              </span>
              <span id="csStr" class="str-badge"
                    style="font-size:.65rem;color:var(--giallo);font-weight:700;<?= !empty($foglio['capo_straordinario']) ? '' : 'display:none' ?>">★ STR</span>
              <button type="button" class="fh-clear" id="csClear" title="Togli capo servizio"
                      onclick="svuotaRuolo(true)"<?= $capoNome ? '' : ' style="display:none"' ?>>×</button>
            </div>
          </div>

          <!-- Vice Capo Servizio (drag: trascina qui un vigile) -->
          <div class="fh-field">
            <div class="fh-label">Vice Capo Servizio</div>
            <div class="fh-value fh-dropzone" id="dropViceCapo" title="Trascina qui un vigile">
              <input type="hidden" name="vice_capo_id" id="vcsId"
                     value="<?= (int)($foglio['vice_capo_id'] ?? 0) ?: '' ?>">
              <input type="hidden" name="vice_straordinario" id="vcsStraordInput"
                     value="<?= (int)($foglio['vice_straordinario'] ?? 0) ?>">
              <span id="vcsNome" class="dz-nome<?= $viceNome ? '' : ' vuoto' ?>"
                    style="<?= $viceNome ? 'color:' . $viceColore : '' ?>">
                <?= $viceNome ? htmlspecialchars($viceNome) : 'Trascina qui…' ?>
              </span>
              <span id="vcsStr" class="str-badge"
                    style="font-size:.65rem;color:var(--giallo);font-weight:700;<?= !empty($foglio['vice_straordinario']) ? '' : 'display:none' ?>">★ STR</span>
              <button type="button" class="fh-clear" id="vcsClear" title="Togli vice capo"
                      onclick="svuotaRuolo(false)"<?= $viceNome ? '' : ' style="display:none"' ?>>×</button>
            </div>
          </div>

          <!-- Funzionario -->
          <div class="fh-field">
            <div class="fh-label">Funzionario</div>
            <div class="fh-value">
              <select name="funzionario" id="funzionario">
                <option value="">— seleziona il funzionario —</option>
                <?php
                  $funzInLista = false;
                  foreach ($funzionariOpt as $fnome):
                      if ($fnome === $funzCorrente) $funzInLista = true; ?>
                  <option value="<?= htmlspecialchars($fnome) ?>"<?= $fnome === $funzCorrente ? ' selected' : '' ?>>
                    <?= htmlspecialchars($fnome) ?>
                  </option>
                <?php endforeach; ?>
                <?php if ($funzCorrente !== '' && !$funzInLista): ?>
                  <!-- valore storico non più in anagrafica: lo conservo finché non si ri-salva -->
                  <option value="<?= htmlspecialchars($funzCorrente) ?>" selected><?= htmlspecialchars($funzCorrente) ?> (fuori lista)</option>
                <?php endif; ?>
              </select>
            </div>
          </div>

        </div>
        </form>

        <div class="sede-body griglia-unica">
          <?php
          $usati = [];
          foreach ($cellaGriglia as $code => [$c, $r]):
              if (isset($byCode[$code])) { $renderPosCard($byCode[$code], "grid-column:$c;grid-row:$r"); $usati[$code] = 1; }
          endforeach;
          // Fallback: posizioni non mappate (Centrale o Distacc.) → riga 6
          $leftCol = 1;
          foreach (array_merge($posCentrale, $posDistacc) as $p):
              if (isset($usati[$p['codice']])) continue;
              $renderPosCard($p, "grid-column:".($leftCol++).";grid-row:6");
          endforeach;
          ?>

          <?php if ($posAeroporto): ?>
          <div class="aeroporto-col" style="grid-column:7;grid-row:2 / span 3">
            <div class="aeroporto-head">✈️ Aeroporto</div>
            <?php
            foreach ($ordineAP as $code):
                if (isset($apByCode[$code])) $renderPosCard($apByCode[$code]);
            endforeach;
            foreach ($posAeroporto as $pa):
                if (!in_array($pa['codice'], $ordineAP, true)) $renderPosCard($pa);
            endforeach;
            ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.griglia-wrapper -->

  </div><!-- /.foglio-layout -->

  <!-- ══ SEZIONE ASSENTI ══════════════════════════════════════ -->
  <div class="assenti-section">
    <div class="assenti-head">
      <span>👤 Personale Assente / Salto Turno</span>
      <button onclick="toggleAssentiForm()"
              class="btn btn-sm btn-grigio">➕ Aggiungi assenza</button>
    </div>

    <!-- Form aggiungi assenza (nascosto di default) -->
    <div id="assenzaForm"
         style="display:none;padding:16px;border-bottom:1px solid #e8e8e8;
                background:#fafafa">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">

        <div style="display:flex;flex-direction:column;gap:4px;min-width:160px;flex:1">
          <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Vigile</label>
          <select id="assVigile" style="padding:7px 10px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.85rem">
            <option value="">— seleziona —</option>
            <?php foreach ($tuttoPersonale as $v): ?>
              <option value="<?= $v['id'] ?>">
                <?= htmlspecialchars(etichettaVigile($v)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="display:flex;flex-direction:column;gap:4px;min-width:140px">
          <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Tipo assenza</label>
          <select id="assTipo" style="padding:7px 10px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.85rem">
            <?php foreach ($tipiAssenza as $ta): ?>
              <option value="<?= $ta['id'] ?>"
                      data-codice="<?= htmlspecialchars($ta['codice']) ?>">
                <?= htmlspecialchars($ta['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="campoSedeDist"
             style="display:none;flex-direction:column;gap:4px;min-width:120px">
          <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Sede distaccata</label>
          <select id="assSede" style="padding:7px 10px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.85rem">
            <option value="">— nessuna —</option>
            <?php foreach ($sediSelect as $sd): ?>
              <option value="<?= htmlspecialchars($sd['codice']) ?>">
                <?= htmlspecialchars($sd['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="campoFerie"
             style="display:none;align-items:flex-end;gap:8px;flex-wrap:wrap">
          <div style="display:flex;flex-direction:column;gap:4px">
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                          color:var(--grigio-md)">Dal</label>
            <input type="date" id="assDa"
                   style="padding:7px 10px;border:1px solid #d5d8dc;
                          border-radius:5px;font-size:.85rem">
          </div>
          <div style="display:flex;flex-direction:column;gap:4px">
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                          color:var(--grigio-md)">Al</label>
            <input type="date" id="assA"
                   style="padding:7px 10px;border:1px solid #d5d8dc;
                          border-radius:5px;font-size:.85rem">
          </div>
          <div style="display:flex;flex-direction:column;gap:4px;max-width:80px">
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                          color:var(--grigio-md)">N° turni</label>
            <input type="number" id="assNrTurni" min="1" max="99"
                   style="padding:7px 10px;border:1px solid #d5d8dc;
                          border-radius:5px;font-size:.85rem">
          </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;flex:1">
          <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Note</label>
          <input type="text" id="assNote" placeholder="Note opzionali…"
                 style="padding:7px 10px;border:1px solid #d5d8dc;
                        border-radius:5px;font-size:.85rem">
        </div>

        <div style="display:flex;gap:8px;align-items:flex-end">
          <button onclick="salvaAssenza()" class="btn btn-rosso btn-sm">
            ✔ Conferma
          </button>
          <button onclick="toggleAssentiForm()" class="btn btn-grigio btn-sm">
            ✕
          </button>
        </div>

      </div>
    </div>

    <!-- Griglia assenti per tipo -->
    <div class="assenti-grid" id="grigliaSalto">

     <!-- Salto turno (riposo canonico) — pre-popolato dal PHP -->
<div class="assenti-col">
  <span class="assenti-col-head"
        style="background:var(--giallo);color:#7d5a00">
    😴 Salto <?= htmlspecialchars($codSaltoRip) ?>
  </span>

  <div id="colSalto">

    <?php
    // #132: qui SOLO per cognome (non qualifica→cognome come $tuttoPersonale altrove).
    $personaleSalto = array_values(array_filter($tuttoPersonale, fn($v) => in_array($v['id'], $idVigiliInSalto)));
    usort($personaleSalto, fn($a, $b) => strcasecmp(
        $a['cognome'] . sprintf('%03d', (int)$a['disambiguatore']),
        $b['cognome'] . sprintf('%03d', (int)$b['disambiguatore'])
    ));
    foreach ($personaleSalto as $v):
      $vid          = $v['id'];   // verità: salto_servizio (riflette gli scambi)
      $isAssegnatoStr = !empty($saltoRichiamato[$vid]);   // STR = richiamato (straordinario)
      $label        = etichettaVigile($v);
    ?>
      <div class="assente-row"
     id="salto-<?= $vid ?>"
     data-vigile-id="<?= $vid ?>"
     draggable="true"
     style="cursor:grab">
    <span class="qual-dot <?= htmlspecialchars($v['qcodice']) ?>"></span>
    <span class="assente-nome"
          style="color:<?= colorePatentePHP($v['patente_max'] ?? null) ?>">
        <?= htmlspecialchars($label) ?>
        <?php if ($v['sede_nome'] !== 'CENTRALE'): ?>
            <span class="persona-salto">
                <?= htmlspecialchars(siglaSede($v['sede_codice'])) ?>
            </span>
        <?php endif; ?>
        <?php if (isset($scambioIn[$vid])): ?>
            <span class="scambio-badge"
                  style="font-size:.65rem;color:#0a58ca;font-weight:700;margin-left:4px"
                  title="A riposo per scambio salto turno">🔄 scambio</span>
        <?php endif; ?>
        <?php if ($isAssegnatoStr): ?>
            <span class="str-badge"
                  style="font-size:.65rem;color:var(--giallo);
                         font-weight:700;margin-left:4px">★ STR</span>
        <?php endif; ?>
    </span>
    <?php if (!$isAssegnatoStr): ?>
        <span class="drag-icon-salto"
              style="font-size:.75rem;color:var(--grigio-md);
                     margin-left:auto"
              title="Trascina su posizione o assenza">⇄</span>
    <?php endif; ?>
</div>
    <?php endforeach; ?>

    <div class="assenti-drop" id="dropSalto" style="font-size:.7rem">
      ⇄ Trascina qui da organico per mettere in salto
    </div>

    <!-- Cambio salto turno (inserimento manuale, bypass Telegram) -->
    <div style="margin-top:8px;border-top:1px dashed #e0c200;padding-top:8px">
      <button type="button" class="btn btn-sm btn-grigio" style="width:100%"
              onclick="toggleCambioSalto()">🔄 Cambia salto…</button>

      <?php if (!empty($scambiAttivi)): ?>
      <div style="margin-top:8px;display:flex;flex-direction:column;gap:4px">
        <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;
                    color:var(--grigio-md)">Scambi attivi su questo turno</div>
        <?php foreach ($scambiAttivi as $sc): ?>
          <div style="display:flex;align-items:center;gap:6px;font-size:.74rem;
                      background:#fff7d6;border:1px solid #e0c200;border-radius:5px;padding:4px 6px">
            <span style="flex:1">🔄 B<?= (int)$sc['slot_a'] ?> <?= htmlspecialchars(ucfirst(strtolower($sc['a_cognome']))) ?><?= $sc['a_disambiguatore'] ? ' ' . (int)$sc['a_disambiguatore'] : '' ?>
                  ⇄ B<?= (int)$sc['slot_b'] ?> <?= htmlspecialchars(ucfirst(strtolower($sc['b_cognome']))) ?><?= $sc['b_disambiguatore'] ? ' ' . (int)$sc['b_disambiguatore'] : '' ?></span>
            <button type="button" class="btn btn-grigio btn-sm" style="padding:2px 6px"
                    onclick="annullaScambio(<?= (int)$sc['id'] ?>)"
                    title="Annulla questo scambio">✖️</button>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <script>window.CS_SLOT_A = <?= (int)$slotRiposoOggi ?>;</script>

      <div id="cambioSaltoForm" style="display:none;flex-direction:column;gap:6px;margin-top:8px">
        <?php if (!$cambioSaltoOk): ?>
          <div style="font-size:.7rem;color:var(--grigio-md)">
            Nessuna controparte disponibile nel blocco (riposi già passati o
            nessun vigile dell'altro salto).
          </div>
        <?php else: ?>
          <label style="font-size:.68rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Cede il salto (B<?= (int)$slotRiposoOggi ?>)</label>
          <select id="csVigileA" style="padding:6px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.8rem">
            <option value="">— seleziona —</option>
            <?php foreach ($vigiliPerSlot[$slotRiposoOggi] as $v): ?>
              <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars(etichettaVigile($v)) ?></option>
            <?php endforeach; ?>
          </select>

          <label style="font-size:.68rem;font-weight:700;text-transform:uppercase;
                        color:var(--grigio-md)">Entra in salto (riposa al suo posto)</label>
          <?php
            // Tutti i disponibili del blocco (controparti di ogni altro slot),
            // in un'unica lista alfabetica: niente suddivisione per salto.
            $contropartiVigili = [];
            foreach ($contropartiSlot as $k => $occ) {
                foreach ($vigiliPerSlot[$k] ?? [] as $v) {
                    $v['_passato'] = !empty($occ['passato']);
                    $contropartiVigili[] = $v;
                }
            }
            usort($contropartiVigili, fn($a, $b) =>
                strcmp($a['cognome'].' '.$a['nome'], $b['cognome'].' '.$b['nome']));
          ?>
          <select id="csVigileB" style="padding:6px;border:1px solid #d5d8dc;
                  border-radius:5px;font-size:.8rem">
            <option value="">— seleziona —</option>
            <?php foreach ($contropartiVigili as $v): ?>
              <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars(etichettaVigile($v)) ?><?= $v['_passato'] ? ' — ⏪ a ritroso (riposo passato)' : '' ?></option>
            <?php endforeach; ?>
          </select>

          <button type="button" class="btn btn-rosso btn-sm"
                  onclick="salvaCambioSalto()">✔ Esegui scambio</button>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>


     <!-- Ferie -->
<div class="assenti-col" id="feriePanel" data-drop-zone="colFerie">
  <div class="ferie-head-row">
    <span class="assenti-col-head ac-ferie">🏖️ Ferie</span>
    <button type="button" id="btnFeriePos" class="ferie-pos-btn" onclick="toggleFeriePos()"
            title="Sposta il pannello Ferie tra Disponibili e Centrale (resta salvato sul tuo browser)">↔️</button>
  </div>
  <div id="colFerie">
          <?php /* Mostra TUTTE le ferie (da Telegram + a mano): le ferie a mano
                   si vedono sia qui sia nel box "Ferie d'ufficio" (ridondanza voluta). */ ?>
          <?php foreach ($ferieTutte as $a):
    $colore = colorePatentePHP($a['patente_max'] ?? null);
?>
    <div class="assente-row" data-vigile-id="<?= $a['vigile_id'] ?>" data-cognome="<?= htmlspecialchars($a['cognome']) ?>">
        <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
        <span class="assente-nome" style="color:<?= $colore ?>">
            <?= htmlspecialchars(etichettaVigile($a)) ?>
            <?php if (!empty($a['ferie_estiva'])): ?>
                <span title="Ferie estiva">🏖️</span>
            <?php endif; ?>
            <?php if (!empty($a['sede_nome']) && $a['sede_nome'] !== 'CENTRALE'): ?>
                <span class="persona-salto">
                    <?= htmlspecialchars(siglaSede($a['sede_codice'])) ?>
                </span>
            <?php endif; ?>
        </span>
        <?php if ($a['nr_turni']): ?>
            <span class="assente-info"
                  title="<?= $a['data_da'] ? date('d/m', strtotime($a['data_da'])) . '→' . date('d/m', strtotime($a['data_a'])) : '' ?>">
                <?= (int)$a['nr_turni'] ?>T
            </span>
        <?php endif; ?>
        <button class="assente-del"
                onclick="rimuoviDaAssenza(<?= $a['vigile_id'] ?>)"
                title="Rimuovi">✕</button>
    </div>
<?php endforeach; ?>

        </div>
      </div>

      <!-- Missione / Permesso -->
<div class="assenti-col" data-drop-zone="colMissione">
  <span class="assenti-col-head ac-missione">✈️ Missione / Permesso</span>
  <div id="colMissione">
          <?php foreach (array_merge(
              $assenzePerTipo['MISS'] ?? [],
              $assenzePerTipo['PERM'] ?? []
          ) as $a): ?>
            <div class="assente-row" data-vigile-id="<?= $a['vigile_id'] ?>">
              <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
              <span class="assente-nome">
                <?= htmlspecialchars(etichettaVigile($a)) ?>
                <?php if (!empty($a['sede_nome']) && $a['sede_nome'] !== 'CENTRALE'): ?>
                    <span class="persona-salto">
                        <?= htmlspecialchars(siglaSede($a['sede_codice'])) ?>
                    </span>
                <?php endif; ?>
              </span>
              <span class="assente-info">
                <?= htmlspecialchars($a['tipo_codice']) ?>
              </span>
              <button class="assente-del"
                      onclick="rimuoviDaAssenza(<?= $a['vigile_id'] ?>)"
                      title="Rimuovi">✕</button>
            </div>
          <?php endforeach; ?>
          <?php foreach ($permessoOrarioMap as $vigIdPo => $po): ?>
            <!-- Permesso orario: compare QUI in aggiunta alla squadra (badge 🕐
                 sulla card), non al posto — resta assegnato al turno. Niente
                 tasto rimuovi: si gestisce dall'Agenda (accetto/respingo/cestino).
                 data-permesso-orario: rimuoviDOM() lo esclude dallo sweep delle
                 "altre rappresentazioni" quando il vigile viene riassegnato —
                 lui resta sempre anche qui, non è un'assenza da spostare. -->
            <div class="assente-row" data-vigile-id="<?= $vigIdPo ?>" data-permesso-orario="1">
              <span class="qual-dot <?= htmlspecialchars($po['qcodice']) ?>"></span>
              <span class="assente-nome">
                <?= htmlspecialchars(etichettaVigile($po)) ?>
                <?php if (!empty($po['sede_nome']) && $po['sede_nome'] !== 'CENTRALE'): ?>
                    <span class="persona-salto">
                        <?= htmlspecialchars(siglaSede($po['sede_codice'])) ?>
                    </span>
                <?php endif; ?>
              </span>
              <span class="assente-info">
                🕐 <?= htmlspecialchars(substr($po['ora_da'], 0, 5)) ?>–<?= htmlspecialchars(substr($po['ora_a'], 0, 5)) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Malattia / Infortunio -->
      <div class="assenti-col" data-drop-zone="colMalattia">
  <span class="assenti-col-head ac-malattia">🤒 Malattia / Infortunio</span>
  <div id="colMalattia">
          <?php foreach (array_merge(
              $assenzePerTipo['MAL'] ?? [],
              $assenzePerTipo['INF'] ?? []
          ) as $a): ?>
            <div class="assente-row" data-vigile-id="<?= $a['vigile_id'] ?>">
              <span class="qual-dot <?= htmlspecialchars($a['qcodice']) ?>"></span>
              <span class="assente-nome">
                <?= htmlspecialchars(etichettaVigile($a)) ?>
                <?php if (!empty($a['sede_nome']) && $a['sede_nome'] !== 'CENTRALE'): ?>
                    <span class="persona-salto">
                        <?= htmlspecialchars(siglaSede($a['sede_codice'])) ?>
                    </span>
                <?php endif; ?>
              </span>
              <span class="assente-info">
                <?= htmlspecialchars($a['tipo_codice']) ?>
              </span>
              <button class="assente-del"
                      onclick="rimuoviDaAssenza(<?= $a['vigile_id'] ?>)"
                      title="Rimuovi">✕</button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /.assenti-grid -->
  </div><!-- /.assenti-section -->

</div><!-- /.foglio-page -->

<!-- ══ DATI JSON PER JAVASCRIPT ═════════════════════════════ -->
<script>
const FOGLIO_ID  = <?= $foglioId ?>;
const FOGLIO_URL = 'nuovo.php?data=<?= $dataStr ?>&tipo=<?= $tipoParam ?>';
// Stile patenti del turno (parametri admin): colore/numero/entrambi + tinte.
const STILE_PATENTE = <?= json_encode(stilePatenteCfg()) ?>;

const PERSONALE = {
<?php foreach ($tuttoPersonale as $_i => $v): ?>
  <?= $v['id'] ?>: {
    id:          <?= $v['id'] ?>,
    nome:        <?= json_encode(etichettaVigile($v)) ?>,
    cognome:     <?= json_encode($v['cognome']) ?>,
    qcodice:     <?= json_encode($v['qcodice']) ?>,
    salto:       <?= json_encode($v['salto_codice']) ?>,
    saltoId:     <?= (int)$v['salto_id'] ?>,
    saltoCanon:  <?= ((int)$v['salto_id'] === $idSaltoRiposo) ? 'true':'false' ?>,
    saltoEff:    <?= (((int)$v['salto_id'] === $idSaltoRiposo && !isset($scambioOut[(int)$v['id']])) || isset($scambioIn[(int)$v['id']])) ? 'true':'false' ?>,
    scambioIn:   <?= isset($scambioIn[(int)$v['id']]) ? 'true':'false' ?>,
    sede:        <?= json_encode($v['sede_codice']) ?>,
    sedeCentrale:<?= ($v['sede_nome'] === 'CENTRALE') ? 'true':'false' ?>,
    patente:     <?= json_encode($v['patente_max'] ?? '') ?>,
    visita:      <?= isset($visitaIds[(int)$v['id']]) ? 'true':'false' ?>,
    permesso:    <?= isset($permessoOrarioMap[(int)$v['id']])
                      ? json_encode(substr($permessoOrarioMap[(int)$v['id']]['ora_da'],0,5)
                          . '–' . substr($permessoOrarioMap[(int)$v['id']]['ora_a'],0,5))
                      : 'null' ?>,
    // Posizione nell'ordinamento standard (qualifica, poi cognome) di
    // $tuttoPersonale: serve per reinserire una card nel punto giusto
    // quando si ricrea da JS invece che dal render server (#122).
    rango:       <?= (int)$_i ?>
  },
<?php endforeach; ?>
};

// Distaccamenti per il menu "Manda a sede": esclusi Centrale, Reparto Volo (EL)
// e Multedo Nautica (MN); in ordine alfabetico per nome. La Centrale è aggiunta
// a parte dal sottomenu, sempre in fondo.
<?php
$sediMenu = array_values(array_filter($sediSelect, function ($s) {
    return !in_array($s['codice'] ?? '', ['CENTR', 'EL', 'MN'], true);
}));
usort($sediMenu, function ($a, $b) { return strcasecmp($a['nome'], $b['nome']); });
?>
const SEDI_DISTACCATE = [
<?php foreach ($sediMenu as $s): ?>
  { codice: <?= json_encode($s['codice']) ?>, nome: <?= json_encode($s['nome']) ?> },
<?php endforeach; ?>
];


const TIPI_ASSENZA = {
<?php foreach ($tipiAssenza as $ta):
    $mapCol = [
        'FER'  => 'colFerie',
        'MISS' => 'colMissione',
        'PERM' => 'colMissione',
        'MAL'  => 'colMalattia',
        'INF'  => 'colMalattia',
    ];
?>
  '<?= $ta['codice'] ?>': {
      id:    <?= (int)$ta['id'] ?>,
      colId: '<?= $mapCol[$ta['codice']] ?? 'colMalattia' ?>'
  },
<?php endforeach; ?>
};

const ID_SALTO_RIPOSO = <?= $idSaltoRiposo ?>;

// #188 (logbook): vigili in ferie su questo foglio coinvolti anche in uno
// scambio salto attivo per lo stesso turno — solo avviso, mai un blocco.
<?php $avvisiScambioFerie = scambioConflittoFerie($pdo, $foglioId, $dataStr, $tipoParam); ?>
const AVVISI_SCAMBIO_FERIE = <?= json_encode($avvisiScambioFerie) ?>;

// ── Stato drag ───────────────────────────────────────────────
let _dragId     = null;  // vigile_id corrente
let _dragSource = null;  // 'organico' | 'posizione' | 'salto'
let _dragExt    = null;  // nome esterno trascinato: {extId, nome, str, source, el}

// ════════════════════════════════════════════════════════════
// UTILITY
// ════════════════════════════════════════════════════════════
function showMsg() {
    // Nessuno stato/toast a video: silenzioso anche sugli errori.
}

// #188 (logbook, Moli): popup di avviso — mai un blocco — quando un vigile in
// ferie su questo foglio è anche coinvolto in uno scambio salto attivo per lo
// stesso turno. Richiamata al caricamento pagina e dopo ogni azione che può
// crearlo (assegna ferie/ferie d'ufficio, reset foglio → reload).
function mostraAvvisiScambioFerie(avvisi) {
    if (!avvisi || !avvisi.length) return;
    chiediConferma({
        titolo:  '⚠️ Ferie e scambio salto',
        testo:   'Attenzione — questi vigili risultano in ferie ma anche in uno ' +
                 'scambio salto attivo per lo stesso turno:<br><br>' +
                 avvisi.map(a => '• ' + a).join('<br>') +
                 '<br><br>Puoi modificare il servizio o lasciarlo così.',
        okLabel: 'Ho capito',
        okStyle: 'background:#b7950b;color:#fff',
    });
}

// "Disponibile" = card in organico non occupata (.assente = occupato altrove).
function contaDisponibili() {
    return document.querySelectorAll('#organicoList .persona-card:not(.assente)').length;
}

function aggiornaContatore() {
    // Conteggio separato: operativi vs specialisti (gli specialisti vanno calcolati
    // a parte e in futuro restano fuori dalla reportistica).
    const liberi = document.querySelectorAll('#organicoList .persona-card:not(.assente)');
    let op = 0, spec = 0;
    liberi.forEach(c => (c.dataset.spec === '1' ? spec++ : op++));
    const el = document.getElementById('contatore-liberi');
    if (el) {
        el.textContent = spec > 0
            ? `${op + spec} disponibili · ${op} op + ${spec} spec`
            : `${op} disponibili`;
    }
}

// Avvisa se resta personale in Disponibili prima di un'azione di chiusura
// servizio (genera .odt / invio comunicazioni): a servizio concluso quella
// casella dovrebbe essere vuota (tutti assegnati, in salto o assenti).
// Non blocca — è solo un promemoria, l'operatore può procedere comunque.
function avvisaSeDisponibiliResidui(messaggioAzione, onProcedi) {
    const n = contaDisponibili();
    if (n === 0) { onProcedi(); return; }
    chiediConferma({
        titolo:  '⚠️ Personale ancora disponibile',
        testo:   `Ci sono ancora <b>${n}</b> ${n === 1 ? 'vigile' : 'vigili'} in <b>Disponibili</b>, `
               + `non assegnati a nessuna posizione.<br>`
               + `A servizio concluso la casella dovrebbe essere vuota.<br><br>`
               + `Vuoi procedere comunque con ${messaggioAzione}?`,
        okLabel: 'Procedi comunque',
        okStyle: 'background:var(--rosso);color:#fff',
        onOk:    onProcedi,
    });
}

// #149: squadre con caselle valorizzate diverse da n_richiesti (solo dove
// configurato in amministrazione — 0 = nessun requisito, ignorata).
function squadreIncomplete() {
    const elenco = [];
    document.querySelectorAll('.pos-body[data-n-richiesti]').forEach(body => {
        const nRich = parseInt(body.dataset.nRichiesti || '0');
        if (!nRich) return;
        const occupate = body.querySelectorAll('.ass-card').length;
        if (occupate !== nRich) {
            const head = body.closest('.pos-card')?.querySelector('.pos-head');
            const nome = head ? head.textContent.trim() : '?';
            elenco.push(`${nome} (${occupate}/${nRich})`);
        }
    });
    return elenco;
}

// Avvisa se qualche squadra non ha il numero di elementi previsto — non blocca,
// l'operatore può procedere comunque o annullare e tornare al servizio.
function avvisaSeSquadreIncomplete(messaggioAzione, onProcedi) {
    const elenco = squadreIncomplete();
    if (elenco.length === 0) { onProcedi(); return; }
    chiediConferma({
        titolo:  '⚠️ Squadre non complete',
        testo:   `Queste squadre non hanno il numero di elementi previsto (occupate/richieste):<br><br>`
               + `<b>${elenco.join(', ')}</b><br><br>`
               + `Vuoi procedere comunque con ${messaggioAzione}?`,
        okLabel: 'Procedi comunque',
        okStyle: 'background:var(--rosso);color:#fff',
        onOk:    onProcedi,
    });
}

async function ajax(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));

    // Robusto: la macchina Fly può essere "fredda" (auto-stop) e rispondere con un
    // 502/HTML, oppure la connessione può cadere → r.json() lancerebbe e il tasto
    // "non farebbe niente". Qui controlliamo lo stato, parsiamo in sicurezza e
    // ritentiamo una volta sugli errori transitori, restituendo SEMPRE un oggetto.
    let lastErr = '';
    for (let tentativo = 0; tentativo < 2; tentativo++) {
        if (tentativo > 0) await new Promise(res => setTimeout(res, 600));
        try {
            const r = await fetch(FOGLIO_URL, {
                method: 'POST', body: fd, headers: { 'Accept': 'application/json' },
            });
            const testo = await r.text();
            if (!r.ok) { lastErr = 'HTTP ' + r.status; continue; }   // 5xx/4xx → ritenta
            let json;
            try { json = JSON.parse(testo); }
            catch (e) { lastErr = 'Risposta non valida dal server'; continue; }

            // Se il server segnala blocco (es. bloccato da un altro utente), allinea la UI
            if (json && json.bloccato === true && !BLOCCATO) {
                BLOCCATO = true;
                applicaStatoBlocco();
                showMsg('🔒 Foglio bloccato.', 'err');
            }
            return json;
        } catch (e) {
            lastErr = e.message || 'Errore di rete';   // fetch fallito → ritenta
        }
    }
    showMsg('⚠️ Salvataggio non riuscito (' + lastErr + '). Riprova.', 'err');
    return { ok: false, errore: lastErr };
}

// ════════════════════════════════════════════════════════════
// BLOCCO / SBLOCCO FOGLIO (stato condiviso sul server)
// ════════════════════════════════════════════════════════════
// Gli utenti in sola lettura vedono il foglio come "bloccato" (drag disabilitato).
const SOLA_LETTURA = <?= soloLetturaAttivo() ? 'true' : 'false' ?>;
let BLOCCATO = <?= ($foglioBloccato || soloLetturaAttivo()) ? 'true' : 'false' ?>;

function applicaStatoBlocco() {
    const btn = document.getElementById('btnBlocco');
    if (BLOCCATO) {
        document.body.classList.add('cruscotto-bloccato');
        if (btn) { btn.innerHTML = '🔓 Sblocca'; btn.className = 'btn btn-sm btn-verde'; btn.style.cssText = ''; }
    } else {
        document.body.classList.remove('cruscotto-bloccato');
        if (btn) { btn.innerHTML = '🔒 Blocca'; btn.className = 'btn btn-sm'; btn.style.cssText = 'background:#c0392b;color:#fff'; }
    }
}

function toggleBlocco() {
    if (BLOCCATO) {
        chiediConferma({
            titolo:  'Sblocca foglio',
            testo:   'Sbloccare il foglio?<br>Le modifiche torneranno possibili per tutti.',
            okLabel: '🔓 Sblocca',
            okStyle: 'background:var(--verde);color:#fff',
            onOk:    () => eseguiBlocco(false)
        });
    } else {
        eseguiBlocco(true);
    }
}

async function eseguiBlocco(nuovo) {
    const res = await ajax({ azione: 'set_blocco', bloccato: nuovo ? 1 : 0 });
    if (!res.ok) { showMsg('⚠️ Errore.', 'err'); return; }
    BLOCCATO = res.bloccato;
    applicaStatoBlocco();
    showMsg(BLOCCATO ? '🔒 Foglio bloccato.' : '🔓 Foglio sbloccato.');
}

function setOccupato(id, occupato, label) {
    const c = document.getElementById('pers-' + id);
    if (!c) return;
    if (occupato) {
        c.classList.add('assente');
        c.removeAttribute('draggable');
        let s = c.querySelector('small');
        if (!s) { s = document.createElement('small'); c.querySelector('.persona-nome').appendChild(s); }
        s.textContent = label || 'in servizio';
    } else {
        c.classList.remove('assente');
        c.setAttribute('draggable','true');
        const s = c.querySelector('small');
        if (s) s.remove();
    }
    aggiornaContatore();
    updateRespinteCount();
}

// Un ex capo/vice è ancora "occupato" (e quindi NON torna disponibile) se sta
// in una posizione, in salto, o ricopre l'altro ruolo di intestazione.
function _ruoloOccupatoAltrove(id, isCapo) {
    if (document.getElementById('ass-' + id))   return true;
    if (document.getElementById('salto-' + id)) return true;
    const altroId = parseInt(document.getElementById(isCapo ? 'vcsId' : 'csId').value) || 0;
    return altroId === id;
}

// Ricostruisce le righe di una squadra secondo `data-ordine`: NIENTE
// compattazione, un buco resta un buco finché non ci si mette qualcuno
// (stessa riga dove è stato lasciato cadere il vigile). Le card con ordine
// mancante/duplicato/fuori capienza (dati incoerenti) finiscono nella prima
// riga libera — non si perde comunque nessuno dal foglio.
// Aggiorna (crea/rimuove) la sigla sede su una .ass-card già in DOM dopo uno
// spostamento che sposta il nodo invece di ricostruirlo (sposta_riga,
// scambio_posizioni) — altrimenti resta la sigla della posizione di partenza,
// o manca quella nuova, finché non si ricarica la pagina.
function aggiornaSiglaSede(card, posId) {
    const p = PERSONALE[card.dataset.vigileId];
    const nomeEl = card.querySelector('.ass-nome');
    if (!p || !nomeEl) return;
    const posSede = document.getElementById('pos-' + posId)?.dataset.sede || '';
    const sig = nomeEl.querySelector('.persona-salto');
    const serve = p.sede && p.sede !== posSede;
    if (serve && !sig) {
        nomeEl.querySelector('.ass-nome-txt')
            ?.insertAdjacentHTML('afterend', `<span class="persona-salto">${siglaSede(p.sede)}</span>`);
    } else if (serve && sig) {
        sig.textContent = siglaSede(p.sede);
    } else if (!serve && sig) {
        sig.remove();
    }
}

function sincronizzaSlot(body) {
    if (!body) return;
    const cap = parseInt(body.dataset.cap || '7');
    const nRichiesti = parseInt(body.dataset.nRichiesti || '0');   // #148
    const cards = [...body.querySelectorAll('.ass-card')];
    const righe = new Map();
    const fuoriRange = [];
    cards.forEach(c => {
        const o = parseInt(c.dataset.ordine);
        if (o >= 1 && o <= cap && !righe.has(o)) righe.set(o, c);
        else fuoriRange.push(c);
    });
    let extra = cap + 1;
    fuoriRange.forEach(c => {
        let posto = null;
        for (let o = 1; o <= cap; o++) if (!righe.has(o)) { posto = o; break; }
        if (posto === null) posto = extra++;
        c.dataset.ordine = posto;
        righe.set(posto, c);
    });
    const maxRiga = Math.max(cap, ...righe.keys());
    body.querySelectorAll('.slot-empty').forEach(s => s.remove());
    cards.forEach(c => c.remove());   // stacca tutto, si reinserisce nell'ordine giusto
    for (let i = 1; i <= maxRiga; i++) {
        const c = righe.get(i);
        if (c) { body.appendChild(c); continue; }
        const s = document.createElement('div');
        s.className = 'slot-empty' + (i <= nRichiesti ? ' slot-mancante' : '');
        s.dataset.ordine = i;
        if (i <= nRichiesti) s.title = 'Manca personale: previsti ' + nRichiesti + ' in questa squadra';
        body.appendChild(s);
    }
}

// Riga (ass-card o slot-empty) sotto il cursore durante un drag, per capire
// esattamente su quale posto della squadra si sta rilasciando.
function rigaAtY(body, y) {
    const kids = [...body.children];
    if (!kids.length) return null;
    let best = kids[0], bestDist = Infinity;
    for (const k of kids) {
        const box = k.getBoundingClientRect();
        const mid = box.top + box.height / 2;
        const dist = Math.abs(y - mid);
        if (dist < bestDist) { bestDist = dist; best = k; }
    }
    return best;
}

// Evidenzia la riga esatta su cui si andrebbe ad atterrare (niente più gap
// che si apre spostando le altre card: le righe sono fisse, si evidenzia
// solo il bersaglio).
function evidenziaRigaTarget(target, y) {
    pulisciRigaTarget();
    if (target && target.classList?.contains('pos-card')) {
        const body = document.getElementById('body-' + target.dataset.posId);
        const riga = body && rigaAtY(body, y);
        if (riga) riga.classList.add('riga-target');
    }
}
function pulisciRigaTarget() {
    document.querySelectorAll('.riga-target').forEach(el => el.classList.remove('riga-target'));
}

// Rimuove dal DOM da qualsiasi posto (senza toccare la riga salto canonico)
function rimuoviDOM(id) {
    const p = PERSONALE[id];

    // Da posizione
    const ac = document.getElementById('ass-' + id);
    if (ac) { const body = ac.parentElement; ac.remove(); sincronizzaSlot(body); }

    // Da riga salto: solo badge, non la riga (anche per chi riposa via
    // scambio salto, non solo canonico — altrimenti la riga sparisce e
    // riappare solo con F5, #121).
    const sr = document.getElementById('salto-' + id);
    if (sr) {
        if (p && p.saltoEff) {
            sr.querySelector('.str-badge')?.remove();
            sr.querySelector('.abil-badge')?.remove();
            if (!sr.querySelector('.drag-icon-salto')) {
                sr.insertAdjacentHTML('beforeend',
                    `<span class="drag-icon-salto"
                           style="font-size:.75rem;color:var(--grigio-md);
                                  margin-left:auto;cursor:grab"
                           title="Trascina su posizione o assenza">⇄</span>`);
            }
            sr.setAttribute('draggable','true');
            sr.style.cursor = 'grab';
        } else {
            sr.remove();
        }
    }

    // Da colonne assenze (esclude le righe permesso orario: quello resta
    // sempre visibile anche se il vigile viene riassegnato, non è un'assenza)
    document.querySelectorAll('.assente-row[data-vigile-id="' + id + '"]:not([data-permesso-orario])')
        .forEach(r => {
            // Non rimuovere la riga del salto canonico
            if (r.id !== 'salto-' + id) r.remove();
        });
}


// Restituisce il colore CSS in base alla patente massima (stessa regola di
// colorePatentePHP: con stile 'numero' niente colore, lo dice il suffisso).
function colorePatente(patente) {
    if (STILE_PATENTE.stile === 'numero') return '#2c3e50';
    switch (patente) {
        case '4':
        case '3': return STILE_PATENTE.rosso;
        case '2': return STILE_PATENTE.blu;
        case '1':
        default:  return '#2c3e50';  // nero/grigio scuro
    }
}

// Suffisso grado accanto al nome (" 3°") negli stili numero/entrambi — solo per
// gli esterni: i vigili hanno già il suffisso dentro PERSONALE[x].nome (dal PHP).
function suffissoPatente(patente) {
    if (STILE_PATENTE.stile === 'colore') return '';
    return ['2', '3', '4'].includes(patente) ? ` ${patente}°` : '';
}



// Sigla sede per il badge (solo visualizzazione): Centrale → "C".
function siglaSede(c) { return c === 'CENTR' ? 'C' : (c || ''); }

// ════════════════════════════════════════════════════════════
// COSTRUTTORI HTML
// ════════════════════════════════════════════════════════════
function buildAssCard(p, posId, straord, ordine) {
    // Sigla sede SOLO se il vigile lavora fuori dalla propria sede:
    // sede del vigile (p.sede) ≠ sede della posizione (data-sede della pos-card).
    const posSede   = document.getElementById('pos-' + posId)?.dataset.sede || '';
    const sedeBadge = (p.sede && p.sede !== posSede)
        ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : '';
    const strBadge = straord
        ? `<span style="font-size:.6rem;color:var(--giallo);
                        font-weight:700;margin-left:3px">STR</span>` : '';
    const visitaBadge = p.visita
        ? `<span title="Visita medica" style="font-size:.7rem">🚑</span>` : '';
    const permessoBadge = p.permesso
        ? `<span title="Permesso ${p.permesso}" style="font-size:.7rem">🕐</span>` : '';
    const colore = colorePatente(p.patente);
    const removeBtn = straord
        ? `<button class="remove-btn" onclick="rimuoviStraordinarioPos(${p.id}, ${posId})"
                    title="Togli dalla squadra, torna in salto">✕</button>` : '';

    return `<div class="ass-card"
                 id="ass-${p.id}"
                 data-vigile-id="${p.id}"
                 data-pos-id="${posId}"
                 data-ordine="${ordine}"
                 data-straord="${straord ? 1 : 0}"
                 draggable="true">
              <span class="qual-dot ${p.qcodice}"></span>
              <span class="ass-nome" style="color:${colore}" title="${p.nome}">
                <span class="ass-nome-txt">${p.nome}</span>${sedeBadge}${strBadge}${visitaBadge}${permessoBadge}
              </span>${removeBtn}
            </div>`;
}


function buildAssenteRow(p, tipoCodice, nTurni, dataDa, dataA) {
    const sedeBadge = (!p.sedeCentrale && p.sede)
        ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : '';
    const colore = colorePatente(p.patente);
    const turniInfo = nTurni ? `${nTurni}T` : tipoCodice;

    return `<div class="assente-row"
                 data-vigile-id="${p.id}" data-cognome="${escHtml(p.cognome)}">
              <span class="qual-dot ${p.qcodice}"></span>
              <span class="assente-nome"
                    style="color:${colore}">
                ${p.nome}${sedeBadge}
              </span>
              <span class="assente-info"
                    style="font-size:.65rem;color:var(--grigio-md)"
                    title="${nTurni ? (dataDa || '') + '→' + (dataA || '') : ''}">
                ${turniInfo}
              </span>
              <button class="assente-del"
                      onclick="rimuoviDaAssenza(${p.id})"
                      title="Rimetti">✕</button>
            </div>`;
}



// ════════════════════════════════════════════════════════════
// DRAG START — listener unico delegato su tutto il documento
// ════════════════════════════════════════════════════════════
document.addEventListener('dragstart', function(e) {
    if (BLOCCATO) { e.preventDefault(); return; }

    // 0. Nome ESTERNO (non in `vigili`): card nei disponibili o in posizione.
    const extCard = e.target.closest('.esterno');
    if (extCard) {
        const inPos = extCard.classList.contains('ass-card');
        _dragExt = {
            extId:  inPos ? parseInt(extCard.dataset.extId) : 0,
            nome:   extCard.dataset.extNome,
            str:    extCard.dataset.straord === '1' ? 1 : 0,
            pat:    extCard.dataset.pat || '',
            source: inPos ? 'esterno_pos' : 'esterno_disp',
            el:     extCard,
        };
        extCard.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', 'ext');
        return;
    }

    // 1. Da organico (il box "Ferie respinte" è sola lettura, non si trascina)
    const persCard = e.target.closest('#organicoList .persona-card');
    if (persCard && !persCard.classList.contains('assente')) {
        _dragId     = parseInt(persCard.dataset.id);
        _dragSource = 'organico';
        persCard.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', _dragId);
        return;
    }

    // 2. Da ass-card in posizione
    const assCard = e.target.closest('.ass-card');
    if (assCard) {
        _dragId     = parseInt(assCard.dataset.vigileId);
        _dragSource = 'posizione';
        assCard.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', _dragId);
        return;
    }

    // 3. Da riga salto canonico
    const saltoRow = e.target.closest('#colSalto .assente-row[draggable="true"]');
    if (saltoRow) {
        _dragId     = parseInt(saltoRow.dataset.vigileId);
        _dragSource = 'salto';
        saltoRow.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', _dragId);
        return;
    }

    // 4. Da ferie d'ufficio
    const ufficioRow = e.target.closest('#colFerieUfficio .assente-row[draggable="true"]');
    if (ufficioRow) {
        _dragId     = parseInt(ufficioRow.dataset.vigileId);
        _dragSource = 'ufficio';
        ufficioRow.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', _dragId);
        return;
    }

    // Nessuna sorgente valida
    e.preventDefault();
});

document.addEventListener('dragend', function() {
    document.querySelectorAll('.dragging').forEach(el => el.classList.remove('dragging'));
    document.querySelectorAll('.drop-target').forEach(el => el.classList.remove('drop-target'));
    pulisciRigaTarget();
    _stopAutoScroll();
    _dragId     = null;
    _dragSource = null;
    _dragExt    = null;
});

// ════════════════════════════════════════════════════════════
// AUTO-SCROLL durante il drag: avvicinando il puntatore al bordo
// alto/basso della finestra la pagina scorre, così si raggiungono
// anche le caselle in fondo senza lasciare il trascinamento.
// ════════════════════════════════════════════════════════════
let _autoScrollDir = 0, _autoScrollTimer = null;
function _autoScrollTick() {
    if (_autoScrollDir !== 0) window.scrollBy(0, _autoScrollDir * 14);
}
function _setAutoScroll(clientY) {
    const m = 110;                               // zona sensibile ai bordi (px)
    if (clientY > window.innerHeight - m)      _autoScrollDir = 1;
    else if (clientY < m)                      _autoScrollDir = -1;
    else                                       _autoScrollDir = 0;
    if (_autoScrollDir !== 0 && !_autoScrollTimer)
        _autoScrollTimer = setInterval(_autoScrollTick, 16);
}
function _stopAutoScroll() {
    if (_autoScrollTimer) { clearInterval(_autoScrollTimer); _autoScrollTimer = null; }
    _autoScrollDir = 0;
}

// ════════════════════════════════════════════════════════════
// ZONE DI DROP — listener delegato su tutto il documento
// ════════════════════════════════════════════════════════════
document.addEventListener('dragover', function(e) {
    _setAutoScroll(e.clientY);                    // scorri vicino ai bordi
    const target = getDropTarget(e.target);
    if (target) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        // Evidenzia solo la zona corrente
        document.querySelectorAll('.drop-target')
            .forEach(el => { if (el !== target) el.classList.remove('drop-target'); });
        target.classList.add('drop-target');
    }
    // Evidenzia la riga esatta su cui si atterrerebbe (niente più gap che
    // si apre spostando le altre righe: sono fisse).
    evidenziaRigaTarget(target, e.clientY);
});

document.addEventListener('dragleave', function(e) {
    const target = getDropTarget(e.target);
    if (target && !target.contains(e.relatedTarget)) {
        target.classList.remove('drop-target');
    }
});

document.addEventListener('drop', async function(e) {
    _stopAutoScroll();
    const target = getDropTarget(e.target);
    if (!target) return;

    e.preventDefault();
    target.classList.remove('drop-target');

    // ── Drop di un NOME ESTERNO (non in `vigili`) ────────────
    if (_dragExt) {
        const ext = _dragExt;
        _dragExt = null;
        // Su una posizione → persiste (crea o sposta)
        if (target.classList.contains('pos-card')) {
            const posId = parseInt(target.dataset.posId);
            const body  = document.getElementById('body-' + posId);
            // Riga esatta sotto il cursore: stesso trattamento dei vigili,
            // niente più "sempre in fondo alla squadra" (#123).
            const riga  = rigaAtY(body, e.clientY);
            const rigaTarget = (riga && riga.classList.contains('slot-empty'))
                ? parseInt(riga.dataset.ordine) : 0;
            const res = await ajax({
                azione:          'assegna_esterno',
                posizione_id:    posId,
                nome:            ext.nome,
                straordinario:   ext.str,
                patente_forzata: ext.pat,
                ext_id:          ext.extId || 0,
                ordine:          rigaTarget,
            });
            if (!res.ok) { showMsg(res.errore || '⚠️ Errore.', 'err'); return; }
            const oldBody = (ext.source === 'esterno_pos') ? ext.el.parentElement : null;
            ext.el.remove();                                    // toglie card sorgente
            body.appendChild(cardEsternoPos(res.ext_id, ext.nome, ext.str, ext.pat, posId, res.ordine));
            sincronizzaSlot(body);
            if (oldBody && oldBody !== body) sincronizzaSlot(oldBody);
            showMsg('➕ ' + ext.nome + ' aggiunto.');
            return;
        }
        // Da posizione → trascinato nei disponibili: rimuovi dal servizio
        if (target.id === 'organicoList' && ext.source === 'esterno_pos') {
            const res = await ajax({ azione: 'rimuovi_esterno', ext_id: ext.extId });
            if (!res.ok) { showMsg(res.errore || '⚠️ Errore.', 'err'); return; }
            const oldBody = ext.el.parentElement;
            ext.el.remove();
            sincronizzaSlot(oldBody);
            aggiungiCardEsternoDisp(ext.nome, ext.str, ext.pat);   // torna disponibile
        }
        return;
    }

    const vigileId = _dragId;
    const source   = _dragSource;
    _dragId        = null;
    _dragSource    = null;

    if (!vigileId) return;
    const p = PERSONALE[vigileId];
    if (!p) return;

    // ── Drag-out da ferie d'ufficio → rimetti in organico ────
    if (source === 'ufficio') {
        if (target.id === 'organicoList') {
            await rimuoviFerieUfficio(vigileId);
        }
        return;
    }

    // ── Drag-out da una posizione → rimetti tra i disponibili ──
    // (mancava: era possibile solo dal menu tasto destro → "Centrale
    // (disponibili)", IMPORTANTE segnalato da Lele — ora anche col drag.)
    if (source === 'posizione' && target.id === 'organicoList') {
        await mandaInCentraleDisponibili(vigileId);
        return;
    }

    // ── Drop su Capo Servizio / Vice Capo (barra intestazione) ─
    if (target.id === 'dropCapoServizio' || target.id === 'dropViceCapo') {
        const isCapo  = (target.id === 'dropCapoServizio');
        const inputEl = document.getElementById(isCapo ? 'csId' : 'vcsId');
        const prevId  = parseInt(inputEl.value) || 0;
        inputEl.value = String(vigileId);
        // #145: straordinario se era in salto (riposo), stessa regola delle posizioni normali.
        const straord = (source === 'salto' || p.saltoEff) ? 1 : 0;
        document.getElementById(isCapo ? 'csStraordInput' : 'vcsStraordInput').value = straord;
        const nomeEl = document.getElementById(isCapo ? 'csNome' : 'vcsNome');
        if (nomeEl) {
            nomeEl.textContent = p.nome;
            nomeEl.classList.remove('vuoto');
            nomeEl.style.color = colorePatente(p.patente);   // #152
        }
        const strEl = document.getElementById(isCapo ? 'csStr' : 'vcsStr');
        if (strEl) strEl.style.display = straord ? '' : 'none';
        const clearEl = document.getElementById(isCapo ? 'csClear' : 'vcsClear');
        if (clearEl) clearEl.style.display = '';
        await salvaIntestazioneAjax();
        // Il nuovo capo/vice esce dalle squadre/salto/assenze (il server le cancella):
        // toglie subito la sua card dal resto del servizio.
        rimuoviDOM(vigileId);
        // Il nuovo capo/vice esce dai disponibili; il precedente rientra (se non
        // è occupato altrove: posizione, salto o l'altro ruolo).
        setOccupato(vigileId, true, isCapo ? 'capo servizio' : 'vice capo');
        if (prevId && prevId !== vigileId && !_ruoloOccupatoAltrove(prevId, isCapo)) {
            setOccupato(prevId, false);
        }
        showMsg((isCapo ? '👤 Capo Servizio: ' : '👤 Vice Capo: ') + p.nome);
        return;
    }

    // ── Drop su posizione ────────────────────────────────────
    // (svuotaRuolo definita più sotto vicino a salvaIntestazioneAjax)
    if (target.classList.contains('pos-card')) {
        const posId = parseInt(target.dataset.posId);
        const body  = document.getElementById('body-' + posId);
        // Riga esatta sotto il cursore: niente riallineamento automatico,
        // si atterra lì e basta (stessa squadra o un'altra, non cambia).
        const riga        = rigaAtY(body, e.clientY);
        const rigaOrdine  = riga ? parseInt(riga.dataset.ordine) : 0;
        const draggedCard = document.getElementById('ass-' + vigileId);

        // ── Vigile già assegnato da qualche parte: sposta o scambia ──
        if (source === 'posizione' && draggedCard) {
            if (riga && riga.classList.contains('ass-card')) {
                // Riga occupata: scambio A↔B (stessa squadra o un'altra, uguale).
                const targetVid = parseInt(riga.dataset.vigileId);
                if (targetVid && targetVid !== vigileId) {
                    await eseguiScambioPosizioni(vigileId, draggedCard, targetVid, riga);
                }
                return;
            }
            // Riga vuota: sposta esattamente lì. Il posto di partenza resta
            // vuoto — nessuno si muove per compensare.
            const res = await ajax({ azione: 'sposta_riga', vigile_id: vigileId,
                                      posizione_id: posId, ordine: rigaOrdine });
            if (!res.ok) { showMsg('⚠️ ' + (res.errore || 'Errore.'), 'err'); return; }
            const oldBody = draggedCard.parentElement;
            draggedCard.dataset.posId  = posId;
            draggedCard.dataset.ordine = rigaOrdine;
            body.appendChild(draggedCard);
            aggiornaSiglaSede(draggedCard, posId);
            sincronizzaSlot(body);
            if (oldBody && oldBody !== body) sincronizzaSlot(oldBody);
            showMsg('↕️ Spostato.');
            return;
        }

        // STR = messo in servizio un riposante EFFETTIVO (canonico ± scambio salto),
        // non il salto canonico: chi ha ceduto il salto (scambioOut) lavora e NON è
        // straordinario anche se spostato; chi ha preso un salto (scambioIn) sì (#83).
        const straord = (source === 'salto' || p.saltoEff) ? 1 : 0;

        // Riga libera sotto il cursore: ci si mette esattamente lì. Se invece
        // è occupata (o non è stata individuata una riga precisa) il server
        // sceglie da sé la prima libera.
        const rigaTarget = (riga && riga.classList.contains('slot-empty')) ? rigaOrdine : 0;
        const esito = await eseguiAssegnaPos(vigileId, posId, straord, rigaTarget);
        if (esito === 'pieno') {
            showMsg('🚫 Posizione al completo. ' + p.nome + ' resta tra i disponibili.', 'err');
            return;
        }
        if (esito === 'err') { showMsg('⚠️ Errore.', 'err'); return; }
        showMsg('✅ ' + p.nome + (straord ? ' (STR)':'' ) + ' assegnato.');
        return;
    }

    // ── Drop su zona salto ───────────────────────────────────
    if (target.id === 'colSalto') {
        await azioneSalto(vigileId);
        return;
    }

    // ── Drop su box Ferie d'ufficio ──────────────────────────
    if (target.id === 'colFerieUfficio') {
        azioneFerieUfficio(vigileId);
        return;
    }

    // ── Drop su colonna assenza ──────────────────────────────
    const colId = target.id; // colFerie | colMissione | colMalattia
    // Codice tipo per quella colonna (colMissione→MISS, colMalattia→MAL: il primo
    // che mappa la colonna, come da comportamento storico del drop).
    const tipoCodice = Object.keys(TIPI_ASSENZA).find(
        k => TIPI_ASSENZA[k].colId === colId
    );
    if (!tipoCodice) return;
    await azioneAssenza(vigileId, tipoCodice);
});

// ════════════════════════════════════════════════════════════
// AZIONI VIGILE (riusate da drag&drop e dal menu tasto destro)
// ════════════════════════════════════════════════════════════
async function azioneSalto(vigileId) {
    const p = PERSONALE[vigileId];
    if (!p) return;
    if (p.saltoCanon) { showMsg('ℹ️ ' + p.nome + ' è già a riposo (salto).'); return; }
    const res = await ajax({ azione: 'metti_salto', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.', 'err'); return; }

    rimuoviDOM(vigileId);
    document.getElementById('colSalto')?.insertAdjacentHTML('beforeend',
        `<div class="assente-row"
              id="salto-${vigileId}"
              data-vigile-id="${vigileId}"
              draggable="true"
              style="cursor:grab">
           <span class="qual-dot ${p.qcodice}"></span>
           <span class="assente-nome">
             ${p.nome}
             ${(!p.sedeCentrale && p.sede) ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : ''}
           </span>
           <button class="assente-del"
                   onclick="rimuoviDaZonaSalto(${vigileId})"
                   title="Rimetti">✕</button>
         </div>`
    );
    setOccupato(vigileId, true, 'in salto');
    showMsg('😴 ' + p.nome + ' → salto.');
}

// Rimette una card in colonna Salto per un riposante EFFETTIVO, replicando la card
// canonica renderizzata dal server (draggable, niente ✕, badge 🔄 se preso via
// scambio salto). Idempotente. Usata quando si toglie una missione/malattia a un
// riposante: senza questo la card spariva (né salto né disponibili → serviva F5, #65).
function reinserisciInSalto(vigileId) {
    if (document.getElementById('salto-' + vigileId)) return;   // già presente
    const p = PERSONALE[vigileId];
    const col = document.getElementById('colSalto');
    if (!p || !col) return;
    const sedeBadge = (!p.sedeCentrale && p.sede)
        ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : '';
    const scambioBadge = p.scambioIn
        ? `<span class="scambio-badge" style="font-size:.65rem;color:#0a58ca;font-weight:700;margin-left:4px"
                 title="A riposo per scambio salto turno">🔄 scambio</span>` : '';
    const html =
        `<div class="assente-row" id="salto-${vigileId}" data-vigile-id="${vigileId}"
              draggable="true" style="cursor:grab">
           <span class="qual-dot ${p.qcodice}"></span>
           <span class="assente-nome" style="color:${colorePatente(p.patente)}">
             ${p.nome}${sedeBadge}${scambioBadge}
           </span>
           <span class="drag-icon-salto" style="font-size:.75rem;color:var(--grigio-md);margin-left:auto"
                 title="Trascina su posizione o assenza">⇄</span>
         </div>`;
    // Inserisce nel punto giusto dell'ordinamento del box salto — SOLO cognome
    // (#132), non sempre in fondo (#122): trova la prima riga con cognome
    // maggiore e si mette prima di quella.
    const righe = [...col.querySelectorAll('.assente-row[data-vigile-id]')];
    const dopo = righe.find(r => {
        const rp = PERSONALE[r.dataset.vigileId];
        return rp && rp.cognome.toLowerCase() > p.cognome.toLowerCase();
    });
    if (dopo) dopo.insertAdjacentHTML('beforebegin', html);
    else {
        const dropZone = document.getElementById('dropSalto');
        if (dropZone) dropZone.insertAdjacentHTML('beforebegin', html);
        else           col.insertAdjacentHTML('beforeend', html);
    }
    setOccupato(vigileId, true, 'in salto');
}

// #143: ✕ su una card IN STRAORDINARIO dentro una squadra → la toglie dalla
// posizione e basta (niente assenza/salto_servizio toccati). Chi era richiamato
// dal salto ha GIÀ una riga nel box salto (marcata "richiamato"/STR): la si
// ricostruisce pulita invece di lasciarla con lo stato vecchio.
async function rimuoviStraordinarioPos(vigileId, posId) {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const p = PERSONALE[vigileId];
    const res = await ajax({ azione: 'rimuovi_da_posizione', vigile_id: vigileId, posizione_id: posId });
    if (!res.ok) { showMsg(res.errore || '⚠️ Errore.', 'err'); return; }
    const card = document.getElementById('ass-' + vigileId);
    const body = card ? card.parentElement : null;
    if (card) card.remove();
    if (body) sincronizzaSlot(body);
    const saltoRow = document.getElementById('salto-' + vigileId);
    if (saltoRow) saltoRow.remove();
    reinserisciInSalto(vigileId);
    showMsg('↩️ ' + (p ? p.nome : 'Vigile') + ' torna in salto.');
}

// FER, senza richiesta Telegram dietro = "d'ufficio": chiede quanti turni
// consecutivi (#144) PRIMA di scrivere, esattamente come il drop diretto sul
// box "Ferie d'ufficio" — stesso comportamento sia da drag (colFerie) sia da
// tasto destro (IMPORTANTE, segnalato da Lele: dovevano dare lo stesso risultato).
async function azioneAssenza(vigileId, tipoCodice) {
    const p    = PERSONALE[vigileId];
    const tipo = TIPI_ASSENZA[tipoCodice];
    if (!p || !tipo) return;

    if (tipoCodice === 'FER') {
        const chk = await ajax({ azione: 'check_ferie_ufficio', vigile_id: vigileId });
        if (chk.ok && chk.ufficio) {
            azioneFerieUfficio(vigileId);
            return;
        }
    }

    const colId = tipo.colId;
    const res = await ajax({
        azione:          'assenza',
        vigile_id:       vigileId,
        tipo_assenza_id: tipo.id,
        sede_distaccata: '', data_da: '', data_a: '', nr_turni: '', note: ''
    });
    if (!res.ok) { showMsg('⚠️ Errore.', 'err'); return; }
    mostraAvvisiScambioFerie(res.avvisi_scambio_ferie);

    rimuoviDOM(vigileId);

    // Aggiorna riga salto se il vigile era a riposo di diritto (salto canonico)
    if (p.saltoCanon) {
        const sr = document.getElementById('salto-' + vigileId);
        if (sr) {
            // Missione/malattia: non è più a riposo → via dalla lista salti
            // (il server esclude gli assenti dai salti: così resta coerente col reload).
            if (tipoCodice === 'MISS' || tipoCodice === 'MAL') {
                sr.remove();
            } else {
                sr.removeAttribute('draggable');
                sr.style.cursor = 'default';
                sr.querySelector('.drag-icon-salto')?.remove();
                const nome = sr.querySelector('.assente-nome');
                if (nome && !nome.querySelector('.abil-badge')) {
                    nome.insertAdjacentHTML('beforeend',
                        `<span class="abil-badge"
                               style="font-size:.65rem;color:#e67e22;
                                      font-weight:700;margin-left:4px">
                           ${tipoCodice}
                         </span>`);
                }
            }
        }
    }

    inserisciOrdinato(document.getElementById(colId), buildAssenteRow(p, tipoCodice), p.cognome);

    // Riapprovata una ferie respinta → esce dal box "Ferie respinte"
    if (colId === 'colFerie') rimuoviCardRespinta(vigileId);

    if (!p.saltoCanon) setOccupato(vigileId, true, 'assente');
    showMsg('📋 ' + p.nome + ' → ' + tipoCodice + '.');
}

// Ferie d'ufficio: popup "quanti turni consecutivi" poi scrive su tutti i
// fogli del blocco (#144). Riusata dal drop diretto sul box "Ferie d'ufficio"
// E da azioneAssenza quando FER risulta d'ufficio — stessa funzione, stesso
// risultato indipendentemente da come si è arrivati qui.
function azioneFerieUfficio(vigileId) {
    const p = PERSONALE[vigileId];
    if (!p) return;
    chiediConferma({
        titolo:  "Ferie d'ufficio",
        testo:   `Quanti turni consecutivi di ferie d'ufficio per <b>${p.nome}</b>?<br>` +
                 `<input type="number" id="nTurniUfficio" min="1" max="60" value="1" ` +
                 `style="width:80px;margin-top:8px;padding:6px 8px;border:1px solid #ccc;` +
                 `border-radius:6px;font:inherit">`,
        okLabel: 'Conferma',
        onOk: async () => {
            const nInput = document.getElementById('nTurniUfficio');
            const n = Math.max(1, parseInt(nInput ? nInput.value : '1') || 1);
            const res = await ajax({ azione: 'ferie_ufficio', vigile_id: vigileId, n_turni: n });
            if (!res.ok) { showMsg(res.errore || '⚠️ Errore.', 'err'); return; }
            mostraAvvisiScambioFerie(res.avvisi_scambio_ferie);

            rimuoviDOM(vigileId);
            document.getElementById('ferieUfficioVuoto')?.remove();
            inserisciOrdinato(document.getElementById('colFerieUfficio'),
                buildUfficioRow(p, res.n_turni, res.data_da, res.data_a), p.cognome);
            inserisciOrdinato(document.getElementById('colFerie'),
                buildAssenteRow(p, 'FER', res.n_turni, res.data_da, res.data_a), p.cognome);
            if (!p.saltoCanon) setOccupato(vigileId, true, 'ferie ufficio');
            updateUfficioCount();
            const turniTxt = res.n_turni === 1 ? '1 turno' : (res.n_turni + ' turni');
            showMsg('🏛️ ' + p.nome + ' → ferie d\'ufficio (' + turniTxt + ').');
        },
    });
}

// ════════════════════════════════════════════════════════════
// ASSEGNAZIONE A POSIZIONE / SEDE (riusata da drag&drop e menu)
// ════════════════════════════════════════════════════════════
// Assegna un vigile a una posizione precisa: ajax + aggiornamento DOM.
// Ritorna 'ok' | 'pieno' | 'err'. Il toast lo fa il chiamante (messaggi diversi).
async function eseguiAssegnaPos(vigileId, posId, straord, rigaTarget = 0) {
    const p = PERSONALE[vigileId];
    if (!p) return 'err';
    const res = await ajax({ azione: 'assegna', vigile_id: vigileId, posizione_id: posId,
                             straordinario: straord, ordine: rigaTarget });
    if (!res.ok) return res.pieno ? 'pieno' : 'err';

    rimuoviDOM(vigileId);
    const body = document.getElementById('body-' + posId);
    if (body) {
        body.insertAdjacentHTML('beforeend', buildAssCard(p, posId, straord, res.ordine));
        sincronizzaSlot(body);
    }

    // Vigile a riposo (canonico o via scambio) messo in servizio → straordinario:
    // aggiorna la riga salto (#83, #121).
    if (p.saltoEff) {
        const sr = document.getElementById('salto-' + vigileId);
        if (sr) {
            sr.removeAttribute('draggable');
            sr.style.cursor = 'default';
            sr.querySelector('.drag-icon-salto')?.remove();
            const nome = sr.querySelector('.assente-nome');
            if (nome && !nome.querySelector('.str-badge')) {
                nome.insertAdjacentHTML('beforeend',
                    `<span class="str-badge"
                           style="font-size:.65rem;color:var(--giallo);
                                  font-weight:700;margin-left:4px">★ STR</span>`);
            }
        }
    }
    if (!p.saltoCanon) setOccupato(vigileId, true, 'in servizio');
    return 'ok';
}

// Scambia due vigili tra le loro posizioni (A↔B). Salva sul server, poi
// scambia le due card nel DOM (ognuna prende lo slot dell'altra).
async function eseguiScambioPosizioni(aVid, aCard, bVid, bCard) {
    const res = await ajax({ azione: 'scambia_posizioni', vigile_a_id: aVid, vigile_b_id: bVid });
    if (!res.ok) { showMsg('⚠️ ' + (res.errore || 'Errore.'), 'err'); return; }

    const aBody = aCard.parentElement, bBody = bCard.parentElement;
    const aPos = aCard.dataset.posId, aOrdine = aCard.dataset.ordine;
    const bPos = bCard.dataset.posId, bOrdine = bCard.dataset.ordine;
    // Scambio pieno: A prende posto+riga di B e viceversa (sincronizzaSlot
    // ricostruisce da data-ordine, non serve calcolare l'inserimento in DOM).
    aCard.dataset.posId = bPos; aCard.dataset.ordine = bOrdine;
    bCard.dataset.posId = aPos; bCard.dataset.ordine = aOrdine;
    bBody.appendChild(aCard);
    aBody.appendChild(bCard);
    aggiornaSiglaSede(aCard, bPos);
    aggiornaSiglaSede(bCard, aPos);
    sincronizzaSlot(aBody);
    if (bBody !== aBody) sincronizzaSlot(bBody);
    showMsg('🔄 Scambiati di posto.');
}

// Manda il vigile alla prima posizione libera di una sede (sigla): scorre le
// squadre di quella sede in ordine, la prima non piena = es. RP-1A.
async function assegnaASedeDaMenu(vigileId, sedeCodice) {
    const p = PERSONALE[vigileId];
    if (!p) return;
    const cards = [...document.querySelectorAll('.pos-card')]
        .filter(c => c.dataset.sede === sedeCodice);
    if (!cards.length) { showMsg('⚠️ Nessuna squadra per ' + sedeCodice + '.', 'err'); return; }
    const straord = p.saltoEff ? 1 : 0;   // riposo effettivo, non canonico (#83)
    for (const card of cards) {
        const body = document.getElementById('body-' + card.dataset.posId);
        const cap  = parseInt(body?.dataset.cap || '0');
        const occ  = body ? body.querySelectorAll('.ass-card').length : cap;
        if (occ >= cap) continue;                      // piena → prova la prossima
        const esito = await eseguiAssegnaPos(vigileId, parseInt(card.dataset.posId), straord);
        if (esito === 'err') { showMsg('⚠️ Errore.', 'err'); return; }
        if (esito === 'pieno') continue;               // presa in contemporanea → prossima
        const nomePos = card.querySelector('.pos-head')?.textContent.trim() || sedeCodice;
        showMsg('✅ ' + p.nome + ' → ' + nomePos + (straord ? ' (STR)' : ''));
        return;
    }
    showMsg('🚫 Tutte le posizioni di ' + sedeCodice + ' sono al completo.', 'err');
}

// "Centrale": toglie dalla posizione e rimette tra i disponibili (pool centrale).
async function mandaInCentraleDisponibili(vigileId) {
    const p = PERSONALE[vigileId];
    const res = await ajax({ azione: 'rimuovi', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.', 'err'); return; }
    rimuoviDOM(vigileId);
    if (p && !p.saltoCanon) setOccupato(vigileId, false);
    showMsg('🏠 ' + (p ? p.nome : 'Vigile') + ' → Centrale (disponibili).');
}

// ════════════════════════════════════════════════════════════
// MENU TASTO DESTRO — azioni rapide su un vigile: Ferie/Permesso/
// Missione/Salto + "Manda a sede" (sottomenu distaccamenti/Centrale).
// Lista invariata: le azioni condivise con il drag&drop (es. FER) devono
// però comportarsi ESATTAMENTE come il drag — vedi azioneAssenza().
// ════════════════════════════════════════════════════════════
let _ctxVigileId = null;

function buildCtxMenu() {
    if (document.getElementById('ctxMenu')) return;
    const m = document.createElement('div');
    m.id = 'ctxMenu';
    m.className = 'ctx-menu';
    m.style.display = 'none';
    m.innerHTML =
        `<div class="ctx-nome" id="ctxNome"></div>
         <button type="button" class="ctx-has-sub" data-act="SEDI">🏢 Manda a sede<span>▸</span></button>
         <div class="ctx-sep"></div>
         <button type="button" data-act="FER">🏖️ Ferie</button>
         <button type="button" data-act="PERM">📄 Permesso</button>
         <button type="button" data-act="MISS">✈️ Missione</button>`;
    document.body.appendChild(m);

    // Sottomenu sedi: elemento separato (la .ctx-menu ha overflow:hidden e lo taglierebbe)
    const sub = document.createElement('div');
    sub.id = 'ctxSubSedi';
    sub.className = 'ctx-menu ctx-submenu';
    sub.style.display = 'none';
    let html = '';
    SEDI_DISTACCATE.forEach(s => {
        html += `<button type="button" data-sede="${s.codice}"><b>${s.codice}</b> · ${s.nome}</button>`;
    });
    html += `<div class="ctx-sep"></div>`
          + `<button type="button" data-sede="__CENTR__">🏠 Centrale (disponibili)</button>`;
    sub.innerHTML = html;
    document.body.appendChild(sub);

    // Click sul menu principale
    m.addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const act = btn.dataset.act;
        if (act === 'SEDI') { e.stopPropagation(); apriSubSedi(); return; }
        const vid = _ctxVigileId;
        nascondiCtxMenu();
        if (vid == null) return;
        await azioneAssenza(vid, act);
    });
    // Su desktop, apri il sottomenu anche passandoci sopra
    m.querySelector('.ctx-has-sub')?.addEventListener('mouseenter', apriSubSedi);

    // Click su una sede nel sottomenu
    sub.addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-sede]');
        if (!btn) return;
        const sede = btn.dataset.sede;
        const vid  = _ctxVigileId;
        nascondiCtxMenu();
        if (vid == null) return;
        if (sede === '__CENTR__') await mandaInCentraleDisponibili(vid);
        else                      await assegnaASedeDaMenu(vid, sede);
    });
}

// Posiziona e mostra il sottomenu sedi accanto al menu principale
function apriSubSedi() {
    const m = document.getElementById('ctxMenu');
    const sub = document.getElementById('ctxSubSedi');
    if (!m || !sub) return;
    sub.style.visibility = 'hidden';
    sub.style.display = 'block';
    const mr = m.getBoundingClientRect();
    const sw = sub.offsetWidth, sh = sub.offsetHeight;
    let left = mr.right + 2;
    if (left + sw > window.innerWidth - 4) left = mr.left - sw - 2;  // niente spazio a destra → a sinistra
    if (left < 4) left = 4;
    let top = mr.top;
    if (top + sh > window.innerHeight - 4) top = window.innerHeight - sh - 6;
    if (top < 4) top = 4;
    sub.style.left = left + 'px';
    sub.style.top  = top + 'px';
    sub.style.visibility = 'visible';
}

function mostraCtxMenu(x, y, vigileId) {
    buildCtxMenu();
    _ctxVigileId = vigileId;
    const p = PERSONALE[vigileId];
    const m = document.getElementById('ctxMenu');
    const sub = document.getElementById('ctxSubSedi');
    if (sub) sub.style.display = 'none';   // reset sottomenu a ogni apertura
    const nome = document.getElementById('ctxNome');
    if (nome) nome.textContent = p ? p.nome : '';
    m.style.display = 'block';
    // Tieni il menu dentro la finestra
    const mw = m.offsetWidth, mh = m.offsetHeight;
    m.style.left = Math.min(x, window.innerWidth  - mw - 8) + 'px';
    m.style.top  = Math.min(y, window.innerHeight - mh - 8) + 'px';
}

function nascondiCtxMenu() {
    const m = document.getElementById('ctxMenu');
    if (m) m.style.display = 'none';
    const sub = document.getElementById('ctxSubSedi');
    if (sub) sub.style.display = 'none';
    _ctxVigileId = null;
}

document.addEventListener('contextmenu', function(e) {
    if (BLOCCATO) return;  // foglio bloccato da altri → niente azioni
    // Tutto il personale, ovunque sia sul foglio: disponibili, assegnati,
    // in salto, in ferie/permesso/missione/malattia, ferie d'ufficio.
    // (Stesso insieme della ricerca. Il box "Ferie respinte" è sola lettura,
    //  ma quella persona è comunque disponibile in organico → la si raggiunge lì.)
    const card = e.target.closest(
        '.ass-card[data-vigile-id], .assente-row[data-vigile-id], #organicoList .persona-card'
    );
    if (!card) return;     // fuori da una card → menu nativo del browser
    const vid = parseInt(card.dataset.vigileId || card.dataset.id);
    if (!vid || !PERSONALE[vid]) return;
    e.preventDefault();
    mostraCtxMenu(e.clientX, e.clientY, vid);
});

document.addEventListener('click', nascondiCtxMenu);
document.addEventListener('scroll', nascondiCtxMenu, true);
window.addEventListener('resize', nascondiCtxMenu);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') nascondiCtxMenu(); });

// ════════════════════════════════════════════════════════════
// FUNZIONE CHIAVE: identifica la zona di drop dall'elemento
// sotto il cursore — risale il DOM finché trova una zona valida
// ════════════════════════════════════════════════════════════
function getDropTarget(el) {
    while (el && el !== document.body) {
        // Organico (per drag-out da ferie ufficio)
        if (el.id === 'organicoList' || el.closest?.('#organicoList')) {
            return document.getElementById('organicoList');
        }
        // Posizione/squadra
        if (el.classList && el.classList.contains('pos-card')) return el;
        // Zona salto — intercetta sia il div interno che il contenitore
        if (el.id === 'colSalto') return el;
        if (el.closest && el.closest('#colSalto')) {
            return document.getElementById('colSalto');
        }
        // Capo Servizio / Vice Capo (barra intestazione)
        if (el.id === 'dropCapoServizio' || el.id === 'dropViceCapo') return el;
        if (el.closest && el.closest('#dropCapoServizio')) {
            return document.getElementById('dropCapoServizio');
        }
        if (el.closest && el.closest('#dropViceCapo')) {
            return document.getElementById('dropViceCapo');
        }
        // Colonne assenze — intercetta qualsiasi figlio
        // usando data-drop-zone sul contenitore .assenti-col
        const colParent = el.closest && el.closest('[data-drop-zone]');
        if (colParent) {
            const colId = colParent.dataset.dropZone;
            return document.getElementById(colId);
        }
        el = el.parentElement;
    }
    return null;
}

// ════════════════════════════════════════════════════════════
// NOMI ESTERNI (non in `vigili`)
// ════════════════════════════════════════════════════════════
function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
        ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

// Crea una card esterna trascinabile nei DISPONIBILI (non persistita finché
// non viene trascinata su una posizione). La ✕ qui la elimina e basta.
function aggiungiCardEsternoDisp(nome, str, pat) {
    const list = document.getElementById('organicoList');
    const card = document.createElement('div');
    card.className = 'persona-card esterno';
    card.dataset.extNome = nome;
    card.dataset.straord = str ? '1' : '0';
    card.dataset.pat     = pat || '';
    card.dataset.nome    = nome.toLowerCase();   // per il filtro ricerca
    card.setAttribute('draggable', 'true');
    card.innerHTML =
        '<span class="esterno-dot" title="Personale esterno al turno">★</span>' +
        '<span class="persona-nome" style="color:' + colorePatente(pat) + '">' + escHtml(nome + suffissoPatente(pat)) +
            (str ? ' <small style="color:var(--giallo);font-weight:700">STR</small>' : '') +
        '</span>' +
        '<button class="remove-btn" title="Elimina" ' +
                'onclick="this.parentElement.remove()">✕</button>';
    list.insertBefore(card, list.firstChild);
}

// Card esterna DENTRO una posizione (persistita: ext_id reale)
function cardEsternoPos(extId, nome, str, pat, posId, ordine) {
    const card = document.createElement('div');
    card.className = 'ass-card esterno';
    card.id = 'extass-' + extId;
    card.dataset.extId   = extId;
    card.dataset.extNome = nome;
    card.dataset.straord = str ? '1' : '0';
    card.dataset.pat     = pat || '';
    card.dataset.posId   = posId;
    card.dataset.ordine  = ordine;
    card.setAttribute('draggable', 'true');
    card.innerHTML =
        '<span class="esterno-dot" title="Personale esterno al turno">★</span>' +
        '<span class="ass-nome" title="' + escHtml(nome) + ' (esterno)">' +
            '<span class="ass-nome-txt" style="color:' + colorePatente(pat) + '">' + escHtml(nome + suffissoPatente(pat)) + '</span>' +
            (str ? '<span style="font-size:.6rem;color:var(--giallo);font-weight:700">STR</span>' : '') +
        '</span>' +
        '<button class="remove-btn" title="Rimuovi" ' +
                'onclick="rimuoviEsternoPos(' + extId + ')">✕</button>';
    return card;
}

function aggiungiEsterno() {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const inp = document.getElementById('esternoNome');
    const nome = inp.value.trim();
    if (!nome) { inp.focus(); return; }
    const str = document.getElementById('esternoStr').checked ? 1 : 0;
    const pat = document.getElementById('esternoPat2').checked ? '2'
              : document.getElementById('esternoPat3').checked ? '3' : '';
    aggiungiCardEsternoDisp(nome, str, pat);
    inp.value = '';
    inp.focus();
    showMsg('➕ "' + nome + '" creato: trascinalo su una posizione.');
}

// ✕ su una card esterna in posizione → la rimuove dal servizio e la rimette
// tra i disponibili (così non perdi il nome digitato).
async function rimuoviEsternoPos(extId) {
    const card = document.getElementById('extass-' + extId);
    const res = await ajax({ azione: 'rimuovi_esterno', ext_id: extId });
    if (!res.ok) { showMsg('⚠️ Errore.', 'err'); return; }
    if (card) {
        const body = card.parentElement;
        const nome = card.dataset.extNome, str = card.dataset.straord === '1' ? 1 : 0, pat = card.dataset.pat || '';
        card.remove();
        sincronizzaSlot(body);
        aggiungiCardEsternoDisp(nome, str, pat);
    }
    showMsg('↩️ Esterno rimosso dalla posizione.');
}

// ════════════════════════════════════════════════════════════
// RIMOZIONI
// ════════════════════════════════════════════════════════════
// Rimozione da posizione → "Centrale (disponibili)" del menu tasto destro
// (mandaInCentraleDisponibili). La vecchia x sulla card era ridondante, rimossa.

async function rimuoviDaAssenza(vigileId) {
    const res = await ajax({ azione: 'rimuovi_assenza', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }
    rimuoviDOM(vigileId);          // toglie la card sia da Ferie sia da Ferie d'ufficio
    updateUfficioCount();
    const p = PERSONALE[vigileId];
    if (res.era_richiesta) {
        // Solo le ferie DA RICHIESTA Telegram diventano "respinte"
        spostaInFerieRespinte(vigileId);
        showMsg('🚫 ' + (p ? p.nome : 'Vigile') + ' → ferie respinta.');
    } else {
        // Assenza/ferie a mano tolta: il vigile torna dove lo rimetterebbe il server
        // al reload. Riposante EFFETTIVO (canonico ± scambio salto) → colonna Salto
        // (#65: con missione/malattia la riga salto era stata rimossa, e prima qui
        // non veniva rimessa da nessuna parte → spariva). Altrimenti → disponibile.
        if (p && p.saltoEff) {
            reinserisciInSalto(vigileId);
            showMsg('😴 ' + p.nome + ' → salto.');
        } else {
            if (p) setOccupato(vigileId, false);
            showMsg('↩️ Rimesso disponibile.');
        }
    }
}

// Ferie respinta (deny da foglio): il vigile torna DISPONIBILE in organico
// (card normale, trascinabile) e compare un promemoria sola-lettura nel box.
function spostaInFerieRespinte(vigileId) {
    const p = PERSONALE[vigileId];
    if (p && !p.saltoCanon) setOccupato(vigileId, false);
    aggiungiCardRespinta(vigileId);
}

// Aggiunge la card promemoria (non trascinabile) nel box "Ferie respinte".
function aggiungiCardRespinta(vigileId) {
    const list = document.getElementById('ferieRespinteList');
    const p    = PERSONALE[vigileId];
    if (!list || !p) return;
    if (document.getElementById('resp-' + vigileId)) return; // già presente
    list.querySelector('.ferie-respinte-vuoto')?.remove();
    const sedeBadge = (!p.sedeCentrale && p.sede)
        ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : '';
    list.insertAdjacentHTML('beforeend',
        `<div class="persona-card" id="resp-${p.id}" data-id="${p.id}" style="cursor:default">
            <span class="qual-dot ${p.qcodice}"></span>
            <span class="persona-nome" style="color:${colorePatente(p.patente)}">
                ${p.nome}<small style="color:var(--rosso)">ferie respinta</small>
            </span>${sedeBadge}
         </div>`);
    updateRespinteCount();
}

// Rimuove la card promemoria dal box (quando la ferie torna approvata).
function rimuoviCardRespinta(vigileId) {
    document.getElementById('resp-' + vigileId)?.remove();
    const list = document.getElementById('ferieRespinteList');
    if (list && !list.querySelector('.persona-card')
            && !list.querySelector('.ferie-respinte-vuoto')) {
        list.insertAdjacentHTML('beforeend',
            '<div class="ferie-respinte-vuoto">Nessuna ferie respinta per questo turno.</div>');
    }
    updateRespinteCount();
}

function updateRespinteCount() {
    const list = document.getElementById('ferieRespinteList');
    if (!list) return;
    const n = list.querySelectorAll('.persona-card:not(.assente)').length;
    const badge = document.getElementById('ferieRespinteCount');
    if (badge) badge.textContent = n;
}

// ── Ferie d'ufficio ──────────────────────────────────────────
function buildUfficioRow(p, nTurni, dataDa, dataA) {
    const sedeBadge = (!p.sedeCentrale && p.sede)
        ? `<span class="persona-salto">${siglaSede(p.sede)}</span>` : '';
    const colore = colorePatente(p.patente);
    const turniBadge = nTurni
        ? `<span class="assente-info" style="font-size:.65rem;color:var(--grigio-md)"
                 title="${dataDa || ''}→${dataA || ''}">${nTurni}T</span>` : '';
    return `<div class="assente-row" data-vigile-id="${p.id}" data-cognome="${escHtml(p.cognome)}"
                 draggable="true" style="cursor:grab">
              <span class="qual-dot ${p.qcodice}"></span>
              <span class="assente-nome" style="color:${colore}">${p.nome}${sedeBadge}</span>
              ${turniBadge}
              <button class="assente-del" onclick="rimuoviFerieUfficio(${p.id})"
                      title="Togli ferie d'ufficio">✕</button>
            </div>`;
}

// #186: inserisce la riga HTML nella posizione alfabetica giusta (per cognome,
// via data-cognome) invece che sempre in fondo — prima serviva un refresh
// pagina per rivedere l'elenco in ordine.
function inserisciOrdinato(container, html, cognome) {
    if (!container) return;
    const tmp = document.createElement('div');
    tmp.innerHTML = html.trim();
    const nuovo = tmp.firstElementChild;
    if (!nuovo) return;
    const righe = [...container.querySelectorAll(':scope > .assente-row')];
    const target = righe.find(r =>
        (r.dataset.cognome || '').localeCompare(cognome || '', 'it', { sensitivity: 'base' }) > 0
    );
    if (target) container.insertBefore(nuovo, target);
    else container.appendChild(nuovo);
}

function updateUfficioCount() {
    const list = document.getElementById('colFerieUfficio');
    if (!list) return;
    const badge = document.getElementById('ferieUfficioCount');
    if (badge) badge.textContent = list.querySelectorAll('.assente-row').length;
}

async function rimuoviFerieUfficio(vigileId) {
    const res = await ajax({ azione: 'rimuovi_ufficio', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }
    rimuoviDOM(vigileId);
    const p = PERSONALE[vigileId];
    if (p && !p.saltoCanon) setOccupato(vigileId, false);
    updateUfficioCount();
    showMsg('↩️ Ferie d\'ufficio rimossa.');
}

async function rimuoviDaZonaSalto(vigileId) {
    const res = await ajax({ azione: 'rimuovi', vigile_id: vigileId });
    if (!res.ok) { showMsg('⚠️ Errore.','err'); return; }
    rimuoviDOM(vigileId);
    const p = PERSONALE[vigileId];
    if (p && !p.saltoCanon) setOccupato(vigileId, false);
    showMsg('↩️ Rimesso in organico.');
}

// ════════════════════════════════════════════════════════════
// FORM ASSENZA MANUALE
// ════════════════════════════════════════════════════════════
function toggleAssentiForm() {
    const f = document.getElementById('assenzaForm');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

document.getElementById('assTipo').addEventListener('change', function() {
    const codice = this.options[this.selectedIndex].dataset.codice;
    document.getElementById('campoSedeDist').style.display =
        codice === 'RC'  ? 'flex' : 'none';
    document.getElementById('campoFerie').style.display =
        codice === 'FER' ? 'flex' : 'none';
});

// Funzionario: salvataggio immediato alla scelta (come il drop di capo/vice).
document.getElementById('funzionario').addEventListener('change', function() {
    salvaIntestazioneAjax();
});

async function salvaAssenza() {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const vigileId      = parseInt(document.getElementById('assVigile').value);
    const tipoAssenzaId = parseInt(document.getElementById('assTipo').value);
    if (!vigileId) { showMsg('⚠️ Seleziona un vigile.','err'); return; }

    const res = await ajax({
        azione:          'assenza',
        vigile_id:       vigileId,
        tipo_assenza_id: tipoAssenzaId,
        sede_distaccata: document.getElementById('assSede').value,
        data_da:         document.getElementById('assDa').value,
        data_a:          document.getElementById('assA').value,
        nr_turni:        document.getElementById('assNrTurni').value,
        note:            document.getElementById('assNote').value,
    });

    if (res.ok) {
        mostraAvvisiScambioFerie(res.avvisi_scambio_ferie);
        rimuoviDOM(vigileId);
        const p = PERSONALE[vigileId];
        if (p) {
            // Trova la colonna corretta dal tipo assenza selezionato
            const tipoSel = document.getElementById('assTipo');
            const tipoCodice = tipoSel.options[tipoSel.selectedIndex]?.dataset?.codice || '';
            const colId = (TIPI_ASSENZA[tipoCodice] || {}).colId;
            if (colId) {
                inserisciOrdinato(document.getElementById(colId), buildAssenteRow(p, tipoCodice), p.cognome);
            }
            // Ridondanza ferie a mano: anche nel box "Ferie d'ufficio"
            if (colId === 'colFerie' && res.ufficio) {
                document.getElementById('ferieUfficioVuoto')?.remove();
                inserisciOrdinato(document.getElementById('colFerieUfficio'), buildUfficioRow(p), p.cognome);
                updateUfficioCount();
            }
            if (!p.saltoCanon) setOccupato(vigileId, true, 'assente');
        }
        toggleAssentiForm();
        showMsg('✅ Assenza registrata.');
    } else {
        showMsg('⚠️ Errore.','err');
    }
}

// ════════════════════════════════════════════════════════════
// SALVA INTESTAZIONE
// ════════════════════════════════════════════════════════════
function apriModalReset() {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const m = document.getElementById('modalReset');
    m.style.display = 'flex';
}
function chiudiModalReset() {
    document.getElementById('modalReset').style.display = 'none';
}
async function eseguiReset() {
    chiudiModalReset();
    showMsg('Reset in corso…', 'ok');
    const res = await ajax({ azione: 'reset_foglio' });
    if (res.ok) {
        location.reload();
    } else {
        showMsg('⚠️ Errore durante il reset.', 'err');
    }
}

function apriModalCopiaDiurno() {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const m = document.getElementById('modalCopiaDiurno');
    if (m) m.style.display = 'flex';
}
function chiudiModalCopiaDiurno() {
    const m = document.getElementById('modalCopiaDiurno');
    if (m) m.style.display = 'none';
}
async function eseguiCopiaDiurno() {
    chiudiModalCopiaDiurno();
    showMsg('Copia dal diurno in corso…', 'ok');
    const res = await ajax({ azione: 'copia_da_diurno' });
    if (res.ok) {
        location.reload();
    } else {
        showMsg('⚠️ ' + (res.errore || 'Errore durante la copia.'), 'err');
    }
}

// ════════════════════════════════════════════════════════════
// CAMBIO SALTO TURNO (inserimento manuale dal foglio)
// ════════════════════════════════════════════════════════════
function toggleCambioSalto() {
    const f = document.getElementById('cambioSaltoForm');
    if (!f) return;
    f.style.display = (f.style.display === 'none' || !f.style.display) ? 'flex' : 'none';
}

async function salvaCambioSalto() {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const selA = document.getElementById('csVigileA');
    const selB = document.getElementById('csVigileB');
    const a = parseInt(selA?.value);
    const b = parseInt(selB?.value);
    if (!a || !b) { showMsg('⚠️ Seleziona entrambi i vigili.', 'err'); return; }
    if (a === b) { showMsg('⚠️ Sono lo stesso vigile.', 'err'); return; }

    // Riepilogo per la conferma
    const nomeA = selA.options[selA.selectedIndex]?.text || '';
    const optB  = selB.options[selB.selectedIndex];
    const nomeB = optB?.text || '';
    chiediConferma({
        titolo:  'Conferma scambio salto',
        testo:   `Stai scambiando il salto:<br><br>` +
                 `• <b>${nomeA}</b> (B${window.CS_SLOT_A || '?'}) cede il riposo e torna in servizio<br>` +
                 `• <b>${nomeB}</b> va a riposo al suo posto<br><br>` +
                 `E viceversa sull'altra data del blocco. Confermi?`,
        okLabel: '🔄 Esegui scambio',
        okStyle: 'background:var(--rosso);color:#fff',
        onOk: async () => {
            const res = await ajax({ azione: 'scambia_salto', vigile_a_id: a, vigile_b_id: b });
            if (res.ok) {
                showMsg('✅ Salto scambiato.');
                setTimeout(() => location.reload(), 500);
            } else {
                showMsg('⚠️ ' + (res.errore || 'Errore.'), 'err');
            }
        }
    });
}

async function annullaScambio(scambioId) {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    chiediConferma({
        titolo:  'Annulla scambio salto',
        testo:   'Annulli questo scambio? I due vigili tornano alla situazione di partenza ' +
                 'su tutte le date interessate.',
        okLabel: '✖️ Annulla scambio',
        okStyle: 'background:var(--rosso);color:#fff',
        onOk: async () => {
            const res = await ajax({ azione: 'annulla_scambio', scambio_id: scambioId });
            if (res.ok) {
                showMsg('✅ Scambio annullato.');
                setTimeout(() => location.reload(), 500);
            } else {
                showMsg('⚠️ ' + (res.errore || 'Errore.'), 'err');
            }
        }
    });
}

// Scarica ODT = solo export del documento. NON approva ferie né notifica:
// quello avviene dal tasto Salva (con conferma). Download diretto, salvo
// l'avviso se resta personale in Disponibili (blocca la navigazione di
// default finché non si conferma, poi riparte manualmente).
function scaricaOdt(a) {
    const via = () => { window.location.href = a.href; };
    const conSquadre = () => avvisaSeSquadreIncomplete('lo scarico del .odt', via);
    if (contaDisponibili() > 0) {
        avvisaSeDisponibiliResidui('lo scarico del .odt', conSquadre);
        return false;
    }
    if (squadreIncomplete().length > 0) {
        avvisaSeSquadreIncomplete('lo scarico del .odt', via);
        return false;
    }
    return true;
}

// proponiFerie: solo il tasto "✉️ Invia" propone di approvare le ferie pending
// (e qui avvisa se resta personale in Disponibili o squadre incomplete, #149).
// Drag/svuota di capo/vice salvano in silenzio (niente popup di alcun tipo).
async function salvaIntestazioneAjax(proponiFerie = false) {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const esegui = () => _salvaIntestazioneEsegui(proponiFerie);
    const conSquadre = () => {
        if (proponiFerie && squadreIncomplete().length > 0) avvisaSeSquadreIncomplete('l\'invio delle comunicazioni', esegui);
        else esegui();
    };
    if (proponiFerie && contaDisponibili() > 0) {
        avvisaSeDisponibiliResidui('l\'invio delle comunicazioni', conSquadre);
        return;
    }
    await conSquadre();
}

async function _salvaIntestazioneEsegui(proponiFerie) {
    const res = await ajax({
        azione:             'salva_intestazione',
        capo_servizio_id:   document.getElementById('csId').value,
        capo_straordinario: document.getElementById('csStraordInput').value,
        vice_capo_id:       document.getElementById('vcsId').value,
        vice_straordinario: document.getElementById('vcsStraordInput').value,
        funzionario:        document.getElementById('funzionario').value,
    });
    if (!res.ok) { if (res.errore) showMsg('⚠️ ' + res.errore, 'err'); return; }

    // Se ci sono comunicazioni ferie da inviare (approvande / d'ufficio / negate),
    // proponi l'invio con conferma esplicita. Se il tasto è ✉️ Invia (proponiFerie)
    // ma non c'è nulla, avvisa esplicitamente invece di restare muto (#82, #125).
    const nf  = res.ferie_notifiche || {};
    const tot = parseInt(nf.tot || 0);
    if (proponiFerie && tot === 0) {
        chiediConferma({
            titolo:  'Comunicazioni ferie',
            testo:   'Nessuna comunicazione ferie da inviare per questo turno.',
            okLabel: 'OK',
            okStyle: 'background:var(--grigio-sc);color:#fff',
        });
        return;
    }
    showMsg('✅ Salvato.', 'ok');
    if (proponiFerie && tot > 0) {
        const nomi = nf.nomi || {};
        const voci = [];
        if (nf.approva > 0) voci.push(`<b>${nf.approva}</b> da comunicare (${(nomi.approva || []).join(', ')})`);
        if (nf.ufficio > 0) voci.push(`<b>${nf.ufficio}</b> d'ufficio (${(nomi.ufficio || []).join(', ')})`);
        if (nf.negate  > 0) voci.push(`<b>${nf.negate}</b> negate (${(nomi.negate || []).join(', ')})`);
        chiediConferma({
            titolo:  'Comunicazioni ferie',
            testo:   `Su questo turno ci sono comunicazioni ferie da inviare: ${voci.join(', ')}. ` +
                     `Confermando <b>invii le notifiche</b> ai vigili (Telegram + mail).`,
            okLabel: '✉️ Invia comunicazioni',
            okStyle: 'background:var(--rosso);color:#fff',
            onOk:    async () => {
                const r2 = await ajax({ azione: 'finalizza_ferie' });
                if (!r2.ok) { showMsg('⚠️ ' + (r2.errore || 'Errore comunicazioni ferie.'), 'err'); return; }
                const parti = [];
                if (r2.approvate > 0) parti.push(`${r2.approvate} approvate`);
                if (r2.ufficio   > 0) parti.push(`${r2.ufficio} d'ufficio`);
                if (r2.negate    > 0) parti.push(`${r2.negate} negate`);
                showMsg(`✅ Comunicazioni inviate: ${parti.length ? parti.join(', ') : 'nessuna nuova'}.`, 'ok');
            },
        });
    }
}

// Svuota il riquadro capo/vice (anche se precompilato dalle regole admin):
// libera il vigile e lo rimette tra i disponibili se non occupato altrove.
async function svuotaRuolo(isCapo) {
    if (BLOCCATO) { showMsg('🔒 Foglio bloccato.', 'err'); return; }
    const inputEl = document.getElementById(isCapo ? 'csId'  : 'vcsId');
    const prevId  = parseInt(inputEl.value) || 0;
    if (!prevId) return;
    inputEl.value = '';
    document.getElementById(isCapo ? 'csStraordInput' : 'vcsStraordInput').value = 0;
    const nomeEl  = document.getElementById(isCapo ? 'csNome' : 'vcsNome');
    if (nomeEl) { nomeEl.textContent = 'Trascina qui…'; nomeEl.classList.add('vuoto'); nomeEl.style.color = ''; }
    const strEl   = document.getElementById(isCapo ? 'csStr' : 'vcsStr');
    if (strEl) strEl.style.display = 'none';
    const clearEl = document.getElementById(isCapo ? 'csClear' : 'vcsClear');
    if (clearEl) clearEl.style.display = 'none';
    await salvaIntestazioneAjax();
    if (!_ruoloOccupatoAltrove(prevId, isCapo)) setOccupato(prevId, false);
    showMsg((isCapo ? 'Capo Servizio' : 'Vice Capo') + ' rimosso.');
}

// ════════════════════════════════════════════════════════════
// FILTRO ORGANICO
// ════════════════════════════════════════════════════════════
function filtraOrganico(testo) {
    // Ricerca su TUTTO il foglio: evidenzia dove si trova il vigile (assegnato,
    // assente o tra i disponibili) e oscura il resto. Scorre al primo risultato.
    const t = testo.toLowerCase().trim();
    const cards = document.querySelectorAll(
        '.ass-card[data-vigile-id], .assente-row[data-vigile-id], #organicoList .persona-card'
    );
    let primoFoglio = null, primoQualsiasi = null;
    cards.forEach(card => {
        const id = card.dataset.vigileId || card.dataset.id;
        const p  = id ? PERSONALE[id] : null;
        const cognome = ((p && p.cognome) ? p.cognome : (card.dataset.cognome || '')).toLowerCase();
        const match = t !== '' && cognome.startsWith(t);
        card.classList.toggle('trovato', match);
        card.classList.toggle('dimmed', t !== '' && !match);
        if (match) {
            if (!primoQualsiasi) primoQualsiasi = card;
            if (!primoFoglio && !card.classList.contains('persona-card')) primoFoglio = card;
        }
    });
    const target = primoFoglio || primoQualsiasi;
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ════════════════════════════════════════════════════════════
// AGGIUNGE STILE DROP-TARGET AL CSS DINAMICAMENTE
// ════════════════════════════════════════════════════════════
const styleEl = document.createElement('style');
styleEl.textContent = `
    .pos-card.drop-target {
        border-color: var(--rosso) !important;
        background: #fff0ee !important;
        box-shadow: 0 0 0 2px rgba(192,57,43,.25) !important;
    }
    #colSalto.drop-target,
    #colFerie.drop-target,
    #colMissione.drop-target,
    #colMalattia.drop-target {
        background: #fff0ee !important;
        outline: 2px dashed var(--rosso) !important;
        border-radius: 4px;
    }
    .fh-dropzone { border-radius: 6px; transition: background .12s, outline .12s; }
    .fh-dropzone.drop-target {
        background: #fff0ee !important;
        outline: 2px dashed var(--rosso) !important;
    }
`;
document.head.appendChild(styleEl);

// ════════════════════════════════════════════════════════════
// INIT
// ════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
    aggiornaContatore();
    applicaStatoBlocco();
    mostraAvvisiScambioFerie(AVVISI_SCAMBIO_FERIE);   // #188: copre apertura/generazione e reset (reload)

    // Rimuovi tutti gli handler inline dalle pos-card
    // (ora gestiti dal listener delegato sul documento)
    document.querySelectorAll('.pos-card').forEach(card => {
        card.removeAttribute('ondragover');
        card.removeAttribute('ondragleave');
        card.removeAttribute('ondrop');
    });
});

// ════════════════════════════════════════════════════════════
// POSIZIONE PANNELLO FERIE (preferenza del singolo browser)
// "basso" = nella sezione assenze (default) · "mezzo" = tra Disponibili e Centrale.
// Nessun dato sul server: solo localStorage. Non tocca drag&drop (i drop sono
// delegati sul document) né le altre 3 colonne assenze.
// ════════════════════════════════════════════════════════════
function _applyFeriePos(pos) {
    const panel  = document.getElementById('feriePanel');
    const mid    = document.getElementById('ferieMiddleSlot');
    const layout = document.querySelector('.foglio-layout');
    const miss   = document.querySelector('[data-drop-zone="colMissione"]');
    const btn    = document.getElementById('btnFeriePos');
    if (!panel || !mid || !layout) return;
    if (pos === 'mezzo') {
        mid.appendChild(panel);
        mid.style.display = 'flex';
        layout.classList.add('with-ferie-middle');
        if (btn) { btn.textContent = '⬇️'; btn.title = 'Rimetti Ferie in basso'; }
    } else {
        if (miss && miss.parentNode) miss.parentNode.insertBefore(panel, miss);
        mid.style.display = 'none';
        layout.classList.remove('with-ferie-middle');
        if (btn) { btn.textContent = '↔️'; btn.title = 'Sposta Ferie tra Disponibili e Centrale'; }
    }
}
function toggleFeriePos() {
    const cur  = localStorage.getItem('feriePos') === 'mezzo' ? 'mezzo' : 'basso';
    const next = cur === 'mezzo' ? 'basso' : 'mezzo';
    localStorage.setItem('feriePos', next);
    _applyFeriePos(next);
}
_applyFeriePos(localStorage.getItem('feriePos') === 'mezzo' ? 'mezzo' : 'basso');
</script>




<?php require __DIR__ . '/../includes/logbook_widget.php'; ?>
</body>
</html>
