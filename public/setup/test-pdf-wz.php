<?php
/**
 * Test generowania PDF WZ — uruchom na serwerze API.
 * php public/setup/test-pdf-wz.php "WZ 2836/06/2026" [wzw_Id]
 */
require_once dirname(__FILE__) . '/../init.php';

use APISubiektGT\Config;
use APISubiektGT\SubiektGT;
use APISubiektGT\SubiektGT\Document;

$docRef = isset($argv[1]) ? $argv[1] : 'WZ 2836/06/2026';
$templateOverride = isset($argv[2]) ? (int) $argv[2] : 0;

$cfg = new Config(CONFIG_INI_FILE);
$cfg->load();

$subiektGt = SubiektGT::getInstance($cfg);
$com = $subiektGt->connect();

if (!$com->SuDokumentyManager->Istnieje($docRef)) {
    fwrite(STDERR, "Dokument nie istnieje: {$docRef}\n");
    exit(1);
}

$docGt = $com->SuDokumentyManager->Wczytaj($docRef);
$tempDir = dirname(__FILE__) . '/../../tmp/pdf';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

$templates = $templateOverride > 0 ? array($templateOverride) : array(447, 1000004, 1000007, 629, 0);
echo "Test PDF dla: {$docRef}\n";
echo "Temp: {$tempDir}\n\n";

foreach ($templates as $tplId) {
    $file = str_replace('/', '\\', $tempDir . '\\test_' . ($tplId ?: 'default') . '_' . time() . '.pdf');
    $t0 = microtime(true);
    try {
        if ($tplId > 0) {
            $docGt->DrukujDoPlikuWgWzorca($tplId, $file, 0);
            $method = "DrukujDoPlikuWgWzorca({$tplId})";
        } else {
            $docGt->DrukujDoPliku($file, 0);
            $method = 'DrukujDoPliku()';
        }
        $ok = false;
        for ($i = 0; $i < 20; $i++) {
            if (is_file($file) && filesize($file) > 0) {
                $ok = true;
                break;
            }
            usleep(300000);
        }
        $ms = round((microtime(true) - $t0) * 1000);
        if ($ok) {
            echo "OK  {$method} -> " . filesize($file) . " bytes ({$ms}ms) [{$file}]\n";
        } else {
            echo "FAIL {$method} — brak pliku po {$ms}ms [{$file}]\n";
        }
    } catch (Exception $e) {
        echo "ERR  wzw_Id={$tplId}: " . $e->getMessage() . "\n";
    }
    if (is_file($file)) {
        @unlink($file);
    }
}

// Test przez klasę Document
echo "\n--- Document::getPdf() ---\n";
$doc = new Document($com, array('doc_ref' => $docRef));
$doc->setCfg($cfg);
try {
    $result = $doc->getPdf();
    if ($result && !empty($result['pdf_file'])) {
        echo 'OK getPdf: variant=' . ($result['pdf_variant'] ?? '?') . ', bytes=' . strlen(base64_decode($result['pdf_file'])) . "\n";
    } else {
        echo "FAIL getPdf: brak danych\n";
    }
} catch (Exception $e) {
    echo 'ERR getPdf: ' . $e->getMessage() . "\n";
}
