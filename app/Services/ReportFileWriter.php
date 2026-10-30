<?php

namespace App\Services;

use App\Models\ExportLog;
use App\Support\ReportValue;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportFileWriter
{
    public function write(ExportLog $export, array $report, string $path): void
    {
        Storage::disk('local')->makeDirectory('report-exports');
        if ($export->format === 'pdf') {
            $this->pdf($report, $path);
        } else {
            $this->xlsx($report, $path);
        }
    }

    private function pdf(array $report, string $path): void
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('chroot', base_path('vendor/dompdf/dompdf/lib/fonts'));
        $options->set('fontCache', storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('portal.reports.pdf', ['report' => $report])->render(), 'UTF-8');
        $pdf->setPaper(count($report['columns']) > 10 ? 'a3' : 'a4', 'landscape');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text(28, $canvas->get_height() - 18, '{PAGE_NUM} / {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8);
        Storage::disk('local')->put($path, $pdf->output());
    }

    private function xlsx(array $report, string $path): void
    {
        $book = new Spreadsheet;
        try {
            $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
            $summary = $book->getActiveSheet()->setTitle('Tong hop');
            $this->cell($summary, 1, 1, $report['title'], 'text');
            $summary->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $this->cell($summary, 1, 2, $report['filters']['from'].' - '.$report['filters']['until'], 'text');
            $this->cell($summary, 1, 3, $report['period_note'], 'text');
            $summary->getStyle('A3')->getAlignment()->setWrapText(true);
            $summary->mergeCells('A3:H3')->getRowDimension(3)->setRowHeight(42);
            $this->cell($summary, 1, 4, 'Dữ liệu lúc: '.$report['as_of'].' ('.config('app.timezone').')', 'text');
            $this->cell($summary, 1, 5, implode(' · ', $report['filter_labels']), 'text');
            $summary->mergeCells('A5:H5')->getRowDimension(5)->setRowHeight(42);
            $summary->getStyle('A5')->getAlignment()->setWrapText(true);
            $line = 7;
            foreach ($report['summary'] as $metric) {
                $this->cell($summary, 1, $line, $metric['label'], 'text');
                $this->cell($summary, 2, $line++, $metric['value'], $metric['format']);
            }
            $line += 2;
            $groupColumns = ['name' => 'Nhóm'];
            if ($report['type'] === 'finance') {
                $groupColumns['category_label'] = 'Loại / trạng thái';
            }
            $groupColumns += $report['group_columns'];
            $this->header($summary, $line++, array_values($groupColumns));
            foreach ($report['groups'] as $group) {
                $column = 1;
                foreach ($groupColumns as $key => $label) {
                    $format = in_array($key, ['name', 'category_label']) ? 'text' : ($report['type'] === 'finance' ? 'money' : 'number');
                    $this->cell($summary, $column++, $line, $group[$key] ?? null, $format);
                }
                $line++;
            }
            $summary->getColumnDimension('A')->setWidth(36);
            foreach (range('B', 'H') as $column) {
                $summary->getColumnDimension($column)->setWidth(24);
            }
            $details = $book->createSheet()->setTitle('Chi tiet');
            $columns = $report['columns'];
            $this->header($details, 1, array_column($columns, 'label'));
            $line = 2;
            foreach ($report['rows'] as $row) {
                $column = 1;
                foreach ($columns as $key => $definition) {
                    $value = $key === 'category' ? $row->category_label : ($row->$key ?? null);
                    $this->cell($details, $column++, $line, $value, $definition['format']);
                }
                $line++;
            }
            foreach (array_keys($columns) as $index => $key) {
                $details->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth(in_array($key, ['code', 'shop', 'reference']) ? 34 : 24);
            }
            $last = Coordinate::stringFromColumnIndex(count($columns));
            $details->freezePane('A2')->setAutoFilter('A1:'.$last.max(1, $line - 1));
            $details->getStyle('A1:'.$last.max(1, $line - 1))->getAlignment()->setVertical('top');
            $book->setActiveSheetIndex(0);
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save(Storage::disk('local')->path($path));
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function cell($sheet, int $column, int $row, mixed $value, string $format): void
    {
        $address = Coordinate::stringFromColumnIndex($column).$row;
        if ($value === null) {
            $sheet->setCellValue($address, null);
        } elseif (in_array($format, ['money', 'number', 'percent'])) {
            $number = $format === 'percent' ? $value / 100 : ReportValue::integer($value);
            // Excel chi giu chinh xac 15 chu so: tong lon hon luu chuoi, dong tien van la so.
            $sheet->setCellValueExplicit($address, $number, abs($number) > 999999999999999 ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
            $sheet->getStyle($address)->getNumberFormat()->setFormatCode($format === 'percent' ? '0.0%' : '#,##0');
        } else {
            // Chuoi bat dau bang =, +, -, @ van la van ban, khong bao gio thanh cong thuc Excel.
            $sheet->setCellValueExplicit($address, ReportValue::display($value, $format), DataType::TYPE_STRING);
        }
    }

    private function header($sheet, int $row, array $labels): void
    {
        foreach ($labels as $index => $label) {
            $this->cell($sheet, $index + 1, $row, $label, 'text');
        }
        $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex(count($labels)).$row;
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('087C70');
        $sheet->getStyle($range)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(34);
    }
}
