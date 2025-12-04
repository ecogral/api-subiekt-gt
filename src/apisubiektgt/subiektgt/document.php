<?php

namespace APISubiektGT\SubiektGT;

use COM;
use Exception;
use APISubiektGT\Logger;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\SubiektObj;
use APISubiektGT\SubiektGT\Product;
use APISubiektGT\SubiektGT\Customer;
use APISubiektGT\Helper;

class Document extends SubiektObj
{
    protected $documentGt;
    protected $products = false;
    protected $accounting_state = false;
    protected $reference;
    protected $comments;
    protected $customer = array();
    protected $doc_ref = '';
    protected $amount = 0;
    protected $state = -1;
    protected $date_of_delivery = '';
    protected $doc_type = '';
    protected $doc_type_id = 0;
    protected $documentDetail = array();
    protected $order_processing = 0;
    protected $id_flag = NULL;
    protected $id_gr_flag = NULL;
    protected $flag_name = '';
    protected $flag_comment = '';


    public function __construct($subiektGt, $documentDetail = array())
    {
        parent::__construct($subiektGt, $documentDetail);
        $this->excludeAttr(array('documentGt', 'documentDetail', 'doc_types'));
        if ($this->doc_ref != '' && $subiektGt->SuDokumentyManager->Istnieje($this->doc_ref)) {
            $this->documentGt = $subiektGt->SuDokumentyManager->Wczytaj($this->doc_ref);
            $this->getGtObject();
            $this->is_exists = true;
        }
        $this->documentDetail = $documentDetail;
    }


    protected function setGtObject()
    {
        return false;
    }

