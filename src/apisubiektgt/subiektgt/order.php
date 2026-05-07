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

class Order extends SubiektObj
{
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
        Logger::getInstance()->log('api', 'Utworzono dokument sprzedaży: ' . $selling_doc->NumerPelny, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        $response = array(
            'doc_ref' => $selling_doc->NumerPelny,
            'doc_amount' => $this->getOrderAmountById($selling_doc->Identyfikator),
            'doc_state' => 'ok',
            'doc_state_code' => 0,
            'order_ref' => $this->order_ref,

        );

        if (isset($this->pdf_request)) {
            $response['doc_pdf'] = $this->getPdfInBase64($selling_doc);
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
        $this->reservation = $o['statusrez'] ?? 0;
        $this->state = $o['dok_Status'] ?? 0;
        $this->amount = $o['dok_WartBrutto'] ?? 0;
        $this->projected_value = $o['dok_WartMag'] ?? 0;
        $this->projected_profit = $o['dok_PrognozowanyZysk'] ?? (($o['dok_WartTwNetto'] ?? 0) - ($o['dok_WartMag'] ?? 0));
        $this->date_of_delivery = $o['dok_TerminRealizacji'] ?? null;
        $this->order_processing = $o['ss_PrzetworzonoZKwZD'] ?? $o['dok_PrzetworzonoZKwZD'] ?? 0;
        $this->id_flag = $o['flg_Id'] ?? null;
        $this->flag_txt = $o['flg_Text'] ?? '';

        $customer = Customer::getCustomerById($this->orderGt->KontrahentId);
        $this->customer = $customer;

        $positions = array();
        for ($i = 1; $i <= $this->orderGt->Pozycje->Liczba(); $i++) {
            $positions[$this->orderGt->Pozycje->Element($i)->Id]['name'] = $this->orderGt->Pozycje->Element($i)->TowarNazwa;
            $positions[$this->orderGt->Pozycje->Element($i)->Id]['code'] = $this->orderGt->Pozycje->Element($i)->TowarSymbol;
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
            $this->products[] = $p_a;
        }

    }

    protected function getOrderById($id)
    {
        $sql = "SELECT d.dok_Id, d.dok_NrPelnyOryg, d.dok_Uwagi, d.dok_NrPelny, d.dok_Status,
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
        $row['statusrez'] = 0;
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
            'order_processing' => $this->order_processing,
            'id_flag' => $this->id_flag,
            'flag_txt' => $this->flag_txt,
            'amount' => $this->amount,
            'projected_value' => $this->projected_value,
            'projected_profit' => $this->projected_profit,
            'sell_doc' => $this->selling_doc
        );
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

        $this->reservation = $reservationRequested;
        $this->orderGt->Rezerwacja = $this->reservation;
        $this->orderGt->Przelicz();
        $this->orderGt->Zapisz();

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

        $commentsPreview = isset($this->orderDetail['comments']) ? substr((string)$this->orderDetail['comments'], 0, 50) : '';
        Logger::getInstance()->log('api', 'update: setGtObject (reference=' . ($this->reference ?? '') . ', comments=' . $commentsPreview . ', shipment_number=' . (isset($this->orderDetail['shipment_number']) ? 'tak' : 'nie') . ')', __CLASS__ . '->' . __FUNCTION__, __LINE__);
        $this->setGtObject();

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
            'positions_count' => $positions_count
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

}

?>