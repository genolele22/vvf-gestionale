<?php
/**
 * Scambio salto turno — logica condivisa (gestionale).
 *
 * Replica esatta di approva_scambio / annulla_scambio_approvato del bot
 * (database.py): scrive bot_scambi_salto + salto_override e patcha i fogli
 * di servizio già esistenti. Usata da:
 *   - foglio/nuovo.php   → crea/annulla lo scambio direttamente sul foglio
 *   - ferie/index.php    → approva/rifiuta in Agenda gli scambi nati dal bot
 *
 * Le funzioni assumono di girare DENTRO una transazione gestita dal chiamante.
 */
require_once __DIR__ . '/turni.php';
require_once __DIR__ . '/FoglioRenderer.php';

class ScambioConflitto extends RuntimeException {}

/**
 * Capienza di una posizione (slot del modello.odt + override editor).
 * Unica fonte della mappa capienze: capPos() in foglio/nuovo.php delega qui.
 */
function scambioCapPos(string $codice): int
{
    static $map = null;
    if ($map === null) {
        // Override editor (non tocca l'ODT, che mantiene le sue celle):
        //  - 5A e 1SMZ a 6 slot (più visione a schermo);
        //  - BL-1A e RP-1A a 7 slot, uniformi agli altri distaccamenti.
        $override = ['5A' => 6, '1SMZ' => 6, 'BL-1A' => 7, 'RP-1A' => 7,
                     'EL-1SMZ' => 4, 'AP-2VI' => 4];
        $map = $override + FoglioRenderer::slotCapacities();   // override vince
    }
    return $map[$codice] ?? 7;   // 7 = fallback se codice non nel modello
}

/**
 * Posizioni-candidate (in ordine di riempimento) per ogni sede NON centrale.
 * Condivisa da prepopolaAssegnazioni (nuovo.php) e scambioPiazzaInServizio.
 * MN (Multedo Nautica) non ha posizioni proprie → confluisce in ML-1NAU.
 */
function sedePosizioni(): array
{
    return [
        'ML' => ['ML-1A'], 'MN' => ['ML-1NAU'], 'GA' => ['GA-1NAU'], 'SM' => ['1SMZ'],
        'GE' => ['GE-1A'], 'BL' => ['BL-1A'], 'BS' => ['BS-1A'], 'RP' => ['RP-1A'],
        'CH' => ['CH-1A', 'CH-1B'],
        'AP' => ['AP-TEL', 'AP-1ROS', 'AP-1ASA', 'AP-1VI', 'AP-2VI'],
        'EL' => ['EL-1SMZ'],
    ];
}

/**
 * Piazza un vigile nelle SQUADRE di un foglio già esistente seguendo le stesse
 * regole del compositore di default (prepopolaAssegnazioni in nuovo.php), come
 * servizio ORDINARIO (in_straordinario=0). Usato per chi CEDE il salto in uno
 * scambio: torna a lavorare e va messo in squadra, non in straordinario.
 * Se è già assegnato non fa nulla; se la sua posizione è piena resta disponibile.
 */
