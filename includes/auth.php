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

const AUTH_ENFORCE = false;   // ← mettere true per attivare il blocco

function utenteCorrente(): ?array { return $_SESSION['utente'] ?? null; }
function isLoggato(): bool        { return utenteCorrente() !== null; }
function ruoloCorrente(): ?string { return utenteCorrente()['ruolo'] ?? null; }
function turnoCorrente(): ?string { return utenteCorrente()['turno'] ?? null; }
function isComando(): bool        { return ruoloCorrente() === 'comando'; }
function isAdmin(): bool          { return in_array(ruoloCorrente(), ['comando','admin'], true); }
function isSoloLettura(): bool    { return ruoloCorrente() === 'user'; }

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

/** Blocca le azioni di modifica per gli user in sola lettura (no-op in soft). */
function vietaSeSolaLettura(): void {
    if (!AUTH_ENFORCE) return;
    if (isSoloLettura()) { http_response_code(403); exit('Profilo in sola lettura.'); }
}
