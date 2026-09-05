<?php
/**
 * #255/#259 (logbook, Moli): avviso prima di negare una ferie che fa parte di
 * un blocco contiguo in cui altri turni sono già stati confermati al vigile.
 *
 * Condiviso tra l'Agenda (ferie/index.php, tasto RESPINGI) e il Foglio
 * (foglio/nuovo.php, azione rimuovi_assenza su una ferie da richiesta): il
 * criterio di blocco è lo stesso di Agenda/ODT (blocchiContigui, gap <= 3gg,
 * stesso gruppo/tipo, spezza_dopo).
 *
 * #259: il controllo NON guarda più bot_outbox — deve scattare anche se il
 * foglio del turno precedente non è ancora stato confermato con l'invio, e
 * anche quando il vicino è 'pending' (accettato ma non ancora comunicato, o
 * ancora da decidere). Vale a dire: qualunque turno del blocco che oggi porta
 * un'assenza sul foglio (pending/approved) e che non sia tra quelli che si
 * stanno negando adesso.
 */

require_once __DIR__ . '/ferie_blocchi.php';
require_once __DIR__ . '/format.php';

if (!function_exists('feriaVicinoGiaConfermato')) {

/**
 * @param array $righeInNegazione righe bot_requests in via di negazione
 *                               (servono id, vigile_id, tipo_assenza_id)
 * @param array $idsEsclusi       id che NON contano come vicini (quelli in negazione)
 * @return string|null etichetta del vigile da mostrare nel popup, null se nessun avviso
 */
function feriaVicinoGiaConfermato(PDO $pdo, array $righeInNegazione, array $idsEsclusi): ?string {
    $idsEsclusi = array_map('intval', $idsEsclusi);

    foreach ($righeInNegazione as $r) {
        // Solo FER: è l'unico tipo per cui #255 è stato segnalato.
        if ((int)($r['tipo_assenza_id'] ?? 1) !== 1) continue;

        $st = $pdo->prepare(
            "SELECT id, data_richiesta, stato, tipo_assenza_id, spezza_dopo
             FROM bot_requests WHERE vigile_id = ? AND tipo_assenza_id = 1
             ORDER BY data_richiesta"
        );
        $st->execute([$r['vigile_id']]);
        $tutte = $st->fetchAll();

        $blocco = null;
        foreach (blocchiContigui($tutte) as $b) {
            if (in_array((int)$r['id'], array_column($b, 'id'), true)) { $blocco = $b; break; }
        }
        if (!$blocco) continue;

        // Vicini ancora in piedi nel blocco: il vigile li ha già visti confermati
        // (assenza sul foglio), negare questo turno gli spezza le ferie.
        $vicini = array_filter($blocco, fn($x) =>
            in_array($x['stato'], ['approved', 'pending'], true)
            && !in_array((int)$x['id'], $idsEsclusi, true));
        if (!$vicini) continue;

        $vst = $pdo->prepare(
            "SELECT v.cognome, v.disambiguatore, q.codice AS qcodice
             FROM vigili v JOIN qualifiche q ON q.id = v.qualifica_id WHERE v.id = ?"
        );
        $vst->execute([$r['vigile_id']]);
        $v = $vst->fetch();
        if ($v) return etichettaVigile($v);
    }
    return null;
}

}
