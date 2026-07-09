<?php
// Blocchi di ferie contigui — condivisi tra l'Agenda (ferie/index.php) e
// l'ODT (FoglioRenderer.php), così il conteggio turni e il periodo sono
// calcolati nello stesso identico modo.
//
// Un "blocco" = richieste di ferie consecutive di un vigile con gap <= 3 giorni,
// nessuna delle quali respinta. Una richiesta RESPINTA non ha assenza sul foglio
// (vedi feriaSyncAssenza) → non è mai parte di un'assenza reale e quindi non può
// "far da ponte" tra due richieste accettate: rompe sempre la contiguità, anche
// se la sua distanza è <= 3 giorni. Senza questa regola, una sequenza tipo
// accettato-respinto-respinto-accettato veniva letta come UN blocco di 4 turni
// (sbagliato sia sull'ODT che sull'aggregato in Agenda): la richiesta respinta
// va isolata nel suo blocco singolo, gli accettati restano separati.
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
        $contiguo = (int)$curr->diff($prev)->days <= 3
                 && ($prevReq['stato'] ?? null) !== 'rejected'
                 && ($currReq['stato'] ?? null) !== 'rejected';
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

function statoBlock(array $block): string {
    $stati = array_unique(array_column($block, 'stato'));
    if (count($stati) === 1) return $stati[0];
    return 'misto';
}

}
