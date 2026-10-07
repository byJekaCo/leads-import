<?php

declare(strict_types=1);

final class LeadImporter
{
    private const COLUMNS = [
        'external_id',
        'created_at',
        'first_name',
        'last_name',
        'phone',
        'email',
        'city',
        'source',
        'utm_campaign',
        'product',
        'budget_uah',
        'status',
        'manager',
        'comment',
        'next_contact_at',
    ];

    private const DATE_COLUMNS = [
        'created_at' => true,
        'next_contact_at' => true,
    ];

    private const BATCH = 2000;

    private const TEXT_NOT_NULL = [
        'external_id' => true,
        'first_name' => true,
        'last_name' => true,
    ];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return array{rows:int,seconds:float,db_count:int}
     */
    public function import(string $xlsxPath): array
    {
        $started = microtime(true);
        if (!is_file($xlsxPath)) {
            throw new RuntimeException('Файл не знайдено.');
        }

        $strings = $this->sharedStrings($xlsxPath);
        [$tempDir, $sheet] = $this->extractSheet($xlsxPath);

        $this->db->query('TRUNCATE TABLE applications');
        $this->db->query('SET unique_checks=0');
        $this->db->query('SET foreign_key_checks=0');
        $this->db->begin_transaction();

        $rows = 0;
        try {
            $rows = $this->writeRows($sheet, $strings);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        } finally {
            $this->db->query('SET unique_checks=1');
            $this->db->query('SET foreign_key_checks=1');
            $this->removeTree($tempDir);
        }

        $count = (int) $this->db->query('SELECT COUNT(*) FROM applications')->fetch_row()[0];
        if ($count !== $rows) {
            throw new RuntimeException("У базі {$count} рядків, у файлі {$rows}.");
        }

        return [
            'rows' => $rows,
            'seconds' => microtime(true) - $started,
            'db_count' => $count,
        ];
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(string $xlsxPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            throw new RuntimeException('Не вдалося відкрити xlsx.');
        }
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        if ($xml === false) {
            return [];
        }

        $strings = [];
        $reader = XMLReader::XML($xml);
        unset($xml);
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }
            $text = '';
            if (!$reader->isEmptyElement) {
                $depth = $reader->depth;
                while ($reader->read()) {
                    if (
                        $reader->nodeType === XMLReader::END_ELEMENT
                        && $reader->localName === 'si'
                        && $reader->depth === $depth
                    ) {
                        break;
                    }
                    if (
                        $reader->nodeType === XMLReader::ELEMENT
                        && $reader->localName === 't'
                        && !$reader->isEmptyElement
                    ) {
                        $reader->read();
                        if ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                            $text .= $reader->value;
                        }
                    }
                }
            }
            $strings[] = $text;
        }
        $reader->close();

        return $strings;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function extractSheet(string $xlsxPath): array
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'leads_import_' . bin2hex(random_bytes(4));
        if (!mkdir($dir) && !is_dir($dir)) {
            throw new RuntimeException('Не вдалося створити тимчасовий каталог.');
        }

        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            throw new RuntimeException('Не вдалося відкрити xlsx.');
        }
        if (!$zip->extractTo($dir, 'xl/worksheets/sheet1.xml')) {
            $zip->close();
            throw new RuntimeException('У файлі немає аркуша sheet1.');
        }
        $zip->close();

        $path = $dir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'worksheets' . DIRECTORY_SEPARATOR . 'sheet1.xml';
        if (!is_file($path)) {
            throw new RuntimeException('Не вдалося прочитати аркуш.');
        }

        return [$dir, $path];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param list<string> $strings
     */
    private function writeRows(string $sheetPath, array $strings): int
    {
        $fh = fopen($sheetPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Не вдалося прочитати аркуш.');
        }

        $headerMap = null;
        $batch = [];
        $written = 0;
        $carry = '';

        while (!feof($fh)) {
            $data = $carry . fread($fh, 1048576);
            $last = strrpos($data, '</row>');
            if ($last === false) {
                $carry = $data;
                continue;
            }
            $block = substr($data, 0, $last + 6);
            $carry = substr($data, $last + 6);
            $offset = 0;
            while (($rowPos = strpos($block, '<row ', $offset)) !== false) {
                $gt = strpos($block, '>', $rowPos);
                $end = strpos($block, '</row>', $gt);
                $inner = substr($block, $gt + 1, $end - $gt - 1);
                $cells = $this->parseCells($inner, $strings);
                $offset = $end + 6;

                if ($headerMap === null) {
                    $headerMap = $this->mapHeader($cells);
                    continue;
                }

                $batch[] = $this->tuple($cells, $headerMap);
                if (count($batch) >= self::BATCH) {
                    $this->flush($batch);
                    $written += count($batch);
                    $batch = [];
                }
            }
        }
        fclose($fh);

        if ($headerMap === null) {
            throw new RuntimeException('У файлі немає рядка заголовків.');
        }
        if ($batch !== []) {
            $this->flush($batch);
            $written += count($batch);
        }

        return $written;
    }

    /**
     * @param list<string> $strings
     * @return array<int, string|null>
     */
    private function parseCells(string $inner, array $strings): array
    {
        $cells = [];
        $offset = 0;
        $length = strlen($inner);
        while (($pos = strpos($inner, '<c ', $offset)) !== false) {
            $gt = strpos($inner, '>', $pos);
            if ($gt === false) {
                break;
            }
            $tag = substr($inner, $pos, $gt - $pos);
            $col = $this->columnIndex($tag);
            if (substr(rtrim($tag), -1) === '/') {
                $cells[$col] = null;
                $offset = $gt + 1;
                continue;
            }
            $end = strpos($inner, '</c>', $gt);
            if ($end === false) {
                break;
            }
            $body = substr($inner, $gt + 1, $end - $gt - 1);
            $value = null;
            $vpos = strpos($body, '<v>');
            if ($vpos !== false) {
                $vend = strpos($body, '</v>', $vpos);
                $value = substr($body, $vpos + 3, $vend - $vpos - 3);
                if (strpos($tag, 't="s"') !== false) {
                    $value = $strings[(int) $value] ?? '';
                }
            }
            $cells[$col] = $value;
            $offset = $end + 4;
            if ($offset > $length) {
                break;
            }
        }

        return $cells;
    }

    private function columnIndex(string $tag): int
    {
        $rpos = strpos($tag, 'r="');
        if ($rpos === false) {
            return 0;
        }
        $n = 0;
        $len = strlen($tag);
        for ($i = $rpos + 3; $i < $len; $i++) {
            $ch = $tag[$i];
            if ($ch < 'A' || $ch > 'Z') {
                break;
            }
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /**
     * @param array<int, string|null> $cells
     * @return array<string, int>
     */
    private function mapHeader(array $cells): array
    {
        $map = [];
        foreach ($cells as $index => $name) {
            $key = strtolower(trim((string) $name));
            if ($key !== '') {
                $map[$key] = $index;
            }
        }
        foreach (self::COLUMNS as $column) {
            if (!isset($map[$column])) {
                throw new RuntimeException('У файлі немає колонки ' . $column . '.');
            }
        }

        return $map;
    }

    /**
     * @param array<int, string|null> $cells
     * @param array<string, int> $headerMap
     */
    private function tuple(array $cells, array $headerMap): string
    {
        $values = [];
        foreach (self::COLUMNS as $column) {
            $raw = $cells[$headerMap[$column]] ?? null;
            if ($raw !== null) {
                $raw = trim($raw);
                if ($raw === '') {
                    $raw = null;
                }
            }
            $values[] = $this->sqlValue($column, $raw);
        }

        return '(' . implode(',', $values) . ')';
    }

    private function sqlValue(string $column, ?string $raw): string
    {
        if ($raw === null) {
            return isset(self::TEXT_NOT_NULL[$column]) ? "''" : 'NULL';
        }
        if (isset(self::DATE_COLUMNS[$column])) {
            $formatted = self::excelDate($raw);
            if ($formatted === null) {
                return 'NULL';
            }

            return "'" . $formatted . "'";
        }
        if ($column === 'budget_uah') {
            if (!preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
                return 'NULL';
            }

            return $raw;
        }
        if ($column === 'phone' && preg_match('/^\d+\.0+$/', $raw)) {
            $raw = preg_replace('/\.0+$/', '', $raw) ?? $raw;
        }

        return "'" . $this->db->real_escape_string($raw) . "'";
    }

    private static function excelDate(string $raw): ?string
    {
        $dot = strpos($raw, '.');
        if ($dot === false) {
            if (!ctype_digit($raw)) {
                return null;
            }
            $days = (int) $raw;
            $seconds = 0;
        } else {
            $whole = substr($raw, 0, $dot);
            if ($whole === '' || !ctype_digit($whole)) {
                return null;
            }
            $days = (int) $whole;
            $seconds = (int) round((float) ('0' . substr($raw, $dot)) * 86400);
            if ($seconds >= 86400) {
                $days++;
                $seconds = 0;
            }
        }
        if ($days <= 0) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', ($days - 25569) * 86400 + $seconds);
    }

    /**
     * @param list<string> $batch
     */
    private function flush(array $batch): void
    {
        $sql = 'INSERT INTO applications (' . implode(',', self::COLUMNS) . ') VALUES ' . implode(',', $batch);
        $this->db->query($sql);
    }
}
