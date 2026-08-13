# SOA HTC — Strategija opterećenja za 10.000+ takmičara

Status: radni nacrt 0.1  
Datum: 2026-08-11

## 1. Cilj

Dokazati da Competition Core pouzdano podržava najmanje 10.000 istovremenih aktivnih takmičarskih sesija, uz sigurnosnu marginu i bez gubitka/dupliranja attempt-a, odgovora ili rezultata. Posebno se testiraju preliminary i semifinal pikovi: identifikacija, skoro istovremeni start i timeout/submit talas.

## 2. Šta znači „10.000 istovremeno“

Broj aktivnih sesija nije dovoljan performance kriterijum. Pre testa treba potvrditi:

- koliko takmičara počinje u istom minutu/sekundi;
- prosečan i maksimalan broj pitanja;
- veličinu JSON submit payload-a i media sadržaja;
- trajanje testa;
- koliko klijenata periodično poziva status/time endpoint;
- odnos web/mobile klijenata;
- geografska raspodela i mrežni kvalitet;
- koliko submit-a stiže u poslednjih 5, 30 i 60 sekundi;
- očekivani broj admin/reporting zahteva tokom testa.

Bez ovih podataka početni scenario koristi konzervativne pretpostavke i zatim se kalibriše produkcionim merenjem ili legacy logovima.

## 3. Početni SLO predlog

Brojevi su predlog za validaciju, ne potvrđeno poslovno pravilo:

| Operacija | Cilj pod planiranim peak load-om |
| --- | --- |
| identifikacija / pristup | p95 < 800 ms, p99 < 1.5 s |
| lista dostupnih testova | p95 < 500 ms, p99 < 1 s |
| start attempt-a | p95 < 800 ms, p99 < 1.5 s |
| state/time refresh | p95 < 300 ms, p99 < 750 ms |
| final submit | p95 < 1.5 s, p99 < 3 s |
| server error rate | < 0.1% bez data-integrity greške |
| dupli validni attempt/result | 0 |
| prihvaćen submit bez trajnog zapisa | 0 |

Availability cilj tokom takmičarskog prozora i RTO/RPO zahtevaju posebnu ADR odluku.

## 4. Model opterećenja

### Scenario A — Ramp identifikacija

- 12.000 virtualnih korisnika u 10–15 minuta;
- provera identiteta i liste dostupnih testova;
- realističan procenat nevalidnih pokušaja;
- rate limiting mora štititi sistem bez blokiranja legitimnog pika.

### Scenario B — Start burst

- 10.000 validnih korisnika već ima session;
- 60% startuje u prvih 30 sekundi, ostatak u naredna 2 minuta;
- deo klijenata šalje dvoklik/retry;
- proverava se da postoji tačno jedan attempt po poslovnom ključu.

### Scenario C — Aktivni test

- 10.000–15.000 aktivnih sesija tokom punog trajanja;
- periodični status/time pozivi samo ako su stvarno potrebni;
- static/media sadržaj ide preko CDN-a;
- mešavina web/mobile mrežnih latencija.

### Scenario D — Timeout/submit burst

- kompletni payload-i sa realnim brojem odgovora;
- 50–70% submit-a u poslednjih 30 sekundi ili neposredno nakon isteka;
- retries, dupli zahtevi i klijentski timeout-i;
- nijedan rezultat se ne sme izgubiti ili duplirati.

### Scenario E — Essay i grading backlog

- attempt-i sa essay pitanjima prelaze u pending grading;
- proveriti da queue/backlog ne usporava potvrdu završnog submit-a;
- admin grading radi pod odvojenim, kontrolisanim load-om.

### Scenario F — Soak

- više sati očekivanog saobraćaja i najmanje jedan puni testni ciklus;
- detekcija memory leak-a, connection pool iscrpljenja, cache rasta i sporih query-ja.

### Scenario G — Failure/chaos

- restart jedne API instance usred testa;
- restart worker-a;
- privremeno usporenje Redis-a;
- DB failover simulacija prema odabranoj infrastrukturi;
- klijent izgubi odgovor na submit i ponavlja zahtev;
- CDN/object storage degradacija ne sme da pokvari već učitan tekstualni test.

## 5. Testni podaci

Generator mora napraviti reprezentativne:

- seasons/countries/regions/schools;
- 15.000+ registracija sa level raspodelom;
- competition i sample quiz-eve;
- exam/test hijerarhiju i realne dužine testa;
- multiple-choice, fill-the-gap i essay kombinacije;
- payload veličine od prosečne do najgoreg legitimnog slučaja;
- postojeće attempts/results za test autorizacije i konflikata.

Koristiti anonimizovane distribucije iz legacy podataka, ne produkcioni PII.

## 6. Arhitektonske mere

### API sloj

- stateless instance iza load balancer-a;
- unapred zagrejani framework/config/opcode cache;
- strogi request size i timeout limiti;
- connection pool/DB connection budžet po instanci;
- horizontalno skaliranje po latency, concurrency i saturation signalima;
- competitor endpoint-i bez N+1 upita.

### Baza

