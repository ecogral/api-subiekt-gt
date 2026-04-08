# Dokumentacja zmian API — KSeF (faktury i korekty)

Ten dokument opisuje zachowanie API **api-subiekt-gt** po dostosowaniu do KSeF w Polsce. Przydatny przy integracji z innymi systemami (synchronizacja, raporty, ERP).

## Kontekst

- Od **2026-04-01** (data wystawienia dokumentu w polu odpowiadającym `dok_DataWyst` w Subiekcie) **faktury sprzedaży (FS, typ 2)** zwracane przez wybrane metody są **filtrowane**: w wyniku pojawiają się tylko te, które mają **nadany numer KSeF** zapisany w bazie Subiekta w tabeli **`ksef_NumerKSeF`** (powiązanie **`dok_NumerKSeFId`** na dokumencie).
- **Korekty faktury sprzedaży (KFS, typ 6)** **nie są** objęte tym obowiązkowym filtrem (nadal widoczne nawet bez numeru KSeF), ale jeśli numer KSeF korekty istnieje, API **dodaje go do odpowiedzi** (te same pola co przy FS).
- Źródło prawdy co do numeru zgodnie ze strukturą bazy Insert: **`ksef_NumerKSeF.ksefnr_NumerKSeF`**, **`ksefnr_DataNadania`** — a nie przestarzałe pola typu `dok_DataNumeruKSeF`.

Stała w kodzie PHP (zmiana daty w jednym miejscu):

- `APISubiektGT\SubiektGT\Document::KSEF_FS_FILTER_FROM` = `'2026-04-01'`

---

## Nowe pola w odpowiedzi JSON

Pojawiają się przy dokumentach, dla których wykonywane jest dołączenie numeru KSeF (FS i/lub KFS — zależnie od metody).

| Pole | Typ | Opis |
|------|-----|------|
| `ksef_number` | `string` \| `null` | Fizyczny numer KSeF (`ksefnr_NumerKSeF`). `null`, gdy brak powiązania lub pusty numer. |
| `ksef_number_assigned_at` | `string` \| `null` | Data nadania numeru (`ksefnr_DataNadania`), format `Y-m-d` w odpowiedzi. |

**Uwaga:** przy starszych fakturach (przed datą odcięcia) lub gdy Subiekt nie ma jeszcze wpisu w `ksef_NumerKSeF`, pola mogą być `null` mimo że dokument jest zwrócony.

---

## Reguły filtrowania (podsumowanie)

| Typ dokumentu | Kod w Subiekcie | Filtrowanie od `KSEF_FS_FILTER_FROM` |
|---------------|-----------------|--------------------------------------|
| Faktura sprzedaży | `dok_Typ = 2` (FS) | **Tak** — tylko z niepustym `ksefnr_NumerKSeF` |
| Korekta FS | `dok_Typ = 6` (KFS) | **Nie** — wszystkie (jak wcześniej); numer KSeF opcjonalnie w JSON |
| WZ, ZK | 11, 16 | **Nie** dotyczy numeru KSeF w tych zmianach (poza wspólnym zapytaniem po NIP) |

Kryterium daty dla filtra FS: **`dok_DataWyst` &lt; 2026-04-01** → faktura przechodzi bez wymogu numeru KSeF.  
**`dok_DataWyst` ≥ 2026-04-01** → wymagany niepusty numer po joinie do `ksef_NumerKSeF`.

---

## Metody API — zachowanie

Wywołanie HTTP zgodne z projektem: URL z parametrem `c` w postaci `{Klasa}/{metoda}`, treść żądania **JSON** z m.in. `api_key` i sekcją `data`.

### `Document/getInvoicesByDateRange`

- **Parametry:** `data.date_from`, `data.date_to` (`YYYY-MM-DD`), opcjonalnie `data.limit`.
- **Zwraca:** wyłącznie faktury FS (`dok_Typ = 2`) z zakresu dat.
- **KSeF:** dla faktur z datą wystawienia **≥ 2026-04-01** — tylko z numerem w `ksef_NumerKSeF`. Każda pozycja w `data.invoices` może zawierać `ksef_number`, `ksef_number_assigned_at`.

**Integracja:** jeśli integracja zakładała „wszystkie FV z zakresu”, po 2026-04-01 lista może być **krótsza** do momentu nadania numerów KSeF w Subiekcie. Warto logować `summary.total_count` i ewentualnie ponawiać odczyt po czasie.

---

### `Document/getUnpaidInvoices`

- **Zwraca:** nieopłacone FS.
- **KSeF:** ten sam warunek co dla FS od daty odcięcia — **bez numeru KSeF** faktura z **≥ 2026-04-01** **nie pojawi się** w wyniku.
- Pola `ksef_number`, `ksef_number_assigned_at` w elementach tablicy, gdy dotyczy.

---

### `Document/getLastDocuments`

- **Parametry:** `data.doc_type` (int), `data.limit`.
- **Ważne typy:** `2` = FS, `6` = KFS.
- **FS (`doc_type = 2`):** jak wyżej — od 2026-04-01 tylko z numerem KSeF; pola KSeF w każdym elemencie.
- **KFS (`doc_type = 6`):** **brak** obowiązkowego filtra daty KSeF; zwracane są korekty jak dotąd; jeśli jest numer — **`ksef_number`** / **`ksef_number_assigned_at`**.

Często używane przez `getLastDocumentsFromApi` (ta sama logika).

---

### `Document/getRecentDocuments`

- **Zwraca:** od początku **bieżącego miesiąca** grupy: FS, KFS, WZ, ZK.
- **FS:** filtr KSeF od daty odcięcia (jak przy innych metodach FS).
- **KFS:** bez filtra KSeF; pola numeru w dokumencie, gdy istnieją.