    public function getPdf()
    {
        $temp_dir = sys_get_temp_dir();
        if ($this->is_exists) {
            $file_name = $temp_dir . '/' . $this->gt_id . '.pdf';
            $this->documentGt->DrukujDoPliku($file_name, 0);
            $pdf_file = file_get_contents($file_name);
            unlink($file_name);
            Logger::getInstance()->log('api', 'Wygenerowano pdf dokumentu: ' . $this->doc_ref, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return array('encoding' => 'base64',
                'doc_ref' => $this->doc_ref,
                'is_exists' => $this->is_exists,
                'file_name' => mb_ereg_replace("[ /]", "_", $this->doc_ref . '.pdf'),
                'state' => $this->state,
                'accounting_state' => $this->accounting_state,
                'doc_type' => $this->doc_type,
                'pdf_file' => base64_encode($pdf_file));
        }
        return false;
    }


    public function getState()
    {
        return array('doc_ref' => $this->doc_ref,
            'is_exists' => $this->is_exists,
            'doc_type' => $this->doc_type,
            'state' => $this->state,
            'accounting_state' => $this->accounting_state,
            'order_processing' => $this->order_processing,
            'id_flag' => $this->id_flag,
            'flag_name' => $this->flag_name,
            'flag_comment' => $this->flag_comment,
            'amount' => $this->amount
        );
    }

    protected function getGtObject()
    {
        if (!$this->documentGt) {
            return false;
        }
        $this->gt_id = $this->documentGt->Identyfikator;
        $this->accounting_state = $this->documentGt->StatusKsiegowy;
        $this->doc_type = $this->doc_types[$this->documentGt->Typ];
        $this->doc_type_id = $this->documentGt->Typ;

        $o = $this->getDocumentById($this->gt_id);

        $this->reference = $o['dok_NrPelnyOryg'];
        $this->comments = $o['dok_Uwagi'];
        $this->doc_ref = $o['dok_NrPelny'];
        $this->state = $o['dok_Status'];
        $this->amount = $o['dok_WartBrutto'];
        $this->date_of_delivery = $o['dok_TerminRealizacji'];
        $this->order_processing = $o['dok_PrzetworzonoZKwZD'];
        if (is_null($this->id_gr_flag)) {
            $this->id_flag = $o['flg_Id'];
            $this->flag_name = $o['flg_Text'];
            $this->id_gr_flag = $o['flg_IdGrupy'];
            $this->flag_comment = $o['flw_Komentarz'];
        }

        if (!is_null($this->documentGt->KontrahentId)) {
            $customer = Customer::getCustomerById($this->documentGt->KontrahentId);
            $this->customer = $customer;
        }

        $positions = array();
        for ($i = 1; $i <= $this->documentGt->Pozycje->Liczba(); $i++) {
            $positions[$this->documentGt->Pozycje->Element($i)->Id]['name'] = $this->documentGt->Pozycje->Element($i)->TowarNazwa;
            $positions[$this->documentGt->Pozycje->Element($i)->Id]['code'] = $this->documentGt->Pozycje->Element($i)->TowarSymbol;
        }

        $products = $this->getPositionsByOrderId($this->gt_id);
        foreach ($products as $p) {
            $p_a = array('name' => $positions[$p['ob_Id']]['name'],
                'code' => $positions[$p['ob_Id']]['code'],
                'qty' => $p['ob_Ilosc'],
                'price' => $p['ob_WartBrutto'],
                'price_net' => $p['ob_CenaNetto'],
                'price_gross' => $p['ob_CenaBrutto'],
                'total_net' => $p['ob_WartNetto'],
                'total_gross' => $p['ob_WartBrutto']);
            $this->products[] = $p_a;
        }
    }

    protected function getDocumentById($id)
    {
        $sql = "SELECT * FROM dok__Dokument as d
					LEFT JOIN fl_Wartosc as fw ON (fw.flw_IdObiektu = d.dok_Id)
					LEFT JOIN fl__Flagi as f ON (f.flg_Id = fw.flw_IdFlagi)
				WHERE dok_Id = {$id}";
        $data = MSSql::getInstance()->query($sql);
        return $data[0];
    }

    protected function getPositionsByOrderId($id)
    {
        try {
            $sql = "SELECT ob_Id, ob_Ilosc, ob_CenaNetto, ob_CenaBrutto, ob_WartNetto, ob_WartBrutto FROM dok_Pozycja
			       WHERE ob_DokHanId = {$id}";
            $data = MSSql::getInstance()->query($sql);
            return is_array($data) ? $data : [];
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania pozycji dokumentu: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return [];
        }
    }

    /**
     * Pobiera powiązane zamówienie (ZK) dla faktury (FS)
     * 
     * @param int $invoice_id ID faktury
     * @return array|null Informacje o powiązanym zamówieniu lub null
     */
    protected function getRelatedOrderForInvoice($invoice_id)
    {
        try {
            // Szukamy zamówienia powiązanego z fakturą przez tabelę relacji dokumentów
            // W Subiekcie GT relacje są w tabeli dok_Powiazanie:
            // - pow_IdDokumentuZrodlowego = ID dokumentu źródłowego (zamówienie ZK)
            // - pow_IdDokumentuDocelowego = ID dokumentu docelowego (faktura FS)
            $sql = "SELECT TOP 1
                        d.dok_Id,
                        d.dok_NrPelny,
                        d.dok_DataWyst,
                        d.dok_WartBrutto,
                        d.dok_Status
                    FROM dok__Dokument d
                    INNER JOIN dok_Powiazanie p ON (p.pow_IdDokumentuZrodlowego = d.dok_Id AND p.pow_IdDokumentuDocelowego = {$invoice_id})
                    WHERE d.dok_Typ = 16  -- ZK (Zamówienie Klienta)
                    ORDER BY d.dok_DataWyst DESC";
            
            $data = MSSql::getInstance()->query($sql);
            
            // Jeśli nie znaleziono przez tabelę relacji, sprawdzamy czy faktura została przetworzona z zamówienia
            // i szukamy zamówienia tego samego klienta z podobną datą
            if (empty($data)) {
                // Pobieramy informacje o fakturze
                $invoice_sql = "SELECT dok_PlatnikId, dok_DataWyst 
                               FROM dok__Dokument 
                               WHERE dok_Id = {$invoice_id}";
                $invoice_data = MSSql::getInstance()->query($invoice_sql);
                
                if (!empty($invoice_data)) {
                    $customer_id = $invoice_data[0]['dok_PlatnikId'];
                    $invoice_date = $invoice_data[0]['dok_DataWyst'];
                    
                    // Szukamy zamówienia tego samego klienta z datą przed lub równą dacie faktury
                    $sql = "SELECT TOP 1
                                d.dok_Id,
                                d.dok_NrPelny,
                                d.dok_DataWyst,
                                d.dok_WartBrutto,
                                d.dok_Status
                            FROM dok__Dokument d
                            WHERE d.dok_Typ = 16  -- ZK
                            AND d.dok_PlatnikId = {$customer_id}
                            AND d.dok_DataWyst <= '{$invoice_date}'
                            AND d.dok_Status >= 0
                            ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";
                    
                    $data = MSSql::getInstance()->query($sql);
                }
            }
            
            if (!empty($data) && isset($data[0])) {
                return [
                    'order_id' => $data[0]['dok_Id'],
                    'order_ref' => $data[0]['dok_NrPelny'],
                    'order_date' => $data[0]['dok_DataWyst'],
                    'order_amount' => $data[0]['dok_WartBrutto'],
                    'order_status' => $data[0]['dok_Status']
                ];
            }
            
            return null;
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania powiązanego zamówienia: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return null;
        }
    }

    /**
     * Pobiera powiązane WZ (Wydanie Zewnętrzne) dla faktury (FS)
     * 
     * @param int $invoice_id ID faktury
     * @return array|null Informacje o powiązanym WZ lub null
     */
    protected function getRelatedWZForInvoice($invoice_id)
    {
        try {
            // Szukamy WZ powiązanego z fakturą przez tabelę relacji dokumentów
            // W Subiekcie GT relacje są w tabeli dok_Powiazanie:
            // - pow_IdDokumentuZrodlowego = ID dokumentu źródłowego (WZ)
            // - pow_IdDokumentuDocelowego = ID dokumentu docelowego (faktura FS)
            $sql = "SELECT TOP 1
                        d.dok_Id,
                        d.dok_NrPelny,
                        d.dok_DataWyst,
                        d.dok_WartBrutto,
                        d.dok_Status
                    FROM dok__Dokument d
                    INNER JOIN dok_Powiazanie p ON (p.pow_IdDokumentuZrodlowego = d.dok_Id AND p.pow_IdDokumentuDocelowego = {$invoice_id})
                    WHERE d.dok_Typ = 11  -- WZ (Wydanie Zewnętrzne)
                    ORDER BY d.dok_DataWyst DESC";
            
            $data = MSSql::getInstance()->query($sql);
            
            // Jeśli nie znaleziono przez tabelę relacji, szukamy WZ tego samego klienta z podobną datą
            if (empty($data)) {
                // Pobieramy informacje o fakturze
                $invoice_sql = "SELECT dok_PlatnikId, dok_DataWyst 
                               FROM dok__Dokument 
                               WHERE dok_Id = {$invoice_id}";
                $invoice_data = MSSql::getInstance()->query($invoice_sql);
                
                if (!empty($invoice_data)) {
                    $customer_id = $invoice_data[0]['dok_PlatnikId'];
                    $invoice_date = $invoice_data[0]['dok_DataWyst'];
                    
                    // Szukamy WZ tego samego klienta z datą przed lub równą dacie faktury
                    $sql = "SELECT TOP 1
                                d.dok_Id,
                                d.dok_NrPelny,
                                d.dok_DataWyst,
                                d.dok_WartBrutto,
                                d.dok_Status
                            FROM dok__Dokument d
                            WHERE d.dok_Typ = 11  -- WZ
                            AND d.dok_PlatnikId = {$customer_id}
                            AND d.dok_DataWyst <= '{$invoice_date}'
                            AND d.dok_Status >= 0
                            ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";
                    
                    $data = MSSql::getInstance()->query($sql);
                }
            }
            
            if (!empty($data) && isset($data[0])) {
                return [
                    'wz_id' => $data[0]['dok_Id'],
                    'wz_ref' => $data[0]['dok_NrPelny'],
                    'wz_date' => $data[0]['dok_DataWyst'],
                    'wz_amount' => $data[0]['dok_WartBrutto'],
                    'wz_status' => $data[0]['dok_Status']
                ];
            }
            
            return null;
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania powiązanego WZ: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return null;
        }
    }

    public function delete()
    {
        if (!$this->documentGt) {
            return false;
        }

        $this->documentGt->Usun(false);
        return array('doc_ref' => $this->doc_ref);
    }

    public function setFlag()
    {
        if (!$this->is_exists) {
            return false;
        }
        parent::flag(intval($this->id_gr_flag), $this->flag_name, '');
        return array('doc_ref' => $this->doc_ref,
            'flag_name' => $this->flag_name,
            'id_gr_flag' => $this->id_gr_flag);
    }

    public function add()
    {
        return true;
    }

    public function update()
    {
        return true;
    }

    public function getGt()
    {
        return $this->documentGt;
    }

    public function getUnpaidInvoices()
    {
        try {
            $sql = "SELECT 
                    d.dok_Id,
                    d.dok_NrPelny,
                    d.dok_WartBrutto,
                    d.dok_TerminRealizacji,
                    d.dok_DataWyst as date_issue,
                    d.dok_PlatTermin as payment_term,
                    d.dok_KwDoZaplaty as amount_to_pay,
                    d.dok_Status,
                    d.dok_StatusKsieg as accounting_state,
                    k.kh_Symbol as ref_id,
                    k.adr_NazwaPelna as company_name,
                    k.adr_NIP as tax_id,
                    k.adr_Adres as address,
                    k.adr_Kod as post_code,
                    k.adr_Miejscowosc as city,
                    k.kh_EMail as email,
                    k.adr_Telefon as phone
                FROM dok__Dokument d
                LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                WHERE d.dok_Typ = 2 
                AND d.dok_StatusKsieg = 0
                AND d.dok_Status >= 0
                AND d.dok_KwDoZaplaty > 0
                AND d.dok_Rozliczony = 0";

            $data = MSSql::getInstance()->query($sql);
            $result = [];

            foreach ($data as $row) {
                $result[] = [
                    'doc_ref' => $row['dok_NrPelny'],
                    'amount' => $row['dok_WartBrutto'],
                    'amount_to_pay' => $row['amount_to_pay'],
                    'date_issue' => $row['date_issue'],
                    'payment_term' => $row['payment_term'],
                    'date_of_delivery' => $row['dok_TerminRealizacji'],
                    'status' => $row['dok_Status'],
                    'accounting_state' => $row['accounting_state'],
                    'customer' => [
                        'ref_id' => $row['ref_id'],
                        'company_name' => $row['company_name'],
                        'tax_id' => $row['tax_id'],
                        'address' => $row['address'],
                        'post_code' => $row['post_code'],
                        'city' => $row['city'],
                        'email' => $row['email'],
                        'phone' => $row['phone']
                    ]
                ];
            }

            Logger::getInstance()->log('api', 'Pobrano listę nieopłaconych faktur', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'success', 'data' => $result];
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania nieopłaconych faktur: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'fail', 'message' => $e->getMessage()];
        }
    }

