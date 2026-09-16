<?php

namespace APISubiektGT;

use APISubiektGT\SubiektGT\OrderComWriter;

/**
 * Uwagi dokumentu GT: dok_Uwagi (500) + opcjonalnie dok_UwagiExt (3500).
 * Włączane flagą use_comments_ext w config/api-subiekt-gt.ini.
 */
class DocumentComments
{
    const PRIMARY_MAX = 500;
    const EXT_MAX = 3500;

    /**
     * @param Config|null $cfg
     * @return bool
     */
    public static function isExtEnabled($cfg = null)
    {
        if ($cfg instanceof Config) {
            return $cfg->useCommentsExt();
        }
        if (defined('CONFIG_INI_FILE') && is_readable(CONFIG_INI_FILE)) {
            $ini = @parse_ini_file(CONFIG_INI_FILE);
            if (is_array($ini)) {
                return isset($ini['use_comments_ext']) && (string) $ini['use_comments_ext'] === '1';
            }
        }

        return false;
    }

    /**
     * @param string $commentsWin ISO-8859-2
     * @return array{primary:string, ext:string}
     */
    public static function splitWin($commentsWin)
    {
        $commentsWin = (string) $commentsWin;
        if ($commentsWin === '') {
            return array('primary' => '', 'ext' => '');
        }

        if (strlen($commentsWin) <= self::PRIMARY_MAX) {
            return array('primary' => $commentsWin, 'ext' => '');
        }

        return array(
            'primary' => substr($commentsWin, 0, self::PRIMARY_MAX),
            'ext' => substr($commentsWin, self::PRIMARY_MAX, self::EXT_MAX),
        );
    }

    /**
     * @param string $primaryWin
     * @param string $extWin
     * @return string
     */
    public static function mergeWin($primaryWin, $extWin = '')
    {
        return (string) $primaryWin . (string) $extWin;
    }

    /**
     * @param string $commentsWin
     * @return string
     */
    public static function truncatePrimaryWin($commentsWin)
    {
        $commentsWin = (string) $commentsWin;
        if (strlen($commentsWin) <= self::PRIMARY_MAX) {
            return $commentsWin;
        }

        return substr($commentsWin, 0, self::PRIMARY_MAX);
    }

    /**
     * Normalizacja uwag z żądania API (UTF-8) → Windows-1250 do zapisu w GT.
     *
     * @param array $detail
     * @param string $existingWin istniejące uwagi (ISO-8859-2), gdy brak comments w żądaniu
     * @return string
     */
    public static function prepareWinFromDetail(array $detail, $existingWin = '')
    {
        $hadCommentsKey = array_key_exists('comments', $detail);
        $comments = $hadCommentsKey
            ? (string) $detail['comments']
            : Helper::toUtf8((string) $existingWin);

        $lines = preg_split('/\r\n|\r|\n/', $comments);
        $lines = array_filter($lines, function ($line) {
            return stripos(trim($line), 'Nr przesyłki:') !== 0;
        });
        $comments = trim(implode("\n", $lines));

        if (!empty($detail['shipment_number'])) {
            $comments .= "\nNr przesyłki: " . trim((string) $detail['shipment_number']);
        }

        $comments = preg_replace('/(?<!\n)(Adres dostawy:)/u', "\n$1", $comments);
        $comments = preg_replace('/(?<!\n)(Nr przesyłki:)/u', "\n$1", $comments);
        $comments = str_replace(array("\r\n", "\r"), "\n", $comments);
        $comments = str_replace("\n", "\r\n", $comments);

        if ($comments === '' && !$hadCommentsKey) {
            return (string) $existingWin;
        }
        if ($comments === '') {
            return '';
        }

        return Helper::toWin($comments);
    }

    /**
     * Ustawia Uwagi (+ opcjonalnie UwagiExt) na obiekcie COM dokumentu.
     *
     * @param mixed $doc
     * @param string $commentsWin
     * @param bool $useExt
     * @return array{primary:string, ext:string}
     */
    public static function applyToComDocument($doc, $commentsWin, $useExt)
    {
        if (!$doc) {
            return array('primary' => '', 'ext' => '');
        }

        if ($useExt) {
            $parts = self::splitWin($commentsWin);
            $doc->Uwagi = $parts['primary'];
            OrderComWriter::trySetProperty(
                $doc,
                array('UwagiExt', 'RozszerzoneUwagi', 'UwagiRozszerzone'),
                $parts['ext']
            );

            return $parts;
        }

        $primary = self::truncatePrimaryWin($commentsWin);
        $doc->Uwagi = $primary;
        OrderComWriter::trySetProperty(
            $doc,
            array('UwagiExt', 'RozszerzoneUwagi', 'UwagiRozszerzone'),
            ''
        );

        return array('primary' => $primary, 'ext' => '');
    }

    /**
     * Po Zapisz() — SQL uzupełnia dok_Uwagi / dok_UwagiExt (COM bywa niespójny z bazą).
     *
     * @param Config|null $cfg
     * @param int $docId
     * @param string $commentsWin
     * @return void
     */
    public static function syncToSqlIfEnabled($cfg, $docId, $commentsWin)
    {
        $docId = (int) $docId;
        if ($docId <= 0) {
            return;
        }

        $useExt = self::isExtEnabled($cfg);
        if ($useExt) {
            $parts = self::splitWin($commentsWin);
        } else {
            $parts = array(
                'primary' => self::truncatePrimaryWin($commentsWin),
                'ext' => '',
            );
        }

        $safePrimary = str_replace("'", "''", $parts['primary']);
        $safeExt = str_replace("'", "''", $parts['ext']);

        MSSql::withSqlWriteFallback(function () use ($docId, $safePrimary, $safeExt) {
            MSSql::getInstance()->query(
                "UPDATE dok__Dokument
                 SET dok_Uwagi = '{$safePrimary}',
                     dok_UwagiExt = '{$safeExt}'
                 WHERE dok_Id = {$docId}"
            );
        });
    }

    /**
     * Skleja comments w wierszu zapytania (pole comments + opcjonalnie comments_ext).
     *
     * @param array $row
     * @param Config|null $cfg
     * @param string $primaryField
     * @param string $extField
     * @return string UTF-8
     */
    public static function mergeRowToUtf8(array $row, $cfg = null, $primaryField = 'comments', $extField = 'comments_ext')
    {
        $primary = Helper::toUtf8((string) ($row[$primaryField] ?? ''));
        if (!self::isExtEnabled($cfg)) {
            return $primary;
        }

        $ext = Helper::toUtf8((string) ($row[$extField] ?? ''));

        return Helper::toUtf8(self::mergeWin(Helper::toWin($primary), Helper::toWin($ext)));
    }
}
