<?php

namespace APISubiektGT\SubiektGT;

use COM;
use Exception;
use APISubiektGT\Logger;
use APISubiektGT\MSSql;
use APISubiektGT\Helper;
use APISubiektGT\SubiektGT\SubiektObj;
use APISubiektGT\SubiektGT\Product;
use APISubiektGT\SubiektGT\Customer;
use APISubiektGT\SubiektGT\OrderComWriter;

class Order extends SubiektObj
{
    /** ob_TowRodzaj: usługa (np. transport U001 — dopisywana na WZ przez API) */
    const TOW_RODZAJ_USLUGA = 2;

    /** Symbol usługi transportu kopiowanej z ZK na WZ */
    const TRANSPORT_SERVICE_CODE = 'U001';

    protected $orderGt;
    protected $products = false;
    protected $reference;
    protected $comments;
    protected $customer = false;
    protected $reservation = true;
    protected $order_ref = '';
    protected $selling_doc = '';
    protected $amount = 0;
    protected $paid_amount = 0;
    protected $state = -1;
    protected $date_of_delivery = '';
    protected $payment_comments = '';
    protected $pay_type = 'transfer';
    protected $create_product_if_not_exists = false;
    protected $orderDetail = array();
    protected $order_processing = false;
    protected $id_flag = 0;
    protected $flag_txt = '';
    protected $pay_point_id = 0;
    protected $projected_value = 0;
    protected $projected_profit = 0;
    protected $status_ex = 0;
    protected $fully_realized = false;
    protected $issue_documents = array();


