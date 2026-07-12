<?php
/**
 * auth.php — sessione e controllo accessi del gestionale.
 *
 * MODALITÀ SOFT: finché AUTH_ENFORCE === false il login NON è bloccante
 * (le pagine restano aperte). Serve a testare il login sul live senza
 * rischiare di restare chiusi fuori. Quando confermato, AUTH_ENFORCE → true.
 *
 * Ruoli: 'comando' (super-admin, tutti i turni) > 'admin' (un turno) >
 *        'user' (sola lettura del suo turno).
 */

if (session_status() === PHP_SESSION_NONE) session_start();

const AUTH_ENFORCE = true;   // ← false = soft (pagine aperte); true = blocco attivo

function utenteCorrente(): ?array { return $_SESSION['utente'] ?? null; }
function isLoggato(): bool        { return utenteCorrente() !== null; }
function ruoloCorrente(): ?string { return utenteCorrente()['ruolo'] ?? null; }
function turnoCorrente(): ?string { return utenteCorrente()['turno'] ?? null; }
function isComando(): bool        { return ruoloCorrente() === 'comando'; }
function isAdmin(): bool          { return in_array(ruoloCorrente(), ['comando','admin'], true); }
function isSoloLettura(): bool    { return ruoloCorrente() === 'user'; }

/** TEMP: chi vede il Logbook di sviluppo — Comando + beta-tester (rimuovere insieme alla pagina). */
function isLogbookUser(): bool    { return in_array(utenteCorrente()['username'] ?? '', ['lelemele', 'adminb'], true); }

/**
 * Permessi per turno dell'utente corrente: [turno => 'lettura'|'scrittura'].
 * Sorgente = tabella `utenti_turni` (matrice gestita da Comando, #90). `comando`
 * è super: tutti i turni in scrittura, matrice ignorata. Cache per-richiesta.
 */
function grantsTurni(): array {
    static $g = null;
    if ($g !== null) return $g;
    $g = [];
    if (isComando()) { foreach (['A','B','C','D'] as $t) $g[$t] = 'scrittura'; return $g; }
    $uid = (int)(utenteCorrente()['id'] ?? 0);
    if ($uid) {
        try {
            $st = getDB()->prepare("SELECT turno, livello FROM utenti_turni WHERE utente_id=?");
            $st->execute([$uid]);
            foreach ($st->fetchAll() as $r) $g[$r['turno']] = $r['livello'];
        } catch (Throwable $e) { /* tabella assente: nessun permesso */ }
    }
    return $g;
}

/** Visibilità per turno: comando tutto, altrimenti serve una riga in utenti_turni. */
function puoVedereTurno(?string $t): bool {
    if (isComando()) return true;
    return $t !== null && isset(grantsTurni()[$t]);
}

/** Modifica per turno: comando ovunque, altrimenti livello='scrittura' sul turno. */
function puoModificareTurno(?string $t): bool {
    if (isComando()) return true;
    return $t !== null && (grantsTurni()[$t] ?? '') === 'scrittura';
}

/**
 * Turno su cui si sta LAVORANDO (A/B/C/D). Da `?turno=`, poi memorizzato in
 * sessione; default = il turno dell'utente (comando non ne ha → 'B'). Se l'utente
 * non può vedere il turno richiesto, torna al proprio. È la dimensione introdotta
 * col multi-turno (vedi docs/multi-turno-e-permessi.md).
 */
function turnoAttivo(): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    $t = $_GET['turno'] ?? ($_SESSION['turno_attivo'] ?? (turnoCorrente() ?: 'B'));
    $t = strtoupper(substr((string)$t, 0, 1));
    // se non valido o non visibile all'utente: turno di casa (se visibile), altrimenti il primo visibile
    if (!in_array($t, ['A', 'B', 'C', 'D'], true) || !puoVedereTurno($t)) {
        $home = turnoCorrente();
        $vis  = turniVisibili();
        $t = ($home && puoVedereTurno($home)) ? $home : ($vis[0] ?? 'B');
    }
    $_SESSION['turno_attivo'] = $t;
    return $cache = $t;
}

