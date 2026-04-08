# Przewodnik integracji z API Subiekt GT (projekt zewnętrzny)

Dokument dla **osobnej aplikacji** (backend, skrypt, serwis), która komunikuje się z **api-subiekt-gt** (wrapper REST + Sfera GT). Opisuje kontrakt HTTP/JSON, pola związane z **KSeF** oraz **pobieranie PDF** faktur i powiązanych dokumentów.

---

## 1. Założenia

- API jest dostępne pod znanym Ci adresem bazowym, np. `https://twoja-domena.pl/api-subiekt-gt/public/api/index.php`.
- Żądania są **POST**, treść **JSON**, odpowiedź **JSON**.
- Po stronie serwera działa proces Subiekt GT z modułem **KSeF** (w bazie SQL istnieją m.in. tabele `ksef_NumerKSeF`, `wy_Wzorzec`).

---

## 2. Format wywołania

### URL

```
POST {BAZOWY_URL}/index.php?c={Klasa}/{metoda}
```

Przykłady parametru `c`:

| `c` | Opis |
|-----|------|
| `Document/getPdf` | PDF dokumentu handlowego |
| `Document/getInvoicesByDateRange` | Lista faktur sprzedaży (FV) z zakresu dat |
| `Document/getDocumentsByTaxId` | Dokumenty klienta po NIP |
| `Document/getRecentDocuments` | Dokumenty od początku bieżącego miesiąca (grupy) |
| `Document/getLastDocuments` | Ostatnie dokumenty jednego typu |
| `Document/getUnpaidInvoices` | Nieopłacone faktury |

`Klasa` = nazwa PHP z pierwszą literą wielką (`Document`), `metoda` = dokładna nazwa metody.

### Nagłówki

Zalecane: `Content-Type: application/json` (ew. `application/json; charset=utf-8`).

### Ciało żądania (wspólny szkielet)

```json
{
  "api_key": "TWÓJ_KLUCZ_Z_KONFIGURACJI_SERWERA",
  "data": { }
}
```

- **`api_key`** — musi zgadzać się z wartością w pliku konfiguracyjnym serwera API (ten sam mechanizm co dotychczas).
- **`data`** — parametry konkretnej metody (poniżej).

---

## 3. Odpowiedź — ogólny kształt

### Sukces

```json
{
  "state": "success",
  "data": { }
}
```

### Błąd

```json
{
  "state": "fail",
  "message": "Opis błędu",
  "file": "...",
  "line": 0,
  "data": { }
}
```

Aplikacja kliencka powinna zawsze sprawdzać **`state`** przed odczytem `data`.

---

## 4. KSeF — pola w listach dokumentów

Przy metodach, które zwracają faktury sprzedaży (**FS**, typ 2) lub korekty (**KFS**, typ 6) z obsługą KSeF w API, w obiektach dokumentów mogą pojawić się:

| Pole | Typ | Znaczenie |
|------|-----|-----------|
| `ksef_number` | `string` \| `null` | Numer KSeF z tabeli `ksef_NumerKSeF` (powiązanie z dokumentem). |
| `ksef_number_assigned_at` | `string` \| `null` | Data nadania numeru, zwykle `YYYY-MM-DD`. |

**Uwagi dla integracji:**

- Dla faktur z **datą wystawienia od 2026-04-01** (wg logiki serwera API) lista metod typu „lista FV” może **pomijać** dokumenty **bez** nadanego numeru KSeF — nie porównuj liczby pozycji z „liczbą wystawionych w GT” 1:1 bez uwzględnienia statusu KSeF.
- Warto **zapisywać** `ksef_number` u siebie jako identyfikator urzędowy / deduplikacja, gdy nie jest `null`.
- Sensowne jest **ponowne odpytanie** listy po czasie, jeśli numer KSeF dopiero się pojawia w Subiekcie.

---

## 5. PDF dokumentu — `Document/getPdf`

### Parametry (`data`)

| Pole | Wymagane | Opis |
|------|----------|------|
| `doc_ref` | tak | Pełny numer dokumentu w Subiekcie, np. `FS 1/04/2026` — ten sam, który dostajesz w polu `doc_ref` z list faktur / dokumentów. |

### Przykład żądania

```http
POST /public/api/index.php?c=Document/getPdf
Content-Type: application/json
```

```json
{
  "api_key": "TWÓJ_KLUCZ",
  "data": {
    "doc_ref": "FS 1/04/2026"
  }
}
```

### Odpowiedź (`data` przy sukcesie)

| Pole | Typ | Opis |
|------|-----|------|
| `encoding` | `string` | Zwykle `"base64"`. |
| `pdf_file` | `string` | Zawartość PDF zakodowana Base64. |
| `file_name` | `string` | Sugerowana nazwa pliku (np. do zapisu na dysku). |
| `doc_ref` | `string` | Potwierdzenie dokumentu. |
| `doc_type` | `string` | Skrót typu (np. `FS`). |
| `state` | `number` | Status dokumentu w GT. |
| `accounting_state` | `mixed` | Status księgowy. |
| `is_exists` | `bool` | Czy dokument istnieje. |
| **`pdf_variant`** | `string` | **`standard`** — zwykły wydruk (`DrukujDoPliku`); **`ksef`** — wydruk wg wzorca KSeF (`DrukujDoPlikuWgWzorca`); **`standard_fallback`** — próba KSeF nie powiodła się lub brak skonfigurowanego wzorca — zwrócono zwykły PDF. |

