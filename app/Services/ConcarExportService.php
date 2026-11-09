<?php

namespace App\Services;

use App\Models\Compra;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ConcarExportService
{
    private $spreadsheet;
    private $sheet;
    private $row = 5; // Empezamos en la fila 5 (1: Títulos, 2: Desc, 3: Tipo, 4: Oculta)
    private $comprobanteCounter = 1;

    // Cuentas contables fijas según el sistema
    const CUENTA_IGV = '401111';
    const CUENTA_POR_PAGAR = '421202';
    const CUENTA_DETRACCION = '421203';
    const CUENTA_HONORARIOS = '414102';

    // Códigos de tipo de documento estándar
    const TIPO_DOC_MAP = [
        '01' => 'FT', 
        '03' => 'BK', 
        '07' => 'NC', 
        '08' => 'ND', 
        'RH' => 'RH', 
    ];

    public function generate(array $comprasIds, string $fechaInicio, string $fechaFin): string
    {
        // 1. Cargar la plantilla existente desde la carpeta public
        $this->spreadsheet = IOFactory::load(public_path('plantilla.xlsx'));
        $this->sheet = $this->spreadsheet->getActiveSheet();

        // Obtener compras con sus relaciones
        $compras = Compra::with(['proveedor', 'cuentaContable', 'subdiario'])
            ->whereIn('id', $comprasIds)
            ->whereBetween('fechaEmision', [$fechaInicio, $fechaFin])
            ->orderBy('fechaEmision', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Procesar cada compra
        foreach ($compras as $compra) {
            $this->processCompra($compra);
        }

        // Guardar archivo
        $filename = 'concar_compras_' . Carbon::parse($fechaInicio)->format('Ymd') . '_' . 
                    Carbon::parse($fechaFin)->format('Ymd') . '_' . time() . '.xlsx';
        $filepath = storage_path('app/public/' . $filename);

        // Usar IOFactory para crear el writer y guardar
        $writer = IOFactory::createWriter($this->spreadsheet, 'Xlsx');
        $writer->save($filepath);

        return $filename;
    }

    private function processCompra(Compra $compra): void
    {
        $subdiario = $compra->subdiario;
        $cuentaGasto = $compra->cuentaContable;
        $proveedor = $compra->proveedor;
        
        if (!$subdiario || !$cuentaGasto || !$proveedor) {
            return; 
        }

        $mes = Carbon::parse($compra->fechaEmision)->format('m');
        $numeroComprobante = $mes . str_pad($this->comprobanteCounter++, 4, '0', STR_PAD_LEFT);
        $fechaComprobante = Carbon::parse($compra->fechaEmision)->format('d/m/Y');
        $moneda = $compra->moneda === 'USD' ? 'US' : 'SO';
        $glosa = strtoupper($proveedor->razonSocial) . '-' . ($compra->tipoDocumento ?? 'FT') . '-' . 
                 ($compra->serie ?? '') . '-' . ($compra->numero ?? '');
        $tipoCambio = $compra->tipoCambio ?? 0;
        $tipoConversion = 'V'; 
        $flagConversion = 'S';
        $fechaTipoCambio = $compra->tipoCambio > 0 ? $fechaComprobante : '';
        $ruc = $proveedor->numeroDocumentoIdentidad ?? '';
        
        $tipoDoc = self::TIPO_DOC_MAP[$compra->tipoDocumento ?? '01'] ?? 'FT';
        
        $numeroDoc = ($compra->serie ?? '') . '-' . ($compra->numero ?? '');
        $fechaDoc = Carbon::parse($compra->fechaEmision)->format('d/m/Y');
        $fechaVencimiento = Carbon::parse($compra->fechaVencimiento ?? $compra->fechaEmision)->format('d/m/Y');
        $glosaDetalle = $glosa;

        $tieneIgv = $compra->igv > 0;
        $tieneDetraccion = ($compra->montoDetraccion ?? 0) > 0;
        $esReciboHonorarios = ($compra->tipoDocumentoIdentidad ?? '') === 'RH' || 
                              str_contains(strtoupper($compra->tipoDocumento ?? ''), 'RH');

        if ($esReciboHonorarios) {
            $this->addReciboHonorarios(
                $subdiario->codigo, $numeroComprobante, $fechaComprobante, $moneda, $glosa,
                $tipoCambio, $tipoConversion, $flagConversion, $fechaTipoCambio,
                $cuentaGasto->cuenta, $ruc, $tipoDoc, $numeroDoc, $fechaDoc, $fechaVencimiento,
                $glosaDetalle, $compra->total
            );
        } elseif ($tieneIgv && $tieneDetraccion) {
            $this->addFacturaConDetraccion(
                $subdiario->codigo, $numeroComprobante, $fechaComprobante, $moneda, $glosa,
                $tipoCambio, $tipoConversion, $flagConversion, $fechaTipoCambio,
                $cuentaGasto->cuenta, $ruc, $tipoDoc, $numeroDoc, $fechaDoc, $fechaVencimiento,
                $glosaDetalle, $compra->subTotal, $compra->igv, $compra->total, $compra->montoDetraccion
            );
        } elseif ($tieneIgv) {
            $this->addFacturaSimple(
                $subdiario->codigo, $numeroComprobante, $fechaComprobante, $moneda, $glosa,
                $tipoCambio, $tipoConversion, $flagConversion, $fechaTipoCambio,
                $cuentaGasto->cuenta, $ruc, $tipoDoc, $numeroDoc, $fechaDoc, $fechaVencimiento,
                $glosaDetalle, $compra->subTotal, $compra->igv, $compra->total
            );
        } else {
            $this->addFacturaSinIgv(
                $subdiario->codigo, $numeroComprobante, $fechaComprobante, $moneda, $glosa,
                $tipoCambio, $tipoConversion, $flagConversion, $fechaTipoCambio,
                $cuentaGasto->cuenta, $ruc, $tipoDoc, $numeroDoc, $fechaDoc, $fechaVencimiento,
                $glosaDetalle, $compra->total
            );
        }
    }

    private function addFacturaSimple($subDiario, $numComp, $fechaComp, $moneda, $glosa, 
        $tipoCambio, $tipoConv, $flagConv, $fechaTC, $cuentaGasto, $ruc, $tipoDoc, $numDoc, 
        $fechaDoc, $fechaVenc, $glosaDetalle, $subtotal, $igv, $total): void
    {
        // Línea 1: Gasto en el DEBE
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, $cuentaGasto, $ruc, '100', 'D', $subtotal, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, '', '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);

        // Línea 2: IGV en el DEBE
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, self::CUENTA_IGV, '', '0', 'D', $igv, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, '', '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);

        // Línea 3: Cuenta por Pagar en el HABER
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, self::CUENTA_POR_PAGAR, $ruc, '0', 'H', $total, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, $fechaVenc, '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);
    }

    private function addFacturaConDetraccion($subDiario, $numComp, $fechaComp, $moneda, $glosa,
        $tipoCambio, $tipoConv, $flagConv, $fechaTC, $cuentaGasto, $ruc, $tipoDoc, $numDoc,
        $fechaDoc, $fechaVenc, $glosaDetalle, $subtotal, $igv, $total, $detraccion): void
    {
        // Línea 1: Gasto en el DEBE
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, $cuentaGasto, $ruc, '100', 'D', $subtotal, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, '', '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);

        // Línea 2: IGV en el DEBE
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, self::CUENTA_IGV, '', '0', 'D', $igv, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, '', '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);

        // Línea 3: Cuenta por Pagar en el HABER (total)
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, self::CUENTA_POR_PAGAR, $ruc, '0', 'H', $total, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, $fechaVenc, '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);

        // Línea 4: Ajuste de Detracción en el DEBE
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, self::CUENTA_POR_PAGAR, $ruc, '0', 'D', $detraccion, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, '', '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);

        // Línea 5: Cuenta de Detracción por Pagar en el HABER
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, self::CUENTA_DETRACCION, $ruc, '0', 'H', $detraccion, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, $fechaVenc, '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);
    }

    private function addFacturaSinIgv($subDiario, $numComp, $fechaComp, $moneda, $glosa,
        $tipoCambio, $tipoConv, $flagConv, $fechaTC, $cuentaGasto, $ruc, $tipoDoc, $numDoc,
        $fechaDoc, $fechaVenc, $glosaDetalle, $total): void
    {
        // Línea 1: Gasto en el DEBE
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, $cuentaGasto, $ruc, '100', 'D', $total, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, '', '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);

        // Línea 2: Cuenta por Pagar en el HABER
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, self::CUENTA_POR_PAGAR, $ruc, '0', 'H', $total, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, $fechaVenc, '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);
    }

    private function addReciboHonorarios($subDiario, $numComp, $fechaComp, $moneda, $glosa,
        $tipoCambio, $tipoConv, $flagConv, $fechaTC, $cuentaGasto, $ruc, $tipoDoc, $numDoc,
        $fechaDoc, $fechaVenc, $glosaDetalle, $total): void
    {
        // Línea 1: Gasto en el DEBE
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, $cuentaGasto, $ruc, '100', 'D', $total, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, '', '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);

        // Línea 2: Honorarios por Pagar en el HABER
        $this->addRow([
            $subDiario, $numComp, $fechaComp, $moneda, $glosa, $tipoCambio, $tipoConv, $flagConv,
            $fechaTC, self::CUENTA_HONORARIOS, $ruc, '0', 'H', $total, 0, 0,
            $tipoDoc, $numDoc, $fechaDoc, '', '', $glosaDetalle, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
        ]);
    }

    private function addRow(array $data): void
    {
        // Empezamos en la Columna B (índice 2), ya que la A es para instrucciones
        $colIndex = 2; 

        foreach ($data as $value) {
            // Convertimos el índice numérico a letra (2 -> 'B', 3 -> 'C', etc.)
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $this->sheet->setCellValue($colLetter . $this->row, $value);
            $colIndex++;
        }

        // Calculamos la última columna usada para aplicar estilos
        $lastColIndex = 2 + count($data) - 1;
        $lastColLetter = Coordinate::stringFromColumnIndex($lastColIndex);

        // Estilo de fila (Bordes y fuente)
        $this->sheet->getStyle('B' . $this->row . ':' . $lastColLetter . $this->row)->applyFromArray([
            'font' => ['size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
        ]);

        // Alinear montos a la derecha (Columnas N, O, P -> Importe Original, Dólares, Soles)
        $this->sheet->getStyle('N' . $this->row . ':P' . $this->row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $this->sheet->getStyle('N' . $this->row . ':P' . $this->row)->getNumberFormat()->setFormatCode('#,##0.00');

        $this->row++;
    }
}