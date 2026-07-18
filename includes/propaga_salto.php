<?php
/**
 * Propagazione anagrafica → fogli FUTURI.
 *
 * L'anagrafica è la fonte di verità del salto: quando cambia (nuovo vigile,
 * cambio salto definitivo, cambio turno, disattivazione) i fogli futuri già
 * popolati si riallineano da soli, DA ORA IN AVANTI — mai retroattivo.
 * Vale allo stesso modo per tutti e 4 i turni.
 *
 * Riallineamento chirurgico sul singolo vigile (le squadre degli altri non si
 * toccano), per ogni foglio con data_servizio > oggi e non bloccato:
 *  - deve riposare ⇔ foglio del SUO turno e salto del foglio = suo salto,
 *    corretto dagli scambi approvati (salto_override: out lavora, in riposa)
 *  - deve riposare ma non è tra i riposanti → via dalla squadra (solo
 *    assegnazioni normali: quelle in straordinario sono richiami espliciti e
 *    restano) e dentro salto_servizio
 *  - non deve riposare ma è tra i riposanti → via da salto_servizio: torna
 *    tra i Disponibili, la collocazione in squadra la decide il furiere
 *  - disattivato o di un altro turno → via da riposanti e squadre (le
 *    assegnazioni in straordinario su altri turni restano: richiamo esplicito)
 *
 * I fogli "nudi" (mai popolati) non c'entrano: si compongono alla prima
 * apertura leggendo l'anagrafica live. Anche il bot legge l'anagrafica live.
 */

require_once __DIR__ . '/turni.php';

/**
 * Riallinea i fogli futuri allo stato anagrafico attuale del vigile.
 * Ritorna il numero di fogli modificati. $dryRun = true: conta senza scrivere.
 * $daData: tocca solo fogli con data_servizio > $daData (default: oggi).
 */
function propagaSaltoVigile(PDO $pdo, int $vigileId, bool $dryRun = false, ?string $daData = null): int
{
    $st = $pdo->prepare(
        "SELECT v.id, v.attivo, v.turno, st.codice AS salto_cod
         FROM vigili v JOIN salti_turno st ON st.id = v.salto_id
         WHERE v.id = ?");
    $st->execute([$vigileId]);
    $v = $st->fetch();
    if (!$v) return 0;

    $oggi = $daData !== null && $daData >= date('Y-m-d') ? $daData : date('Y-m-d');

    // Fogli futuri che riguardano il vigile: quelli del suo turno, più quelli
    // di qualunque turno dove risulta già riposante o assegnato (serve per i
    // cambi di turno: va tolto dai fogli del turno che lascia).
    $stF = $pdo->prepare(
        "SELECT f.id, f.turno, f.data_servizio, f.tipo_turno,
                COALESCE(l.bloccato, 0)          AS bloccato,
                ss.id                            AS ss_id,
                a.posizione_id                   AS ass_pos,
                COALESCE(a.in_straordinario, 0)  AS ass_str,
                (SELECT COUNT(*) FROM assegnazioni  x WHERE x.foglio_id = f.id) AS n_ass_tot,
                (SELECT COUNT(*) FROM salto_servizio y WHERE y.foglio_id = f.id) AS n_ss_tot
         FROM fogli_servizio f
         LEFT JOIN fogli_lock l      ON l.foglio_id  = f.id
         LEFT JOIN salto_servizio ss ON ss.foglio_id = f.id AND ss.vigile_id = ?
         LEFT JOIN assegnazioni a    ON a.foglio_id  = f.id AND a.vigile_id  = ?
         WHERE f.data_servizio > ?
           AND (f.turno = ? OR ss.id IS NOT NULL OR a.posizione_id IS NOT NULL)");
    $stF->execute([$vigileId, $vigileId, $oggi, $v['turno']]);

    $stOvr = $pdo->prepare(
        "SELECT vigile_out_id, vigile_in_id FROM salto_override
         WHERE data = ? AND tipo = ? AND attivo = 1
           AND (vigile_out_id = ? OR vigile_in_id = ?)");

    $delSS  = $pdo->prepare("DELETE FROM salto_servizio WHERE foglio_id = ? AND vigile_id = ?");
    $delAss = $pdo->prepare("DELETE FROM assegnazioni   WHERE foglio_id = ? AND vigile_id = ?");

    $modificati = 0;
    foreach ($stF->fetchAll() as $f) {
        if ((int)$f['bloccato'] === 1) continue;
        // Fogli "nudi" (mai popolati): si compongono alla prima apertura
        // dall'anagrafica live — aggiungervi righe romperebbe l'auto-popolamento.
        if ((int)$f['n_ass_tot'] === 0 && (int)$f['n_ss_tot'] === 0) continue;

        $suoTurno = ((int)$v['attivo'] === 1 && $f['turno'] === $v['turno']);
        $deve = false;
        if ($suoTurno) {
            $slot = saltoRiposoNum($f['data_servizio'], $f['tipo_turno']);
            $deve = ($v['salto_cod'] === $f['turno'] . $slot);
            // Scambi salto approvati del vigile su questo (data, tipo)
            $stOvr->execute([$f['data_servizio'], $f['tipo_turno'], $vigileId, $vigileId]);
            foreach ($stOvr->fetchAll() as $o) {
                if ((int)$o['vigile_out_id'] === $vigileId) $deve = false;
                if ((int)$o['vigile_in_id']  === $vigileId) $deve = true;
            }
        }

        $inSalto   = $f['ss_id'] !== null;
        $assegnato = $f['ass_pos'] !== null;
        $cambiato  = false;

        if (!$suoTurno) {
            // Disattivato o foglio di un altro turno: via da tutto — tranne le
            // assegnazioni in straordinario di un vigile attivo (richiamo esplicito).
            if ($inSalto) { if (!$dryRun) $delSS->execute([$f['id'], $vigileId]); $cambiato = true; }
            $tieniStr = ((int)$v['attivo'] === 1 && (int)$f['ass_str'] === 1);
            if ($assegnato && !$tieniStr) { if (!$dryRun) $delAss->execute([$f['id'], $vigileId]); $cambiato = true; }
        } elseif ($deve && !$inSalto) {
            if ($assegnato && (int)$f['ass_str'] === 0) { if (!$dryRun) $delAss->execute([$f['id'], $vigileId]); }
            if (!$dryRun) {
                $pdo->prepare("INSERT IGNORE INTO salto_servizio (id, foglio_id, vigile_id, richiamato) VALUES (?,?,?,0)")
                    ->execute([nextId($pdo, 'salto_servizio'), $f['id'], $vigileId]);
            }
            $cambiato = true;
        } elseif (!$deve && $inSalto) {
            if (!$dryRun) $delSS->execute([$f['id'], $vigileId]);
            $cambiato = true;
        }

        if ($cambiato) $modificati++;
    }
    return $modificati;
}