/** Il turno attualmente in vista è modificabile dall'utente corrente? */
function puoModificareTurnoAttivo(): bool { return puoModificareTurno(turnoAttivo()); }

/**
 * Turno per le pagine di Amministrazione: fisso sul turno di casa (admin/user
 * non possono cambiarlo, niente tendina). Comando non ha un turno di casa
 * (gestisce tutti i turni): per lui segue il turno attivo di sessione, scelto
 * col selettore di `turnoComandoHtml()` qui sotto.
 */
function turnoAmministrazione(): string { return turnoCorrente() ?: turnoAttivo(); }

/** Vista corrente in sola lettura (user, oppure admin su un turno non suo). */
function soloLetturaAttivo(): bool { return !puoModificareTurnoAttivo(); }

/** Elenco dei turni che l'utente può VEDERE (per la tendina in navbar). */
function turniVisibili(): array {
    return array_values(array_filter(['A', 'B', 'C', 'D'], 'puoVedereTurno'));
}

/**
 * Selettore turno per il SOLO Comando: unico ruolo senza un turno di casa, che
 * quindi lavora su tutti i turni cambiando quello attivo di sessione (usato come
 * fallback da `turnoAmministrazione()` e da `turnoCorrente() ?: turnoAttivo()`
 * nelle pagine turno-scoped). Vuoto per admin/user: per loro il turno resta
 * fisso sul proprio, niente da scegliere.
 */
function turnoComandoHtml(): string {
    if (!isComando()) return '';
    $att  = turnoAttivo();
    $opts = '';
    foreach (turniVisibili() as $t) {
        $opts .= '<option value="' . $t . '"' . ($t === $att ? ' selected' : '') . '>Turno ' . $t . '</option>';
    }
    return '<select class="nav-turno" title="Turno su cui sta lavorando il Comando" '
         . 'style="padding:5px 8px;border-radius:6px;border:1px solid #cbd2d9;'
         . 'font:inherit;font-size:.82rem;font-weight:700;background:#fff;cursor:pointer" '
         . 'onchange="var p=new URLSearchParams(location.search);p.set(\'turno\',this.value);'
         . 'location.href=location.pathname+\'?\'+p.toString()">'
         . $opts . '</select>';
}

/**
 * Blocca una POST di modifica se il turno indicato è in sola lettura per l'utente.
 * Senza argomento usa il turno attivo di sessione (comportamento storico, per le
 * pagine che seguono `turnoAttivo()`). Le pagine di Amministrazione, che seguono
 * invece `turnoAmministrazione()`, passano quel turno esplicitamente.
 */
function vietaSeSolaLetturaTurno(?string $turno = null): void {
    if (!AUTH_ENFORCE) return;
    if (!puoModificareTurno($turno ?? turnoAttivo())) {
        http_response_code(403); exit('Turno in sola lettura per il tuo profilo.');
    }
}

/** Redirect al login se non autenticato (no-op in soft). */
function richiediLogin(): void {
    if (!AUTH_ENFORCE) return;
    if (!isLoggato()) { header('Location: /login.php'); exit; }
}

/** Solo admin di turno o comando (no-op in soft). */
function richiediAdmin(): void {
    if (!AUTH_ENFORCE) return;
    richiediLogin();
    if (!isAdmin()) { http_response_code(403); exit('Accesso negato: serve un profilo admin.'); }
}

/** Solo comando (no-op in soft). */
function richiediComando(): void {
    if (!AUTH_ENFORCE) return;
    richiediLogin();
    if (!isComando()) { http_response_code(403); exit('Accesso negato: solo Comando.'); }
}

/** TEMP: Comando + beta-tester Logbook (no-op in soft). */
function richiediLogbook(): void {
    if (!AUTH_ENFORCE) return;
    richiediLogin();
    if (!isLogbookUser()) { http_response_code(403); exit('Accesso negato: solo Comando e beta-tester.'); }
}

/** Blocca le azioni di modifica per gli user in sola lettura (no-op in soft). */
function vietaSeSolaLettura(): void {
    if (!AUTH_ENFORCE) return;
    if (isSoloLettura()) { http_response_code(403); exit('Profilo in sola lettura.'); }
}
