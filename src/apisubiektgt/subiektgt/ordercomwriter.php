<?php

namespace APISubiektGT\SubiektGT;

use APISubiektGT\Logger;
use APISubiektGT\MSSql;
use APISubiektGT\SubiektGT;

/**
 * Mutacje dokumentów ZK/WZ/FS wyłącznie przez COM/Sfera (zapis).
 * Odczyt weryfikacyjny pozostaje w SQL (SELECT).
 */
class OrderComWriter
{
    /** @var bool */
    private static $comWritesOnly = true;

    /** @var bool */
    private static $allowSqlFallback = false;

    /**
     * @param bool $comWritesOnly
     * @param bool $allowSqlFallback
     */
    public static function configure($comWritesOnly = true, $allowSqlFallback = false)
    {
        self::$comWritesOnly = (bool) $comWritesOnly;
        self::$allowSqlFallback = (bool) $allowSqlFallback;
    }

    /**
     * @return bool
     */
    public static function comWritesOnly()
    {
        return self::$comWritesOnly;
    }

    /**
     * @return bool
     */
    public static function allowSqlWriteFallback()
    {
        return self::$allowSqlFallback;
    }

    /**
     * @param string $query
     * @return bool
     */
    public static function isWriteQuery($query)
    {
        $normalized = ltrim((string) $query);
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match(
            '/^\s*(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|EXEC(?:UTE)?)\b/i',
            $normalized
        );
    }

    /**
     * @param mixed $subiektGt
     * @return mixed|false
     */
    public static function resolveSubiektGt($subiektGt = null)
    {
        if ($subiektGt) {
            return $subiektGt;
        }

        if (!SubiektGT::hasInstance()) {
            return false;
        }

        $instance = SubiektGT::getInstance();
        if (method_exists($instance, 'getCom')) {
            $com = $instance->getCom();
            if ($com) {
                return $com;
            }
        }

        return false;
    }

