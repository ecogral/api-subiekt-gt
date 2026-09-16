<?php

namespace APISubiektGT;

use APISubiektGT\SubiektGT\OrderComWriter;

/**
 * Pełne uwagi ZK w rozszerzonym polu własnym (zakładka Własne w Subiekcie).
 * Definicje w pw_Pole (obiekt: Zamówienie od klienta, gto_Id = -8).
 */
class DocumentCustomField
{
    const ZK_OBJECT_TYPE = -8;
    const TEXT_CHUNK_MAX = 255;

    /**
     * @param Config|null $cfg
     * @return bool
     */
    public static function isEnabled($cfg = null)
    {
        if ($cfg instanceof Config) {
            return $cfg->useCommentsCustomField();
        }
        if (defined('CONFIG_INI_FILE') && is_readable(CONFIG_INI_FILE)) {
            $ini = @parse_ini_file(CONFIG_INI_FILE);
            if (is_array($ini)) {
                if (isset($ini['use_comments_custom_field']) && (string) $ini['use_comments_custom_field'] === '0') {
                    return false;
                }

                return isset($ini['comments_custom_field_name'])
                    && trim((string) $ini['comments_custom_field_name']) !== '';
            }
        }

        return false;
    }

    /**
     * @param Config|null $cfg
     * @return string
     */
    public static function getFieldPrefix($cfg = null)
    {
        if ($cfg instanceof Config) {
            return $cfg->getCommentsCustomFieldName();
        }
        if (defined('CONFIG_INI_FILE') && is_readable(CONFIG_INI_FILE)) {
            $ini = @parse_ini_file(CONFIG_INI_FILE);
            if (is_array($ini) && isset($ini['comments_custom_field_name'])) {
                return trim((string) $ini['comments_custom_field_name']);
            }
        }

        return '';
    }

    /**
     * @param Config|null $cfg
     * @return array<int, array{pwp_Pole:string, pwp_Nazwa:string, pwp_NazwaUtf8:string}>
     */
    public static function resolveTextFields($cfg = null)
    {
        $prefix = self::getFieldPrefix($cfg);
        if ($prefix === '' || !self::isEnabled($cfg)) {
            return array();
        }

        // Nazwy w pw_Pole są w Windows-1250 — porównanie w PHP (UTF-8), nie w SQL (UTF-8 vs CP1250 się rozjeżdża).
        $prefixNorm = mb_strtolower(trim(Helper::toUtf8(Helper::toWin($prefix))), 'UTF-8');

        $rows = MSSql::getInstance()->query(
            'SELECT pwp_Pole, pwp_Nazwa
             FROM pw_Pole
             WHERE pwp_TypObiektu = ' . self::ZK_OBJECT_TYPE . '
               AND pwp_Typ = 3
             ORDER BY pwp_Nazwa'
        );

        if (!is_array($rows)) {
            return array();
        }

        $matched = array();
        foreach ($rows as $row) {
            $column = trim((string) ($row['pwp_Pole'] ?? ''));
            if ($column === '' || !preg_match('/^pwd_Tekst\d{2}$/', $column)) {
                continue;
            }

            $nameUtf8 = trim(Helper::toUtf8((string) ($row['pwp_Nazwa'] ?? '')));
            $nameNorm = mb_strtolower($nameUtf8, 'UTF-8');
            if ($nameNorm !== $prefixNorm && strpos($nameNorm, $prefixNorm . ' ') !== 0) {
                continue;
            }

            $matched[] = array(
                'pwp_Pole' => $column,
                'pwp_Nazwa' => (string) ($row['pwp_Nazwa'] ?? ''),
                'pwp_NazwaUtf8' => $nameUtf8,
            );
        }

        usort($matched, function ($a, $b) {
            return strcmp($a['pwp_NazwaUtf8'], $b['pwp_NazwaUtf8']);
        });

        return $matched;
    }

    /**
     * @param string $commentsWin
     * @param int $maxChunks
     * @return array<int, string>
     */
    public static function splitWinChunks($commentsWin, $maxChunks)
    {
        $commentsWin = (string) $commentsWin;
        $maxChunks = max(0, (int) $maxChunks);
        if ($commentsWin === '' || $maxChunks <= 0) {
            return array();
        }

        $chunks = array();
        $offset = 0;
        $len = strlen($commentsWin);
        while ($offset < $len && count($chunks) < $maxChunks) {
            $chunks[] = substr($commentsWin, $offset, self::TEXT_CHUNK_MAX);
            $offset += self::TEXT_CHUNK_MAX;
        }

        return $chunks;
    }

