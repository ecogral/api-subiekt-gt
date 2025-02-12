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
    protected $fiscal_state = false;
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
                'fiscal_state' => $this->fiscal_state,
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
            'fiscal_state' => $this->fiscal_state,
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
        $this->fiscal_state = $this->documentGt->StatusFiskalny;
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
                'price' => $p['ob_WartBrutto']);
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
        $sql = "SELECT * FROM dok_Pozycja
			   WHERE ob_DokHanId = {$id}";
        $data = MSSql::getInstance()->query($sql);
        return $data;
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
}

?>