    public function getUnpaidInvoicesFromSettlements()
    {
        try {
            $sql = "SELECT 
                    Bk.nzf_Id,
                    Bk.nzf_NumerPelny,
                    Bk.nzf_Data as date_issue,
                    Bk.nzf_TerminPlatnosci as payment_term,
                    Bk.DniSpoznienia as days_overdue,
                    Bk.nzf_DataOstatniejSplaty as last_payment_date,
                    Bk.naleznosc as amount_total,
                    Bk.NalPierwotna as amount_original,
                    Bk.zobowiazanie as amount_liability,
                    k.kh_Symbol as ref_id,
                    k.adr_NazwaPelna as company_name,
                    k.adr_NIP as tax_id,
                    k.adr_Adres as address,
                    k.adr_Kod as post_code,
                    k.adr_Miejscowosc as city,
                    k.kh_EMail as email,
                    k.adr_Telefon as phone,
                    Flagi.flg_Text as flag_name,
                    FlagiWartosci.flw_Komentarz as flag_comment,
                    d.dok_Id
                FROM vwFinanseRozrachunkiWgDokumentow Bk
                LEFT JOIN dok__Dokument d ON Bk.nzf_IdDokumentAuto = d.dok_Id
                LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                LEFT JOIN fl_Wartosc FlagiWartosci ON Bk.nzf_Id = FlagiWartosci.flw_IdObiektu 
                    AND FlagiWartosci.flw_IdGrupyFlag = 1
                LEFT JOIN fl__Flagi Flagi ON FlagiWartosci.flw_IdFlagi = Flagi.flg_Id
                WHERE Bk.Rozliczenie IN (0, 1)
                AND Bk.nzf_Typ = 39
                AND Bk.naleznosc > 0
                AND k.adr_NIP IS NOT NULL 
                AND k.adr_NIP <> ''";

            $data = MSSql::getInstance()->query($sql);
            $result = [];

            foreach ($data as $row) {
                $positions_sql = "SELECT 
                    p.ob_Id,
                    p.ob_Ilosc as quantity,
                    p.ob_CenaNetto as price_net,
                    p.ob_CenaBrutto as price_brutto,
                    p.ob_WartNetto as gross_netto,
                    p.ob_WartBrutto as gross_brutto,
                    p.ob_VatProc as vat_rate,
                    t.tw_Symbol as code,
                    t.tw_Nazwa as name,
                    t.tw_Id as id,
                    t.tw_Zablokowany as blocked,
                    t.Rezerwacja as reservation,
                    t.Dostepne as available,
                    t.Stan as on_store,
                    t.Stan-t.Rezerwacja as on_store_available
                FROM dok_Pozycja p
                LEFT JOIN vwTowar t ON t.tw_Id = p.ob_TowId
                WHERE p.ob_DokHanId = {$row['dok_Id']}";
                
                $positions = MSSql::getInstance()->query($positions_sql);

                $result[] = [
                    'doc_ref' => $row['nzf_NumerPelny'],
                    'date_issue' => $row['date_issue'],
                    'payment_term' => $row['payment_term'],
                    'days_overdue' => $row['days_overdue'],
                    'last_payment_date' => $row['last_payment_date'],
                    'amount' => [
                        'total' => $row['amount_total'],
                        'original' => $row['amount_original'],
                        'liability' => $row['amount_liability']
                    ],
                    'customer' => [
                        'ref_id' => $row['ref_id'],
                        'company_name' => $row['company_name'],
                        'tax_id' => $row['tax_id'],
                        'address' => $row['address'],
                        'post_code' => $row['post_code'],
                        'city' => $row['city'],
                        'email' => $row['email'],
                        'phone' => $row['phone']
                    ],
                    'flag' => [
                        'name' => $row['flag_name'],
                        'comment' => $row['flag_comment']
                    ],
                    'positions' => $positions
                ];
            }

            Logger::getInstance()->log('api', 'Pobrano listę nieopłaconych należności', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'success', 'data' => $result];
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania nieopłaconych należności: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'fail', 'message' => $e->getMessage()];
        }
    }

    public function getLastOrders()
    {
        try {
            $sql = "SELECT TOP 100
                    d.dok_Id,
                    d.dok_NrPelny,
                    d.dok_WartBrutto,
                    d.dok_TerminRealizacji,
                    d.dok_DataWyst as date_issue,
                    d.dok_Status,
                    d.dok_StatusKsieg as accounting_state,
                    k.kh_Symbol as ref_id,
                    k.adr_NazwaPelna as company_name,
                    k.adr_NIP as tax_id,
                    k.adr_Adres as address,
                    k.adr_Kod as post_code,
                    k.adr_Miejscowosc as city,
                    k.kh_EMail as email,
                    k.adr_Telefon as phone
                FROM dok__Dokument d
                LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                WHERE d.dok_Typ = 16 /* Zamówienie od klienta */
                ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";

            $data = MSSql::getInstance()->query($sql);
            $result = [];

            foreach ($data as $row) {
                $positions = $this->getPositionsByOrderId($row['dok_Id']);
                $result[] = [
                    'doc_ref' => $row['dok_NrPelny'],
                    'amount' => $row['dok_WartBrutto'],
                    'date_issue' => $row['date_issue'],
                    'date_of_delivery' => $row['dok_TerminRealizacji'],
                    'status' => $row['dok_Status'],
                    'accounting_state' => $row['accounting_state'],
                    'fiscal_state' => $row['fiscal_state'],
                    'customer' => [
                        'ref_id' => $row['ref_id'],
                        'company_name' => $row['company_name'],
                        'tax_id' => $row['tax_id'],
                        'address' => $row['address'],
                        'post_code' => $row['post_code'],
                        'city' => $row['city'],
                        'email' => $row['email'],
                        'phone' => $row['phone']
                    ],
                    'positions' => $positions
                ];
            }

            Logger::getInstance()->log('api', 'Pobrano listę ostatnich 100 zamówień', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'success', 'data' => $result];
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania zamówień: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'fail', 'message' => $e->getMessage()];
        }
    }

    public function getLastDocumentsFromApi($json_request)
    {
        try {
            $data = json_decode($json_request, true);
            
            if (!isset($data['data'])) {
                throw new Exception("Brak sekcji 'data' w zapytaniu");
            }

            $requestData = $data['data'];
            
            if (!isset($requestData['doc_type'])) {
                throw new Exception("Nie podano typu dokumentu (doc_type)");
            }

            $doc_type = (int)$requestData['doc_type'];
            $limit = isset($requestData['limit']) ? (int)$requestData['limit'] : 100;

            return $this->getLastDocuments($doc_type, $limit);
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas przetwarzania zapytania API: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return array(
                'state' => 'fail',
                'message' => $e->getMessage()
            );
        }
    }

    public function getLastDocuments($doc_type, $limit = 2000)
    {
        try {
            if (!is_numeric($doc_type)) {
                throw new Exception("Typ dokumentu musi być liczbą");
            }
            
            if (!is_numeric($limit) || $limit <= 0) {
                throw new Exception("Limit dokumentów musi być liczbą większą od 0");
            }

            $sql = "SELECT TOP {$limit}
                    d.dok_Id,
                    d.dok_NrPelny,
                    d.dok_NrPelnyOryg,
                    d.dok_WartBrutto,
                    d.dok_WartNetto,
                    d.dok_WartVat,
                    d.dok_TerminRealizacji,
                    d.dok_DataWyst,
                    d.dok_Status,
                    d.dok_StatusKsieg,
                    d.dok_Uwagi,
                    d.dok_PrzetworzonoZKwZD,
                    k.kh_Symbol,
                    k.adr_NazwaPelna,
                    k.adr_NIP,
                    k.adr_Adres,
                    k.adr_Kod,
                    k.adr_Miejscowosc,
                    k.kh_EMail,
                    k.adr_Telefon
                FROM dok__Dokument d
                LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                WHERE d.dok_Typ = {$doc_type}
                AND d.dok_Status >= 0
                ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";

            $data = MSSql::getInstance()->query($sql);
            
            if (!is_array($data)) {
                throw new Exception("Błąd podczas pobierania danych z bazy");
            }
            
            $result = [];

            foreach ($data as $row) {
                $positions = $this->getPositionsByOrderId($row['dok_Id']);
                $document = [
                    'doc_ref' => $row['dok_NrPelny'],
                    'reference' => $row['dok_NrPelnyOryg'],
                    'amount' => $row['dok_WartBrutto'],
                    'amount_net' => $row['dok_WartNetto'],
                    'amount_vat' => $row['dok_WartVat'],
                    'date_issue' => $row['dok_DataWyst'],
                    'date_of_delivery' => $row['dok_TerminRealizacji'],
                    'status' => $row['dok_Status'],
                    'accounting_state' => $row['dok_StatusKsieg'],
                    'comments' => $row['dok_Uwagi'],
                    'order_processing' => $row['dok_PrzetworzonoZKwZD'],
                    'customer' => [
                        'ref_id' => $row['kh_Symbol'],
                        'company_name' => $row['adr_NazwaPelna'],
                        'tax_id' => $row['adr_NIP'],
                        'address' => $row['adr_Adres'],
                        'post_code' => $row['adr_Kod'],
                        'city' => $row['adr_Miejscowosc'],
                        'email' => $row['kh_EMail'],
                        'phone' => $row['adr_Telefon']
                    ],
                    'flag' => [
                        'id' => null,
                        'name' => null,
                        'group_id' => null,
                        'comment' => null
                    ],
                    'positions' => $positions
                ];
                
                // Dla dokumentów KFS (korekt) dodajemy numer dokumentu korygowanego
                if ($doc_type == 6) { // KFS
                    $document['corrected_document_number'] = $row['dok_NrPelnyOryg'] ? $row['dok_NrPelnyOryg'] : null;
                }
                
                // Dla faktur (FS) dodajemy informacje o powiązanym zamówieniu
                if ($doc_type == 2 && isset($row['dok_PrzetworzonoZKwZD']) && $row['dok_PrzetworzonoZKwZD'] == 1) {
                    $related_order = $this->getRelatedOrderForInvoice($row['dok_Id']);
                    if ($related_order) {
                        $document['related_order'] = $related_order;
                    }
                }
                
                // Dla faktur (FS) dodajemy informacje o powiązanym WZ
                if ($doc_type == 2) { // FS
                    $related_wz = $this->getRelatedWZForInvoice($row['dok_Id']);
                    if ($related_wz) {
                        $document['related_wz'] = $related_wz;
                    }
                }
                
                $result[] = $document;
            }

            $doc_type_name = isset($this->doc_types[$doc_type]) ? $this->doc_types[$doc_type] : 'dokumentów';
            Logger::getInstance()->log('api', "Pobrano listę ostatnich {$limit} {$doc_type_name}", __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'success', 'data' => $result];
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania dokumentów: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'fail', 'message' => $e->getMessage()];
        }
    }

    /**
     * Pobiera wszystkie typy dokumentów z ostatnich 7 dni
     * 
     * @param int $limit Limit dokumentów na typ
     * @return array Wynik z dokumentami pogrupowanymi według typu
     */
    public function getRecentDocuments($limit = 100)
    {
        try {
            Logger::getInstance()->log('api', 'Rozpoczęcie pobierania ostatnich dokumentów z 7 dni', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            $seven_days_ago = date('Y-m-d', strtotime('-7 days'));
            $today = date('Y-m-d');
            
            // Definicja typów dokumentów do pobrania
            $doc_types = [
                2 => 'FS',   // Faktura Sprzedaży
                6 => 'KFS',  // Korekta Faktury Sprzedaży
                11 => 'WZ',  // Wydanie Zewnętrzne
                16 => 'ZK'   // Zamówienie Klienta
            ];
            
            $result = [
                'state' => 'success',
                'data' => [],
                'summary' => [
                    'total_documents' => 0,
                    'date_range' => [
                        'from' => $seven_days_ago,
                        'to' => $today
                    ]
                ]
            ];
            
            foreach ($doc_types as $type_id => $type_name) {
                $sql = "SELECT TOP {$limit}
                        d.dok_Id,
                        d.dok_NrPelny,
                        d.dok_NrPelnyOryg,
                        d.dok_WartBrutto,
                        d.dok_WartNetto,
                        d.dok_WartVat,
                        d.dok_TerminRealizacji,
                        d.dok_DataWyst,
                        d.dok_Status,
                        d.dok_StatusKsieg,
                        d.dok_Uwagi,
                        d.dok_PrzetworzonoZKwZD,
                        k.kh_Symbol,
                        k.adr_NazwaPelna,
                        k.adr_NIP,
                        k.adr_Adres,
                        k.adr_Kod,
                        k.adr_Miejscowosc,
                        k.kh_EMail,
                        k.adr_Telefon
                    FROM dok__Dokument d
                    LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                    WHERE d.dok_Typ = {$type_id}
                    AND d.dok_Status >= 0
                    AND d.dok_DataWyst >= '{$seven_days_ago}'
                    AND d.dok_DataWyst <= '{$today}'
                    ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";

                $data = MSSql::getInstance()->query($sql);
                
                if (!is_array($data)) {
                    Logger::getInstance()->log('api', "Błąd podczas pobierania dokumentów typu {$type_name}", __CLASS__ . '->' . __FUNCTION__, __LINE__);
                    continue;
                }
                
                $documents = [];
                foreach ($data as $row) {
                    $positions = $this->getPositionsByOrderId($row['dok_Id']);
                    $document = [
                        'doc_ref' => $row['dok_NrPelny'],
                        'reference' => $row['dok_NrPelnyOryg'],
                        'amount' => $row['dok_WartBrutto'],
                        'amount_net' => $row['dok_WartNetto'],
                        'amount_vat' => $row['dok_WartVat'],
                        'date_issue' => $row['dok_DataWyst'],
                        'date_of_delivery' => $row['dok_TerminRealizacji'],
                        'status' => $row['dok_Status'],
                        'accounting_state' => $row['dok_StatusKsieg'],
                        'comments' => $row['dok_Uwagi'],
                        'order_processing' => $row['dok_PrzetworzonoZKwZD'],
                        'customer' => [
                            'ref_id' => $row['kh_Symbol'],
                            'company_name' => $row['adr_NazwaPelna'],
                            'tax_id' => $row['adr_NIP'],
                            'address' => $row['adr_Adres'],
                            'post_code' => $row['adr_Kod'],
                            'city' => $row['adr_Miejscowosc'],
                            'email' => $row['kh_EMail'],
                            'phone' => $row['adr_Telefon']
                        ],
                        'flag' => [
                            'id' => null,
                            'name' => null,
                            'group_id' => null,
                            'comment' => null
                        ],
                        'positions' => $positions
                    ];
                    
                    // Dla dokumentów KFS (korekt) dodajemy numer dokumentu korygowanego
                    if ($type_id == 6) { // KFS
                        $document['corrected_document_number'] = $row['dok_NrPelnyOryg'] ? $row['dok_NrPelnyOryg'] : null;
                    }
                    
                    // Dla faktur (FS) dodajemy informacje o powiązanym zamówieniu
                    if ($type_id == 2 && isset($row['dok_PrzetworzonoZKwZD']) && $row['dok_PrzetworzonoZKwZD'] == 1) {
                        $related_order = $this->getRelatedOrderForInvoice($row['dok_Id']);
                        if ($related_order) {
                            $document['related_order'] = $related_order;
                        }
                    }
                    
                    // Dla faktur (FS) dodajemy informacje o powiązanym WZ
                    if ($type_id == 2) { // FS
                        $related_wz = $this->getRelatedWZForInvoice($row['dok_Id']);
                        if ($related_wz) {
                            $document['related_wz'] = $related_wz;
                        }
                    }
                    
                    $documents[] = $document;
                }
                
                $result['data'][$type_name] = [
                    'type_name' => $type_name,
                    'type_id' => $type_id,
                    'count' => count($documents),
                    'documents' => $documents
                ];
                
                $result['summary']['total_documents'] += count($documents);
            }
            
            Logger::getInstance()->log('api', "Pobrano {$result['summary']['total_documents']} dokumentów z ostatnich 7 dni", __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return $result;
            
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania ostatnich dokumentów: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'fail', 'message' => $e->getMessage()];
        }
    }

    /**
     * Pobiera wszystkie dokumenty (WZ, FV, FR, ZK) dla firmy o podanym NIP
     * 
     * @param string $tax_id NIP firmy
     * @return array Wynik z dokumentami pogrupowanymi według typu
     */
    public function getDocumentsByTaxId($tax_id)
    {
        try {
            Logger::getInstance()->log('api', 'Rozpoczęcie pobierania dokumentów dla NIP: ' . $tax_id, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            if (empty($tax_id)) {
                throw new Exception("NIP firmy nie może być pusty");
            }

            // Najpierw szukamy klienta po NIPie
            $sql = "SELECT kh_Id as customer_id, kh_Symbol as customer_symbol, adr_NazwaPelna as company_name, adr_NIP as nip 
                    FROM vwKlienci 
                    WHERE adr_NIP = '{$tax_id}'";
            
            Logger::getInstance()->log('api', 'Wykonuję zapytanie SQL po klienta: ' . $sql, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            $customer_data = MSSql::getInstance()->query($sql);
            
            if (empty($customer_data)) {
                Logger::getInstance()->log('api', 'Nie znaleziono klienta o NIP: ' . $tax_id, __CLASS__ . '->' . __FUNCTION__, __LINE__);
                return [
                    'state' => 'success',
                    'data' => [
                        'customer' => null,
                        'documents' => [
                            'WZ' => [],
                            'FS' => [],
                            'KFS' => [],
                            'ZK' => []
                        ],
                        'total_count' => 0
                    ]
                ];
            }

            $customer_id = $customer_data[0]['customer_id'];
            $customer_symbol = $customer_data[0]['customer_symbol'];
            $company_name = $customer_data[0]['company_name'];
            
            Logger::getInstance()->log('api', 'Znaleziono klienta: ID=' . $customer_id . ', Symbol=' . $customer_symbol, __CLASS__ . '->' . __FUNCTION__, __LINE__);

            // Pobieramy wszystkie dokumenty dla znalezionego klienta (FS=2, KFS=6, WZ=11, ZK=16)
            $sql = "SELECT 
                        d.dok_Id,
                        d.dok_NrPelny,
                        d.dok_NrPelnyOryg,
                        d.dok_WartBrutto,
                        d.dok_WartNetto,
                        d.dok_WartVat,
                        d.dok_TerminRealizacji,
                        d.dok_DataWyst,
                        d.dok_Status,
                        d.dok_StatusKsieg,
                        d.dok_Uwagi,
                        d.dok_PrzetworzonoZKwZD,
                        d.dok_Typ,
                        k.kh_Symbol,
                        k.adr_NazwaPelna,
                        k.adr_NIP,
                        k.adr_Adres,
                        k.adr_Kod,
                        k.adr_Miejscowosc,
                        k.kh_EMail,
                        k.adr_Telefon
                    FROM dok__Dokument d
                    LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                    WHERE d.dok_PlatnikId = {$customer_id}
                    AND d.dok_Typ IN (2, 6, 11, 16)  -- FS, KFS, WZ, ZK
                    ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";
            
            Logger::getInstance()->log('api', 'Wykonuję zapytanie SQL po dokumenty: ' . $sql, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            $data = MSSql::getInstance()->query($sql);
            
            Logger::getInstance()->log('api', 'Znaleziono dokumentów: ' . count($data), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            // Debug: sprawdźmy wszystkie dokumenty dla tego klienta (bez filtrowania po typie)
            $debug_sql = "SELECT dok_Id, dok_NrPelny, dok_Typ, dok_Status, dok_DataWyst, dok_WartBrutto 
                         FROM dok__Dokument 
                         WHERE dok_PlatnikId = {$customer_id} 
                         ORDER BY dok_DataWyst DESC";
            $debug_data = MSSql::getInstance()->query($debug_sql);
            Logger::getInstance()->log('api', 'DEBUG - Wszystkie dokumenty dla klienta ID=' . $customer_id . ': ' . json_encode($debug_data), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            // Grupujemy dokumenty według typu
            $documents = [
                'WZ' => [],
                'FS' => [],  // Faktura Sprzedaży (typ 2)
                'KFS' => [], // Korekta Faktury Sprzedaży (typ 6)
                'ZK' => []
            ];
            
            $total_count = 0;

            foreach ($data as $row) {
                $positions = $this->getPositionsByOrderId($row['dok_Id']);
                
                $document = [
                    'doc_ref' => $row['dok_NrPelny'],
                    'reference' => $row['dok_NrPelnyOryg'],
                    'amount' => $row['dok_WartBrutto'],
                    'amount_net' => $row['dok_WartNetto'],
                    'amount_vat' => $row['dok_WartVat'],
                    'date_issue' => $row['dok_DataWyst'],
                    'date_of_delivery' => $row['dok_TerminRealizacji'],
                    'status' => $row['dok_Status'],
                    'accounting_state' => $row['dok_StatusKsieg'],
                    'comments' => $row['dok_Uwagi'],
                    'order_processing' => $row['dok_PrzetworzonoZKwZD'],
                    'doc_type' => $row['dok_Typ'],
                    'doc_type_name' => isset($this->doc_types[$row['dok_Typ']]) ? $this->doc_types[$row['dok_Typ']] : 'Nieznany',
                    'customer' => [
                        'ref_id' => $row['kh_Symbol'],
                        'company_name' => $row['adr_NazwaPelna'],
                        'tax_id' => $row['adr_NIP'],
                        'address' => $row['adr_Adres'],
                        'post_code' => $row['adr_Kod'],
                        'city' => $row['adr_Miejscowosc'],
                        'email' => $row['kh_EMail'],
                        'phone' => $row['adr_Telefon']
                    ],
                    'flag' => [
                        'id' => null,
                        'name' => null,
                        'group_id' => null,
                        'comment' => null
                    ],
                    'positions' => $positions
                ];
                
                // Dla dokumentów KFS (korekt) dodajemy numer dokumentu korygowanego
                if ($row['dok_Typ'] == 6) { // KFS
                    $document['corrected_document_number'] = $row['dok_NrPelnyOryg'] ? $row['dok_NrPelnyOryg'] : null;
                }
                
                // Dla faktur (FS) dodajemy informacje o powiązanym zamówieniu
                if ($row['dok_Typ'] == 2 && $row['dok_PrzetworzonoZKwZD'] == 1) { // FS przetworzona z zamówienia
                    $related_order = $this->getRelatedOrderForInvoice($row['dok_Id']);
                    if ($related_order) {
                        $document['related_order'] = $related_order;
                    }
                }
                
                // Dla faktur (FS) dodajemy informacje o powiązanym WZ
                if ($row['dok_Typ'] == 2) { // FS
                    $related_wz = $this->getRelatedWZForInvoice($row['dok_Id']);
                    if ($related_wz) {
                        $document['related_wz'] = $related_wz;
                    }
                }
                
                // Dodajemy dokument do odpowiedniej grupy
                switch ($row['dok_Typ']) {
                    case 11: // WZ
                        $documents['WZ'][] = $document;
                        break;
                    case 2:  // FS (Faktura Sprzedaży)
                        $documents['FS'][] = $document;
                        break;
                    case 6:  // KFS (Korekta Faktury Sprzedaży)
                        $documents['KFS'][] = $document;
                        break;
                    case 16: // ZK
                        $documents['ZK'][] = $document;
                        break;
                }
                
                $total_count++;
            }

            $result = [
                'customer' => [
                    'id' => $customer_id,
                    'symbol' => $customer_symbol,
                    'company_name' => $company_name,
                    'tax_id' => $tax_id
                ],
                'documents' => $documents,
                'total_count' => $total_count,
                'summary' => [
                    'WZ_count' => count($documents['WZ']),
                    'FS_count' => count($documents['FS']),
                    'KFS_count' => count($documents['KFS']),
                    'ZK_count' => count($documents['ZK'])
                ]
            ];

            Logger::getInstance()->log('api', 'Pobrano dokumenty dla firmy: ' . $company_name . ' (NIP: ' . $tax_id . ')', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            Logger::getInstance()->log('api', 'Podsumowanie: WZ=' . count($documents['WZ']) . ', FS=' . count($documents['FS']) . ', KFS=' . count($documents['KFS']) . ', ZK=' . count($documents['ZK']), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            return ['state' => 'success', 'data' => $result];
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania dokumentów po NIP: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'fail', 'message' => $e->getMessage()];
        }
    }

    /**
     * Pobiera faktury (FV) z określonego zakresu dat
     * 
     * @param string $date_from Data początkowa (format YYYY-MM-DD)
     * @param string $date_to Data końcowa (format YYYY-MM-DD)
     * @param int $limit Maksymalna liczba faktur do pobrania (domyślnie 1000)
     * @return array Wynik z fakturaami z zakresu dat
     */
    public function getInvoicesByDateRange($date_from, $date_to, $limit = 1000)
    {
        try {
            Logger::getInstance()->log('api', 'Rozpoczęcie pobierania faktur z zakresu dat: ' . $date_from . ' - ' . $date_to, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            if (empty($date_from) || empty($date_to)) {
                throw new Exception("Data początkowa i końcowa nie mogą być puste");
            }

            // Walidacja formatu dat
            $date_from_obj = DateTime::createFromFormat('Y-m-d', $date_from);
            $date_to_obj = DateTime::createFromFormat('Y-m-d', $date_to);
            
            if (!$date_from_obj || !$date_to_obj) {
                throw new Exception("Nieprawidłowy format dat. Użyj formatu YYYY-MM-DD");
            }

            if ($date_from_obj > $date_to_obj) {
                throw new Exception("Data początkowa nie może być późniejsza niż data końcowa");
            }

            if (!is_numeric($limit) || $limit <= 0) {
                throw new Exception("Limit musi być liczbą większą od 0");
            }

            // Pobieramy faktury (typ 2) z zakresu dat
            $sql = "SELECT TOP {$limit}
                        d.dok_Id,
                        d.dok_NrPelny,
                        d.dok_NrPelnyOryg,
                        d.dok_WartBrutto,
                        d.dok_WartNetto,
                        d.dok_WartVat,
                        d.dok_TerminRealizacji,
                        d.dok_DataWyst,
                        d.dok_Status,
                        d.dok_StatusKsieg,
                        d.dok_Uwagi,
                        d.dok_PrzetworzonoZKwZD,
                        d.dok_KwDoZaplaty,
                        d.dok_PlatTermin,
                        k.kh_Symbol,
                        k.adr_NazwaPelna,
                        k.adr_NIP,
                        k.adr_Adres,
                        k.adr_Kod,
                        k.adr_Miejscowosc,
                        k.kh_EMail,
                        k.adr_Telefon,
                        fw.flw_IdFlagi as flg_Id,
                        f.flg_Text,
                        fw.flw_IdGrupyFlag as flg_IdGrupy,
                        fw.flw_Komentarz
                    FROM dok__Dokument d
                    LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                    LEFT JOIN fl_Wartosc fw ON (fw.flw_IdObiektu = d.dok_Id)
                    LEFT JOIN fl__Flagi f ON (f.flg_Id = fw.flw_IdFlagi)
                    WHERE d.dok_Typ = 2  -- Faktura sprzedaży
                    AND d.dok_Status >= 0
                    AND d.dok_DataWyst >= '{$date_from}'
                    AND d.dok_DataWyst <= '{$date_to}'
                    ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";
            
            Logger::getInstance()->log('api', 'Wykonuję zapytanie SQL po faktury: ' . $sql, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            $data = MSSql::getInstance()->query($sql);
            
            Logger::getInstance()->log('api', 'Znaleziono faktur: ' . count($data), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            $invoices = [];
            $total_amount = 0;
            $total_amount_net = 0;
            $total_amount_vat = 0;
            $unpaid_count = 0;

            foreach ($data as $row) {
                $positions = $this->getPositionsByOrderId($row['dok_Id']);
                
                $amount_to_pay = $row['dok_KwDoZaplaty'] ?? 0;
                $is_unpaid = $amount_to_pay > 0;
                
                if ($is_unpaid) {
                    $unpaid_count++;
                }
                
                $total_amount += $row['dok_WartBrutto'];
                $total_amount_net += $row['dok_WartNetto'];
                $total_amount_vat += $row['dok_WartVat'];
                
                $invoice = [
                    'doc_ref' => $row['dok_NrPelny'],
                    'reference' => $row['dok_NrPelnyOryg'],
                    'amount' => $row['dok_WartBrutto'],
                    'amount_net' => $row['dok_WartNetto'],
                    'amount_vat' => $row['dok_WartVat'],
                    'amount_to_pay' => $amount_to_pay,
                    'is_unpaid' => $is_unpaid,
                    'date_issue' => $row['dok_DataWyst'],
                    'date_of_delivery' => $row['dok_TerminRealizacji'],
                    'payment_term' => $row['dok_PlatTermin'],
                    'status' => $row['dok_Status'],
                    'accounting_state' => $row['dok_StatusKsieg'],
                    'comments' => $row['dok_Uwagi'],
                    'order_processing' => $row['dok_PrzetworzonoZKwZD'],
                    'doc_type' => $row['dok_Typ'],
                    'doc_type_name' => 'FV',
                    'customer' => [
                        'ref_id' => $row['kh_Symbol'],
                        'company_name' => $row['adr_NazwaPelna'],
                        'tax_id' => $row['adr_NIP'],
                        'address' => $row['adr_Adres'],
                        'post_code' => $row['adr_Kod'],
                        'city' => $row['adr_Miejscowosc'],
                        'email' => $row['kh_EMail'],
                        'phone' => $row['adr_Telefon']
                    ],
                    'flag' => [
                        'id' => null,
                        'name' => null,
                        'group_id' => null,
                        'comment' => null
                    ],
                    'positions' => $positions
                ];
                
                // Dla faktur przetworzonych z zamówienia dodajemy informacje o powiązanym zamówieniu
                if ($row['dok_PrzetworzonoZKwZD'] == 1) {
                    $related_order = $this->getRelatedOrderForInvoice($row['dok_Id']);
                    if ($related_order) {
                        $invoice['related_order'] = $related_order;
                    }
                }
                
                // Dla faktur dodajemy informacje o powiązanym WZ
                $related_wz = $this->getRelatedWZForInvoice($row['dok_Id']);
                if ($related_wz) {
                    $invoice['related_wz'] = $related_wz;
                }
                
                $invoices[] = $invoice;
            }

            $result = [
                'date_range' => [
                    'from' => $date_from,
                    'to' => $date_to
                ],
                'invoices' => $invoices,
                'summary' => [
                    'total_count' => count($invoices),
                    'unpaid_count' => $unpaid_count,
                    'paid_count' => count($invoices) - $unpaid_count,
                    'total_amount' => $total_amount,
                    'total_amount_net' => $total_amount_net,
                    'total_amount_vat' => $total_amount_vat
                ]
            ];

            Logger::getInstance()->log('api', 'Pobrano faktury z zakresu: ' . $date_from . ' - ' . $date_to . ' (łącznie: ' . count($invoices) . ')', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            Logger::getInstance()->log('api', 'Podsumowanie: nieopłacone=' . $unpaid_count . ', opłacone=' . (count($invoices) - $unpaid_count) . ', suma=' . $total_amount, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            return ['state' => 'success', 'data' => $result];
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania faktur z zakresu dat: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return ['state' => 'fail', 'message' => $e->getMessage()];
        }
    }
}

?>