### Obsługa po stronie Twojego projektu

1. Odbierz JSON, sprawdź `state === "success"`.
2. Weź `data.pdf_file`, zdekoduj Base64 do bajtów.
3. Zapisz plik lub wyślij do użytkownika / bufora.
4. Opcjonalnie zapisz **`pdf_variant`** w logu lub metadanych, aby wiedzieć, czy PDF jest „urzędowym” szablonem KSeF (`ksef`), czy zwykłym (`standard` / `standard_fallback`).

Przykład (pseudokod):

```
bytes = base64_decode(response.data.pdf_variant)
zapisz_plik(response.data.file_name, bytes)
```

(Język zależy od Twojego stacku: PHP `base64_decode`, C# `Convert.FromBase64String`, Node `Buffer.from(..., 'base64')`, itd.)

### Zachowanie serwera (ważne dla architektury)

- Jeśli dokument **ma** nadany numer KSeF w bazie, serwer **stara się** wygenerować PDF **wzorcem KSeF** (Sfera: `DrukujDoPlikuWgWzorca`).
- Konkretny **`wzw_Id`** wzorca może być ustawiony w konfiguracji INI serwera API (`ksef_pdf_template_fs`, `ksef_pdf_template_kfs`, `ksef_pdf_template_id`) albo dobrany automatycznie z `wy_Wzorzec` — **to nie jest konfigurowane z Twojego żądania JSON**.
- Gdy wzorzec jest niedostępny lub COM zgłosi błąd, dostaniesz nadal PDF w **`standard_fallback`** — nie traktuj braku `ksef` jako błędu HTTP; rozwiązanie po stronie GT / INI.

---

## 6. Inne metody przydatne przy KSeF

### `Document/getInvoicesByDateRange`

`data`: `date_from`, `date_to` (`YYYY-MM-DD`), opcjonalnie `limit`.

Zwraca faktury FS; od **2026-04-01** (data wystawienia) serwer zwraca tylko pozycje **z numerem KSeF** — patrz sekcja 4.

### `Document/getDocumentsByTaxId`

`data`: `tax_id` (NIP).

Grupy `FS`, `KFS`, `WZ`, `ZK`. Filtrowanie KSeF wg daty dotyczy **FS**; pola `ksef_*` przy FS i KFS, gdy dotyczy.

### `Document/getUnpaidInvoices`

Lista nieopłaconych FS z tym samym filtrem KSeF od daty odcięcia co lista FV.

### `Document/getLastDocuments`

`data`: `doc_type` (np. `2` = FS, `6` = KFS), `limit`.

### `Document/getRecentDocuments`

`data`: opcjonalnie `limit` — zestaw dokumentów od początku miesiąca.

---

## 7. Rekomendowany przepływ w Twoim projekcie

1. **Synchronizacja listy** — np. `getInvoicesByDateRange` lub `getDocumentsByTaxId`.
2. Dla wybranego wiersza odczytaj `doc_ref` oraz (jeśli jest) `ksef_number`.
3. **Pobranie PDF** — `Document/getPdf` z tym samym `doc_ref`.
4. Zinterpretuj **`pdf_variant`**: przy wymaganiach prawnych / UX możesz ostrzegać użytkownika, gdy jest `standard_fallback`, a oczekiwano wydruku KSeF (wtedy problem jest po stronie konfiguracji wzorca na serwerze API / Subiekcie).

---

## 8. Błędy i diagnostyka

| Objaw | Możliwa przyczyna |
|--------|-------------------|
| `state: fail`, komunikat o dokumencie | Zły `doc_ref`, dokument usunięty, brak uprawnień operatora GT. |
| PDF pusty / uszkodzony po dekodowaniu | Błąd po stronie klienta (np. podwójne kodowanie Base64); sprawdź surowy `pdf_file`. |
| Zawsze `pdf_variant: standard_fallback` przy fakturach KSeF | Na serwerze API brak poprawnego `wzw_Id` w INI lub brak pasującego wzorca w bazie; wymaga konfiguracji po stronie **właściciela API** (nie z JSON klienta). |
| Brak faktur z kwietnia 2026+ na liście | Faktury bez numeru KSeF są odfiltrowane przez API — uzupełnij KSeF w Subiekcie lub zmień politykę po stronie serwera. |

---

## 9. Wersjonowanie

Zachowanie opisane w tym dokumencie odpowiada wersji **api-subiekt-gt** z obsługą:

- filtrowania list FV pod KSeF (data odcięcia w kodzie: `Document::KSEF_FS_FILTER_FROM`),
- pól `ksef_number` / `ksef_number_assigned_at`,
- `getPdf` z `pdf_variant` i logiką wzorca KSeF.

Przy aktualizacji serwera API warto ponownie sprawdzić ten plik w repozytorium `docs/` pod kątem zmian.

---

*Dokument przeznaczony do skopiowania lub dołączenia do repozytorium projektu klienckiego. Nie zastępuje pełnej dokumentacji Sfery GT — szczegóły COM pozostają po stronie InsERT.*
