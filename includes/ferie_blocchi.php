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
        $stessoGruppo = (($prevReq['stato'] ?? null) === 'rejected')
                      === (($currReq['stato'] ?? null) === 'rejected');
        $contiguo = (int)$curr->diff($prev)->days <= 3 && $stessoGruppo;
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