    public function __construct($subiektGt, $orderDetail = array())
    {
        parent::__construct($subiektGt, $orderDetail);
        $this->excludeAttr(array('orderGt', 'orderDetail', 'pay_type', 'create_product_if_not_exists'));

        if ($this->order_ref != '') {
            $exists = $subiektGt->SuDokumentyManager->Istnieje($this->order_ref);
            Logger::getInstance()->log('api', 'Order konstruktor: order_ref=' . $this->order_ref . ', Istnieje=' . ($exists ? 'tak' : 'nie'), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            if ($exists) {
                $this->orderGt = $subiektGt->SuDokumentyManager->Wczytaj($this->order_ref);
                $this->getGtObject();
                $this->is_exists = true;
                Logger::getInstance()->log('api', 'Order wczytany z Subiekta, gt_id=' . $this->gt_id . ', pozycji=' . $this->orderGt->Pozycje->Liczba(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            }
        } else {
            Logger::getInstance()->log('api', 'Order konstruktor: brak order_ref w danych', __CLASS__ . '->' . __FUNCTION__, __LINE__);
        }
        $this->orderDetail = $orderDetail;
    }

    protected function addPosition($product)
    {
        $position = false;
        $p = new Product($this->subiektGt, $product);

        if (!$p->isExists()) {
            return false;
        }
        $p_data = $p->get();
        if (isset($product['supplier_code']) && strlen($product['supplier_code']) > 0) {
            $p->setProductSupplierCode($product['supplier_code']);
        }
        $code = sprintf('%s', $p_data['code']);

        Logger::getInstance()->log('api', 'addPosition: przed Pozycje->Dodaj code=' . $code, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        try {
            $position = $this->orderGt->Pozycje->Dodaj($code);
            Logger::getInstance()->log('api', 'addPosition: po Dodaj code=' . $code . ', Liczba()=' . $this->orderGt->Pozycje->Liczba(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
        } catch (\Exception $e) {
            Logger::getInstance()->log('api', 'addPosition: BŁĄD Dodaj(' . $code . '): ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            throw $e;
        }

        $position->IloscJm = intval($product['qty']);
        $position->WartoscBruttoPoRabacie = floatval($product['price']) * intval($product['qty']);
        if (isset($product['price_before_discount']) && floatval($product['price_before_discount']) > 0) {
            $position->WartoscBruttoPrzedRabatem = floatval($product['price_before_discount']) * intval($product['qty']);
        }
        Logger::getInstance()->log('api', 'addPosition: pozycja ustawiona code=' . $code, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        return $position;
    }

    /**
     * Aktualizuje istniejącą pozycję (ilość, wartości) – bez usuwania.
     */
    protected function updatePositionInPlace($position, $product)
    {
        $qty = intval($product['qty']);
        $position->IloscJm = $qty;
        $position->WartoscBruttoPoRabacie = floatval($product['price']) * $qty;
        if (isset($product['price_before_discount']) && floatval($product['price_before_discount']) > 0) {
            $position->WartoscBruttoPrzedRabatem = floatval($product['price_before_discount']) * $qty;
        }
    }

    /**
     * Synchronizuje pozycje zamówienia z listą products: aktualizuje istniejące po code, dodaje nowe, usuwa zbędne.
     * Unika masowego Usun() – w typowym przypadku (dodanie drugiej pozycji) nie wywołuje Usun w ogóle.
     */
    protected function syncPositionsWithProducts($products)
    {
        $requestedByCode = [];
        foreach ($products as $p) {
            $code = isset($p['code']) ? trim((string)$p['code']) : '';
            if ($code === '') {
                continue;
            }
            $pObj = new Product($this->subiektGt, $p);
            if (!$pObj->isExists()) {
                throw new Exception('Nie odnaleziono towaru o podanym kodzie: ' . $p['code']);
            }
            $pData = $pObj->get();
            $normalizedCode = (string)$pData['code'];
            $requestedByCode[$normalizedCode] = $p;
        }

        $numPos = $this->orderGt->Pozycje->Liczba();
        Logger::getInstance()->log('api', 'syncPositions: pozycji w dokumencie=' . $numPos . ', żądanych (unikalnych kodów)=' . count($requestedByCode), __CLASS__ . '->' . __FUNCTION__, __LINE__);

        $indicesToRemove = [];
        for ($i = 1; $i <= $numPos; $i++) {
            $pos = $this->orderGt->Pozycje->Element($i);
            $symbol = isset($pos->TowarSymbol) ? trim((string)$pos->TowarSymbol) : '';
            if ($symbol === '') {
                continue;
            }
            if (isset($requestedByCode[$symbol])) {
                Logger::getInstance()->log('api', 'syncPositions: aktualizuję pozycję ' . $i . ' code=' . $symbol, __CLASS__ . '->' . __FUNCTION__, __LINE__);
                $this->updatePositionInPlace($pos, $requestedByCode[$symbol]);
                unset($requestedByCode[$symbol]);
            } else {
                if ($this->shouldPreserveSubiektOnlyPositions()) {
                    Logger::getInstance()->log(
                        'api',
                        'syncPositions: pomijam usunięcie pozycji ' . $i . ' code=' . $symbol
                            . ' (ZK z rezerwacją — zachowaj pozycje z Subiekta)',
                        __CLASS__ . '->' . __FUNCTION__,
                        __LINE__
                    );
                    continue;
                }
                $indicesToRemove[] = $i;
            }
        }

        if (count($indicesToRemove) > 0) {
            Logger::getInstance()->log('api', 'syncPositions: do usunięcia indeksy (od końca)=' . implode(',', $indicesToRemove), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            for ($k = count($indicesToRemove) - 1; $k >= 0; $k--) {
                $idx = $indicesToRemove[$k];
                try {
                    Logger::getInstance()->log('api', 'syncPositions: wywołuję Usun dla indeksu ' . $idx, __CLASS__ . '->' . __FUNCTION__, __LINE__);
                    if (method_exists($this->orderGt->Pozycje, 'Usun')) {
                        $this->orderGt->Pozycje->Usun($idx);
                    } else {
                        $this->orderGt->Pozycje->Element($idx)->Usun();
                    }
                    Logger::getInstance()->log('api', 'syncPositions: Usun(' . $idx . ') OK, Liczba()=' . $this->orderGt->Pozycje->Liczba(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
                    $this->orderGt->Przelicz();
                    $this->orderGt->Zapisz();
                    Logger::getInstance()->log('api', 'syncPositions: po Usun Przelicz+Zapisz OK', __CLASS__ . '->' . __FUNCTION__, __LINE__);
                } catch (\Exception $e) {
                    Logger::getInstance()->log('api', 'syncPositions: Usun(' . $idx . ') BŁĄD: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
                    throw $e;
                }
            }
        }

        foreach ($requestedByCode as $code => $p) {
            Logger::getInstance()->log('api', 'syncPositions: dodaję nową pozycję code=' . $code, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            $add_position = $this->addPosition($p);
            if (!$add_position) {
                throw new Exception('Nie odnaleziono towaru o podanym kodzie: ' . $code);
            }
        }
        Logger::getInstance()->log('api', 'syncPositions: koniec, Liczba()=' . $this->orderGt->Pozycje->Liczba(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
    }

    /**
     * Usuwa wszystkie pozycje z zamówienia (używane tylko gdy products=[]).
     */
    protected function clearAllPositions()
    {
        $initial = $this->orderGt->Pozycje->Liczba();
        Logger::getInstance()->log('api', 'clearAllPositions: start, liczba pozycji=' . $initial, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        $count = 0;
        while ($this->orderGt->Pozycje->Liczba() > 0) {
            try {
                Logger::getInstance()->log('api', 'clearAllPositions: przed Usun(1)', __CLASS__ . '->' . __FUNCTION__, __LINE__);
                if (method_exists($this->orderGt->Pozycje, 'Usun')) {
                    $this->orderGt->Pozycje->Usun(1);
                } else {
                    $this->orderGt->Pozycje->Element(1)->Usun();
                }
                $count++;
                $this->orderGt->Przelicz();
                $this->orderGt->Zapisz();
                Logger::getInstance()->log('api', 'clearAllPositions: po Usun+Przelicz+Zapisz, usunięto=' . $count, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            } catch (\Exception $e) {
                Logger::getInstance()->log('api', 'clearAllPositions błąd: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
                throw $e;
            }
        }
        Logger::getInstance()->log('api', 'clearAllPositions: koniec, usunięto=' . $count, __CLASS__ . '->' . __FUNCTION__, __LINE__);
    }

    protected function setGtObject()
    {
        $this->orderGt->Tytul = $this->reference;
        $this->orderGt->Uwagi = $this->comments;
        $this->orderGt->Rezerwacja = $this->reservation;
        $this->orderGt->NumerOryginalny = $this->reference;
        // switch ($this->pay_type) {
        //     case 'transfer' :
        //         $this->orderGt->PlatnoscGotowkaKwota = 0;
        //         // $this->orderGt->PlatnoscPrzelewKwota = floatval($this->amount);
        //         $this->orderGt->PlatnoscPrzelewKwota = 0;
        //         $this->orderGt->PlatnoscKredytKwota = floatval($this->amount);
        //         break;
        //     case 'cart' :
        //         $this->orderGt->PlatnoscKartaKwota = floatval($this->amount);
        //         $this->orderGt->PlatnoscKartaId = intval($this->pay_point_id);
        //         break;
        //     case 'money' :
        //         $this->orderGt->PlatnoscGotowkaKwota = floatval($this->amount);
        //         break;
        //     case 'credit' :
        //         $this->orderGt->PlatnoscKredytKwota = floatval($this->amount);
        //         break;
        //     case 'loan' :
        //         $this->orderGt->PlatnoscRatyKwota = floatval($this->amount);
        //         break;
        //     default:
        //         $this->orderGt->PlatnoscPrzelewKwota = floatval($this->amount);
        //         break;
        // }
        $this->orderGt->PlatnoscKredytKwota = floatval($this->amount);
        $this->orderGt->LiczonyOdCenBrutto = false;

    }

    public function getPdf()
    {
        $temp_dir = sys_get_temp_dir();
        if ($this->is_exists) {
            $file_name = $temp_dir . '/' . $this->gt_id . '.pdf';
            $this->orderGt->DrukujDoPliku($file_name, 0);
            $pdf_file = file_get_contents($file_name);
            Logger::getInstance()->log('api', 'Wygenerowano pdf dokumentu: ' . $this->order_ref, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return array('encoding' => 'base64', 'order_ref' => $this->order_ref, 'pdf_file' => base64_encode($pdf_file));
        }
        return false;
    }

    public function makeSaleDoc()
    {
        if (!$this->is_exists) {
            return array(
                'order_ref' => $this->order_ref,
                'doc_state' => 'warning',
                'doc_state_code' => 1,
                'message' => 'Nie odnaleziono dokumentu',
                'doc_ref' => false
            );
        }

        if ($this->customer['is_company'] == true) {
            $selling_doc = $this->subiektGt->SuDokumentyManager->DodajFS();
        } else {
            $selling_doc = $this->subiektGt->SuDokumentyManager->DodajPAi();
        }
        if ($this->orderGt->WartoscBrutto == 0) {
            throw new Exception('Nie można utworzyć dokumentu sprzedaży. 0 wartość dokumentu.');
        }

        try {
            $selling_doc->NaPodstawie(intval($this->gt_id));
        } catch (Exception $e) {
            throw new Exception('Nie można utworzyć dokumentu sprzedaży. Dokument: ' . $this->order_ref . '. ' . $this->toUtf8($e->getMessage()));
        }
        try {
            $selling_doc->ZapiszSymulacja();
        } catch (Exception $e) {
            if ($selling_doc->PozycjeBrakujace->Liczba() > 0) {
                return array(
                    'doc_ref' => $selling_doc->NumerPelny,
                    'doc_state' => 'warning',
                    'doc_state_code' => 2,
                    'message' => 'Nie można utworzyć dokumentu sprzedaży. Brakuje produktów na magazynie.',
                );
            } else {
                throw new Exception('Nie można utworzyć dokumentu sprzedaży. Dokument: ' . $this->order_ref . '. ' . $this->toUtf8($e->getMessage()));
            }
        }
        if ($this->customer['is_company'] == false) {
            $selling_doc->RejestrujNaUF = true;
        }
        $selling_doc->Podtytul = trim($this->orderGt->Tytul);//.'/'.$this->orderGt->order_ref;
        $selling_doc->Wystawil = Helper::toWin($this->cfg->getIdPerson());
        $selling_doc->LiczonyOdCenBrutto = true;
        $selling_doc->Zapisz();
        $fsRef = trim((string) $selling_doc->NumerPelny);
        Logger::getInstance()->log('api', 'Utworzono dokument sprzedaży: ' . $fsRef, __CLASS__ . '->' . __FUNCTION__, __LINE__);

        $fsCleanup = self::cleanupSalesInvoiceLinksAfterFsSql($fsRef, __CLASS__ . '->' . __FUNCTION__);
        $response = array(
            'doc_ref' => $fsRef,
            'doc_amount' => $this->getOrderAmountById($selling_doc->Identyfikator),
            'doc_state' => 'ok',
            'doc_state_code' => 0,
            'order_ref' => $this->order_ref,

        );

        if (isset($this->pdf_request)) {
            $response['doc_pdf'] = $this->getPdfInBase64($selling_doc);
        }
        if (($fsCleanup['state'] ?? '') === 'success') {
            $response['fs_links_cleaned'] = true;
        } elseif (($fsCleanup['state'] ?? '') === 'noop') {
            $response['fs_links_cleaned'] = false;
        } else {
            $response['fs_links_cleanup'] = $fsCleanup;
        }

        return $response;
    }

    protected function getGtObject()
    {
        if (!$this->orderGt) {
            return false;
        }
        $this->gt_id = $this->orderGt->Identyfikator;
        $o = $this->getOrderById($this->gt_id);

        $this->reference = $o['dok_NrPelnyOryg'] ?? '';
        $this->doc_type = $this->doc_types[$this->orderGt->Typ];
        $this->selling_doc = $o['pow_NrPelny'] ?? '';
        $this->comments = $o['dok_Uwagi'] ?? '';
        $this->order_ref = $o['dok_NrPelny'] ?? '';
        $this->reservation = (bool) ($this->orderGt->Rezerwacja ?? false);
        $this->state = $o['dok_Status'] ?? 0;
        $this->status_ex = (int) ($o['dok_StatusEx'] ?? 0);
        $this->fully_realized = $this->isZkFulfilledForApi();
        if ($this->fully_realized) {
            $this->reservation = false;
        }
        $this->issue_documents = self::getIssueRefsForOrder($this->order_ref, (int) $this->gt_id);
        $this->amount = $o['dok_WartBrutto'] ?? 0;
        $this->projected_value = $o['dok_WartMag'] ?? 0;
        $this->projected_profit = $o['dok_PrognozowanyZysk'] ?? (($o['dok_WartTwNetto'] ?? 0) - ($o['dok_WartMag'] ?? 0));
        $this->date_of_delivery = $o['dok_TerminRealizacji'] ?? null;
        $this->order_processing = $o['ss_PrzetworzonoZKwZD'] ?? $o['dok_PrzetworzonoZKwZD'] ?? 0;
        $this->id_flag = $o['flg_Id'] ?? null;
        $this->flag_txt = $o['flg_Text'] ?? '';

        $customer = Customer::getCustomerById($this->orderGt->KontrahentId);
        $this->customer = $customer;

        $this->products = array();
        $positions = array();
        for ($i = 1; $i <= $this->orderGt->Pozycje->Liczba(); $i++) {
            $positions[$this->orderGt->Pozycje->Element($i)->Id]['name'] = $this->orderGt->Pozycje->Element($i)->TowarNazwa;
            $positions[$this->orderGt->Pozycje->Element($i)->Id]['code'] = $this->orderGt->Pozycje->Element($i)->TowarSymbol;
        }


        $comPositionsById = array();
        for ($i = 1; $i <= $this->orderGt->Pozycje->Liczba(); $i++) {
            $comPos = $this->orderGt->Pozycje->Element($i);
            $comPositionsById[(int) $comPos->Id] = $comPos;
        }

        $products = $this->getPositionsByOrderId($this->gt_id);
        foreach ($products as $p) {
            $p_a = array('name' => $positions[$p['ob_Id']]['name'],
                'code' => $positions[$p['ob_Id']]['code'],
                'qty' => $p['ob_Ilosc'],
                'price' => $p['ob_CenaNetto'],
                'price_net' => $p['ob_CenaNetto'],
                'price_gross' => $p['ob_CenaBrutto'],
                'total_net' => $p['ob_WartNetto'],
                'total_gross' => $p['ob_WartBrutto']);
            $comPos = isset($comPositionsById[(int) $p['ob_Id']]) ? $comPositionsById[(int) $p['ob_Id']] : null;
            $p_a = self::appendRealizationFieldsToProduct($p_a, $comPos, (float) $p['ob_Ilosc']);
            $this->products[] = $p_a;
        }

    }

    protected function getOrderById($id)
    {
        $sql = "SELECT d.dok_Id, d.dok_NrPelnyOryg, d.dok_Uwagi, d.dok_NrPelny, d.dok_Status, d.dok_StatusEx,
                       d.dok_WartBrutto, d.dok_WartNetto, d.dok_WartTwNetto, d.dok_WartMag,
                       (d.dok_WartTwNetto - d.dok_WartMag) AS dok_PrognozowanyZysk,
                       d.dok_TerminRealizacji, d.dok_PrzetworzonoZKwZD,
                       fw.flw_IdFlagi as flg_Id, f.flg_Text
                FROM dok__Dokument d
                LEFT JOIN fl_Wartosc as fw ON (fw.flw_IdObiektu = d.dok_Id)
                LEFT JOIN fl__Flagi as f ON (f.flg_Id = fw.flw_IdFlagi)
                WHERE d.dok_Id = {$id}";
        $data = MSSql::getInstance()->query($sql);
        if (empty($data)) {
            return [];
        }
        $row = $data[0];
        $row['ss_PrzetworzonoZKwZD'] = $row['dok_PrzetworzonoZKwZD'] ?? 0;
        $row['statusrez'] = (bool) ($this->orderGt->Rezerwacja ?? false);

        return $row;
    }


    protected function getOrderAmountById($id)
    {
        $sql = "SELECT dok_WartBrutto FROM dok__Dokument WHERE dok_Id = {$id}";
        $data = MSSql::getInstance()->query($sql);
        if (!is_array($data) || empty($data)) {
            return false;
        }
        return $data[0]['dok_WartBrutto'];
    }

    protected function getPositionsByOrderId($id)
    {
        $sql = "SELECT ob_Id, ob_Ilosc, ob_CenaNetto, ob_CenaBrutto, ob_WartNetto, ob_WartBrutto FROM dok_Pozycja
			   WHERE ob_DokHanId = {$id}";
        $data = MSSql::getInstance()->query($sql);
        return $data;
    }


    public function getState()
    {
        return array('order_ref' => $this->order_ref,
            'is_exists' => $this->is_exists,
            'state' => $this->state,
            'status_ex' => $this->status_ex,
            'reservation' => (bool) $this->reservation,
            'fully_realized' => $this->fully_realized,
            'is_realized' => $this->fully_realized,
            'is_fulfilled' => $this->fully_realized,
            'order_processing' => $this->order_processing,
            'id_flag' => $this->id_flag,
            'flag_txt' => $this->flag_txt,
            'amount' => $this->amount,
            'projected_value' => $this->projected_value,
            'projected_profit' => $this->projected_profit,
            'sell_doc' => $this->selling_doc,
            'issue_documents' => $this->issue_documents,
            'wz_refs' => $this->issue_documents,
        );
    }

    /**
     * Warianty numeru ZK (ZK 123/… vs ZK123/…).
     *
     * @param string $orderRef
     * @return array
     */
    public static function orderRefVariants($orderRef)
    {
        $ref = trim((string) $orderRef);
        $variants = array();
        if ($ref !== '') {
            $variants[] = $ref;
        }
        if (preg_match('/^ZK\s*(.+)$/iu', $ref, $matches)) {
            $core = trim((string) $matches[1]);
            if ($core !== '') {
                $variants[] = 'ZK ' . $core;
                $variants[] = 'ZK' . $core;
            }
        }
        return array_values(array_unique($variants));
    }

    /**
     * Fragment SET do czyszczenia nagłówka powiązania dokumentu (varchar NOT NULL → '').
     *
     * @return string
     */
    private static function sqlSetClearedDocumentLinkFields()
    {
        return 'dok_DoDokId = NULL,
                dok_DoDokNrPelny = \'\',
                dok_DoDokDataWyst = NULL';
    }

    /**
     * Czy żądanie wymaga pełnych ilości na WZ (applyRemainingQuantitiesToIssueDoc).
     * Domyślnie false — nie mylić z domknięciem ZK (close_order).
     *
     * @param array $detail
     * @param bool $default
     * @return bool
     */
    public static function isFullRealizationRequested(array $detail, $default = false)
    {
        foreach (array('realize_as_wz', 'full_realization', 'realize_all') as $flag) {
            if (!array_key_exists($flag, $detail)) {
                continue;
            }
            $value = filter_var($detail[$flag], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === true) {
                return true;
            }
            if ($value === false) {
                return false;
            }
        }

        if (array_key_exists('partial', $detail)) {
            $partial = filter_var($detail['partial'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($partial === false) {
                return true;
            }
            if ($partial === true) {
                return false;
            }
        }

        return (bool) $default;
    }

    /**
     * Czy po WZ domknąć ZK (status 7/8) i powiązać numer WZ wyłącznie na nagłówku ZK.
     *
     * Domyślnie true — po WZ domknij ZK (7/8) i powiąż numer WZ na nagłówku ZK.
     * Wyłącz: close_order=false lub leave_order_open=true.
     *
     * @param array $detail
     * @param bool $default
     * @return bool
     */
    public static function isCloseOrderRequested(array $detail, $default = true)
    {
        if (array_key_exists('leave_order_open', $detail)) {
            $leaveOpen = filter_var($detail['leave_order_open'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($leaveOpen === true) {
                return false;
            }
            if ($leaveOpen === false) {
                return true;
            }
        }

        foreach (array('close_order', 'close_zk', 'zk_closed', 'fulfill_order', 'mark_fulfilled') as $flag) {
            if (!array_key_exists($flag, $detail)) {
                continue;
            }
            $value = filter_var($detail[$flag], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === true) {
                return true;
            }
            if ($value === false) {
                return false;
            }
        }

        return (bool) $default;
    }

    /**
     * Czy do WZ dołączyć usługi z ZK (np. transport U001).
     *
     * Domyślnie true przy tworzeniu WZ z API (transport U001 z ZK → pełne domknięcie).
     *
     * @param array $detail
     * @param bool $default
     * @return bool
     */
    public static function isCopyServicesFromOrderRequested(array $detail, $default = true)
    {
        foreach (array('include_order_services', 'copy_services_from_order') as $flag) {
            if (!array_key_exists($flag, $detail)) {
                continue;
            }
            $value = filter_var($detail[$flag], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === true) {
                return true;
            }
            if ($value === false) {
                return false;
            }
        }

        if (!empty($detail['services']) && is_array($detail['services'])) {
            return true;
        }

        return (bool) $default;
    }

    /**
     * Czy żądanie wymaga dopisania usług na istniejącym WZ (document/update).
     *
     * @param array $detail
     * @return bool
     */
    public static function isAppendServicesRequested(array $detail)
    {
        if (!array_key_exists('append_services', $detail)) {
            return false;
        }
        $value = $detail['append_services'];
        if (is_array($value)) {
            return !empty($value);
        }
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $bool === true;
    }

    /**
     * @param array $line
     * @return array|null
     */
    public static function normalizeServiceLine(array $line)
    {
        $code = '';
        foreach (array('code', 'symbol', 'sku', 'product_code') as $key) {
            if (!empty($line[$key])) {
                $code = trim((string) $line[$key]);
                break;
            }
        }
        if ($code === '') {
            return null;
        }

        $qty = 1.0;
        foreach (array('qty', 'quantity', 'ilosc') as $key) {
            if (isset($line[$key]) && (float) $line[$key] > 0.00001) {
                $qty = (float) $line[$key];
                break;
            }
        }

        $normalized = array(
            'code' => $code,
            'qty' => $qty,
            'zk_position_id' => isset($line['zk_position_id']) ? (int) $line['zk_position_id'] : 0,
        );

        foreach (array(
            'price_net' => array('price_net', 'net_price', 'cena_netto'),
            'price_gross' => array('price_gross', 'gross_price', 'cena_brutto'),
            'price' => array('price', 'unit_price'),
            'price_before_discount' => array('price_before_discount', 'price_gross_before_discount'),
        ) as $target => $keys) {
            foreach ($keys as $key) {
                if (isset($line[$key]) && (float) $line[$key] > 0.00001) {
                    $normalized[$target] = (float) $line[$key];
                    break;
                }
            }
        }

        return $normalized;
    }

    /**
     * Pozycje usług z ZK (SQL).
     *
     * @param int $orderId
     * @param array<int, string>|null $codes opcjonalny filtr symboli (np. U001)
     * @return array<int, array>
     */
    public static function getOrderServiceLinesFromSql($orderId, array $codes = null)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return array();
        }

        $codeFilter = '';
        if (is_array($codes) && !empty($codes)) {
            $safeCodes = array();
            foreach ($codes as $code) {
                $code = trim((string) $code);
                if ($code !== '') {
                    $safeCodes[] = "'" . str_replace("'", "''", $code) . "'";
                }
            }
            if (!empty($safeCodes)) {
                $codeFilter = ' AND t.tw_Symbol IN (' . implode(', ', $safeCodes) . ')';
            }
        }

        $rows = MSSql::getInstance()->query(
            "SELECT zk.ob_Id AS zk_position_id,
                    t.tw_Symbol AS code,
                    zk.ob_Ilosc AS qty,
                    zk.ob_CenaNetto AS price_net,
                    zk.ob_CenaBrutto AS price_gross
             FROM dok_Pozycja zk
             INNER JOIN tw__Towar t ON t.tw_Id = zk.ob_TowId
             WHERE zk.ob_DokHanId = {$orderId}
               AND ISNULL(zk.ob_TowRodzaj, ISNULL(t.tw_Rodzaj, 1)) = " . self::TOW_RODZAJ_USLUGA
            . $codeFilter
        );

        $lines = array();
        if (!is_array($rows)) {
            return $lines;
        }

        foreach ($rows as $row) {
            $line = self::normalizeServiceLine(array(
                'code' => $row['code'] ?? '',
                'qty' => $row['qty'] ?? 1,
                'price_net' => $row['price_net'] ?? null,
                'price_gross' => $row['price_gross'] ?? null,
                'zk_position_id' => $row['zk_position_id'] ?? 0,
            ));
            if ($line !== null) {
                $lines[$line['code']] = $line;
            }
        }

        return array_values($lines);
    }

    /**
     * Czy symbol towaru to usługa (nie wymaga stanu magazynowego na WZ).
     *
     * @param string $symbol
     * @return bool
     */
    public static function isServiceProductSymbolSql($symbol)
    {
        $symbol = trim((string) $symbol);
        if ($symbol === '') {
            return false;
        }

        $safeSymbol = str_replace("'", "''", $symbol);
        $rows = MSSql::getInstance()->query(
            "SELECT ISNULL(tw_Rodzaj, 1) AS rodzaj
             FROM tw__Towar WHERE tw_Symbol = '{$safeSymbol}'"
        );

        return is_array($rows)
            && !empty($rows)
            && (int) ($rows[0]['rodzaj'] ?? 1) === self::TOW_RODZAJ_USLUGA;
    }

    /**
     * Braki magazynowe ZK wg SQL (towary + komplety, bez usług).
     * Dla ZK z rezerwacją (status 5/7) wystarczy stan fizyczny (st_Stan).
     *
     * @param int $orderId
     * @param int|null $warehouseId
     * @param int|null $orderState
     * @return array<int, array>
     */
    public static function getOrderWarehouseStockShortagesSql($orderId, $warehouseId = null, $orderState = null)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return array();
        }

        if ($orderState === null) {
            $stateRows = MSSql::getInstance()->query(
                "SELECT dok_Status FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
            );
            $orderState = is_array($stateRows) && !empty($stateRows)
                ? (int) ($stateRows[0]['dok_Status'] ?? 0)
                : 0;
        }
        $orderState = (int) $orderState;
        $withReservation = in_array($orderState, array(5, 7), true);

        $warehouseId = (int) $warehouseId;
        if ($warehouseId <= 0) {
            $warehouseId = 1;
        }

        $goodsFilter = self::sqlWarehouseGoodsPositionsOnly('zk');
        $rows = MSSql::getInstance()->query(
            "SELECT t.tw_Symbol AS code,
                    zk.ob_Ilosc AS required,
                    ISNULL(s.st_Stan, 0) AS on_store,
                    ISNULL(s.st_StanRez, 0) AS reserved,
                    ISNULL(s.st_Stan, 0) - ISNULL(s.st_StanRez, 0) AS avail_free,
                    ISNULL(t.tw_Rodzaj, 1) AS rodzaj
             FROM dok_Pozycja zk
             INNER JOIN tw__Towar t ON t.tw_Id = zk.ob_TowId
             LEFT JOIN tw_Stan s ON s.st_TowId = zk.ob_TowId AND s.st_MagId = {$warehouseId}
             WHERE zk.ob_DokHanId = {$orderId}{$goodsFilter}"
        );

        $shortages = array();
        if (!is_array($rows)) {
            return $shortages;
        }

        foreach ($rows as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            $required = (float) ($row['required'] ?? 0);
            if ($code === '' || $required <= 0.00001) {
                continue;
            }
            if ((int) ($row['rodzaj'] ?? 1) === self::TOW_RODZAJ_USLUGA) {
                continue;
            }

            $onStore = (float) ($row['on_store'] ?? 0);
            $availFree = (float) ($row['avail_free'] ?? 0);
            $available = $withReservation ? $onStore : $availFree;
            $missing = max(0.0, $required - $available);
            if ($missing <= 0.00001) {
                continue;
            }

            $shortages[] = array(
                'sku' => $code,
                'code' => $code,
                'symbol' => $code,
                'name' => '',
                'required' => $required,
                'available' => $available,
                'missing' => $missing,
                'source' => 'sql',
            );
        }

        return $shortages;
    }

    /**
     * Usuwa fałszywe braki COM, gdy SQL potwierdza stan (ZK z rezerwacją / komplety).
     *
     * @param array<int, array> $shortages
     * @param int $orderId
     * @param int|null $orderState
     * @param int|null $warehouseId
     * @return array<int, array>
     */
    public static function filterComShortagesAgainstSql(array $shortages, $orderId, $orderState = null, $warehouseId = null)
    {
        if (empty($shortages) || (int) $orderId <= 0) {
            return $shortages;
        }

        $sqlShortages = self::getOrderWarehouseStockShortagesSql($orderId, $warehouseId, $orderState);
        $sqlByCode = array();
        foreach ($sqlShortages as $row) {
            $code = trim((string) ($row['code'] ?? $row['sku'] ?? ''));
            if ($code !== '') {
                $sqlByCode[$code] = $row;
            }
        }

        $filtered = array();
        foreach ($shortages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['sku'] ?? $row['code'] ?? ''));
            if ($code === '' || !isset($sqlByCode[$code])) {
                $filtered[] = $row;
                continue;
            }
            Logger::getInstance()->log(
                'api',
                'filterComShortagesAgainstSql: pominięto fałszywy brak COM dla '
                    . $code . ' (COM available=' . ($row['available'] ?? '?') . ')',
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        }

        return $filtered;
    }

    /**
     * Lista usług do dopisania na WZ z payloadu CRM + opcjonalnie z ZK.
     *
     * @param array $detail
     * @param int $orderId
     * @return array<int, array>
     */
    public static function resolveIssueServicesInput(array $detail, $orderId)
    {
        $lines = array();

        foreach (array('services', 'append_services') as $key) {
            if (empty($detail[$key]) || !is_array($detail[$key])) {
                continue;
            }
            $isAssoc = array_keys($detail[$key]) !== range(0, count($detail[$key]) - 1);
            if ($isAssoc && $key === 'append_services') {
                $bool = filter_var($detail[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool !== null) {
                    continue;
                }
            }
            foreach ($detail[$key] as $svc) {
                if (!is_array($svc)) {
                    continue;
                }
                $line = self::normalizeServiceLine($svc);
                if ($line !== null) {
                    $lines[$line['code']] = $line;
                }
            }
        }

        $copyFromOrder = self::isCopyServicesFromOrderRequested($detail, true)
            || self::isAppendServicesRequested($detail);

        if ($copyFromOrder && (int) $orderId > 0) {
            foreach (self::getOrderServiceLinesFromSql((int) $orderId) as $zkLine) {
                if (!isset($lines[$zkLine['code']])) {
                    $lines[$zkLine['code']] = $zkLine;
                }
            }
        }

        return array_values($lines);
    }

    /**
     * Czy WZ ma już pozycję o podanym symbolu.
     *
     * @param string $issueRef
     * @param string $code
     * @return bool
     */
    public static function issueDocumentHasProductCodeSql($issueRef, $code)
    {
        $issueRef = trim((string) $issueRef);
        $code = trim((string) $code);
        if ($issueRef === '' || $code === '') {
            return false;
        }

        $safeRef = str_replace("'", "''", $issueRef);
        $safeCode = str_replace("'", "''", $code);
        $rows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok_Pozycja wp
             INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
             INNER JOIN tw__Towar t ON t.tw_Id = wp.ob_TowId
             WHERE wz.dok_NrPelny = '{$safeRef}'
               AND t.tw_Symbol = '{$safeCode}'"
        );

        return is_array($rows) && !empty($rows) && (int) ($rows[0]['cnt'] ?? 0) > 0;
    }

    /**
     * Powiąż pozycje usług WZ z pozycjami ZK (ob_DoId po ob_TowId).
     *
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @return int
     */
    public static function linkIssueServicePositionsToOrderSql($orderId, array $issueRefs)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || empty($issueRefs)) {
            return 0;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::linkIssueServicePositionsToOrder(
                OrderComWriter::resolveSubiektGt(),
                $orderId,
                $issueRefs
            );
        }

        $safeIssueRefs = array();
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef !== '') {
                $safeIssueRefs[] = "'" . str_replace("'", "''", $issueRef) . "'";
            }
        }
        if (empty($safeIssueRefs)) {
            return 0;
        }

        $issueInList = implode(', ', $safeIssueRefs);
        MSSql::getInstance()->query(
            "UPDATE wp
             SET wp.ob_DoId = zk.ob_Id
             FROM dok_Pozycja wp
             INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
             INNER JOIN dok_Pozycja zk ON zk.ob_DokHanId = {$orderId}
                 AND ISNULL(zk.ob_TowRodzaj, 1) = " . self::TOW_RODZAJ_USLUGA . "
                 AND zk.ob_TowId = wp.ob_TowId
             WHERE wz.dok_NrPelny IN ({$issueInList})
               AND wp.ob_DoId IS NULL"
        );

        $rows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok_Pozycja wp
             INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
             INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
             WHERE wz.dok_NrPelny IN ({$issueInList})
               AND ISNULL(zk.ob_TowRodzaj, 1) = " . self::TOW_RODZAJ_USLUGA
        );

        return is_array($rows) && !empty($rows) ? (int) ($rows[0]['cnt'] ?? 0) : 0;
    }

    /**
     * Dopisz brakujące usługi (np. U001) na istniejącym WZ.
     *
     * @param string $issueRef
     * @param array $detail
     * @return array{added:int, service_codes:array<int, string>, linked:int}
     * @throws Exception
     */
    public function appendMissingServicesToIssue($issueRef, array $detail = array())
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '') {
            throw new Exception('Brak numeru WZ do dopisania usług.');
        }
        if (!$this->subiektGt) {
            throw new Exception('Brak połączenia z Subiekt GT.');
        }
        if (!$this->subiektGt->SuDokumentyManager->Istnieje($issueRef)) {
            throw new Exception('Dokument WZ nie istnieje: ' . $issueRef);
        }

        $orderId = (int) $this->gt_id;
        if ($orderId <= 0 && !empty($detail['order_ref'])) {
            $zkRow = self::getOrderRowByRefSql((string) $detail['order_ref']);
            $orderId = $zkRow !== null ? (int) ($zkRow['dok_Id'] ?? 0) : 0;
        }
        if ($orderId <= 0) {
            $wzRow = self::getIssueDocumentRowByRef($issueRef);
            $oryg = $wzRow !== null ? trim((string) ($wzRow['dok_NrPelnyOryg'] ?? '')) : '';
            if ($oryg !== '') {
                $zkRow = self::getOrderRowByRefSql($oryg);
                $orderId = $zkRow !== null ? (int) ($zkRow['dok_Id'] ?? 0) : 0;
            }
        }
        if ($orderId <= 0) {
            $orderId = self::resolveOrderIdForIssueSql($issueRef);
        }

        if ($orderId > 0) {
            $this->ensureOrderLoadedById($orderId);
        }

        $serviceLines = self::resolveIssueServicesInput($detail, $orderId);
        if (empty($serviceLines) && $orderId > 0) {
            $serviceLines = self::getOrderServiceLinesFromSql($orderId);
        }
        if (empty($serviceLines)) {
            return array('added' => 0, 'service_codes' => array(), 'linked' => 0);
        }

        $issueDoc = $this->subiektGt->SuDokumentyManager->Wczytaj($issueRef);
        $appendResult = $this->appendServicesToIssueDocument($issueDoc, $serviceLines, $orderId, $issueRef);
        $added = (int) ($appendResult['added'] ?? 0);
        $addedCodes = isset($appendResult['codes']) && is_array($appendResult['codes'])
            ? $appendResult['codes']
            : array();

        if ($added > 0) {
            try {
                $issueDoc->Przelicz();
                $issueDoc->Zapisz();
            } catch (\Exception $e) {
                throw new Exception('Nie udało się zapisać WZ po dopisaniu usług: ' . $e->getMessage());
            }
        }

        $fulfillmentRepair = null;
        if ($orderId > 0) {
            $orderRows = MSSql::getInstance()->query(
                "SELECT TOP 1 dok_NrPelny FROM dok__Dokument
                 WHERE dok_Id = {$orderId} AND dok_Typ = 16 AND dok_Status >= 0"
            );
            if (is_array($orderRows) && !empty($orderRows[0]['dok_NrPelny'])) {
                $fulfillmentRepair = self::ensureOrderFulfilledAfterIssueSql(
                    (string) $orderRows[0]['dok_NrPelny'],
                    $this->cfg ? (int) $this->cfg->getWarehouse() : 1
                );
            }
        }

        $linked = 0;
        if ($orderId > 0) {
            $linked = self::linkIssueServicePositionsToOrderSql($orderId, array($issueRef));
            self::syncIssuePricesFromOrderSql($orderId, array($issueRef));
        }

        Logger::getInstance()->log(
            'api',
            'appendMissingServicesToIssue: issue_ref=' . $issueRef
                . ', order_id=' . $orderId
                . ', added=' . $added
                . ', linked=' . $linked
                . ', codes=' . implode(',', $addedCodes),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return array(
            'added' => $added,
            'service_codes' => $addedCodes,
            'linked' => $linked,
            'fulfillment_repair' => $fulfillmentRepair,
        );
    }

    /**
     * Wczytaj ZK po dok_Id (do kopiowania cen usług na WZ).
     *
     * @param int $orderId
     * @return void
     */
    protected function ensureOrderLoadedById($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || !$this->subiektGt) {
            return;
        }
        if ($this->orderGt && (int) $this->gt_id === $orderId) {
            return;
        }

        $rows = MSSql::getInstance()->query(
            "SELECT TOP 1 dok_NrPelny FROM dok__Dokument
             WHERE dok_Id = {$orderId} AND dok_Typ = 16 AND dok_Status >= 0"
        );
        if (!is_array($rows) || empty($rows)) {
            return;
        }
        $ref = trim((string) ($rows[0]['dok_NrPelny'] ?? ''));
        if ($ref === '' || !$this->subiektGt->SuDokumentyManager->Istnieje($ref)) {
            return;
        }
        $this->order_ref = $ref;
        $this->orderGt = $this->subiektGt->SuDokumentyManager->Wczytaj($ref);
        $this->getGtObject();
        $this->is_exists = true;
    }

    /**
     * @param mixed $issueDoc
     * @param array<int, array> $serviceLines
     * @param int $orderId
     * @return array{added:int, codes:array<int, string>}
     */
    protected function appendServicesToIssueDocument($issueDoc, array $serviceLines, $orderId = 0, $issueRef = '')
    {
        if (!$issueDoc || empty($serviceLines)) {
            return array('added' => 0, 'codes' => array());
        }

        $issueRef = trim((string) $issueRef);
        $missingSql = null;
        if ($orderId > 0 && $issueRef !== '') {
            $missingCodes = self::findMissingIssueServiceCodesSql($orderId, $issueRef);
            $missingSql = !empty($missingCodes) ? array_flip($missingCodes) : array();
        }

        $existingCodes = array();
        if ($missingSql === null) {
            try {
                for ($i = 1; $i <= (int) $issueDoc->Pozycje->Liczba(); $i++) {
                    $sym = trim((string) $issueDoc->Pozycje->Element($i)->TowarSymbol);
                    if ($sym !== '') {
                        $existingCodes[$sym] = true;
                    }
                }
            } catch (\Exception $e) {
                return array('added' => 0, 'codes' => array());
            }
        }

        $added = 0;
        $addedCodes = array();
        foreach ($serviceLines as $line) {
            $code = trim((string) ($line['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if ($missingSql !== null) {
                if (!isset($missingSql[$code])) {
                    continue;
                }
            } elseif (isset($existingCodes[$code])) {
                continue;
            }

            try {
                $pos = $issueDoc->Pozycje->Dodaj($code);
            } catch (\Exception $e) {
                Logger::getInstance()->log(
                    'api',
                    'appendServicesToIssueDocument: Dodaj(' . $code . ') BŁĄD: ' . $e->getMessage(),
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
                continue;
            }

            $qty = (float) ($line['qty'] ?? 1);
            if ($qty <= 0.00001) {
                $qty = 1.0;
            }
            $pos->IloscJm = $qty;

            if (!empty($line['price_net']) && (float) $line['price_net'] > 0.00001) {
                self::trySetComObjectProperty(
                    $pos,
                    array('CenaNetto', 'CenaJednostkowaNetto', 'Cena'),
                    (float) $line['price_net']
                );
            }
            if (!empty($line['price_gross']) && (float) $line['price_gross'] > 0.00001) {
                self::trySetComObjectProperty(
                    $pos,
                    array('CenaBrutto', 'CenaJednostkowaBrutto'),
                    (float) $line['price_gross']
                );
            }
            if (!empty($line['price']) && (float) $line['price'] > 0.00001) {
                $pos->WartoscBruttoPoRabacie = (float) $line['price'] * $qty;
                if (!empty($line['price_before_discount']) && (float) $line['price_before_discount'] > 0.00001) {
                    $pos->WartoscBruttoPrzedRabatem = (float) $line['price_before_discount'] * $qty;
                }
            } elseif ($orderId > 0 && !empty($line['zk_position_id'])) {
                $zkPos = $this->findOrderPositionComById((int) $line['zk_position_id']);
                if ($zkPos) {
                    $this->copyOrderPricesToIssuePosition($zkPos, $pos, $qty);
                }
            } elseif ($orderId > 0 && $this->orderGt) {
                for ($i = 1; $i <= $this->orderGt->Pozycje->Liczba(); $i++) {
                    $zkPos = $this->orderGt->Pozycje->Element($i);
                    $sym = trim((string) $zkPos->TowarSymbol);
                    if ($sym === $code) {
                        $this->copyOrderPricesToIssuePosition($zkPos, $pos, $qty);
                        break;
                    }
                }
            }

            $existingCodes[$code] = true;
            $addedCodes[] = $code;
            $added++;
        }

        return array('added' => $added, 'codes' => $addedCodes);
    }

    /**
     * @param int $positionId ob_Id ZK
     * @return mixed|null
     */
    protected function findOrderPositionComById($positionId)
    {
        $positionId = (int) $positionId;
        if ($positionId <= 0 || !$this->orderGt) {
            return null;
        }
        try {
            for ($i = 1; $i <= $this->orderGt->Pozycje->Liczba(); $i++) {
                $pos = $this->orderGt->Pozycje->Element($i);
                if ((int) $pos->Id === $positionId) {
                    return $pos;
                }
            }
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }

    /**
     * Kody usług obecnych na WZ (SQL).
     *
     * @param string $issueRef
     * @return array<int, string>
     */
    public static function getIssueServiceCodesSql($issueRef)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '') {
            return array();
        }
        $safeRef = str_replace("'", "''", $issueRef);
        $rows = MSSql::getInstance()->query(
            "SELECT DISTINCT t.tw_Symbol AS code
             FROM dok_Pozycja wp
             INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
             INNER JOIN tw__Towar t ON t.tw_Id = wp.ob_TowId
             INNER JOIN dok_Pozycja zk ON zk.ob_DokHanId = wz.dok_DoDokId
                 AND zk.ob_TowId = wp.ob_TowId
                 AND ISNULL(zk.ob_TowRodzaj, 1) = " . self::TOW_RODZAJ_USLUGA . "
             WHERE wz.dok_NrPelny = '{$safeRef}'"
        );
        if (!is_array($rows)) {
            return array();
        }
        $codes = array();
        foreach ($rows as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * Lista usług na WZ po symbolu towaru (bez wymogu linku ZK).
     *
     * @param string $issueRef
     * @return array<int, string>
     */
    public static function getIssueProductCodesSql($issueRef)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '') {
            return array();
        }
        $safeRef = str_replace("'", "''", $issueRef);
        $rows = MSSql::getInstance()->query(
            "SELECT DISTINCT t.tw_Symbol AS code
             FROM dok_Pozycja wp
             INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
             INNER JOIN tw__Towar t ON t.tw_Id = wp.ob_TowId
             WHERE wz.dok_NrPelny = '{$safeRef}'"
        );
        if (!is_array($rows)) {
            return array();
        }
        $codes = array();
        foreach ($rows as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * Czy na WZ brakuje usług względem ZK (np. U001).
     *
     * @param int $orderId
     * @param string $issueRef
     * @return array<int, string> brakujące kody
     */
    public static function findMissingIssueServiceCodesSql($orderId, $issueRef)
    {
        $orderId = (int) $orderId;
        $issueRef = trim((string) $issueRef);
        if ($orderId <= 0 || $issueRef === '') {
            return array();
        }

        $zkServices = self::getOrderServiceLinesFromSql($orderId);
        if (empty($zkServices)) {
            return array();
        }

        $onIssue = array_flip(self::getIssueProductCodesSql($issueRef));
        $missing = array();
        foreach ($zkServices as $line) {
            $code = $line['code'];
            if (!isset($onIssue[$code])) {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    /**
     * ZK.dok_Id dla WZ (dok_NrPelnyOryg, ZK→WZ, ob_DoId na pozycjach).
     *
     * @param string $issueRef
     * @return int
     */
    public static function resolveOrderIdForIssueSql($issueRef)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '') {
            return 0;
        }

        $wzRow = self::getIssueDocumentRowByRef($issueRef);
        if ($wzRow === null) {
            return 0;
        }

        $wzId = (int) ($wzRow['dok_Id'] ?? 0);
        $oryg = trim((string) ($wzRow['dok_NrPelnyOryg'] ?? ''));
        if ($oryg !== '') {
            $zkRow = self::getOrderRowByRefSql($oryg);
            if ($zkRow !== null) {
                return (int) ($zkRow['dok_Id'] ?? 0);
            }
        }

        if ($wzId > 0) {
            $rows = MSSql::getInstance()->query(
                "SELECT TOP 1 zk.dok_Id
                 FROM dok__Dokument zk
                 WHERE zk.dok_Typ = 16 AND zk.dok_Status >= 0 AND zk.dok_DoDokId = {$wzId}
                 ORDER BY zk.dok_Id DESC"
            );
            if (is_array($rows) && !empty($rows[0]['dok_Id'])) {
                return (int) $rows[0]['dok_Id'];
            }

            $rows = MSSql::getInstance()->query(
                "SELECT TOP 1 zk_p.ob_DokHanId AS order_id
                 FROM dok_Pozycja wz_p
                 INNER JOIN dok_Pozycja zk_p ON zk_p.ob_Id = wz_p.ob_DoId
                 INNER JOIN dok__Dokument zk ON zk.dok_Id = zk_p.ob_DokHanId AND zk.dok_Typ = 16
                 WHERE wz_p.ob_DokMagId = {$wzId}
                 ORDER BY wz_p.ob_Id"
            );
            if (is_array($rows) && !empty($rows[0]['order_id'])) {
                return (int) $rows[0]['order_id'];
            }
        }

        return 0;
    }

    /**
     * Numer ZK powiązany z WZ (dok_NrPelnyOryg, nagłówek ZK→WZ, ob_DoId).
     *
     * @param string $issueRef
     * @return string
     */
    public static function resolveOrderRefForIssueSql($issueRef)
    {
        $orderId = self::resolveOrderIdForIssueSql($issueRef);
        if ($orderId <= 0) {
            return '';
        }

        $rows = MSSql::getInstance()->query(
            "SELECT TOP 1 dok_NrPelny FROM dok__Dokument
             WHERE dok_Id = {$orderId} AND dok_Typ = 16 AND dok_Status >= 0"
        );
        if (!is_array($rows) || empty($rows[0]['dok_NrPelny'])) {
            return '';
        }

        return trim((string) $rows[0]['dok_NrPelny']);
    }

    /**
     * Symbole usług na FS (np. U001).
     *
     * @param int $fsId
     * @return array<int, string>
     */
    public static function getInvoiceServiceCodesSql($fsId)
    {
        $fsId = (int) $fsId;
        if ($fsId <= 0) {
            return array();
        }

        $rows = MSSql::getInstance()->query(
            "SELECT DISTINCT t.tw_Symbol AS code
             FROM dok_Pozycja p
             INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
             WHERE p.ob_DokHanId = {$fsId}
               AND ISNULL(p.ob_TowRodzaj, 1) = " . self::TOW_RODZAJ_USLUGA
        );

        if (!is_array($rows)) {
            return array();
        }

        $codes = array();
        foreach ($rows as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Brakujące usługi na WZ względem ZK lub FS (gdy ZK nieznane).
     *
     * @param string $invoiceRef
     * @return array
     */
    public static function findMissingServicesForInvoiceSql($invoiceRef)
    {
        $invoiceRef = trim((string) $invoiceRef);
        $fsRow = self::getSalesInvoiceRowByRefSql($invoiceRef);
        if ($fsRow === null) {
            return array(
                'state' => 'not_found',
                'invoice_ref' => $invoiceRef,
                'message' => 'Nie znaleziono FS.',
            );
        }

        $fsId = (int) ($fsRow['dok_Id'] ?? 0);
        $fsServiceCodes = self::getInvoiceServiceCodesSql($fsId);

        $wzRows = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_DoDokId, dok_NrPelnyOryg
             FROM dok__Dokument
             WHERE dok_Typ = 11 AND dok_DoDokId = {$fsId} AND dok_Status >= 0
             ORDER BY dok_NrPelny"
        );
        $wzRows = is_array($wzRows) ? $wzRows : array();

        $wzOut = array();
        $allMissing = array();
        foreach ($wzRows as $wz) {
            $wzRef = trim((string) ($wz['dok_NrPelny'] ?? ''));
            if ($wzRef === '') {
                continue;
            }

            $orderId = self::resolveOrderIdForIssueSql($wzRef);
            $orderRef = '';
            if ($orderId > 0) {
                $zkRows = MSSql::getInstance()->query(
                    "SELECT dok_NrPelny FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
                );
                $orderRef = is_array($zkRows) && !empty($zkRows[0]['dok_NrPelny'])
                    ? trim((string) $zkRows[0]['dok_NrPelny'])
                    : '';
            }

            $missing = array();
            if ($orderId > 0) {
                $missing = self::findMissingIssueServiceCodesSql($orderId, $wzRef);
            } elseif (!empty($fsServiceCodes)) {
                $onIssue = array_flip(self::getIssueProductCodesSql($wzRef));
                foreach ($fsServiceCodes as $code) {
                    if (!isset($onIssue[$code])) {
                        $missing[] = $code;
                    }
                }
            }

            $missing = array_values(array_unique($missing));
            $allMissing = array_values(array_unique(array_merge($allMissing, $missing)));

            $linkedFsId = (int) ($wz['dok_DoDokId'] ?? 0);
            $wzOut[] = array(
                'wz_ref' => $wzRef,
                'wz_id' => (int) ($wz['dok_Id'] ?? 0),
                'order_id' => $orderId,
                'order_ref' => $orderRef,
                'missing_codes' => $missing,
                'invoiced_to_fs' => $linkedFsId === $fsId,
            );
        }

        return array(
            'state' => 'success',
            'invoice_ref' => (string) ($fsRow['dok_NrPelny'] ?? $invoiceRef),
            'fs' => $fsRow,
            'fs_service_codes' => $fsServiceCodes,
            'wz' => $wzOut,
            'missing_services' => $allMissing,
            'needs_service_fix' => !empty($allMissing),
        );
    }

    /**
     * Naprawa FS: opcjonalnie dopisz usługi (COM) + przygotowanie pod KFS (SQL).
     *
     * @param string $invoiceRef
     * @param array{append_services?:bool, prepare_kfs?:bool, apply?:bool} $options
     * @param Order|null $orderApi wymagane gdy append_services i apply
     * @return array
     */
    public static function repairSalesInvoiceSql($invoiceRef, array $options = array(), $orderApi = null)
    {
        $invoiceRef = trim((string) $invoiceRef);
        $appendServices = !empty($options['append_services']);
        $prepareKfs = !array_key_exists('prepare_kfs', $options) || !empty($options['prepare_kfs']);
        $apply = !empty($options['apply']);

        $serviceDiag = self::findMissingServicesForInvoiceSql($invoiceRef);
        if (($serviceDiag['state'] ?? '') === 'not_found') {
            return $serviceDiag;
        }

        $out = array(
            'state' => 'success',
            'invoice_ref' => (string) ($serviceDiag['invoice_ref'] ?? $invoiceRef),
            'applied' => $apply,
            'services' => array(),
            'kfs' => null,
        );

        $needsService = !empty($serviceDiag['needs_service_fix']);
        if ($appendServices && $needsService) {
            if (!$apply) {
                $out['services'] = array(
                    'state' => 'preview',
                    'would_append' => $serviceDiag['wz'],
                    'missing_services' => $serviceDiag['missing_services'],
                );
            } elseif ($orderApi === null || empty($orderApi->subiektGt)) {
                return array(
                    'state' => 'error',
                    'invoice_ref' => $invoiceRef,
                    'message' => 'Dopisanie transportu wymaga połączenia z Subiekt GT (Sfera COM).',
                );
            } else {
                foreach ($serviceDiag['wz'] as $wzEntry) {
                    $wzRef = (string) ($wzEntry['wz_ref'] ?? '');
                    $missing = isset($wzEntry['missing_codes']) && is_array($wzEntry['missing_codes'])
                        ? $wzEntry['missing_codes']
                        : array();
                    if ($wzRef === '' || empty($missing)) {
                        continue;
                    }

                    $detail = array(
                        'copy_services_from_order' => true,
                        'append_services' => true,
                    );
                    if (!empty($wzEntry['order_ref'])) {
                        $detail['order_ref'] = (string) $wzEntry['order_ref'];
                    }

                    try {
                        $append = $orderApi->appendMissingServicesToIssue($wzRef, $detail);
                        $out['services'][] = array(
                            'wz_ref' => $wzRef,
                            'missing_codes' => $missing,
                            'invoiced_to_fs' => !empty($wzEntry['invoiced_to_fs']),
                            'added' => (int) ($append['added'] ?? 0),
                            'service_codes' => $append['service_codes'] ?? array(),
                            'linked' => (int) ($append['linked'] ?? 0),
                            'state' => 'success',
                        );
                    } catch (\Exception $e) {
                        $out['services'][] = array(
                            'wz_ref' => $wzRef,
                            'missing_codes' => $missing,
                            'invoiced_to_fs' => !empty($wzEntry['invoiced_to_fs']),
                            'state' => 'error',
                            'message' => $e->getMessage(),
                        );
                        $out['state'] = 'partial';
                    }
                }
            }
        } elseif ($appendServices) {
            $out['services'] = array(
                'state' => 'noop',
                'message' => 'Brak usług do dopisania na WZ.',
            );
        }

        if ($prepareKfs) {
            $out['kfs'] = self::prepareSalesInvoiceForCorrectionSql($invoiceRef, $apply);
            if (($out['kfs']['state'] ?? '') === 'error') {
                $out['state'] = ($out['state'] ?? 'success') === 'partial' ? 'partial' : 'error';
            }
        }

        $parts = array();
        if ($appendServices && $needsService) {
            $parts[] = $apply ? 'usługi na WZ' : 'podgląd usług';
        }
        if ($prepareKfs) {
            $parts[] = $apply ? 'powiązania pod KFS' : 'podgląd KFS';
        }
        $out['message'] = $apply
            ? ('Naprawa FS: ' . implode(', ', $parts) . '. Zamknij dokumenty w GT (F5).')
            : ('Podgląd naprawy FS: ' . implode(', ', $parts) . '.');

        return $out;
    }

    /**
     * @param int $month
     * @param int $year
     * @param bool $apply
     * @param array $options
     * @param Order|null $orderApi
     * @return array
     */
    public static function batchRepairSalesInvoicesMonthSql($month, $year, $apply = false, array $options = array(), $orderApi = null)
    {
        $scanOptions = array(
            'only_needs_fix' => !empty($options['only_needs_fix']),
            'only_with_wz' => !empty($options['only_with_wz']),
        );
        $scan = self::scanSalesInvoicesMonthSql($month, $year, $scanOptions);
        $targets = isset($scan['invoices']) && is_array($scan['invoices']) ? $scan['invoices'] : array();

        if (empty($targets)) {
            return array(
                'state' => 'noop',
                'month' => (int) $month,
                'year' => (int) $year,
                'applied' => false,
                'summary' => $scan['summary'] ?? array(),
                'message' => 'Brak FS do naprawy w tym miesiącu (wg filtrów).',
            );
        }

        if (!$apply) {
            return array(
                'state' => 'preview',
                'month' => (int) $month,
                'year' => (int) $year,
                'applied' => false,
                'would_fix_count' => count($targets),
                'would_fix' => array_map(function ($row) {
                    return (string) ($row['fs_ref'] ?? '');
                }, $targets),
                'summary' => $scan['summary'] ?? array(),
                'message' => 'Podgląd — do naprawy: ' . count($targets) . ' FS.',
            );
        }

        $results = array();
        $ok = 0;
        $partial = 0;
        $errors = 0;
        foreach ($targets as $target) {
            $fsRef = (string) ($target['fs_ref'] ?? '');
            if ($fsRef === '') {
                continue;
            }
            $one = self::repairSalesInvoiceSql($fsRef, array_merge($options, array('apply' => true)), $orderApi);
            $state = (string) ($one['state'] ?? 'error');
            if ($state === 'success' || $state === 'noop') {
                $ok++;
            } elseif ($state === 'partial') {
                $partial++;
            } else {
                $errors++;
            }
            $results[] = array(
                'fs_ref' => $fsRef,
                'state' => $state,
                'message' => $one['message'] ?? '',
            );
        }

        $afterScan = self::scanSalesInvoicesMonthSql($month, $year);

        return array(
            'state' => $errors > 0 ? 'partial' : ($partial > 0 ? 'partial' : 'success'),
            'month' => (int) $month,
            'year' => (int) $year,
            'applied' => true,
            'fixed_ok' => $ok,
            'fixed_partial' => $partial,
            'errors' => $errors,
            'results' => $results,
            'summary_before' => $scan['summary'] ?? array(),
            'summary_after' => $afterScan['summary'] ?? array(),
            'message' => 'Naprawiono ' . $ok . ' FS'
                . ($partial > 0 ? ', częściowo: ' . $partial : '')
                . ($errors > 0 ? ', błędów: ' . $errors : '') . '.',
        );
    }

    /**
     * Lista numerów WZ powiązanych z ZK:
     * - dok_NrPelnyOryg na WZ
     * - WZ.dok_DoDokId → ZK (realizacja przez API)
     * - ZK.dok_DoDokId / ZK.dok_DoDokNrPelny → WZ (typowe w GT po realizacji ZK→WZ→FS)
     *
     * @param string $orderRef
     * @param int|null $orderId dok_Id ZK
     * @return array
     */
    public static function getIssueRefsForOrder($orderRef, $orderId = null)
    {
        $refs = array();
        foreach (self::orderRefVariants($orderRef) as $variant) {
            $safeRef = str_replace("'", "''", $variant);
            $sql = "SELECT d.dok_NrPelny
                    FROM dok__Dokument d
                    WHERE d.dok_Typ = 11
                    AND d.dok_Status >= 0
                    AND d.dok_NrPelnyOryg = '{$safeRef}'
                    ORDER BY d.dok_Id ASC";
            $data = MSSql::getInstance()->query($sql);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $row) {
                if (!empty($row['dok_NrPelny'])) {
                    $refs[] = (string) $row['dok_NrPelny'];
                }
            }
        }

        $orderId = (int) $orderId;
        if ($orderId > 0) {
            $sql = "SELECT d.dok_NrPelny
                    FROM dok__Dokument d
                    WHERE d.dok_Typ = 11
                    AND d.dok_Status >= 0
                    AND d.dok_DoDokId = {$orderId}
                    ORDER BY d.dok_Id ASC";
            $data = MSSql::getInstance()->query($sql);
            if (is_array($data)) {
                foreach ($data as $row) {
                    if (!empty($row['dok_NrPelny'])) {
                        $refs[] = (string) $row['dok_NrPelny'];
                    }
                }
            }

            foreach (self::findIssueRefsLinkedFromOrderDocumentSql($orderId) as $candidate) {
                if (!empty($candidate['issue_ref'])) {
                    $refs[] = (string) $candidate['issue_ref'];
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * WZ wskazane na nagłówku ZK (dok_DoDokId / dok_DoDokNrPelny).
     *
     * @param int $orderId dok_Id ZK
     * @return array
     */
    public static function findIssueRefsLinkedFromOrderDocumentSql($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return array();
        }

        $sql = "SELECT wz.dok_Id, wz.dok_NrPelny, wz.dok_NrPelnyOryg, wz.dok_Status,
                       zk.dok_DoDokId, zk.dok_DoDokNrPelny
                FROM dok__Dokument zk
                LEFT JOIN dok__Dokument wz ON wz.dok_Id = zk.dok_DoDokId
                    AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                WHERE zk.dok_Id = {$orderId} AND zk.dok_Typ = 16";
        $rows = MSSql::getInstance()->query($sql);
        $candidates = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
                if ($ref === '') {
                    continue;
                }
                $candidates[$ref] = array(
                    'issue_ref' => $ref,
                    'issue_oryg' => trim((string) ($row['dok_NrPelnyOryg'] ?? '')),
                    'issue_status' => (int) ($row['dok_Status'] ?? -1),
                    'link_type' => 'zk_do_dok_id',
                    'zk_do_dok_id' => (int) ($row['dok_DoDokId'] ?? 0),
                    'zk_do_dok_nr' => trim((string) ($row['dok_DoDokNrPelny'] ?? '')),
                );
            }
        }

        $nrSql = "SELECT wz.dok_Id, wz.dok_NrPelny, wz.dok_NrPelnyOryg, wz.dok_Status,
                         zk.dok_DoDokNrPelny
                  FROM dok__Dokument zk
                  INNER JOIN dok__Dokument wz ON wz.dok_NrPelny = zk.dok_DoDokNrPelny
                      AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                  WHERE zk.dok_Id = {$orderId} AND zk.dok_Typ = 16
                  AND ISNULL(zk.dok_DoDokNrPelny, '') <> ''";
        $nrRows = MSSql::getInstance()->query($nrSql);
        if (is_array($nrRows)) {
            foreach ($nrRows as $row) {
                $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
                if ($ref === '' || isset($candidates[$ref])) {
                    continue;
                }
                $candidates[$ref] = array(
                    'issue_ref' => $ref,
                    'issue_oryg' => trim((string) ($row['dok_NrPelnyOryg'] ?? '')),
                    'issue_status' => (int) ($row['dok_Status'] ?? -1),
                    'link_type' => 'zk_do_dok_nr',
                    'zk_do_dok_nr' => trim((string) ($row['dok_DoDokNrPelny'] ?? '')),
                );
            }
        }

        return array_values($candidates);
    }

    /**
     * @param int $orderId
     * @return array
     * @deprecated Użyj findIssueRefsLinkedFromOrderDocumentSql — WZ.dok_DoDokId→ZK to inny przypadek
     */
    public static function findIssueRefsByDoDokIdSql($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return array();
        }

        $sql = "SELECT d.dok_Id, d.dok_NrPelny, d.dok_NrPelnyOryg, d.dok_Status, d.dok_DoDokId
                FROM dok__Dokument d
                WHERE d.dok_Typ = 11
                AND d.dok_Status >= 0
                AND d.dok_DoDokId = {$orderId}
                ORDER BY d.dok_Id DESC";
        $rows = MSSql::getInstance()->query($sql);
        if (!is_array($rows)) {
            return array();
        }

        $candidates = array();
        foreach ($rows as $row) {
            $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $candidates[] = array(
                'issue_ref' => $ref,
                'issue_oryg' => trim((string) ($row['dok_NrPelnyOryg'] ?? '')),
                'issue_status' => (int) ($row['dok_Status'] ?? -1),
                'link_type' => 'wz_do_dok_id',
                'do_dok_id' => (int) ($row['dok_DoDokId'] ?? 0),
            );
        }

        return $candidates;
    }

    /**
     * @param string $issueRef
     * @return array|null dok_Id, dok_NrPelnyOryg, dok_Status
     */
    public static function getIssueDocumentRowByRef($issueRef)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '') {
            return null;
        }

        $safeIssue = str_replace("'", "''", $issueRef);
        $sql = "SELECT d.dok_Id, d.dok_NrPelny, d.dok_NrPelnyOryg, d.dok_Status, d.dok_DoDokId
                FROM dok__Dokument d
                WHERE d.dok_Typ = 11 AND d.dok_NrPelny = '{$safeIssue}'";
        $data = MSSql::getInstance()->query($sql);
        if (!is_array($data) || empty($data)) {
            return null;
        }

        return $data[0];
    }

    /**
     * @param string $orderRef
     * @return array|null dok_Id, dok_Status, dok_StatusEx, dok_DoDokId, dok_DoDokNrPelny
     */
    public static function getOrderRowByRefSql($orderRef)
    {
        $orderRef = trim((string) $orderRef);
        if ($orderRef === '') {
            return null;
        }

        $safeRef = str_replace("'", "''", $orderRef);
        $rows = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_DoDokId, dok_DoDokNrPelny
             FROM dok__Dokument
             WHERE dok_NrPelny = '{$safeRef}' AND dok_Typ = 16"
        );

        return is_array($rows) && !empty($rows) ? $rows[0] : null;
    }

    /**
     * Czy WZ faktycznie dotyczy tego ZK (ob_DoId → pozycje ZK, bez pozycji z innego ZK).
     * Chroni przed recyklingiem numeru WZ po usunięciu dokumentu w Subiekcie.
     *
     * @param string $issueRef
     * @param int $orderId
     * @param string $orderRef
     * @return bool
     */
    public static function isIssueDocumentLinkedToOrder($issueRef, $orderId, $orderRef)
    {
        $row = self::getIssueDocumentRowByRef($issueRef);
        if ($row === null) {
            return false;
        }

        if ((int) ($row['dok_Status'] ?? -1) < 0) {
            return false;
        }

        return self::isIssueDocumentIdLinkedToOrder((int) $row['dok_Id'], $orderId, $orderRef, $row);
    }

    /**
     * @param int $wzId
     * @param int $orderId
     * @param string $orderRef
     * @param array|null $wzRow
     * @return bool
     */
    public static function isIssueDocumentIdLinkedToOrder($wzId, $orderId, $orderRef, $wzRow = null)
    {
        $wzId = (int) $wzId;
        $orderId = (int) $orderId;
        if ($wzId <= 0 || $orderId <= 0) {
            return false;
        }

        $foreignSql = "SELECT COUNT(*) AS cnt
                       FROM dok_Pozycja wz_p
                       INNER JOIN dok_Pozycja zk_p ON zk_p.ob_Id = wz_p.ob_DoId
                       WHERE wz_p.ob_DokMagId = {$wzId}
                       AND wz_p.ob_DoId > 0
                       AND zk_p.ob_DokHanId <> {$orderId}";
        $foreignData = MSSql::getInstance()->query($foreignSql);
        if ((int) ($foreignData[0]['cnt'] ?? 0) > 0) {
            return false;
        }

        $linkSql = "SELECT COUNT(*) AS cnt
                    FROM dok_Pozycja wz_p
                    INNER JOIN dok_Pozycja zk_p ON zk_p.ob_Id = wz_p.ob_DoId
                    WHERE wz_p.ob_DokMagId = {$wzId}
                    AND zk_p.ob_DokHanId = {$orderId}";
        $linkData = MSSql::getInstance()->query($linkSql);
        if ((int) ($linkData[0]['cnt'] ?? 0) > 0) {
            return true;
        }

        $zkLinkSql = "SELECT dok_DoDokId, dok_DoDokNrPelny
                      FROM dok__Dokument
                      WHERE dok_Id = {$orderId} AND dok_Typ = 16";
        $zkLinkData = MSSql::getInstance()->query($zkLinkSql);
        if (is_array($zkLinkData) && !empty($zkLinkData)) {
            $zkDoDokId = (int) ($zkLinkData[0]['dok_DoDokId'] ?? 0);
            $zkDoDokNr = trim((string) ($zkLinkData[0]['dok_DoDokNrPelny'] ?? ''));
            if ($zkDoDokId === $wzId) {
                return true;
            }
            $wzNr = trim((string) ($wzRow['dok_NrPelny'] ?? ''));
            if ($wzNr === '' && $wzRow === null) {
                $wzNrData = MSSql::getInstance()->query(
                    "SELECT dok_NrPelny FROM dok__Dokument WHERE dok_Id = {$wzId}"
                );
                $wzNr = is_array($wzNrData) && !empty($wzNrData)
                    ? trim((string) ($wzNrData[0]['dok_NrPelny'] ?? ''))
                    : '';
            } elseif ($wzRow !== null) {
                $wzNr = trim((string) ($wzRow['dok_NrPelny'] ?? ''));
            }
            if ($zkDoDokNr !== '' && $wzNr !== '' && $zkDoDokNr === $wzNr) {
                return true;
            }
        }

        if ($wzRow === null) {
            $wzSql = "SELECT dok_NrPelnyOryg, dok_DoDokId FROM dok__Dokument WHERE dok_Id = {$wzId}";
            $wzData = MSSql::getInstance()->query($wzSql);
            $oryg = is_array($wzData) && !empty($wzData)
                ? trim((string) ($wzData[0]['dok_NrPelnyOryg'] ?? ''))
                : '';
            $doDokId = is_array($wzData) && !empty($wzData)
                ? (int) ($wzData[0]['dok_DoDokId'] ?? 0)
                : 0;
        } else {
            $oryg = trim((string) ($wzRow['dok_NrPelnyOryg'] ?? ''));
            $doDokId = (int) ($wzRow['dok_DoDokId'] ?? 0);
        }

        if ($doDokId === $orderId) {
            return true;
        }

        $orygMatches = false;
        foreach (self::orderRefVariants($orderRef) as $variant) {
            if ($oryg === $variant) {
                $orygMatches = true;
                break;
            }
        }
        if (!$orygMatches) {
            return false;
        }

        return self::isSingleIssueCoveringOrderSql($orderId, $wzId);
    }

    /**
     * @param int $orderId
     * @param int $wzId
     * @return bool
     */
    public static function isSingleIssueCoveringOrderSql($orderId, $wzId)
    {
        $orderId = (int) $orderId;
        $wzId = (int) $wzId;
        if ($orderId <= 0 || $wzId <= 0) {
            return false;
        }

        $orderedRows = MSSql::getInstance()->query(
            "SELECT zk.ob_TowId, SUM(zk.ob_Ilosc) AS qty
             FROM dok_Pozycja zk
             WHERE zk.ob_DokHanId = {$orderId}
             GROUP BY zk.ob_TowId"
        );
        $issuedRows = MSSql::getInstance()->query(
            "SELECT wp.ob_TowId, SUM(wp.ob_Ilosc) AS qty
             FROM dok_Pozycja wp
             WHERE wp.ob_DokMagId = {$wzId}
             GROUP BY wp.ob_TowId"
        );

        return self::isOrderTowQtyCoveredBySqlMaps(
            self::getOrderTowQtyMapFromRows($orderedRows),
            self::getOrderTowQtyMapFromRows($issuedRows)
        );
    }

    /**
     * Numery WZ powiązane z tym ZK (dok_NrPelnyOryg lub ob_DoId), po walidacji treści.
     *
     * @return array
     */
    public function getValidIssueRefsForOrder()
    {
        $refs = array();
        $seen = array();
        $orderId = (int) $this->gt_id;
        if ($orderId <= 0) {
            return $refs;
        }

        foreach (self::getIssueRefsForOrder($this->order_ref, $orderId) as $ref) {
            if (self::isIssueDocumentLinkedToOrder($ref, $orderId, $this->order_ref)) {
                $seen[$ref] = true;
                $refs[] = $ref;
            }
        }

        $linkedSql = "SELECT DISTINCT wz.dok_NrPelny, wz.dok_Id
                      FROM dok_Pozycja zk
                      INNER JOIN dok_Pozycja wz_p ON wz_p.ob_DoId = zk.ob_Id
                      INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId
                          AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                      WHERE zk.ob_DokHanId = {$orderId}
                      ORDER BY wz.dok_Id ASC";
        $linkedRows = MSSql::getInstance()->query($linkedSql);
        if (is_array($linkedRows)) {
            foreach ($linkedRows as $row) {
                $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
                if ($ref === '' || isset($seen[$ref])) {
                    continue;
                }
                if (self::isIssueDocumentLinkedToOrder($ref, $orderId, $this->order_ref)) {
                    $seen[$ref] = true;
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * Najnowsze poprawne WZ dla bieżącego ZK.
     *
     * @return string|null
     */
    public function findValidIssueForOrder()
    {
        $orderId = (int) $this->gt_id;
        if ($orderId <= 0) {
            return null;
        }

        foreach (self::findIssueRefsLinkedFromOrderDocumentSql($orderId) as $candidate) {
            $ref = trim((string) ($candidate['issue_ref'] ?? ''));
            if ($ref !== '' && self::isIssueDocumentLinkedToOrder($ref, $orderId, $this->order_ref)) {
                return $ref;
            }
        }

        $doDokSql = "SELECT d.dok_NrPelny, d.dok_Id
                     FROM dok__Dokument d
                     WHERE d.dok_Typ = 11
                     AND d.dok_Status >= 0
                     AND d.dok_DoDokId = {$orderId}
                     ORDER BY d.dok_Id DESC";
        $doDokData = MSSql::getInstance()->query($doDokSql);
        if (is_array($doDokData)) {
            foreach ($doDokData as $row) {
                $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
                if ($ref !== '' && self::isIssueDocumentLinkedToOrder($ref, $orderId, $this->order_ref)) {
                    return $ref;
                }
            }
        }

        $safeVariants = array();
        foreach (self::orderRefVariants($this->order_ref) as $variant) {
            $safeVariants[] = "'" . str_replace("'", "''", $variant) . "'";
        }
        if (!empty($safeVariants)) {
            $inList = implode(', ', $safeVariants);
            $sql = "SELECT d.dok_NrPelny, d.dok_Id
                    FROM dok__Dokument d
                    WHERE d.dok_Typ = 11
                    AND d.dok_Status >= 0
                    AND d.dok_NrPelnyOryg IN ({$inList})
                    ORDER BY d.dok_Id DESC";
            $data = MSSql::getInstance()->query($sql);
            if (is_array($data)) {
                foreach ($data as $row) {
                    $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
                    if ($ref !== '' && self::isIssueDocumentLinkedToOrder($ref, $orderId, $this->order_ref)) {
                        return $ref;
                    }
                }
            }
        }

        $validRefs = $this->getValidIssueRefsForOrder();
        if (empty($validRefs)) {
            return null;
        }

        return (string) end($validRefs);
    }

    /**
     * WZ już zapisane (powiązane lub osierocone) — nie twórz duplikatu przy order/fulfill.
     *
     * @return array<int, string>
     */
    protected function resolveIssueRefsBlockingDuplicateWzCreation()
    {
        $refs = $this->getValidIssueRefsForOrder();
        $seen = array();
        foreach ($refs as $ref) {
            $seen[$ref] = true;
        }

        foreach ($this->findOrphanIssueRefsForOrder() as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '' && !isset($seen[$ref])) {
                $seen[$ref] = true;
                $refs[] = $ref;
            }
        }

        return array_values($refs);
    }

    /**
     * WZ z poprawnymi ilościami, ale bez powiązań ob_DoId / nagłówka — typowy efekt NaPodstawie+Zapisz bez linków w SQL.
     *
     * @return array<int, string>
     */
    public function findOrphanIssueRefsForOrder()
    {
        $orderId = (int) $this->gt_id;
        if ($orderId <= 0) {
            return array();
        }

        $refs = array();
        $sql = "SELECT wz.dok_NrPelny, wz.dok_Id
                FROM dok__Dokument wz
                WHERE wz.dok_Typ = 11
                  AND wz.dok_Status >= 0
                  AND wz.dok_Id BETWEEN {$orderId} AND {$orderId} + 15
                  AND EXISTS (
                      SELECT 1 FROM dok_Pozycja wp WHERE wp.ob_DokMagId = wz.dok_Id
                  )
                  AND NOT EXISTS (
                      SELECT 1
                      FROM dok_Pozycja wp
                      INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
                      WHERE wp.ob_DokMagId = wz.dok_Id
                  )
                ORDER BY wz.dok_Id ASC";
        $rows = MSSql::getInstance()->query($sql);
        if (!is_array($rows)) {
            return $refs;
        }

        foreach ($rows as $row) {
            $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
            $wzId = (int) ($row['dok_Id'] ?? 0);
            if ($ref === '' || $wzId <= 0) {
                continue;
            }
            if (self::isIssueDocumentLinkedToOrder($ref, $orderId, $this->order_ref)) {
                continue;
            }
            if (!self::isSingleIssueCoveringOrderSql($orderId, $wzId)) {
                continue;
            }
            $refs[] = $ref;
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param string $issueRef
     * @throws Exception
     */
    protected function assertIssueDocumentLinkedToOrderOrCancel($issueRef)
    {
        if (self::isIssueDocumentLinkedToOrder($issueRef, (int) $this->gt_id, $this->order_ref)) {
            return;
        }

        $this->cancelIssueDocumentByRef($issueRef);
        throw new Exception(
            'WZ ' . $issueRef . ' nie jest powiązane z ZK ' . $this->order_ref
            . ' (możliwy konflikt numeru po usunięciu poprzedniego WZ w Subiekcie).'
        );
    }

    /**
     * @param string $issueRef
     */
    protected function cancelIssueDocumentByRef($issueRef, $unlinkFromOrder = true)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '') {
            return;
        }

        if ($unlinkFromOrder && (int) $this->gt_id > 0 && $this->order_ref !== '') {
            self::unlinkIssueFromOrderSql((int) $this->gt_id, $this->order_ref, array($issueRef));
        }

        $result = $this->removeIssueDocumentViaCom($issueRef);
        if (strpos($result, 'failed:') === 0) {
            Logger::getInstance()->log(
                'api',
                'cancelIssueDocumentByRef: ' . $issueRef . ': ' . $result,
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        }
    }

    /**
     * Fragment SQL: pozycje magazynowe ZK (bez usług niewydawanych na WZ).
     *
     * @param string $alias
     * @return string
     */
    protected static function sqlWarehouseGoodsPositionsOnly($alias = 'zk')
    {
        return " AND ({$alias}.ob_TowRodzaj IS NULL OR {$alias}.ob_TowRodzaj <> " . self::TOW_RODZAJ_USLUGA . ")";
    }

    /**
     * Oczekiwana rezerwacja magazynowa z otwartych ZK (status 5, pozostałość towaru).
     *
     * @param int $warehouseId
     * @param array<int, string>|null $productSymbols opcjonalny filtr symboli
     * @return array<int, array{product_id:int, symbol:string, warehouse_id:int, expected_rez:float, current_rez:float, orphan_rez:float}>
     */
    public static function findStockReservationMismatchesSql($warehouseId = 1, array $productSymbols = null)
    {
        $warehouseId = (int) $warehouseId;
        if ($warehouseId <= 0) {
            $warehouseId = 1;
        }

        $symbolFilter = '';
        if (is_array($productSymbols) && !empty($productSymbols)) {
            $safeSymbols = array();
            foreach ($productSymbols as $symbol) {
                $symbol = trim((string) $symbol);
                if ($symbol !== '') {
                    $safeSymbols[] = "'" . str_replace("'", "''", $symbol) . "'";
                }
            }
            if (!empty($safeSymbols)) {
                $symbolFilter = ' AND t.tw_Symbol IN (' . implode(', ', $safeSymbols) . ')';
            }
        }

        $goodsFilter = self::sqlWarehouseGoodsPositionsOnly('p');
        $rows = MSSql::getInstance()->query(
            "SELECT s.st_TowId AS product_id,
                    t.tw_Symbol AS symbol,
                    s.st_MagId AS warehouse_id,
                    ISNULL(s.st_StanRez, 0) AS current_rez,
                    ISNULL(e.expected_rez, 0) AS expected_rez,
                    ISNULL(s.st_StanRez, 0) - ISNULL(e.expected_rez, 0) AS orphan_rez,
                    ISNULL(s.st_Stan, 0) AS on_store
             FROM tw_Stan s
             INNER JOIN tw__Towar t ON t.tw_Id = s.st_TowId
             LEFT JOIN (
                 SELECT p.ob_TowId AS product_id,
                        ISNULL(NULLIF(p.ob_MagId, 0), {$warehouseId}) AS warehouse_id,
                        SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS expected_rez
                 FROM dok_Pozycja p
                 INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId
                     AND d.dok_Typ = 16
                     AND d.dok_Status >= 0
                 WHERE d.dok_Status = 5
                   AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
                   {$goodsFilter}
                 GROUP BY p.ob_TowId, ISNULL(NULLIF(p.ob_MagId, 0), {$warehouseId})
             ) e ON e.product_id = s.st_TowId AND e.warehouse_id = s.st_MagId
             WHERE s.st_MagId = {$warehouseId}
               AND ABS(ISNULL(s.st_StanRez, 0) - ISNULL(e.expected_rez, 0)) > 0.00001
               {$symbolFilter}
             ORDER BY orphan_rez DESC, t.tw_Symbol"
        );

        if (!is_array($rows)) {
            return array();
        }

        $items = array();
        foreach ($rows as $row) {
            $items[] = array(
                'product_id' => (int) ($row['product_id'] ?? 0),
                'symbol' => trim((string) ($row['symbol'] ?? '')),
                'warehouse_id' => (int) ($row['warehouse_id'] ?? $warehouseId),
                'current_rez' => (float) ($row['current_rez'] ?? 0),
                'expected_rez' => (float) ($row['expected_rez'] ?? 0),
                'orphan_rez' => (float) ($row['orphan_rez'] ?? 0),
                'on_store' => (float) ($row['on_store'] ?? 0),
            );
        }

        return $items;
    }

    /**
     * Ustawia tw_Stan.st_StanRez wg otwartych ZK (status 5). Naprawia osierocone rezerwacje po domknięciu ZK przez SQL.
     *
     * @param int $warehouseId
     * @param bool $dryRun
     * @param array<int, string>|null $productSymbols
     * @return array
     */
    public static function syncStockReservationsFromOrdersSql($warehouseId = 1, $dryRun = true, array $productSymbols = null)
    {
        $warehouseId = (int) $warehouseId;
        if ($warehouseId <= 0) {
            $warehouseId = 1;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::syncStockReservationsFromOrders(
                OrderComWriter::resolveSubiektGt(),
                $warehouseId,
                $dryRun,
                $productSymbols
            );
        }

        $mismatches = self::findStockReservationMismatchesSql($warehouseId, $productSymbols);
        if ($dryRun || empty($mismatches)) {
            return array(
                'state' => $dryRun ? 'preview' : 'noop',
                'dry_run' => (bool) $dryRun,
                'warehouse_id' => $warehouseId,
                'count' => count($mismatches),
                'total_orphan_rez' => array_sum(array_map(function ($row) {
                    return (float) ($row['orphan_rez'] ?? 0);
                }, $mismatches)),
                'items' => $mismatches,
                'message' => $dryRun
                    ? 'Podgląd — nic nie zmieniono. Użyj apply, aby zsynchronizować st_StanRez.'
                    : (empty($mismatches) ? 'Brak rozjazdów rezerwacji.' : 'Brak zmian do wykonania.'),
            );
        }

        $goodsFilter = self::sqlWarehouseGoodsPositionsOnly('p');
        $symbolFilter = '';
        if (is_array($productSymbols) && !empty($productSymbols)) {
            $safeSymbols = array();
            foreach ($productSymbols as $symbol) {
                $symbol = trim((string) $symbol);
                if ($symbol !== '') {
                    $safeSymbols[] = "'" . str_replace("'", "''", $symbol) . "'";
                }
            }
            if (!empty($safeSymbols)) {
                $symbolFilter = ' AND t.tw_Symbol IN (' . implode(', ', $safeSymbols) . ')';
            }
        }

        MSSql::getInstance()->query(
            "UPDATE s
             SET s.st_StanRez = ISNULL(e.expected_rez, 0)
             FROM tw_Stan s
             INNER JOIN tw__Towar t ON t.tw_Id = s.st_TowId
             LEFT JOIN (
                 SELECT p.ob_TowId AS product_id,
                        ISNULL(NULLIF(p.ob_MagId, 0), {$warehouseId}) AS warehouse_id,
                        SUM(p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0)) AS expected_rez
                 FROM dok_Pozycja p
                 INNER JOIN dok__Dokument d ON d.dok_Id = p.ob_DokHanId
                     AND d.dok_Typ = 16
                     AND d.dok_Status >= 0
                 WHERE d.dok_Status = 5
                   AND p.ob_Ilosc - ISNULL(p.ob_IloscMag, 0) > 0.00001
                   {$goodsFilter}
                 GROUP BY p.ob_TowId, ISNULL(NULLIF(p.ob_MagId, 0), {$warehouseId})
             ) e ON e.product_id = s.st_TowId AND e.warehouse_id = s.st_MagId
             WHERE s.st_MagId = {$warehouseId}
               AND ABS(ISNULL(s.st_StanRez, 0) - ISNULL(e.expected_rez, 0)) > 0.00001
               {$symbolFilter}"
        );

        $after = self::findStockReservationMismatchesSql($warehouseId, $productSymbols);

        Logger::getInstance()->log(
            'api',
            'syncStockReservationsFromOrdersSql: mag=' . $warehouseId
                . ', fixed=' . count($mismatches)
                . ', remaining=' . count($after),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return array(
            'state' => 'success',
            'dry_run' => false,
            'warehouse_id' => $warehouseId,
            'fixed_count' => count($mismatches),
            'fixed_items' => $mismatches,
            'remaining_count' => count($after),
            'remaining_items' => $after,
            'message' => 'Zsynchronizowano st_StanRez z otwartymi ZK (status 5). Odśwież stany w GT (F5).',
        );
    }

    /**
     * Po domknięciu ZK — napraw st_StanRez dla towarów z tego zamówienia.
     *
     * @param int $orderId
     * @param int $warehouseId
     * @return array
     */
    public static function syncStockReservationsForOrderProductsSql($orderId, $warehouseId = 1)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return array('state' => 'noop', 'count' => 0);
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::syncStockReservationsForOrder(
                OrderComWriter::resolveSubiektGt(),
                $orderId,
                null,
                null
            );
        }

        $goodsFilter = self::sqlWarehouseGoodsPositionsOnly('p');
        $rows = MSSql::getInstance()->query(
            "SELECT DISTINCT t.tw_Symbol AS symbol
             FROM dok_Pozycja p
             INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
             WHERE p.ob_DokHanId = {$orderId}{$goodsFilter}"
        );
        if (!is_array($rows) || empty($rows)) {
            return array('state' => 'noop', 'count' => 0);
        }

        $symbols = array();
        foreach ($rows as $row) {
            $symbol = trim((string) ($row['symbol'] ?? ''));
            if ($symbol !== '') {
                $symbols[] = $symbol;
            }
        }
        if (empty($symbols)) {
            return array('state' => 'noop', 'count' => 0);
        }

        return self::syncStockReservationsFromOrdersSql((int) $warehouseId, false, $symbols);
    }

    /**
     * @param int|null $rodzaj ob_TowRodzaj
     * @return bool
     */
    public static function isServicePositionKind($rodzaj)
    {
        return (int) $rodzaj === self::TOW_RODZAJ_USLUGA;
    }

    /**
     * @param int $orderId dok_Id ZK
     * @return array<int, int> ob_Id => ob_TowRodzaj
     */
    public static function getOrderPositionTowRodzajMap($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return array();
        }

        $rows = MSSql::getInstance()->query(
            "SELECT ob_Id, ISNULL(ob_TowRodzaj, 1) AS ob_TowRodzaj
             FROM dok_Pozycja WHERE ob_DokHanId = {$orderId}"
        );
        $map = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['ob_Id']] = (int) $row['ob_TowRodzaj'];
            }
        }

        return $map;
    }

    /**
     * @param int $orderId
     * @return array<int, int> ob_Id => ob_TowId
     */
    public static function getOrderPositionTowIdMap($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return array();
        }

        $rows = MSSql::getInstance()->query(
            "SELECT ob_Id, ob_TowId FROM dok_Pozycja WHERE ob_DokHanId = {$orderId}"
        );
        $map = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['ob_Id']] = (int) $row['ob_TowId'];
            }
        }

        return $map;
    }

    /**
     * Suma wydanych towarów z WZ (wg numeru dokumentu), bez usług.
     *
     * @param array<int, string> $issueRefs
     * @return array<int, float> ob_TowId => qty
     */
    public static function getIssuedGoodsQtyByTowIdFromIssueRefs(array $issueRefs)
    {
        $safeIssueRefs = array();
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef === '') {
                continue;
            }
            $safeIssueRefs[] = "'" . str_replace("'", "''", $issueRef) . "'";
        }
        if (empty($safeIssueRefs)) {
            return array();
        }

        $issueInList = implode(', ', $safeIssueRefs);
        $rows = MSSql::getInstance()->query(
            "SELECT wp.ob_TowId, SUM(wp.ob_Ilosc) AS qty
             FROM dok__Dokument wz
             INNER JOIN dok_Pozycja wp ON wp.ob_DokMagId = wz.dok_Id
             WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
             AND wz.dok_NrPelny IN ({$issueInList})
             GROUP BY wp.ob_TowId"
        );

        return self::getOrderTowQtyMapFromRows($rows);
    }

    /**
     * Czy ZK ma pozycje towarowe (do wydania na WZ), poza usługami.
     *
     * @param int $orderId
     * @return bool
     */
    public static function orderHasWarehouseGoodsPositions($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return false;
        }

        $rows = MSSql::getInstance()->query(
            "SELECT TOP 1 p.ob_Id FROM dok_Pozycja p
             WHERE p.ob_DokHanId = {$orderId}" . self::sqlWarehouseGoodsPositionsOnly('p')
        );

        return is_array($rows) && !empty($rows);
    }

    /**
     * Ustawia właściwość COM — próbuje kolejne nazwy (InsERT GT różni się między wersjami).
     *
     * @param mixed $object
     * @param array<int, string> $propertyNames
     * @param mixed $value
     * @return string|false użyta nazwa lub false
     */
    protected static function trySetComObjectProperty($object, array $propertyNames, $value)
    {
        if (!$object) {
            return false;
        }
        foreach ($propertyNames as $propertyName) {
            try {
                $object->{$propertyName} = $value;
                return $propertyName;
            } catch (\Exception $e) {
            }
        }

        return false;
    }

    /**
     * Odczyt właściwości COM z listy alternatywnych nazw.
     *
     * @param mixed $object
     * @param array<int, string> $propertyNames
     * @param mixed|null $default
     * @return mixed
     */
    protected static function tryGetComObjectProperty($object, array $propertyNames, $default = null)
    {
        if (!$object) {
            return $default;
        }
        foreach ($propertyNames as $propertyName) {
            try {
                return $object->{$propertyName};
            } catch (\Exception $e) {
            }
        }

        return $default;
    }

    /**
     * Powiąż pozycje WZ z pozycjami ZK (ob_DoId) po ob_TowId — brak tego linku blokuje domknięcie w GT.
     *
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @param string $orderRef
     * @param bool $linkZkHeaderToIssue ustaw ZK.dok_DoDokId → WZ przy domknięciu ZK
     * @param bool $linkWzHeaderToOrder ustaw WZ.dok_DoDokId → ZK (blokuje edycję WZ / FS w GT)
     * @param bool $linkPositionsToZk ob_DoId na pozycjach WZ→ZK (blokuje KFS po wystawieniu FS)
     * @param bool $setWzNrPelnyOryg WZ.dok_NrPelnyOryg = numer ZK
     * @param bool $linkServicePositionsToZk ob_DoId na usługach WZ→ZK
     * @return array{position_links:int, wz_oryg:int, zk_header:int, wz_header:int, prices_synced:int}
     */
    public static function repairWzToOrderPositionLinksSql(
        $orderId,
        array $issueRefs,
        $orderRef,
        $linkZkHeaderToIssue = false,
        $linkWzHeaderToOrder = false,
        $linkPositionsToZk = true,
        $setWzNrPelnyOryg = true,
        $linkServicePositionsToZk = true
    )
    {
        $orderId = (int) $orderId;
        $orderRef = trim((string) $orderRef);
        if ($orderId <= 0 || $orderRef === '') {
            return array('position_links' => 0, 'wz_oryg' => 0, 'zk_header' => 0, 'wz_header' => 0, 'prices_synced' => 0);
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::repairWzToOrderPositionLinks(
                OrderComWriter::resolveSubiektGt(),
                $orderId,
                $issueRefs,
                $orderRef,
                $linkZkHeaderToIssue,
                $linkWzHeaderToOrder,
                $linkPositionsToZk,
                $setWzNrPelnyOryg,
                $linkServicePositionsToZk
            );
        }

        $safeIssueRefs = array();
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef !== '') {
                $safeIssueRefs[] = "'" . str_replace("'", "''", $issueRef) . "'";
            }
        }
        if (empty($safeIssueRefs)) {
            return array('position_links' => 0, 'wz_oryg' => 0, 'zk_header' => 0, 'wz_header' => 0, 'prices_synced' => 0);
        }

        $issueInList = implode(', ', $safeIssueRefs);
        $safeOrderRef = str_replace("'", "''", $orderRef);
        $goodsFilter = self::sqlWarehouseGoodsPositionsOnly('zk');

        if ($linkWzHeaderToOrder) {
            MSSql::getInstance()->query(
                "UPDATE wz
                 SET wz.dok_DoDokId = {$orderId}
                 FROM dok__Dokument wz
                 WHERE wz.dok_Typ = 11
                   AND wz.dok_NrPelny IN ({$issueInList})
                   AND (wz.dok_DoDokId IS NULL OR wz.dok_DoDokId = 0)"
            );
        }

        if ($linkZkHeaderToIssue && count($safeIssueRefs) === 1) {
            MSSql::getInstance()->query(
                "UPDATE zk
                 SET zk.dok_DoDokId = wz.dok_Id,
                     zk.dok_DoDokNrPelny = wz.dok_NrPelny
                 FROM dok__Dokument zk
                 INNER JOIN dok__Dokument wz ON wz.dok_Typ = 11 AND wz.dok_NrPelny IN ({$issueInList})
                 WHERE zk.dok_Id = {$orderId}
                   AND zk.dok_Typ = 16
                   AND (zk.dok_DoDokId IS NULL OR zk.dok_DoDokId = 0)"
            );
        }

        if ($linkPositionsToZk) {
            MSSql::getInstance()->query(
                "UPDATE wp
                 SET wp.ob_DoId = zk.ob_Id
                 FROM dok_Pozycja wp
                 INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                 INNER JOIN dok_Pozycja zk ON zk.ob_DokHanId = {$orderId}
                     AND zk.ob_TowId = wp.ob_TowId{$goodsFilter}
                 WHERE wz.dok_NrPelny IN ({$issueInList})
                   AND wp.ob_DoId IS NULL"
            );
        }

        $linkedRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok_Pozycja wp
             INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
             INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
             WHERE wz.dok_NrPelny IN ({$issueInList})"
        );
        $positionLinks = is_array($linkedRows) && !empty($linkedRows)
            ? (int) ($linkedRows[0]['cnt'] ?? 0)
            : 0;

        if ($setWzNrPelnyOryg) {
            MSSql::getInstance()->query(
                "UPDATE dok__Dokument
                 SET dok_NrPelnyOryg = '{$safeOrderRef}'
                 WHERE dok_Typ = 11 AND dok_NrPelny IN ({$issueInList})
                   AND (dok_NrPelnyOryg IS NULL OR LTRIM(RTRIM(dok_NrPelnyOryg)) = '')"
            );
        }

        $orygRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt FROM dok__Dokument
             WHERE dok_Typ = 11 AND dok_NrPelny IN ({$issueInList})
               AND dok_NrPelnyOryg = '{$safeOrderRef}'"
        );
        $wzOryg = is_array($orygRows) && !empty($orygRows) ? (int) ($orygRows[0]['cnt'] ?? 0) : 0;

        $zkHeaderRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok__Dokument zk
             INNER JOIN dok__Dokument wz ON wz.dok_Id = zk.dok_DoDokId AND wz.dok_Typ = 11
             WHERE zk.dok_Id = {$orderId} AND zk.dok_Typ = 16
               AND wz.dok_NrPelny IN ({$issueInList})"
        );
        $zkHeader = is_array($zkHeaderRows) && !empty($zkHeaderRows)
            ? (int) ($zkHeaderRows[0]['cnt'] ?? 0)
            : 0;

