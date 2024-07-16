<?php
namespace APISubiektGT\SubiektGT;

use COM;
use APISubiektGT\MSSql;
use APISubiektGT\Logger;
use APISubiektGT\SubiektGT\SubiektObj;
use APISubiektGT\SubiektGT;

class Product extends SubiektObj
{

    protected $productGt = false;
    protected $ean = 0;
    protected $code = '';
    protected $price;
    protected $wholesale_price = 0;
    protected $price_a = 0;
    protected $price_s = 0;
    protected $price_pallet = 0;
    protected $price_4_pallets = 0;
    protected $name;
    protected $name_for_devices;
    protected $description = '';
    protected $qty;
    protected $supplier_code = '';
    protected $supplier_id = '';
    protected $vat = 0;
    //protected $unit = '';
    protected $weight = 0;
    protected $capacity = 0;
    protected $time_of_delivery = 0;
    protected $attribute = '';
    protected $id_store = 1;
    protected $intrastat_country_id = 0;
    protected $intrastat_code = NULL;
    protected $products_qtys = array();
    protected $products_qtys_by_supplier = 0;
    protected $group_id = '';
    protected $off_prefix = 0;

    public function __construct($subiektGt, $productDetail = array())
    {
        parent::__construct($subiektGt, $productDetail);
        $this->excludeAttr(array('productGt', 'off_prefix', 'is_exists', 'objDetail'));

        if ($this->code != '' && $subiektGt->Towary->Istnieje($this->code)) {
            $this->productGt = $subiektGt->Towary->Wczytaj($this->code);
            $this->is_exists = true;
            $this->getGtObject();
        }
    }

    protected function setGtObject()
    {
        if (!$this->is_exists) {
            $new_prefix = SubiektGT::getInstance()->getConfig()->getNewProductPrefix();

            if (strlen($new_prefix) > 0 && $this->off_prefix == 0) {
                $this->productGt->Nazwa = substr("{$new_prefix} {$this->name}", 0, 50);
            } else {
                $this->productGt->Nazwa = substr("{$this->name}", 0, 50);

            }
            //domyslny atrybut dla nowego produktu
            $new_attribute = SubiektGT::getInstance()->getConfig()->getDefaultAttribute();
            if (!empty($new_attribute)) {
                $this->productGt->Cechy->Dodaj($new_attribute);
            }
        } else {
            $this->productGt->Nazwa = substr("{$this->name}", 0, 50);
        }

        //Opis
        if (!empty($this->description)) {
            $this->productGt->Opis = $this->description;
        } else {
            $this->productGt->Opis = $this->name;
        }

        //nazwa dla urządzeń
        if (!empty($this->name_for_devices)) {
            $this->productGt->NazwaDlaUF = substr("{$this->name_for_devices}", 0, 50);
        }
        $this->productGt->Symbol = substr(sprintf('%s', $this->code), 0, 20);
        $this->productGt->Aktywny = true;
        $this->productGt->CzasDostawy = $this->time_of_delivery;
        if (!empty($this->supplier_id)) {
            $this->productGt->DostawcaId = $this->supplier_id;
        }

        //cena detaliczna
        if ($this->productGt->Ceny->Liczba > 0) {
            $this->productGt->Ceny->Element(1)->Netto = floatval($this->price);
        }

        //cena hurtowa
        if ($this->productGt->Ceny->Liczba > 1 && $this->wholesale_price > 0) {
            $this->productGt->Ceny->Element(2)->Netto = floatval($this->wholesale_price);
        }

        //cena s
        if ($this->productGt->Ceny->Liczba > 3 && $this->price_s > 0) {
            $this->productGt->Ceny->Element(4)->Netto = floatval($this->price_s);
        }

        //cena A
        if ($this->productGt->Ceny->Liczba > 4 && $this->price_a > 0) {
            $this->productGt->Ceny->Element(5)->Netto = floatval($this->price_a);
        }

        //cena paleta
        if ($this->productGt->Ceny->Liczba > 5 && $this->price_pallet > 0) {
            $this->productGt->Ceny->Element(6)->Netto = floatval($this->price_pallet);
        }

        //cena 4 palety
        if ($this->productGt->Ceny->Liczba > 6 && $this->price_4_pallets > 0) {
            $this->productGt->Ceny->Element(7)->Netto = floatval($this->price_4_pallets);
        }

        //stawka vat
        if (!empty($this->vat)) {
            $this->productGt->SprzedazVatId = $this->vat;
        }

        //masa
        if (!empty($this->weight)) {
            $this->productGt->Masa = $this->weight;
        }

        //objetosc
        if (!empty($this->capacity)) {
            $this->productGt->Objetosc = $this->capacity;
        }

        //atrybut
        if (!empty($this->attribute)) {
            $this->productGt->Cechy->Dodaj($this->attribute);
        }

        if (!empty($this->supplier_code)) {
            $this->productGt->SymbolUDostawcy = substr(sprintf('%s', $this->supplier_code), 0, 20);
        }

        //intrastat
        if (!empty($this->intrastat_code)) {
            $this->productGt->IntrastatKodWgCN = substr(sprintf('%s', $this->intrastat_code), 0, 8);
        }

        if (!empty($this->intrastat_country_id)) {
            $this->productGt->IntrastatKrajPochodzeniaId = intval($this->intrastat_country_id);
        }

        //podstawowy kod kreskowy
        $ean = substr(sprintf('%s', trim($this->ean)), 0, 20);
        if (!empty($ean)) {
            $this->productGt->KodyKreskowe->Podstawowy = $ean;
        }

        return true;
    }