function scambioPiazzaInServizio(PDO $pdo, int $foglioId, int $vigileId): void
{
    $chk = $pdo->prepare("SELECT 1 FROM assegnazioni WHERE foglio_id=? AND vigile_id=?");
    $chk->execute([$foglioId, $vigileId]);
    if ($chk->fetchColumn()) return;   // già in squadra

    $patMax = "(SELECT MAX(p.tipo) FROM vigili_patenti vp JOIN patenti p ON p.id=vp.patente_id WHERE vp.vigile_id=v.id)";
    $hasSO  = "EXISTS (SELECT 1 FROM vigili_abilitazioni va JOIN abilitazioni ab ON ab.id=va.abilitazione_id WHERE va.vigile_id=v.id AND ab.codice='SO')";
    $st = $pdo->prepare(
        "SELECT s.codice AS sede, v.qualifica_id, $patMax AS patente_max, ($hasSO) AS ha_so
         FROM vigili v JOIN sedi s ON s.id=v.sede_id WHERE v.id=? AND v.attivo=1"
    );
    $st->execute([$vigileId]);
    $v = $st->fetch();
    if (!$v) return;

    $sede = $v['sede'];
    $pat  = (int)$v['patente_max'];
    $qual = (int)$v['qualifica_id'];
    $so   = (int)$v['ha_so'] === 1;

    $codes = [];
    if ($sede !== 'C') {
        $codes = sedePosizioni()[$sede] ?? [];
    } else {
        if ($so && in_array($pat, [1, 2], true)) $codes[] = 'CENTR-OP';
        if ($qual === 1) {                                  // Vp
            if ($pat === 2)                    $codes = array_merge($codes, ['3A', '4B']);
            elseif (in_array($pat, [3, 4], true)) $codes = array_merge($codes, ['4A', '1FUN-AUTORADIO']);
            elseif ($pat === 1)                $codes = array_merge($codes, ['5A', '1SOP-AUTORIM']);
        } elseif (in_array($qual, [2, 3], true)) {          // Cr/Cs
            $codes[] = in_array($pat, [3, 4], true) ? '3B' : '2A';
        }
    }
    if (!$codes) return;   // nessuna regola → resta disponibile

    $posId = [];
    foreach ($pdo->query("SELECT id, codice FROM posizioni") as $p) $posId[$p['codice']] = (int)$p['id'];

    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM assegnazioni WHERE foglio_id=? AND posizione_id=?");
    $ins   = $pdo->prepare(
        "INSERT IGNORE INTO assegnazioni (id,foglio_id,posizione_id,vigile_id,ordine,in_straordinario)
         VALUES (?,?,?,?,?,0)"
    );
    foreach ($codes as $code) {
        $pid = $posId[$code] ?? 0;
        if (!$pid) continue;
        $cntSt->execute([$foglioId, $pid]);
        $cnt = (int)$cntSt->fetchColumn();
        if ($cnt < scambioCapPos($code)) {
            $aid = nextId($pdo, 'assegnazioni');
            $ins->execute([$aid, $foglioId, $pid, $vigileId, $cnt + 1]);
            return;
        }
    }
    // tutte le posizioni-candidate piene → resta disponibile (mai sforare capienza)
}

/**
 * Le 4 righe override [[data, tipo, vigile_out, vigile_in], ...] di uno scambio,
 * calcolate dai due slot e dal blocco. A cede il suo riposo a B e viceversa:
 *   - sulle date di A (slot_a): out=A, in=B  (B riposa al posto di A)
 *   - sulle date di B (slot_b): out=B, in=A
 * $sc deve avere: slot_a, slot_b, blocco_inizio, vigile_a_id, vigile_b_id.
 * I tipi D/N vengono dall'occorrenza reale (per i turni A e D il riposo è
 * N + D del giro dopo, non la coppia D+N consecutiva del turno B).
 */
function scambioOverrideRows(PDO $pdo, array $sc): array
{
    $stT = $pdo->prepare("SELECT turno FROM vigili WHERE id=?");
    $stT->execute([(int)$sc['vigile_a_id']]);
    $turno = (string)$stT->fetchColumn();

    $aOcc = slotDatesInBlocco((int)$sc['slot_a'], $sc['blocco_inizio'], $turno);
    $bOcc = slotDatesInBlocco((int)$sc['slot_b'], $sc['blocco_inizio'], $turno);
    if (!$aOcc || !$bOcc) {
        throw new RuntimeException('Slot fuori dal blocco.');
    }
    $aId = (int)$sc['vigile_a_id'];
    $bId = (int)$sc['vigile_b_id'];
    return [
        [$aOcc[0][0], $aOcc[0][1], $aId, $bId],
        [$aOcc[1][0], $aOcc[1][1], $aId, $bId],
        [$bOcc[0][0], $bOcc[0][1], $bId, $aId],
        [$bOcc[1][0], $bOcc[1][1], $bId, $aId],
    ];
}

