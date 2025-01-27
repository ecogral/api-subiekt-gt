<?php

namespace APISubiektGT\SubiektGT;

use COM;
use APISubiektGT\Logger;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT\SubiektObj;
use Exception;


class Customer extends SubiektObj
{
    protected $customerGt = false;
    protected $email;
    protected $ref_id = false;
    protected $firstname;
    protected $lastname;
    protected $post_code;
    protected $city;
    protected $tax_id = '';
    protected $company_name = '';
    protected $address;
    protected $address_no = '';
    protected $phone = false;
    protected $is_company = false;


    public function __construct($subiektGt, $customerDetail = [])
    {
        parent::__construct($subiektGt, $customerDetail);
        Logger::getInstance()->log('debug', 'Tworzenie obiektu klienta: ' . json_encode($customerDetail), __CLASS__ . '->' . __FUNCTION__, __LINE__);
        $this->excludeAttr('customerGt');

        // czyszczenie nipu ze znaków
        $clean_tax_id = preg_replace('/([  \-])/', '', $this->tax_id);
        // usuń kod kraju jeśli istnieje (np. PL)
        $clean_tax_id_no_country = preg_replace('/^[A-Z]{2}/', '', $clean_tax_id);
        // wersja z możliwą spacją po kodzie kraju (np. "PL 1234567890")
        $tax_id_with_space = preg_replace('/^([A-Z]{2})/', '$1 ', $clean_tax_id);
        
        // Próby znalezienia klienta po różnych wariantach NIPu
        if ($this->is_company && $clean_tax_id_no_country != '') {
            if ($subiektGt->Kontrahenci->Istnieje($clean_tax_id_no_country)) {
                $this->customerGt = $subiektGt->Kontrahenci->Wczytaj($clean_tax_id_no_country);
                $this->getGtObject();
                $this->is_exists = true;
            } elseif ($subiektGt->Kontrahenci->Istnieje($clean_tax_id)) {
                $this->customerGt = $subiektGt->Kontrahenci->Wczytaj($clean_tax_id);
                $this->getGtObject();
                $this->is_exists = true;
            } elseif ($subiektGt->Kontrahenci->Istnieje($tax_id_with_space)) {
                $this->customerGt = $subiektGt->Kontrahenci->Wczytaj($tax_id_with_space);
                $this->getGtObject();
                $this->is_exists = true;
            }
        }

        // szukanie klienta po ref_id narazie rezygnujemy z tego
        if (!$this->customerGt && $this->ref_id && $subiektGt->Kontrahenci->Istnieje($this->ref_id)) {
            $this->customerGt = $subiektGt->Kontrahenci->Wczytaj($this->ref_id);
            $this->getGtObject();
            $this->is_exists = true;
        }

        // Jesli nie ma to tworzy
        if (!$this->customerGt) {
            $this->customerGt = $subiektGt->Kontrahenci->Dodaj();
            $this->setGtObject();
            
            
            if (!$this->customerGt->Zapisz()) {
                throw new Exception('Nie udało się zapisać klienta w Subiekcie GT');
            }
            
            
            $saved_nip = $this->customerGt->NIP;
            if (empty($saved_nip)) {
                throw new Exception('Nie udało się zapisać NIPu klienta');
            }
            
            $this->customerGt = $subiektGt->Kontrahenci->Wczytaj($saved_nip);
            if (!$this->customerGt) {
                throw new Exception('Nie udało się wczytać zapisanego klienta');
            }
            
            $this->getGtObject();
            $this->is_exists = true;

            Logger::getInstance()->log('debug', 'Dodano nowego klienta do Subiekta: ' . json_encode([
                'NIP' => $saved_nip,
                'Symbol' => $this->customerGt->Symbol
            ]), __CLASS__ . '->' . __FUNCTION__, __LINE__);
        }
    }

    protected function setGtObject()
    {
        $this->customerGt->Symbol = substr($this->ref_id, 0, 20);
        if ($this->is_company && strlen($this->tax_id) >= 10) {
            if (strlen($this->company_name) == 0) {
                throw new Exception('Nie można utworzyć klienta brak jego nazwy!');
            }
            $this->customerGt->NazwaPelna = $this->company_name;
            $this->customerGt->Nazwa = mb_substr($this->company_name, 0, 40);
            $this->customerGt->Osoba = 0;
            $this->customerGt->NIP = $this->tax_id;
            
            $this->customerGt->Symbol = $this->customerGt->NIP;

        } else {
            $this->customerGt->Osoba = 1;
            $this->customerGt->OsobaImie = substr($this->firstname, 0, 20);
            $this->customerGt->OsobaNazwisko = substr($this->lastname, 0, 50);
            $this->customerGt->NazwaPelna = $this->firstname . ' ' . $this->lastname;
            $this->customerGt->NIP = $this->tax_id;
            $this->customerGt->Symbol = $this->customerGt->NIP;
        }
        $this->customerGt->Email = $this->email;
        $this->customerGt->Miejscowosc = $this->city;
        $this->customerGt->KodPocztowy = substr($this->post_code, 0, 6);
        $this->customerGt->Ulica = substr($this->address, 0, 60);
        $this->customerGt->NrDomu = substr($this->address_no, 0, 10);
        

        if ($this->phone) {
            if ($this->customerGt->Telefony->Liczba == 0) {
                $phoneGt = $this->customerGt->Telefony->Dodaj($this->phone);
            } else {
                $phoneGt = $this->customerGt->Telefony->Element(1);

            }
            $phoneGt->Nazwa = 'Primary';
            $phoneGt->Numer = $this->phone;
            $phoneGt->Typ = 3;
        }
        return true;
    }