    protected function getGtObject()
    {
        if (!$this->productGt) {
            return false;
        }
        $this->gt_id = $this->productGt->Identyfikator;
        $this->name = $this->productGt->Nazwa;
        $this->description = $this->productGt->Opis;
        $this->code = $this->productGt->Symbol;
        $this->time_of_delivery = $this->productGt->CzasDostawy;
        $this->supplier_id = $this->productGt->DostawcaId;
        $this->weight = $this->productGt->Masa;
        $this->capacity = $this->productGt->Objetosc;
        $this->vat = $this->productGt->SprzedazVatId;
        $this->supplier_code = $this->productGt->SymbolUDostawcy;
        $this->intrastat_code = $this->productGt->IntrastatKodWgCN;
        $this->intrastat_country_id = $this->productGt->IntrastatKrajPochodzeniaId;
        $this->ean = $this->productGt->KodyKreskowe->Podstawowy;

        if ($this->productGt->Ceny->Liczba > 0) {
            $prices = $this->productGt->Ceny->Element(1);
            $this->price = floatval($prices->Netto);
        }

        if ($this->productGt->Ceny->Liczba > 1) {
            $prices = $this->productGt->Ceny->Element(2);
            $this->wholesale_price = floatval($prices->Netto);
        }

        if ($this->productGt->Ceny->Liczba > 3) {
            $prices = $this->productGt->Ceny->Element(4);
            $this->price_s = floatval($prices->Netto);
        }

        if ($this->productGt->Ceny->Liczba > 4) {
            $prices = $this->productGt->Ceny->Element(5);
            $this->price_a = floatval($prices->Netto);
        }

        if ($this->productGt->Ceny->Liczba > 5) {
            $prices = $this->productGt->Ceny->Element(6);
            $this->price_pallet = floatval($prices->Netto);
        }

        if ($this->productGt->Ceny->Liczba > 6) {
            $prices = $this->productGt->Ceny->Element(7);
            $this->price_4_pallets = floatval($prices->Netto);
        }

        $qty = $this->getQty();
        $this->qty = intval($qty['Dostepne']);
        return true;
    }

    public function getPriceCalculations()
    {
        if (!$this->productGt) {
            return false;
        }

        $this->setGtObject();
        $this->productGt = $this->subiektGt->Towary->Wczytaj($this->code);

        Logger::getInstance()->log('api', 'Pobrano kalkulacje cen: ' . $this->productGt->Symbol, __CLASS__ . '->' . __FUNCTION__, __LINE__);

        $priceList = $this->productGt->Zakupy;

        for ($i = 1, $size = $priceList->Liczba; $i < $size + 1; ++$i) {
            $data[$i]['nazwa'] = $priceList->Element($i)->Nazwa;
            $data[$i]['wartosc'] = (string)$priceList->Element($i)->Wartosc;
        }

        return $data;
    }

    public function getListByStore()
    {
        $sql = "SELECT tw_Symbol as code ,Rezerwacja as reservation,Dostepne as available, Stan as on_store,  st_MagId as id_store FROM vwTowar WHERE st_MagId = " . intval($this->id_store);
        $data = MSSql::getInstance()->query($sql);
        return $data;
    }

    public function getListAviByStore()
    {
        $sql = "SELECT tw_Symbol as code ,Rezerwacja as reservation,Dostepne as available, Stan as on_store,  st_MagId as id_store FROM vwTowar WHERE st_MagId = " . intval($this->id_store) . ' AND Dostepne > 0';
        $data = MSSql::getInstance()->query($sql);
        return $data;
    }