        $wzHeaderRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt FROM dok__Dokument
             WHERE dok_Typ = 11 AND dok_NrPelny IN ({$issueInList})
               AND dok_DoDokId = {$orderId}"
        );
        $wzHeader = is_array($wzHeaderRows) && !empty($wzHeaderRows)
            ? (int) ($wzHeaderRows[0]['cnt'] ?? 0)
            : 0;

        if ($linkServicePositionsToZk) {
            self::linkIssueServicePositionsToOrderSql($orderId, $issueRefs);
        }
        $pricesSynced = self::syncIssuePricesFromOrderSql($orderId, $issueRefs);

        return array(
            'position_links' => $positionLinks,
            'wz_oryg' => $wzOryg,
            'zk_header' => $zkHeader,
            'wz_header' => $wzHeader,
            'prices_synced' => $pricesSynced,
        );
    }

    /**
     * Powiązuje nagłówek ZK z WZ (dok_DoDokId / dok_DoDokNrPelny) — bez WZ.dok_DoDokId.
     *
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @return int liczba powiązanych nagłówków ZK (0 lub 1)
     */
    public static function linkOrderHeaderToIssueSql($orderId, array $issueRefs)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || empty($issueRefs)) {
            return 0;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::linkOrderHeaderToIssue(
                OrderComWriter::resolveSubiektGt(),
                $orderId,
                $issueRefs
            );
        }

        $safeIssueRefs = array();
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef !== '') {
                $safeIssueRefs[] = "'" . str_replace("'", "''", $issueRef) . "'";
            }
        }
        if (empty($safeIssueRefs)) {
            return 0;
        }

        $issueInList = implode(', ', $safeIssueRefs);
        if (count($safeIssueRefs) !== 1) {
            $issueRef = trim((string) $issueRefs[0]);
            $wzRow = self::getIssueDocumentRowByRef($issueRef);
            if ($wzRow === null) {
                return 0;
            }
            $wzId = (int) ($wzRow['dok_Id'] ?? 0);
            $safeNr = str_replace("'", "''", (string) ($wzRow['dok_NrPelny'] ?? ''));
            if ($wzId <= 0 || $safeNr === '') {
                return 0;
            }
            MSSql::getInstance()->query(
                "UPDATE dok__Dokument
                 SET dok_DoDokId = {$wzId},
                     dok_DoDokNrPelny = '{$safeNr}',
                     dok_DoDokDataWyst = (
                         SELECT dok_DataWyst FROM dok__Dokument WHERE dok_Id = {$wzId} AND dok_Typ = 11
                     )
                 WHERE dok_Id = {$orderId} AND dok_Typ = 16"
            );
        } else {
            MSSql::getInstance()->query(
                "UPDATE zk
                 SET zk.dok_DoDokId = wz.dok_Id,
                     zk.dok_DoDokNrPelny = wz.dok_NrPelny,
                     zk.dok_DoDokDataWyst = wz.dok_DataWyst
                 FROM dok__Dokument zk
                 INNER JOIN dok__Dokument wz ON wz.dok_Typ = 11 AND wz.dok_NrPelny IN ({$issueInList})
                 WHERE zk.dok_Id = {$orderId} AND zk.dok_Typ = 16"
            );
        }

        $linkedRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok__Dokument zk
             INNER JOIN dok__Dokument wz ON wz.dok_Id = zk.dok_DoDokId AND wz.dok_Typ = 11
             WHERE zk.dok_Id = {$orderId} AND zk.dok_Typ = 16
               AND wz.dok_NrPelny IN ({$issueInList})"
        );

        return is_array($linkedRows) && !empty($linkedRows) ? (int) ($linkedRows[0]['cnt'] ?? 0) : 0;
    }

    /**
     * Powiąż nagłówek ZK z WZ (kolumna „do dokumentu” w liście ZK) — operacja panelowa / SQL.
     *
     * @param string $orderRef
     * @param array<int, string> $issueRefs
     * @param bool $closeOrder ustaw status ZK 7/8 (zrealizowane)
     * @return array
     */
    public static function linkOrderHeaderToIssueForOrderSql($orderRef, array $issueRefs, $closeOrder = false)
    {
        $orderRef = trim((string) $orderRef);
        $zkRow = self::getOrderRowByRefSql($orderRef);
        if ($zkRow === null) {
            return array(
                'state' => 'error',
                'message' => 'Nie znaleziono ZK: ' . $orderRef,
            );
        }

        $orderId = (int) ($zkRow['dok_Id'] ?? 0);
        if ($orderId <= 0) {
            return array(
                'state' => 'error',
                'message' => 'Brak dok_Id dla ZK: ' . $orderRef,
            );
        }

        if (empty($issueRefs)) {
            return array(
                'state' => 'error',
                'message' => 'Podaj numer WZ — ta akcja ustawia ZK.dok_DoDokNrPelny (nagłówek), nie same pozycje.',
            );
        }

        $validRefs = array();
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef === '') {
                continue;
            }
            $wzRow = self::getIssueDocumentRowByRef($issueRef);
            if ($wzRow === null) {
                return array(
                    'state' => 'error',
                    'message' => 'Nie znaleziono WZ w bazie: ' . $issueRef
                        . ' — użyj pełnego numeru (np. WZ 3013/06/2026).',
                );
            }
            if ((int) ($wzRow['dok_Status'] ?? -1) < 0) {
                return array(
                    'state' => 'error',
                    'message' => 'WZ jest anulowane/usunięte: ' . $issueRef
                        . ' — najpierw odepnij ZK i usuń WZ w GT.',
                );
            }
            $validRefs[] = (string) ($wzRow['dok_NrPelny'] ?? $issueRef);
        }

        if (empty($validRefs)) {
            return array(
                'state' => 'error',
                'message' => 'Brak poprawnego numeru WZ.',
            );
        }

        self::repairWzToOrderPositionLinksSql($orderId, $validRefs, $orderRef, false, false);
        $linked = self::linkOrderHeaderToIssueSql($orderId, $validRefs);

        $closed = false;
        if ($closeOrder && $linked > 0) {
            $withReservation = (int) ($zkRow['dok_Status'] ?? 0) === 5;
            $coverage = self::isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef);
            $targetStatus = self::resolveOrderFulfilledTargetStatusAfterFullIssue($coverage, $withReservation);
            $closed = self::applyOrderFulfilledStatusInSql(
                $orderId,
                $targetStatus,
                self::orderFulfilledStatusUsesReservation($targetStatus, $withReservation)
            );
        }

        $zkAfter = self::getOrderRowByRefSql($orderRef);

        return array(
            'state' => $linked > 0 ? 'success' : 'error',
            'order_ref' => $orderRef,
            'issue_refs' => $validRefs,
            'zk_header_linked' => $linked,
            'zk_closed' => $closed,
            'zk_do_dok_id' => (int) ($zkAfter['dok_DoDokId'] ?? 0),
            'zk_do_dok_nr' => trim((string) ($zkAfter['dok_DoDokNrPelny'] ?? '')),
            'zk_status' => (int) ($zkAfter['dok_Status'] ?? 0),
            'status_label' => self::getOrderStatusLabel((int) ($zkAfter['dok_Status'] ?? 0)),
            'message' => $linked > 0
                ? ($closeOrder && $closed
                    ? 'Powiązano WZ na nagłówku ZK i ustawiono status zrealizowane. Odśwież listę ZK w GT (F5).'
                    : 'Powiązano numer WZ na nagłówku ZK (dok_DoDokNrPelny). Odśwież listę ZK w GT (F5).')
                : 'Nie udało się powiązać nagłówka ZK — sprawdź numery dokumentów.',
        );
    }

    /**
     * Ustawia dok_Status 7/8 i dok_StatusEx=4 (ZK całkowicie) wg tabeli dok__Dokument.
     *
     * @param int $orderId
     * @param int $targetStatus 7 lub 8
     * @param bool $withReservation dok_ZrealizowaneZRezerwacja
     * @return bool
     */
    public static function applyOrderFulfilledStatusInSql($orderId, $targetStatus, $withReservation = false)
    {
        $orderId = (int) $orderId;
        $targetStatus = (int) $targetStatus;
        if ($orderId <= 0 || !in_array($targetStatus, array(7, 8), true)) {
            return false;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::applyOrderFulfilledStatus(
                OrderComWriter::resolveSubiektGt(),
                $orderId,
                null,
                $targetStatus,
                $withReservation
            );
        }

        $withReservation = $withReservation ? 1 : 0;
        $promoteSevenToEight = ($targetStatus === 8) ? 1 : 0;
        MSSql::getInstance()->query(
            "UPDATE dok__Dokument
             SET dok_Status = {$targetStatus},
                 dok_StatusEx = (ISNULL(dok_StatusEx, 0) & ~1) | 4,
                 dok_ZrealizowaneZRezerwacja = {$withReservation}
             WHERE dok_Id = {$orderId}
               AND dok_Typ = 16
               AND (
                   dok_Status IN (5, 6)
                   OR (dok_Status IN (7, 8) AND (ISNULL(dok_StatusEx, 0) & 4) = 0)
                   OR (dok_Status = 7 AND {$promoteSevenToEight} = 1)
               )"
        );

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Status, dok_StatusEx FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );

        return is_array($rows)
            && !empty($rows)
            && (int) ($rows[0]['dok_Status'] ?? 0) === $targetStatus
            && (((int) ($rows[0]['dok_StatusEx'] ?? 0)) & 4) !== 0;
    }

    /**
     * Naprawia ptaszek ZK: status 7/8 bez StatusEx bit 4 (COM po Zapisz często zostawia „częściowo”).
     *
     * @param string $orderRef
     * @return array
     */
    public static function repairOrderFulfilledCheckmarkSql($orderRef)
    {
        $orderRef = trim((string) $orderRef);
        $zkRow = self::getOrderRowByRefSql($orderRef);
        if ($zkRow === null) {
            return array(
                'state' => 'not_found',
                'order_ref' => $orderRef,
                'message' => 'Nie znaleziono ZK.',
            );
        }

        $orderId = (int) ($zkRow['dok_Id'] ?? 0);
        $status = (int) ($zkRow['dok_Status'] ?? 0);
        $statusEx = (int) ($zkRow['dok_StatusEx'] ?? 0);
        $coverage = self::isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef);
        $remainingGoods = self::getOrderRemainingQtyFromSql($orderId, $orderRef, true);

        if (!$coverage || $remainingGoods > 0.00001) {
            return array(
                'state' => 'noop',
                'order_ref' => $orderRef,
                'zk_status' => $status,
                'status_ex' => $statusEx,
                'coverage_complete' => $coverage,
                'remaining_qty_goods' => $remainingGoods,
                'message' => 'ZK nie ma pełnego pokrycia WZ — nie można ustawić ptaszka „całkowicie”.',
            );
        }

        if ($status === 8 && ($statusEx & 4) !== 0) {
            return array(
                'state' => 'noop',
                'order_ref' => $orderRef,
                'zk_status' => $status,
                'status_ex' => $statusEx,
                'message' => 'ZK ma już pełny ptaszek (status 8, StatusEx bit 4).',
            );
        }

        $withReservation = in_array($status, array(5, 7), true);
        $targetStatus = self::resolveOrderFulfilledTargetStatusAfterFullIssue($coverage, $withReservation);
        $ok = self::applyOrderFulfilledStatusInSql(
            $orderId,
            $targetStatus,
            self::orderFulfilledStatusUsesReservation($targetStatus, $withReservation)
        );
        $after = self::getOrderRowByRefSql($orderRef);

        return array(
            'state' => $ok ? 'success' : 'error',
            'order_ref' => $orderRef,
            'applied' => $ok,
            'before' => array(
                'dok_Status' => $status,
                'dok_StatusEx' => $statusEx,
                'status_label' => self::getOrderStatusLabel($status),
                'status_ex_label' => self::getOrderStatusExLabel($statusEx),
            ),
            'after' => $after !== null ? array(
                'dok_Status' => (int) ($after['dok_Status'] ?? 0),
                'dok_StatusEx' => (int) ($after['dok_StatusEx'] ?? 0),
                'status_label' => self::getOrderStatusLabel((int) ($after['dok_Status'] ?? 0)),
                'status_ex_label' => self::getOrderStatusExLabel((int) ($after['dok_StatusEx'] ?? 0)),
            ) : null,
            'message' => $ok
                ? 'Ustawiono StatusEx „całkowicie” (bit 4). Odśwież listę ZK w GT (F5).'
                : 'Nie udało się naprawić ptaszka — sprawdź status ZK i powiązanie WZ.',
        );
    }

    /**
     * Po zapisie WZ (COM) GT często cofa ZK ze statusu 8 na 7 — promuj ponownie gdy WZ pokrywa całość.
     *
     * @param string $orderRef numer ZK lub WZ (rozwiązywany przez resolveOrderRefForIssueSql)
     * @param int $warehouseId
     * @return array
     */
    public static function ensureOrderFulfilledAfterIssueSql($orderRef, $warehouseId = 1)
    {
        $orderRef = trim((string) $orderRef);
        if ($orderRef === '') {
            return array('state' => 'noop', 'message' => 'Brak numeru dokumentu.');
        }

        if (stripos($orderRef, 'WZ ') === 0) {
            $resolved = self::resolveOrderRefForIssueSql($orderRef);
            if ($resolved !== '') {
                $orderRef = $resolved;
            }
        }

        $zkRow = self::getOrderRowByRefSql($orderRef);
        if ($zkRow === null) {
            return array(
                'state' => 'not_found',
                'order_ref' => $orderRef,
                'message' => 'Nie znaleziono ZK.',
            );
        }

        $orderId = (int) ($zkRow['dok_Id'] ?? 0);
        $status = (int) ($zkRow['dok_Status'] ?? 0);
        $statusEx = (int) ($zkRow['dok_StatusEx'] ?? 0);
        $coverage = self::isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef);
        $remainingGoods = self::getOrderRemainingQtyFromSql($orderId, $orderRef, true);

        if (!$coverage || $remainingGoods > 0.00001) {
            return array(
                'state' => 'noop',
                'order_ref' => $orderRef,
                'zk_status' => $status,
                'status_ex' => $statusEx,
                'coverage_complete' => $coverage,
                'message' => 'ZK bez pełnego pokrycia WZ — pominięto domknięcie.',
            );
        }

        if ($status === 8 && ($statusEx & 4) !== 0) {
            $resSync = self::syncStockReservationsForOrderProductsSql($orderId, (int) $warehouseId);
            return array(
                'state' => 'noop',
                'order_ref' => $orderRef,
                'zk_status' => $status,
                'status_ex' => $statusEx,
                'reservation_sync' => $resSync,
                'message' => 'ZK ma już status 8 i pełny ptaszek.',
            );
        }

        $repair = self::repairOrderFulfilledCheckmarkSql($orderRef);
        $resSync = self::syncStockReservationsForOrderProductsSql($orderId, (int) $warehouseId);
        $repair['reservation_sync'] = $resSync;

        Logger::getInstance()->log(
            'api',
            'ensureOrderFulfilledAfterIssueSql: order_ref=' . $orderRef
                . ', before_status=' . $status
                . ', repair=' . ($repair['state'] ?? 'unknown'),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return $repair;
    }

    /**
     * Masowa naprawa ZK: status 7 + pełne WZ → promocja do 8 (szary ptaszek w liście GT).
     *
     * @param string|null $dateFrom YYYY-MM-DD
     * @param bool $dryRun
     * @return array
     */
    public static function repairStuckFulfilledOrdersBulkSql($dateFrom = null, $dryRun = true)
    {
        $dateFilter = '';
        if ($dateFrom !== null && trim((string) $dateFrom) !== '') {
            $safeDate = str_replace("'", "''", trim((string) $dateFrom));
            $dateFilter = " AND zk.dok_DataWyst >= '{$safeDate}'";
        }

        $rows = MSSql::getInstance()->query(
            "SELECT zk.dok_Id, zk.dok_NrPelny, zk.dok_Status, zk.dok_StatusEx, zk.dok_DoDokNrPelny
             FROM dok__Dokument zk
             WHERE zk.dok_Typ = 16 AND zk.dok_Status >= 0
               AND zk.dok_Status = 7
               AND (ISNULL(zk.dok_StatusEx, 0) & 4) <> 0
               AND ISNULL(zk.dok_DoDokNrPelny, '') LIKE 'WZ%'
               {$dateFilter}
             ORDER BY zk.dok_Id DESC"
        );

        $candidates = array();
        $fixed = array();
        $skipped = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $orderRef = trim((string) ($row['dok_NrPelny'] ?? ''));
            $orderId = (int) ($row['dok_Id'] ?? 0);
            if ($orderRef === '' || $orderId <= 0) {
                continue;
            }
            if (!self::isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef)) {
                $skipped[] = array('order_ref' => $orderRef, 'reason' => 'brak_pełnego_pokrycia');
                continue;
            }
            $candidates[] = $orderRef;
            if (!$dryRun) {
                $result = self::repairOrderFulfilledCheckmarkSql($orderRef);
                if (($result['state'] ?? '') === 'success') {
                    self::syncStockReservationsForOrderProductsSql($orderId, 1);
                    $fixed[] = $orderRef;
                } else {
                    $skipped[] = array(
                        'order_ref' => $orderRef,
                        'reason' => $result['message'] ?? 'repair_failed',
                    );
                }
            }
        }

        return array(
            'state' => $dryRun ? 'preview' : 'success',
            'dry_run' => (bool) $dryRun,
            'date_from' => $dateFrom,
            'candidate_count' => count($candidates),
            'candidates' => array_slice($candidates, 0, 100),
            'candidates_truncated' => count($candidates) > 100,
            'fixed_count' => count($fixed),
            'fixed' => array_slice($fixed, 0, 100),
            'skipped_count' => count($skipped),
            'skipped' => array_slice($skipped, 0, 50),
            'message' => $dryRun
                ? 'Podgląd — użyj apply, aby promować ZK 7→8.'
                : 'Naprawiono ' . count($fixed) . ' zamówień. Odśwież listę ZK w GT (F5).',
        );
    }

    /**
     * ZK status 7/8 bez powiązanego WZ — rezerwacja zniknęła, zamówienie nadal otwarte.
     *
     * @param int $orderId
     * @param string $orderRef
     * @return bool
     */
    public static function isOrderStuckWithoutIssueSql($orderId, $orderRef)
    {
        $orderId = (int) $orderId;
        $orderRef = trim((string) $orderRef);
        if ($orderId <= 0 || $orderRef === '') {
            return false;
        }

        $zkRow = self::getOrderRowByRefSql($orderRef);
        if ($zkRow === null || (int) ($zkRow['dok_Id'] ?? 0) !== $orderId) {
            return false;
        }

        $status = (int) ($zkRow['dok_Status'] ?? 0);
        if (!self::isOrderStatusFulfilled($status)) {
            return false;
        }

        if (self::orderHasActiveIssueLinksSql($orderId, $orderRef)) {
            return false;
        }

        $headerWz = trim((string) ($zkRow['dok_DoDokNrPelny'] ?? ''));
        if ($headerWz !== '' && stripos($headerWz, 'WZ ') === 0) {
            return false;
        }

        return true;
    }

    /**
     * Czy ZK ma aktywną rezerwację (status 5 lub błędne 7 bez WZ po ręcznym „Rezerwuj stany” w GT).
     *
     * @param int $orderId
     * @param string $orderRef
     * @return bool
     */
    public static function orderHasActiveReservationSql($orderId, $orderRef)
    {
        $orderId = (int) $orderId;
        $orderRef = trim((string) $orderRef);
        if ($orderId <= 0 || $orderRef === '') {
            return false;
        }

        $zkRow = self::getOrderRowByRefSql($orderRef);
        if ($zkRow === null || (int) ($zkRow['dok_Id'] ?? 0) !== $orderId) {
            return false;
        }

        $status = (int) ($zkRow['dok_Status'] ?? 0);
        if ($status === 5) {
            return true;
        }

        if ($status === 7 && self::isOrderStuckWithoutIssueSql($orderId, $orderRef)) {
            return true;
        }

        return false;
    }

    /**
     * Zeruje ob_IloscMag na pozycjach ZK bez realnego powiązania WZ (ob_DoId).
     *
     * @param int $orderId
     * @return int
     */
    public static function resetOrderPositionIssuedQtyWithoutIssueSql($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return 0;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::resetOrderPositionIssuedQtyWithoutIssue(
                OrderComWriter::resolveSubiektGt(),
                $orderId
            );
        }

        $countRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok_Pozycja p
             WHERE p.ob_DokHanId = {$orderId}
               AND ISNULL(p.ob_IloscMag, 0) > 0.00001
               AND NOT EXISTS (
                   SELECT 1
                   FROM dok_Pozycja wz_p
                   INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId
                       AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                   WHERE wz_p.ob_DoId = p.ob_Id
               )"
        );
        $toReset = is_array($countRows) && !empty($countRows) ? (int) ($countRows[0]['cnt'] ?? 0) : 0;
        if ($toReset <= 0) {
            return 0;
        }

        MSSql::getInstance()->query(
            "UPDATE p
             SET p.ob_IloscMag = 0
             FROM dok_Pozycja p
             WHERE p.ob_DokHanId = {$orderId}
               AND ISNULL(p.ob_IloscMag, 0) > 0.00001
               AND NOT EXISTS (
                   SELECT 1
                   FROM dok_Pozycja wz_p
                   INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId
                       AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                   WHERE wz_p.ob_DoId = p.ob_Id
               )"
        );

        return $toReset;
    }

    /**
     * Cofa ZK 7/8 bez WZ do statusu 5 i przywraca rezerwacje magazynowe (st_StanRez).
     *
     * @param string $orderRef
     * @param int $warehouseId
     * @param bool $dryRun
     * @param bool $syncStockReservations czy nadpisywać tw_Stan.st_StanRez (false przy ścieżce API — GT/COM)
     * @return array
     */
    public static function repairStuckOrderWithoutIssueSql($orderRef, $warehouseId = 1, $dryRun = true, $syncStockReservations = true)
    {
        $orderRef = trim((string) $orderRef);
        $zkRow = self::getOrderRowByRefSql($orderRef);
        if ($zkRow === null) {
            return array(
                'state' => 'not_found',
                'order_ref' => $orderRef,
                'message' => 'Nie znaleziono ZK.',
            );
        }

        $orderId = (int) ($zkRow['dok_Id'] ?? 0);
        $statusBefore = (int) ($zkRow['dok_Status'] ?? 0);
        if (!self::isOrderStuckWithoutIssueSql($orderId, $orderRef)) {
            return array(
                'state' => 'noop',
                'order_ref' => $orderRef,
                'zk_status' => $statusBefore,
                'message' => 'ZK nie jest w stanie 7/8 bez WZ — pominięto.',
            );
        }

        $hadReservation = in_array($statusBefore, array(5, 7), true)
            || (int) ($zkRow['dok_ZrealizowaneZRezerwacja'] ?? 0) === 1;

        if ($dryRun) {
            return array(
                'state' => 'preview',
                'dry_run' => true,
                'order_ref' => $orderRef,
                'order_id' => $orderId,
                'zk_status' => $statusBefore,
                'had_reservation' => $hadReservation,
                'message' => 'Podgląd — użyj apply, aby cofnąć ZK do statusu 5 i zsynchronizować rezerwacje.',
            );
        }

        $positionsReset = self::resetOrderPositionIssuedQtyWithoutIssueSql($orderId);
        $reopened = self::reopenOrderAfterIssueRemovalSql($orderId, $orderRef, $hadReservation);
        $resSync = null;
        if ($syncStockReservations) {
            $resSync = self::syncStockReservationsForOrderProductsSql($orderId, (int) $warehouseId);
        }
        $zkAfter = self::getOrderRowByRefSql($orderRef);
        $statusAfter = $zkAfter !== null ? (int) ($zkAfter['dok_Status'] ?? 0) : $statusBefore;

        Logger::getInstance()->log(
            'api',
            'repairStuckOrderWithoutIssueSql: order_ref=' . $orderRef
                . ', status ' . $statusBefore . '→' . $statusAfter
                . ', positions_reset=' . $positionsReset
                . ', reopened=' . ($reopened ? 'tak' : 'nie'),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return array(
            'state' => $reopened ? 'success' : 'partial',
            'order_ref' => $orderRef,
            'order_id' => $orderId,
            'zk_status_before' => $statusBefore,
            'zk_status_after' => $statusAfter,
            'positions_reset' => $positionsReset,
            'reopened_sql' => $reopened,
            'reservation_sync' => $resSync,
            'message' => $reopened
                ? 'ZK cofnięte do statusu 5 w SQL — rezerwacje magazynowe zsynchronizowane. '
                    . 'Napis „Rezerwuj stany magazynowe” w GT wymaga Zapisz przez COM (Sfera); '
                    . 'sam SQL nie odświeża dropdownu — odśwież listę F5 lub uruchom naprawę z --with-com.'
                : 'Częściowa naprawa — sprawdź ZK w GT.',
        );
    }

    /**
     * @param array<int, string> $orderRefs
     * @param int $warehouseId
     * @param bool $dryRun
     * @return array
     */
    public static function repairStuckOrdersWithoutIssueBulkSql(array $orderRefs, $warehouseId = 1, $dryRun = true)
    {
        $fixed = array();
        $skipped = array();
        foreach ($orderRefs as $orderRef) {
            $orderRef = trim((string) $orderRef);
            if ($orderRef === '') {
                continue;
            }
            $result = self::repairStuckOrderWithoutIssueSql($orderRef, $warehouseId, $dryRun);
            $state = (string) ($result['state'] ?? '');
            if ($state === 'success') {
                $fixed[] = $orderRef;
            } elseif ($state !== 'preview') {
                $skipped[] = array(
                    'order_ref' => $orderRef,
                    'state' => $state,
                    'message' => $result['message'] ?? '',
                );
            } elseif ($dryRun) {
                $fixed[] = $orderRef;
            }
        }

        return array(
            'state' => $dryRun ? 'preview' : 'success',
            'dry_run' => (bool) $dryRun,
            'count' => count($fixed),
            'fixed' => $fixed,
            'skipped' => $skipped,
            'message' => $dryRun
                ? 'Podgląd naprawy ZK bez WZ.'
                : 'Naprawiono ' . count($fixed) . ' ZK. Odśwież stany w GT (F5).',
        );
    }

    /**
     * SQL + opcjonalnie COM: przywraca rezerwację na ZK po repairStuckOrderWithoutIssueSql.
     *
     * @param int $warehouseId
     * @param bool $syncStockReservations
     * @return array
     */
    public function repairStuckOrderWithoutIssue($warehouseId = 1, $syncStockReservations = true)
    {
        $result = self::repairStuckOrderWithoutIssueSql(
            $this->order_ref,
            $warehouseId,
            false,
            $syncStockReservations
        );
        if (($result['state'] ?? '') !== 'success' && ($result['state'] ?? '') !== 'partial') {
            return $result;
        }

        if (!$this->orderGt) {
            return $result;
        }

        $comSync = $this->syncOrderReservationInCom(true);
        $result['com_reservation'] = (bool) ($comSync['synced'] ?? false);
        if (!empty($comSync['error'])) {
            $result['com_error'] = $comSync['error'];
        }
        if (!empty($comSync['message'])) {
            $result['com_message'] = $comSync['message'];
        }

        return $result;
    }

    /**
     * Czy SQL wymaga rezerwacji, a COM (Informator / dropdown GT) jeszcze nie zsynchronizowane.
     *
     * @return bool
     */
    public function orderNeedsComReservationSync()
    {
        if (!$this->orderGt || (int) $this->gt_id <= 0) {
            return false;
        }
        if (!self::orderHasActiveReservationSql((int) $this->gt_id, $this->order_ref)) {
            return false;
        }

        return !(bool) ($this->orderGt->Rezerwacja ?? false);
    }

    /**
     * COM: Rezerwacja=true + Zapisz() — odświeża Informator produktu i dropdown w GT.
     * Samo dok_Status=5 w SQL nie wystarcza (kolumna Ilość w Informatorze = rezerwacja COM).
     *
     * @param bool|null $enable null = wg SQL (status 5), true/false = wymuszenie
     * @return array{synced:bool, skipped?:string, reservation?:bool, state?:int, error?:string, message?:string}
     */
    public function syncOrderReservationInCom($enable = null)
    {
        if (!$this->orderGt) {
            return array(
                'synced' => false,
                'error' => 'Brak obiektu COM ZK.',
            );
        }

        $this->reloadOrderFromGt();
        $state = (int) $this->state;
        if (!self::isOrderStatusOpen($state)) {
            return array(
                'synced' => false,
                'skipped' => 'not_open',
                'state' => $state,
                'message' => 'ZK nie jest otwarte (status ' . $state . ').',
            );
        }

        if ($enable === null) {
            $enable = self::orderHasActiveReservationSql((int) $this->gt_id, $this->order_ref)
                || $this->orderHadReservationForClose($state);
        } else {
            $enable = (bool) $enable;
        }

        $comReserved = (bool) ($this->orderGt->Rezerwacja ?? false);
        if ($comReserved === $enable && (!$enable || self::orderHasActiveReservationSql((int) $this->gt_id, $this->order_ref))) {
            return array(
                'synced' => true,
                'skipped' => 'already_synced',
                'reservation' => $comReserved,
                'state' => $state,
                'message' => 'COM już zsynchronizowane z SQL.',
            );
        }

        try {
            $this->orderGt->Rezerwacja = $enable;
            $this->orderGt->Przelicz();
            $this->orderGt->Zapisz();
            $this->reloadOrderFromGt();
            Logger::getInstance()->log(
                'api',
                'syncOrderReservationInCom: ' . $this->order_ref
                    . ' rezerwacja=' . ($enable ? 'tak' : 'nie')
                    . ', status=' . (int) $this->state,
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );

            return array(
                'synced' => true,
                'reservation' => (bool) ($this->orderGt->Rezerwacja ?? $enable),
                'state' => (int) $this->state,
                'message' => 'Zsynchronizowano rezerwację COM — odśwież Informator / listę ZK (F5).',
            );
        } catch (\Throwable $e) {
            Logger::getInstance()->log(
                'api',
                'syncOrderReservationInCom: ' . $this->order_ref . ': ' . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );

            return array(
                'synced' => false,
                'error' => $e->getMessage(),
                'message' => 'Nie udało się zapisać rezerwacji w COM — Informator pozostanie bez ikon.',
            );
        }
    }

    /**
     * Przygotowuje ZK przed WZ z API: cofa błędne 7 bez WZ, nie nadpisuje rezerwacji SQL z GT.
     *
     * @return array{prepared:bool, state:int, reservation:bool}
     */
    public function prepareOrderForIssueFromApi()
    {
        $orderId = (int) $this->gt_id;
        $prepared = false;

        if ($orderId > 0 && self::isOrderStuckWithoutIssueSql($orderId, $this->order_ref)) {
            self::resetOrderPositionIssuedQtyWithoutIssueSql($orderId);
            $hadReservation = $this->orderHadReservationForClose();
            if (self::reopenOrderAfterIssueRemovalSql($orderId, $this->order_ref, $hadReservation)) {
                $prepared = true;
            }
            $this->reloadOrderFromGt();
            Logger::getInstance()->log(
                'api',
                'prepareOrderForIssueFromApi: cofnięto ZK 7/8 bez WZ do statusu 5 dla '
                    . $this->order_ref,
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        } elseif ($this->orderGt && self::isOrderStatusFulfilled((int) $this->state)
            && !$this->isZkFulfilledForApi()) {
            $prepared = $this->reopenOrderForIssueIfNeeded();
        }

        if ($this->orderGt && self::isOrderStatusOpen((int) $this->state)) {
            $this->ensureOrderReservationBeforeIssue();
        }

        return array(
            'prepared' => $prepared,
            'state' => (int) $this->state,
            'reservation' => (bool) $this->reservation,
        );
    }

    /**
     * Czy przy Order/update nie usuwać pozycji istniejących tylko w Subiekcie (ręczne zmiany / rezerwacja).
     *
     * @return bool
     */
    protected function shouldPreserveSubiektOnlyPositions()
    {
        if (!empty($this->orderDetail['force_sync_products'])) {
            return false;
        }

        return self::orderHasActiveReservationSql((int) $this->gt_id, $this->order_ref);
    }

    /**
     * Status docelowy po pełnej realizacji ZK: 7 z rezerwacją, 8 bez.
     *
     * @param bool $withReservation
     * @return int
     */
    public static function resolveOrderFulfilledTargetStatus($withReservation)
    {
        return $withReservation ? 7 : 8;
    }

    /**
     * Po pełnym pokryciu WZ — status 8 (pełny ptaszek w liście GT, rezerwacja zwolniona).
     *
     * @param bool $goodsCoverage
     * @param bool $hadReservation
     * @return int
     */
    public static function resolveOrderFulfilledTargetStatusAfterFullIssue($goodsCoverage, $hadReservation)
    {
        if ($goodsCoverage) {
            return 8;
        }

        return self::resolveOrderFulfilledTargetStatus($hadReservation);
    }

    /**
     * @param bool $withReservation
     * @return bool
     */
    public static function orderFulfilledStatusUsesReservation($targetStatus, $withReservation)
    {
        return (int) $targetStatus === 7 && $withReservation;
    }

    /**
     * Oznacza otwarte ZK jako częściowo zrealizowane (StatusEx bit 1) bez zmiany dok_Status 5/6.
     *
     * @param int $orderId
     * @return bool
     */
    public static function applyOrderPartialRealizationStatusInSql($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return false;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::applyOrderPartialRealizationStatus(
                OrderComWriter::resolveSubiektGt(),
                $orderId
            );
        }

        MSSql::getInstance()->query(
            "UPDATE dok__Dokument
             SET dok_StatusEx = (ISNULL(dok_StatusEx, 0) | 1)
             WHERE dok_Id = {$orderId}
               AND dok_Typ = 16
               AND dok_Status IN (5, 6)
               AND (ISNULL(dok_StatusEx, 0) & 4) = 0"
        );

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Status, dok_StatusEx FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );

        return is_array($rows)
            && !empty($rows)
            && self::isOrderStatusOpen((int) ($rows[0]['dok_Status'] ?? 0))
            && (((int) ($rows[0]['dok_StatusEx'] ?? 0)) & 1) !== 0;
    }

    /**
     * Pozostała ilość do realizacji — wyłącznie z SQL (dok_Pozycja + WZ), bez COM IloscZrealizowana.
     *
     * @param int $orderId
     * @param string $orderRef
     * @param bool $goodsOnly
     * @param array<int, string>|null $issueRefs
     * @return float
     */
    public static function getOrderRemainingQtyFromSql($orderId, $orderRef, $goodsOnly = false, array $issueRefs = null)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return 0.0;
        }

        if ($issueRefs === null) {
            $issueRefs = self::getIssueRefsForOrder($orderRef, $orderId);
        }

        $goodsFilter = $goodsOnly ? self::sqlWarehouseGoodsPositionsOnly('zk') : '';
        $goodsCoverage = self::isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef);
        $issuedByTowId = self::getIssuedGoodsQtyByTowIdFromIssueRefs($issueRefs);
        $issuedByTowIdMutable = $issuedByTowId;

        $statusRows = MSSql::getInstance()->query(
            "SELECT dok_Status FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );
        $orderStatus = is_array($statusRows) && !empty($statusRows)
            ? (int) ($statusRows[0]['dok_Status'] ?? 0)
            : 0;

        $rows = MSSql::getInstance()->query(
            "SELECT zk.ob_Id, zk.ob_TowId, zk.ob_Ilosc AS ordered, ISNULL(zk.ob_TowRodzaj, 1) AS tow_rodzaj,
                    ISNULL((
                        SELECT SUM(wz_p.ob_Ilosc)
                        FROM dok_Pozycja wz_p
                        INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId
                            AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                        WHERE wz_p.ob_DoId = zk.ob_Id
                    ), 0) AS issued_by_link
             FROM dok_Pozycja zk
             WHERE zk.ob_DokHanId = {$orderId}{$goodsFilter}"
        );
        if (!is_array($rows)) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($rows as $row) {
            $ordered = (float) ($row['ordered'] ?? 0);
            if ($ordered <= 0.00001) {
                continue;
            }

            $rodzaj = (int) ($row['tow_rodzaj'] ?? 1);
            if ($goodsOnly && self::isServicePositionKind($rodzaj)) {
                continue;
            }
            if (!$goodsOnly && self::isServicePositionKind($rodzaj)) {
                if ($goodsCoverage || self::isOrderStatusFulfilled($orderStatus)) {
                    continue;
                }
                $sum += $ordered;
                continue;
            }

            $issued = (float) ($row['issued_by_link'] ?? 0);
            $towId = (int) ($row['ob_TowId'] ?? 0);
            if ($issued <= 0.00001 && $towId > 0 && isset($issuedByTowIdMutable[$towId])) {
                $issued = min($ordered, (float) $issuedByTowIdMutable[$towId]);
                $issuedByTowIdMutable[$towId] -= $issued;
            }

            $sum += max(0.0, $ordered - $issued);
        }

        return $sum;
    }

    /**
     * Czy powiązane WZ pokrywają pozycje towarowe ZK (usługi pomijane — nie trafiają na WZ).
     *
     * @param int $orderId dok_Id ZK
     * @param string $orderRef numer ZK
     * @return bool
     */
    public static function isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || trim((string) $orderRef) === '') {
            return false;
        }

        $goodsFilter = self::sqlWarehouseGoodsPositionsOnly('zk');

        $positionSql = "SELECT zk.ob_Id, zk.ob_TowId, zk.ob_Ilosc AS ordered,
                               ISNULL(SUM(CASE WHEN wz.dok_Id IS NOT NULL THEN wz_p.ob_Ilosc ELSE 0 END), 0) AS issued
                        FROM dok_Pozycja zk
                        LEFT JOIN dok_Pozycja wz_p ON wz_p.ob_DoId = zk.ob_Id
                        LEFT JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId
                            AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                        WHERE zk.ob_DokHanId = {$orderId}{$goodsFilter}
                        GROUP BY zk.ob_Id, zk.ob_TowId, zk.ob_Ilosc";
        $positions = MSSql::getInstance()->query($positionSql);
        if (!is_array($positions) || empty($positions)) {
            if (!self::orderHasWarehouseGoodsPositions($orderId)) {
                foreach (self::getIssueRefsForOrder($orderRef, $orderId) as $issueRef) {
                    if (self::isIssueDocumentLinkedToOrder($issueRef, $orderId, $orderRef)) {
                        return true;
                    }
                }

                return !empty(self::findIssueRefsLinkedFromOrderDocumentSql($orderId));
            }

            return false;
        }

        $linkedViaDoId = true;
        $hasLinkedIssue = false;
        foreach ($positions as $row) {
            $ordered = (float) ($row['ordered'] ?? 0);
            $issued = (float) ($row['issued'] ?? 0);
            if ($issued > 0.00001) {
                $hasLinkedIssue = true;
            }
            if ($ordered - $issued > 0.00001) {
                $linkedViaDoId = false;
            }
        }
        if ($linkedViaDoId && $hasLinkedIssue) {
            return true;
        }

        if (self::isOrderTowQtyCoveredBySqlMaps(
            self::getOrderTowQtyMapFromRows(
                MSSql::getInstance()->query(
                    "SELECT zk.ob_TowId, SUM(zk.ob_Ilosc) AS qty
                     FROM dok_Pozycja zk
                     WHERE zk.ob_DokHanId = {$orderId}{$goodsFilter}
                     GROUP BY zk.ob_TowId"
                )
            ),
            self::getOrderTowQtyMapFromRows(
                MSSql::getInstance()->query(
                    "SELECT wp.ob_TowId, SUM(wp.ob_Ilosc) AS qty
                     FROM dok_Pozycja zk
                     INNER JOIN dok_Pozycja wp ON wp.ob_DoId = zk.ob_Id
                     INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId
                         AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                     WHERE zk.ob_DokHanId = {$orderId}{$goodsFilter}
                     GROUP BY wp.ob_TowId"
                )
            )
        )) {
            return true;
        }

        $orderedSql = "SELECT zk.ob_TowId, SUM(zk.ob_Ilosc) AS qty
                       FROM dok_Pozycja zk
                       WHERE zk.ob_DokHanId = {$orderId}{$goodsFilter}
                       GROUP BY zk.ob_TowId";
        $orderedRows = MSSql::getInstance()->query($orderedSql);

        $validatedIssueRefs = array();
        foreach (self::getIssueRefsForOrder($orderRef, $orderId) as $issueRef) {
            if (self::isIssueDocumentLinkedToOrder($issueRef, $orderId, $orderRef)) {
                $validatedIssueRefs[] = $issueRef;
            }
        }
        if (empty($validatedIssueRefs)) {
            return false;
        }

        $safeIssueRefs = array();
        foreach ($validatedIssueRefs as $issueRef) {
            $safeIssueRefs[] = "'" . str_replace("'", "''", $issueRef) . "'";
        }
        $issueInList = implode(', ', $safeIssueRefs);

        $issuedByNrSql = "SELECT wp.ob_TowId, SUM(wp.ob_Ilosc) AS qty
                          FROM dok__Dokument wz
                          INNER JOIN dok_Pozycja wp ON wp.ob_DokMagId = wz.dok_Id
                          WHERE wz.dok_Typ = 11 AND wz.dok_Status >= 0
                          AND wz.dok_NrPelny IN ({$issueInList})
                          GROUP BY wp.ob_TowId";
        $issuedByNrRows = MSSql::getInstance()->query($issuedByNrSql);
        if (!is_array($orderedRows) || empty($orderedRows) || !is_array($issuedByNrRows) || empty($issuedByNrRows)) {
            return false;
        }

        return self::isOrderTowQtyCoveredBySqlMaps(
            self::getOrderTowQtyMapFromRows($orderedRows),
            self::getOrderTowQtyMapFromRows($issuedByNrRows)
        );
    }

    /**
     * @param array|null $rows
     * @return array<int, float>
     */
    protected static function getOrderTowQtyMapFromRows($rows)
    {
        $map = array();
        if (!is_array($rows)) {
            return $map;
        }
        foreach ($rows as $row) {
            $map[(int) $row['ob_TowId']] = (float) $row['qty'];
        }
        return $map;
    }

    /**
     * @param array<int, float> $orderedByTow
     * @param array<int, float> $issuedByTow
     */
    protected static function isOrderTowQtyCoveredBySqlMaps(array $orderedByTow, array $issuedByTow)
    {
        if (empty($orderedByTow) || empty($issuedByTow)) {
            return false;
        }
        foreach ($orderedByTow as $towId => $ordered) {
            $issued = isset($issuedByTow[$towId]) ? $issuedByTow[$towId] : 0.0;
            if ($ordered - $issued > 0.00001) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return string|false 'com' | 'wz' gdy można domknąć ZK (tylko odczyt SQL do weryfikacji WZ), false gdy nie
     */
    protected function canCloseOrderAsFulfilled()
    {
        if (!$this->orderGt) {
            return false;
        }

        try {
            $this->orderGt->Przelicz();
        } catch (\Exception $e) {
        }

        if (self::sumRemainingToRealize($this->orderGt, true, (int) $this->gt_id, $this->order_ref) <= 0.00001) {
            if (!empty($this->getValidIssueRefsForOrder())) {
                return 'com';
            }

            return false;
        }

        if (self::isOrderFullyCoveredByLinkedIssuesSql((int) $this->gt_id, $this->order_ref)) {
            return 'wz';
        }

        return false;
    }

    /**
     * Czy ZK było / jest z rezerwacją stanów (dok_Status 5 lub 7, flaga COM).
     *
     * @param int|null $state
     * @return bool
     */
    public function orderHadReservationForClose($state = null)
    {
        $state = $state !== null ? (int) $state : (int) $this->state;
        if (in_array($state, array(5, 7), true)) {
            return true;
        }

        return (bool) ($this->orderGt->Rezerwacja ?? $this->reservation);
    }

    /**
     * Cofa status 7/8 → 5/6 w SQL (gdy COM Status niedostępny).
     *
     * @param int $orderId
     * @param bool $withReservation
     * @return bool
     */
    public static function reopenOrderStatusInSql($orderId, $withReservation = true)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return false;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::reopenOrderStatus(
                OrderComWriter::resolveSubiektGt(),
                $orderId,
                null,
                $withReservation
            );
        }

        $targetStatus = $withReservation ? 5 : 6;
        MSSql::getInstance()->query(
            "UPDATE dok__Dokument
             SET dok_Status = {$targetStatus},
                 dok_ZrealizowaneZRezerwacja = 0
             WHERE dok_Id = {$orderId}
               AND dok_Typ = 16
               AND dok_Status IN (7, 8)"
        );

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Status FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );

        return is_array($rows)
            && !empty($rows)
            && (int) ($rows[0]['dok_Status'] ?? 0) === $targetStatus;
    }

    /**
     * Dokumenty, które wskazują na WZ (np. FS) — blokują Anuluj/Usun w GT.
     *
     * @param int $wzId
     * @param int $excludeOrderId opcjonalnie pomiń to ZK (rodzic WZ)
     * @return array
     */
    public static function findBlockingDocumentsForIssueSql($wzId, $excludeOrderId = 0)
    {
        $wzId = (int) $wzId;
        if ($wzId <= 0) {
            return array();
        }

        $excludeOrderId = (int) $excludeOrderId;
        $excludeSql = $excludeOrderId > 0 ? " AND dok_Id <> {$excludeOrderId}" : '';

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_Typ, dok_Status
             FROM dok__Dokument
             WHERE dok_DoDokId = {$wzId}
               AND dok_Status >= 0
               AND dok_Typ <> 16{$excludeSql}
             ORDER BY dok_Id ASC"
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Wszystkie dokumenty wskazujące WZ jako źródło (dok_DoDokId), w tym ZK — do diagnostyki GT.
     *
     * @param int $wzId
     * @return array
     */
    public static function findAllDocumentsDerivedFromIssueSql($wzId)
    {
        $wzId = (int) $wzId;
        if ($wzId <= 0) {
            return array();
        }

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_Typ, dok_Status, dok_DoDokId, dok_DoDokNrPelny
             FROM dok__Dokument
             WHERE dok_DoDokId = {$wzId}
               AND dok_Status >= 0
             ORDER BY dok_Typ ASC, dok_Id ASC"
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Usuwa powiązania SQL między ZK a wskazanymi WZ (nagłówki, pozycje, ob_Powiazane).
     *
     * @param int $orderId
     * @param string $orderRef
     * @param array<int, string> $issueRefs
     * @return array{position_links:int, wz_header:int, zk_header:int, ob_powiazane:int, ob_dok_han:int}
     */
    public static function unlinkIssueFromOrderSql($orderId, $orderRef, array $issueRefs)
    {
        $orderId = (int) $orderId;
        $orderRef = trim((string) $orderRef);
        $stats = array(
            'position_links' => 0,
            'wz_header' => 0,
            'zk_header' => 0,
            'ob_powiazane' => 0,
            'ob_dok_han' => 0,
        );
        if ($orderId <= 0 || $orderRef === '' || empty($issueRefs)) {
            return $stats;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::unlinkIssueFromOrder(
                OrderComWriter::resolveSubiektGt(),
                $orderId,
                $orderRef,
                $issueRefs
            );
        }

        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef === '') {
                continue;
            }

            $wzRow = self::getIssueDocumentRowByRef($issueRef);
            if ($wzRow === null) {
                continue;
            }

            $wzId = (int) ($wzRow['dok_Id'] ?? 0);
            if ($wzId <= 0) {
                continue;
            }

            $safeIssueRef = str_replace("'", "''", $issueRef);

            MSSql::getInstance()->query(
                "UPDATE wp
                 SET wp.ob_DoId = NULL
                 FROM dok_Pozycja wp
                 INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
                 WHERE wp.ob_DokMagId = {$wzId}"
            );

            MSSql::getInstance()->query(
                "UPDATE dok_Pozycja
                 SET ob_DokHanId = NULL
                 WHERE ob_DokMagId = {$wzId}
                   AND ob_DokHanId = {$orderId}"
            );

            MSSql::getInstance()->query(
                "UPDATE dok__Dokument
                 SET dok_DoDokId = NULL,
                     dok_DoDokNrPelny = '',
                     dok_NrPelnyOryg = ''
                 WHERE dok_Id = {$wzId}
                   AND dok_Typ = 11"
            );

            MSSql::getInstance()->query(
                "UPDATE dok__Dokument
                 SET " . self::sqlSetClearedDocumentLinkFields() . "
                 WHERE dok_Id = {$orderId}
                   AND dok_Typ = 16
                   AND (dok_DoDokId = {$wzId}
                        OR LTRIM(RTRIM(ISNULL(dok_DoDokNrPelny, ''))) = '{$safeIssueRef}')"
            );

            MSSql::getInstance()->query(
                "DELETE FROM ob_Powiazane
                 WHERE (op_TypOb = 921 AND op_IdOb = {$orderId}
                        AND op_TypWskazywanego = 918 AND op_IdWskazywanego = {$wzId})
                    OR (op_TypOb = 918 AND op_IdOb = {$wzId}
                        AND op_TypWskazywanego = 921 AND op_IdWskazywanego = {$orderId})"
            );
        }

        $safeIssueRefs = array();
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef !== '') {
                $safeIssueRefs[] = "'" . str_replace("'", "''", $issueRef) . "'";
            }
        }
        if (!empty($safeIssueRefs)) {
            $issueInList = implode(', ', $safeIssueRefs);
            $linkedRows = MSSql::getInstance()->query(
                "SELECT COUNT(*) AS cnt
                 FROM dok_Pozycja wp
                 INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
                 INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
                 WHERE wz.dok_NrPelny IN ({$issueInList})"
            );
            $stats['position_links'] = is_array($linkedRows) && !empty($linkedRows)
                ? (int) ($linkedRows[0]['cnt'] ?? 0)
                : 0;
        }

        return $stats;
    }

    /**
     * Czy po odpięciu WZ ZK nadal ma jakiekolwiek powiązanie z WZ w bazie.
     *
     * @param int $orderId
     * @param string $orderRef
     * @return bool
     */
    public static function orderHasActiveIssueLinksSql($orderId, $orderRef)
    {
        return !empty(self::getIssueRefsForOrder($orderRef, (int) $orderId));
    }

    /**
     * Otwiera ZK (5/6) po usunięciu ostatniego WZ i czyści nagłówek realizacji.
     *
     * @param int $orderId
     * @param string $orderRef
     * @param bool $withReservation
     * @return bool
     */
    public static function reopenOrderAfterIssueRemovalSql($orderId, $orderRef, $withReservation = true)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || self::orderHasActiveIssueLinksSql($orderId, $orderRef)) {
            return false;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::reopenOrderAfterIssueRemoval(
                OrderComWriter::resolveSubiektGt(),
                $orderId,
                $orderRef,
                $withReservation
            );
        }

        $targetStatus = $withReservation ? 5 : 6;
        MSSql::getInstance()->query(
            "UPDATE dok__Dokument
             SET " . self::sqlSetClearedDocumentLinkFields() . ",
                 dok_Status = CASE WHEN dok_Status IN (7, 8) THEN {$targetStatus} ELSE dok_Status END,
                 dok_StatusEx = ISNULL(dok_StatusEx, 0) & ~4,
                 dok_ZrealizowaneZRezerwacja = 0
             WHERE dok_Id = {$orderId}
               AND dok_Typ = 16"
        );

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Status, dok_DoDokId FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );

        return is_array($rows)
            && !empty($rows)
            && empty($rows[0]['dok_DoDokId'])
            && self::isOrderStatusOpen((int) ($rows[0]['dok_Status'] ?? 0));
    }

    /**
     * Anuluj lub usuń WZ przez COM (po odpięciu SQL).
     *
     * @param string $issueRef
     * @return string
     */
    public function removeIssueDocumentViaCom($issueRef)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '' || !$this->subiektGt) {
            return 'skipped';
        }

        try {
            if (!$this->subiektGt->SuDokumentyManager->Istnieje($issueRef)) {
                return 'not_found';
            }

            $issueDoc = $this->subiektGt->SuDokumentyManager->Wczytaj($issueRef);
            try {
                $issueDoc->Anuluj();
                return 'cancelled';
            } catch (\Exception $e) {
                try {
                    $issueDoc->Usun(false);
                    return 'deleted';
                } catch (\Exception $e2) {
                    Logger::getInstance()->log(
                        'api',
                        'removeIssueDocumentViaCom: ' . $issueRef . ': ' . $e2->getMessage(),
                        __CLASS__ . '->' . __FUNCTION__,
                        __LINE__
                    );
                    return 'failed: ' . $e2->getMessage();
                }
            }
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'removeIssueDocumentViaCom: ' . $issueRef . ': ' . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            return 'failed: ' . $e->getMessage();
        }
    }

    /**
     * Odpina błędne WZ od ZK, otwiera ZK i usuwa WZ (COM).
     *
     * @param array<int, string> $issueRefs puste = wszystkie WZ powiązane z ZK
     * @param bool $removeViaCom
     * @return array
     */
    public function resetIssuesAndReopenOrder(array $issueRefs = array(), $removeViaCom = true)
    {
        if (!$this->is_exists || !$this->orderGt) {
            return array(
                'order_ref' => $this->order_ref,
                'state' => 'error',
                'message' => 'ZK nie istnieje',
            );
        }

        $orderId = (int) $this->gt_id;
        $hadReservation = $this->orderHadReservationForClose();
        if (empty($issueRefs)) {
            $issueRefs = self::getIssueRefsForOrder($this->order_ref, $orderId);
        }

        $issueRefs = array_values(array_unique(array_filter(array_map('trim', $issueRefs))));
        if (empty($issueRefs)) {
            return array(
                'order_ref' => $this->order_ref,
                'state' => 'noop',
                'message' => 'Brak WZ powiązanych z tym ZK',
                'state_before' => (int) $this->state,
            );
        }

        $issueResults = array();
        foreach ($issueRefs as $issueRef) {
            $wzRow = self::getIssueDocumentRowByRef($issueRef);
            $wzId = $wzRow !== null ? (int) ($wzRow['dok_Id'] ?? 0) : 0;
            $blocking = $wzId > 0 ? self::findBlockingDocumentsForIssueSql($wzId, $orderId) : array();
            if (OrderComWriter::comWritesOnly()) {
                $unlinkStats = OrderComWriter::unlinkIssueFromOrder(
                    $this->subiektGt,
                    $orderId,
                    $this->order_ref,
                    array($issueRef)
                );
            } else {
                $unlinkStats = self::unlinkIssueFromOrderSql($orderId, $this->order_ref, array($issueRef));
            }
            $comAction = 'skipped';
            if ($removeViaCom && empty($blocking)) {
                $comAction = $this->removeIssueDocumentViaCom($issueRef);
            } elseif ($removeViaCom && !empty($blocking)) {
                $comAction = 'blocked_by_linked_document';
            }

            $issueResults[] = array(
                'issue_ref' => $issueRef,
                'wz_id' => $wzId,
                'unlink_remaining_position_links' => (int) ($unlinkStats['position_links'] ?? 0),
                'blocking_documents' => $blocking,
                'com_action' => $comAction,
            );
        }

        if (OrderComWriter::comWritesOnly()) {
            $reopened = OrderComWriter::reopenOrderAfterIssueRemoval(
                $this->subiektGt,
                $orderId,
                $this->order_ref,
                $hadReservation
            );
        } else {
            $reopened = self::reopenOrderAfterIssueRemovalSql($orderId, $this->order_ref, $hadReservation);
        }
        $this->reloadOrderFromGt();

        if ($this->orderGt && self::isOrderStatusOpen((int) $this->state)) {
            try {
                $this->orderGt->Rezerwacja = $hadReservation;
                $this->orderGt->Przelicz();
                $this->orderGt->Zapisz();
                $this->reloadOrderFromGt();
            } catch (\Exception $e) {
                Logger::getInstance()->log(
                    'api',
                    'resetIssuesAndReopenOrder: zapis ZK po reopen: ' . $e->getMessage(),
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
            }
        }

        return array(
            'order_ref' => $this->order_ref,
            'state' => 'success',
            'reopened_sql' => $reopened,
            'write_path' => OrderComWriter::comWritesOnly() ? 'com' : 'sql',
            'state_after' => (int) $this->state,
            'status_label' => self::getOrderStatusLabel((int) $this->state),
            'reservation' => (bool) ($this->orderGt->Rezerwacja ?? $this->reservation),
            'issues' => $issueResults,
            'remaining_issue_refs' => self::getIssueRefsForOrder($this->order_ref, $orderId),
        );
    }

    /**
     * Odpina WZ od ZK i otwiera ZK — wyłącznie SQL (bez Sfery COM).
     *
     * @param string $orderRef
     * @param array<int, string> $issueRefs
     * @return array
     */
    public static function resetIssuesAndReopenOrderSql($orderRef, array $issueRefs = array())
    {
        $orderRef = trim((string) $orderRef);
        $zkRow = self::getOrderRowByRefSql($orderRef);
        if ($zkRow === null) {
            return array(
                'order_ref' => $orderRef,
                'state' => 'error',
                'message' => 'Nie znaleziono ZK w bazie',
            );
        }

        $orderId = (int) ($zkRow['dok_Id'] ?? 0);
        $stateBefore = (int) ($zkRow['dok_Status'] ?? 0);
        $hadReservation = in_array($stateBefore, array(5, 7), true);

        if (empty($issueRefs)) {
            $issueRefs = self::getIssueRefsForOrder($orderRef, $orderId);
        }
        $issueRefs = array_values(array_unique(array_filter(array_map('trim', $issueRefs))));

        if (empty($issueRefs)) {
            return array(
                'order_ref' => $orderRef,
                'state' => 'noop',
                'message' => 'Brak WZ powiązanych z tym ZK',
                'state_before' => $stateBefore,
                'status_label' => self::getOrderStatusLabel($stateBefore),
            );
        }

        $issueResults = array();
        $anyUnlinked = false;
        foreach ($issueRefs as $issueRef) {
            $wzRow = self::getIssueDocumentRowByRef($issueRef);
            $wzId = $wzRow !== null ? (int) ($wzRow['dok_Id'] ?? 0) : 0;
            $blocking = $wzId > 0 ? self::findBlockingDocumentsForIssueSql($wzId, $orderId) : array();
            if ($wzId > 0) {
                self::unlinkIssueFromOrderSql($orderId, $orderRef, array($issueRef));
                $anyUnlinked = true;
            }
            $issueResults[] = array(
                'issue_ref' => $issueRef,
                'wz_id' => $wzId,
                'blocking_documents' => $blocking,
                'note' => $wzId <= 0
                    ? 'Nie znaleziono WZ — podaj pełny numer (np. WZ 3016/06/2026), nie samą sekwencję.'
                    : (empty($blocking)
                        ? 'Powiązania SQL usunięte — WZ usuń ręcznie w GT (bez Sfery).'
                        : 'Odpięto SQL, ale inny dokument blokuje usunięcie WZ w GT.'),
            );
        }

        if (!$anyUnlinked) {
            return array(
                'order_ref' => $orderRef,
                'state' => 'error',
                'message' => 'Nie odpięto żadnego WZ — sprawdź numer dokumentu (np. WZ 3016/06/2026).',
                'issues' => $issueResults,
                'remaining_issue_refs' => self::getIssueRefsForOrder($orderRef, $orderId),
            );
        }

        $reopened = self::reopenOrderAfterIssueRemovalSql($orderId, $orderRef, $hadReservation);
        $zkAfter = self::getOrderRowByRefSql($orderRef);
        $stateAfter = $zkAfter !== null ? (int) ($zkAfter['dok_Status'] ?? 0) : $stateBefore;

        return array(
            'order_ref' => $orderRef,
            'state' => 'success',
            'mode' => OrderComWriter::comWritesOnly() ? 'com' : 'sql_only',
            'reopened_sql' => $reopened,
            'write_path' => OrderComWriter::comWritesOnly() ? 'com' : 'sql',
            'state_before' => $stateBefore,
            'state_after' => $stateAfter,
            'status_label' => self::getOrderStatusLabel($stateAfter),
            'issues' => $issueResults,
            'remaining_issue_refs' => self::getIssueRefsForOrder($orderRef, $orderId),
            'message' => OrderComWriter::comWritesOnly()
                ? 'Odpięto WZ od ZK przez COM. Odśwież listę ZK/WZ w GT (F5).'
                : 'Odpięto WZ od ZK w SQL. Zamknij okna ZK i WZ w GT (F5 / ponowne wejście), potem usuń WZ.',
        );
    }

    /**
     * Wycofaj WZ w SQL (dok_Status = 0) — gdy GT nie pozwala usunąć, ale odpięcie już zadziałało.
     *
     * @param string $issueRef
     * @return bool
     */
    public static function withdrawIssueDocumentSql($issueRef)
    {
        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::withdrawIssueDocument(
                OrderComWriter::resolveSubiektGt(),
                $issueRef
            );
        }

        $wzRow = self::getIssueDocumentRowByRef($issueRef);
        if ($wzRow === null) {
            return false;
        }

        $wzId = (int) ($wzRow['dok_Id'] ?? 0);
        if ($wzId <= 0) {
            return false;
        }

        MSSql::getInstance()->query(
            "UPDATE dok__Dokument
             SET dok_Status = 0
             WHERE dok_Id = {$wzId} AND dok_Typ = 11 AND dok_Status > 0"
        );

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Status FROM dok__Dokument WHERE dok_Id = {$wzId} AND dok_Typ = 11"
        );

        return is_array($rows) && !empty($rows) && (int) ($rows[0]['dok_Status'] ?? -1) === 0;
    }

    /**
     * Przygotowuje WZ do wystawienia FS w GT (Operacje → Wypisz fakturę zwykłą).
     * Cofa nagłówek ZK→WZ i otwiera ZK — zostawia ob_DoId i WZ.dok_NrPelnyOryg.
     *
     * @param string $orderRef
     * @param array<int, string> $issueRefs
     * @return array
     */
    public static function prepareIssueForInvoicingSql($orderRef, array $issueRefs = array())
    {
        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::prepareIssueForInvoicing(
                OrderComWriter::resolveSubiektGt(),
                $orderRef,
                $issueRefs
            );
        }

        $orderRef = trim((string) $orderRef);
        $zkRow = self::getOrderRowByRefSql($orderRef);
        if ($zkRow === null) {
            return array('order_ref' => $orderRef, 'state' => 'error', 'message' => 'Nie znaleziono ZK');
        }

        $orderId = (int) ($zkRow['dok_Id'] ?? 0);
        $stateBefore = (int) ($zkRow['dok_Status'] ?? 0);
        $hadReservation = in_array($stateBefore, array(5, 7), true);

        if (empty($issueRefs)) {
            $issueRefs = self::getIssueRefsForOrder($orderRef, $orderId);
        }
        $issueRefs = array_values(array_unique(array_filter(array_map('trim', $issueRefs))));

        if (empty($issueRefs)) {
            return array(
                'order_ref' => $orderRef,
                'state' => 'error',
                'message' => 'Brak WZ powiązanych z tym ZK',
            );
        }

        $results = array();
        foreach ($issueRefs as $issueRef) {
            $wzRow = self::getIssueDocumentRowByRef($issueRef);
            if ($wzRow === null) {
                $results[] = array(
                    'issue_ref' => $issueRef,
                    'wz_id' => 0,
                    'error' => 'Nie znaleziono WZ w bazie — podaj pełny numer (np. WZ 3013/06/2026)',
                );
                continue;
            }

            $wzId = (int) ($wzRow['dok_Id'] ?? 0);
            $safeIssueRef = str_replace("'", "''", $issueRef);

            MSSql::getInstance()->query(
                "UPDATE dok__Dokument
                 SET " . self::sqlSetClearedDocumentLinkFields() . "
                 WHERE dok_Id = {$orderId} AND dok_Typ = 16
                   AND (dok_DoDokId = {$wzId}
                        OR LTRIM(RTRIM(ISNULL(dok_DoDokNrPelny, ''))) = '{$safeIssueRef}')"
            );

            MSSql::getInstance()->query(
                "UPDATE dok__Dokument
                 SET dok_DoDokId = NULL
                 WHERE dok_Id = {$wzId} AND dok_Typ = 11"
            );

            $results[] = array(
                'issue_ref' => $issueRef,
                'wz_id' => $wzId,
                'zk_header_cleared' => true,
                'wz_dok_dok_id_cleared' => true,
            );
        }

        $anyFound = false;
        foreach ($results as $row) {
            if ((int) ($row['wz_id'] ?? 0) > 0) {
                $anyFound = true;
                break;
            }
        }
        if (!$anyFound) {
            return array(
                'order_ref' => $orderRef,
                'state' => 'error',
                'message' => 'Nie znaleziono WZ — użyj pełnego numeru dokumentu',
                'issues' => $results,
            );
        }

        $reopened = self::reopenOrderStatusInSql($orderId, $hadReservation);
        self::applyOrderPartialRealizationStatusInSql($orderId);

        $zkAfter = self::getOrderRowByRefSql($orderRef);
        $stateAfter = $zkAfter !== null ? (int) ($zkAfter['dok_Status'] ?? 0) : $stateBefore;

        return array(
            'order_ref' => $orderRef,
            'state' => 'success',
            'state_before' => $stateBefore,
            'state_after' => $stateAfter,
            'status_label' => self::getOrderStatusLabel($stateAfter),
            'reopened_sql' => $reopened,
            'issues' => $results,
            'message' => 'ZK otwarte, nagłówek ZK→WZ wyczyszczony. W GT: Magazyn → WZ → Operacje → Wypisz fakturę zwykłą.',
        );
    }

    /**
     * Czy WZ ma już powiązaną fakturę FS.
     *
     * @param int $wzId
     * @return bool
     */
    public static function issueHasLinkedInvoiceSql($wzId)
    {
        $wzId = (int) $wzId;
        if ($wzId <= 0) {
            return false;
        }

        $rows = MSSql::getInstance()->query(
            "SELECT TOP 1 fs.dok_Id
             FROM dok__Dokument wz
             INNER JOIN dok__Dokument fs ON fs.dok_Typ = 2 AND fs.dok_Status >= 0
                 AND (fs.dok_DoDokId = wz.dok_Id OR wz.dok_DoDokId = fs.dok_Id)
             WHERE wz.dok_Id = {$wzId} AND wz.dok_Typ = 11"
        );

        return is_array($rows) && !empty($rows[0]['dok_Id']);
    }

    /**
     * Diagnostyka WZ pod „Wypisz fakturę zwykłą” (błąd dokumentu automatycznego).
     *
     * @param string $issueRef
     * @return array
     */
    public static function diagnoseIssueForInvoicingSql($issueRef)
    {
        $issueRef = trim((string) $issueRef);
        $wzRow = self::getIssueDocumentRowByRef($issueRef);
        if ($wzRow === null) {
            return array(
                'state' => 'not_found',
                'issue_ref' => $issueRef,
                'message' => 'Nie znaleziono WZ.',
            );
        }

        $wzId = (int) ($wzRow['dok_Id'] ?? 0);
        $orderId = self::resolveOrderIdForIssueSql($issueRef);
        $orderRef = '';
        $zkRow = null;
        if ($orderId > 0) {
            $zkRow = self::getOrderRowByIdSql($orderId);
            $orderRef = $zkRow !== null ? trim((string) ($zkRow['dok_NrPelny'] ?? '')) : '';
        }

        $targetRow = null;
        $doDokId = (int) ($wzRow['dok_DoDokId'] ?? 0);
        if ($doDokId > 0) {
            $rows = MSSql::getInstance()->query(
                "SELECT dok_Id, dok_NrPelny, dok_Typ, dok_Status FROM dok__Dokument WHERE dok_Id = {$doDokId}"
            );
            $targetRow = is_array($rows) && !empty($rows) ? $rows[0] : null;
        }

        $hasFs = self::issueHasLinkedInvoiceSql($wzId);
        $wzPointsToZk = $targetRow !== null && (int) ($targetRow['dok_Typ'] ?? 0) === 16;
        $zkHeaderToWz = $zkRow !== null
            && (int) ($zkRow['dok_DoDokId'] ?? 0) === $wzId;
        $wzPodtyp = (int) ($wzRow['dok_Podtyp'] ?? 0);
        $isWzaPodtyp = $wzPodtyp === 1;

        $issues = array();
        if ($hasFs) {
            $issues[] = 'WZ ma już powiązaną FS — nie trzeba wystawiać faktury.';
        }
        if ($wzPointsToZk) {
            $issues[] = 'WZ.dok_DoDokId wskazuje ZK (GT traktuje WZ jako automatyczny).';
        }
        if ($zkHeaderToWz) {
            $issues[] = 'ZK ma nagłówek realizacji do tego WZ (dok_DoDokId / dok_DoDokNrPelny).';
        }
        if ($isWzaPodtyp) {
            $issues[] = 'WZ ma podtyp automatyczny (WZa, dok_Podtyp=1).';
        }

        $needsPrepare = !$hasFs && ($wzPointsToZk || $zkHeaderToWz);

        return array(
            'state' => 'success',
            'issue_ref' => (string) ($wzRow['dok_NrPelny'] ?? $issueRef),
            'wz' => $wzRow,
            'zk_ref' => $orderRef,
            'zk' => $zkRow,
            'wz_do_dok' => $targetRow,
            'has_fs' => $hasFs,
            'wz_points_to_zk' => $wzPointsToZk,
            'zk_header_to_wz' => $zkHeaderToWz,
            'is_wza_podtyp' => $isWzaPodtyp,
            'needs_prepare' => $needsPrepare,
            'issues' => $issues,
            'ready_for_invoice' => !$hasFs && !$needsPrepare && !$isWzaPodtyp,
            'message' => $needsPrepare
                ? 'Użyj „Przygotuj do faktury” — cofnie powiązanie ZK→WZ w nagłówku.'
                : ($hasFs ? 'Faktura już wystawiona.' : 'WZ wygląda na gotowe do fakturowania.'),
        );
    }

    /**
     * @param int $orderId
     * @return array|null
     */
    public static function getOrderRowByIdSql($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return null;
        }

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_Status, dok_StatusEx, dok_DoDokId, dok_DoDokNrPelny, dok_Podtyp
             FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );

        return is_array($rows) && !empty($rows) ? $rows[0] : null;
    }

    /**
     * Skan WZ bez FS, które blokują „Wypisz fakturę zwykłą”.
     *
     * @param int $month
     * @param int $year
     * @param array{only_needs_prepare?:bool} $options
     * @return array
     */
    public static function scanIssuesForInvoicingMonthSql($month, $year, array $options = array())
    {
        $range = self::buildMonthDateRange($month, $year);
        $start = $range['start'];
        $end = $range['end'];

        $rows = MSSql::getInstance()->query(
            "SELECT wz.dok_Id AS wz_id,
                    wz.dok_NrPelny AS wz_ref,
                    wz.dok_DoDokId,
                    wz.dok_NrPelnyOryg,
                    wz.dok_Podtyp,
                    wz.dok_WartNetto,
                    wz.dok_DataWyst,
                    tgt.dok_Typ AS do_typ,
                    tgt.dok_NrPelny AS do_ref,
                    zk.dok_NrPelny AS zk_ref,
                    zk.dok_Status AS zk_status,
                    zk.dok_DoDokId AS zk_to_wz
             FROM dok__Dokument wz
             LEFT JOIN dok__Dokument tgt ON tgt.dok_Id = wz.dok_DoDokId
             OUTER APPLY (
                 SELECT TOP 1 zk2.dok_NrPelny, zk2.dok_Status, zk2.dok_DoDokId
                 FROM dok__Dokument zk2
                 WHERE zk2.dok_Typ = 16 AND zk2.dok_Status >= 0
                   AND (zk2.dok_DoDokId = wz.dok_Id
                        OR LTRIM(RTRIM(ISNULL(zk2.dok_NrPelny, ''))) = LTRIM(RTRIM(ISNULL(wz.dok_NrPelnyOryg, '')))
                        OR zk2.dok_Id = wz.dok_DoDokId)
                 ORDER BY zk2.dok_Id DESC
             ) zk
             WHERE wz.dok_Typ = 11
               AND wz.dok_Status = 1
               AND wz.dok_DataWyst >= '{$start}'
               AND wz.dok_DataWyst < '{$end}'
               AND NOT EXISTS (
                   SELECT 1 FROM dok__Dokument fs
                   WHERE fs.dok_Typ = 2 AND fs.dok_Status >= 0
                     AND (fs.dok_DoDokId = wz.dok_Id OR wz.dok_DoDokId = fs.dok_Id)
               )
             ORDER BY wz.dok_NrPelny"
        );
        $rows = is_array($rows) ? $rows : array();

        $issues = array();
        $summary = array(
            'total_wz' => 0,
            'needs_prepare' => 0,
            'ready' => 0,
            'wza_podtyp' => 0,
        );

        foreach ($rows as $row) {
            $wzPointsToZk = (int) ($row['do_typ'] ?? 0) === 16;
            $zkHeaderToWz = (int) ($row['zk_to_wz'] ?? 0) === (int) ($row['wz_id'] ?? 0);
            $isWzaPodtyp = (int) ($row['dok_Podtyp'] ?? 0) === 1;
            $needsPrepare = $wzPointsToZk || $zkHeaderToWz;

            $flags = array();
            if ($wzPointsToZk) {
                $flags[] = 'WZ→ZK';
            }
            if ($zkHeaderToWz) {
                $flags[] = 'ZK→WZ';
            }
            if ($isWzaPodtyp) {
                $flags[] = 'WZa podtyp';
            }

            $entry = array(
                'wz_ref' => (string) ($row['wz_ref'] ?? ''),
                'wz_id' => (int) ($row['wz_id'] ?? 0),
                'zk_ref' => trim((string) ($row['zk_ref'] ?? '')),
                'zk_status' => $row['zk_status'] ?? null,
                'wz_do_ref' => trim((string) ($row['do_ref'] ?? '')),
                'wz_oryg' => trim((string) ($row['dok_NrPelnyOryg'] ?? '')),
                'needs_prepare' => $needsPrepare,
                'is_wza_podtyp' => $isWzaPodtyp,
                'ready_for_invoice' => !$needsPrepare && !$isWzaPodtyp,
                'flags' => $flags,
            );

            $summary['total_wz']++;
            if ($needsPrepare) {
                $summary['needs_prepare']++;
            }
            if ($entry['ready_for_invoice']) {
                $summary['ready']++;
            }
            if ($isWzaPodtyp) {
                $summary['wza_podtyp']++;
            }

            if (!empty($options['only_needs_prepare']) && !$needsPrepare) {
                continue;
            }

            $issues[] = $entry;
        }

        return array(
            'state' => 'success',
            'month' => $range['month'],
            'year' => $range['year'],
            'period' => $start . ' — ' . $end,
            'summary' => $summary,
            'issues' => $issues,
            'message' => 'WZ bez FS: ' . $summary['total_wz']
                . ', blokuje fakturę: ' . $summary['needs_prepare'] . '.',
        );
    }

    /**
     * Naprawa WZ pod fakturowanie (prepareIssueForInvoicingSql).
     *
     * @param string $issueRef
     * @param bool $apply
     * @return array
     */
    public static function repairIssueForInvoicingSql($issueRef, $apply = true)
    {
        $diag = self::diagnoseIssueForInvoicingSql($issueRef);
        if (($diag['state'] ?? '') !== 'success') {
            return $diag;
        }

        if (!empty($diag['has_fs'])) {
            return array_merge($diag, array(
                'state' => 'noop',
                'applied' => false,
                'message' => 'WZ ma już FS — pominięto.',
            ));
        }

        if (empty($diag['needs_prepare'])) {
            return array_merge($diag, array(
                'state' => 'noop',
                'applied' => false,
                'message' => 'WZ nie wymaga przygotowania pod fakturę.',
            ));
        }

        $orderRef = trim((string) ($diag['zk_ref'] ?? ''));
        if ($orderRef === '') {
            return array(
                'state' => 'error',
                'issue_ref' => (string) ($diag['issue_ref'] ?? $issueRef),
                'message' => 'Nie udało się ustalić ZK dla tego WZ.',
            );
        }

        if (!$apply) {
            return array_merge($diag, array(
                'state' => 'preview',
                'applied' => false,
                'would_run' => 'prepareIssueForInvoicingSql',
                'order_ref' => $orderRef,
                'message' => 'Podgląd — użyje „Przygotuj do faktury” dla ' . $orderRef . '.',
            ));
        }

        $result = self::prepareIssueForInvoicingSql($orderRef, array((string) ($diag['issue_ref'] ?? $issueRef)));
        $result['issue_ref'] = (string) ($diag['issue_ref'] ?? $issueRef);
        $result['applied'] = true;

        return $result;
    }

    /**
     * Masowa naprawa WZ pod fakturowanie w miesiącu.
     *
     * @param int $month
     * @param int $year
     * @param bool $apply
     * @param array $options
     * @return array
     */
    public static function batchRepairIssuesForInvoicingMonthSql($month, $year, $apply = false, array $options = array())
    {
        $scan = self::scanIssuesForInvoicingMonthSql($month, $year, array(
            'only_needs_prepare' => true,
        ));
        $targets = isset($scan['issues']) && is_array($scan['issues']) ? $scan['issues'] : array();

        if (empty($targets)) {
            return array(
                'state' => 'noop',
                'month' => (int) $month,
                'year' => (int) $year,
                'applied' => false,
                'summary' => $scan['summary'] ?? array(),
                'message' => 'Brak WZ wymagających przygotowania w tym miesiącu.',
            );
        }

        $byZk = array();
        foreach ($targets as $row) {
            $zkRef = trim((string) ($row['zk_ref'] ?? ''));
            $wzRef = trim((string) ($row['wz_ref'] ?? ''));
            if ($zkRef === '' || $wzRef === '') {
                continue;
            }
            if (!isset($byZk[$zkRef])) {
                $byZk[$zkRef] = array();
            }
            $byZk[$zkRef][] = $wzRef;
        }

        if (!$apply) {
            return array(
                'state' => 'preview',
                'month' => (int) $month,
                'year' => (int) $year,
                'applied' => false,
                'would_fix_wz' => count($targets),
                'would_fix_zk' => count($byZk),
                'groups' => $byZk,
                'summary' => $scan['summary'] ?? array(),
                'message' => 'Podgląd — do przygotowania: ' . count($targets) . ' WZ (' . count($byZk) . ' ZK).',
            );
        }

        $results = array();
        $ok = 0;
        $errors = 0;
        foreach ($byZk as $zkRef => $wzRefs) {
            $wzRefs = array_values(array_unique($wzRefs));
            $one = self::prepareIssueForInvoicingSql($zkRef, $wzRefs);
            $state = (string) ($one['state'] ?? 'error');
            if ($state === 'success') {
                $ok += count($wzRefs);
            } else {
                $errors += count($wzRefs);
            }
            $results[] = array(
                'zk_ref' => $zkRef,
                'wz_refs' => $wzRefs,
                'state' => $state,
                'message' => $one['message'] ?? '',
            );
        }

        $after = self::scanIssuesForInvoicingMonthSql($month, $year);

        return array(
            'state' => $errors > 0 ? 'partial' : 'success',
            'month' => (int) $month,
            'year' => (int) $year,
            'applied' => true,
            'fixed_wz' => $ok,
            'errors' => $errors,
            'results' => $results,
            'summary_before' => $scan['summary'] ?? array(),
            'summary_after' => $after['summary'] ?? array(),
            'message' => 'Przygotowano ' . $ok . ' WZ' . ($errors > 0 ? ', błędów: ' . $errors : '')
                . '. W GT: Magazyn → WZ → Wypisz fakturę zwykłą.',
        );
    }

    /**
     * Diagnostyka ZK↔WZ (SQL, bez Sfery).
     *
     * @param string $orderRef
     * @param string|null $issueRef
     * @return array
     */
    public static function diagnoseOrderIssueLinkSql($orderRef, $issueRef = null)
    {
        $orderRef = trim((string) $orderRef);
        $zkRow = self::getOrderRowByRefSql($orderRef);
        if ($zkRow === null) {
            return array('order_ref' => $orderRef, 'state' => 'not_found');
        }

        $orderId = (int) ($zkRow['dok_Id'] ?? 0);
        $issueRefs = self::getIssueRefsForOrder($orderRef, $orderId);
        if ($issueRef !== null && trim((string) $issueRef) !== '') {
            $issueRef = trim((string) $issueRef);
            if (!in_array($issueRef, $issueRefs, true)) {
                $issueRefs[] = $issueRef;
            }
        }

        $issues = array();
        foreach ($issueRefs as $ref) {
            $wzRow = self::getIssueDocumentRowByRef($ref);
            $wzId = $wzRow !== null ? (int) ($wzRow['dok_Id'] ?? 0) : 0;
            $positions = array();
            if ($wzId > 0) {
                $positions = MSSql::getInstance()->query(
                    "SELECT ob_Id, ob_DoId, ob_TowId, ob_Ilosc, ob_CenaNetto, ob_WartNetto, ob_WartMag
                     FROM dok_Pozycja WHERE ob_DokMagId = {$wzId}"
                );
            }
            $issues[] = array(
                'issue_ref' => $ref,
                'wz' => $wzRow,
                'blocking_documents' => $wzId > 0
                    ? self::findBlockingDocumentsForIssueSql($wzId, $orderId)
                    : array(),
                'derived_documents' => $wzId > 0
                    ? self::findAllDocumentsDerivedFromIssueSql($wzId)
                    : array(),
                'positions' => is_array($positions) ? $positions : array(),
            );
        }

        $remainingGoods = self::getOrderRemainingQtyFromSql($orderId, $orderRef, true, $issueRefs);
        $coverage = self::isOrderFullyCoveredByLinkedIssuesSql($orderId, $orderRef);

        return array(
            'state' => 'success',
            'order_ref' => $orderRef,
            'zk' => $zkRow,
            'status_label' => self::getOrderStatusLabel((int) ($zkRow['dok_Status'] ?? 0)),
            'status_ex_label' => self::getOrderStatusExLabel((int) ($zkRow['dok_StatusEx'] ?? 0)),
            'issue_refs' => $issueRefs,
            'issues' => $issues,
            'coverage_complete' => $coverage,
            'remaining_qty_goods' => $remainingGoods,
        );
    }

    /**
     * Pełny numer FS z sekwencji (np. 2645 → FS 2645/06/2026).
     *
     * @param string|int $sequence
     * @param int|null $month
     * @param int|null $year
     * @return string
     */
    public static function buildSalesInvoiceRefFromSequence($sequence, $month = null, $year = null)
    {
        $ref = trim((string) $sequence);
        if ($ref === '') {
            return '';
        }
        if (preg_match('/^FS\s/i', $ref)) {
            return $ref;
        }

        $month = $month !== null ? (int) $month : (int) date('n');
        $year = $year !== null ? (int) $year : (int) date('Y');
        $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        return 'FS ' . $ref . '/' . $monthStr . '/' . $year;
    }

    /**
     * @param string $invoiceRef
     * @return array|null
     */
    public static function getSalesInvoiceRowByRefSql($invoiceRef)
    {
        $invoiceRef = trim((string) $invoiceRef);
        if ($invoiceRef === '') {
            return null;
        }

        $safe = str_replace("'", "''", $invoiceRef);
        $rows = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_Status, dok_DoDokId, dok_DoDokNrPelny,
                    dok_WartNetto, dok_WartBrutto, dok_NumerKSeFId, dok_DataWyst
             FROM dok__Dokument
             WHERE dok_NrPelny = '{$safe}' AND dok_Typ = 2 AND dok_Status >= 0"
        );

        return is_array($rows) && !empty($rows) ? $rows[0] : null;
    }

    /**
     * Rozpoznaje FS po numerze FS lub powiązanym WZ.
     *
     * @param string $invoiceInput
     * @param int $month
     * @param int $year
     * @param string $issueInput opcjonalny WZ gdy podano sam numer
     * @return string
     */
    public static function resolveSalesInvoiceRefInput($invoiceInput, $month, $year, $issueInput = '')
    {
        $invoiceInput = trim((string) $invoiceInput);
        if ($invoiceInput !== '') {
            if (stripos($invoiceInput, 'FS ') === 0 && strpos($invoiceInput, '/') !== false) {
                $row = self::getSalesInvoiceRowByRefSql($invoiceInput);
                if ($row !== null) {
                    return (string) ($row['dok_NrPelny'] ?? '');
                }
            }

            $built = self::buildSalesInvoiceRefFromSequence($invoiceInput, $month, $year);
            if ($built !== '' && self::getSalesInvoiceRowByRefSql($built) !== null) {
                return $built;
            }

            $num = preg_replace('/\D+/', '', $invoiceInput);
            if ($num !== '') {
                $rows = MSSql::getInstance()->query(
                    "SELECT TOP 1 dok_NrPelny FROM dok__Dokument
                     WHERE dok_Typ = 2 AND dok_Status >= 0 AND dok_NrPelny LIKE 'FS {$num}/%'
                     ORDER BY dok_DataWyst DESC"
                );
                if (is_array($rows) && !empty($rows[0]['dok_NrPelny'])) {
                    return (string) $rows[0]['dok_NrPelny'];
                }
            }
        }

        $issueInput = trim((string) $issueInput);
        if ($issueInput === '') {
            return '';
        }

        $issueRefs = self::resolveIssueRefsInput('', 0, array($issueInput), (int) $month, (int) $year);
        if (empty($issueRefs)) {
            return '';
        }

        $wzRow = self::getIssueDocumentRowByRef($issueRefs[0]);
        $fsId = $wzRow !== null ? (int) ($wzRow['dok_DoDokId'] ?? 0) : 0;
        if ($fsId <= 0) {
            return '';
        }

        $rows = MSSql::getInstance()->query(
            "SELECT dok_NrPelny FROM dok__Dokument WHERE dok_Id = {$fsId} AND dok_Typ = 2 AND dok_Status >= 0"
        );

        return is_array($rows) && !empty($rows[0]['dok_NrPelny'])
            ? (string) $rows[0]['dok_NrPelny']
            : '';
    }

    /**
     * Diagnostyka FS pod korektę KFS (zachowane powiązania ZK na WZ po fakturowaniu z API).
     *
     * @param string $invoiceRef
     * @return array
     */
    public static function diagnoseSalesInvoiceForCorrectionSql($invoiceRef)
    {
        $invoiceRef = trim((string) $invoiceRef);
        $fsRow = self::getSalesInvoiceRowByRefSql($invoiceRef);
        if ($fsRow === null) {
            return array(
                'state' => 'not_found',
                'invoice_ref' => $invoiceRef,
                'message' => 'Nie znaleziono FS — podaj pełny numer (np. FS 2645/06/2026).',
            );
        }

        $fsId = (int) ($fsRow['dok_Id'] ?? 0);
        $wzRows = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_Status, dok_DoDokId, dok_NrPelnyOryg, dok_WartNetto, dok_WartBrutto
             FROM dok__Dokument
             WHERE dok_Typ = 11 AND dok_DoDokId = {$fsId} AND dok_Status >= 0
             ORDER BY dok_NrPelny"
        );
        $wzRows = is_array($wzRows) ? $wzRows : array();

        $zkLinkedPositions = MSSql::getInstance()->query(
            "SELECT p.ob_Id, t.tw_Symbol, p.ob_DoId, p.ob_DokMagId, p.ob_DokHanId, zk.dok_NrPelny AS zk_ref
             FROM dok_Pozycja p
             LEFT JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
             INNER JOIN dok_Pozycja zk_p ON zk_p.ob_Id = p.ob_DoId
             INNER JOIN dok__Dokument zk ON zk.dok_Id = zk_p.ob_DokHanId AND zk.dok_Typ = 16
             WHERE p.ob_DokHanId = {$fsId}
                OR p.ob_DokMagId IN (
                    SELECT dok_Id FROM dok__Dokument WHERE dok_Typ = 11 AND dok_DoDokId = {$fsId}
                )
             ORDER BY p.ob_Id"
        );
        $zkLinkedPositions = is_array($zkLinkedPositions) ? $zkLinkedPositions : array();

        $kfsRows = MSSql::getInstance()->query(
            "SELECT dok_Id, dok_NrPelny, dok_Status, dok_DataWyst
             FROM dok__Dokument
             WHERE dok_Typ = 6 AND dok_DoDokId = {$fsId} AND dok_Status >= 0
             ORDER BY dok_DataWyst DESC"
        );
        $kfsRows = is_array($kfsRows) ? $kfsRows : array();

        $wzWithOryg = array();
        foreach ($wzRows as $wz) {
            $oryg = trim((string) ($wz['dok_NrPelnyOryg'] ?? ''));
            if ($oryg !== '') {
                $wzWithOryg[] = array(
                    'wz_ref' => (string) ($wz['dok_NrPelny'] ?? ''),
                    'dok_NrPelnyOryg' => $oryg,
                );
            }
        }

        $needsFix = !empty($zkLinkedPositions) || !empty($wzWithOryg);
        $issues = array();
        if (!empty($zkLinkedPositions)) {
            $issues[] = 'Pozycje FS/WZ mają ob_DoId wskazujące na pozycje ZK — po fakturze z API powinny być puste.';
        }
        if (!empty($wzWithOryg)) {
            $issues[] = 'WZ.dok_NrPelnyOryg nadal wskazuje ZK — po poprawnym FS pole powinno być puste.';
        }

        return array(
            'state' => 'success',
            'invoice_ref' => (string) ($fsRow['dok_NrPelny'] ?? $invoiceRef),
            'fs' => $fsRow,
            'wz' => $wzRows,
            'zk_linked_positions' => $zkLinkedPositions,
            'wz_with_zk_oryg' => $wzWithOryg,
            'existing_kfs' => $kfsRows,
            'needs_fix' => $needsFix,
            'issues' => $issues,
            'ready_for_kfs' => !$needsFix,
            'message' => $needsFix
                ? 'Wykryto powiązania ZK zostawione po WZ z API — użyj „Napraw pod korektę”.'
                : 'Brak typowych blokad — jeśli KFS nadal się nie tworzy, sprawdź inne przyczyny w GT.',
        );
    }

    /**
     * Czyści ob_DoId→ZK na pozycjach i dok_NrPelnyOryg na WZ po wystawieniu FS (przygotowanie do KFS).
     *
     * @param string $invoiceRef
     * @param bool $apply false = podgląd
     * @return array
     */
    public static function prepareSalesInvoiceForCorrectionSql($invoiceRef, $apply = false)
    {
        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::prepareSalesInvoiceForCorrection(
                OrderComWriter::resolveSubiektGt(),
                $invoiceRef,
                $apply
            );
        }

        $diag = self::diagnoseSalesInvoiceForCorrectionSql($invoiceRef);
        if (($diag['state'] ?? '') !== 'success') {
            return $diag;
        }

        $fsId = (int) ($diag['fs']['dok_Id'] ?? 0);
        if ($fsId <= 0) {
            return array(
                'state' => 'error',
                'invoice_ref' => $invoiceRef,
                'message' => 'Brak identyfikatora FS.',
            );
        }

        if (empty($diag['needs_fix'])) {
            return array_merge($diag, array(
                'state' => 'noop',
                'applied' => false,
                'message' => 'Nie wykryto powiązań do naprawy — dokument wygląda na gotowy do korekty.',
            ));
        }

        $preview = array(
            'positions_to_clear' => $diag['zk_linked_positions'],
            'wz_oryg_to_clear' => $diag['wz_with_zk_oryg'],
        );

        if (!$apply) {
            return array_merge($diag, array(
                'state' => 'preview',
                'applied' => false,
                'would_change' => $preview,
                'message' => 'Podgląd — nic nie zostało zmienione. Użyj „Napraw pod korektę”.',
            ));
        }

        MSSql::getInstance()->query(
            "UPDATE p
             SET p.ob_DoId = NULL
             FROM dok_Pozycja p
             INNER JOIN dok_Pozycja zk_p ON zk_p.ob_Id = p.ob_DoId
             INNER JOIN dok__Dokument zk ON zk.dok_Id = zk_p.ob_DokHanId AND zk.dok_Typ = 16
             WHERE p.ob_DokHanId = {$fsId}
                OR p.ob_DokMagId IN (
                    SELECT dok_Id FROM dok__Dokument WHERE dok_Typ = 11 AND dok_DoDokId = {$fsId}
                )"
        );

        MSSql::getInstance()->query(
            "UPDATE dok__Dokument
             SET dok_NrPelnyOryg = ''
             WHERE dok_Typ = 11 AND dok_DoDokId = {$fsId}
               AND LTRIM(RTRIM(ISNULL(dok_NrPelnyOryg, ''))) <> ''"
        );

        $after = self::diagnoseSalesInvoiceForCorrectionSql((string) ($diag['invoice_ref'] ?? $invoiceRef));

        return array(
            'state' => 'success',
            'invoice_ref' => (string) ($diag['invoice_ref'] ?? $invoiceRef),
            'applied' => true,
            'changed' => $preview,
            'after' => $after,
            'message' => 'Naprawiono powiązania pod korektę KFS. Zamknij FS/WZ w GT (F5), potem wystaw korektę do FS.',
        );
    }

    /**
     * Numer FS powiązanej z WZ (WZ.dok_DoDokId → FS).
     *
     * @param string $issueRef
     * @return string
     */
    public static function findSalesInvoiceRefForIssueSql($issueRef)
    {
        return self::resolveSalesInvoiceRefInput('', 0, 0, (string) $issueRef);
    }

    /**
     * Automatyczne czyszczenie ob_DoId→ZK i WZ.dok_NrPelnyOryg po wystawieniu FS (prewencja błędów KFS).
     *
     * @param string $invoiceRef
     * @param string $logContext
     * @return array
     */
    public static function cleanupSalesInvoiceLinksAfterFsSql($invoiceRef, $logContext = '')
    {
        $invoiceRef = trim((string) $invoiceRef);
        if ($invoiceRef === '') {
            return array(
                'state' => 'noop',
                'invoice_ref' => '',
                'message' => 'Brak numeru FS do czyszczenia powiązań.',
            );
        }

        $result = self::prepareSalesInvoiceForCorrectionSql($invoiceRef, true);
        $state = (string) ($result['state'] ?? '');

        if ($state === 'success') {
            Logger::getInstance()->log(
                'api',
                'cleanupSalesInvoiceLinksAfterFsSql: wyczyszczono powiązania ZK dla ' . $invoiceRef
                    . ($logContext !== '' ? ' (' . $logContext . ')' : ''),
                __CLASS__ . '::cleanupSalesInvoiceLinksAfterFsSql',
                __LINE__
            );
        } elseif ($state === 'noop') {
            // Brak linków do czyszczenia — oczekiwane po poprawnym przepływie.
        } elseif ($state === 'not_found' || $state === 'error') {
            Logger::getInstance()->log(
                'api',
                'cleanupSalesInvoiceLinksAfterFsSql: ' . $state . ' dla ' . $invoiceRef
                    . ' — ' . (string) ($result['message'] ?? '')
                    . ($logContext !== '' ? ' (' . $logContext . ')' : ''),
                __CLASS__ . '::cleanupSalesInvoiceLinksAfterFsSql',
                __LINE__
            );
        }

        return $result;
    }

    /**
     * Gdy WZ ma już FS — usuń linki ZK blokujące KFS (np. po fakturowaniu ręcznym w GT).
     *
     * @param string $issueRef
     * @return array
     */
    public static function maybeAutoCleanupSalesInvoiceForIssueSql($issueRef)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '') {
            return array(
                'state' => 'noop',
                'issue_ref' => '',
                'message' => 'Brak numeru WZ.',
            );
        }

        $fsRef = self::findSalesInvoiceRefForIssueSql($issueRef);
        if ($fsRef === '') {
            return array(
                'state' => 'noop',
                'issue_ref' => $issueRef,
                'message' => 'WZ nie ma jeszcze powiązanej FS — pomijam czyszczenie.',
            );
        }

        $cleanup = self::cleanupSalesInvoiceLinksAfterFsSql(
            $fsRef,
            'issue:' . $issueRef
        );

        return array_merge($cleanup, array(
            'issue_ref' => $issueRef,
            'invoice_ref' => $fsRef,
        ));
    }

    /**
     * Zakres dat [początek, koniec) dla miesiąca rozliczeniowego FS.
     *
     * @param int $month
     * @param int $year
     * @return array{start:string, end:string, month:int, year:int}
     */
    public static function buildMonthDateRange($month, $year)
    {
        $month = max(1, min(12, (int) $month));
        $year = (int) $year;
        if ($year < 2000 || $year > 2099) {
            $year = (int) date('Y');
        }

        $start = sprintf('%04d-%02d-01', $year, $month);
        $endMonth = $month === 12 ? 1 : $month + 1;
        $endYear = $month === 12 ? $year + 1 : $year;
        $end = sprintf('%04d-%02d-01', $endYear, $endMonth);

        return array(
            'start' => $start,
            'end' => $end,
            'month' => $month,
            'year' => $year,
        );
    }

    /**
     * Masowy skan FS w miesiącu: powiązania WZ, linki ZK blokujące KFS, istniejące korekty.
     *
     * @param int $month
     * @param int $year
     * @param array{only_needs_fix?:bool, only_with_wz?:bool} $options
     * @return array
     */
    public static function scanSalesInvoicesMonthSql($month, $year, array $options = array())
    {
        $range = self::buildMonthDateRange($month, $year);
        $start = $range['start'];
        $end = $range['end'];

        $rows = MSSql::getInstance()->query(
            "SELECT fs.dok_Id AS fs_id,
                    fs.dok_NrPelny AS fs_ref,
                    fs.dok_DataWyst,
                    fs.dok_WartNetto AS fs_netto,
                    fs.dok_WartBrutto AS fs_brutto,
                    wz.dok_Id AS wz_id,
                    wz.dok_NrPelny AS wz_ref,
                    wz.dok_NrPelnyOryg AS wz_zk_oryg,
                    wz.dok_WartNetto AS wz_netto,
                    wz.dok_WartBrutto AS wz_brutto,
                    ISNULL(zk_link.cnt, 0) AS zk_linked_positions,
                    kfs.kfs_ref,
                    ISNULL(kfs.kfs_count, 0) AS kfs_count
             FROM dok__Dokument fs
             LEFT JOIN dok__Dokument wz
                 ON wz.dok_Typ = 11 AND wz.dok_DoDokId = fs.dok_Id AND wz.dok_Status >= 0
             OUTER APPLY (
                 SELECT COUNT(DISTINCT p.ob_Id) AS cnt
                 FROM dok_Pozycja p
                 INNER JOIN dok_Pozycja zk_p ON zk_p.ob_Id = p.ob_DoId
                 INNER JOIN dok__Dokument zk ON zk.dok_Id = zk_p.ob_DokHanId AND zk.dok_Typ = 16
                 WHERE p.ob_DokHanId = fs.dok_Id
                    OR (wz.dok_Id IS NOT NULL AND p.ob_DokMagId = wz.dok_Id)
             ) zk_link
             LEFT JOIN (
                 SELECT dok_DoDokId,
                        MIN(dok_NrPelny) AS kfs_ref,
                        COUNT(*) AS kfs_count
                 FROM dok__Dokument
                 WHERE dok_Typ = 6 AND dok_Status >= 0
                 GROUP BY dok_DoDokId
             ) kfs ON kfs.dok_DoDokId = fs.dok_Id
             WHERE fs.dok_Typ = 2
               AND fs.dok_Status >= 0
               AND fs.dok_DataWyst >= '{$start}'
               AND fs.dok_DataWyst < '{$end}'
             ORDER BY fs.dok_NrPelny, wz.dok_NrPelny"
        );
        $rows = is_array($rows) ? $rows : array();

        $grouped = array();
        foreach ($rows as $row) {
            $fsId = (int) ($row['fs_id'] ?? 0);
            if ($fsId <= 0) {
                continue;
            }

            if (!isset($grouped[$fsId])) {
                $grouped[$fsId] = array(
                    'fs_id' => $fsId,
                    'fs_ref' => (string) ($row['fs_ref'] ?? ''),
                    'fs_netto' => (string) ($row['fs_netto'] ?? ''),
                    'fs_brutto' => (string) ($row['fs_brutto'] ?? ''),
                    'fs_date' => $row['dok_DataWyst'] ?? null,
                    'wz_refs' => array(),
                    'wz_zk_oryg' => array(),
                    'wz_netto' => array(),
                    'zk_linked_positions' => 0,
                    'kfs_ref' => trim((string) ($row['kfs_ref'] ?? '')),
                    'kfs_count' => (int) ($row['kfs_count'] ?? 0),
                    'has_wz' => false,
                    'value_mismatch' => false,
                );
            }

            $wzRef = trim((string) ($row['wz_ref'] ?? ''));
            if ($wzRef !== '') {
                $grouped[$fsId]['has_wz'] = true;
                if (!in_array($wzRef, $grouped[$fsId]['wz_refs'], true)) {
                    $grouped[$fsId]['wz_refs'][] = $wzRef;
                }
                $oryg = trim((string) ($row['wz_zk_oryg'] ?? ''));
                if ($oryg !== '' && !in_array($oryg, $grouped[$fsId]['wz_zk_oryg'], true)) {
                    $grouped[$fsId]['wz_zk_oryg'][] = $oryg;
                }
                $grouped[$fsId]['wz_netto'][] = (string) ($row['wz_netto'] ?? '');
            }

            $grouped[$fsId]['zk_linked_positions'] = max(
                $grouped[$fsId]['zk_linked_positions'],
                (int) ($row['zk_linked_positions'] ?? 0)
            );
        }

        $invoices = array();
        $summary = array(
            'total_fs' => 0,
            'with_wz' => 0,
            'without_wz' => 0,
            'needs_fix' => 0,
            'needs_service_fix' => 0,
            'needs_kfs_fix' => 0,
            'ready_for_kfs' => 0,
            'has_kfs' => 0,
            'value_mismatch' => 0,
        );

        foreach ($grouped as $item) {
            $wzOryg = !empty($item['wz_zk_oryg']);
            $zkLinks = (int) ($item['zk_linked_positions'] ?? 0) > 0;
            $needsKfsFix = $item['has_wz'] && ($zkLinks || $wzOryg);

            $missingServices = array();
            foreach ($item['wz_refs'] as $wzRef) {
                $orderId = self::resolveOrderIdForIssueSql($wzRef);
                if ($orderId > 0) {
                    $missingServices = array_merge(
                        $missingServices,
                        self::findMissingIssueServiceCodesSql($orderId, $wzRef)
                    );
                } else {
                    $fsServiceCodes = self::getInvoiceServiceCodesSql((int) $item['fs_id']);
                    if (!empty($fsServiceCodes)) {
                        $onIssue = array_flip(self::getIssueProductCodesSql($wzRef));
                        foreach ($fsServiceCodes as $code) {
                            if (!isset($onIssue[$code])) {
                                $missingServices[] = $code;
                            }
                        }
                    }
                }
            }
            $missingServices = array_values(array_unique($missingServices));
            $needsServiceFix = $item['has_wz'] && !empty($missingServices);
            $needsFix = $needsKfsFix || $needsServiceFix;

            $valueMismatch = false;
            if ($item['has_wz'] && !empty($item['wz_netto'])) {
                $fsNet = (float) str_replace(',', '.', (string) $item['fs_netto']);
                $wzNetSum = 0.0;
                foreach ($item['wz_netto'] as $wzNet) {
                    $wzNetSum += (float) str_replace(',', '.', (string) $wzNet);
                }
                if (abs($fsNet - $wzNetSum) > 0.02) {
                    $valueMismatch = true;
                }
            }

            $status = 'ready';
            if (!$item['has_wz']) {
                $status = 'no_wz';
            } elseif ($needsFix) {
                $status = 'needs_fix';
            }

            $flags = array();
            if ($needsServiceFix) {
                $flags[] = 'brak usług na WZ: ' . implode(', ', $missingServices);
            }
            if ($zkLinks) {
                $flags[] = 'ob_DoId→ZK';
            }
            if ($wzOryg) {
                $flags[] = 'WZ.dok_NrPelnyOryg';
            }
            if ($valueMismatch && !$needsServiceFix) {
                $flags[] = 'FS > WZ (wartość)';
            }
            if (!$item['has_wz']) {
                $flags[] = 'brak WZ';
            }

            $entry = array(
                'fs_ref' => $item['fs_ref'],
                'fs_id' => $item['fs_id'],
                'fs_netto' => $item['fs_netto'],
                'fs_brutto' => $item['fs_brutto'],
                'wz_refs' => $item['wz_refs'],
                'wz_zk_oryg' => $item['wz_zk_oryg'],
                'zk_linked_positions' => $item['zk_linked_positions'],
                'missing_services' => $missingServices,
                'kfs_ref' => $item['kfs_ref'],
                'kfs_count' => $item['kfs_count'],
                'has_wz' => $item['has_wz'],
                'needs_fix' => $needsFix,
                'needs_service_fix' => $needsServiceFix,
                'needs_kfs_fix' => $needsKfsFix,
                'ready_for_kfs' => $item['has_wz'] && !$needsKfsFix,
                'value_mismatch' => $valueMismatch,
                'status' => $status,
                'flags' => $flags,
            );

            $summary['total_fs']++;
            if ($item['has_wz']) {
                $summary['with_wz']++;
            } else {
                $summary['without_wz']++;
            }
            if ($needsFix) {
                $summary['needs_fix']++;
            }
            if ($needsServiceFix) {
                $summary['needs_service_fix']++;
            }
            if ($needsKfsFix) {
                $summary['needs_kfs_fix']++;
            }
            if ($item['has_wz'] && !$needsKfsFix) {
                $summary['ready_for_kfs']++;
            }
            if ($item['kfs_count'] > 0) {
                $summary['has_kfs']++;
            }
            if ($valueMismatch) {
                $summary['value_mismatch']++;
            }

            $invoices[] = $entry;
        }

        $onlyNeedsFix = !empty($options['only_needs_fix']);
        $onlyWithWz = !empty($options['only_with_wz']);
        if ($onlyNeedsFix || $onlyWithWz) {
            $invoices = array_values(array_filter($invoices, function ($row) use ($onlyNeedsFix, $onlyWithWz) {
                if ($onlyWithWz && empty($row['has_wz'])) {
                    return false;
                }
                if ($onlyNeedsFix && empty($row['needs_fix'])) {
                    return false;
                }
                return true;
            }));
        }

        return array(
            'state' => 'success',
            'month' => $range['month'],
            'year' => $range['year'],
            'period' => $start . ' — ' . $end,
            'summary' => $summary,
            'invoices' => $invoices,
            'message' => 'Przeskanowano ' . $summary['total_fs'] . ' FS — wymaga naprawy: '
                . $summary['needs_fix']
                . ' (usługi: ' . ($summary['needs_service_fix'] ?? 0)
                . ', KFS: ' . ($summary['needs_kfs_fix'] ?? 0) . ').',
        );
    }

    /**
     * @deprecated Użyj batchRepairSalesInvoicesMonthSql
     */
    public static function batchPrepareSalesInvoicesForCorrectionSql($month, $year, $apply = false)
    {
        return self::batchRepairSalesInvoicesMonthSql($month, $year, $apply, array(
            'append_services' => false,
            'prepare_kfs' => true,
        ));
    }

    /**
     * @param array $payload
     * @return array
     */
    public function batchResetOrderIssues(array $payload)
    {
        $month = isset($payload['month']) ? (int) $payload['month'] : (int) date('n');
        $year = isset($payload['year']) ? (int) $payload['year'] : (int) date('Y');
        $apply = !empty($payload['apply']);
        $removeViaCom = !array_key_exists('remove_via_com', $payload) || !empty($payload['remove_via_com']);
        $issueRefFilter = isset($payload['issue_ref']) ? trim((string) $payload['issue_ref']) : '';

        $orderNumbers = array();
        if (!empty($payload['order_numbers']) && is_array($payload['order_numbers'])) {
            $orderNumbers = $payload['order_numbers'];
        }

        $results = array();
        $summary = array(
            'total' => 0,
            'reset' => 0,
            'noop' => 0,
            'blocked' => 0,
            'not_found' => 0,
            'errors' => 0,
        );

        foreach ($orderNumbers as $number) {
            $number = trim((string) $number);
            if ($number === '') {
                continue;
            }

            $orderRef = self::buildOrderRefFromSequence($number, $month, $year);
            $summary['total']++;

            $issueRefs = array();
            if ($issueRefFilter !== '') {
                $issueRefs = array($issueRefFilter);
            }

            if (!$apply) {
                $zkRow = self::getOrderRowByRefSql($orderRef);
                if ($zkRow === null) {
                    $summary['not_found']++;
                    $results[] = array(
                        'order_ref' => $orderRef,
                        'state' => 'not_found',
                        'message' => 'Nie znaleziono ZK',
                    );
                    continue;
                }

                $orderId = (int) ($zkRow['dok_Id'] ?? 0);
                $allIssues = !empty($issueRefs)
                    ? $issueRefs
                    : self::getIssueRefsForOrder($orderRef, $orderId);
                $preview = array();
                foreach ($allIssues as $ref) {
                    $wzRow = self::getIssueDocumentRowByRef($ref);
                    $wzId = $wzRow !== null ? (int) ($wzRow['dok_Id'] ?? 0) : 0;
                    $preview[] = array(
                        'issue_ref' => $ref,
                        'wz_status' => $wzRow !== null ? (int) ($wzRow['dok_Status'] ?? -1) : null,
                        'blocking_documents' => $wzId > 0
                            ? self::findBlockingDocumentsForIssueSql($wzId, $orderId)
                            : array(),
                    );
                }
                $results[] = array(
                    'order_ref' => $orderRef,
                    'state' => empty($allIssues) ? 'noop' : 'would_reset',
                    'state_before' => (int) ($zkRow['dok_Status'] ?? 0),
                    'status_label' => self::getOrderStatusLabel((int) ($zkRow['dok_Status'] ?? 0)),
                    'zk_do_dok_id' => (int) ($zkRow['dok_DoDokId'] ?? 0),
                    'zk_do_dok_nr' => trim((string) ($zkRow['dok_DoDokNrPelny'] ?? '')),
                    'issues' => $preview,
                );
                if (empty($allIssues)) {
                    $summary['noop']++;
                }
                continue;
            }

            try {
                if (!$removeViaCom || !$this->subiektGt) {
                    $reset = self::resetIssuesAndReopenOrderSql(
                        $orderRef,
                        !empty($issueRefs) ? $issueRefs : array()
                    );
                } else {
                    $order = new self($this->subiektGt, array('order_ref' => $orderRef));
                    if (!$order->isExists()) {
                        $summary['not_found']++;
                        $results[] = array(
                            'order_ref' => $orderRef,
                            'state' => 'not_found',
                            'message' => 'Nie znaleziono ZK',
                        );
                        continue;
                    }
                    $order->setCfg($this->cfg);
                    $reset = $order->resetIssuesAndReopenOrder($issueRefs, $removeViaCom);
                }
                $results[] = $reset;
                if (($reset['state'] ?? '') === 'noop') {
                    $summary['noop']++;
                } else {
                    $blocked = false;
                    foreach ($reset['issues'] ?? array() as $issue) {
                        if (!empty($issue['blocking_documents'])
                            || ($issue['com_action'] ?? '') === 'blocked_by_linked_document'
                            || strpos((string) ($issue['com_action'] ?? ''), 'failed:') === 0) {
                            $blocked = true;
                            break;
                        }
                    }
                    if ($blocked) {
                        $summary['blocked']++;
                    } else {
                        $summary['reset']++;
                    }
                }
            } catch (\Exception $e) {
                $summary['errors']++;
                $results[] = array(
                    'order_ref' => $orderRef,
                    'state' => 'error',
                    'message' => $e->getMessage(),
                );
            }
        }

        return array(
            'state' => 'success',
            'dry_run' => !$apply,
            'apply' => $apply,
            'month' => $month,
            'year' => $year,
            'summary' => $summary,
            'results' => $results,
        );
    }

    public function finalizeOrderAfterIssueSaved(array $extraIssueRefs = array(), $closeOrder = false)
    {
        if (!$this->orderGt) {
            return false;
        }

        $orderId = (int) $this->gt_id;
        $hadReservation = $this->orderHadReservationForClose();
        $issueRefs = array();
        $freshIssueRefs = array();
        foreach (array_merge(
            $this->getValidIssueRefsForOrder(),
            self::getIssueRefsForOrder($this->order_ref, $orderId),
            $extraIssueRefs
        ) as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '' && !in_array($ref, $issueRefs, true)) {
                $issueRefs[] = $ref;
            }
        }
        foreach ($extraIssueRefs as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '' && !in_array($ref, $freshIssueRefs, true)) {
                $freshIssueRefs[] = $ref;
            }
        }

        // Po NaPodstawie zawsze weryfikuj powiązania WZ↔ZK w COM (GT czasem nie zapisuje ob_DoId).
        foreach ($this->findOrphanIssueRefsForOrder() as $orphanRef) {
            if (!in_array($orphanRef, $issueRefs, true)) {
                $issueRefs[] = $orphanRef;
            }
        }
        $shouldLinkHeaders = !empty($freshIssueRefs) || $closeOrder;
        $repair = self::repairWzToOrderPositionLinksSql(
            $orderId,
            $issueRefs,
            $this->order_ref,
            $shouldLinkHeaders,
            !empty($freshIssueRefs),
            true,
            true,
            true
        );
        if ($repair['position_links'] > 0 || $repair['wz_oryg'] > 0
            || $repair['zk_header'] > 0 || $repair['wz_header'] > 0
            || $repair['prices_synced'] > 0) {
            Logger::getInstance()->log(
                'api',
                'finalizeOrderAfterIssueSaved: naprawa powiązań WZ↔ZK dla '
                    . $this->order_ref . ': ' . json_encode($repair),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        }

        $this->reloadOrderFromGt();

        $goodsCoverage = $this->isIssueCoverageComplete();
        $headerLinked = !empty(self::findIssueRefsLinkedFromOrderDocumentSql($orderId));
        $statusExComplete = (((int) $this->status_ex) & 4) !== 0;
        $remainingGoods = $this->orderGt
            ? self::sumRemainingToRealize($this->orderGt, true, $orderId, $this->order_ref)
            : 0.0;
        $remainingAll = $this->orderGt
            ? self::sumRemainingToRealize($this->orderGt, false, $orderId, $this->order_ref)
            : 0.0;
        $hasValidIssue = false;
        foreach ($issueRefs as $ref) {
            if (self::isIssueDocumentLinkedToOrder($ref, $orderId, $this->order_ref)) {
                $hasValidIssue = true;
                break;
            }
        }

        $shouldClose = $closeOrder && $hasValidIssue && (
            $goodsCoverage
            || $remainingGoods <= 0.00001
            || $remainingAll <= 0.00001
            || ($headerLinked && $statusExComplete && !self::orderHasWarehouseGoodsPositions($orderId))
        );

        if (!$closeOrder && $goodsCoverage && self::isOrderStatusOpen((int) $this->state)) {
            $partialOk = self::applyOrderPartialRealizationStatusInSql($orderId);
            if ($partialOk) {
                Logger::getInstance()->log(
                    'api',
                    'finalizeOrderAfterIssueSaved: ZK pozostaje otwarte (5/6), StatusEx|=1 dla '
                        . $this->order_ref,
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
                $this->reloadOrderFromGt();
            }
        }

        if ($shouldClose) {
            $closure = $this->syncOrderStateAfterNaPodstawieIssue(
                $issueRefs,
                $goodsCoverage,
                $hadReservation,
                !empty($freshIssueRefs)
            );
            Logger::getInstance()->log(
                'api',
                'finalizeOrderAfterIssueSaved: domknięcie ZK ' . $this->order_ref
                    . ' target_status=' . (int) ($closure['target_status'] ?? 0)
                    . ', gt_closed=' . (!empty($closure['gt_closed']) ? 'tak' : 'nie')
                    . ', method=' . (string) ($closure['method'] ?? '?')
                    . ', com_zapisz=' . (!empty($closure['com_saved']) ? 'ok' : 'nie'),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        }

        return $this->isBusinessComplete() || ($closeOrder && $this->isZkFulfilledForApi());
    }

    /**
     * Po Zapisz WZ z NaPodstawie(ZK) — GT domyka ZK sam (jak ręczne „Realizuj jako WZ”).
     * Nie ustawiamy ręcznie Status/StatusEx ani SQL dok_Status.
     *
     * @param array<int, string> $issueRefs
     * @param bool $goodsCoverage
     * @param bool $hadReservation
     * @param bool $freshIssueFromNaPodstawie WZ właśnie zapisany przez NaPodstawie w tej operacji
     * @return array{target_status:int, gt_closed:bool, com_saved:bool, method:string}
     */
    protected function syncOrderStateAfterNaPodstawieIssue(
        array $issueRefs,
        $goodsCoverage,
        $hadReservation,
        $freshIssueFromNaPodstawie = false
    )
    {
        $targetStatus = self::resolveOrderFulfilledTargetStatusAfterFullIssue($goodsCoverage, $hadReservation);
        $this->reloadOrderFromGt();

        if (self::isOrderStatusFulfilled((int) $this->state)) {
            return array(
                'target_status' => (int) $this->state,
                'gt_closed' => true,
                'com_saved' => false,
                'method' => $freshIssueFromNaPodstawie ? 'gt_auto_after_na_podstawie' : 'gt_already_closed',
            );
        }

        if (!$freshIssueFromNaPodstawie) {
            foreach ($issueRefs as $issueRef) {
                $this->refreshExistingIssueViaCom($issueRef);
            }
            $this->reloadOrderFromGt();
            if (self::isOrderStatusFulfilled((int) $this->state)) {
                return array(
                    'target_status' => (int) $this->state,
                    'gt_closed' => true,
                    'com_saved' => false,
                    'method' => 'gt_after_wz_refresh',
                );
            }
        }

        $this->syncOrderRealizedQuantitiesViaCom();

        $comSaved = false;
        try {
            if ((int) $targetStatus === 8 || !$hadReservation) {
                $this->orderGt->Rezerwacja = false;
            }
            $this->orderGt->Przelicz();
            $this->orderGt->Zapisz();
            $comSaved = true;
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'syncOrderStateAfterNaPodstawieIssue: COM Zapisz ZK ' . $this->order_ref . ': '
                    . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        }

        $this->reloadOrderFromGt();
        $gtClosed = self::isOrderStatusFulfilled((int) $this->state);

        return array(
            'target_status' => $gtClosed ? (int) $this->state : $targetStatus,
            'gt_closed' => $gtClosed,
            'com_saved' => $comSaved,
            'method' => $gtClosed
                ? ($freshIssueFromNaPodstawie ? 'gt_auto_after_na_podstawie' : 'gt_after_zk_zapisz')
                : 'gt_still_open',
        );
    }

    /**
     * Ponowny Zapisz istniejącego WZ — GT propaguje ilości zrealizowane na ZK.
     *
     * @param string $issueRef
     * @return bool
     */
    protected function refreshExistingIssueViaCom($issueRef)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '' || !$this->subiektGt) {
            return false;
        }

        try {
            if (!$this->subiektGt->SuDokumentyManager->Istnieje($issueRef)) {
                return false;
            }
            $issueDoc = $this->subiektGt->SuDokumentyManager->Wczytaj($issueRef);
            $issueDoc->Przelicz();
            $issueDoc->Zapisz();
            Logger::getInstance()->log(
                'api',
                'refreshExistingIssueViaCom: odświeżono WZ ' . $issueRef . ' dla ZK ' . $this->order_ref,
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );

            return true;
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'refreshExistingIssueViaCom: ' . $issueRef . ': ' . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );

            return false;
        }
    }

    /**
     * @deprecated Użyj syncOrderStateAfterNaPodstawieIssue — ręczne Status/SQL nie domykają ZK w GT.
     *
     * @param array<int, string> $issueRefs
     * @param bool $goodsCoverage
     * @param bool $hadReservation
     * @return array{target_status:int, sql_ok:bool, com_saved:bool, linked:int}
     */
    protected function persistOrderFulfilledAfterFullIssue(array $issueRefs, $goodsCoverage, $hadReservation)
    {
        $closure = $this->syncOrderStateAfterNaPodstawieIssue($issueRefs, $goodsCoverage, $hadReservation, false);

        return array(
            'target_status' => (int) ($closure['target_status'] ?? 0),
            'sql_ok' => !empty($closure['gt_closed']),
            'com_saved' => !empty($closure['com_saved']),
            'linked' => 0,
            'gt_closed' => !empty($closure['gt_closed']),
            'method' => (string) ($closure['method'] ?? ''),
        );
    }

    /**
     * Domyka ZK na podstawie istniejącego WZ (bez nowego NaPodstawie) — odświeżenie WZ + ZK w COM.
     *
     * @param array<int, string> $issueRefs
     * @return array{target_status:int, gt_closed:bool, com_saved:bool, method:string}
     */
    public function reconcileOrderCloseFromExistingIssues(array $issueRefs = array())
    {
        if (empty($issueRefs)) {
            $issueRefs = $this->getValidIssueRefsForOrder();
        }

        return $this->syncOrderStateAfterNaPodstawieIssue(
            $issueRefs,
            $this->isIssueCoverageComplete(),
            $this->orderHadReservationForClose(),
            false
        );
    }

    /**
     * Naprawia powiązania WZ↔ZK (COM) gdy WZ istnieje, ale brak ob_DoId / nagłówka — bez nowego WZ.
     *
     * Parametry w orderDetail (opcjonalnie):
     * - issue_ref / doc_ref / issue_refs[] — konkretny WZ do naprawy (np. z CRM)
     * - close_order — domknij ZK po naprawie (domyślnie true)
     *
     * @return array
     * @throws Exception
     */
    public function repairIssueLinks()
    {
        if (!$this->is_exists || !$this->orderGt) {
            throw new Exception('Zamówienie nie istnieje lub nie udało się go wczytać: ' . $this->order_ref);
        }

        $data = is_array($this->orderDetail) ? $this->orderDetail : array();
        $explicitRefs = $this->resolveExplicitIssueRefsFromDetail($data);
        $repairResult = $this->repairOrphanIssuesForOrder($explicitRefs);

        $issueRefs = $this->getValidIssueRefsForOrder();
        if (empty($issueRefs) && !empty($repairResult['issue_refs'])) {
            $issueRefs = $repairResult['issue_refs'];
        }

        $closeOrder = true;
        if (array_key_exists('close_order', $data)) {
            $closeOrder = filter_var($data['close_order'], FILTER_VALIDATE_BOOLEAN);
        }

        $closure = null;
        if ($closeOrder && !empty($issueRefs)) {
            $closure = $this->reconcileOrderCloseFromExistingIssues($issueRefs);
        }

        $this->reloadOrderFromGt();
        $fulfilled = $this->isBusinessComplete();

        Logger::getInstance()->log(
            'api',
            'repairIssueLinks: order_ref=' . $this->order_ref
                . ', issue_refs=' . implode(', ', $issueRefs)
                . ', repaired=' . (!empty($repairResult['repaired']) ? 'tak' : 'nie')
                . ', fulfilled=' . ($fulfilled ? 'tak' : 'nie'),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return array(
            'state' => 'success',
            'order_ref' => $this->order_ref,
            'issue_refs' => $issueRefs,
            'repair' => $repairResult,
            'closure' => $closure,
            'fulfilled' => $fulfilled,
            'fully_realized' => $fulfilled,
            'zk_closed' => self::isOrderStatusFulfilled((int) $this->state),
            'state_gt' => (int) $this->state,
            'status_ex' => (int) $this->status_ex,
            'issue_coverage_complete' => $this->isIssueCoverageComplete(),
            'message' => $fulfilled
                ? 'Powiązano WZ z ZK i domknięto zamówienie w GT.'
                : (empty($issueRefs)
                    ? 'Nie znaleziono WZ do naprawy dla tego ZK.'
                    : 'Naprawiono powiązania WZ, ale ZK nadal nie ma statusu zrealizowane w GT.'),
        );
    }

    /**
     * @param array $detail
     * @return array<int, string>
     */
    protected function resolveExplicitIssueRefsFromDetail(array $detail)
    {
        $refs = array();
        foreach (array('issue_ref', 'doc_ref', 'document_ref') as $key) {
            $ref = trim((string) ($detail[$key] ?? ''));
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }
        if (!empty($detail['issue_refs']) && is_array($detail['issue_refs'])) {
            foreach ($detail['issue_refs'] as $ref) {
                $ref = trim((string) $ref);
                if ($ref !== '') {
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * Osierocony WZ (pełne ilości, brak ob_DoId) → naprawa linków COM.
     *
     * @param array<int, string> $extraIssueRefs
     * @return array{repaired:bool, issue_refs:array<int, string>, repair:array|null}
     */
    protected function repairOrphanIssuesForOrder(array $extraIssueRefs = array())
    {
        $orderId = (int) $this->gt_id;
        $issueRefs = $this->findOrphanIssueRefsForOrder();

        foreach ($extraIssueRefs as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '' || in_array($ref, $issueRefs, true)) {
                continue;
            }
            $row = self::getIssueDocumentRowByRef($ref);
            if ($row === null) {
                continue;
            }
            if (self::isIssueDocumentLinkedToOrder($ref, $orderId, $this->order_ref)) {
                continue;
            }
            if (self::isSingleIssueCoveringOrderSql($orderId, (int) ($row['dok_Id'] ?? 0))) {
                $issueRefs[] = $ref;
            }
        }

        $issueRefs = array_values(array_unique($issueRefs));
        if (empty($issueRefs)) {
            return array(
                'repaired' => false,
                'issue_refs' => array(),
                'repair' => null,
            );
        }

        $repair = self::repairWzToOrderPositionLinksSql(
            $orderId,
            $issueRefs,
            $this->order_ref,
            true,
            true,
            true,
            true,
            true
        );

        $validAfter = array();
        foreach ($issueRefs as $ref) {
            if (self::isIssueDocumentLinkedToOrder($ref, $orderId, $this->order_ref)) {
                $validAfter[] = $ref;
            }
        }

        return array(
            'repaired' => true,
            'issue_refs' => !empty($validAfter) ? $validAfter : $issueRefs,
            'repair' => $repair,
        );
    }

    /**
     * Czy powiązane WZ pokrywają pozycje towarowe ZK (usługi pomijane — nie trafiają na WZ).
     *
     * @return bool
     */
    public function isIssueCoverageComplete()
    {
        return self::isOrderFullyCoveredByLinkedIssuesSql((int) $this->gt_id, $this->order_ref);
    }

    /**
     * Czy ZK uznajemy za zrealizowane w odpowiedzi API (status GT lub pełne pokrycie WZ).
     *
     * @return bool
     */
    public function isZkFulfilledForApi()
    {
        if ($this->isBusinessComplete()) {
            return true;
        }

        return $this->isIssueCoverageComplete() && !empty($this->getValidIssueRefsForOrder());
    }

    /**
     * @param array $payload
     * @return array
     */
    public function enrichIssueResultPayload(array $payload)
    {
        $this->reloadOrderFromGt();
        $fulfilled = $this->isZkFulfilledForApi();
        $payload['issue_coverage_complete'] = $this->isIssueCoverageComplete();
        $payload['fulfilled'] = $fulfilled;
        $payload['fully_realized'] = $fulfilled;
        $payload['is_realized'] = $fulfilled;
        $payload['is_fulfilled'] = $fulfilled;
        $payload['zk_closed'] = self::isOrderStatusFulfilled((int) $this->state);
        $payload['state'] = (int) $this->state;
        $payload['status_ex'] = (int) $this->status_ex;
        $payload['remaining_qty'] = self::sumRemainingToRealize($this->orderGt, false, (int) $this->gt_id, $this->order_ref);
        $payload['remaining_qty_goods'] = self::sumRemainingToRealize($this->orderGt, true, (int) $this->gt_id, $this->order_ref);
        $payload['issue_documents'] = $this->getValidIssueRefsForOrder();
        $payload['wz_refs'] = $payload['issue_documents'];
        return $payload;
    }

    /**
     * Ustawia IloscZrealizowana na pozycjach ZK przez COM (gdy WZ już pokrywa zamówienie).
     *
     * @return bool
     */
    protected function syncOrderRealizedQuantitiesViaCom()
    {
        if (!$this->orderGt) {
            return false;
        }

        $rodzajMap = self::getOrderPositionTowRodzajMap((int) $this->gt_id);
        $towIdMap = self::getOrderPositionTowIdMap((int) $this->gt_id);
        $issuedByTowId = self::getIssuedGoodsQtyByTowIdFromIssueRefs($this->getValidIssueRefsForOrder());
        $finalizeGoods = $this->isIssueCoverageComplete();
        if (!$finalizeGoods) {
            $finalizeGoods = !empty(self::findIssueRefsLinkedFromOrderDocumentSql((int) $this->gt_id))
                && (((int) $this->status_ex) & 4);
        }
        $synced = false;
        for ($i = 1; $i <= $this->orderGt->Pozycje->Liczba(); $i++) {
            $pos = $this->orderGt->Pozycje->Element($i);
            try {
                $ordered = (float) $pos->IloscJm;
                if ($ordered <= 0.00001) {
                    continue;
                }
                $posId = (int) $pos->Id;
                $rodzaj = isset($rodzajMap[$posId]) ? $rodzajMap[$posId] : 1;
                $targetQty = null;

                if (self::isServicePositionKind($rodzaj)) {
                    $targetQty = $ordered;
                } else {
                    $towId = isset($towIdMap[$posId]) ? (int) $towIdMap[$posId] : 0;
                    if ($towId > 0 && isset($issuedByTowId[$towId]) && $issuedByTowId[$towId] > 0.00001) {
                        $targetQty = min($ordered, (float) $issuedByTowId[$towId]);
                        $issuedByTowId[$towId] -= $targetQty;
                    } elseif ($finalizeGoods) {
                        $targetQty = $ordered;
                    }
                }

                if ($targetQty === null || $targetQty <= 0.00001) {
                    continue;
                }

                $prop = self::trySetComObjectProperty(
                    $pos,
                    array('IloscZrealizowana', 'IloscZreal', 'ObIloscZrealizowana', 'Zrealizowano'),
                    $targetQty
                );
                if ($prop === false) {
                    continue;
                }
                $synced = true;
            } catch (\Exception $e) {
                Logger::getInstance()->log(
                    'api',
                    'syncOrderRealizedQuantitiesViaCom: pozycja ' . $i . ': ' . $e->getMessage(),
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
            }
        }

        if ($synced) {
            try {
                $this->orderGt->Przelicz();
            } catch (\Exception $e) {
                Logger::getInstance()->log(
                    'api',
                    'syncOrderRealizedQuantitiesViaCom: Przelicz: ' . $e->getMessage(),
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
            }
        }

        return $synced;
    }

    /**
     * Wczytuje ZK po order_ref (z wariantami numeru).
     *
     * @param object $subiektGt
     * @param array $orderDetail
     * @return Order|null
     */
    public static function loadExistingByRefVariants($subiektGt, array $orderDetail)
    {
        $requested = isset($orderDetail['order_ref']) ? trim((string) $orderDetail['order_ref']) : '';
        $variants = self::orderRefVariants($requested);
        foreach ($variants as $variant) {
            $detail = $orderDetail;
            $detail['order_ref'] = $variant;
            $order = new self($subiektGt, $detail);
            if ($order->isExists()) {
                return $order;
            }
        }
        return null;
    }

    /**
     * Domknięcie ZK — order/fulfill (fallback po WZ).
     *
     * @return array
     * @throws Exception
     */
    public function fulfill()
    {
        if (!$this->order_ref) {
            throw new Exception('Brak parametru order_ref – nie można zrealizować zamówienia.');
        }
        if (!$this->is_exists || !$this->orderGt) {
            throw new Exception('Zamówienie nie istnieje lub nie udało się go wczytać: ' . $this->order_ref);
        }

        $result = $this->fulfillRemainingToWz(true);
        Logger::getInstance()->log(
            'api',
            'fulfill: order_ref=' . $this->order_ref
                . ', fulfilled=' . (!empty($result['fulfilled']) ? 'tak' : 'nie'),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return $result;
    }

    /**
     * Tworzy WZ z zamówienia ZK.
     *
     * @param bool $fullRealization
     * @param string $reference
     * @param array $issueOptions
     * @param bool $closeOrder domknij ZK (7/8) po zapisie WZ
     * @return array
     * @throws Exception
     */
    public function createWzFromOrder(
        $fullRealization = false,
        $reference = '',
        array $issueOptions = array(),
        $closeOrder = true
    )
    {
        if (!$this->is_exists || !$this->orderGt) {
            throw new Exception('Zamówienie nie istnieje lub nie udało się go wczytać: ' . $this->order_ref);
        }

        $this->ensureOrderReservationBeforeIssue();

        $issueDoc = $this->subiektGt->SuDokumentyManager->DodajWZ();
        $issueDoc->NaPodstawie((int) $this->gt_id);

        if ($fullRealization) {
            $this->applyRemainingQuantitiesToIssueDoc($this->orderGt, $issueDoc);
        }

        if ((int) $issueDoc->Pozycje->Liczba() <= 0) {
            $this->cancelDraftIssueDocument($issueDoc);
            throw new Exception('Brak pozycji do wydania na WZ dla zamówienia: ' . $this->order_ref);
        }

        if ($reference !== '') {
            $issueDoc->Uwagi = Helper::toWin($reference);
        }

        $serviceLines = self::resolveIssueServicesInput($issueOptions, (int) $this->gt_id);

        $issueDoc->Wystawil = Helper::toWin($this->cfg->getIdPerson());
        $shortages = $this->saveIssueDocWithStockCheck($issueDoc);
        if ($shortages !== null) {
            return array(
                'stock_failed' => true,
                'shortages' => $shortages,
            );
        }

        $servicesAdded = 0;
        $serviceCodes = array();
        if (!empty($serviceLines)) {
            $issueRefForServices = trim((string) ($issueDoc->NumerPelny ?? ''));
            $appendResult = $this->saveIssueDocServicesIfNeeded(
                $issueDoc,
                $serviceLines,
                (int) $this->gt_id,
                $issueRefForServices
            );
            $servicesAdded = (int) ($appendResult['added'] ?? 0);
            $serviceCodes = isset($appendResult['codes']) && is_array($appendResult['codes'])
                ? $appendResult['codes']
                : array();
        }

        $docRef = isset($issueDoc->NumerPelny) ? trim((string) $issueDoc->NumerPelny) : '';
        if ($docRef === '') {
            throw new Exception('Brak numeru dokumentu WZ po zapisie dla zamówienia: ' . $this->order_ref);
        }

        $missingAfter = self::findMissingIssueServiceCodesSql((int) $this->gt_id, $docRef);
        if (!empty($missingAfter)) {
            $retryLines = !empty($serviceLines)
                ? $serviceLines
                : self::resolveIssueServicesInput($issueOptions, (int) $this->gt_id);
            if (!empty($retryLines)) {
                $issueDocReload = $this->subiektGt->SuDokumentyManager->Wczytaj($docRef);
                $retryResult = $this->saveIssueDocServicesIfNeeded(
                    $issueDocReload,
                    $retryLines,
                    (int) $this->gt_id,
                    $docRef
                );
                $retryAdded = (int) ($retryResult['added'] ?? 0);
                if ($retryAdded > 0) {
                    $servicesAdded += $retryAdded;
                    $retryCodes = isset($retryResult['codes']) && is_array($retryResult['codes'])
                        ? $retryResult['codes']
                        : array();
                    $serviceCodes = array_values(array_unique(array_merge($serviceCodes, $retryCodes)));
                }
            }
        }

        self::repairWzToOrderPositionLinksSql(
            (int) $this->gt_id,
            array($docRef),
            $this->order_ref,
            true,
            true,
            true,
            true,
            true
        );
        $this->assertIssueDocumentLinkedToOrderOrCancel($docRef);

        $this->reloadOrderFromGt();
        $this->finalizeOrderAfterIssueSaved(array($docRef), (bool) $closeOrder);

        $payload = $this->buildWzResultPayload($docRef, (bool) $closeOrder);
        if ($servicesAdded > 0) {
            $payload['services_added'] = $servicesAdded;
            $payload['service_codes'] = $serviceCodes;
        }

        return $payload;
    }

    /**
     * Symulacja WZ (ZapiszSymulacja) — ta sama logika co przy createIssueFromOrder, bez zapisu.
     * Do weryfikacji stanów w aplikacji zewnętrznej (zamiast Product/getStocks).
     *
     * @return array
     * @throws Exception
     */
    public function checkIssueStock()
    {
        if (!$this->is_exists || !$this->orderGt) {
            throw new Exception('Zamówienie nie istnieje lub nie udało się go wczytać: ' . $this->order_ref);
        }

        $fullRealization = self::isFullRealizationRequested($this->orderDetail, false);
        $this->ensureOrderReservationBeforeIssue();

        $issueDoc = $this->subiektGt->SuDokumentyManager->DodajWZ();
        $issueDoc->NaPodstawie((int) $this->gt_id);

        if ($fullRealization) {
            $this->applyRemainingQuantitiesToIssueDoc($this->orderGt, $issueDoc);
        }

        if ((int) $issueDoc->Pozycje->Liczba() <= 0) {
            $this->cancelDraftIssueDocument($issueDoc);
            return array(
                'order_ref' => $this->order_ref,
                'ready' => false,
                'message' => 'Brak pozycji towarowych do wydania na WZ',
                'shortages' => array(),
                'full_realization' => $fullRealization,
                'reservation' => (bool) $this->reservation,
                'state' => (int) $this->state,
            );
        }

        $issueDoc->Wystawil = Helper::toWin($this->cfg->getIdPerson());
        $comShortages = $this->simulateIssueDocStock($issueDoc);
        $this->cancelDraftIssueDocument($issueDoc);

        $warehouseId = $this->cfg ? (int) $this->cfg->getWarehouse() : 1;
        $sqlShortages = self::getOrderWarehouseStockShortagesSql(
            (int) $this->gt_id,
            $warehouseId,
            (int) $this->state
        );
        $shortages = $comShortages !== null
            ? self::filterComShortagesAgainstSql($comShortages, (int) $this->gt_id, (int) $this->state, $warehouseId)
            : array();

        return array(
            'order_ref' => $this->order_ref,
            'ready' => empty($shortages) && empty($sqlShortages),
            'message' => empty($shortages) && empty($sqlShortages)
                ? 'Subiekt GT: wystarczający stan magazynowy do WZ'
                : 'Subiekt GT: brak towaru na magazynie',
            'shortages' => !empty($shortages) ? $shortages : $sqlShortages,
            'shortages_com' => $comShortages ?? array(),
            'shortages_com_filtered' => $shortages,
            'shortages_sql' => $sqlShortages,
            'full_realization' => $fullRealization,
            'reservation' => (bool) $this->reservation,
            'state' => (int) $this->state,
        );
    }

    /**
     * Domyka ZK (bez duplikatu WZ, gdy WZ już pokrywa całość).
     *
     * @param bool $createWzWhenNeeded
     * @return array
     * @throws Exception
     */
    public function fulfillRemainingToWz($createWzWhenNeeded = true)
    {
        if (!$this->is_exists || !$this->orderGt) {
            throw new Exception('Zamówienie nie istnieje lub nie udało się go wczytać: ' . $this->order_ref);
        }

        $this->reloadOrderFromGt();
        $this->reopenOrderForIssueIfNeeded();

        if ($this->isBusinessComplete()) {
            return $this->buildFulfillResultPayload(true, 'ZK jest w pełni zrealizowane w Subiekcie.');
        }

        $explicitRefs = $this->resolveExplicitIssueRefsFromDetail(
            is_array($this->orderDetail) ? $this->orderDetail : array()
        );
        $orphanRepair = $this->repairOrphanIssuesForOrder($explicitRefs);
        if (!empty($orphanRepair['repaired'])) {
            $this->reloadOrderFromGt();
            if ($this->isBusinessComplete()) {
                return $this->buildFulfillResultPayload(
                    true,
                    'Naprawiono powiązania istniejącego WZ i domknięto ZK w GT.'
                );
            }
            $this->syncOrderStateAfterNaPodstawieIssue(
                $orphanRepair['issue_refs'],
                $this->isIssueCoverageComplete(),
                $this->orderHadReservationForClose(),
                false
            );
            $this->reloadOrderFromGt();
            if ($this->isBusinessComplete()) {
                return $this->buildFulfillResultPayload(
                    true,
                    'Naprawiono powiązania istniejącego WZ i domknięto ZK w GT.'
                );
            }
        }

        $remainingQty = self::sumRemainingToRealize($this->orderGt, false, (int) $this->gt_id, $this->order_ref);
        $existingIssues = $this->resolveIssueRefsBlockingDuplicateWzCreation();

        if (!empty($existingIssues) && !$createWzWhenNeeded) {
            if ($this->isBusinessComplete()) {
                return $this->buildFulfillResultPayload(true, 'ZK jest w pełni zrealizowane w Subiekcie.');
            }
            $this->syncOrderStateAfterNaPodstawieIssue(
                $existingIssues,
                $this->isIssueCoverageComplete(),
                $this->orderHadReservationForClose(),
                false
            );
            if ($this->isBusinessComplete()) {
                return $this->buildFulfillResultPayload(
                    true,
                    'ZK domknięte na podstawie istniejącego WZ (status 7/8 w GT).'
                );
            }
            return $this->buildFulfillResultPayload(
                false,
                'ZK ma WZ, ale Subiekt GT nie domknął zamówienia — użyj realizacji NaPodstawie lub sprawdź powiązania WZ↔ZK w GT.'
            );
        }

        if ($remainingQty <= 0.00001 && !empty($existingIssues)) {
            $this->syncOrderStateAfterNaPodstawieIssue(
                $existingIssues,
                $this->isIssueCoverageComplete(),
                $this->orderHadReservationForClose(),
                false
            );
            if ($this->isBusinessComplete()) {
                return $this->buildFulfillResultPayload(
                    true,
                    'ZK domknięte bez tworzenia kolejnego WZ.'
                );
            }
            if (!self::isOrderStatusOpen((int) $this->state)) {
                return $this->buildFulfillResultPayload(
                    false,
                    'ZK nie zostało domknięte w GT mimo braku pozostałości do wydania.'
                );
            }
            Logger::getInstance()->log(
                'api',
                'fulfillRemainingToWz: status otwarty (5/6) przy remaining_qty≈0 i istniejącym WZ '
                    . implode(', ', $existingIssues) . ' dla ' . $this->order_ref
                    . ' — pomijam tworzenie kolejnego WZ',
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            return $this->buildFulfillResultPayload(
                false,
                'ZK ma już WZ (' . implode(', ', $existingIssues) . '), ale GT nie domknął zamówienia.'
                    . ' Użyj order/repairIssueLinks — nie twórz drugiego WZ.'
            );
        } elseif ($remainingQty <= 0.00001) {
            $this->finalizeOrderAfterIssueSaved(array(), true);
            if ($this->isBusinessComplete()) {
                return $this->buildFulfillResultPayload(
                    true,
                    'ZK domknięte bez tworzenia kolejnego WZ.'
                );
            }
            if (!self::isOrderStatusOpen((int) $this->state)) {
                return $this->buildFulfillResultPayload(
                    false,
                    'ZK nie zostało domknięte w GT mimo braku pozostałości do wydania.'
                );
            }
            $orphanOnly = $this->findOrphanIssueRefsForOrder();
            if (!empty($orphanOnly)) {
                $orphanRepairLate = $this->repairOrphanIssuesForOrder($orphanOnly);
                $this->reloadOrderFromGt();
                if ($this->isBusinessComplete()) {
                    return $this->buildFulfillResultPayload(
                        true,
                        'Naprawiono powiązania istniejącego WZ i domknięto ZK w GT.'
                    );
                }
                if (!empty($orphanRepairLate['issue_refs'])) {
                    $this->reconcileOrderCloseFromExistingIssues($orphanRepairLate['issue_refs']);
                    $this->reloadOrderFromGt();
                    if ($this->isBusinessComplete()) {
                        return $this->buildFulfillResultPayload(
                            true,
                            'Naprawiono powiązania istniejącego WZ i domknięto ZK w GT.'
                        );
                    }
                }
                Logger::getInstance()->log(
                    'api',
                    'fulfillRemainingToWz: znaleziono osierocony WZ '
                        . implode(', ', $orphanOnly) . ' dla ' . $this->order_ref
                        . ' — pomijam tworzenie kolejnego WZ',
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
                return $this->buildFulfillResultPayload(
                    false,
                    'ZK ma już WZ (' . implode(', ', $orphanOnly) . ') bez pełnego domknięcia.'
                        . ' Użyj order/repairIssueLinks — nie twórz drugiego WZ.'
                );
            }
            Logger::getInstance()->log(
                'api',
                'fulfillRemainingToWz: status otwarty (5/6) przy remaining_qty≈0 dla '
                    . $this->order_ref . ' — kontynuacja tworzenia WZ',
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        }

        if (!$createWzWhenNeeded) {
            return $this->buildFulfillResultPayload(false, 'ZK ma pozostałości do realizacji, tworzenie WZ wyłączone.');
        }

        $blockingRefs = $this->resolveIssueRefsBlockingDuplicateWzCreation();
        if ($remainingQty <= 0.00001 && !empty($blockingRefs)) {
            Logger::getInstance()->log(
                'api',
                'fulfillRemainingToWz: blokada duplikatu WZ dla ' . $this->order_ref
                    . ', istniejące: ' . implode(', ', $blockingRefs),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            return $this->buildFulfillResultPayload(
                false,
                'ZK ma już WZ (' . implode(', ', $blockingRefs) . '). Nie utworzono kolejnego dokumentu.'
            );
        }

        $this->ensureOrderReservationBeforeIssue();

        $issueDoc = $this->subiektGt->SuDokumentyManager->DodajWZ();
        $issueDoc->NaPodstawie((int) $this->gt_id);
        $this->applyRemainingQuantitiesToIssueDoc($this->orderGt, $issueDoc);

        if ((int) $issueDoc->Pozycje->Liczba() <= 0) {
            $this->cancelDraftIssueDocument($issueDoc);
            $this->finalizeOrderAfterIssueSaved(array(), true);
            return $this->buildFulfillResultPayload(
                $this->isBusinessComplete(),
                'Brak pozycji na WZ — próba domknięcia ZK.'
            );
        }

        $serviceLines = self::resolveIssueServicesInput($this->orderDetail, (int) $this->gt_id);

        $issueDoc->Wystawil = Helper::toWin($this->cfg->getIdPerson());
        $shortages = $this->saveIssueDocWithStockCheck($issueDoc);
        if ($shortages !== null) {
            return array_merge($this->buildFulfillResultPayload(false, 'Brak towaru w magazynie.'), array(
                'shortages' => $shortages,
            ));
        }

        if (!empty($serviceLines)) {
            $this->saveIssueDocServicesIfNeeded($issueDoc, $serviceLines, (int) $this->gt_id);
        }

        $docRef = isset($issueDoc->NumerPelny) ? trim((string) $issueDoc->NumerPelny) : '';
        if ($docRef !== '') {
            $this->assertIssueDocumentLinkedToOrderOrCancel($docRef);
        }

        $this->reloadOrderFromGt();
        $this->finalizeOrderAfterIssueSaved($docRef !== '' ? array($docRef) : array(), true);

        $payload = $this->buildFulfillResultPayload(
            $this->isBusinessComplete(),
            $docRef !== '' ? 'Utworzono WZ (NaPodstawie) i domknięto ZK w GT.' : null
        );
        if ($docRef !== '') {
            $payload['doc_ref'] = $docRef;
        }

        return $payload;
    }

    protected function reloadOrderFromGt()
    {
        if ($this->order_ref === '' || !$this->subiektGt) {
            return;
        }
        if ($this->subiektGt->SuDokumentyManager->Istnieje($this->order_ref)) {
            $this->orderGt = $this->subiektGt->SuDokumentyManager->Wczytaj($this->order_ref);
            $this->getGtObject();
            $this->is_exists = true;
        }
    }

    /**
     * Odświeża rezerwację ZK w COM przed WZ (ZapiszSymulacja inaczej liczy IloscDostepna).
     *
     * @return bool
     */
    protected function ensureOrderReservationBeforeIssue()
    {
        if (!$this->orderGt) {
            return false;
        }

        $orderId = (int) $this->gt_id;
        $state = (int) $this->state;
        if ($orderId > 0 && self::isOrderStuckWithoutIssueSql($orderId, $this->order_ref)) {
            self::resetOrderPositionIssuedQtyWithoutIssueSql($orderId);
            self::reopenOrderAfterIssueRemovalSql(
                $orderId,
                $this->order_ref,
                $this->orderHadReservationForClose($state)
            );
            $this->reloadOrderFromGt();
            $state = (int) $this->state;
        }

        if (!in_array($state, array(5, 6), true)) {
            return false;
        }

        if (!$this->orderHadReservationForClose($state)) {
            return false;
        }

        $sync = $this->syncOrderReservationInCom(true);
        if ($sync['synced'] ?? false) {
            return true;
        }

        Logger::getInstance()->log(
            'api',
            'ensureOrderReservationBeforeIssue: ' . $this->order_ref
                . ' sync failed: ' . ($sync['error'] ?? $sync['message'] ?? '?'),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return false;
    }

    /**
     * Korekta fałszywych braków COM względem SQL (ZK z rezerwacją, komplety).
     *
     * @param array<int, array>|null $shortages
     * @return array<int, array>|null
     */
    protected function reconcileComShortagesWithSql($shortages)
    {
        if ($shortages === null || empty($shortages) || (int) $this->gt_id <= 0) {
            return $shortages;
        }

        $warehouseId = $this->cfg ? (int) $this->cfg->getWarehouse() : 1;
        $filtered = self::filterComShortagesAgainstSql(
            $shortages,
            (int) $this->gt_id,
            (int) $this->state,
            $warehouseId
        );

        return empty($filtered) ? null : $filtered;
    }

    /**
     * Anuluje niezapisany szkic WZ (COM). Po błędzie ZapiszSymulacja obiekt bywa variant — nie rzuca dalej.
     *
     * @param mixed $issueDoc
     * @return bool
     */
    protected function cancelDraftIssueDocument($issueDoc)
    {
        if (!$issueDoc) {
            return false;
        }

        try {
            $issueDoc->Anuluj();
            return true;
        } catch (\Throwable $e) {
            Logger::getInstance()->log(
                'api',
                'cancelDraftIssueDocument: Anuluj: ' . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        }

        $issueRef = '';
        try {
            $issueRef = trim((string) $issueDoc->NumerPelny);
        } catch (\Throwable $e) {
        }
        if ($issueRef !== '' && $this->subiektGt) {
            $result = $this->removeIssueDocumentViaCom($issueRef);
            if (strpos($result, 'failed:') !== 0 && $result !== 'not_found') {
                return true;
            }
        }

        return false;
    }

    /**
     * Dopisuje usługi na zapisanym WZ i ponownie zapisuje dokument.
     *
     * @param mixed $issueDoc
     * @param array<int, array> $serviceLines
     * @param int $orderId
     * @return array{added:int, codes:array<int, string>}
     */
    protected function saveIssueDocServicesIfNeeded($issueDoc, array $serviceLines, $orderId = 0, $issueRef = '')
    {
        if (!$issueDoc || empty($serviceLines)) {
            return array('added' => 0, 'codes' => array());
        }

        $appendResult = $this->appendServicesToIssueDocument($issueDoc, $serviceLines, $orderId, $issueRef);
        if ((int) ($appendResult['added'] ?? 0) <= 0) {
            return $appendResult;
        }

        try {
            $issueDoc->Zapisz();
        } catch (\Throwable $e) {
            Logger::getInstance()->log(
                'api',
                'saveIssueDocServicesIfNeeded: Zapisz: ' . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            throw new Exception('Nie udało się zapisać WZ po dopisaniu usług: ' . $e->getMessage());
        }

        return $appendResult;
    }

    /**
     * Symulacja zapisu WZ — tylko ZapiszSymulacja, bez Zapisz().
     *
     * @param mixed $issueDoc
     * @return array|null shortages lub null gdy OK
     * @throws Exception
     */
    protected function simulateIssueDocStock($issueDoc)
    {
        $shortages = null;
        try {
            $issueDoc->ZapiszSymulacja();
        } catch (\Throwable $e) {
            $collected = $this->collectShortagesFromIssueDoc($issueDoc);
            if (!empty($collected)) {
                $shortages = $collected;
            } else {
                throw $e;
            }
        }

        if ($shortages === null && $this->issueDocHasShortages($issueDoc)) {
            $collected = $this->collectShortagesFromIssueDoc($issueDoc);
            if (!empty($collected)) {
                $shortages = $collected;
            }
        }

        return $this->reconcileComShortagesWithSql($shortages);
    }

    /**
     * Zapis WZ z weryfikacją stanów (ZapiszSymulacja).
     *
     * @param mixed $issueDoc
     * @return array|null shortages lub null przy sukcesie
     * @throws Exception
     */
    protected function saveIssueDocWithStockCheck($issueDoc)
    {
        $shortages = $this->simulateIssueDocStock($issueDoc);
        if ($shortages !== null && !empty($shortages)) {
            $this->cancelDraftIssueDocument($issueDoc);
            return $shortages;
        }

        try {
            $issueDoc->Zapisz();
        } catch (\Throwable $e) {
            $this->cancelDraftIssueDocument($issueDoc);
            $sqlShortages = self::getOrderWarehouseStockShortagesSql(
                (int) $this->gt_id,
                $this->cfg ? (int) $this->cfg->getWarehouse() : 1,
                (int) $this->state
            );
            if (empty($sqlShortages)) {
                throw $e;
            }
            return $sqlShortages;
        }

        return null;
    }

    /**
     * @param mixed $issueDoc
     * @return bool
     */
    protected function issueDocHasShortages($issueDoc)
    {
        try {
            return isset($issueDoc->PozycjeBrakujace) && (int) $issueDoc->PozycjeBrakujace->Liczba() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param mixed $issueDoc
     * @return array
     */
    protected function collectShortagesFromIssueDoc($issueDoc)
    {
        $shortages = array();
        try {
            if (!isset($issueDoc->PozycjeBrakujace)) {
                return $shortages;
            }
            $count = (int) $issueDoc->PozycjeBrakujace->Liczba();
            for ($i = 1; $i <= $count; $i++) {
                $pos = $issueDoc->PozycjeBrakujace->Element($i);
                $sku = '';
                $name = '';
                $required = 0.0;
                $available = 0.0;
                try {
                    $sku = trim((string) $pos->TowarSymbol);
                } catch (\Exception $e) {
                }
                try {
                    $name = trim((string) $pos->TowarNazwa);
                } catch (\Exception $e) {
                }
                try {
                    $required = (float) $pos->IloscJm;
                } catch (\Exception $e) {
                }
                try {
                    $available = (float) $pos->IloscDostepna;
                } catch (\Exception $e) {
                    try {
                        $available = (float) $pos->StanMagazynowy;
                    } catch (\Exception $e2) {
                        $available = 0.0;
                    }
                }
                $stanMagazynowy = 0.0;
                try {
                    $stanMagazynowy = (float) $pos->StanMagazynowy;
                } catch (\Exception $e) {
                }
                if ($this->orderHadReservationForClose((int) $this->state) && $stanMagazynowy > $available) {
                    $available = $stanMagazynowy;
                }
                $missing = max(0.0, $required - $available);
                if ($sku !== '' && self::isServiceProductSymbolSql($sku)) {
                    continue;
                }
                if ($missing <= 0.00001) {
                    continue;
                }
                if ($sku === '' && $name === '' && $required <= 0) {
                    continue;
                }
                $shortages[] = array(
                    'sku' => $sku,
                    'code' => $sku,
                    'symbol' => $sku,
                    'name' => $name,
                    'required' => $required,
                    'available' => $available,
                    'missing' => $missing,
                );
            }
        } catch (\Exception $e) {
            Logger::getInstance()->log('api', 'collectShortagesFromIssueDoc: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
        }
        return $shortages;
    }

    /**
     * Oznacza ZK jako zrealizowane (status 7/8 + StatusEx) gdy brak pozostałości do wydania.
     *
     * @return bool
     */
    protected function closeOrderAsFulfilledIfPossible()
    {
        if (!$this->orderGt) {
            return false;
        }

        if ($this->isBusinessComplete()) {
            return true;
        }

        $closeSource = $this->canCloseOrderAsFulfilled();
        if ($closeSource === false) {
            return false;
        }

        $issueRefs = $this->getValidIssueRefsForOrder();
        $goodsCoverage = $this->isIssueCoverageComplete();
        $hadReservation = $this->orderHadReservationForClose();

        $closure = $this->syncOrderStateAfterNaPodstawieIssue(
            $issueRefs,
            $goodsCoverage,
            $hadReservation,
            false
        );
        $closed = $this->isBusinessComplete();

        Logger::getInstance()->log(
            'api',
            'closeOrderAsFulfilledIfPossible: order_ref=' . $this->order_ref
                . ', source=' . $closeSource
                . ', state=' . (int) $this->state
                . ', status_ex=' . (int) $this->status_ex
                . ', method=' . (string) ($closure['method'] ?? '?')
                . ', closed=' . ($closed ? 'tak' : 'nie'),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return $closed;
    }

    /**
     * @param mixed $orderGt
     * @param mixed $issueDoc
     */
    protected function applyRemainingQuantitiesToIssueDoc($orderGt, $issueDoc)
    {
        $remainingById = array();
        $remainingBySymbol = array();
        $rodzajMap = self::getOrderPositionTowRodzajMap((int) $this->gt_id);

        for ($i = 1; $i <= $orderGt->Pozycje->Liczba(); $i++) {
            $pos = $orderGt->Pozycje->Element($i);
            $posId = (int) $pos->Id;
            if (isset($rodzajMap[$posId]) && self::isServicePositionKind($rodzajMap[$posId])) {
                continue;
            }
            $toRealize = self::getComPositionQtyToRealize(
                $pos,
                (int) $this->gt_id,
                $this->order_ref,
                $posId
            );
            $entry = array(
                'qty' => $toRealize,
                'zk_pos' => $pos,
            );
            $remainingById[$posId] = $entry;
            $symbol = trim((string) $pos->TowarSymbol);
            if ($symbol !== '') {
                if (!isset($remainingBySymbol[$symbol])) {
                    $remainingBySymbol[$symbol] = array('qty' => 0.0, 'zk_pos' => $pos);
                }
                $remainingBySymbol[$symbol]['qty'] += $toRealize;
            }
        }

        $indicesToRemove = array();
        for ($j = 1; $j <= $issueDoc->Pozycje->Liczba(); $j++) {
            $wzPos = $issueDoc->Pozycje->Element($j);
            $posId = (int) $wzPos->Id;
            $symbol = trim((string) $wzPos->TowarSymbol);
            $qty = null;
            $zkPos = null;

            if (isset($remainingById[$posId])) {
                $qty = (float) $remainingById[$posId]['qty'];
                $zkPos = $remainingById[$posId]['zk_pos'];
            } elseif ($symbol !== '' && isset($remainingBySymbol[$symbol])) {
                $qty = (float) $remainingBySymbol[$symbol]['qty'];
                $zkPos = $remainingBySymbol[$symbol]['zk_pos'];
                unset($remainingBySymbol[$symbol]);
            }

            if ($qty === null || $qty <= 0.00001) {
                $indicesToRemove[] = $j;
                continue;
            }

            $wzPos->IloscJm = $qty;
            $this->copyOrderPricesToIssuePosition($zkPos, $wzPos, $qty);
        }

        if (count($indicesToRemove) > 0) {
            for ($k = count($indicesToRemove) - 1; $k >= 0; $k--) {
                $idx = $indicesToRemove[$k];
                if (method_exists($issueDoc->Pozycje, 'Usun')) {
                    $issueDoc->Pozycje->Usun($idx);
                } else {
                    $issueDoc->Pozycje->Element($idx)->Usun();
                }
            }
        }
    }

    /**
     * Przenosi ceny sprzedaży z pozycji ZK na pozycję WZ (jak przy ręcznym WZ z „ostatnia cena”).
     *
     * @param mixed $zkPos
     * @param mixed $wzPos
     * @param float $qty
     */
    protected function copyOrderPricesToIssuePosition($zkPos, $wzPos, $qty)
    {
        if (!$zkPos || !$wzPos) {
            return;
        }

        $priceNet = self::tryGetComObjectProperty(
            $zkPos,
            array('CenaNetto', 'CenaJednostkowaNetto', 'Cena'),
            null
        );
        $priceGross = self::tryGetComObjectProperty(
            $zkPos,
            array('CenaBrutto', 'CenaJednostkowaBrutto'),
            null
        );

        if ($priceNet !== null && (float) $priceNet > 0.00001) {
            self::trySetComObjectProperty(
                $wzPos,
                array('CenaNetto', 'CenaJednostkowaNetto', 'Cena'),
                (float) $priceNet
            );
        }
        if ($priceGross !== null && (float) $priceGross > 0.00001) {
            self::trySetComObjectProperty(
                $wzPos,
                array('CenaBrutto', 'CenaJednostkowaBrutto'),
                (float) $priceGross
            );
        }

        $orderedQty = self::tryGetComObjectProperty($zkPos, array('IloscJm', 'Ilosc'), 0.0);
        $orderedQty = (float) $orderedQty;
        if ($orderedQty <= 0.00001) {
            $orderedQty = (float) $qty;
        }

        $ratio = min(1.0, (float) $qty / $orderedQty);
        foreach (array(
            array('WartoscNetto', 'WartoscNettoPoRabacie'),
            array('WartoscBrutto', 'WartoscBruttoPoRabacie'),
        ) as $pair) {
            $value = self::tryGetComObjectProperty($zkPos, $pair, null);
            if ($value !== null && (float) $value > 0.00001) {
                self::trySetComObjectProperty($wzPos, $pair, round((float) $value * $ratio, 4));
            }
        }
    }

    /**
     * Uzupełnia ob_CenaNetto/Brutto na WZ z powiązanych pozycji ZK (SQL).
     *
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @return int liczba zaktualizowanych pozycji
     */
    public static function syncIssuePricesFromOrderSql($orderId, array $issueRefs)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || empty($issueRefs)) {
            return 0;
        }

        if (OrderComWriter::comWritesOnly()) {
            return OrderComWriter::syncIssuePricesFromOrder(
                OrderComWriter::resolveSubiektGt(),
                $orderId,
                $issueRefs
            );
        }

        $safeIssueRefs = array();
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef !== '') {
                $safeIssueRefs[] = "'" . str_replace("'", "''", $issueRef) . "'";
            }
        }
        if (empty($safeIssueRefs)) {
            return 0;
        }

        $issueInList = implode(', ', $safeIssueRefs);

        self::linkIssueServicePositionsToOrderSql($orderId, $issueRefs);

        MSSql::getInstance()->query(
            "UPDATE wp
             SET wp.ob_CenaNetto = zk.ob_CenaNetto,
                 wp.ob_CenaBrutto = zk.ob_CenaBrutto,
                 wp.ob_WartNetto = ROUND(wp.ob_Ilosc * zk.ob_CenaNetto, 4),
                 wp.ob_WartBrutto = ROUND(wp.ob_Ilosc * zk.ob_CenaBrutto, 4)
             FROM dok_Pozycja wp
             INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
             INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
             WHERE wz.dok_NrPelny IN ({$issueInList})
               AND (ISNULL(wp.ob_CenaNetto, 0) = 0 OR ISNULL(wp.ob_WartNetto, 0) = 0)
               AND ISNULL(zk.ob_CenaNetto, 0) > 0"
        );

        MSSql::getInstance()->query(
            "UPDATE d
             SET d.dok_WartNetto = agg.w_net,
                 d.dok_WartBrutto = agg.w_brut
             FROM dok__Dokument d
             INNER JOIN (
                 SELECT p.ob_DokMagId,
                        SUM(ISNULL(p.ob_WartNetto, 0)) AS w_net,
                        SUM(ISNULL(p.ob_WartBrutto, 0)) AS w_brut
                 FROM dok_Pozycja p
                 INNER JOIN dok__Dokument wz ON wz.dok_Id = p.ob_DokMagId AND wz.dok_Typ = 11
                 WHERE wz.dok_NrPelny IN ({$issueInList})
                 GROUP BY p.ob_DokMagId
             ) agg ON agg.ob_DokMagId = d.dok_Id
             WHERE d.dok_Typ = 11"
        );

        $rows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok_Pozycja wp
             INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
             INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
             WHERE wz.dok_NrPelny IN ({$issueInList})
               AND ISNULL(wp.ob_CenaNetto, 0) > 0"
        );

        return is_array($rows) && !empty($rows) ? (int) ($rows[0]['cnt'] ?? 0) : 0;
    }

    /**
     * @param mixed $position
     */
    public static function getComPositionQtyToRealize($position, $orderId = null, $orderRef = null, $posId = null)
    {
        if (!$position) {
            return 0.0;
        }

        $ordered = 0.0;
        try {
            $ordered = (float) $position->IloscJm;
        } catch (\Exception $e) {
            return 0.0;
        }

        $realized = self::tryGetComObjectProperty(
            $position,
            array('IloscZrealizowana', 'IloscZreal', 'ObIloscZrealizowana', 'Zrealizowano'),
            null
        );
        if ($realized !== null) {
            return max(0.0, $ordered - (float) $realized);
        }

        if ($orderId !== null && $orderRef !== null && trim((string) $orderRef) !== '') {
            if ($posId === null) {
                try {
                    $posId = (int) $position->Id;
                } catch (\Exception $e) {
                    $posId = 0;
                }
            }
            if ($posId > 0) {
                $rows = MSSql::getInstance()->query(
                    "SELECT zk.ob_Ilosc AS ordered,
                            ISNULL((
                                SELECT SUM(wz_p.ob_Ilosc)
                                FROM dok_Pozycja wz_p
                                INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId
                                    AND wz.dok_Typ = 11 AND wz.dok_Status >= 0
                                WHERE wz_p.ob_DoId = zk.ob_Id
                            ), 0) AS issued
                     FROM dok_Pozycja zk
                     WHERE zk.ob_Id = {$posId} AND zk.ob_DokHanId = " . (int) $orderId
                );
                if (is_array($rows) && !empty($rows)) {
                    $issued = (float) ($rows[0]['issued'] ?? 0);
                    $posOrdered = (float) ($rows[0]['ordered'] ?? $ordered);
                    return max(0.0, $posOrdered - $issued);
                }
            }
        }

        return $ordered;
    }

    /**
     * @param mixed $orderGt
     * @param bool $warehouseGoodsOnly pomiń usługi (nie wydawane na WZ)
     * @param int|null $orderId dok_Id ZK
     * @param string|null $orderRef numer ZK — wymagane do odczytu SQL
     */
    public static function sumRemainingToRealize($orderGt, $warehouseGoodsOnly = false, $orderId = null, $orderRef = null)
    {
        if (!$orderGt) {
            return 0.0;
        }

        if ($orderId !== null && $orderRef !== null && trim((string) $orderRef) !== '') {
            return self::getOrderRemainingQtyFromSql(
                (int) $orderId,
                (string) $orderRef,
                $warehouseGoodsOnly
            );
        }

        $rodzajMap = null;
        if ($warehouseGoodsOnly && $orderId !== null) {
            $rodzajMap = self::getOrderPositionTowRodzajMap((int) $orderId);
        }

        $sum = 0.0;
        for ($i = 1; $i <= $orderGt->Pozycje->Liczba(); $i++) {
            $pos = $orderGt->Pozycje->Element($i);
            if ($rodzajMap !== null) {
                $posId = (int) $pos->Id;
                if (isset($rodzajMap[$posId]) && self::isServicePositionKind($rodzajMap[$posId])) {
                    continue;
                }
            }
            $sum += self::getComPositionQtyToRealize($pos, $orderId, $orderRef);
        }

        return $sum;
    }

    /**
     * Statusy ZK w Subiekcie GT oznaczające zamówienie jawnie niezrealizowane (dok_Status).
     *
     * @param int|null $state
     * @return bool
     */
    public static function isOrderStatusOpen($state)
    {
        return $state !== null && in_array((int) $state, array(5, 6), true);
    }

    /**
     * Czy ZK ma w Subiekcie status zamknięty (dok_Status 7 lub 8).
     *
     * @param int|null $state
     * @return bool
     */
    public static function isOrderStatusFulfilled($state)
    {
        return $state !== null && in_array((int) $state, array(7, 8), true);
    }

    /**
     * Czy ZK jest domknięte operacyjnie: status 7/8 oraz powiązane WZ pokrywa całość.
     * Sam status 7 bez WZ (np. po błędnym zapisie COM) nie blokuje ponownej realizacji.
     *
     * @return bool
     */
    public function isBusinessComplete()
    {
        if (!self::isOrderStatusFulfilled((int) $this->state)) {
            return false;
        }

        $issues = $this->getValidIssueRefsForOrder();
        if (empty($issues)) {
            return false;
        }

        return self::isOrderFullyCoveredByLinkedIssuesSql((int) $this->gt_id, $this->order_ref);
    }

    /**
     * Cofa błędny status 7/8 (bez pokrycia WZ), żeby Subiekt przyjął realizację jako WZ.
     *
     * @return bool
     */
    public function reopenOrderForIssueIfNeeded()
    {
        if (!$this->orderGt || $this->isZkFulfilledForApi()) {
            return false;
        }

        if (!self::isOrderStatusFulfilled((int) $this->state)) {
            return false;
        }

        $previousState = (int) $this->state;
        $targetStatus = $this->orderHadReservationForClose($previousState) ? 5 : 6;

        $reopened = false;
        $statusSet = self::trySetComObjectProperty(
            $this->orderGt,
            array('Status', 'StatusDokumentu', 'StanDokumentu'),
            $targetStatus
        );
        if ($statusSet !== false) {
            try {
                $this->orderGt->Przelicz();
                $this->orderGt->Zapisz();
                $this->reloadOrderFromGt();
                $reopened = true;
            } catch (\Exception $e) {
                Logger::getInstance()->log(
                    'api',
                    'reopenOrderForIssueIfNeeded: ' . $e->getMessage(),
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
            }
        }

        if (!$reopened && !OrderComWriter::comWritesOnly()) {
            $reopened = self::reopenOrderStatusInSql((int) $this->gt_id, $targetStatus === 5);
            if ($reopened) {
                $this->reloadOrderFromGt();
            }
        } elseif (!$reopened && OrderComWriter::comWritesOnly()) {
            $reopened = OrderComWriter::reopenOrderStatus(
                $this->subiektGt,
                (int) $this->gt_id,
                $this->order_ref,
                $targetStatus === 5
            );
            if ($reopened) {
                $this->reloadOrderFromGt();
            }
        }

        if ($reopened) {
            Logger::getInstance()->log(
                'api',
                'reopenOrderForIssueIfNeeded: ' . $this->order_ref
                    . ' status ' . $previousState . ' → ' . $targetStatus,
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            return true;
        }

        return false;
    }

    /**
     * Czy ZK jest w pełni zrealizowane — wyłącznie dok_Status / COM Status 7 lub 8.
     * StatusEx i COM bez zgodnego statusu SQL nie blokują realizacji (ZK 5/6 = otwarte).
     *
     * @param mixed $orderGt
     */
    public static function isOrderComFullyRealized($orderGt, $state = null, $statusEx = null)
    {
        if (self::isOrderStatusFulfilled($state)) {
            return true;
        }

        if (self::isOrderStatusOpen($state)) {
            return false;
        }

        if (!$orderGt) {
            return false;
        }

        if ($state === null || (int) $state < 0) {
            $comState = self::tryGetComObjectProperty(
                $orderGt,
                array('Status', 'StatusDokumentu', 'StanDokumentu'),
                null
            );
            if ($comState !== null) {
                $comState = (int) $comState;
                if (self::isOrderStatusFulfilled($comState)) {
                    return true;
                }
                if (self::isOrderStatusOpen($comState)) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * @param array $product
     * @param mixed $comPos
     * @param float|null $orderedQtyFallback
     * @return array
     */
    public static function appendRealizationFieldsToProduct(array $product, $comPos, $orderedQtyFallback = null)
    {
        $ordered = $orderedQtyFallback;
        if ($ordered === null) {
            $ordered = isset($product['qty']) ? (float) $product['qty'] : 0.0;
        }

        $toRealize = self::getComPositionQtyToRealize($comPos);
        if ($comPos === null) {
            $toRealize = $ordered;
        }

        $realized = max(0.0, (float) $ordered - $toRealize);
        $product['qty_to_realize'] = $toRealize;
        $product['qty_realized'] = $realized;
        $product['qty_left'] = $toRealize;
        $product['to_realize'] = $toRealize;
        $product['do_realizacji'] = $toRealize;

        return $product;
    }

    /**
     * @param string|null $docRef
     * @param bool $alreadyExists
     * @return array
     */
    protected function buildWzResultPayload($docRef, $alreadyExists)
    {
        $fullyRealized = $this->isZkFulfilledForApi();

        return array(
            'order_ref' => $this->order_ref,
            'doc_ref' => $docRef,
            'document_ref' => $docRef,
            'issue_ref' => $docRef,
            'doc_type' => 11,
            'already_exists' => (bool) $alreadyExists,
            'fully_realized' => $fullyRealized,
            'is_realized' => $fullyRealized,
            'is_fulfilled' => $fullyRealized,
            'fulfilled' => $fullyRealized,
            'reservation' => (bool) $this->reservation,
            'remaining_qty' => self::sumRemainingToRealize($this->orderGt, false, (int) $this->gt_id, $this->order_ref),
            'state' => (int) $this->state,
            'status_ex' => (int) $this->status_ex,
            'issue_documents' => $this->getValidIssueRefsForOrder(),
            'wz_refs' => $this->getValidIssueRefsForOrder(),
        );
    }

    /**
     * @param bool $fulfilled
     * @param string|null $message
     * @return array
     */
    protected function buildFulfillResultPayload($fulfilled, $message = null)
    {
        $fulfilled = (bool) $fulfilled;
        $payload = array(
            'order_ref' => $this->order_ref,
            'fulfilled' => $fulfilled,
            'fully_realized' => $fulfilled,
            'is_realized' => $fulfilled,
            'is_fulfilled' => $fulfilled,
            'reservation' => $fulfilled ? false : (bool) $this->reservation,
            'state' => (int) $this->state,
            'status_ex' => (int) $this->status_ex,
            'remaining_qty' => self::sumRemainingToRealize($this->orderGt, false, (int) $this->gt_id, $this->order_ref),
            'issue_documents' => $this->getValidIssueRefsForOrder(),
            'wz_refs' => $this->getValidIssueRefsForOrder(),
        );
        if ($message !== null) {
            $payload['message'] = $message;
        }
        return $payload;
    }

    /**
     * Ustawia flagę rezerwacji towaru dla istniejącego zamówienia.
     * Domyślnie włącza rezerwację (reservation=true). Aby wyłączyć, przekaż reservation=false.
     *
     * @return array
     * @throws Exception
     */
    public function reserve()
    {
        if (!$this->order_ref) {
            throw new Exception('Brak parametru order_ref – nie można zidentyfikować zamówienia.');
        }
        if (!$this->is_exists || !$this->orderGt) {
            throw new Exception('Zamówienie nie istnieje lub nie udało się go wczytać: ' . $this->order_ref);
        }
        if ($this->order_processing) {
            throw new Exception('Nie można zmienić rezerwacji dla zamówienia już przetworzonego: ' . $this->order_ref);
        }

        $reservationRequested = true;
        if (isset($this->orderDetail['reservation'])) {
            $reservationRequested = filter_var($this->orderDetail['reservation'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($reservationRequested === null) {
                throw new Exception('Parametr reservation musi być typu bool (true/false).');
            }
        }

        if ($reservationRequested && $this->orderNeedsComReservationSync()) {
            $sync = $this->syncOrderReservationInCom(true);
            return array(
                'order_ref' => $this->order_ref,
                'reservation' => (bool) ($sync['reservation'] ?? true),
                'synced' => (bool) ($sync['synced'] ?? false),
                'skipped' => !empty($sync['skipped']) ? $sync['skipped'] : null,
                'error' => $sync['error'] ?? null,
            );
        }

        $comReserved = (bool) ($this->orderGt->Rezerwacja ?? false);
        $sqlReserved = self::orderHasActiveReservationSql((int) $this->gt_id, $this->order_ref);
        if ($reservationRequested && $comReserved && $sqlReserved) {
            Logger::getInstance()->log(
                'api',
                'reserve: pomijam — ZK ' . $this->order_ref . ' ma rezerwację w SQL i COM',
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            return array(
                'order_ref' => $this->order_ref,
                'reservation' => true,
                'skipped' => 'already_reserved',
            );
        }

        $this->reservation = $reservationRequested;
        $sync = $this->syncOrderReservationInCom($this->reservation);
        if (!($sync['synced'] ?? false)) {
            throw new Exception(
                'Nie udało się zapisać rezerwacji w COM: ' . ($sync['error'] ?? 'nieznany błąd')
            );
        }

        Logger::getInstance()->log(
            'api',
            'reserve: zaktualizowano rezerwację dla ' . $this->order_ref . ' na ' . ($this->reservation ? 'tak' : 'nie'),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return [
            'order_ref' => $this->order_ref,
            'reservation' => (bool)$this->reservation
        ];
    }


    public function add()
{
    $this->customer = isset($this->orderDetail['customer']) ? $this->orderDetail['customer'] : false;
    if (!$this->customer) {
        throw new Exception('Brak danych "customer" dla zamówienia!', 1);
    }
    if (!$this->products) {
        throw new Exception('Brak danych "products" dla zamówienia!', 1);
    }

    $taxId = $this->customer['tax_id'] ?? '';

    // Logowanie przed przetwarzaniem NIP
    Logger::getInstance()->log('api', 'Otrzymany NIP przed przetwarzaniem: ' . $taxId, __CLASS__ . '->' . __FUNCTION__, __LINE__);

    if (empty($taxId)) {
        throw new Exception('Brak NIP-u w danych klienta!', 1);
    }

    // Tworzenie nowego kontrahenta
    $customer = new Customer($this->subiektGt, $this->customer);

    Logger::getInstance()->log('api', 'Kontrahent po inicjalizacji: ' . json_encode($this->customer), __CLASS__ . '->' . __FUNCTION__, __LINE__);

    if (!$customer->isExists()) {
        Logger::getInstance()->log('api', 'Tworzenie nowego kontrahenta o NIP: ' . $taxId, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        $customer->add();

        // Logowanie po utworzeniu nowego kontrahenta
        Logger::getInstance()->log('api', 'Nowy kontrahent utworzony z NIP: ' . $taxId, __CLASS__ . '->' . __FUNCTION__, __LINE__);

        $customer = new Customer($this->subiektGt, $this->customer);
        
    }

    $cust_data = $customer->get();
    Logger::getInstance()->log('api', 'Kontrahent o NIP: ' . $cust_data['tax_id'] . ' istnieje w systemie.', __CLASS__ . '->' . __FUNCTION__, __LINE__);

    // Tworzenie zamówienia
    $this->orderGt = $this->subiektGt->SuDokumentyManager->DodajZK();
    $this->orderGt->KontrahentId = intval($cust_data['gt_id']);

    foreach ($this->products as $p) {
        $add_position = $this->addPosition($p);
        if (!$add_position) {
            throw new Exception('Nie odnaleziono towaru o podanym kodzie: ' . $p['code'], 1);
        }
    }

    $this->orderGt->Przelicz();
    $this->amount = $this->orderGt->WartoscBrutto;
    // Sprawdź czy istnieje pole caretaker w orderDetail, jeśli tak - użyj go, w przeciwnym razie użyj użytkownika z konfiguracji
    if (isset($this->orderDetail['caretaker']) && !empty($this->orderDetail['caretaker'])) {
        $this->orderGt->Wystawil = Helper::toWin($this->orderDetail['caretaker']);
        Logger::getInstance()->log('api', 'Użyto użytkownika z zapytania (caretaker): ' . $this->orderDetail['caretaker'], __CLASS__ . '->' . __FUNCTION__, __LINE__);
    } else {
        $this->orderGt->Wystawil = Helper::toWin($this->cfg->getIdPerson());
        Logger::getInstance()->log('api', 'Użyto użytkownika z konfiguracji: ' . $this->cfg->getIdPerson(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
    }
    $this->setGtObject();
    $this->orderGt->Zapisz();

    Logger::getInstance()->log('api', 'Utworzono zamówienie dla kontrahenta o NIP: ' . $taxId, __CLASS__ . '->' . __FUNCTION__, __LINE__);

    return [
        'order_ref' => $this->orderGt->NumerPelny,
        'order_amount' => $this->amount
    ];
}


    /**
     * Aktualizuje istniejące zamówienie: nagłówek (reference, comments, reservation, amount)
     * oraz pozycje, jeśli w data podano tablicę products.
     * products = pełna lista pozycji (kod, qty, price, opcjonalnie price_before_discount, supplier_code) – zastępuje całą listę.
     *
     * @return array ['order_ref' => string, 'order_amount' => float, 'positions_count' => int] lub wyjątek
     */
    public function update()
    {
        Logger::getInstance()->log('api', 'update: start, order_ref=' . ($this->order_ref ?? '(null)') . ', is_exists=' . ($this->is_exists ? '1' : '0') . ', order_processing=' . ($this->order_processing ? '1' : '0'), __CLASS__ . '->' . __FUNCTION__, __LINE__);

        if (!$this->order_ref) {
            Logger::getInstance()->log('api', 'update: odrzucono – brak order_ref', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            throw new Exception('Brak parametru order_ref – nie można zidentyfikować zamówienia do edycji.');
        }
        if (!$this->is_exists || !$this->orderGt) {
            Logger::getInstance()->log('api', 'update: odrzucono – zamówienie nie wczytane (is_exists=' . ($this->is_exists ? '1' : '0') . ')', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            throw new Exception('Zamówienie nie istnieje lub nie udało się go wczytać: ' . $this->order_ref);
        }
        if ($this->order_processing) {
            Logger::getInstance()->log('api', 'update: odrzucono – zamówienie już przetworzone', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            throw new Exception('Nie można edytować zamówienia już przetworzonego na dokument sprzedaży: ' . $this->order_ref);
        }

        $products = isset($this->orderDetail['products']) && is_array($this->orderDetail['products'])
            ? $this->orderDetail['products']
            : null;
        $clearAllPositions = !empty($this->orderDetail['clear_all_positions']);

        $productsCount = $products === null ? 'null' : count($products);
        Logger::getInstance()->log('api', 'update: products w data = ' . $productsCount . ', clear_all_positions = ' . ($clearAllPositions ? 'tak' : 'nie') . ($products !== null && count($products) > 0 ? ', pierwszy code=' . (isset($products[0]['code']) ? $products[0]['code'] : '?') : ''), __CLASS__ . '->' . __FUNCTION__, __LINE__);

        // Usuń wszystkie pozycje: gdy products=[] LUB gdy zewnętrzna aplikacja wysłała clear_all_positions: true
        if ($clearAllPositions || ($products !== null && count($products) === 0)) {
            Logger::getInstance()->log('api', 'update: usuwam wszystkie pozycje (products puste lub clear_all_positions=true)', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            $this->clearAllPositions();
        }

        if ($products !== null && count($products) > 0) {
            Logger::getInstance()->log('api', 'update: synchronizuję pozycje (aktualizacja wg code + dodawanie nowych)', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            $this->syncPositionsWithProducts($products);
        } elseif (!$clearAllPositions && $products === null) {
            Logger::getInstance()->log('api', 'update: brak products w data – tylko aktualizacja nagłówka', __CLASS__ . '->' . __FUNCTION__, __LINE__);
        }

        Logger::getInstance()->log('api', 'update: wywołuję Przelicz(), pozycji przed=' . $this->orderGt->Pozycje->Liczba(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
        $this->orderGt->Przelicz();
        $this->amount = $this->orderGt->WartoscBrutto;
        Logger::getInstance()->log('api', 'update: po Przelicz WartoscBrutto=' . $this->amount, __CLASS__ . '->' . __FUNCTION__, __LINE__);

        // Pełna treść uwag z żądania (UTF-8, bez ucinania) + dopisanie nr przesyłki (bez duplikatu)
        $comments = isset($this->orderDetail['comments']) ? (string)$this->orderDetail['comments'] : '';
        // Usuń ewentualną istniejącą linię "Nr przesyłki: ...", żeby nie dublować przy kolejnej aktualizacji
        $lines = preg_split('/\r\n|\r|\n/', $comments);
        $lines = array_filter($lines, function ($line) {
            return stripos(trim($line), 'Nr przesyłki:') !== 0;
        });
        $comments = trim(implode("\n", $lines));
        if (!empty($this->orderDetail['shipment_number'])) {
            $comments .= "\nNr przesyłki: " . trim((string)$this->orderDetail['shipment_number']);
        }
        // Nowa linia przed "Adres dostawy:" i "Nr przesyłki:" gdy przyszło wszystko w jednej linii
        $comments = preg_replace('/(?<!\n)(Adres dostawy:)/u', "\n$1", $comments);
        $comments = preg_replace('/(?<!\n)(Nr przesyłki:)/u', "\n$1", $comments);
        // Znaki nowej linii w formacie Windows (CRLF), żeby Subiekt GT wyświetlał każdą sekcję w nowej linii
        $comments = str_replace(["\r\n", "\r"], "\n", $comments);
        $comments = str_replace("\n", "\r\n", $comments);
        // Subiekt GT oczekuje ISO-8859-2 (tak jak w Helper::toWin w całym projekcie)
        if ($comments !== '') {
            $this->comments = Helper::toWin($comments);
        }
        if (isset($this->orderDetail['reference']) && (string)$this->orderDetail['reference'] !== '') {
            $this->reference = Helper::toWin((string)$this->orderDetail['reference']);
        }

        if (isset($this->orderDetail['reservation'])) {
            $reservationRequested = filter_var($this->orderDetail['reservation'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($reservationRequested !== null) {
                $this->reservation = $reservationRequested;
            }
        }

        $commentsPreview = isset($this->orderDetail['comments']) ? substr((string)$this->orderDetail['comments'], 0, 50) : '';
        Logger::getInstance()->log('api', 'update: setGtObject (reference=' . ($this->reference ?? '') . ', comments=' . $commentsPreview . ', reservation=' . ($this->reservation ? 'tak' : 'nie') . ', shipment_number=' . (isset($this->orderDetail['shipment_number']) ? 'tak' : 'nie') . ')', __CLASS__ . '->' . __FUNCTION__, __LINE__);
        $this->setGtObject();
        $this->orderGt->Przelicz();
        $this->amount = $this->orderGt->WartoscBrutto;

        try {
            Logger::getInstance()->log('api', 'update: wywołuję Zapisz() dla ' . $this->order_ref, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            $this->orderGt->Zapisz();
            Logger::getInstance()->log('api', 'update: Zapisz() zakończone OK', __CLASS__ . '->' . __FUNCTION__, __LINE__);
        } catch (\Exception $e) {
            Logger::getInstance()->log('api', 'update: Zapisz() BŁĄD: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            throw $e;
        }

        $positions_count = $this->orderGt->Pozycje->Liczba();
        Logger::getInstance()->log('api', 'update: sukces, order_ref=' . $this->order_ref . ', order_amount=' . $this->amount . ', positions_count=' . $positions_count, __CLASS__ . '->' . __FUNCTION__, __LINE__);

        return [
            'order_ref' => $this->order_ref,
            'order_amount' => $this->amount,
            'positions_count' => $positions_count,
            'reservation' => (bool) $this->reservation,
        ];
    }

    public function getGt()
    {
        return $this->orderGt;
    }

    public function setFlag()
    {
        if (!$this->is_exists) {
            return false;
        }
        //$this->subiektGt->UstawFlageWlasna($this->id_flag,$this->orderGt->Identyfikator,$this->flag_txt,"");
        parent::flag(intval($this->id_gr_flag), $this->flag_name, '');
        return array('order_ref' => $this->order_ref,
            'flag_name' => $this->flag_name,
            'id_gr_flag' => $this->id_gr_flag);
    }

    public function delete()
    {
        if (!$this->orderGt) {
            return false;
        }

        $this->orderGt->Usun(false);
        return array('order_ref' => $this->order_ref);
    }

    //get pdf file in base64
    protected function getPdfInBase64($gtObject)
    {
        $temp_dir = sys_get_temp_dir();
        $file_name = $temp_dir . '/' . $gtObject->Identyfikator . '.pdf';
        $gtObject->DrukujDoPliku($file_name, 0);
        $pdf_file = file_get_contents($file_name);
        unlink($file_name);
        return base64_encode($pdf_file);
    }

    public function getRecentOrders($limit = 300, $orderBy = 'date_created', $orderDirection = 'desc')
    {
        try {
            $current_date = date('Y-m-d');
            $sql = "SELECT TOP {$limit} 
                        d.dok_Id,
                        d.dok_NrPelny as order_ref,
                        d.dok_NrPelnyOryg as reference,
                        d.dok_WartBrutto as amount,
                        d.dok_WartMag as projected_value,
                        (d.dok_WartTwNetto - d.dok_WartMag) as projected_profit,
                        d.dok_Status as state,
                        d.dok_TerminRealizacji as date_of_delivery,
                        d.dok_Uwagi as comments,
                        d.dok_DataWyst as date_created,
                        k.adr_NIP as customer_tax_id,
                        k.adr_NazwaPelna as customer_name,
                        k.kh_EMail as customer_email,
                        k.adr_Telefon as customer_phone,
                        k.adr_Adres as customer_address,
                        k.adr_Kod as customer_post_code,
                        k.adr_Miejscowosc as customer_city
                    FROM dok__Dokument d
                    LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                    WHERE d.dok_Typ = 16  -- Typ dokumentu ZK (Zamówienie Klienta)
                    AND d.dok_DataWyst >= '{$current_date}'  -- Tylko zamówienia od dzisiejszej daty
                    ORDER BY d.dok_DataWyst {$orderDirection}";
            
            $data = MSSql::getInstance()->query($sql);
            
            if (!is_array($data)) {
                return array(
                    'state' => 'error',
                    'message' => 'Brak danych zamówień'
                );
            }
            
            return array(
                'state' => 'success',
                'data' => $data
            );
            
        } catch (Exception $e) {
            return array(
                'state' => 'error',
                'message' => 'Błąd pobierania ostatnich zamówień: ' . $e->getMessage()
            );
        }
    }

    /**
     * Pobiera zamówienia z bieżącego miesiąca z informacją o opiekunie klienta
     * Funkcja synchronizacji - sprawdza opiekuna dla już pobranych zamówień
     * 
     * @param int $limit Maksymalna liczba zamówień do pobrania
     * @param array $existing_orders Array istniejących zamówień z zewnętrznego systemu
     * @return array Wynik z zamówieniami i informacją o zmianach opiekuna
     */
    public function getCurrentMonthOrdersWithCaretakerSync($limit = 1000, $existing_orders = [])
    {
        try {
            $current_month_start = date('Y-m-01'); // Pierwszy dzień bieżącego miesiąca
            $current_month_end = date('Y-m-t');    // Ostatni dzień bieżącego miesiąca
            
            Logger::getInstance()->log('api', 'Rozpoczęcie synchronizacji zamówień z miesiąca: ' . $current_month_start . ' - ' . $current_month_end, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            $sql = "SELECT TOP {$limit} 
                        d.dok_Id,
                        d.dok_NrPelny as order_ref,
                        d.dok_NrPelnyOryg as reference,
                        d.dok_WartBrutto as amount,
                        d.dok_WartNetto as amount_net,
                        d.dok_WartVat as amount_vat,
                        d.dok_WartMag as projected_value,
                        (d.dok_WartTwNetto - d.dok_WartMag) as projected_profit,
                        d.dok_Status as state,
                        d.dok_TerminRealizacji as date_of_delivery,
                        d.dok_Uwagi as comments,
                        d.dok_DataWyst as date_created,
                        d.dok_StatusKsieg as accounting_state,
                        k.kh_Id as customer_id,
                        k.kh_Symbol as customer_ref_id,
                        k.adr_NIP as customer_tax_id,
                        k.adr_NazwaPelna as customer_name,
                        k.kh_EMail as customer_email,
                        k.adr_Telefon as customer_phone,
                        k.adr_Adres as customer_address,
                        k.adr_Kod as customer_post_code,
                        k.adr_Miejscowosc as customer_city,
                        k.CrmOsobaKontaktowa as customer_caretaker
                    FROM dok__Dokument d
                    LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                    WHERE d.dok_Typ = 16  -- Typ dokumentu ZK (Zamówienie Klienta)
                    AND d.dok_DataWyst >= '{$current_month_start}'
                    AND d.dok_DataWyst <= '{$current_month_end}'
                    ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";
            
            Logger::getInstance()->log('api', 'Wykonuję zapytanie SQL po zamówienia: ' . $sql, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            $data = MSSql::getInstance()->query($sql);
            
            if (!is_array($data)) {
                return array(
                    'state' => 'error',
                    'message' => 'Błąd podczas pobierania danych z bazy'
                );
            }
            
            Logger::getInstance()->log('api', 'Znaleziono zamówień: ' . count($data), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            $orders = [];
            $caretaker_changes = [];
            $new_orders = [];
            $updated_orders = [];
            
            // Tworzymy mapę istniejących zamówień dla szybkiego wyszukiwania
            $existing_orders_map = [];
            foreach ($existing_orders as $existing_order) {
                $order_ref = $existing_order['order_ref'] ?? $existing_order['doc_ref'] ?? '';
                if ($order_ref) {
                    $existing_orders_map[$order_ref] = $existing_order;
                }
            }
            
            foreach ($data as $row) {
                $order_ref = $row['order_ref'];
                $current_caretaker = $row['customer_caretaker'];
                
                // Pobieramy pozycje zamówienia
                $positions = $this->getPositionsByOrderId($row['dok_Id']);
                
                $order_data = [
                    'order_ref' => $order_ref,
                    'reference' => $row['reference'],
                    'amount' => $row['amount'],
                    'amount_net' => $row['amount_net'],
                    'amount_vat' => $row['amount_vat'],
                    'projected_value' => $row['projected_value'],
                    'projected_profit' => $row['projected_profit'],
                    'state' => $row['state'],
                    'accounting_state' => $row['accounting_state'],
                    'date_of_delivery' => $row['date_of_delivery'],
                    'comments' => $row['comments'],
                    'date_created' => $row['date_created'],
                    'customer' => [
                        'id' => $row['customer_id'],
                        'ref_id' => $row['customer_ref_id'],
                        'tax_id' => $row['customer_tax_id'],
                        'name' => $row['customer_name'],
                        'email' => $row['customer_email'],
                        'phone' => $row['customer_phone'],
                        'address' => $row['customer_address'],
                        'post_code' => $row['customer_post_code'],
                        'city' => $row['customer_city'],
                        'caretaker' => $current_caretaker
                    ],
                    'positions' => $positions
                ];
                
                $orders[] = $order_data;
                
                // Sprawdzamy czy zamówienie już istnieje w zewnętrznym systemie
                if (isset($existing_orders_map[$order_ref])) {
                    $existing_order = $existing_orders_map[$order_ref];
                    $existing_caretaker = $existing_order['customer']['caretaker'] ?? '';
                    
                    // Sprawdzamy czy opiekun się zmienił
                    if ($existing_caretaker !== $current_caretaker) {
                        $caretaker_changes[] = [
                            'order_ref' => $order_ref,
                            'customer_name' => $row['customer_name'],
                            'customer_tax_id' => $row['customer_tax_id'],
                            'old_caretaker' => $existing_caretaker,
                            'new_caretaker' => $current_caretaker,
                            'change_date' => date('Y-m-d H:i:s')
                        ];
                        
                        $updated_orders[] = $order_data;
                        
                        Logger::getInstance()->log('api', "Zmiana opiekuna dla zamówienia {$order_ref}: '{$existing_caretaker}' -> '{$current_caretaker}'", __CLASS__ . '->' . __FUNCTION__, __LINE__);
                    }
                } else {
                    // Nowe zamówienie
                    $new_orders[] = $order_data;
                }
            }
            
            $result = [
                'date_range' => [
                    'from' => $current_month_start,
                    'to' => $current_month_end
                ],
                'orders' => $orders,
                'summary' => [
                    'total_count' => count($orders),
                    'new_orders_count' => count($new_orders),
                    'updated_orders_count' => count($updated_orders),
                    'caretaker_changes_count' => count($caretaker_changes)
                ],
                'caretaker_changes' => $caretaker_changes,
                'new_orders' => $new_orders,
                'updated_orders' => $updated_orders
            ];
            
            Logger::getInstance()->log('api', 'Synchronizacja zakończona: nowe=' . count($new_orders) . ', zaktualizowane=' . count($updated_orders) . ', zmiany opiekuna=' . count($caretaker_changes), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            return array(
                'state' => 'success',
                'data' => $result
            );
            
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas synchronizacji zamówień: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return array(
                'state' => 'error',
                'message' => 'Błąd synchronizacji zamówień: ' . $e->getMessage()
            );
        }
    }

    /**
     * Buduje pełny numer ZK z sekwencji (np. 2866 → ZK 2866/06/2026).
     *
     * @param string|int $sequence
     * @param int|null $month
     * @param int|null $year
     * @return string
     */
    public static function buildOrderRefFromSequence($sequence, $month = null, $year = null)
    {
        $ref = trim((string) $sequence);
        if ($ref === '') {
            return '';
        }
        if (preg_match('/^ZK\s/i', $ref)) {
            return $ref;
        }

        $month = $month !== null ? (int) $month : (int) date('n');
        $year = $year !== null ? (int) $year : (int) date('Y');
        $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        return 'ZK ' . $ref . '/' . $monthStr . '/' . $year;
    }

    /**
     * Pełny numer WZ z samego numeru sekwencji (np. 3013 → WZ 3013/06/2026).
     *
     * @param string $sequence
     * @param int|null $month
     * @param int|null $year
     * @return string
     */
    public static function buildIssueRefFromSequence($sequence, $month = null, $year = null)
    {
        $ref = trim((string) $sequence);
        if ($ref === '') {
            return '';
        }
        if (preg_match('/^WZ\s/i', $ref)) {
            return $ref;
        }

        $month = $month !== null ? (int) $month : (int) date('n');
        $year = $year !== null ? (int) $year : (int) date('Y');
        $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        return 'WZ ' . $ref . '/' . $monthStr . '/' . $year;
    }

    /**
     * Normalizuje listę numerów WZ (pełny ref lub sam numer + miesiąc/rok).
     *
     * @param string $orderRef
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @param int $month
     * @param int $year
     * @return array<int, string>
     */
    public static function resolveIssueRefsInput($orderRef, $orderId, array $issueRefs, $month, $year)
    {
        $resolved = array();
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef === '') {
                continue;
            }
            if (preg_match('/^WZ\s/i', $issueRef)) {
                $resolved[] = $issueRef;
                continue;
            }
            $built = self::buildIssueRefFromSequence($issueRef, $month, $year);
            if ($built !== '' && self::getIssueDocumentRowByRef($built) !== null) {
                $resolved[] = $built;
                continue;
            }
            $resolved[] = $issueRef;
        }

        if (empty($resolved) && $orderId > 0) {
            return self::getIssueRefsForOrder($orderRef, $orderId);
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Etykieta statusu ZK (dok_Status).
     *
     * @param int|null $state
     * @return string
     */
    public static function getOrderStatusLabel($state)
    {
        switch ((int) $state) {
            case 5:
                return 'niezrealizowane (rezerwacja)';
            case 6:
                return 'niezrealizowane';
            case 7:
                return 'zrealizowane (rezerwacja)';
            case 8:
                return 'zrealizowane';
            default:
                return 'status ' . (int) $state;
        }
    }

    /**
     * Znaczenie dok_StatusEx dla ZK (wg dokumentacji InsERT).
     *
     * @param int|null $statusEx
     * @return string
     */
    public static function getOrderStatusExLabel($statusEx)
    {
        $flags = array();
        $value = (int) $statusEx;
        if ($value === 0) {
            return 'nie zrealizowane';
        }
        if ($value & 1) {
            $flags[] = 'częściowo';
        }
        if ($value & 2) {
            $flags[] = 'różnicowo';
        }
        if ($value & 4) {
            $flags[] = 'całkowicie';
        }
        if ($value & 8) {
            $flags[] = 'FSzal pośrednia';
        }
        if ($value & 16) {
            $flags[] = 'FSzal końcowa';
        }
        if (empty($flags)) {
            return 'status_ex ' . $value;
        }

        return implode(', ', $flags);
    }

    /**
     * WZ powiązane z pozycjami ZK (ob_DoId), bez walidacji treści.
     *
     * @param int $orderId
     * @return array
     */
    public static function findWzCandidatesLinkedByPositionsSql($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return array();
        }

        $sql = "SELECT DISTINCT wz.dok_Id, wz.dok_NrPelny, wz.dok_NrPelnyOryg, wz.dok_Status
                FROM dok_Pozycja zk
                INNER JOIN dok_Pozycja wz_p ON wz_p.ob_DoId = zk.ob_Id
                INNER JOIN dok__Dokument wz ON wz.dok_Id = wz_p.ob_DokMagId
                    AND wz.dok_Typ = 11
                WHERE zk.ob_DokHanId = {$orderId}
                ORDER BY wz.dok_Id DESC";
        $rows = MSSql::getInstance()->query($sql);
        if (!is_array($rows)) {
            return array();
        }

        $candidates = array();
        foreach ($rows as $row) {
            $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $candidates[] = array(
                'issue_ref' => $ref,
                'issue_oryg' => trim((string) ($row['dok_NrPelnyOryg'] ?? '')),
                'issue_status' => (int) ($row['dok_Status'] ?? -1),
            );
        }

        return $candidates;
    }

    /**
     * Szczegóły diagnostyczne dla audytu ZK ↔ WZ.
     *
     * @return array
     */
    protected function collectIssueCoverageDiagnosis()
    {
        $orderId = (int) $this->gt_id;
        $state = (int) $this->state;
        $statusEx = (int) $this->status_ex;
        $remainingQty = self::sumRemainingToRealize($this->orderGt, false, (int) $this->gt_id, $this->order_ref);
        $remainingQtyGoods = self::sumRemainingToRealize($this->orderGt, true, $orderId, $this->order_ref);
        $reservation = (bool) ($this->orderGt->Rezerwacja ?? $this->reservation);

        $comStatus = self::tryGetComObjectProperty(
            $this->orderGt,
            array('Status', 'StatusDokumentu', 'StanDokumentu'),
            null
        );
        if ($comStatus !== null) {
            $comStatus = (int) $comStatus;
        }

        $linkedCandidates = self::findWzCandidatesLinkedByPositionsSql($orderId);
        foreach ($linkedCandidates as $index => $candidate) {
            $linkedCandidates[$index]['linked_to_order'] = self::isIssueDocumentLinkedToOrder(
                $candidate['issue_ref'],
                $orderId,
                $this->order_ref
            );
        }
        $statusInconsistent = self::isOrderStatusFulfilled($state)
            && ($remainingQtyGoods > 0.00001 || empty($this->getValidIssueRefsForOrder()));

        $hints = array();
        if ($statusInconsistent) {
            $hints[] = 'dok_Status=' . $state . ' (' . self::getOrderStatusLabel($state) . ') '
                . 'nie zgadza się z remaining_qty=' . $remainingQty
                . ' — typowy efekt częściowego zapisu COM bez WZ';
        }
        if (empty(self::getIssueRefsForOrder($this->order_ref, $orderId)) && !empty($linkedCandidates)) {
            $hints[] = 'Są WZ powiązane przez ob_DoId, ale brak dopasowania po pozostałych polach';
        }
        $doDokCandidates = self::findIssueRefsLinkedFromOrderDocumentSql($orderId);
        $wzFromWzSide = self::findIssueRefsByDoDokIdSql($orderId);
        if (empty(self::getIssueRefsForOrder($this->order_ref, $orderId)) && empty($linkedCandidates) && empty($doDokCandidates)) {
            $hints[] = 'W bazie nie ma WZ powiązanego z ZK ' . $this->order_ref
                . ' (sprawdź ZK.dok_DoDokId / dok_DoDokNrPelny, WZ.dok_NrPelnyOryg, ob_DoId)';
        } elseif (!empty($doDokCandidates)) {
            $hints[] = 'Subiekt powiązał WZ na nagłówku ZK (dok_DoDokId / dok_DoDokNrPelny)';
        }
        if ($reservation && $remainingQty > 0.00001) {
            $hints[] = 'W Subiekcie lista może pokazywać „Rezerwuj stany magazynowe” '
                . '(Rezerwacja=true, pozycje niezrealizowane) mimo dok_Status=' . $state;
        }
        if ($statusEx & 4 && $remainingQtyGoods > 0.00001) {
            $hints[] = 'dok_StatusEx=4 (całkowicie) jest sprzeczne z remaining_qty_goods>0';
        }
        if ($remainingQty > 0.00001 && $remainingQtyGoods <= 0.00001) {
            $hints[] = 'Pozostałości dotyczą wyłącznie usług (np. transport) — nie blokują domknięcia WZ';
        }

        return array(
            'com_status' => $comStatus,
            'com_status_label' => $comStatus !== null ? self::getOrderStatusLabel($comStatus) : null,
            'sql_status' => $state,
            'sql_status_ex' => $statusEx,
            'status_ex_label' => self::getOrderStatusExLabel($statusEx),
            'reservation' => $reservation,
            'status_inconsistent' => $statusInconsistent,
            'remaining_qty_goods' => $remainingQtyGoods,
            'wz_candidates_by_oryg' => self::getIssueRefsForOrder($this->order_ref, $orderId),
            'wz_candidates_by_zk_do_dok' => $doDokCandidates,
            'wz_candidates_by_wz_do_dok_id' => $wzFromWzSide,
            'wz_candidates_by_position_link' => $linkedCandidates,
            'hints' => $hints,
        );
    }

    /**
     * Otwarte ZK z bieżącego miesiąca, które mają powiązane WZ (wg dok_NrPelnyOryg).
     *
     * @param int $month
     * @param int $year
     * @return array
     */
    public static function findOpenOrdersWithIssueInMonth($month, $year)
    {
        $month = (int) $month;
        $year = (int) $year;
        if ($month < 1 || $month > 12 || $year < 2000) {
            return array();
        }

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $sql = "SELECT d.dok_NrPelny AS order_ref
                FROM dok__Dokument d
                WHERE d.dok_Typ = 16
                AND d.dok_Status IN (5, 6)
                AND d.dok_DataWyst >= '{$monthStart}'
                AND d.dok_DataWyst <= '{$monthEnd}'
                AND EXISTS (
                    SELECT 1
                    FROM dok__Dokument wz
                    WHERE wz.dok_Typ = 11
                    AND wz.dok_Status >= 0
                    AND (
                        wz.dok_NrPelnyOryg = d.dok_NrPelny
                        OR wz.dok_DoDokId = d.dok_Id
                        OR wz.dok_Id = d.dok_DoDokId
                    )
                )
                ORDER BY d.dok_Id ASC";

        $rows = MSSql::getInstance()->query($sql);
        if (!is_array($rows)) {
            return array();
        }

        $refs = array();
        foreach ($rows as $row) {
            $ref = trim((string) ($row['order_ref'] ?? ''));
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * Audyt powiązania ZK ↔ WZ dla wczytanego zamówienia (bez modyfikacji).
     *
     * @return array
     */
    public function getIssueCoverageAudit()
    {
        if (!$this->is_exists || !$this->orderGt) {
            return array(
                'order_ref' => $this->order_ref,
                'action' => 'not_found',
                'message' => 'Nie znaleziono ZK w Subiekcie',
            );
        }

        $this->reloadOrderFromGt();

        $allIssues = self::getIssueRefsForOrder($this->order_ref, (int) $this->gt_id);
        $validIssues = $this->getValidIssueRefsForOrder();
        $invalidIssues = array_values(array_diff($allIssues, $validIssues));
        $coverageComplete = $this->isIssueCoverageComplete();
        $fulfilled = $this->isZkFulfilledForApi();
        $state = (int) $this->state;
        $gtClosed = self::isOrderStatusFulfilled($state);

        if ($gtClosed && $this->isBusinessComplete()) {
            $action = 'already_closed';
            $message = 'ZK jest już zrealizowane';
        } elseif ($coverageComplete && !empty($validIssues)) {
            $action = 'ready_to_close';
            $message = 'WZ pokrywa towary ZK — można domknąć';
        } elseif ($fulfilled && !$gtClosed) {
            $action = 'ready_to_close';
            $message = 'WZ pokrywa ZK — można domknąć status w GT';
        } elseif (!empty($allIssues) && empty($validIssues)) {
            $action = 'wz_mismatch';
            $message = 'Istnieje WZ, ale nie pasuje do tego ZK (np. recykling numeru)';
        } elseif (!empty($validIssues) && !$coverageComplete) {
            $headerLinked = !empty(self::findIssueRefsLinkedFromOrderDocumentSql((int) $this->gt_id));
            if ($headerLinked && ((int) $this->status_ex & 4)) {
                $action = 'ready_to_close';
                $message = 'WZ powiązane na nagłówku ZK (StatusEx=całkowicie) — można domknąć przez COM';
            } else {
                $action = 'partial_coverage';
                $message = 'WZ częściowo pokrywa ZK';
            }
        } else {
            $action = 'no_wz';
            $message = 'Brak poprawnego WZ dla ZK';
        }

        return array(
            'order_ref' => $this->order_ref,
            'state' => $state,
            'status_label' => self::getOrderStatusLabel($state),
            'status_ex' => (int) $this->status_ex,
            'status_ex_label' => self::getOrderStatusExLabel((int) $this->status_ex),
            'action' => $action,
            'message' => $message,
            'issue_documents' => $allIssues,
            'issue_documents_valid' => $validIssues,
            'issue_documents_invalid' => $invalidIssues,
            'issue_coverage_complete' => $coverageComplete,
            'fulfilled' => $fulfilled,
            'remaining_qty' => self::sumRemainingToRealize($this->orderGt, false, (int) $this->gt_id, $this->order_ref),
            'remaining_qty_goods' => self::sumRemainingToRealize($this->orderGt, true, (int) $this->gt_id, $this->order_ref),
            'diagnosis' => $this->collectIssueCoverageDiagnosis(),
        );
    }

    /**
     * Domyka ZK na podstawie istniejącego WZ (bez tworzenia nowego).
     *
     * @return array
     * @throws Exception
     */
    public function reconcileFromExistingWz()
    {
        if (!$this->is_exists || !$this->orderGt) {
            throw new Exception('Zamówienie nie istnieje: ' . $this->order_ref);
        }

        $before = $this->getIssueCoverageAudit();
        if ($before['action'] === 'already_closed') {
            return array_merge($before, array(
                'fixed' => false,
                'fulfilled_after' => true,
            ));
        }
        if ($before['action'] !== 'ready_to_close') {
            return array_merge($before, array(
                'fixed' => false,
                'fulfilled_after' => false,
            ));
        }

        $this->reopenOrderForIssueIfNeeded();
        $issueRefs = $this->getValidIssueRefsForOrder();
        $closure = $this->reconcileOrderCloseFromExistingIssues($issueRefs);
        $after = $this->getIssueCoverageAudit();
        $afterState = isset($after['state']) ? (int) $after['state'] : null;
        $gtClosedAfter = self::isOrderStatusFulfilled($afterState);

        return array_merge($before, array(
            'fixed' => $gtClosedAfter,
            'fulfilled_after' => $gtClosedAfter,
            'state_after' => $afterState,
            'closure' => $closure,
        ));
    }

    /**
     * Masowy audyt i opcjonalne domknięcie ZK z poprawnym WZ.
     *
     * Parametry w orderDetail:
     * - order_numbers[] — numery sekwencji (2866) lub pełne ref (ZK 2866/06/2026)
     * - order_refs[] — pełne numery ZK
     * - month, year — miesiąc/rok dla order_numbers (domyślnie bieżący)
     * - auto_scan — true: skan otwartych ZK z WZ w miesiącu
     * - apply — true: domknij ZK z action=ready_to_close (domyślnie false = tylko audyt)
     *
     * @return array
     */
    public function batchReconcileIssueCoverage()
    {
        $data = is_array($this->orderDetail) ? $this->orderDetail : array();
        $apply = isset($data['apply']) && filter_var($data['apply'], FILTER_VALIDATE_BOOLEAN);
        $month = isset($data['month']) ? (int) $data['month'] : (int) date('n');
        $year = isset($data['year']) ? (int) $data['year'] : (int) date('Y');

        $orderRefs = array();
        if (!empty($data['order_refs']) && is_array($data['order_refs'])) {
            foreach ($data['order_refs'] as $ref) {
                $ref = trim((string) $ref);
                if ($ref !== '') {
                    $orderRefs[] = $ref;
                }
            }
        }
        if (!empty($data['order_numbers']) && is_array($data['order_numbers'])) {
            foreach ($data['order_numbers'] as $number) {
                $built = self::buildOrderRefFromSequence($number, $month, $year);
                if ($built !== '') {
                    $orderRefs[] = $built;
                }
            }
        }
        if (!empty($data['auto_scan']) && filter_var($data['auto_scan'], FILTER_VALIDATE_BOOLEAN)) {
            $orderRefs = array_merge($orderRefs, self::findOpenOrdersWithIssueInMonth($month, $year));
        }

        $orderRefs = array_values(array_unique($orderRefs));
        if (empty($orderRefs)) {
            return array(
                'state' => 'error',
                'message' => 'Brak listy ZK — podaj order_numbers, order_refs lub auto_scan=true',
            );
        }

        $summary = array(
            'total' => 0,
            'already_closed' => 0,
            'ready_to_close' => 0,
            'fixed' => 0,
            'wz_mismatch' => 0,
            'partial_coverage' => 0,
            'no_wz' => 0,
            'not_found' => 0,
            'errors' => 0,
        );
        $results = array();

        Logger::getInstance()->log(
            'api',
            'batchReconcileIssueCoverage: count=' . count($orderRefs)
                . ', apply=' . ($apply ? 'tak' : 'nie')
                . ', month=' . $month . ', year=' . $year,
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        foreach ($orderRefs as $orderRef) {
            $summary['total']++;
            try {
                $order = self::loadExistingByRefVariants($this->subiektGt, array('order_ref' => $orderRef));
                if ($order === null) {
                    $entry = array(
                        'order_ref' => $orderRef,
                        'action' => 'not_found',
                        'message' => 'Nie znaleziono ZK',
                    );
                    $summary['not_found']++;
                    $results[] = $entry;
                    continue;
                }

                $order->setCfg($this->cfg);
                if ($apply) {
                    $entry = $order->reconcileFromExistingWz();
                    if (!empty($entry['fixed'])) {
                        $summary['fixed']++;
                    }
                } else {
                    $entry = $order->getIssueCoverageAudit();
                }

                $action = isset($entry['action']) ? (string) $entry['action'] : 'unknown';
                if (isset($summary[$action])) {
                    $summary[$action]++;
                }

                $results[] = $entry;
            } catch (Exception $e) {
                $summary['errors']++;
                $results[] = array(
                    'order_ref' => $orderRef,
                    'action' => 'error',
                    'message' => $e->getMessage(),
                );
            }
        }

        return array(
            'state' => 'success',
            'dry_run' => !$apply,
            'apply' => $apply,
            'month' => $month,
            'year' => $year,
            'summary' => $summary,
            'results' => $results,
        );
    }

}

?>