    protected function getGtObject()
    {
        $this->is_company = !$this->customerGt->Osoba;
        $this->gt_id = $this->customerGt->Identyfikator;
        $this->ref_id = $this->customerGt->Symbol;
        $this->company_name = $this->customerGt->NazwaPelna;
        $this->tax_id = $this->customerGt->NIP;
        $this->firstname = $this->customerGt->OsobaImie;
        $this->lastname = $this->customerGt->OsobaNazwisko;
        $this->email = $this->customerGt->Email;
        $this->city = $this->customerGt->Miejscowosc;
        $this->post_code = $this->customerGt->KodPocztowy;
        $this->address = $this->customerGt->Ulica;
        $this->address_no = $this->customerGt->NrDomu;

        if ($this->customerGt->Telefony->Liczba > 0) {
            $phoneGt = $this->customerGt->Telefony->Element(1);
            $this->phone = $phoneGt->Numer;
        }
        return true;
    }

    static public function getCustomerById($id)
    {
        $sql = "SELECT * FROM vwKlienci WHERE kh_Id = {$id}";
        $data = MSSql::getInstance()->query($sql);
        if (!isset($data[0])) {
            return false;
        }
        $data = $data[0];
        Logger::getInstance()->log('debug', 'Pobrano dane klienta z Subiektu: ' . json_encode($data), __CLASS__ . '->' . __FUNCTION__, __LINE__);
        $ret_data = array(
            'ref_id' => $data['kh_Symbol'],
            'company_name' => $data['Firma'],
            'tax_id' => $data['adr_NIP'],
            'fullname' => $data['adr_NazwaPelna'],
            'email' => $data['kh_EMail'],
            'city' => $data['adr_Miejscowosc'],
            'post_code' => $data['adr_Kod'],
            'address' => $data['adr_Adres'],
            'phone' => $data['adr_Telefon'],
            'is_company' => $data['kh_Typ'] == 2 ? false : true,
        );
        return $ret_data;
    }

    public static function getAllCustomers($subiektGtCom, $limit = 1000, $offset = 0)
    {
        $customers = array();
        $customersGt = $subiektGtCom->Kontrahenci;

        // Ustawienie filtru, aby pobrać wszystkich kontrahentów
//        $customersGt->Filtry->Zeruj();

        $count = $customersGt->Liczba();
        $end = min($offset + $limit, $count);

        for ($i = $offset + 1; $i <= $end; $i++) {
            $customerGt = $customersGt->Element($i);

            $customer = array(
                'gt_id' => $customerGt->Identyfikator,
                'ref_id' => $customerGt->Symbol,
                'is_company' => !$customerGt->Osoba,
                'company_name' => $customerGt->NazwaPelna,
                'tax_id' => $customerGt->NIP,
                'firstname' => $customerGt->OsobaImie,
                'lastname' => $customerGt->OsobaNazwisko,
                'email' => $customerGt->Email,
                'city' => $customerGt->Miejscowosc,
                'post_code' => $customerGt->KodPocztowy,
                'address' => $customerGt->Ulica,
                'address_no' => $customerGt->NrDomu,
                'caretaker' => $customerGt->CrmOsobaKontaktowa
            );

            if ($customerGt->Telefony->Liczba > 0) {
                $phoneGt = $customerGt->Telefony->Element(1);
                $customer['phone'] = $phoneGt->Numer;
            }

            $customers[] = $customer;
        }

        return array(
            'customers' => $customers,
            'total_count' => $count,
            'limit' => $limit,
            'offset' => $offset
        );
    }

    public function add()
    {
        $this->customerGt = $this->subiektGt->Kontrahenci->Dodaj();
        $this->setGtObject();
        $this->customerGt->Zapisz();
        Logger::getInstance()->log('api', 'Utworzono klienta od klienta: ' . $this->customerGt->Symbol, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        $this->gt_id = $this->customerGt->Identyfikator;
        return array('gt_id' => $this->customerGt->Identyfikator);
    }

    public function update()
    {
        if (!$this->customerGt) {
            return false;
        }
        $this->setGtObject();
        $this->customerGt->Zapisz();
        Logger::getInstance()->log('api', 'Zaktualizowano klienta od klienta: ' . $this->customerGt->Symbol, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        return true;
    }

    public function getGt()
    {
        return $this->customerGt;
    }

    public function get()
    {
        if (!$this->customerGt) {
            return false;
        }
        
        return array(
            'gt_id' => $this->gt_id,
            'ref_id' => $this->ref_id,
            'company_name' => $this->company_name,
            'tax_id' => $this->tax_id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'city' => $this->city,
            'post_code' => $this->post_code,
            'address' => $this->address,
            'address_no' => $this->address_no,
            'phone' => $this->phone,
            'is_company' => $this->is_company
        );
    }

}

?>