    /**
     * @param mixed $subiektGt
     * @param string $ref
     * @return mixed|false
     */
    public static function loadDocument($subiektGt, $ref)
    {
        $ref = trim((string) $ref);
        $subiektGt = self::resolveSubiektGt($subiektGt);
        if ($ref === '' || !$subiektGt) {
            return false;
        }

        try {
            if (!$subiektGt->SuDokumentyManager->Istnieje($ref)) {
                return false;
            }

            return $subiektGt->SuDokumentyManager->Wczytaj($ref);
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'OrderComWriter::loadDocument(' . $ref . '): ' . $e->getMessage(),
                __CLASS__ . '::loadDocument',
                __LINE__
            );

            return false;
        }
    }

    /**
     * @param mixed $object
     * @param array<int, string> $propertyNames
     * @param mixed $value
     * @return string|false
     */
    public static function trySetProperty($object, array $propertyNames, $value)
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
     * @param mixed $object
     * @param array<int, string> $propertyNames
     * @param mixed|null $default
     * @return mixed
     */
    public static function tryGetProperty($object, array $propertyNames, $default = null)
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
     * @param mixed $doc
     * @param string $context
     * @return bool
     */
    public static function saveDocument($doc, $context = '')
    {
        if (!$doc) {
            return false;
        }

        try {
            $doc->Przelicz();
            $doc->Zapisz();
            return true;
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'OrderComWriter::saveDocument' . ($context !== '' ? ' (' . $context . ')' : '')
                    . ': ' . $e->getMessage(),
                __CLASS__ . '::saveDocument',
                __LINE__
            );

            return false;
        }
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param string|null $orderRef
     * @return array{doc:mixed, ref:string}|null
     */
    public static function loadOrderDocument($subiektGt, $orderId, $orderRef = null)
    {
        $orderId = (int) $orderId;
        $orderRef = $orderRef !== null ? trim((string) $orderRef) : '';

        if ($orderRef === '' && $orderId > 0) {
            $row = Order::getOrderRowByIdSql($orderId);
            if ($row !== null) {
                $orderRef = trim((string) ($row['dok_NrPelny'] ?? ''));
            }
        }

        if ($orderRef === '') {
            return null;
        }

        $doc = self::loadDocument($subiektGt, $orderRef);
        if (!$doc) {
            return null;
        }

        return array('doc' => $doc, 'ref' => $orderRef);
    }

    /**
     * @param mixed $orderDoc
     * @param int $statusEx
     * @return bool
     */
    public static function setOrderStatusEx($orderDoc, $statusEx)
    {
        if (!$orderDoc) {
            return false;
        }

        $set = self::trySetProperty(
            $orderDoc,
            array('StatusEx', 'StatusRozszerzony', 'DokStatusEx', 'StanRozszerzony'),
            (int) $statusEx
        );

        return $set !== false;
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param string|null $orderRef
     * @param int $targetStatus
     * @param bool $withReservation
     * @return bool
     */
    public static function applyOrderFulfilledStatus(
        $subiektGt,
        $orderId,
        $orderRef = null,
        $targetStatus = 8,
        $withReservation = false
    )
    {
        $orderId = (int) $orderId;
        $targetStatus = (int) $targetStatus;
        if ($orderId <= 0 || !in_array($targetStatus, array(7, 8), true)) {
            return false;
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
        if ($loaded === null) {
            return false;
        }

        $orderDoc = $loaded['doc'];
        $orderRef = $loaded['ref'];

        try {
            if ($targetStatus === 8 || !$withReservation) {
                $orderDoc->Rezerwacja = false;
            } elseif ($withReservation) {
                $orderDoc->Rezerwacja = true;
            }

            self::trySetProperty(
                $orderDoc,
                array('Status', 'StatusDokumentu', 'StanDokumentu'),
                $targetStatus
            );

            $row = Order::getOrderRowByIdSql($orderId);
            $currentEx = $row !== null ? (int) ($row['dok_StatusEx'] ?? 0) : 0;
            self::setOrderStatusEx($orderDoc, ($currentEx & ~1) | 4);

            if (!self::saveDocument($orderDoc, 'applyOrderFulfilledStatus:' . $orderRef)) {
                return false;
            }
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'applyOrderFulfilledStatus ' . $orderRef . ': ' . $e->getMessage(),
                __CLASS__ . '::applyOrderFulfilledStatus',
                __LINE__
            );

            return false;
        }

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Status, dok_StatusEx FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );

        return is_array($rows)
            && !empty($rows)
            && (int) ($rows[0]['dok_Status'] ?? 0) === $targetStatus
            && (((int) ($rows[0]['dok_StatusEx'] ?? 0)) & 4) !== 0;
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param string|null $orderRef
     * @return bool
     */
    public static function applyOrderPartialRealizationStatus($subiektGt, $orderId, $orderRef = null)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return false;
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
        if ($loaded === null) {
            return false;
        }

        $orderDoc = $loaded['doc'];
        $row = Order::getOrderRowByIdSql($orderId);
        $currentEx = $row !== null ? (int) ($row['dok_StatusEx'] ?? 0) : 0;
        if (($currentEx & 4) !== 0) {
            return true;
        }

        self::setOrderStatusEx($orderDoc, $currentEx | 1);
        if (!self::saveDocument($orderDoc, 'applyOrderPartialRealizationStatus')) {
            return false;
        }

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Status, dok_StatusEx FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );

        return is_array($rows)
            && !empty($rows)
            && Order::isOrderStatusOpen((int) ($rows[0]['dok_Status'] ?? 0))
            && (((int) ($rows[0]['dok_StatusEx'] ?? 0)) & 1) !== 0;
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param string|null $orderRef
     * @param bool $withReservation
     * @return bool
     */
    public static function reopenOrderStatus($subiektGt, $orderId, $orderRef = null, $withReservation = true)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return false;
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
        if ($loaded === null) {
            return false;
        }

        $targetStatus = $withReservation ? 7 : 6;
        $orderDoc = $loaded['doc'];

        try {
            self::trySetProperty(
                $orderDoc,
                array('Status', 'StatusDokumentu', 'StanDokumentu'),
                $targetStatus
            );
            $orderDoc->Rezerwacja = $withReservation;
            self::trySetProperty(
                $orderDoc,
                array('ZrealizowaneZRezerwacja', 'ZrealizowaneZRezerwacją'),
                false
            );
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'reopenOrderStatus: ' . $e->getMessage(),
                __CLASS__ . '::reopenOrderStatus',
                __LINE__
            );

            return false;
        }

        if (!self::saveDocument($orderDoc, 'reopenOrderStatus')) {
            return false;
        }

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Status FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );

        return is_array($rows)
            && !empty($rows)
            && (int) ($rows[0]['dok_Status'] ?? 0) === $targetStatus;
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param string $orderRef
     * @param bool $withReservation
     * @return bool
     */
    public static function reopenOrderAfterIssueRemoval($subiektGt, $orderId, $orderRef, $withReservation = true)
    {
        $orderId = (int) $orderId;
        $orderRef = trim((string) $orderRef);
        if ($orderId <= 0 || $orderRef === '') {
            return false;
        }

        if (Order::orderHasActiveIssueLinksSql($orderId, $orderRef)) {
            return false;
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
        if ($loaded === null) {
            return false;
        }

        $targetStatus = $withReservation ? 7 : 6;
        $orderDoc = $loaded['doc'];
        $row = Order::getOrderRowByIdSql($orderId);
        $currentEx = $row !== null ? (int) ($row['dok_StatusEx'] ?? 0) : 0;

        try {
            self::trySetProperty(
                $orderDoc,
                array('Status', 'StatusDokumentu', 'StanDokumentu'),
                $targetStatus
            );
            $orderDoc->Rezerwacja = $withReservation;
            self::setOrderStatusEx($orderDoc, $currentEx & ~4);
            self::trySetProperty(
                $orderDoc,
                array(
                    'DokumentDocelowy',
                    'DokumentDo',
                    'PowiazanyDokument',
                    'RealizacjaDokumentu',
                    'DokumentRealizacji',
                ),
                null
            );
            foreach (array('DoDokumentu', 'DoDokumentuNumer', 'DoDokumentuData') as $prop) {
                self::trySetProperty($orderDoc, array($prop), '');
            }
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'reopenOrderAfterIssueRemoval: ' . $e->getMessage(),
                __CLASS__ . '::reopenOrderAfterIssueRemoval',
                __LINE__
            );

            return false;
        }

        if (!self::saveDocument($orderDoc, 'reopenOrderAfterIssueRemoval:' . $orderRef)) {
            return false;
        }

        $rows = MSSql::getInstance()->query(
            "SELECT dok_Status, dok_DoDokId FROM dok__Dokument WHERE dok_Id = {$orderId} AND dok_Typ = 16"
        );

        return is_array($rows)
            && !empty($rows)
            && empty($rows[0]['dok_DoDokId'])
            && Order::isOrderStatusOpen((int) ($rows[0]['dok_Status'] ?? 0));
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param string|null $orderRef
     * @return int
     */
    public static function resetOrderPositionIssuedQtyWithoutIssue($subiektGt, $orderId, $orderRef = null)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return 0;
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
        if ($loaded === null) {
            return 0;
        }

        $orderDoc = $loaded['doc'];
        $resetCount = 0;
        $issueRefs = Order::getIssueRefsForOrder($loaded['ref'], $orderId);
        $issuedByPosId = array();
        if (!empty($issueRefs)) {
            foreach ($issueRefs as $issueRef) {
                $issueDoc = self::loadDocument($subiektGt, $issueRef);
                if (!$issueDoc) {
                    continue;
                }
                for ($j = 1; $j <= $issueDoc->Pozycje->Liczba(); $j++) {
                    $wzPos = $issueDoc->Pozycje->Element($j);
                    $doId = (int) self::tryGetProperty(
                        $wzPos,
                        array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                        0
                    );
                    if ($doId > 0) {
                        $issuedByPosId[$doId] = true;
                    }
                }
            }
        }

        for ($i = 1; $i <= $orderDoc->Pozycje->Liczba(); $i++) {
            $pos = $orderDoc->Pozycje->Element($i);
            $posId = (int) $pos->Id;
            if (isset($issuedByPosId[$posId])) {
                continue;
            }

            $realized = self::tryGetProperty(
                $pos,
                array('IloscZrealizowana', 'IloscZreal', 'ObIloscZrealizowana', 'Zrealizowano'),
                0.0
            );
            if ((float) $realized <= 0.00001) {
                continue;
            }

            if (self::trySetProperty(
                $pos,
                array('IloscZrealizowana', 'IloscZreal', 'ObIloscZrealizowana', 'Zrealizowano'),
                0.0
            ) !== false) {
                $resetCount++;
            }
        }

        if ($resetCount > 0) {
            self::saveDocument($orderDoc, 'resetOrderPositionIssuedQtyWithoutIssue');
        }

        return $resetCount;
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @param string|null $orderRef
     * @return int
     */
    public static function linkOrderHeaderToIssue($subiektGt, $orderId, array $issueRefs, $orderRef = null)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || empty($issueRefs)) {
            return 0;
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
        if ($loaded === null) {
            return 0;
        }

        $issueRef = trim((string) $issueRefs[0]);
        if ($issueRef === '') {
            return 0;
        }

        $issueDoc = self::loadDocument($subiektGt, $issueRef);
        if (!$issueDoc) {
            return 0;
        }

        $orderDoc = $loaded['doc'];
        $orderRef = trim((string) ($orderRef !== null ? $orderRef : $loaded['ref']));
        $issueId = (int) self::tryGetProperty($issueDoc, array('Identyfikator', 'Id'), 0);
        $linked = false;

        self::trySetProperty(
            $issueDoc,
            array('NumerPelnyOryginalny', 'NrPelnyOryg', 'NumerOryginalny'),
            $orderRef
        );
        if (self::trySetProperty(
            $issueDoc,
            array('DokumentZrodlowy', 'DokumentZrodla', 'NaPodstawieDokumentu'),
            $orderDoc
        ) !== false
            || self::trySetProperty(
                $issueDoc,
                array('DokumentZrodlowy', 'DokumentZrodla', 'NaPodstawieDokumentu'),
                $orderRef
            ) !== false) {
            self::saveDocument($issueDoc, 'linkOrderHeaderToIssue:wz-source');
        }

        foreach (array(
            array(array('DokumentDocelowy', 'DokumentDo', 'RealizacjaDokumentu', 'DokumentRealizacji', 'PowiazanyDokument'), $issueDoc),
            array(array('DokumentDocelowy', 'DokumentDo', 'RealizacjaDokumentu', 'DokumentRealizacji', 'PowiazanyDokument'), $issueRef),
            array(array('DoDokumentu', 'DoDokumentuNumer', 'DokumentDocelowyNumer'), $issueRef),
        ) as $attempt) {
            if (self::trySetProperty($orderDoc, $attempt[0], $attempt[1]) !== false) {
                $linked = true;
                break;
            }
        }

        if ($issueId > 0) {
            foreach (array('DoDokumentuId', 'DokumentDocelowyId', 'IdDokumentuDocelowego', 'DoDokId') as $prop) {
                if (self::trySetProperty($orderDoc, array($prop), $issueId) !== false) {
                    $linked = true;
                }
            }
        }

        if (!$linked) {
            try {
                if (method_exists($orderDoc, 'UstawDokumentDocelowy')) {
                    $orderDoc->UstawDokumentDocelowy($issueRef);
                    $linked = true;
                }
            } catch (\Exception $e) {
            }
        }

        if ($linked || $issueId > 0) {
            self::saveDocument($orderDoc, 'linkOrderHeaderToIssue:' . $loaded['ref']);
        }

        $safeIssueRef = str_replace("'", "''", $issueRef);
        $linkedRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok__Dokument zk
             INNER JOIN dok__Dokument wz ON wz.dok_Id = zk.dok_DoDokId AND wz.dok_Typ = 11
             WHERE zk.dok_Id = {$orderId} AND zk.dok_Typ = 16
               AND wz.dok_NrPelny = '{$safeIssueRef}'"
        );

        $count = is_array($linkedRows) && !empty($linkedRows) ? (int) ($linkedRows[0]['cnt'] ?? 0) : 0;
        if ($count > 0) {
            return $count;
        }

        $nrRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt FROM dok__Dokument
             WHERE dok_Id = {$orderId} AND dok_Typ = 16
               AND LTRIM(RTRIM(ISNULL(dok_DoDokNrPelny, ''))) = '{$safeIssueRef}'"
        );

        return is_array($nrRows) && !empty($nrRows) ? (int) ($nrRows[0]['cnt'] ?? 0) : ($linked ? 1 : 0);
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @return int
     */
    public static function linkIssueServicePositionsToOrder($subiektGt, $orderId, array $issueRefs)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || empty($issueRefs)) {
            return 0;
        }

        $subiektGt = self::resolveSubiektGt($subiektGt);
        if (!$subiektGt) {
            return 0;
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, null);
        if ($loaded === null) {
            return 0;
        }

        $orderDoc = $loaded['doc'];
        $zkServicesByTowId = array();
        for ($i = 1; $i <= $orderDoc->Pozycje->Liczba(); $i++) {
            $pos = $orderDoc->Pozycje->Element($i);
            $towId = (int) self::tryGetProperty($pos, array('TowarId', 'TowId', 'IdTowaru'), 0);
            $rodzaj = (int) self::tryGetProperty($pos, array('TowarRodzaj', 'Rodzaj', 'TowRodzaj'), 1);
            if ($towId > 0 && $rodzaj === Order::TOW_RODZAJ_USLUGA) {
                $zkServicesByTowId[$towId] = (int) $pos->Id;
            }
        }

        $linked = 0;
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef === '') {
                continue;
            }

            $issueDoc = self::loadDocument($subiektGt, $issueRef);
            if (!$issueDoc) {
                continue;
            }

            $changed = false;
            for ($j = 1; $j <= $issueDoc->Pozycje->Liczba(); $j++) {
                $wzPos = $issueDoc->Pozycje->Element($j);
                $doId = (int) self::tryGetProperty(
                    $wzPos,
                    array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                    0
                );
                if ($doId > 0) {
                    continue;
                }

                $towId = (int) self::tryGetProperty($wzPos, array('TowarId', 'TowId', 'IdTowaru'), 0);
                if ($towId <= 0 || !isset($zkServicesByTowId[$towId])) {
                    continue;
                }

                if (self::trySetProperty(
                    $wzPos,
                    array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                    $zkServicesByTowId[$towId]
                ) !== false) {
                    $linked++;
                    $changed = true;
                }
            }

            if ($changed) {
                self::saveDocument($issueDoc, 'linkIssueServicePositions:' . $issueRef);
            }
        }

        return $linked;
    }

    /**
     * Powiązuje pozycje towarowe WZ z pozycjami ZK (DoId) — gdy NaPodstawie nie zapisało ob_DoId w bazie.
     *
     * @param mixed $subiektGt
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @return int
     */
    public static function linkIssueGoodsPositionsToOrder($subiektGt, $orderId, array $issueRefs)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || empty($issueRefs)) {
            return 0;
        }

        $subiektGt = self::resolveSubiektGt($subiektGt);
        if (!$subiektGt) {
            return 0;
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, null);
        if ($loaded === null) {
            return 0;
        }

        $orderDoc = $loaded['doc'];
        $zkByTowId = array();
        $zkBySymbol = array();
        for ($i = 1; $i <= $orderDoc->Pozycje->Liczba(); $i++) {
            $pos = $orderDoc->Pozycje->Element($i);
            $posId = (int) $pos->Id;
            $towId = (int) self::tryGetProperty($pos, array('TowarId', 'TowId', 'IdTowaru'), 0);
            $rodzaj = (int) self::tryGetProperty($pos, array('TowarRodzaj', 'Rodzaj', 'TowRodzaj'), 1);
            if ($rodzaj === Order::TOW_RODZAJ_USLUGA) {
                continue;
            }
            if ($towId > 0) {
                $zkByTowId[$towId] = $posId;
            }
            $symbol = trim((string) $pos->TowarSymbol);
            if ($symbol !== '' && !isset($zkBySymbol[$symbol])) {
                $zkBySymbol[$symbol] = $posId;
            }
        }

        $linked = 0;
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef === '') {
                continue;
            }

            $issueDoc = self::loadDocument($subiektGt, $issueRef);
            if (!$issueDoc) {
                continue;
            }

            $changed = false;
            for ($j = 1; $j <= $issueDoc->Pozycje->Liczba(); $j++) {
                $wzPos = $issueDoc->Pozycje->Element($j);
                $doId = (int) self::tryGetProperty(
                    $wzPos,
                    array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                    0
                );
                if ($doId > 0) {
                    continue;
                }

                $rodzaj = (int) self::tryGetProperty($wzPos, array('TowarRodzaj', 'Rodzaj', 'TowRodzaj'), 1);
                if ($rodzaj === Order::TOW_RODZAJ_USLUGA) {
                    continue;
                }

                $zkPosId = 0;
                $towId = (int) self::tryGetProperty($wzPos, array('TowarId', 'TowId', 'IdTowaru'), 0);
                if ($towId > 0 && isset($zkByTowId[$towId])) {
                    $zkPosId = (int) $zkByTowId[$towId];
                } else {
                    $symbol = trim((string) $wzPos->TowarSymbol);
                    if ($symbol !== '' && isset($zkBySymbol[$symbol])) {
                        $zkPosId = (int) $zkBySymbol[$symbol];
                    }
                }

                if ($zkPosId <= 0) {
                    continue;
                }

                if (self::trySetProperty(
                    $wzPos,
                    array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                    $zkPosId
                ) !== false) {
                    $linked++;
                    $changed = true;
                }
            }

            if ($changed) {
                self::saveDocument($issueDoc, 'linkIssueGoodsPositions:' . $issueRef);
            }
        }

        return $linked;
    }

    /**
     * @param mixed $zkPos
     * @param mixed $wzPos
     * @param float $qty
     */
    public static function copyOrderPricesToIssuePosition($zkPos, $wzPos, $qty)
    {
        if (!$zkPos || !$wzPos) {
            return;
        }

        $priceNet = self::tryGetProperty(
            $zkPos,
            array('CenaNetto', 'CenaJednostkowaNetto', 'Cena'),
            null
        );
        $priceGross = self::tryGetProperty(
            $zkPos,
            array('CenaBrutto', 'CenaJednostkowaBrutto'),
            null
        );

        if ($priceNet !== null) {
            self::trySetProperty(
                $wzPos,
                array('CenaNetto', 'CenaJednostkowaNetto', 'Cena'),
                (float) $priceNet
            );
        }
        if ($priceGross !== null) {
            self::trySetProperty(
                $wzPos,
                array('CenaBrutto', 'CenaJednostkowaBrutto'),
                (float) $priceGross
            );
        }

        $orderedQty = (float) self::tryGetProperty($zkPos, array('IloscJm', 'Ilosc'), 0.0);
        if ($orderedQty <= 0.00001) {
            $orderedQty = (float) $qty;
        }

        $ratio = min(1.0, (float) $qty / $orderedQty);
        foreach (array(
            array('WartoscNetto', 'WartoscNettoPoRabacie'),
            array('WartoscBrutto', 'WartoscBruttoPoRabacie'),
        ) as $pair) {
            $value = self::tryGetProperty($zkPos, $pair, null);
            if ($value !== null && (float) $value > 0.00001) {
                self::trySetProperty($wzPos, $pair, round((float) $value * $ratio, 4));
            }
        }
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @param string|null $orderRef
     * @return int
     */
    public static function syncIssuePricesFromOrder($subiektGt, $orderId, array $issueRefs, $orderRef = null)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || empty($issueRefs)) {
            return 0;
        }

        self::linkIssueServicePositionsToOrder($subiektGt, $orderId, $issueRefs);

        $loaded = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
        if ($loaded === null) {
            return 0;
        }

        $orderDoc = $loaded['doc'];
        $zkById = array();
        $zkBySymbol = array();
        for ($i = 1; $i <= $orderDoc->Pozycje->Liczba(); $i++) {
            $pos = $orderDoc->Pozycje->Element($i);
            $posId = (int) $pos->Id;
            $zkById[$posId] = $pos;
            $symbol = trim((string) $pos->TowarSymbol);
            if ($symbol !== '' && !isset($zkBySymbol[$symbol])) {
                $zkBySymbol[$symbol] = $pos;
            }
        }

        $synced = 0;
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef === '') {
                continue;
            }

            $issueDoc = self::loadDocument($subiektGt, $issueRef);
            if (!$issueDoc) {
                continue;
            }

            $changed = false;
            for ($j = 1; $j <= $issueDoc->Pozycje->Liczba(); $j++) {
                $wzPos = $issueDoc->Pozycje->Element($j);
                $doId = (int) self::tryGetProperty(
                    $wzPos,
                    array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                    0
                );
                $qty = (float) self::tryGetProperty($wzPos, array('IloscJm', 'Ilosc'), 0.0);
                $zkPos = null;

                if ($doId > 0 && isset($zkById[$doId])) {
                    $zkPos = $zkById[$doId];
                } else {
                    $symbol = trim((string) $wzPos->TowarSymbol);
                    if ($symbol !== '' && isset($zkBySymbol[$symbol])) {
                        $zkPos = $zkBySymbol[$symbol];
                        if ($doId <= 0) {
                            $zkPosId = (int) $zkPos->Id;
                            if ($zkPosId > 0 && self::trySetProperty(
                                $wzPos,
                                array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                                $zkPosId
                            ) !== false) {
                                $changed = true;
                            }
                        }
                    }
                }

                if (!$zkPos) {
                    continue;
                }

                self::copyOrderPricesToIssuePosition($zkPos, $wzPos, $qty);
                $synced++;
                $changed = true;
            }

            if ($changed) {
                self::saveDocument($issueDoc, 'syncIssuePrices:' . $issueRef);
            }
        }

        return $synced;
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @param string $orderRef
     * @param bool $linkZkHeaderToIssue
     * @param bool $linkWzHeaderToOrder
     * @param bool $linkPositionsToZk
     * @param bool $setWzNrPelnyOryg
     * @param bool $linkServicePositionsToZk
     * @return array{position_links:int, wz_oryg:int, zk_header:int, wz_header:int, prices_synced:int}
     */
    public static function repairWzToOrderPositionLinks(
        $subiektGt,
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
        $empty = array(
            'position_links' => 0,
            'wz_oryg' => 0,
            'zk_header' => 0,
            'wz_header' => 0,
            'prices_synced' => 0,
        );
        if ($orderId <= 0 || $orderRef === '' || empty($issueRefs)) {
            return $empty;
        }

        $subiektGt = self::resolveSubiektGt($subiektGt);
        if (!$subiektGt) {
            return $empty;
        }

        if ($linkServicePositionsToZk) {
            self::linkIssueServicePositionsToOrder($subiektGt, $orderId, $issueRefs);
        }

        if ($linkPositionsToZk) {
            self::linkIssueGoodsPositionsToOrder($subiektGt, $orderId, $issueRefs);
        }

        if ($linkWzHeaderToOrder) {
            foreach ($issueRefs as $issueRef) {
                $issueRef = trim((string) $issueRef);
                if ($issueRef === '') {
                    continue;
                }
                $issueDoc = self::loadDocument($subiektGt, $issueRef);
                if (!$issueDoc) {
                    continue;
                }
                $loadedOrder = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
                if ($loadedOrder === null) {
                    continue;
                }
                if (self::trySetProperty(
                    $issueDoc,
                    array('DoDokumentu', 'DokumentZrodlowy', 'DokumentZamowienia', 'NaPodstawieDokumentu'),
                    $loadedOrder['doc']
                ) !== false
                    || self::trySetProperty(
                        $issueDoc,
                        array('DoDokumentu', 'DokumentZrodlowy', 'DokumentZamowienia', 'NaPodstawieDokumentu'),
                        $loadedOrder['ref']
                    ) !== false) {
                    self::saveDocument($issueDoc, 'linkWzHeaderToOrder:' . $issueRef);
                }
            }
        }

        if ($setWzNrPelnyOryg) {
            foreach ($issueRefs as $issueRef) {
                $issueRef = trim((string) $issueRef);
                if ($issueRef === '') {
                    continue;
                }
                $issueDoc = self::loadDocument($subiektGt, $issueRef);
                if (!$issueDoc) {
                    continue;
                }
                $current = trim((string) self::tryGetProperty(
                    $issueDoc,
                    array('NumerPelnyOryginalny', 'NrPelnyOryg', 'NumerOryginalny'),
                    ''
                ));
                if ($current === '') {
                    self::trySetProperty(
                        $issueDoc,
                        array('NumerPelnyOryginalny', 'NrPelnyOryg', 'NumerOryginalny'),
                        $orderRef
                    );
                    self::saveDocument($issueDoc, 'setWzNrPelnyOryg:' . $issueRef);
                }
            }
        }

        if ($linkZkHeaderToIssue) {
            self::linkOrderHeaderToIssue($subiektGt, $orderId, $issueRefs, $orderRef);
        }

        $pricesSynced = self::syncIssuePricesFromOrder($subiektGt, $orderId, $issueRefs, $orderRef);

        Order::clearWzDoDokIdPointingToOrderSql($orderId, $issueRefs);
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef === '') {
                continue;
            }
            $issueDoc = self::loadDocument($subiektGt, $issueRef);
            if (!$issueDoc) {
                continue;
            }
            if (self::trySetProperty(
                $issueDoc,
                array('DoDokumentu', 'DokumentDocelowy', 'DokumentDo'),
                null
            ) !== false) {
                self::saveDocument($issueDoc, 'clearWzDoDokIdPointingToOrder:' . $issueRef);
            }
        }

        return self::readWzLinkRepairStats($orderId, $issueRefs, $orderRef, $pricesSynced);
    }

    /**
     * @param int $orderId
     * @param array<int, string> $issueRefs
     * @param string $orderRef
     * @param int $pricesSynced
     * @return array{position_links:int, wz_oryg:int, zk_header:int, wz_header:int, prices_synced:int}
     */
    public static function readWzLinkRepairStats($orderId, array $issueRefs, $orderRef, $pricesSynced = 0)
    {
        $safeIssueRefs = array();
        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef !== '') {
                $safeIssueRefs[] = "'" . str_replace("'", "''", $issueRef) . "'";
            }
        }

        if (empty($safeIssueRefs)) {
            return array(
                'position_links' => 0,
                'wz_oryg' => 0,
                'zk_header' => 0,
                'wz_header' => 0,
                'prices_synced' => $pricesSynced,
            );
        }

        $issueInList = implode(', ', $safeIssueRefs);
        $safeOrderRef = str_replace("'", "''", $orderRef);

        $linkedRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok_Pozycja wp
             INNER JOIN dok__Dokument wz ON wz.dok_Id = wp.ob_DokMagId AND wz.dok_Typ = 11
             INNER JOIN dok_Pozycja zk ON zk.ob_Id = wp.ob_DoId AND zk.ob_DokHanId = {$orderId}
             WHERE wz.dok_NrPelny IN ({$issueInList})"
        );
        $orygRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt FROM dok__Dokument
             WHERE dok_Typ = 11 AND dok_NrPelny IN ({$issueInList})
               AND dok_NrPelnyOryg = '{$safeOrderRef}'"
        );
        $zkHeaderRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt
             FROM dok__Dokument zk
             INNER JOIN dok__Dokument wz ON wz.dok_Id = zk.dok_DoDokId AND wz.dok_Typ = 11
             WHERE zk.dok_Id = {$orderId} AND zk.dok_Typ = 16
               AND wz.dok_NrPelny IN ({$issueInList})"
        );
        $wzHeaderRows = MSSql::getInstance()->query(
            "SELECT COUNT(*) AS cnt FROM dok__Dokument
             WHERE dok_Typ = 11 AND dok_NrPelny IN ({$issueInList})
               AND dok_DoDokId = {$orderId}"
        );

        return array(
            'position_links' => is_array($linkedRows) && !empty($linkedRows)
                ? (int) ($linkedRows[0]['cnt'] ?? 0) : 0,
            'wz_oryg' => is_array($orygRows) && !empty($orygRows)
                ? (int) ($orygRows[0]['cnt'] ?? 0) : 0,
            'zk_header' => is_array($zkHeaderRows) && !empty($zkHeaderRows)
                ? (int) ($zkHeaderRows[0]['cnt'] ?? 0) : 0,
            'wz_header' => is_array($wzHeaderRows) && !empty($wzHeaderRows)
                ? (int) ($wzHeaderRows[0]['cnt'] ?? 0) : 0,
            'prices_synced' => $pricesSynced,
        );
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param string $orderRef
     * @param array<int, string> $issueRefs
     * @return array{position_links:int, wz_header:int, zk_header:int, ob_powiazane:int, ob_dok_han:int}
     */
    public static function unlinkIssueFromOrder($subiektGt, $orderId, $orderRef, array $issueRefs)
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

        $subiektGt = self::resolveSubiektGt($subiektGt);
        if (!$subiektGt) {
            return $stats;
        }

        foreach ($issueRefs as $issueRef) {
            $issueRef = trim((string) $issueRef);
            if ($issueRef === '') {
                continue;
            }

            $issueDoc = self::loadDocument($subiektGt, $issueRef);
            if (!$issueDoc) {
                continue;
            }

            $changed = false;
            for ($j = 1; $j <= $issueDoc->Pozycje->Liczba(); $j++) {
                $wzPos = $issueDoc->Pozycje->Element($j);
                if (self::trySetProperty(
                    $wzPos,
                    array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                    null
                ) !== false) {
                    $changed = true;
                }
            }

            self::trySetProperty(
                $issueDoc,
                array('NumerPelnyOryginalny', 'NrPelnyOryg', 'NumerOryginalny'),
                ''
            );
            self::trySetProperty(
                $issueDoc,
                array('DokumentZrodlowy', 'DokumentZrodla', 'NaPodstawieDokumentu'),
                null
            );
            self::trySetProperty(
                $issueDoc,
                array('DoDokumentu', 'DokumentDocelowy', 'DokumentDo'),
                null
            );

            if ($changed) {
                self::saveDocument($issueDoc, 'unlinkIssueFromOrder:' . $issueRef);
            }
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
        if ($loaded !== null) {
            $orderDoc = $loaded['doc'];
            self::trySetProperty(
                $orderDoc,
                array(
                    'DokumentDocelowy',
                    'DokumentDo',
                    'PowiazanyDokument',
                    'RealizacjaDokumentu',
                    'DokumentRealizacji',
                ),
                null
            );
            self::saveDocument($orderDoc, 'unlinkIssueFromOrder:zk:' . $orderRef);
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
     * @param mixed $subiektGt
     * @param string $issueRef
     * @return bool
     */
    public static function withdrawIssueDocument($subiektGt, $issueRef)
    {
        $issueRef = trim((string) $issueRef);
        if ($issueRef === '') {
            return false;
        }

        $issueDoc = self::loadDocument($subiektGt, $issueRef);
        if (!$issueDoc) {
            return false;
        }

        try {
            if (method_exists($issueDoc, 'Anuluj')) {
                $issueDoc->Anuluj();
                return true;
            }
        } catch (\Exception $e) {
        }

        try {
            if (method_exists($issueDoc, 'Usun')) {
                $issueDoc->Usun(false);
                return true;
            }
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'withdrawIssueDocument ' . $issueRef . ': ' . $e->getMessage(),
                __CLASS__ . '::withdrawIssueDocument',
                __LINE__
            );
        }

        return false;
    }

    /**
     * @param mixed $subiektGt
     * @param int $orderId
     * @param string|null $orderRef
     * @param bool|null $enableReservation
     * @return array
     */
    public static function syncStockReservationsForOrder($subiektGt, $orderId, $orderRef = null, $enableReservation = null)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return array('state' => 'noop', 'count' => 0);
        }

        $loaded = self::loadOrderDocument($subiektGt, $orderId, $orderRef);
        if ($loaded === null) {
            return array('state' => 'error', 'count' => 0, 'message' => 'Nie wczytano ZK w COM.');
        }

        $row = Order::getOrderRowByIdSql($orderId);
        $state = $row !== null ? (int) ($row['dok_Status'] ?? 0) : 0;
        if (!Order::isOrderStatusOpen($state)) {
            try {
                $loaded['doc']->Rezerwacja = false;
                self::saveDocument($loaded['doc'], 'syncStockReservations:close');
            } catch (\Exception $e) {
            }

            return array(
                'state' => 'closed_order',
                'count' => 0,
                'message' => 'ZK zamknięte — zwolniono rezerwację COM.',
            );
        }

        if ($enableReservation === null) {
            $enableReservation = Order::orderHasActiveReservationSql($orderId, $loaded['ref'])
                || in_array($state, array(5, 7), true);
        }

        try {
            $loaded['doc']->Rezerwacja = (bool) $enableReservation;
            self::saveDocument($loaded['doc'], 'syncStockReservations:' . $loaded['ref']);
        } catch (\Exception $e) {
            return array(
                'state' => 'error',
                'count' => 0,
                'message' => $e->getMessage(),
            );
        }

        return array(
            'state' => 'success',
            'count' => 1,
            'reservation' => (bool) $enableReservation,
            'message' => 'Zsynchronizowano rezerwację COM dla ZK.',
        );
    }

    /**
     * @param mixed $subiektGt
     * @param int $warehouseId
     * @param bool $dryRun
     * @param array<int, string>|null $productSymbols
     * @return array
     */
    public static function syncStockReservationsFromOrders($subiektGt, $warehouseId = 1, $dryRun = true, array $productSymbols = null)
    {
        $mismatches = Order::findStockReservationMismatchesSql($warehouseId, $productSymbols);
        if ($dryRun || empty($mismatches)) {
            return array(
                'state' => $dryRun ? 'preview' : 'noop',
                'dry_run' => (bool) $dryRun,
                'warehouse_id' => (int) $warehouseId,
                'count' => count($mismatches),
                'items' => $mismatches,
                'message' => $dryRun
                    ? 'Podgląd — nic nie zmieniono. Użyj apply, aby zsynchronizować rezerwacje przez COM.'
                    : (empty($mismatches) ? 'Brak rozjazdów rezerwacji.' : 'Brak zmian.'),
            );
        }

        $subiektGt = self::resolveSubiektGt($subiektGt);
        if (!$subiektGt) {
            return array(
                'state' => 'error',
                'message' => 'Brak połączenia COM — nie można zsynchronizować rezerwacji.',
            );
        }

        $fixed = 0;
        $orderRefs = array();
        $rows = MSSql::getInstance()->query(
            "SELECT DISTINCT d.dok_Id, d.dok_NrPelny, d.dok_Status
             FROM dok__Dokument d
             INNER JOIN dok_Pozycja p ON p.ob_DokHanId = d.dok_Id
             INNER JOIN tw__Towar t ON t.tw_Id = p.ob_TowId
             WHERE d.dok_Typ = 16 AND d.dok_Status IN (5, 7) AND d.dok_Status >= 0"
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $ref = trim((string) ($row['dok_NrPelny'] ?? ''));
                if ($ref !== '') {
                    $orderRefs[$ref] = (int) ($row['dok_Id'] ?? 0);
                }
            }
        }

        foreach ($orderRefs as $ref => $orderId) {
            $result = self::syncStockReservationsForOrder($subiektGt, $orderId, $ref, true);
            if (($result['state'] ?? '') === 'success') {
                $fixed++;
            }
        }

        $after = Order::findStockReservationMismatchesSql($warehouseId, $productSymbols);

        return array(
            'state' => 'success',
            'dry_run' => false,
            'warehouse_id' => (int) $warehouseId,
            'fixed_count' => $fixed,
            'remaining_count' => count($after),
            'remaining_items' => $after,
            'message' => 'Zsynchronizowano rezerwacje COM dla ZK status 7/5 (otwarte z rezerwacją).',
        );
    }

    /**
     * @param mixed $subiektGt
     * @param string $invoiceRef
     * @param bool $apply
     * @return array
     */
    public static function prepareSalesInvoiceForCorrection($subiektGt, $invoiceRef, $apply = false)
    {
        $diag = Order::diagnoseSalesInvoiceForCorrectionSql($invoiceRef);
        if (($diag['state'] ?? '') !== 'success') {
            return $diag;
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
                'message' => 'Podgląd — nic nie zostało zmienione.',
            ));
        }

        $subiektGt = self::resolveSubiektGt($subiektGt);
        if (!$subiektGt) {
            return array(
                'state' => 'error',
                'invoice_ref' => $invoiceRef,
                'message' => 'Brak połączenia COM.',
            );
        }

        $invoiceRefResolved = (string) ($diag['invoice_ref'] ?? $invoiceRef);
        $fsDoc = self::loadDocument($subiektGt, $invoiceRefResolved);
        if ($fsDoc) {
            for ($i = 1; $i <= $fsDoc->Pozycje->Liczba(); $i++) {
                $pos = $fsDoc->Pozycje->Element($i);
                self::trySetProperty(
                    $pos,
                    array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                    null
                );
            }
            self::saveDocument($fsDoc, 'prepareSalesInvoiceForCorrection:fs');
        }

        if (!empty($diag['wz']) && is_array($diag['wz'])) {
            foreach ($diag['wz'] as $wzRow) {
                $wzRef = trim((string) ($wzRow['dok_NrPelny'] ?? ''));
                if ($wzRef === '') {
                    continue;
                }
                $wzDoc = self::loadDocument($subiektGt, $wzRef);
                if (!$wzDoc) {
                    continue;
                }
                self::trySetProperty(
                    $wzDoc,
                    array('NumerPelnyOryginalny', 'NrPelnyOryg', 'NumerOryginalny'),
                    ''
                );
                for ($j = 1; $j <= $wzDoc->Pozycje->Liczba(); $j++) {
                    $pos = $wzDoc->Pozycje->Element($j);
                    self::trySetProperty(
                        $pos,
                        array('DoId', 'ObDoId', 'PozycjaZrodlowaId', 'IdPozycjiZrodlowej'),
                        null
                    );
                }
                self::saveDocument($wzDoc, 'prepareSalesInvoiceForCorrection:wz:' . $wzRef);
            }
        }

        $after = Order::diagnoseSalesInvoiceForCorrectionSql($invoiceRefResolved);

        return array(
            'state' => 'success',
            'invoice_ref' => $invoiceRefResolved,
            'applied' => true,
            'changed' => $preview,
            'after' => $after,
            'message' => 'Naprawiono powiązania pod korektę KFS przez COM.',
        );
    }

    /**
     * @param mixed $subiektGt
     * @param string $orderRef
     * @param array<int, string> $issueRefs
     * @return array
     */
    public static function prepareIssueForInvoicing($subiektGt, $orderRef, array $issueRefs = array())
    {
        $orderRef = trim((string) $orderRef);
        $zkRow = Order::getOrderRowByRefSql($orderRef);
        if ($zkRow === null) {
            return array('order_ref' => $orderRef, 'state' => 'error', 'message' => 'Nie znaleziono ZK');
        }

        $orderId = (int) ($zkRow['dok_Id'] ?? 0);
        $stateBefore = (int) ($zkRow['dok_Status'] ?? 0);
        $hadReservation = in_array($stateBefore, array(5, 7), true);

        if (empty($issueRefs)) {
            $issueRefs = Order::getIssueRefsForOrder($orderRef, $orderId);
        }
        $issueRefs = array_values(array_unique(array_filter(array_map('trim', $issueRefs))));

        if (empty($issueRefs)) {
            return array(
                'order_ref' => $orderRef,
                'state' => 'error',
                'message' => 'Brak WZ powiązanych z tym ZK',
            );
        }

        $subiektGt = self::resolveSubiektGt($subiektGt);
        if (!$subiektGt) {
            return array(
                'order_ref' => $orderRef,
                'state' => 'error',
                'message' => 'Brak połączenia COM.',
            );
        }

        $results = array();
        foreach ($issueRefs as $issueRef) {
            $wzRow = Order::getIssueDocumentRowByRef($issueRef);
            if ($wzRow === null) {
                $results[] = array(
                    'issue_ref' => $issueRef,
                    'wz_id' => 0,
                    'error' => 'Nie znaleziono WZ w bazie.',
                );
                continue;
            }

            $wzId = (int) ($wzRow['dok_Id'] ?? 0);
            $safeIssueRef = str_replace("'", "''", $issueRef);
            $issueDoc = self::loadDocument($subiektGt, $issueRef);
            if ($issueDoc) {
                self::trySetProperty(
                    $issueDoc,
                    array('DoDokumentu', 'DokumentDocelowy', 'DokumentDo'),
                    null
                );
                self::saveDocument($issueDoc, 'prepareIssueForInvoicing:wz');
            }

            MSSql::withSqlWriteFallback(function () use ($orderId, $issueRef, $wzId, $safeIssueRef) {
                Order::clearWzDoDokIdPointingToOrderSql($orderId, array($issueRef));
                MSSql::getInstance()->query(
                    "UPDATE dok__Dokument
                     SET dok_DoDokId = NULL,
                         dok_DoDokNrPelny = '',
                         dok_DoDokDataWyst = NULL
                     WHERE dok_Id = {$orderId} AND dok_Typ = 16
                       AND (dok_DoDokId = {$wzId}
                            OR LTRIM(RTRIM(ISNULL(dok_DoDokNrPelny, ''))) = '{$safeIssueRef}')"
                );
            });

            $results[] = array(
                'issue_ref' => $issueRef,
                'wz_id' => (int) ($wzRow['dok_Id'] ?? 0),
                'zk_header_cleared' => true,
                'wz_dok_dok_id_cleared' => true,
            );
        }

        self::reopenOrderStatus($subiektGt, $orderId, $orderRef, $hadReservation);
        self::applyOrderPartialRealizationStatus($subiektGt, $orderId, $orderRef);

        return array(
            'order_ref' => $orderRef,
            'state' => 'success',
            'issues' => $results,
            'message' => 'Przygotowano WZ do fakturowania przez COM.',
        );
    }
}
