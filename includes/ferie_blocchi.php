<?php
// Blocchi di ferie contigui — condivisi tra l'Agenda (ferie/index.php) e
// l'ODT (FoglioRenderer.php), così il conteggio turni e il periodo sono
// calcolati nello stesso identico modo.
//
// Un "blocco" = richieste di ferie consecutive di un vigile con gap <= 3 giorni
// E stesso "gruppo" (respinta o non-respinta). Una richiesta RESPINTA non ha
// assenza sul foglio (vedi feriaSyncAssenza): non può mai far da ponte tra due
// richieste ACCETTATE (accettato-respinto-respinto-accettato non è un blocco
// unico di 4 turni), ma due o più respinte consecutive restano un blocco loro
// — sono comunque contigue, solo di segno opposto. Il cambio di gruppo rompe
// sempre il blocco, a prescindere dalla distanza in giorni.
// #224: 'spezza_dopo' sul turno rompe il blocco subito dopo di esso, anche se
// il turno seguente sarebbe altrimenti contiguo — spezzatura manuale decisa
// dalla fureria (turno per turno approvato), indipendente da gap/gruppo/tipo.
// turniLabel = nr turni del blocco (DN vale 2). periodLabel = etichetta da–a.

if (!function_exists('blocchiContigui')) {

function blocchiContigui(array $richieste): array {
    if (empty($richieste)) return [];
    $blocks = [];
    $current = [$richieste[0]];
    for ($i = 1; $i < count($richieste); $i++) {
        $prevReq = end($current);
        $currReq = $richieste[$i];
        $prev = new DateTime($prevReq['data_richiesta']);
        $curr = new DateTime($currReq['data_richiesta']);
        $isNegativo = fn($r) => in_array($r['stato'] ?? null, ['rejected', 'declined'], true);
        $stessoGruppo = $isNegativo($prevReq) === $isNegativo($currReq);
        // Tipo diverso (es. ferie seguite da un permesso) non deve fondersi in
        // un blocco solo. Assente su entrambe (righe FoglioRenderer, sempre FER)
        // → default 1, confronto sempre vero, comportamento invariato.
        $stessoTipo = (int)($prevReq['tipo_assenza_id'] ?? 1) === (int)($currReq['tipo_assenza_id'] ?? 1);
        $spezzato   = !empty($prevReq['spezza_dopo']);
        $contiguo = (int)$curr->diff($prev)->days <= 3 && $stessoGruppo && $stessoTipo && !$spezzato;
        if ($contiguo) {
            $current[] = $currReq;
        } else {
            $blocks[] = $current;
            $current  = [$currReq];
        }
    }
    $blocks[] = $current;
    return $blocks;
}

function periodLabel(array $block): string {
    $da = new DateTime($block[0]['data_richiesta']);
    $a  = new DateTime(end($block)['data_richiesta']);
    if ($da->format('Y-m-d') === $a->format('Y-m-d')) {
        return $da->format('d/m');
    }
    return $da->format('d') . '–' . $a->format('d/m');
}

function turniLabel(array $block): int {
    $n = 0;
    foreach ($block as $r) {
        $n += ($r['tipo_turno'] === 'DN') ? 2 : 1;
    }
    return $n;
}

// Stato "visuale" del blocco: pending/declined sono entrambi "in attesa di
// comunicazione" e si equivalgono qui; un blocco non uniforme (anche se tutte
// le righe sono decise ma diverse tra loro) mostra "pending" — una comunicazione
// da inviare pesa più di una già inviata (voluto, vedi #129).
function statoBlock(array $block): string {
    $stati = array_unique(array_map(
        fn($r) => ($r['stato'] ?? '') === 'declined' ? 'pending' : ($r['stato'] ?? ''),
        $block
    ));
    return count($stati) === 1 ? $stati[0] : 'pending';
}

}