    public function getQtysByCode()
    {
        $qtys = array();
        foreach ($this->products_qtys as $pq) {
            $code = $pq['code'];
            $id_store = isset($pq['id_store']) ? intval($pq['id_store']) : 0;
            $sql = 'SELECT tw_Id as id ,tw_Symbol as code, Rezerwacja as reservation , Dostepne as available, Stan as on_store, Stan-Rezerwacja as on_store_available   FROM vwTowar LEFT JOIN 
			tw_KodKreskowy ON kk_IdTowar = tw_Id 
			WHERE st_MagId = ' . $id_store . ' AND tw_Symbol = \'' . $code . '\'';

            $data = MSSql::getInstance()->query($sql);
            if (!isset($data[0])) {
                $qtys[$code] = 'not found';
                continue;
            }
            $qtys[$code]['id'] = $data[0]['id'];
            $qtys[$code]['code'] = $data[0]['code'];
            $qtys[$code]['reservation'] = intval($data[0]['reservation']);
            $qtys[$code]['available'] = intval($data[0]['available']);
            $qtys[$code]['on_store'] = intval($data[0]['on_store']);
            $qtys[$code]['on_store_available'] = intval($data[0]['on_store_available']);
        }
        return $qtys;
    }


    public function getQtysBySupplier()
    {
        $sql = "SELECT tw_Id as id ,tw_Symbol as code, Rezerwacja as reservation , Dostepne as available, Stan as on_store, tc_CenaNetto1 as price1, tc_CenaNetto2 as price2, tc_CenaNetto3 as price3, tc_CenaNetto4 as price4, tc_CenaNetto5 as price5, tw_Nazwa as name  FROM vwTowar LEFT JOIN 
			tw_KodKreskowy ON kk_IdTowar = tw_Id 
			WHERE tw_IdPodstDostawca = {$this->products_qtys_by_supplier} and Dostepne > 0 AND st_MagId = {$this->id_store}";

        $data = MSSql::getInstance()->query($sql);


        return $data;
    }


    protected function getQty()
    {
        $sql = "SELECT TOP 1 Rezerwacja,Dostepne,Stan  FROM vwTowar WHERE tw_Id = {$this->gt_id} AND st_MagId = " . intval($this->id_store);
        $data = MSSql::getInstance()->query($sql);
        return $data[0];
    }

    public function add()
    {
        $this->productGt = $this->subiektGt->TowaryManager->DodajTowar();
        $this->setGtObject();
        $this->productGt->Zapisz();
        Logger::getInstance()->log('api', 'Utworzono produkt: ' . $this->productGt->Symbol, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        return array('gt_id' => $this->productGt->Identyfikator);
    }

    public function setProductSupplierCode($supplier_code)
    {
        if (!$this->productGt) {
            return false;
        }
        $this->productGt->SymbolUDostawcy = substr(sprintf('%s', $supplier_code), 0, 20);
        $this->productGt->Zapisz();
        Logger::getInstance()->log('api', 'Zaktualizowano kod dostawcy produktu: ' . $supplier_code, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        return true;
    }

    public function update()
    {
        if (!$this->productGt) {
            return false;
        }
        $this->readData($this->objDetail);
        $this->setGtObject();
        $this->productGt->Zapisz();
        Logger::getInstance()->log('api', 'Zaktualizowano produkt: ' . $this->productGt->Symbol, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        return $this;
    }

    public function getGt()
    {
        return $this->productGt;
    }

    public function printPricesObject()
    {
        if (!$this->productGt) {
            return false;
        }

        Logger::getInstance()->log('api', 'Pobrano ceny produkt: ' . $this->productGt->Symbol, __CLASS__ . '->' . __FUNCTION__, __LINE__);

        $prices = array();
        for ($i = 1; $i <= $this->productGt->Ceny->Liczba; $i++) {
            $price = $this->productGt->Ceny->Element($i);
            $prices[] = array(
                'name' => $price->Nazwa,
                'netto' => floatval($price->Netto),
                'brutto' => floatval($price->Brutto)
            );
        }

        return $prices;
    }

    public function getStocks()
    {
        $sql = "SELECT tw_Symbol as code, Rezerwacja as reservation, Dostepne as available, Stan as on_store, st_MagId as id_store 
            FROM vwTowar 
            WHERE st_MagId = " . intval($this->id_store);

        $data = MSSql::getInstance()->query($sql);

        $stocks = array();
        foreach ($data as $row) {
            $stocks[] = array(
                'code' => $row['code'],
                'available' => intval($row['available']),
                'reservation' => intval($row['reservation']),
                'on_store' => intval($row['on_store']),
                'id_store' => intval($row['id_store'])
            );
        }

        Logger::getInstance()->log('api', 'Pobrano stany magazynowe', __CLASS__ . '->' . __FUNCTION__, __LINE__);

        return $stocks;
    }
}

?>
