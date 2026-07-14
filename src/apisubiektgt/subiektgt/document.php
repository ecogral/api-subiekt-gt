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
    protected $status_ex = 0;
    protected $fully_realized = false;
    protected $reservation = false;
    protected $issue_documents = array();

    /** Od tej daty (dok_DataWyst FS) zwracane są wyłącznie faktury z nadanym numerem KSeF w tabeli ksef_NumerKSeF (ksefnr_NumerKSeF), powiązanie dok_NumerKSeFId — wg dokumentacji struktury bazy Subiekt GT. */
    const KSEF_FS_FILTER_FROM = '2026-04-01';

    protected function sqlLeftJoinKsefNumer($docAlias = 'd', $alias = 'ksef_nr')
    {
        return "LEFT JOIN ksef_NumerKSeF AS {$alias} ON {$alias}.ksefnr_Id = {$docAlias}.dok_NumerKSeFId";
    }

    /**
     * Warunek WHERE dla samych faktur sprzedaży (dok_Typ = 2): przed cutoff bez zmian, od cutoff — wymagany niepusty numer w ksef_NumerKSeF.
     */
    protected function sqlWhereFsKsefRequiredFromCutoff($docAlias = 'd', $ksefAlias = 'ksef_nr')
    {
        $from = self::KSEF_FS_FILTER_FROM;
        return "(
        {$docAlias}.dok_DataWyst < CAST('{$from}' AS datetime)
        OR ({$ksefAlias}.ksefnr_NumerKSeF IS NOT NULL AND LTRIM(RTRIM({$ksefAlias}.ksefnr_NumerKSeF)) <> '')
    )";
    }

    /**
     * To samo przy zapytaniach z wieloma typami dokumentów — dotyczy tylko wierszy FS (dok_Typ = 2).
     */
    protected function sqlWhereFsKsefWhenMixedDocTypes($docAlias = 'd', $ksefAlias = 'ksef_nr')
    {
        $from = self::KSEF_FS_FILTER_FROM;
        return "(
        {$docAlias}.dok_Typ <> 2
        OR {$docAlias}.dok_DataWyst < CAST('{$from}' AS datetime)
        OR ({$ksefAlias}.ksefnr_NumerKSeF IS NOT NULL AND LTRIM(RTRIM({$ksefAlias}.ksefnr_NumerKSeF)) <> '')
    )";
    }

    protected function ksefFieldsFromRow(array $row)
    {
        if (!array_key_exists('ksef_numer', $row)) {
            return [];
        }
        $n = $row['ksef_numer'];
        $num = ($n !== null && trim((string) $n) !== '') ? trim((string) $n) : null;
        return [
            'ksef_number' => $num,
            'ksef_number_assigned_at' => $this->formatDateForResponse(isset($row['ksef_data_nadania']) ? $row['ksef_data_nadania'] : null),
        ];
    }

    /**
     * Czy dokument handlowy ma nadany numer KSeF w ksef_NumerKSeF (powiązanie dok_NumerKSeFId).
     */
    protected function dokHanHasAssignedKsef($dokId)
    {
        $dokId = (int) $dokId;
        if ($dokId <= 0) {
            return false;
        }
        $sql = "SELECT 1 AS ok FROM dok__Dokument d
            INNER JOIN ksef_NumerKSeF k ON k.ksefnr_Id = d.dok_NumerKSeFId
            WHERE d.dok_Id = {$dokId}
            AND k.ksefnr_NumerKSeF IS NOT NULL AND LTRIM(RTRIM(k.ksefnr_NumerKSeF)) <> ''";
        try {
            $data = MSSql::getInstance()->query($sql);
            return !empty($data);
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'KSeF PDF: nie można sprawdzić numeru KSeF: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return false;
        }
    }

    /**
     * Opcjonalne klucze w pliku INI (parse_ini → Config): ksef_pdf_template_fs, ksef_pdf_template_kfs, ksef_pdf_template_id (wspólny).
     * Wartość = wzw_Id z tabeli wy_Wzorzec (wzorce wydruku KSeF).
     */
    protected function getKsefPdfTemplateIdFromCfg()
    {
        if (!$this->cfg || !is_object($this->cfg)) {
            return null;
        }
        $typ = (int) $this->doc_type_id;
        $keys = array();
        if ($typ === 2) {
            $keys[] = 'ksef_pdf_template_fs';
        }
        if ($typ === 6) {
            $keys[] = 'ksef_pdf_template_kfs';
        }
        $keys[] = 'ksef_pdf_template_id';
        foreach ($keys as $key) {
            if (!isset($this->cfg->{$key})) {
                continue;
            }
            $v = $this->cfg->{$key};
            if ($v === '' || $v === null) {
                continue;
            }
            if (is_numeric($v) && (int) $v > 0) {
                return (int) $v;
            }
        }
        return null;
    }

    /**
     * Opcjonalne klucze INI dla jawnego wzorca PDF (wzw_Id): pdf_template_wz, pdf_template_zk, pdf_template_fs, pdf_template_kfs, pdf_template_id.
     */
    protected function getPdfTemplateIdFromCfg($docTypeId = null)
    {
        if (!$this->cfg || !is_object($this->cfg)) {
            return null;
        }
        $typ = $docTypeId !== null ? (int) $docTypeId : (int) $this->doc_type_id;
        $typeKeys = array(
            2 => 'pdf_template_fs',
            6 => 'pdf_template_kfs',
            11 => 'pdf_template_wz',
            16 => 'pdf_template_zk',
        );
        $keys = array();
        if (isset($typeKeys[$typ])) {
            $keys[] = $typeKeys[$typ];
        }
        $keys[] = 'pdf_template_id';
        foreach ($keys as $key) {
            if (!isset($this->cfg->{$key})) {
                continue;
            }
            $v = $this->cfg->{$key};
            if ($v === '' || $v === null) {
                continue;
            }
            if (is_numeric($v) && (int) $v > 0) {
                return (int) $v;
            }
        }
        return null;
    }

    /**
     * Katalog na pliki PDF — pdf_temp_dir w INI lub katalog projektu tmp/pdf (współdzielony z procesem Subiekta/COM).
     */
    protected function resolvePdfTempDir()
    {
        $candidates = array();
        if ($this->cfg && is_object($this->cfg) && isset($this->cfg->pdf_temp_dir)) {
            $cfgDir = trim((string) $this->cfg->pdf_temp_dir);
            if ($cfgDir !== '') {
                $candidates[] = $cfgDir;
            }
        }
        $candidates[] = 'C:\\Windows\\Temp\\api-subiekt-gt-pdf';
        if (defined('LOG_DIR')) {
            $candidates[] = rtrim(LOG_DIR, "\\/") . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'pdf';
        }
        $candidates[] = sys_get_temp_dir();

        foreach ($candidates as $dir) {
            if (!is_string($dir) || $dir === '') {
                continue;
            }
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $resolved = realpath($dir) ?: $dir;
            if (is_dir($resolved) && is_writable($resolved)) {
                return $resolved;
            }
        }

        throw new Exception('Brak zapisywalnego katalogu tymczasowego dla PDF (pdf_temp_dir / Windows\\Temp / tmp/pdf).');
    }

    /**
     * Sfera/COM na Windows wymaga pełnej ścieżki z backslashami.
     */
    protected function normalizePathForCom($path)
    {
        return str_replace('/', '\\', (string) $path);
    }

    protected function waitForPdfFile($file_name, $maxAttempts = 15, $sleepMicros = 300000)
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            if (is_file($file_name) && @filesize($file_name) > 0) {
                return true;
            }
            usleep($sleepMicros);
        }
        return is_file($file_name) && @filesize($file_name) > 0;
    }

    /**
     * COM czasem zapisuje plik pod inną ścieżką (np. katalog roboczy procesu) — przenieś do oczekiwanej lokalizacji.
     */
    protected function locateGeneratedPdf($expectedPath)
    {
        if (is_file($expectedPath) && @filesize($expectedPath) > 0) {
            return $expectedPath;
        }
        $basename = basename($expectedPath);
        $candidates = array(
            $expectedPath,
            getcwd() . DIRECTORY_SEPARATOR . $basename,
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . $basename,
        );
        $safeId = (int) $this->gt_id;
        if ($safeId > 0) {
            $candidates[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $safeId . '.pdf';
            $candidates[] = getcwd() . DIRECTORY_SEPARATOR . $safeId . '.pdf';
        }
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '' || $candidate === $expectedPath) {
                continue;
            }
            if (is_file($candidate) && @filesize($candidate) > 0) {
                if (@rename($candidate, $expectedPath) || @copy($candidate, $expectedPath)) {
                    @unlink($candidate);
                    return $expectedPath;
                }
                return $candidate;
            }
        }
        return false;
    }

    protected function isValidPdfTemplateId($templateId)
    {
        $templateId = (int) $templateId;
        if ($templateId <= 0) {
            return false;
        }
        $sql = "SELECT TOP 1 w.wzw_Id AS id, w.wzw_TypPliku AS typ_pliku, w.wzw_Nazwa AS nazwa
            FROM wy_Wzorzec w
            WHERE w.wzw_Id = {$templateId} AND w.wzw_Widoczny = 1";
        try {
            $data = MSSql::getInstance()->query($sql);
            return !empty($data) && isset($data[0]['id']) && (int) $data[0]['id'] > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Wzorzec WZ przypisany do kontrahenta (kh_WzwIdWZ / kh_WzwIdWZVAT).
     */
    protected function findCustomerWzTemplateIds()
    {
        if ((int) $this->doc_type_id !== 11 || !$this->documentGt || is_null($this->documentGt->KontrahentId)) {
            return array();
        }
        $khId = (int) $this->documentGt->KontrahentId;
        if ($khId <= 0) {
            return array();
        }
        $sql = "SELECT kh_WzwIdWZ AS wz_id, kh_WzwIdWZVAT AS wz_vat_id
            FROM kh__Kontrahent WHERE kh_Id = {$khId}";
        try {
            $data = MSSql::getInstance()->query($sql);
            if (empty($data)) {
                return array();
            }
            $ids = array();
            foreach (array('wz_id', 'wz_vat_id') as $col) {
                if (isset($data[0][$col]) && (int) $data[0][$col] > 0) {
                    $ids[] = (int) $data[0][$col];
                }
            }
            return $ids;
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'PDF: wzorzec WZ kontrahenta: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return array();
        }
    }

    /**
     * Domyślne wzorce z wy_WzDomyslny — preferuj RPT (wzw_TypPliku=0), bo tylko one eksportują do PDF przez COM.
     */
    protected function findDefaultWydrukWzorzecIds($docTypeId = null, $limit = 5)
    {
        $typ = $docTypeId !== null ? (int) $docTypeId : (int) $this->doc_type_id;
        if ($typ <= 0) {
            return array();
        }
        $host = str_replace("'", "''", (string) gethostname());
        $limit = max(1, (int) $limit);
        $nameFilter = '';
        if ($typ === 11) {
            $nameFilter = "AND (w.wzw_Nazwa LIKE N'%WZ%' OR w.wzw_Nazwa LIKE N'%Wydanie%' OR w.wzw_Nazwa LIKE N'%zewn%')";
        } elseif ($typ === 2) {
            $nameFilter = "AND (w.wzw_Nazwa LIKE N'%FS%' OR w.wzw_Nazwa LIKE N'%Faktur%')";
        }
        $sql = "SELECT TOP {$limit} wzd.wzd_WzorzecId AS id, w.wzw_Nazwa AS template_name,
                w.wzw_TypPliku AS typ_pliku, wzd.wzd_NazwaKomputera AS host_name
            FROM wy_WzDomyslny wzd
            INNER JOIN wy_Wzorzec w ON w.wzw_Id = wzd.wzd_WzorzecId AND w.wzw_Widoczny = 1
            WHERE wzd.wzd_Typ = {$typ}
            {$nameFilter}
            ORDER BY
                CASE WHEN w.wzw_TypPliku = 0 THEN 0 ELSE 1 END,
                CASE
                    WHEN LTRIM(RTRIM(ISNULL(wzd.wzd_NazwaKomputera, ''))) = N'{$host}' THEN 0
                    WHEN LTRIM(RTRIM(ISNULL(wzd.wzd_NazwaKomputera, ''))) = '' THEN 1
                    ELSE 2
                END,
                wzd.wzd_Id DESC";
        try {
            $data = MSSql::getInstance()->query($sql);
            $ids = array();
            if (!empty($data)) {
                foreach ($data as $row) {
                    if (isset($row['id']) && (int) $row['id'] > 0) {
                        $ids[] = (int) $row['id'];
                    }
                }
            }
            return $ids;
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'PDF: domyślne wzorce z DB: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return array();
        }
    }

    protected function findDefaultWydrukWzorzecId($docTypeId = null)
    {
        $ids = $this->findDefaultWydrukWzorzecIds($docTypeId, 1);
        return !empty($ids) ? $ids[0] : null;
    }

    protected function reloadDocumentGtForPrint()
    {
        if ($this->doc_ref === '' || !$this->subiektGt) {
            return false;
        }
        try {
            if (!$this->subiektGt->SuDokumentyManager->Istnieje($this->doc_ref)) {
                return false;
            }
            $this->documentGt = $this->subiektGt->SuDokumentyManager->Wczytaj($this->doc_ref);
            return (bool) $this->documentGt;
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'PDF: przeładowanie dokumentu COM: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return false;
        }
    }

    /**
     * Wzorce RPT w tej samej grupie wy_Typ co domyślny wzorzec dla typu dokumentu (np. WZ → wzw_Typ=121).
     */
    protected function findCompatibleRptWzorzecIds($docTypeId = null, $limit = 3)
    {
        $typ = $docTypeId !== null ? (int) $docTypeId : (int) $this->doc_type_id;
        if ($typ <= 0) {
            return array();
        }
        $limit = max(1, (int) $limit);
        $extraName = '';
        if ($typ === 11) {
            $extraName = "AND w.wzw_Nazwa NOT LIKE N'%WZv%' AND (w.wzw_Nazwa LIKE N'%WZ%' OR w.wzw_Nazwa LIKE N'%Wydanie%')";
        }
        $sql = "SELECT TOP {$limit} w.wzw_Id AS id
            FROM wy_Wzorzec w
            WHERE w.wzw_Widoczny = 1
            AND w.wzw_TypPliku = 0
            AND w.wzw_Typ = (
                SELECT TOP 1 w0.wzw_Typ FROM wy_WzDomyslny z
                INNER JOIN wy_Wzorzec w0 ON w0.wzw_Id = z.wzd_WzorzecId
                WHERE z.wzd_Typ = {$typ}
            )
            {$extraName}
            ORDER BY
                CASE WHEN w.wzw_Id = (SELECT TOP 1 z.wzd_WzorzecId FROM wy_WzDomyslny z WHERE z.wzd_Typ = {$typ} ORDER BY z.wzd_Id DESC) THEN 0 ELSE 1 END,
                w.wzw_Id DESC";
        try {
            $data = MSSql::getInstance()->query($sql);
            $ids = array();
            if (!empty($data)) {
                foreach ($data as $row) {
                    if (isset($row['id']) && (int) $row['id'] > 0) {
                        $ids[] = (int) $row['id'];
                    }
                }
            }
            return $ids;
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'PDF: kompatybilne wzorce RPT: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return array();
        }
    }

    protected function findCompatibleWzRptWzorzecIds()
    {
        return $this->findCompatibleRptWzorzecIds(11, 3);
    }

    /**
     * Zapasowe wzorce RPT — ta sama grupa wy_Typ co domyślny wzorzec dokumentu.
     */
    protected function findFallbackRptWzorzecIds($docTypeId = null, $limit = 5)
    {
        return $this->findCompatibleRptWzorzecIds($docTypeId, $limit);
    }

    protected function collectPdfTemplateCandidates()
    {
        $candidates = array();
        $add = function ($id) use (&$candidates) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $candidates, true)) {
                $candidates[] = $id;
            }
        };

        $add($this->getPdfTemplateIdFromCfg());
        foreach ($this->findCustomerWzTemplateIds() as $id) {
            $add($id);
        }
        foreach ($this->findFallbackRptWzorzecIds() as $id) {
            $add($id);
        }
        foreach ($this->findDefaultWydrukWzorzecIds() as $id) {
            $add($id);
        }
        return $candidates;
    }

    protected function tryPrintDrukujDoPliku($file_name, $wzorzecId = null)
    {
        if (is_file($file_name)) {
            @unlink($file_name);
        }
        $wzorzecId = $wzorzecId !== null ? (int) $wzorzecId : 0;
        if ($wzorzecId > 0) {
            return $this->tryPrintWithTemplate($wzorzecId, $file_name);
        }
        try {
            $this->documentGt->DrukujDoPliku($file_name, 0);
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'PDF: DrukujDoPliku(path, 0) wyjątek: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return false;
        }
        if ($this->waitForPdfFile($file_name, 12, 500000)) {
            return true;
        }
        return $this->locateGeneratedPdf($file_name) !== false;
    }

    protected function tryPrintStandardDrukujDoPliku($file_name)
    {
        return $this->tryPrintDrukujDoPliku($file_name, null);
    }

    /**
     * Dedykowana ścieżka WZ: najpierw domyślny DrukujDoPliku (2 arg.), potem jeden wzorzec z INI przez DrukujDoPlikuWgWzorca.
     */
    protected function printWzDocumentToPdfFile($file_name)
    {
        $tried = array();
        $this->reloadDocumentGtForPrint();
        usleep(300000);
        $tried[] = 'default';
        if ($this->tryPrintDrukujDoPliku($file_name, null)) {
            Logger::getInstance()->log('api', 'PDF WZ OK: ' . $this->doc_ref . ' metoda=DrukujDoPliku', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            return 'standard';
        }

        $cfgTpl = $this->getPdfTemplateIdFromCfg(11);
        if ($cfgTpl !== null && (int) $cfgTpl > 0) {
            $this->reloadDocumentGtForPrint();
            usleep(300000);
            $tried[] = (string) (int) $cfgTpl;
            if ($this->tryPrintDrukujDoPliku($file_name, (int) $cfgTpl)) {
                Logger::getInstance()->log('api', 'PDF WZ OK: ' . $this->doc_ref . ' metoda=DrukujDoPlikuWgWzorca(' . (int) $cfgTpl . ')', __CLASS__ . '->' . __FUNCTION__, __LINE__);
                return 'template';
            }
        }

        Logger::getInstance()->log(
            'api',
            'PDF WZ FAIL: ' . $this->doc_ref . ' — próbowano: ' . implode(',', $tried),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );
        return 'standard';
    }

    protected function acquirePdfGenerationLock($timeoutSeconds = 120)
    {
        $lockDir = defined('LOG_DIR') ? LOG_DIR : sys_get_temp_dir();
        $lockFile = rtrim($lockDir, "\\/") . DIRECTORY_SEPARATOR . 'pdf_generation.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return null;
        }
        $deadline = time() + (int) $timeoutSeconds;
        while (!@flock($fp, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($fp);
                throw new Exception('Timeout oczekiwania na dostęp do Sfery (inny proces generuje PDF). Spróbuj ponownie za chwilę.');
            }
            usleep(300000);
        }
        return $fp;
    }

    protected function releasePdfGenerationLock($fp)
    {
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    protected function tryPrintWithTemplate($wzorzecId, $file_name)
    {
        $wzorzecId = (int) $wzorzecId;
        if ($wzorzecId <= 0 || !$this->isValidPdfTemplateId($wzorzecId)) {
            return false;
        }
        if (is_file($file_name)) {
            @unlink($file_name);
        }
        try {
            $this->documentGt->DrukujDoPlikuWgWzorca($wzorzecId, $file_name, 0);
        } catch (Exception $e) {
            Logger::getInstance()->log(
                'api',
                'PDF: DrukujDoPlikuWgWzorca(wzw_Id=' . $wzorzecId . ') wyjątek: ' . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            return false;
        }
        if ($this->waitForPdfFile($file_name, 10, 250000)) {
            return true;
        }
        $located = $this->locateGeneratedPdf($file_name);
        return $located !== false;
    }

    /**
     * Próba znalezienia wzorca KSeF w wy_Wzorzec (nazwa zawiera „KSeF”) w tej samej grupie typu (wy_Typ) co domyślny wzorzec dla FS/KFS.
     */
    protected function findAutoKsefWydrukWzorzecId()
    {
        $typ = (int) $this->doc_type_id;
        if ($typ !== 2 && $typ !== 6) {
            return null;
        }
        $sql = "SELECT TOP 1 w.wzw_Id AS id
            FROM wy_Wzorzec w
            WHERE w.wzw_Widoczny = 1
            AND (w.wzw_Nazwa LIKE N'%KSeF%' OR w.wzw_Nazwa LIKE N'%KSEF%')
            AND EXISTS (
                SELECT 1 FROM wy_WzDomyslny z
                INNER JOIN wy_Wzorzec w0 ON w0.wzw_Id = z.wzd_WzorzecId
                WHERE z.wzd_Typ = {$typ} AND w0.wzw_Typ = w.wzw_Typ
            )
            ORDER BY w.wzw_Id DESC";
        try {
            $data = MSSql::getInstance()->query($sql);
            if (!empty($data) && isset($data[0]['id']) && (int) $data[0]['id'] > 0) {
                return (int) $data[0]['id'];
            }
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'KSeF PDF: auto wzorzec: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
        }
        return null;
    }

    /**
     * Generuje PDF przez Sferę.
     * Kolejność: wzorzec KSeF (FS/KFS z numerem) → lista wzorców RPT (DrukujDoPlikuWgWzorca) → DrukujDoPliku.
     * gtaTypPlikuPDF = 0 (pomoc Sfery: TypPlikuEnum).
     */
    protected function printDocumentToPdfFile($file_name)
    {
        $triedTemplates = array();
        $useKsef = $this->dokHanHasAssignedKsef($this->gt_id);

        // WZ: osobna ścieżka (mniej wywołań COM niż pętla wzorców).
        if ((int) $this->doc_type_id === 11 && !$useKsef) {
            return $this->printWzDocumentToPdfFile($file_name);
        }

        if ($useKsef) {
            $wzorzecId = $this->getKsefPdfTemplateIdFromCfg();
            if ($wzorzecId === null) {
                $wzorzecId = $this->findAutoKsefWydrukWzorzecId();
            }
            if ($wzorzecId !== null) {
                $triedTemplates[] = (int) $wzorzecId;
                if ($this->tryPrintWithTemplate($wzorzecId, $file_name)) {
                    return 'ksef';
                }
                Logger::getInstance()->log('api', 'KSeF PDF: wzorzec wzw_Id=' . $wzorzecId . ' nie utworzył pliku — próba standardowych wzorców.', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            } else {
                Logger::getInstance()->log('api', 'KSeF PDF: brak wzorca KSeF w INI/DB — próba standardowych wzorców.', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            }
        }

        foreach ($this->collectPdfTemplateCandidates() as $wzorzecId) {
            $triedTemplates[] = (int) $wzorzecId;
            if ($this->tryPrintWithTemplate($wzorzecId, $file_name)) {
                Logger::getInstance()->log('api', 'PDF: utworzono plikiem wzw_Id=' . $wzorzecId . ' dla ' . $this->doc_ref, __CLASS__ . '->' . __FUNCTION__, __LINE__);
                return $useKsef ? 'standard_fallback' : 'template';
            }
        }

        if (empty($triedTemplates)) {
            Logger::getInstance()->log(
                'api',
                'PDF: brak wzorca w INI/DB dla typu ' . (int) $this->doc_type_id . ' (' . $this->doc_type . ', doc_ref=' . $this->doc_ref . ') — używam DrukujDoPliku.',
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        } else {
            Logger::getInstance()->log(
                'api',
                'PDF: żaden wzorzec nie utworzył pliku (próbowano: ' . implode(',', $triedTemplates) . ') — fallback DrukujDoPliku dla ' . $this->doc_ref,
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        }

        if (is_file($file_name)) {
            @unlink($file_name);
        }
        if (!$this->tryPrintStandardDrukujDoPliku($file_name)) {
            Logger::getInstance()->log('api', 'PDF: DrukujDoPliku (fallback) nie utworzył pliku dla ' . $this->doc_ref, __CLASS__ . '->' . __FUNCTION__, __LINE__);
        }
        return $useKsef ? 'standard_fallback' : 'standard';
    }

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
        if (!$this->documentGt) {
            return false;
        }
        $this->documentGt->Uwagi = $this->comments;
        return true;
    }

    public function getPdf()
    {
        if (!$this->is_exists) {
            return false;
        }

        @set_time_limit(90);
        @ini_set('max_execution_time', '90');

        $temp_dir = $this->resolvePdfTempDir();
        if (!is_string($temp_dir) || $temp_dir === '' || !is_dir($temp_dir)) {
            throw new Exception('Nie można ustalić katalogu tymczasowego na serwerze (pdf_temp_dir / tmp/pdf).');
        }
        if (!is_writable($temp_dir)) {
            throw new Exception('Brak uprawnień zapisu do katalogu tymczasowego: ' . $temp_dir);
        }

        $safeId = (int) $this->gt_id;
        $uniq = bin2hex(random_bytes(8));
        $file_name = $this->normalizePathForCom(
            rtrim($temp_dir, "\\/") . DIRECTORY_SEPARATOR . $safeId . '_' . $uniq . '.pdf'
        );

        $pdf_variant = 'standard';
        $lockFp = null;
        try {
            $lockFp = $this->acquirePdfGenerationLock();
            $this->reloadDocumentGtForPrint();
            $pdf_variant = $this->printDocumentToPdfFile($file_name);

            if (!$this->waitForPdfFile($file_name)) {
                $located = $this->locateGeneratedPdf($file_name);
                if ($located !== false && $located !== $file_name) {
                    $file_name = $this->normalizePathForCom($located);
                }
            }

            if (!is_file($file_name)) {
                $hint = ((int) $this->doc_type_id === 11)
                    ? ' Sprawdź w Subiekcie domyślny wzorzec wydruku WZ (wy_WzDomyslny) — musi być typu RPT, nie tekstowy — lub ustaw pdf_template_wz w INI API.'
                    : ' Sprawdź domyślny wzorzec wydruku RPT w Subiekcie lub pdf_template_* w INI API.';
                Logger::getInstance()->log(
                    'api',
                    'PDF nie został utworzony przez Sferę: doc_ref=' . $this->doc_ref . ', doc_type=' . $this->doc_type . ', gt_id=' . $safeId . ', tmp=' . $file_name . ', tmp_dir=' . $temp_dir . ', wariant=' . $pdf_variant,
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
                throw new Exception('Subiekt nie wygenerował pliku PDF dla dokumentu: ' . $this->doc_ref . '.' . $hint);
            }

            $size = @filesize($file_name);
            if ($size === false || $size <= 0) {
                Logger::getInstance()->log(
                    'api',
                    'PDF utworzony, ale pusty: doc_ref=' . $this->doc_ref . ', gt_id=' . $safeId . ', tmp=' . $file_name . ', size=' . (is_int($size) ? $size : 'n/a') . ', wariant=' . $pdf_variant,
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
                throw new Exception('Subiekt wygenerował pusty plik PDF dla dokumentu: ' . $this->doc_ref);
            }

            $pdf_file = @file_get_contents($file_name);
            if ($pdf_file === false || $pdf_file === '') {
                throw new Exception('Nie można odczytać wygenerowanego PDF z dysku (plik: ' . $file_name . ').');
            }

            Logger::getInstance()->log('api', 'Wygenerowano pdf dokumentu: ' . $this->doc_ref . ' (wariant=' . $pdf_variant . ', bytes=' . strlen($pdf_file) . ')', __CLASS__ . '->' . __FUNCTION__, __LINE__);

            return array(
                'encoding' => 'base64',
                'doc_ref' => $this->doc_ref,
                'is_exists' => $this->is_exists,
                'file_name' => mb_ereg_replace("[ /]", "_", $this->doc_ref . '.pdf'),
                'state' => $this->state,
                'accounting_state' => $this->accounting_state,
                'doc_type' => $this->doc_type,
                'pdf_variant' => $pdf_variant,
                'pdf_file' => base64_encode($pdf_file),
            );
        } catch (Exception $e) {
            Logger::getInstance()->log(
                'api',
                'Błąd generowania PDF: doc_ref=' . $this->doc_ref . ', gt_id=' . $safeId . ', wariant=' . $pdf_variant . ', msg=' . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            throw $e;
        } finally {
            $this->releasePdfGenerationLock($lockFp);
            if (isset($file_name) && is_string($file_name) && is_file($file_name)) {
                @unlink($file_name);
            }
        }
    }


    public function getState()
    {
        return array('doc_ref' => $this->doc_ref,
            'is_exists' => $this->is_exists,
            'doc_type' => $this->doc_type,
            'state' => $this->state,
            'status_ex' => $this->status_ex,
            'reservation' => (bool) $this->reservation,
            'fully_realized' => $this->fully_realized,
            'is_realized' => $this->fully_realized,
            'is_fulfilled' => $this->fully_realized,
            'accounting_state' => $this->accounting_state,
            'order_processing' => $this->order_processing,
            'id_flag' => $this->id_flag,
            'flag_name' => $this->flag_name,
            'flag_comment' => $this->flag_comment,
            'amount' => $this->amount,
            'issue_documents' => $this->issue_documents,
            'wz_refs' => $this->issue_documents,
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
        $statusEx = (int) ($o['dok_StatusEx'] ?? 0);
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

        $this->products = array();
        $positions = array();
        $comPositionsById = array();
        for ($i = 1; $i <= $this->documentGt->Pozycje->Liczba(); $i++) {
            $comPos = $this->documentGt->Pozycje->Element($i);
            $positions[$comPos->Id]['name'] = $comPos->TowarNazwa;
            $positions[$comPos->Id]['code'] = $comPos->TowarSymbol;
            $comPositionsById[(int) $comPos->Id] = $comPos;
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
            if ((int) $this->doc_type_id === 16) {
                $comPos = isset($comPositionsById[(int) $p['ob_Id']]) ? $comPositionsById[(int) $p['ob_Id']] : null;
                $p_a = Order::appendRealizationFieldsToProduct($p_a, $comPos, (float) $p['ob_Ilosc']);
            }
            $this->products[] = $p_a;
        }

        if ((int) $this->doc_type_id === 16) {
            $this->status_ex = $statusEx;
            $this->reservation = (bool) ($this->documentGt->Rezerwacja ?? false);
            $this->fully_realized = Order::isOrderComFullyRealized(
                $this->documentGt,
                (int) $this->state,
                $statusEx
            );
            if ($this->fully_realized) {
                $this->reservation = false;
            }
            $this->issue_documents = Order::getIssueRefsForOrder($this->doc_ref);
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
     * Formatuje datę do postaci string dla odpowiedzi JSON (DateTime z MSSQL nie serializuje się poprawnie).
     *
     * @param mixed $value Data (DateTime, string lub null)
     * @return string|null Data w formacie Y-m-d lub null
     */
    protected function formatDateForResponse($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s.u', $value);
            if ($dt) {
                return $dt->format('Y-m-d');
            }
            $dt = \DateTime::createFromFormat('Y-m-d', $value);
            if ($dt) {
                return $dt->format('Y-m-d');
            }
            return $value;
        }
        return null;
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
            // Szukamy zamówienia tego samego klienta z datą przed lub równą dacie faktury
            $invoice_sql = "SELECT dok_PlatnikId, dok_DataWyst 
                           FROM dok__Dokument 
                           WHERE dok_Id = {$invoice_id}";
            $invoice_data = MSSql::getInstance()->query($invoice_sql);
            
            if (empty($invoice_data)) {
                return null;
            }
            
            $customer_id = $invoice_data[0]['dok_PlatnikId'];
            $invoice_date_raw = $invoice_data[0]['dok_DataWyst'];
            $invoice_date = $this->formatDateForResponse($invoice_date_raw);
            if ($invoice_date === null) {
                $invoice_date = is_object($invoice_date_raw) && method_exists($invoice_date_raw, 'format')
                    ? $invoice_date_raw->format('Y-m-d') : (string) $invoice_date_raw;
            }
            
            $sql = "SELECT TOP 1
                        d.dok_Id,
                        d.dok_NrPelny,
                        d.dok_DataWyst,
                        d.dok_WartBrutto,
                        d.dok_Status
                    FROM dok__Dokument d
                    WHERE d.dok_Typ = 16  -- ZK (Zamówienie Klienta)
                    AND d.dok_PlatnikId = {$customer_id}
                    AND d.dok_DataWyst <= '{$invoice_date}'
                    AND d.dok_Status >= 0
                    ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";
            
            $data = MSSql::getInstance()->query($sql);
            
            if (!empty($data) && isset($data[0])) {
                return [
                    'order_id' => $data[0]['dok_Id'],
                    'order_ref' => $data[0]['dok_NrPelny'],
                    'order_date' => $this->formatDateForResponse($data[0]['dok_DataWyst']),
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
            // Szukamy WZ tego samego klienta z datą przed lub równą dacie faktury
            $invoice_sql = "SELECT dok_PlatnikId, dok_DataWyst 
                           FROM dok__Dokument 
                           WHERE dok_Id = {$invoice_id}";
            $invoice_data = MSSql::getInstance()->query($invoice_sql);
            
            if (empty($invoice_data)) {
                return null;
            }
            
            $customer_id = $invoice_data[0]['dok_PlatnikId'];
            $invoice_date_raw = $invoice_data[0]['dok_DataWyst'];
            $invoice_date = $this->formatDateForResponse($invoice_date_raw);
            if ($invoice_date === null) {
                $invoice_date = is_object($invoice_date_raw) && method_exists($invoice_date_raw, 'format')
                    ? $invoice_date_raw->format('Y-m-d') : (string) $invoice_date_raw;
            }
            
            $sql = "SELECT TOP 1
                        d.dok_Id,
                        d.dok_NrPelny,
                        d.dok_DataWyst,
                        d.dok_WartBrutto,
                        d.dok_Status
                    FROM dok__Dokument d
                    WHERE d.dok_Typ = 11  -- WZ (Wydanie Zewnętrzne)
                    AND d.dok_PlatnikId = {$customer_id}
                    AND d.dok_DataWyst <= '{$invoice_date}'
                    AND d.dok_Status >= 0
                    ORDER BY d.dok_DataWyst DESC, d.dok_Id DESC";
            
            $data = MSSql::getInstance()->query($sql);
            
            if (!empty($data) && isset($data[0])) {
                return [
                    'wz_id' => $data[0]['dok_Id'],
                    'wz_ref' => $data[0]['dok_NrPelny'],
                    'wz_date' => $this->formatDateForResponse($data[0]['dok_DataWyst']),
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

    /**
     * Aktualizuje nagłówek istniejącego dokumentu (np. WZ): pole uwagi w GT oraz Zapisz().
     * Obsługa comments + shipment_number jak w Order::update() (nr przesyłki w uwagach).
     *
     * @return array
     * @throws Exception
     */
    public function update()
    {
        Logger::getInstance()->log(
            'api',
            'Document/update: start, doc_ref=' . ($this->doc_ref ?? '(null)') . ', is_exists=' . ($this->is_exists ? '1' : '0'),
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        if (!$this->doc_ref) {
            Logger::getInstance()->log('api', 'Document/update: odrzucono – brak doc_ref', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            throw new Exception('Brak parametru doc_ref – nie można zidentyfikować dokumentu do edycji.');
        }
        if (!$this->is_exists || !$this->documentGt) {
            Logger::getInstance()->log(
                'api',
                'Document/update: odrzucono – dokument nie wczytany (is_exists=' . ($this->is_exists ? '1' : '0') . ')',
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            throw new Exception('Dokument nie istnieje lub nie udało się go wczytać: ' . $this->doc_ref);
        }

        // Jak w Order::update dla UTF-8; jeśli klient nie podaje `comments`, zachowujemy istniejące uwagi z GT (np. sama synchronizacja shipment_number).
        $hadCommentsKey = array_key_exists('comments', $this->documentDetail);
        $comments = $hadCommentsKey
            ? (string) $this->documentDetail['comments']
            : Helper::toUtf8((string) $this->comments);
        $lines = preg_split('/\r\n|\r|\n/', $comments);
        $lines = array_filter($lines, function ($line) {
            return stripos(trim($line), 'Nr przesyłki:') !== 0;
        });
        $comments = trim(implode("\n", $lines));
        if (!empty($this->documentDetail['shipment_number'])) {
            $comments .= "\nNr przesyłki: " . trim((string) $this->documentDetail['shipment_number']);
        }
        $comments = preg_replace('/(?<!\n)(Adres dostawy:)/u', "\n$1", $comments);
        $comments = preg_replace('/(?<!\n)(Nr przesyłki:)/u', "\n$1", $comments);
        $comments = str_replace(["\r\n", "\r"], "\n", $comments);
        $comments = str_replace("\n", "\r\n", $comments);
        $this->comments = Helper::toWin($comments);

        $commentsPreview = $hadCommentsKey ? substr((string) $this->documentDetail['comments'], 0, 50) : '(z dokumentu)';
        Logger::getInstance()->log(
            'api',
            'Document/update: setGtObject (comments_preview=' . $commentsPreview . ', shipment_number=' . (isset($this->documentDetail['shipment_number']) ? 'tak' : 'nie') . ')',
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );
        $this->setGtObject();

        try {
            Logger::getInstance()->log('api', 'Document/update: wywołuję Zapisz() dla ' . $this->doc_ref, __CLASS__ . '->' . __FUNCTION__, __LINE__);
            $this->documentGt->Zapisz();
            Logger::getInstance()->log('api', 'Document/update: Zapisz() zakończone OK', __CLASS__ . '->' . __FUNCTION__, __LINE__);
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Document/update: Zapisz() BŁĄD: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
            throw $e;
        }

        $servicesResult = null;
        $appendServices = Order::isAppendServicesRequested($this->documentDetail)
            || Order::isCopyServicesFromOrderRequested($this->documentDetail, true)
            || !empty($this->documentDetail['services']);
        if ($appendServices && (int) $this->doc_type_id === 11) {
            $detailForServices = $this->documentDetail;
            $orderRefForServices = isset($detailForServices['order_ref'])
                ? trim((string) $detailForServices['order_ref'])
                : '';
            if ($orderRefForServices === '') {
                $orderRefForServices = trim((string) $this->reference);
            }
            if ($orderRefForServices === '') {
                $orderRefForServices = Order::resolveOrderRefForIssueSql($this->doc_ref);
            }
            if ($orderRefForServices !== '') {
                $detailForServices['order_ref'] = $orderRefForServices;
            }

            $orderForServices = null;
            if ($orderRefForServices !== '') {
                $orderForServices = Order::loadExistingByRefVariants($this->subiektGt, array(
                    'order_ref' => $orderRefForServices,
                ));
                if ($orderForServices !== null) {
                    $orderForServices->setCfg($this->cfg);
                }
            }
            if ($orderForServices === null) {
                $orderForServices = new Order($this->subiektGt, array());
                $orderForServices->setCfg($this->cfg);
            }
            $servicesResult = $orderForServices->appendMissingServicesToIssue(
                $this->doc_ref,
                $detailForServices
            );
        }

        $fulfillmentRepair = null;
        if ((int) $this->doc_type_id === 11) {
            $fulfillmentRepair = Order::ensureOrderFulfilledAfterIssueSql(
                $this->doc_ref,
                $this->cfg ? (int) $this->cfg->getWarehouse() : 1
            );
        }

        $this->getGtObject();
        Logger::getInstance()->log(
            'api',
            'Document/update: sukces, doc_ref=' . $this->doc_ref . ', doc_type=' . $this->doc_type,
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        return [
            'doc_ref' => $this->doc_ref,
            'doc_type' => $this->doc_type,
            'doc_type_id' => $this->doc_type_id,
            'services_appended' => $servicesResult !== null ? (int) ($servicesResult['added'] ?? 0) : 0,
            'service_codes' => $servicesResult !== null ? ($servicesResult['service_codes'] ?? array()) : array(),
            'order_fulfillment_repair' => $fulfillmentRepair,
        ];
    }

    protected function getOrderIdByReference($orderRef)
    {
        $safeRef = str_replace("'", "''", (string) $orderRef);
        $sql = "SELECT TOP 1 d.dok_Id, d.dok_NrPelny
                FROM dok__Dokument d
                WHERE d.dok_Typ = 16
                AND d.dok_Status >= 0
                AND d.dok_NrPelny = '{$safeRef}'
                ORDER BY d.dok_Id DESC";
        $data = MSSql::getInstance()->query($sql);
        if (!is_array($data) || empty($data)) {
            return null;
        }
        return (int) $data[0]['dok_Id'];
    }

    protected function getExistingIssueForOrder($orderRef, Order $order = null)
    {
        if ($order !== null) {
            return $order->findValidIssueForOrder();
        }

        foreach (Order::orderRefVariants((string) $orderRef) as $variant) {
            $safeRef = str_replace("'", "''", $variant);
            $sql = "SELECT TOP 1 d.dok_NrPelny
                    FROM dok__Dokument d
                    WHERE d.dok_Typ = 11
                    AND d.dok_Status >= 0
                    AND d.dok_NrPelnyOryg = '{$safeRef}'
                    ORDER BY d.dok_Id DESC";
            $data = MSSql::getInstance()->query($sql);
            if (is_array($data) && !empty($data) && isset($data[0]['dok_NrPelny'])) {
                return (string) $data[0]['dok_NrPelny'];
            }
        }
        return null;
    }

    /**
     * @param string $orderRef
     * @param array $wzResult
     * @param bool $fullRealization pełne ilości na WZ
     * @param bool $closeOrder domknij ZK (status 7/8) + link WZ tylko na nagłówku ZK
     * @param bool $alreadyExists
     * @return array
     */
    protected function buildCreateIssueFromOrderResponse($orderRef, array $wzResult, $fullRealization, $closeOrder = false, $alreadyExists = false)
    {
        $docRef = isset($wzResult['doc_ref']) ? (string) $wzResult['doc_ref'] : '';
        if ($docRef === '') {
            $docRef = isset($wzResult['issue_ref']) ? (string) $wzResult['issue_ref'] : '';
        }

        $wzResult['doc_ref'] = $docRef;
        $wzResult['document_ref'] = $docRef;
        $wzResult['issue_ref'] = $docRef;
        $wzResult['order_ref'] = $orderRef;
        $wzResult['doc_type'] = 11;
        $wzResult['already_exists'] = (bool) $alreadyExists;
        $wzResult['full_realization'] = (bool) $fullRealization;
        $wzResult['realize_as_wz'] = (bool) $fullRealization;
        $wzResult['close_order'] = (bool) $closeOrder;

        $fulfilled = !empty($wzResult['fulfilled']) || !empty($wzResult['fully_realized']);
        $coverageComplete = !empty($wzResult['issue_coverage_complete']);
        $zkClosed = !empty($wzResult['zk_closed'])
            || Order::isOrderStatusFulfilled((int) ($wzResult['state'] ?? 0));
        $wzResult['zk_closed'] = $zkClosed;

        if ($docRef === '' && ($fullRealization || $closeOrder)) {
            return array(
                'state' => 'fail',
                'message' => 'Nie udało się utworzyć WZ dla ZK ' . $orderRef,
                'error' => 'WZ_CREATE_FAILED',
                'data' => $wzResult,
            );
        }

        if ($closeOrder && !$zkClosed && !$coverageComplete) {
            return array(
                'state' => 'fail',
                'message' => 'WZ ' . ($docRef !== '' ? $docRef . ' ' : '')
                    . 'istnieje, ale ZK ' . $orderRef . ' nie zostało domknięte w Subiekcie '
                    . '(brak statusu „zrealizowane”).',
                'error' => 'ORDER_NOT_CLOSED',
                'data' => $wzResult,
            );
        }

        $message = $alreadyExists ? 'WZ already exists' : 'WZ created';
        if ($closeOrder && $zkClosed && $docRef !== '') {
            $message = $alreadyExists ? 'WZ już istnieje, ZK zrealizowane.' : 'WZ wystawione, ZK zrealizowane (status 7/8).';
        } elseif ($docRef !== '' && !$closeOrder) {
            $message = $alreadyExists
                ? 'WZ już istnieje — ZK pozostaje otwarte (close_order=false).'
                : 'WZ wystawione — ZK otwarte (rezerwacja aktywna).';
        } elseif ($docRef !== '' && $closeOrder && !$zkClosed) {
            $message = $alreadyExists
                ? 'WZ już istnieje — ZK nie domknięte (sprawdź pokrycie towarów).'
                : 'WZ wystawione — ZK nie domknięte (sprawdź pokrycie towarów).';
        }

        return array(
            'state' => 'success',
            'message' => $message,
            'data' => $wzResult,
        );
    }

    public function createIssueFromOrder()
    {
        try {
            $orderRef = isset($this->documentDetail['order_ref']) ? trim((string) $this->documentDetail['order_ref']) : '';
            $reference = isset($this->documentDetail['reference']) ? trim((string) $this->documentDetail['reference']) : '';
            // Domyślnie: WZ + domknięcie ZK (7/8), żeby zwolnić rezerwacje. close_order=false zostawia ZK otwarte.
            $fullRealization = Order::isFullRealizationRequested($this->documentDetail, false);
            $closeOrder = Order::isCloseOrderRequested($this->documentDetail, true);

            if ($orderRef === '') {
                return [
                    'state' => 'error',
                    'message' => 'Brak wymaganego pola data.order_ref',
                    'error' => 'VALIDATION_ERROR',
                ];
            }

            Logger::getInstance()->log(
                'api',
                'createIssueFromOrder start: order_ref=' . $orderRef
                    . ', reference=' . $reference
                    . ', full_realization=' . ($fullRealization ? 'tak' : 'nie')
                    . ', close_order=' . ($closeOrder ? 'tak' : 'nie')
                    . ', copy_services=' . (Order::isCopyServicesFromOrderRequested($this->documentDetail, true) ? 'tak' : 'nie'),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );

            $order = Order::loadExistingByRefVariants($this->subiektGt, $this->documentDetail);
            if ($order === null) {
                return [
                    'state' => 'error',
                    'message' => 'Nie znaleziono zamówienia o podanym order_ref',
                    'error' => 'ORDER_NOT_FOUND',
                ];
            }
            $order->setCfg($this->cfg);
            $orderSnapshot = $order->get();
            $orderRef = isset($orderSnapshot['order_ref']) ? (string) $orderSnapshot['order_ref'] : $orderRef;
            $orderId = (int) ($orderSnapshot['gt_id'] ?? 0);
            $prep = $order->prepareOrderForIssueFromApi();
            Logger::getInstance()->log(
                'api',
                'createIssueFromOrder: prepareOrderForIssueFromApi order_ref=' . $orderRef
                    . ', prepared=' . (!empty($prep['prepared']) ? 'tak' : 'nie')
                    . ', state=' . (int) ($prep['state'] ?? 0)
                    . ', reservation=' . (!empty($prep['reservation']) ? 'tak' : 'nie'),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
            $orderSnapshot = $order->get();

            if ($closeOrder && $order->isBusinessComplete()) {
                $existingIssueRef = $order->findValidIssueForOrder();
                $data = array(
                    'doc_ref' => $existingIssueRef !== null ? $existingIssueRef : '',
                    'document_ref' => $existingIssueRef,
                    'issue_ref' => $existingIssueRef,
                    'fulfilled' => true,
                    'fully_realized' => true,
                    'state' => (int) ($orderSnapshot['state'] ?? 0),
                );
                Logger::getInstance()->log(
                    'api',
                    'createIssueFromOrder: ZK już zrealizowane order_ref=' . $orderRef
                        . ', doc_ref=' . ($existingIssueRef ?? ''),
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );
                return $this->buildCreateIssueFromOrderResponse($orderRef, $data, $fullRealization, $closeOrder, true);
            }

            $existingIssueRef = $order->findValidIssueForOrder();

            $reservationRequested = true;
            if (isset($this->documentDetail['reservation'])) {
                $reservationRequested = filter_var(
                    $this->documentDetail['reservation'],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );
                if ($reservationRequested === null) {
                    $reservationRequested = true;
                }
            }

            if ($reservationRequested) {
                $sqlReserved = Order::orderHasActiveReservationSql($orderId, $orderRef);
                $comReserved = (bool) ($orderSnapshot['reservation'] ?? false);
                $needsReservation = !$sqlReserved && !$comReserved;
                $needsComSync = $sqlReserved && !$comReserved;
                if ($needsReservation || $needsComSync) {
                    try {
                        $order->reserve();
                        Logger::getInstance()->log(
                            'api',
                            'createIssueFromOrder: '
                                . ($needsComSync ? 'zsynchronizowano' : 'włączono')
                                . ' rezerwację COM przed WZ dla ' . $orderRef,
                            __CLASS__ . '->' . __FUNCTION__,
                            __LINE__
                        );
                    } catch (Exception $reserveException) {
                        Logger::getInstance()->log(
                            'api',
                            'createIssueFromOrder: nie udało się włączyć rezerwacji przed WZ: '
                                . $reserveException->getMessage(),
                            __CLASS__ . '->' . __FUNCTION__,
                            __LINE__
                        );
                    }
                } else {
                    Logger::getInstance()->log(
                        'api',
                        'createIssueFromOrder: ZK ma rezerwację w SQL i COM, pomijam reserve() dla '
                            . $orderRef,
                        __CLASS__ . '->' . __FUNCTION__,
                        __LINE__
                    );
                }
            }

            $existingIssueRef = $order->findValidIssueForOrder();
            if ($existingIssueRef !== null && $existingIssueRef !== '') {
                $fulfillment = null;
                if ($closeOrder) {
                    $fulfillment = $order->fulfillRemainingToWz(false);
                }

                $data = array(
                    'doc_ref' => $existingIssueRef,
                    'document_ref' => $existingIssueRef,
                    'issue_ref' => $existingIssueRef,
                );
                if (is_array($fulfillment)) {
                    $data = array_merge($data, $fulfillment);
                }
                if ($closeOrder) {
                    $order->finalizeOrderAfterIssueSaved(array($existingIssueRef), true);
                    $data = $order->enrichIssueResultPayload($data);
                } else {
                    $order->finalizeOrderAfterIssueSaved(array($existingIssueRef), false);
                    $data = $order->enrichIssueResultPayload($data);
                }

                $shouldAppendServices = Order::isCopyServicesFromOrderRequested($this->documentDetail, true)
                    || Order::isAppendServicesRequested($this->documentDetail)
                    || !empty($this->documentDetail['services']);
                if ($shouldAppendServices) {
                    $appendResult = $order->appendMissingServicesToIssue(
                        $existingIssueRef,
                        $this->documentDetail
                    );
                    if (!empty($appendResult['added'])) {
                        $data['services_appended'] = (int) $appendResult['added'];
                        $data['service_codes'] = $appendResult['service_codes'];
                        if ($closeOrder) {
                            $order->finalizeOrderAfterIssueSaved(array($existingIssueRef), true);
                            $data = $order->enrichIssueResultPayload($data);
                        }
                    }
                }

                $data = $this->attachFsCleanupToIssuePayload($data, $existingIssueRef);

                if ($closeOrder) {
                    $fulfillmentRepair = Order::ensureOrderFulfilledAfterIssueSql($orderRef);
                    if (is_array($fulfillmentRepair) && ($fulfillmentRepair['state'] ?? '') === 'success') {
                        $data = $order->enrichIssueResultPayload($data);
                        $data['fulfillment_repaired'] = true;
                    }
                    $data['fulfillment_check'] = $fulfillmentRepair;
                }

                Logger::getInstance()->log(
                    'api',
                    'createIssueFromOrder: WZ już istnieje order_ref=' . $orderRef . ', doc_ref=' . $existingIssueRef
                        . ', zk_closed=' . (!empty($data['zk_closed']) ? 'tak' : 'nie'),
                    __CLASS__ . '->' . __FUNCTION__,
                    __LINE__
                );

                return $this->buildCreateIssueFromOrderResponse($orderRef, $data, $fullRealization, $closeOrder, true);
            }

            $wzResult = $order->createWzFromOrder(
                $fullRealization,
                $reference,
                $this->documentDetail,
                $closeOrder
            );

            if (!empty($wzResult['stock_failed'])) {
                $shortages = isset($wzResult['shortages']) && is_array($wzResult['shortages'])
                    ? $wzResult['shortages']
                    : array();
                $shortageParts = array();
                foreach ($shortages as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $code = trim((string) ($row['sku'] ?? $row['code'] ?? ''));
                    $missing = (float) ($row['missing'] ?? 0);
                    if ($code === '' || $missing <= 0.00001) {
                        continue;
                    }
                    $shortageParts[] = $code . ' (brakuje ' . $missing . ')';
                }
                $detail = !empty($shortageParts) ? ': ' . implode(', ', $shortageParts) : '';

                return [
                    'state' => 'fail',
                    'message' => 'Brak towaru w magazynie — nie można utworzyć WZ dla ZK ' . $orderRef . $detail,
                    'error' => 'INSUFFICIENT_STOCK',
                    'data' => [
                        'order_ref' => $orderRef,
                        'shortages' => $shortages,
                    ],
                ];
            }

            $docRef = isset($wzResult['doc_ref']) ? (string) $wzResult['doc_ref'] : '';
            if ($docRef === '') {
                $docRef = (string) $order->findValidIssueForOrder();
            }
            if ($docRef === '') {
                throw new Exception('Brak numeru dokumentu WZ po zapisie');
            }

            $shouldAppendServices = Order::isCopyServicesFromOrderRequested($this->documentDetail, true)
                || Order::isAppendServicesRequested($this->documentDetail)
                || !empty($this->documentDetail['services']);
            if ($shouldAppendServices) {
                $appendResult = $order->appendMissingServicesToIssue($docRef, $this->documentDetail);
                if (!empty($appendResult['added'])) {
                    $wzResult['services_appended'] = (int) $appendResult['added'];
                    $wzResult['service_codes'] = $appendResult['service_codes'];
                    if ($closeOrder) {
                        $order->finalizeOrderAfterIssueSaved(array($docRef), true);
                        $wzResult = $order->enrichIssueResultPayload($wzResult);
                    }
                }
            }

            $wzResult = $order->enrichIssueResultPayload($wzResult);
            $wzResult = $this->attachFsCleanupToIssuePayload($wzResult, $docRef);

            if ($closeOrder) {
                $fulfillmentRepair = Order::ensureOrderFulfilledAfterIssueSql($orderRef);
                if (is_array($fulfillmentRepair) && ($fulfillmentRepair['state'] ?? '') === 'success') {
                    $wzResult = $order->enrichIssueResultPayload($wzResult);
                    $wzResult['fulfillment_repaired'] = true;
                }
                $wzResult['fulfillment_check'] = $fulfillmentRepair;
            }

            Logger::getInstance()->log(
                'api',
                'createIssueFromOrder success: order_ref=' . $orderRef
                    . ', doc_ref=' . $docRef
                    . ', zk_closed=' . (!empty($wzResult['zk_closed']) ? 'tak' : 'nie'),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );

            return $this->buildCreateIssueFromOrderResponse($orderRef, $wzResult, $fullRealization, $closeOrder, false);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            Logger::getInstance()->log('api', 'createIssueFromOrder error: ' . $message, __CLASS__ . '->' . __FUNCTION__, __LINE__);

            if (stripos($message, 'nie jest powiązane z ZK') !== false
                || stripos($message, 'konflikt numeru') !== false) {
                return [
                    'state' => 'fail',
                    'message' => $message,
                    'error' => 'WZ_ORDER_MISMATCH',
                ];
            }

            if (stripos($message, 'zrealizowan') !== false) {
                return [
                    'state' => 'fail',
                    'message' => $message,
                    'error' => 'ORDER_ALREADY_FULFILLED',
                ];
            }

            return [
                'state' => 'error',
                'message' => 'Nie udało się utworzyć dokumentu WZ',
                'error' => 'WZ_CREATE_FAILED',
                'details' => $message,
            ];
        }
    }

    /**
     * Po wystawieniu FS (np. ręcznie w GT) usuwa linki ZK blokujące KFS.
     * POST Document/finalizeSalesInvoiceForCorrection — data.invoice_ref lub data.issue_ref / data.doc_ref (WZ).
     */
    public function finalizeSalesInvoiceForCorrection()
    {
        $invoiceRef = isset($this->documentDetail['invoice_ref'])
            ? trim((string) $this->documentDetail['invoice_ref'])
            : '';
        $issueRef = isset($this->documentDetail['issue_ref'])
            ? trim((string) $this->documentDetail['issue_ref'])
            : '';
        $docRef = isset($this->documentDetail['doc_ref'])
            ? trim((string) $this->documentDetail['doc_ref'])
            : '';

        if ($invoiceRef === '' && $issueRef !== '') {
            $invoiceRef = Order::findSalesInvoiceRefForIssueSql($issueRef);
        }
        if ($invoiceRef === '' && $docRef !== '') {
            $invoiceRef = Order::findSalesInvoiceRefForIssueSql($docRef);
        }
        if ($invoiceRef === '' && isset($this->documentDetail['fs_ref'])) {
            $invoiceRef = trim((string) $this->documentDetail['fs_ref']);
        }

        if ($invoiceRef === '') {
            return array(
                'state' => 'error',
                'message' => 'Podaj data.invoice_ref (FS) lub data.issue_ref / data.doc_ref (WZ z powiązaną FS).',
                'error' => 'VALIDATION_ERROR',
            );
        }

        $result = Order::cleanupSalesInvoiceLinksAfterFsSql(
            $invoiceRef,
            __CLASS__ . '->' . __FUNCTION__
        );
        $state = (string) ($result['state'] ?? '');

        if ($state === 'success' || $state === 'noop') {
            return array(
                'state' => 'success',
                'message' => (string) ($result['message'] ?? 'FS gotowa pod KFS.'),
                'data' => $result,
            );
        }

        return array(
            'state' => 'error',
            'message' => (string) ($result['message'] ?? 'Nie udało się przygotować FS pod KFS.'),
            'error' => 'FS_CLEANUP_FAILED',
            'data' => $result,
        );
    }

    /**
     * Gdy WZ ma już FS — automatycznie czyści linki ZK (prewencja błędów KFS).
     *
     * @param array $data
     * @param string $issueRef
     * @return array
     */
    protected function attachFsCleanupToIssuePayload(array $data, $issueRef)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '') {
            return $data;
        }

        $cleanup = Order::maybeAutoCleanupSalesInvoiceForIssueSql($issueRef);
        $cleanupState = (string) ($cleanup['state'] ?? '');

        if ($cleanupState === 'success') {
            $data['fs_links_cleaned'] = true;
            if (!empty($cleanup['invoice_ref'])) {
                $data['invoice_ref'] = (string) $cleanup['invoice_ref'];
            }
        } elseif ($cleanupState !== 'noop') {
            $data['fs_links_cleanup'] = $cleanup;
        }

        return $data;
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
                    k.adr_Telefon as phone,
                    ksef_nr.ksefnr_NumerKSeF AS ksef_numer,
                    ksef_nr.ksefnr_DataNadania AS ksef_data_nadania
                FROM dok__Dokument d
                LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                " . $this->sqlLeftJoinKsefNumer('d', 'ksef_nr') . "
                WHERE d.dok_Typ = 2 
                AND d.dok_StatusKsieg = 0
                AND d.dok_Status >= 0
                AND d.dok_KwDoZaplaty > 0
                AND d.dok_Rozliczony = 0
                AND " . $this->sqlWhereFsKsefRequiredFromCutoff('d', 'ksef_nr');

            $data = MSSql::getInstance()->query($sql);
            $result = [];

            foreach ($data as $row) {
                $item = [
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
                $item = array_merge($item, $this->ksefFieldsFromRow($row));
                $result[] = $item;
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

    protected function escapeSqlString($value)
    {
        return str_replace("'", "''", (string) $value);
    }

    protected function buildSettlementCustomerFilter()
    {
        if (isset($this->documentDetail['customer_id']) && is_numeric($this->documentDetail['customer_id']) && (int) $this->documentDetail['customer_id'] > 0) {
            $customerId = (int) $this->documentDetail['customer_id'];
            return [
                'where' => "k.kh_Id = {$customerId}",
                'meta' => [
                    'type' => 'customer_id',
                    'value' => $customerId,
                ],
            ];
        }

        if (isset($this->documentDetail['tax_id']) && trim((string) $this->documentDetail['tax_id']) !== '') {
            $taxId = $this->escapeSqlString(trim((string) $this->documentDetail['tax_id']));
            return [
                'where' => "k.adr_NIP = '{$taxId}'",
                'meta' => [
                    'type' => 'tax_id',
                    'value' => $taxId,
                ],
            ];
        }

        if (isset($this->documentDetail['ref_id']) && trim((string) $this->documentDetail['ref_id']) !== '') {
            $refId = $this->escapeSqlString(trim((string) $this->documentDetail['ref_id']));
            return [
                'where' => "k.kh_Symbol = '{$refId}'",
                'meta' => [
                    'type' => 'ref_id',
                    'value' => $refId,
                ],
            ];
        }

        return null;
    }

    public function getSettlementsByCustomer()
    {
        try {
            $customerFilter = $this->buildSettlementCustomerFilter();
            if ($customerFilter === null) {
                throw new Exception('Brak parametru klienta. Podaj jedno z pól: customer_id, tax_id, ref_id');
            }

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
                    Bk.Rozliczenie as settlement_state,
                    k.kh_Id as customer_id,
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
                AND " . $customerFilter['where'] . "
                ORDER BY Bk.nzf_TerminPlatnosci ASC, Bk.nzf_Id DESC";

            $data = MSSql::getInstance()->query($sql);
            $result = [];

            foreach ($data as $row) {
                $positions = [];
                if (isset($row['dok_Id']) && is_numeric($row['dok_Id']) && (int) $row['dok_Id'] > 0) {
                    $positionsSql = "SELECT
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
                    WHERE p.ob_DokHanId = " . (int) $row['dok_Id'];
                    $positions = MSSql::getInstance()->query($positionsSql);
                }

                $result[] = [
                    'settlement_id' => $row['nzf_Id'],
                    'doc_ref' => $row['nzf_NumerPelny'],
                    'date_issue' => $row['date_issue'],
                    'payment_term' => $row['payment_term'],
                    'days_overdue' => $row['days_overdue'],
                    'last_payment_date' => $row['last_payment_date'],
                    'settlement_state' => $row['settlement_state'],
                    'amount' => [
                        'total' => $row['amount_total'],
                        'original' => $row['amount_original'],
                        'liability' => $row['amount_liability'],
                    ],
                    'customer' => [
                        'id' => $row['customer_id'],
                        'ref_id' => $row['ref_id'],
                        'company_name' => $row['company_name'],
                        'tax_id' => $row['tax_id'],
                        'address' => $row['address'],
                        'post_code' => $row['post_code'],
                        'city' => $row['city'],
                        'email' => $row['email'],
                        'phone' => $row['phone'],
                    ],
                    'flag' => [
                        'name' => $row['flag_name'],
                        'comment' => $row['flag_comment'],
                    ],
                    'positions' => $positions,
                ];
            }

            Logger::getInstance()->log('api', 'Pobrano rozrachunki klienta: ' . json_encode($customerFilter['meta']), __CLASS__ . '->' . __FUNCTION__, __LINE__);

            return [
                'state' => 'success',
                'data' => [
                    'customer_filter' => $customerFilter['meta'],
                    'count' => count($result),
                    'settlements' => $result,
                ],
            ];
        } catch (Exception $e) {
            Logger::getInstance()->log('api', 'Błąd podczas pobierania rozrachunków klienta: ' . $e->getMessage(), __CLASS__ . '->' . __FUNCTION__, __LINE__);
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
                    d.dok_WartNetto,
                    d.dok_WartTwNetto,
                    d.dok_WartMag,
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
                    'projected_value' => $row['dok_WartMag'],
                    'projected_profit' => ($row['dok_WartTwNetto'] - $row['dok_WartMag']),
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

            $ksefSelect = ($doc_type == 2 || $doc_type == 6)
                ? ", ksef_nr.ksefnr_NumerKSeF AS ksef_numer, ksef_nr.ksefnr_DataNadania AS ksef_data_nadania"
                : '';
            $ksefJoin = ($doc_type == 2 || $doc_type == 6) ? "\n                " . $this->sqlLeftJoinKsefNumer('d', 'ksef_nr') : '';
            $ksefAnd = ($doc_type == 2) ? "\n                AND " . $this->sqlWhereFsKsefRequiredFromCutoff('d', 'ksef_nr') : '';

            $sql = "SELECT TOP {$limit}
                    d.dok_Id,
                    d.dok_NrPelny,
                    d.dok_NrPelnyOryg,
                    d.dok_WartBrutto,
                    d.dok_WartNetto,
                    d.dok_WartVat,
                    d.dok_WartTwNetto,
                    d.dok_WartMag,
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
                    {$ksefSelect}
                FROM dok__Dokument d
                LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id{$ksefJoin}
                WHERE d.dok_Typ = {$doc_type}
                AND d.dok_Status >= 0{$ksefAnd}
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
                    'projected_value' => $row['dok_WartMag'],
                    'projected_profit' => ($row['dok_WartTwNetto'] - $row['dok_WartMag']),
                    'date_issue' => $this->formatDateForResponse($row['dok_DataWyst']),
                    'date_of_delivery' => $this->formatDateForResponse($row['dok_TerminRealizacji']),
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
                    $document = array_merge($document, $this->ksefFieldsFromRow($row));
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
                    $document = array_merge($document, $this->ksefFieldsFromRow($row));
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
     * Pobiera wszystkie typy dokumentów od początku bieżącego miesiąca
     * 
     * @param int $limit Limit dokumentów na typ
     * @return array Wynik z dokumentami pogrupowanymi według typu
     */
    public function getRecentDocuments($limit = 100)
    {
        try {
            Logger::getInstance()->log('api', 'Rozpoczęcie pobierania dokumentów od początku bieżącego miesiąca', __CLASS__ . '->' . __FUNCTION__, __LINE__);
            
            $first_day_of_month = date('Y-m-01');
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
                        'from' => $first_day_of_month,
                        'to' => $today
                    ]
                ]
            ];
            
            foreach ($doc_types as $type_id => $type_name) {
                $ksefSelect = ($type_id == 2 || $type_id == 6)
                    ? ", ksef_nr.ksefnr_NumerKSeF AS ksef_numer, ksef_nr.ksefnr_DataNadania AS ksef_data_nadania"
                    : '';
                $ksefJoin = ($type_id == 2 || $type_id == 6) ? "\n                    " . $this->sqlLeftJoinKsefNumer('d', 'ksef_nr') : '';
                $ksefAnd = ($type_id == 2) ? "\n                    AND " . $this->sqlWhereFsKsefRequiredFromCutoff('d', 'ksef_nr') : '';

                $sql = "SELECT TOP {$limit}
                        d.dok_Id,
                        d.dok_NrPelny,
                        d.dok_NrPelnyOryg,
                        d.dok_WartBrutto,
                        d.dok_WartNetto,
                        d.dok_WartVat,
                        d.dok_WartTwNetto,
                        d.dok_WartMag,
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
                        {$ksefSelect}
                    FROM dok__Dokument d
                    LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id{$ksefJoin}
                    WHERE d.dok_Typ = {$type_id}
                    AND d.dok_Status >= 0
                    AND d.dok_DataWyst >= '{$first_day_of_month}'
                    AND d.dok_DataWyst <= '{$today}'{$ksefAnd}
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
                        'projected_value' => $row['dok_WartMag'],
                        'projected_profit' => ($row['dok_WartTwNetto'] - $row['dok_WartMag']),
                        'date_issue' => $this->formatDateForResponse($row['dok_DataWyst']),
                        'date_of_delivery' => $this->formatDateForResponse($row['dok_TerminRealizacji']),
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
                        $document = array_merge($document, $this->ksefFieldsFromRow($row));
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
                        $document = array_merge($document, $this->ksefFieldsFromRow($row));
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
            
            Logger::getInstance()->log('api', "Pobrano {$result['summary']['total_documents']} dokumentów od początku miesiąca", __CLASS__ . '->' . __FUNCTION__, __LINE__);
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
                        d.dok_WartTwNetto,
                        d.dok_WartMag,
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
                        k.adr_Telefon,
                        ksef_nr.ksefnr_NumerKSeF AS ksef_numer,
                        ksef_nr.ksefnr_DataNadania AS ksef_data_nadania
                    FROM dok__Dokument d
                    LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                    " . $this->sqlLeftJoinKsefNumer('d', 'ksef_nr') . "
                    WHERE d.dok_PlatnikId = {$customer_id}
                    AND d.dok_Typ IN (2, 6, 11, 16)  -- FS, KFS, WZ, ZK
                    AND " . $this->sqlWhereFsKsefWhenMixedDocTypes('d', 'ksef_nr') . "
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
                    'projected_value' => $row['dok_WartMag'],
                    'projected_profit' => ($row['dok_WartTwNetto'] - $row['dok_WartMag']),
                    'date_issue' => $this->formatDateForResponse($row['dok_DataWyst']),
                    'date_of_delivery' => $this->formatDateForResponse($row['dok_TerminRealizacji']),
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
                if ((int) $row['dok_Typ'] === 2 || (int) $row['dok_Typ'] === 6) {
                    $document = array_merge($document, $this->ksefFieldsFromRow($row));
                }
                
                // Dla dokumentów KFS (korekt) dodajemy numer dokumentu korygowanego
                if ($row['dok_Typ'] == 6) { // KFS
                    $document['corrected_document_number'] = $row['dok_NrPelnyOryg'] ? $row['dok_NrPelnyOryg'] : null;
                }
                
                // Dla faktur (FS) dodajemy informacje o powiązanym zamówieniu
                if ($row['dok_Typ'] == 2 && isset($row['dok_PrzetworzonoZKwZD']) && (int)$row['dok_PrzetworzonoZKwZD'] === 1) { // FS przetworzona z zamówienia
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
     * Pobiera faktury (FV) z określonego zakresu dat.
     * Od daty KSEF_FS_FILTER_FROM zwracane są wyłącznie faktury z niepustym numerem KSeF (tabela ksef_NumerKSeF).
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

            // Pobieramy faktury (typ 2) z zakresu dat; od KSEF_FS_FILTER_FROM — tylko z numerem w ksef_NumerKSeF
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
                        fw.flw_Komentarz,
                        ksef_nr.ksefnr_NumerKSeF AS ksef_numer,
                        ksef_nr.ksefnr_DataNadania AS ksef_data_nadania
                    FROM dok__Dokument d
                    LEFT JOIN vwKlienci k ON d.dok_PlatnikId = k.kh_Id
                    LEFT JOIN fl_Wartosc fw ON (fw.flw_IdObiektu = d.dok_Id)
                    LEFT JOIN fl__Flagi f ON (f.flg_Id = fw.flw_IdFlagi)
                    " . $this->sqlLeftJoinKsefNumer('d', 'ksef_nr') . "
                    WHERE d.dok_Typ = 2  -- Faktura sprzedaży
                    AND d.dok_Status >= 0
                    AND d.dok_DataWyst >= '{$date_from}'
                    AND d.dok_DataWyst <= '{$date_to}'
                    AND " . $this->sqlWhereFsKsefRequiredFromCutoff('d', 'ksef_nr') . "
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
                    'date_issue' => $this->formatDateForResponse($row['dok_DataWyst']),
                    'date_of_delivery' => $this->formatDateForResponse($row['dok_TerminRealizacji']),
                    'payment_term' => isset($row['dok_PlatTermin']) ? (($row['dok_PlatTermin'] instanceof \DateTimeInterface) ? $row['dok_PlatTermin']->format('Y-m-d') : $row['dok_PlatTermin']) : null,
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
                $invoice = array_merge($invoice, $this->ksefFieldsFromRow($row));
                
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