<?php
namespace LeadIntelligence\Qualification;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Leitor e Parser universal de arquivos CSV e XLSX
 * Sem dependências de bibliotecas externas pesadas.
 */
class SpreadsheetParser {

    /**
     * Extrai cabeçalhos e amostra inicial para o assistente de mapeamento
     *
     * @param string $file_path
     * @param int $preview_rows
     * @return array ['headers' => [...], 'rows' => [...], 'total_rows' => int, 'format' => 'csv'|'xlsx']
     */
    public static function preview($file_path, $preview_rows = 5) {
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            return self::preview_csv($file_path, $preview_rows);
        } elseif ($ext === 'xlsx') {
            return self::preview_xlsx($file_path, $preview_rows);
        }

        throw new \Exception('Formato de arquivo não suportado. Por favor, utilize CSV ou XLSX.');
    }

    /**
     * Itera sobre todas as linhas do arquivo chamando o callback para cada linha
     *
     * @param string $file_path
     * @param callable $callback function(array $row_data, int $row_index)
     */
    public static function parse_all($file_path, $callback) {
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            self::parse_all_csv($file_path, $callback);
        } elseif ($ext === 'xlsx') {
            self::parse_all_xlsx($file_path, $callback);
        } else {
            throw new \Exception('Formato de arquivo não suportado.');
        }
    }

    /**
     * Detecta o delimitador CSV mais provável (, ; ou \t)
     */
    private static function detect_csv_delimiter($file_path) {
        $handle = fopen($file_path, 'r');
        if (!$handle) return ',';

        $first_line = fgets($handle);
        fclose($handle);

        $delimiters = [',', ';', "\t"];
        $best_delimiter = ',';
        $max_count = 0;

        foreach ($delimiters as $d) {
            $count = substr_count($first_line, $d);
            if ($count > $max_count) {
                $max_count = $count;
                $best_delimiter = $d;
            }
        }

        return $best_delimiter;
    }

    /**
     * Preview CSV
     */
    private static function preview_csv($file_path, $max_rows = 5) {
        $delimiter = self::detect_csv_delimiter($file_path);
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new \Exception('Não foi possível abrir o arquivo CSV.');
        }

        $headers = [];
        $rows = [];
        $line_count = 0;

        while (($data = fgetcsv($handle, 8192, $delimiter)) !== false) {
            // Remove BOM de UTF-8 se presente no primeiro campo
            if ($line_count === 0 && !empty($data[0])) {
                $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
            }

            // Converte encoding se necessário
            $data = array_map([__CLASS__, 'ensure_utf8'], $data);

            if ($line_count === 0) {
                $headers = array_map('trim', $data);
            } else {
                if (count($rows) < $max_rows) {
                    $rows[] = $data;
                }
            }
            $line_count++;
        }

        fclose($handle);

        return [
            'headers'    => $headers,
            'rows'       => $rows,
            'total_rows' => max(0, $line_count - 1),
            'format'     => 'csv',
        ];
    }

    /**
     * Processa todas as linhas CSV
     */
    private static function parse_all_csv($file_path, $callback) {
        $delimiter = self::detect_csv_delimiter($file_path);
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new \Exception('Não foi possível abrir o arquivo CSV.');
        }

        $headers = [];
        $row_index = 0;

        while (($data = fgetcsv($handle, 8192, $delimiter)) !== false) {
            if ($row_index === 0 && !empty($data[0])) {
                $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
            }

            $data = array_map([__CLASS__, 'ensure_utf8'], $data);

            if ($row_index === 0) {
                $headers = array_map('trim', $data);
            } else {
                $row_assoc = [];
                foreach ($headers as $idx => $header_name) {
                    $row_assoc[$header_name] = isset($data[$idx]) ? trim($data[$idx]) : '';
                }
                call_user_func($callback, $row_assoc, $row_index);
            }
            $row_index++;
        }

        fclose($handle);
    }

    /**
     * Preview de planilha XLSX
     */
    private static function preview_xlsx($file_path, $max_rows = 5) {
        $all_rows = self::read_xlsx_rows($file_path, $max_rows + 1);

        if (empty($all_rows)) {
            throw new \Exception('A planilha XLSX está vazia ou não pôde ser lida.');
        }

        $headers = array_shift($all_rows);
        $total_estimate = self::count_xlsx_rows($file_path);

        return [
            'headers'    => $headers,
            'rows'       => $all_rows,
            'total_rows' => max(0, $total_estimate - 1),
            'format'     => 'xlsx',
        ];
    }

    /**
     * Processa todas as linhas de planilha XLSX
     */
    private static function parse_all_xlsx($file_path, $callback) {
        $rows = self::read_xlsx_rows($file_path, 0); // 0 = sem limite

        if (empty($rows)) {
            return;
        }

        $headers = array_shift($rows);
        $row_index = 1;

        foreach ($rows as $row_data) {
            $row_assoc = [];
            foreach ($headers as $idx => $header_name) {
                $row_assoc[$header_name] = isset($row_data[$idx]) ? trim($row_data[$idx]) : '';
            }
            call_user_func($callback, $row_assoc, $row_index);
            $row_index++;
        }
    }

    /**
     * Leitor leve de XLSX via ZipArchive e XML
     */
    private static function read_xlsx_rows($file_path, $limit = 0) {
        if (!class_exists('\ZipArchive')) {
            throw new \Exception('A extensão PHP ZipArchive não está ativa neste servidor.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($file_path) !== true) {
            throw new \Exception('Falha ao descompactar arquivo XLSX.');
        }

        // 1. Carrega Shared Strings (textos compartilhados)
        $shared_strings = [];
        $strings_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($strings_xml !== false) {
            $xml = @simplexml_load_string($strings_xml);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $shared_strings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                        $shared_strings[] = $text;
                    } else {
                        $shared_strings[] = '';
                    }
                }
            }
        }

        // 2. Carrega primeira planilha (sheet1.xml)
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet_xml === false) {
            $zip->close();
            throw new \Exception('Primeira aba (sheet1.xml) não encontrada no XLSX.');
        }

        $xml_sheet = @simplexml_load_string($sheet_xml);
        $zip->close();

        if (!$xml_sheet || !isset($xml_sheet->sheetData->row)) {
            return [];
        }

        $result = [];
        $count = 0;

        foreach ($xml_sheet->sheetData->row as $row) {
            $row_cells = [];
            $max_col_index = 0;

            foreach ($row->c as $cell) {
                $ref = (string) $cell['r']; // Ex: A1, B2, C15
                $col_letters = preg_replace('/\d/', '', $ref);
                $col_idx = self::col_letter_to_index($col_letters);

                $val = '';
                $type = (string) $cell['t'];

                if ($type === 's') {
                    // String compartilhada
                    $idx = (int) $cell->v;
                    $val = isset($shared_strings[$idx]) ? $shared_strings[$idx] : '';
                } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                    $val = (string) $cell->is->t;
                } elseif (isset($cell->v)) {
                    $val = (string) $cell->v;
                }

                $row_cells[$col_idx] = $val;
                if ($col_idx > $max_col_index) {
                    $max_col_index = $col_idx;
                }
            }

            // Preenche lacunas de colunas vazias
            $normalized_row = [];
            for ($i = 0; $i <= $max_col_index; $i++) {
                $normalized_row[] = isset($row_cells[$i]) ? $row_cells[$i] : '';
            }

            $result[] = $normalized_row;
            $count++;

            if ($limit > 0 && $count >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Conta linhas no sheet1.xml
     */
    private static function count_xlsx_rows($file_path) {
        $zip = new \ZipArchive();
        if ($zip->open($file_path) !== true) return 0;
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet_xml === false) return 0;
        return substr_count($sheet_xml, '<row ');
    }

    /**
     * Converte letra da coluna para índice numérico base 0 (A->0, B->1, Z->25, AA->26)
     */
    private static function col_letter_to_index($letters) {
        $letters = strtoupper($letters);
        $num = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $num = $num * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $num - 1;
    }

    /**
     * Garante que a string esteja em UTF-8
     */
    public static function ensure_utf8($str) {
        if (!is_string($str)) return $str;
        if (mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }
        return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1, Windows-1252');
    }
}