/**
 * Guardia anti-conflitto: nessuno dei due vigili è già in uno scambio attivo
 * (salto_override.attivo=1) su una di queste righe. Solleva ScambioConflitto.
 */
function scambioGuardiaConflitto(PDO $pdo, array $rows, int $aId, int $bId): void
{
    $stDup = $pdo->prepare(
        "SELECT COUNT(*) FROM salto_override
         WHERE attivo=1 AND data=? AND tipo=?
           AND (vigile_in_id IN (?,?) OR vigile_out_id IN (?,?))"
    );
    foreach ($rows as [$d, $t, , ]) {
        $stDup->execute([$d, $t, $aId, $bId, $aId, $bId]);
        if ((int)$stDup->fetchColumn() > 0) {
            throw new ScambioConflitto(
                'Esiste già uno scambio attivo per uno dei due vigili in questo blocco.'
            );
        }
    }
}

/**
 * Scrive le righe salto_override e applica lo scambio sui fogli esistenti:
 *   - out torna a lavorare (esce dal salto)
 *   - in va a riposo (esce dalle assegnazioni, entra nel salto)
 * I fogli futuri non ancora creati li sistema prepopolaFoglio leggendo gli override.
 */
function scambioScriviOverride(PDO $pdo, int $sid, array $rows): void
{
    // I fogli da patchare sono SOLO quelli del turno dei due vigili: senza il
    // filtro si rischia di toccare il foglio omonimo (data,tipo) di un altro turno.
    $stT = $pdo->prepare(
        "SELECT v.turno FROM bot_scambi_salto s JOIN vigili v ON v.id=s.vigile_a_id WHERE s.id=?"
    );
    $stT->execute([$sid]);
    $turno = (string)$stT->fetchColumn();

    $insOv  = $pdo->prepare(
        "INSERT INTO salto_override
            (id, scambio_id, data, tipo, vigile_out_id, vigile_in_id, attivo)
         VALUES (?,?,?,?,?,?,1)"
    );
    $selF   = $pdo->prepare("SELECT id FROM fogli_servizio WHERE turno=? AND data_servizio=? AND tipo_turno=?");
    $delSS  = $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
    $delAss = $pdo->prepare("DELETE FROM assegnazioni  WHERE foglio_id=? AND vigile_id=?");
    $chkSS  = $pdo->prepare("SELECT 1 FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
    $insSS  = $pdo->prepare("INSERT INTO salto_servizio (id, foglio_id, vigile_id, richiamato) VALUES (?,?,?,0)");

    foreach ($rows as [$d, $t, $outId, $inId]) {
        $oid = nextId($pdo, 'salto_override');
        $insOv->execute([$oid, $sid, $d, $t, $outId, $inId]);

        $selF->execute([$turno, $d, $t]);
        $fid = $selF->fetchColumn();
        if ($fid) {
            $delSS->execute([$fid, $outId]);
            $delAss->execute([$fid, $inId]);
            $chkSS->execute([$fid, $inId]);
            if (!$chkSS->fetchColumn()) {
                $nss = nextId($pdo, 'salto_servizio');
                $insSS->execute([$nss, $fid, $inId]);
            }
            // Chi cede il salto torna in servizio: piazzato in squadra (ordinario)
            scambioPiazzaInServizio($pdo, (int)$fid, (int)$outId);
        }
    }
}

/**
 * Approva uno scambio ESISTENTE (nato dal bot, stato proposto/confermato):
 * guardia, stato='approvato', scrive override + patcha i fogli.
 * $approvatoDa = id del vigile/furiere che approva (0 → default = vigile_a_id).
 * Ritorna la riga bot_scambi_salto (per le notifiche). Solleva su stato non valido
 * o ScambioConflitto. Da chiamare dentro una transazione.
 */
function scambioApprovaEsistente(PDO $pdo, int $sid, int $approvatoDa = 0): array
{
    $st = $pdo->prepare("SELECT * FROM bot_scambi_salto WHERE id=?");
    $st->execute([$sid]);
    $sc = $st->fetch();
    if (!$sc) {
        throw new RuntimeException('Scambio inesistente.');
    }
    if (!in_array($sc['stato'], ['proposto', 'confermato'], true)) {
        throw new RuntimeException('Scambio non più in attesa (stato: ' . $sc['stato'] . ').');
    }

    $rows = scambioOverrideRows($pdo, $sc);
    scambioGuardiaConflitto($pdo, $rows, (int)$sc['vigile_a_id'], (int)$sc['vigile_b_id']);

    $appBy = $approvatoDa ?: (int)$sc['vigile_a_id'];
    $pdo->prepare("UPDATE bot_scambi_salto SET stato='approvato', approvato_da=? WHERE id=?")
        ->execute([$appBy, $sid]);
    scambioScriviOverride($pdo, $sid, $rows);
    return $sc;
}

/**
 * Rifiuta uno scambio ESISTENTE in attesa (proposto/confermato → rifiutato).
 * Nessun override viene scritto (lo scambio non era stato applicato).
 * Ritorna la riga bot_scambi_salto. Solleva su stato non valido.
 */
function scambioRifiuta(PDO $pdo, int $sid): array
{
    $st = $pdo->prepare("SELECT * FROM bot_scambi_salto WHERE id=?");
    $st->execute([$sid]);
    $sc = $st->fetch();
    if (!$sc) {
        throw new RuntimeException('Scambio inesistente.');
    }
    if (!in_array($sc['stato'], ['proposto', 'confermato'], true)) {
        throw new RuntimeException('Scambio non più in attesa.');
    }
    $pdo->prepare("UPDATE bot_scambi_salto SET stato='rifiutato' WHERE id=?")->execute([$sid]);
    return $sc;
}

/**
 * Annulla uno scambio APPROVATO (reversibile): per ogni override attivo inverte
 * il patch sui fogli esistenti, disattiva la riga e marca lo scambio 'annullato'.
 * Ritorna false se non c'era nulla da annullare. Da chiamare dentro una transazione.
 */
function scambioAnnulla(PDO $pdo, int $sid): bool
{
    $stO = $pdo->prepare(
        "SELECT id, data, tipo, vigile_out_id, vigile_in_id
         FROM salto_override WHERE scambio_id=? AND attivo=1"
    );
    $stO->execute([$sid]);
    $rows = $stO->fetchAll();
    if (!$rows) {
        return false;
    }

    $stT = $pdo->prepare(
        "SELECT v.turno FROM bot_scambi_salto s JOIN vigili v ON v.id=s.vigile_a_id WHERE s.id=?"
    );
    $stT->execute([$sid]);
    $turno = (string)$stT->fetchColumn();

    $selF  = $pdo->prepare("SELECT id FROM fogli_servizio WHERE turno=? AND data_servizio=? AND tipo_turno=?");
    $delSS = $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
    $chkSS = $pdo->prepare("SELECT 1 FROM salto_servizio WHERE foglio_id=? AND vigile_id=?");
    $insSS = $pdo->prepare("INSERT INTO salto_servizio (id, foglio_id, vigile_id, richiamato) VALUES (?,?,?,0)");
    $offO  = $pdo->prepare("UPDATE salto_override SET attivo=0 WHERE id=?");

    foreach ($rows as $r) {
        $selF->execute([$turno, $r['data'], $r['tipo']]);
        $fid = $selF->fetchColumn();
        if ($fid) {
            // chi era entrato in salto esce; chi cedeva (rester canonico) rientra
            $delSS->execute([$fid, (int)$r['vigile_in_id']]);
            // chi cedeva era stato piazzato in squadra: togli l'assegnazione, torna a riposo
            $pdo->prepare("DELETE FROM assegnazioni WHERE foglio_id=? AND vigile_id=?")
                ->execute([$fid, (int)$r['vigile_out_id']]);
            $chkSS->execute([$fid, (int)$r['vigile_out_id']]);
            if (!$chkSS->fetchColumn()) {
                $nss = nextId($pdo, 'salto_servizio');
                $insSS->execute([$nss, $fid, (int)$r['vigile_out_id']]);
            }
        }
        $offO->execute([(int)$r['id']]);
    }
    $pdo->prepare("UPDATE bot_scambi_salto SET stato='annullato' WHERE id=?")->execute([$sid]);
    return true;
}

/**
 * Enfila una notifica per il bot in bot_outbox (Telegram + mail le manda il bot,
 * drain). Idempotente via ctx univoco. data/tipo_turno restano NULL: per gli
 * scambi il bot ricostruisce le date dallo scambio (ctx = "scambio:<id>:<stato>").
 */
function scambioEnqueueOutbox(PDO $pdo, int $vigileId, string $tipo, string $ctx): void
{
    $has = $pdo->prepare("SELECT 1 FROM bot_outbox WHERE ctx=? LIMIT 1");
    $has->execute([$ctx]);
    if ($has->fetchColumn()) {
        return;
    }
    $nextId = nextId($pdo, 'bot_outbox');
    $pdo->prepare("INSERT INTO bot_outbox (id, vigile_id, tipo, ctx) VALUES (?,?,?,?)")
        ->execute([$nextId, $vigileId, $tipo, $ctx]);
}

/**
 * #188 (logbook, Moli): un vigile in FERIE su questo foglio non dovrebbe mai
 * essere anche coinvolto in uno scambio salto attivo per la stessa data/turno
 * — i due meccanismi decidono la sua presenza in modo indipendente e possono
 * andare in conflitto:
 *   - è il vigile_out (cede il riposo, l'accordo dello scambio è che TORNI in
 *     servizio) ma risulta anche in ferie → non si sa se aspettarlo o no;
 *   - è il vigile_in (è già a riposo per lo scambio) e in più risulta in ferie
 *     sullo stesso turno → ferie ridondante, probabilmente un errore a monte.
 * Solo rilevazione: nessun blocco, la scelta resta alla fureria (vedi popup
 * lato JS nei tre punti del ticket: generazione/reset foglio, ferie d'ufficio).
 */
function scambioConflittoFerie(PDO $pdo, int $foglioId, string $dataStr, string $tipoParam): array
{
    $st = $pdo->prepare(
        "SELECT a.vigile_id, v.cognome, v.disambiguatore, so.vigile_out_id, so.vigile_in_id
         FROM assenze a
         JOIN vigili v ON v.id = a.vigile_id
         JOIN salto_override so ON so.data = ? AND so.tipo = ? AND so.attivo = 1
              AND (so.vigile_out_id = a.vigile_id OR so.vigile_in_id = a.vigile_id)
         WHERE a.foglio_id = ? AND a.tipo_assenza_id = 1"
    );
    $st->execute([$dataStr, $tipoParam, $foglioId]);

    $avvisi = [];
    foreach ($st->fetchAll() as $r) {
        $nome = ucfirst(strtolower($r['cognome'])) . ($r['disambiguatore'] ? ' ' . (int)$r['disambiguatore'] : '');
        if ((int)$r['vigile_id'] === (int)$r['vigile_out_id']) {
            $avvisi[] = "$nome è segnato in ferie, ma con lo scambio salto dovrebbe tornare in servizio in questo turno.";
        } else {
            $avvisi[] = "$nome è segnato in ferie ed è già a riposo per lo scambio salto in questo turno: la ferie è ridondante.";
        }
    }
    return $avvisi;
}
