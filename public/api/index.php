<?php

use APISubiektGT\Config;
use APISubiektGT\Helper;
use APISubiektGT\Logger;
use APISubiektGT\SubiektGT;

require_once(dirname(__FILE__) . '/../init.php');
$json_response = array();
$obj = false;
Logger::getInstance()->log('api', 'Request start: ' . $_SERVER['REMOTE_ADDR'], '', __LINE__);

header("Content-Type: application/json;charset=utf-8");

$header = Helper::getallheaders();
try {
    if (
        false && (!isset($header['Content-Type']) ||
            !('application/json' == $header['Content-Type'] || 'application/json;charset=utf-8' == $header['Content-Type']))
    ) {
        throw new Exception("Header Content-Type:application/json missing!");
    }


    //Get Json stream from "input".
    $jsonStr = @file_get_contents("php://input");
    $jsonStr = trim($jsonStr);
    if ($jsonStr != NULL) {
        $json_request = json_decode($jsonStr, true);
        if (json_last_error() > 0) {
            throw new Exception("JSON read: " . json_last_error_msg());
        }
    } else {
        throw new Exception("Brak danych w żądaniu!");
    }


    //include('json_test.php');

    $run = explode('/', $_GET['c']);
    if (count($run) != 2) {
        throw new Exception("Nie prawidłowe wywołanie API");
    }

    $class = "APISubiektGT\\SubiektGT\\{$run[0]}";
    $method = $run[1];
    

    if (!class_exists($class)) {
        throw new Exception("Nieprawidłowe wywołanie API nie istnieje obiekt: {$run[0]}");
    }


    //Check is set api_key
    if (!isset($json_request['api_key'])) {
        throw new Exception('Nie podano klucza API=>api_key');
    }
    //Config load
    $cfg = new Config(CONFIG_INI_FILE);
    $cfg->load();


    if (!$cfg->verifyAPIKey($json_request['api_key'])) {
        throw new Exception("Nieprawidłowy klucz API - api_key!");
    }

    //Create instance of Subiekt process and connect to it
    $subiektGt = SubiektGT::getInstance($cfg);

    //Connect or create SubiektGt Windows process
    $subiektGtCom = $subiektGt->connect();

    //Processing API request.
    $result = false;

    // Dodaj ten fragment w miejscu, gdzie przetwarzasz żądania API
    if ($run[0] == 'Customer' && $method == 'getAllCustomers') {
        $limit = isset($json_request['data']['limit']) ? intval($json_request['data']['limit']) : 1000;
        $offset = isset($json_request['data']['offset']) ? intval($json_request['data']['offset']) : 0;

        $result = $class::getAllCustomers($subiektGtCom, $limit, $offset);

        $json_response['state'] = 'success';
        $json_response['data'] = $result;
    } elseif ($run[0] == 'Customer' && $method == 'getCustomerZKDocuments') {
        if (!isset($json_request['data']['tax_id'])) {
            throw new Exception('Brak wymaganego parametru tax_id');
        }
        
        $result = $class::getCustomerZKDocuments($subiektGtCom, $json_request['data']['tax_id']);

        $json_response['state'] = 'success';
        $json_response['data'] = $result;
    } elseif ($run[0] == 'Document' && $method == 'getDocumentsByTaxId') {
        if (!isset($json_request['data']['tax_id'])) {
            throw new Exception('Brak wymaganego parametru tax_id');
        }
        
        $obj = new $class($subiektGtCom, $json_request['data']);
        $obj->setCfg($cfg);
        $result = $obj->getDocumentsByTaxId($json_request['data']['tax_id']);

        $json_response['state'] = 'success';
        $json_response['data'] = $result;
    } elseif ($run[0] == 'Document' && $method == 'getInvoicesByDateRange') {
        if (!isset($json_request['data']['date_from']) || !isset($json_request['data']['date_to'])) {
            throw new Exception('Brak wymaganych parametrów: date_from i date_to');
        }
        
        $limit = isset($json_request['data']['limit']) ? intval($json_request['data']['limit']) : 1000;
        
        $obj = new $class($subiektGtCom, $json_request['data']);
        $obj->setCfg($cfg);
        $result = $obj->getInvoicesByDateRange($json_request['data']['date_from'], $json_request['data']['date_to'], $limit);

        $json_response['state'] = 'success';
        $json_response['data'] = $result;
    } elseif ($run[0] == 'Product' && $method == 'getStocks') {
        $obj = new $class($subiektGtCom, $json_request['data']);
        $obj->setCfg($cfg);
        $result = $obj->getStocks();

        $json_response['state'] = 'success';
        $json_response['data'] = $result;
    } elseif ($run[0] == 'Order' && $method == 'getRecentOrders') {
        $limit = isset($json_request['data']['limit']) ? intval($json_request['data']['limit']) : 300;
        $orderBy = isset($json_request['data']['orderBy']) ? $json_request['data']['orderBy'] : 'date_created';
        $orderDirection = isset($json_request['data']['orderDirection']) ? $json_request['data']['orderDirection'] : 'desc';

        $obj = new $class($subiektGtCom, $json_request['data']);
        $obj->setCfg($cfg);
        $result = $obj->getRecentOrders($limit, $orderBy, $orderDirection);

        $json_response['state'] = 'success';
        $json_response['data'] = $result;
    } else {
        // Istniejąca logika dla innych metod
        $obj = new $class($subiektGtCom, $json_request['data']);
        $obj->setCfg($cfg);
        
        if (!method_exists($obj, $method)) {
            throw new Exception("Nieprawidłowe wywołanie API. Brak metody: {$method} w klasie " . get_class($obj));
        }
        
        $reflection = new ReflectionMethod($obj, $method);
        if (!$reflection->isPublic()) {
            throw new Exception("Wywołanie metody: {$method} jest zabronione!");
        }

        $result = $obj->$method();

        $json_response['state'] = 'success';
        $json_response['data'] = $result;
    }

    //Zakomentowane aby nie zamykac bieżącego (uruchomionego) uchwytu do obiektu COM.
    //$subiektGtCom->Zakoncz();

    Logger::getInstance()->log('api', 'Request finish: ' . $_SERVER['REMOTE_ADDR'], $class . '->' . $method, __LINE__);
} catch (Exception $e) {
    $json_response['state'] = 'fail';
    $json_response['message'] = strip_tags($e->getMessage());
    $json_response['obj_dump'] = print_r($obj, true);
    if (isset($json_request['data'])) {
        $json_response['data'] = $json_request['data'];
    }
    Logger::getInstance()->log('api_error', Helper::toWin($e->getMessage()), $e->getFile(), $e->getLine());
}

$json_string = json_encode($json_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (JSON_ERROR_UTF8 == json_last_error()) {
    $json_string = json_encode(Helper::toUtf8($json_response), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
echo $json_string;