    /**
     * Ustawia pola własne na obiekcie COM przed Zapisz().
     *
     * @param mixed $orderGt
     * @param Config|null $cfg
     * @param string $commentsWin
     * @return bool
     */
    public static function applyToComOrderIfEnabled($orderGt, $cfg, $commentsWin)
    {
        if (!$orderGt || !self::isEnabled($cfg)) {
            return false;
        }

        $fields = self::resolveTextFields($cfg);
        if (empty($fields)) {
            return false;
        }

        $chunks = self::splitWinChunks($commentsWin, count($fields));
        $valuesByName = array();
        foreach ($fields as $idx => $field) {
            $key = isset($field['pwp_NazwaUtf8'])
                ? $field['pwp_NazwaUtf8']
                : Helper::toUtf8((string) $field['pwp_Nazwa']);
            $valuesByName[$key] = isset($chunks[$idx]) ? $chunks[$idx] : '';
        }

        try {
            $pola = OrderComWriter::tryGetProperty($orderGt, array('PolaWlasne', 'PolaWlasneRozszerzone'));
            if (!$pola) {
                return false;
            }

            $count = (int) OrderComWriter::tryGetProperty($pola, array('Liczba'), 0);
            if ($count <= 0) {
                return false;
            }

            $setAny = false;
            for ($i = 1; $i <= $count; $i++) {
                if (!method_exists($pola, 'Element')) {
                    break;
                }
                $element = $pola->Element($i);
                if (!$element) {
                    continue;
                }

                $nameWin = (string) OrderComWriter::tryGetProperty($element, array('Nazwa'), '');
                $nameUtf = Helper::toUtf8($nameWin);
                if ($nameUtf === '' || !array_key_exists($nameUtf, $valuesByName)) {
                    continue;
                }

                if (OrderComWriter::trySetProperty(
                    $element,
                    array('Wartosc', 'Wartość', 'Value', 'Tekst'),
                    $valuesByName[$nameUtf]
                ) !== false) {
                    $setAny = true;
                }
            }

            return $setAny;
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api',
                'DocumentCustomField COM: ' . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );

            return false;
        }
    }

    /**
     * Po Zapisz() — SQL utrwala pw_Dane (COM bywa niespójny).
     *
     * @param Config|null $cfg
     * @param int $docId
     * @param string $commentsWin
     * @return void
     */
    public static function syncToSqlIfEnabled($cfg, $docId, $commentsWin)
    {
        $docId = (int) $docId;
        if ($docId <= 0 || !self::isEnabled($cfg)) {
            return;
        }

        $fields = self::resolveTextFields($cfg);
        if (empty($fields)) {
            Logger::getInstance()->log(
                'api',
                'DocumentCustomField: brak pól pw_Pole dla prefiksu "' . self::getFieldPrefix($cfg) . '" (ZK) — zapis pominięty',
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );

            return;
        }

        $chunks = self::splitWinChunks($commentsWin, count($fields));
        $setParts = array();
        foreach ($fields as $idx => $field) {
            $value = isset($chunks[$idx]) ? $chunks[$idx] : '';
            $safeValue = str_replace("'", "''", $value);
            $setParts[] = $field['pwp_Pole'] . " = '{$safeValue}'";
        }

        try {
            MSSql::withSqlWriteFallback(function () use ($docId, $setParts, $fields, $chunks) {
                // Subiekt trzyma pola dokumentu z pwd_IdPozycji = NULL (nie 0).
                $existing = MSSql::getInstance()->query(
                    'SELECT pwd_Id FROM pw_Dane
                     WHERE pwd_TypObiektu = ' . self::ZK_OBJECT_TYPE . "
                       AND pwd_IdObiektu = {$docId}
                       AND (pwd_IdPozycji IS NULL OR pwd_IdPozycji = 0)"
                );

                if (!empty($existing[0]['pwd_Id'])) {
                    $pwdId = (int) $existing[0]['pwd_Id'];
                    MSSql::getInstance()->query(
                        'UPDATE pw_Dane SET ' . implode(', ', $setParts) . " WHERE pwd_Id = {$pwdId}"
                    );

                    return;
                }

                // pwd_Id nie jest IDENTITY — trzeba nadać kolejny numer.
                $next = MSSql::getInstance()->query('SELECT ISNULL(MAX(pwd_Id), 0) + 1 AS next_id FROM pw_Dane');
                $pwdId = !empty($next[0]['next_id']) ? (int) $next[0]['next_id'] : 1;

                $columns = array('pwd_Id', 'pwd_TypObiektu', 'pwd_IdObiektu', 'pwd_IdPozycji');
                $values = array((string) $pwdId, (string) self::ZK_OBJECT_TYPE, (string) $docId, 'NULL');
                foreach ($fields as $idx => $field) {
                    $columns[] = $field['pwp_Pole'];
                    $chunk = isset($chunks[$idx]) ? $chunks[$idx] : '';
                    $values[] = "'" . str_replace("'", "''", $chunk) . "'";
                }

                MSSql::getInstance()->query(
                    'INSERT INTO pw_Dane (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')'
                );
            });
        } catch (\Exception $e) {
            Logger::getInstance()->log(
                'api_error',
                'DocumentCustomField SQL ERROR docId=' . $docId . ': ' . $e->getMessage(),
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );

            return;
        }

        $preview = Helper::toUtf8(substr((string) $commentsWin, 0, 80));
        Logger::getInstance()->log(
            'api',
            'DocumentCustomField: zapisano pole własne ZK docId=' . $docId
                . ', pól=' . count($fields)
                . ', len=' . strlen((string) $commentsWin)
                . ', preview=' . $preview,
            __CLASS__ . '->' . __FUNCTION__,
            __LINE__
        );

        $totalLen = strlen((string) $commentsWin);
        $capacity = count($fields) * self::TEXT_CHUNK_MAX;
        if ($totalLen > $capacity) {
            Logger::getInstance()->log(
                'api',
                'DocumentCustomField: uwagi obcięte do ' . $capacity . ' znaków ('
                    . count($fields) . ' pól × ' . self::TEXT_CHUNK_MAX . '), docId=' . $docId,
                __CLASS__ . '->' . __FUNCTION__,
                __LINE__
            );
        }
    }
}