Struktura odpowiedzi: `data` z kluczami typu `FS`, `KFS`, itd.; dokumenty w `documents`.

---

### `Document/getDocumentsByTaxId`

- **Parametry:** `data.tax_id` (NIP).
- **Zwraca:** dokumenty klienta: WZ, FS, KFS, ZK.
- **FS:** od 2026-04-01 tylko z numerem KSeF (spójnie z `sqlWhereFsKsefWhenMixedDocTypes`).
- **KFS, WZ, ZK:** bez wymuszania numeru KSeF.
- **Pola KSeF w JSON:** tylko dla **FS i KFS** (`ksef_number`, `ksef_number_assigned_at`).

---

## Przykładowe szkielety żądań JSON

Wspólne dla wszystkich metod (dostosuj `api_key` i URL):

```http
POST /public/api/index.php?c=Document/getInvoicesByDateRange
Content-Type: application/json
```

```json
{
  "api_key": "TWÓJ_KLUCZ",
  "data": {
    "date_from": "2026-04-01",
    "date_to": "2026-04-30",
    "limit": 500
  }
}
```

```json
{
  "api_key": "TWÓJ_KLUCZ",
  "data": {
    "tax_id": "5250000000"
  }
}
```

```json
{
  "api_key": "TWÓJ_KLUCZ",
  "data": {
    "doc_type": 6,
    "limit": 200
  }
}
```

(`doc_type`: `2` — faktury, `6` — korekty.)

---

## Wskazówki dla drugiego projektu (integracja)

1. **Nie polegaj na tym, że liczba faktur z kwietnia 2026+ = liczba wystawionych w GT** — część może być **celowo pominięta** do czasu nadania numeru KSeF.
2. **Zapisuj `ksef_number`** jako klucz biznesowy / deduplikacji względem KSeF (gdy nie `null`).
3. **Korekty (KFS):** traktuj numer KSeF jako opcjonalny w warstwie **dostępności** listy, obowiązkowy w warstwie **treści** tylko jeśli sam narzucisz taką regułę po stronie odbiorcy.
4. **Synchronizacja przyrostowa:** dla FS po 2026-04-01 sensowne jest ponowne odpytanie po opóźnieniu (np. gdy numer KSeF dopisuje się asynchronicznie w Subiekcie).
5. **Baza Subiekta** musi zawierać tabele modułu KSeF (w tym **`ksef_NumerKSeF`**). W przeciwnym razie zapytania SQL z joinem zakończą się błędem — to wymóg środowiska, nie samego API.

---

## PDF faktury / korekty (`Document/getPdf`)

`DrukujDoPliku(ścieżka, 0)` w Sferze **nie** przełącza się sam na układ KSeF — generuje wydruk wg **domyślnego wzorca** dokumentu. Wydruk „KSeFowy” (QR, numer KSeF wg szablonu Insert) uzyskuje się przez **`DrukujDoPlikuWgWzorca(wzw_Id, ścieżka, 0)`**, gdzie:

- **`wzw_Id`** — identyfikator wzorca z tabeli **`wy_Wzorzec`** (pomoc Sfery: ten sam identyfikator co w konfiguracji wzorców wydruku),
- trzeci argument **`0`** = format PDF (`gtaTypPlikuPDF` / `TypPlikuEnum`).

### Zachowanie API

1. Jeśli dokument **nie ma** nadania numeru KSeF w **`ksef_NumerKSeF`** (przez `dok_NumerKSeFId`) → jak wcześniej: **`DrukujDoPliku`**.
2. Jeśli **ma** numer KSeF (FS lub KFS):
   - używane jest **`DrukujDoPlikuWgWzorca`** z ID wzorca ustalonym w kolejności:
     - **konfiguracja INI** (plik z `parse_ini_file` ładowany do `Config`):
       - `ksef_pdf_template_fs` — wzorzec dla faktury sprzedaży (typ 2),
       - `ksef_pdf_template_kfs` — wzorzec dla korekty FS (typ 6),
       - `ksef_pdf_template_id` — jeden wzorzec dla obu typów (jeśli brak dedykowanych),
     - **automat**: pierwszy widoczny wzorzec, którego nazwa zawiera `KSeF`, w **tej samej grupie `wy_Typ`**, co domyślny wzorzec dla danego typu dokumentu (`wy_WzDomyslny.wzd_Typ` = 2 lub 6),
   - przy błędzie COM lub braku wzorca → **fallback** do `DrukujDoPliku` (log w API).

W odpowiedzi `getPdf` dodane jest pole **`pdf_variant`**: `standard` | `ksef` | `standard_fallback` (integracja może sprawdzić, czy użyto wydruku KSeF).

### Jak ustalić `wzw_Id` w bazie

Przykład (SQL Server), nazwy wzorców zależą od wersji językowej / bazy:

```sql
SELECT wzw_Id, wzw_Nazwa, wzw_Typ FROM wy_Wzorzec
WHERE wzw_Widoczny = 1 AND (wzw_Nazwa LIKE N'%KSeF%' OR wzw_Nazwa LIKE N'%KSEF%');
```

Wybrany `wzw_Id` wpisz w INI jako `ksef_pdf_template_fs` i/lub `ksef_pdf_template_kfs`.

---

## Plik źródłowy zmian

Implementacja: `src/apisubiektgt/subiektgt/document.php` (m.in. `sqlLeftJoinKsefNumer`, `sqlWhereFsKsefRequiredFromCutoff`, `sqlWhereFsKsefWhenMixedDocTypes`, `ksefFieldsFromRow`, `printDocumentToPdfFile`).

---

*Dokument opisuje zachowanie API na podstawie implementacji w repozytorium. Data odcięcia (`KSEF_FS_FILTER_FROM`) może być zmieniona w kodzie.*
