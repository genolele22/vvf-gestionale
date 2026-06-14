# Checklist — Migrazione gestionale su server privato del Comando

Cosa serve per spostare il gestionale (+ bot Telegram + database) da Fly.io/TiDB Cloud
a un server interno del Comando VVF. Lista di controllo, non istruzioni operative.

> **Stato di partenza:** gestionale PHP + bot Python su Fly.io, database su TiDB Cloud (Francoforte).
> Codice già containerizzato (Docker) in entrambi i progetti.

---

## 0. Decisioni da prendere col Comando (prima di tutto)

- [ ] Chi è il referente tecnico del Comando (CED) che riceve e gestisce il sistema
- [ ] Dove gira: server fisico interno, macchina virtuale, o loro cloud privato
- [ ] Il server ha **Docker**? (sì = consegna pulita; no = installazione classica Apache+PHP)
- [ ] Chi mantiene il software dopo la consegna (Lele o il loro CED) e come si segnalano i bug
- [ ] Backup: chi fa il backup del database e con che frequenza

---

## 1. SICUREZZA — bloccante, da fare PRIMA della consegna

- [ ] **Aggiungere l'autenticazione (login)** — oggi chiunque con l'URL modifica tutto
- [ ] Decidere i ruoli: chi può solo vedere, chi può modificare (fureria/dirigenza)
- [ ] Rimuovere o proteggere la pagina **Logbook** (è temporanea, da sviluppo)
- [ ] Sistemare i 3 link morti in navbar (report, admin, logout) o realizzarli
- [ ] Forzare HTTPS (certificato sul loro server)

---

## 2. DATABASE — il punto più delicato (residenza dati)

- [ ] Verificare con il Comando dove possono stare i dati del personale (privacy/GDPR):
      quasi sicuramente **sul loro server**, non su cloud esterno
- [ ] Installare il loro database: **MySQL 8** o **MariaDB 10.6+**
- [ ] Importare lo schema: file `database vvf sql.sql` (sta nel repo del bot)
- [ ] Esportare i dati attuali da TiDB e importarli nel loro DB
- [ ] **Verificare gli AUTO_INCREMENT**: su MySQL/MariaDB veri funzionano (su TiDB no, per
      questo il codice usa `MAX(id)+1`). Su MySQL il trucco resta innocuo, ma conviene
      controllare che le tabelle abbiano le chiavi corrette
- [ ] Creare un utente DB dedicato al gestionale (non root) con permessi solo sul database `vvf_turno_b`
- [ ] Impostare il backup automatico del database

---

## 3. CODICE GESTIONALE (PHP)

- [ ] Consegnare la cartella `vvf-gestionale/` (o l'immagine Docker)
- [ ] Server: PHP 8.2 + Apache, con estensioni `pdo_mysql`, `calendar` (sono nel Dockerfile)
- [ ] Verificare il percorso del certificato SSL del DB in `config/db.php`
      (ora punta a `/etc/ssl/certs/ca-certificates.crt`)
- [ ] Controllare che `config/db.php` legga le credenziali dalle variabili d'ambiente (già così)

---

## 4. CODICE BOT TELEGRAM (Python)

- [ ] Consegnare la cartella `vvf-ferie-bot/`
- [ ] Server: Python 3 + dipendenze (`requirements.txt`), bot avviato come servizio
- [ ] Far comunicare bot e gestionale sulla **rete interna** del Comando
      (oggi su Fly.io si parlano via rete privata 6PN — va riconfigurato sul nuovo server)
- [ ] Decidere se il bot Telegram resta (serve internet) o se va sostituito/affiancato

---

## 5. CONFIGURAZIONE E SEGRETI (variabili d'ambiente)

Da impostare sul nuovo server, NON nel codice:

- [ ] Credenziali del nuovo database (`MYSQL_HOST`, `MYSQL_USER`, `MYSQL_PASSWORD`, ecc.)
- [ ] **Token del bot Telegram** (da rigenerare e custodire il Comando)
- [ ] **Chiave di cifratura** delle password email dei vigili (`FERNET_KEY` o equivalente)
- [ ] ID Telegram della fureria (`TELEGRAM_FURERIA_IDS`)
- [ ] Eventuali account email per l'invio delle conferme ferie/scambi

> ⚠️ Le password email dei vigili sono cifrate con la chiave attuale. Se si cambia la chiave,
> i vigili devono reinserire la password dal bot. Da pianificare.

---

## 6. TEST DOPO LA MIGRAZIONE (su ambiente nuovo, prima del "vai")

- [ ] Apertura cruscotto, calendario, lista vigili
- [ ] Creazione e download di un foglio di servizio (ODT)
- [ ] Pagina Agenda: ferie e scambi salto
- [ ] Bot: richiesta ferie da un vigile di prova → arriva sul gestionale
- [ ] Bot: scambio salto completo (proposta → conferma → approvazione fureria → mail)
- [ ] Invio email di conferma funzionante dal nuovo server
- [ ] Login/permessi funzionanti

---

## 7. PASSAGGIO DI CONSEGNE

- [ ] Documentazione minima per il CED (come avviare, dove sono i log, come fa il backup)
- [ ] Spegnere Fly.io e TiDB Cloud solo DOPO che il nuovo sistema è verificato e in uso
- [ ] Periodo di affiancamento (qualche settimana col vecchio sistema ancora pronto come riserva)

---

*Documento di pianificazione. Aggiornare man mano che le decisioni col Comando si concretizzano.*