- kratke start/finish transakcije;
- unique constraints su poslednja linija zaštite od duplikata;
- indeksi prema stvarnim query planovima;
- bez teških sync agregata u submit transakciji;
- primary za attempt odluke; replica samo za bezbedne read modele;
- redovno analizirati lock wait, deadlock, slow queries i connection saturation.

### Redis

- cache i rate limit ključevi sa jasnim TTL-om;
- ne koristiti Redis kao jedini trajni dokaz prihvaćenog submit-a;
- odvojiti queue/cache politike kada je potrebno da backlog ne istisne kritične ključeve;
- definisati ponašanje ako Redis privremeno nije dostupan.

### Queue

Asinhrono:

- email;
- export/sertifikati;
- reporting agregati;
- nekritične notification akcije;
- dodatna post-processing obrada posle trajno potvrđenog submit-a.

Sinhrono pre odgovora klijentu:

- attempt autorizacija;
- trajni upis kompletnog submit-a;
- stanje koje garantuje da retry vraća isti ishod.

### Media

- object storage i CDN;
- versioned URLs i dugačak cache za nepromenljivi media asset;
- audio/slike ne prolaze kroz PHP aplikaciju pri svakom prikazu;
- pre testnog prozora pre-warm najčešćih asset-a ako CDN to zahteva.

## 7. Observability

Obavezne metrike:

- requests/sec, concurrency, p50/p95/p99 po endpoint-u;
- error/timeout/rate-limit procenat;
- aktivni, submitted, expired i void attempt-i;
- idempotency replay/conflict count;
- DB connections, CPU, I/O, lock waits, deadlocks i slow queries;
- Redis latency/memory/eviction;
- queue depth, oldest job age i failure rate;
- API/worker CPU i memory;
- submit payload veličina i trajanje transakcije;
- broj attempt-a koji su ostali active posle `expires_at`.

Svi zahtevi dobijaju correlation ID, ali logovi ne smeju sadržati odgovore, lozinke, tokene ili nepotreban PII.

## 8. Recovery i expiry

Frontend timeout submit nije dovoljan kao jedini mehanizam. Potreban je recovery proces koji pronalazi attempt-e sa prošlim `expires_at` i dovodi ih u konzistentno stanje. Pošto nema autosave-a, recovery može sačuvati samo podatke koji su već stigli serveru; ne može rekonstruisati odgovore iz zatvorenog browsera.

Treba razlikovati:

- klijent je poslao submit i nije dobio odgovor — retry mora vratiti isti trajni ishod;
- klijent nikada nije poslao odgovore — server nema odgovore za oporavak;
- server je primio nepotpun/nevalidan payload — attempt ostaje prema formalno definisanom failure pravilu.

Ovo je poslovno i tehnički prioritetno otvoreno pitanje.

## 9. Izvršavanje testova

1. baseline na jednoj instanci i praznoj/realnoj bazi;
2. query profiling i uklanjanje očiglednih bottleneck-a;
3. postepeni ramp: 1k → 3k → 5k → 10k → 15k;
4. burst start i submit scenariji;
5. soak i failure testovi;
6. ponavljanje posle svake relevantne arhitektonske promene;
7. finalni production-like test sa istom topologijom i veličinom podataka;
8. potpisan capacity report i runbook.

Test okruženje mora biti dovoljno slično produkciji; rezultat sa snažnijim ili potpuno drugačijim resursima nije produkcioni dokaz.

## 10. Release gate i sigurnosna margina

Minimum:

- svi SLO ciljevi ispunjeni na 10.000 korisnika;
- 15.000 korisnika ne izaziva gubitak integriteta, čak i ako latency pređe cilj;
- 0 duplih validnih attempt-a i rezultata;
- 0 prihvaćenih submit-a bez trajnih odgovora/rezultata;
- recovery, backup/restore i failure procedure probane;
- monitoring i alerti aktivni;
- dokumentovan maksimalan bezbedan kapacitet i uslovi skaliranja.

## 11. Takmičarski runbook

Pre događaja:

- potvrditi konfiguraciju i zaključati assessment verziju;
- proveriti backup, DB storage, queue, Redis i CDN;
- skalirati minimalni broj instanci unapred;
- pre-warm cache/CDN;
- pauzirati teške migracije, CMS bulk poslove i analitičke rebuild-e;
- otvoriti dashboard i komunikacioni kanal incident tima.

Tokom događaja:

- pratiti latency, error rate, DB locks/connections i submit throughput;
- ne pokretati ad-hoc teške SQL izveštaje;
- svaka intervencija ima vlasnika, vreme i audit belešku.

Posle događaja:

- potvrditi broj started/submitted/expired attempt-a;
- pokrenuti reconciliation i listu anomalija;
- sačuvati performance izveštaj i action items.

## 12. Otvorene infrastrukturne odluke

- cloud/provider i regioni;
- managed DB/Redis ili self-managed;
- availability cilj, RTO i RPO;
- početni/minimalni broj API i worker instanci;
- autoscaling metrika i maksimalni cap;
- da li je multi-region potreban;
- očekivani start/submit profil;
- dozvoljeni nivo degradacije za reporting/CMS tokom takmičenja.
