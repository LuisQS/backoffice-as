<?php

namespace App\Http\Livewire\Compras;

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\TipoDocumento;
use App\Models\Proveedor;
use App\Models\Cliente;
use App\Models\Estado;
use App\Models\CuentaContable;   // <-- NUEVO
use App\Models\Subdiario;        // <-- NUEVO
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EditCompra extends Component
{
    use WithFileUploads;

    public $compra;

    // Propiedades para los campos de la tabla 'compras'
    public $tipoDocumento;
    public $serie;
    public $numero;
    public $idProveedor;
    public $idCliente;
    public $numeroFile;
    public $formaPago;
    public $fechaEmision;
    public $moneda;
    public $subTotal = 0;
    public $inafecto = 0;
    public $igv = 0;
    public $total = 0;
    public $totalLetras;
    public $observacion;
    public $pdf_path_existente; 
    public $pdfFile;
    
    // <-- NUEVO: Propiedades para los campos contables y de detracción
    public $idCuentaContable;
    public $idSubdiario;
    public $numero_constancia_detraccion;
    public $fecha_pago_detraccion;

    public $detalles = [];
    public $tasaIGV;

    public $currentDetalle = [
        'id'            => null,
        'cantidad'      => 1,
        'unidadMedida'  => '',
        'descripcion'   => '',
        'valorUnitario' => 0,
        'afectoIgv'     => true,
        'estado'        => 1,
    ];
    public $editingDetalleIndex = null;
    public $showDetalleModal = false;

    public $tiposDocumento;
    public $proveedores;
    public $clientes;
    public $estados;
    public $formasPago = [
        'Efectivo' => 'Efectivo',
        'Transferencia' => 'Transferencia',
        'Crédito' => 'Crédito',
        'Débito' => 'Débito',
    ];
    public $monedas = [
        'PEN' => 'Soles (PEN)',
        'USD' => 'Dólares (USD)',
    ];

    // <-- NUEVO: Listas para los selects de cuentas y subdiarios
    public $cuentasContables;
    public $subdiarios;

    protected $listeners = [
        'detalle-updated' => 'updateDetalleFromChild',
        'show-detalle-modal',
        'hide-detalle-modal'
    ];

    protected $rules = [
        'tipoDocumento'    => 'required|exists:tipo_documentos,id',
        'serie'            => 'required|string|max:10',
        'numero'           => 'required|string|max:20',
        'idProveedor'      => 'required|exists:proveedors,id',
        'idCliente'        => 'nullable|exists:clientes,id',
        'numeroFile'       => 'nullable|string|max:50',
        'formaPago'        => 'required|string|in:Efectivo,Transferencia,Crédito,Débito',
        'fechaEmision'     => 'required|date',
        'moneda'           => 'required|string|in:PEN,USD',
        'observacion'      => 'nullable|string|max:500',
        'pdfFile'          => 'nullable|mimes:pdf|max:5120',
        // <-- NUEVO: Reglas de validación para los nuevos campos
        'idCuentaContable' => 'nullable|exists:cuenta_contables,id',
        'idSubdiario'      => 'nullable|exists:subdiarios,id',
        'numero_constancia_detraccion' => 'nullable|string|max:50',
        'fecha_pago_detraccion' => 'nullable|date',
    ];

    protected $currentDetalleRules = [
        'currentDetalle.cantidad'      => 'required|numeric|min:1',
        'currentDetalle.unidadMedida'  => 'required|string|max:50',
        'currentDetalle.descripcion'   => 'required|string|max:255',
        'currentDetalle.valorUnitario' => 'required|numeric|min:0',
    ];

    protected $messages = [
        'tipoDocumento.required' => 'El tipo de documento es obligatorio.',
        'idProveedor.required'   => 'El proveedor es obligatorio.',
        'currentDetalle.cantidad.required' => 'La cantidad del detalle es obligatoria.',
        'currentDetalle.unidadMedida.required' => 'La unidad de medida es obligatoria.',
        'currentDetalle.descripcion.required' => 'La descripción del detalle es obligatoria.',
        'currentDetalle.valorUnitario.required' => 'El valor unitario es obligatorio.',
        'pdfFile.mimes' => 'El archivo debe ser de formato PDF.',
        'pdfFile.max'   => 'El tamaño máximo permitido para el PDF es 5MB.',
    ];

    public function mount($compra)
    {
        $this->tasaIGV = config('taxes.igv_rate');

        $compra = Compra::findOrFail($compra);
        $this->compra = $compra;

        $this->tipoDocumento = $compra->tipoDocumento;
        $this->serie = $compra->serie;
        $this->numero = $compra->numero;
        $this->idProveedor = $compra->idProveedor;
        $this->idCliente = $compra->idCliente;
        $this->numeroFile = $compra->numeroFile;
        $this->formaPago = $compra->formaPago;
        $this->fechaEmision = $compra->fechaEmision ? $compra->fechaEmision->format('Y-m-d') : null;
        $this->moneda = $compra->moneda;
        $this->subTotal = $compra->subTotal;
        $this->inafecto = $compra->inafecto;
        $this->igv = $compra->igv;
        $this->total = $compra->total;
        $this->totalLetras = $compra->totalLetras;
        $this->observacion = $compra->observacion;
        $this->pdf_path_existente = $compra->pdf_path;

        // <-- NUEVO: Cargar los valores de los nuevos campos
        $this->idCuentaContable = $compra->idCuentaContable;
        $this->idSubdiario = $compra->idSubdiario;
        $this->numero_constancia_detraccion = $compra->numero_constancia_detraccion;
        $this->fecha_pago_detraccion = $compra->fecha_pago_detraccion ? $compra->fecha_pago_detraccion->format('Y-m-d') : null;

        $this->detalles = $compra->detalles->map(function($detalle) {
            return $detalle->toArray();
        })->toArray();

        $this->tiposDocumento = TipoDocumento::all();
        $this->proveedores = Proveedor::all();
        $this->clientes = Cliente::orderBy('razonSocial')->get();
        $this->estados = Estado::all();

        // <-- NUEVO: Cargar listas para los selects de cuentas y subdiarios (asumiendo idEstado = 1 para Activo)
        $this->cuentasContables = CuentaContable::where('idEstado', 1)->orderBy('cuenta')->get();
        $this->subdiarios = Subdiario::where('idEstado', 1)->orderBy('codigo')->get();

        $this->calculateTotals();
    }

    public function calculateTotals()
    {
        $this->subTotal = 0;
        $this->igv = 0;
        $this->inafecto = 0;
        $this->total = 0;
        
        foreach ($this->detalles as $index => $detalle) {
            $cantidad = floatval($detalle['cantidad'] ?? 0);
            $valorUnitario = floatval($detalle['valorUnitario'] ?? 0);
            $afectoIGV = (bool)($detalle['afectoIgv'] ?? true);
            
            $subtotalLinea = $cantidad * $valorUnitario;

            if ($afectoIGV) {
                $this->subTotal += $subtotalLinea;
                $this->igv += $subtotalLinea * $this->tasaIGV;
            } else {
                $this->inafecto += $subtotalLinea;
            }
        }

        $this->total = $this->subTotal + $this->inafecto + $this->igv;

        $this->subTotal = round($this->subTotal, 2);
        $this->igv = round($this->igv, 2);
        $this->inafecto = round($this->inafecto, 2);
        $this->total = round($this->total, 2);
        $this->totalLetras = "Son " . number_format($this->total, 2) . " " . ($this->monedas[$this->moneda] ?? '') . " y 00/100";
    }

    public function openAddDetalleModal()
    {
        $this->currentDetalle = [
            'id'            => null,
            'cantidad'      => 1,
            'unidadMedida'  => '',
            'descripcion'   => '',
            'valorUnitario' => 0,
            'afectoIgv'     => true,
            'estado'        => 1,
        ];
        $this->editingDetalleIndex = null;
        $this->resetErrorBag('currentDetalle.*');
        $this->showDetalleModal = true;
        $this->emit('show-detalle-modal');
    }

    public function openEditDetalleModal($index)
    {
        $this->currentDetalle = $this->detalles[$index];
        $this->editingDetalleIndex = $index;
        $this->resetErrorBag('currentDetalle.*');
        $this->showDetalleModal = true;
        $this->emit('show-detalle-modal');
    }

    public function closeDetalleModal()
    {
        $this->showDetalleModal = false;
        $this->reset(['currentDetalle', 'editingDetalleIndex']);
        $this->emit('hide-detalle-modal');
    }

    public function saveOrUpdateDetalle()
    {
        $this->validate($this->currentDetalleRules);

        if ($this->editingDetalleIndex !== null) {
            $this->detalles[$this->editingDetalleIndex] = $this->currentDetalle;
        } else {
            $this->detalles[] = $this->currentDetalle;
        }

        $this->calculateTotals();
        $this->closeDetalleModal();
    }

    public function removeDetalle($index)
    {
        unset($this->detalles[$index]);
        $this->detalles = array_values($this->detalles);
        $this->calculateTotals();
    }

    public function updateDetalleFromChild($updatedItem, $index)
    {
        if (isset($this->detalles[$index])) {
            $this->detalles[$index] = $updatedItem;
            $this->calculateTotals();
        }
    }

    public function updated($propertyName)
    {
        if (in_array($propertyName, array_keys($this->rules))) {
            $this->validateOnly($propertyName);
        }

        if ($propertyName === 'moneda') {
            $this->calculateTotals();
        }

        if (str_starts_with($propertyName, 'currentDetalle.')) {
            $this->validateOnly($propertyName, $this->currentDetalleRules);
        }
    }

    public function updateCompra()
    {
        if (empty($this->detalles)) {
            session()->flash('error', 'Debe agregar al menos un detalle de compra para poder guardar.');
            $this->emit('no-detalles-error');
            return;
        }

        $this->validate();

        DB::beginTransaction();

        try {
            $pdfPath = $this->pdf_path_existente;

            if ($this->pdfFile) {
                if ($this->pdf_path_existente && Storage::disk('public')->exists($this->pdf_path_existente)) {
                    Storage::disk('public')->delete($this->pdf_path_existente);
                }
                $pdfPath = $this->pdfFile->store('compras_pdfs', 'public');
            } else if ($this->pdf_path_existente === null && $this->compra->pdf_path) {
                $pdfPath = null;
            }
            
            $this->compra->update([
                'tipoDocumento'      => $this->tipoDocumento,
                'serie'              => $this->serie,
                'numero'             => $this->numero,
                'idProveedor'        => $this->idProveedor,
                'idCliente'          => $this->idCliente,
                'numeroFile'         => $this->numeroFile,
                'formaPago'          => $this->formaPago,
                'fechaEmision'       => $this->fechaEmision,
                'moneda'             => $this->moneda,
                'subTotal'           => $this->subTotal,
                'inafecto'           => $this->inafecto,
                'igv'                => $this->igv,
                'total'              => $this->total,
                'totalLetras'        => $this->totalLetras,
                'observacion'        => $this->observacion,
                'pdf_path'           => $pdfPath,
                'usuarioModificacion' => Auth::id(),
                // <-- NUEVO: Guardar los nuevos campos en la base de datos
                'idCuentaContable'   => $this->idCuentaContable,
                'idSubdiario'        => $this->idSubdiario,
                'numero_constancia_detraccion' => $this->numero_constancia_detraccion,
                'fecha_pago_detraccion' => $this->fecha_pago_detraccion,
            ]);

            $existingDetailIds = $this->compra->detalles->pluck('id')->toArray();
            $submittedDetailIds = collect($this->detalles)->whereNotNull('id')->pluck('id')->toArray();

            $detailsToDelete = array_diff($existingDetailIds, $submittedDetailIds);
            if (!empty($detailsToDelete)) {
                CompraDetalle::whereIn('id', $detailsToDelete)->delete();
            }

            foreach ($this->detalles as $detalleData) {
                if (isset($detalleData['id'])) {
                    $detalle = CompraDetalle::find($detalleData['id']);
                    if ($detalle) {
                        $detalle->update([
                            'cantidad'      => $detalleData['cantidad'],
                            'unidadMedida'  => $detalleData['unidadMedida'],
                            'descripcion'   => $detalleData['descripcion'],
                            'valorUnitario' => $detalleData['valorUnitario'],
                            'afectoIgv'     => $detalleData['afectoIgv'],
                            'estado'        => $detalleData['estado'] ?? 1,
                        ]);
                    }
                } else {
                    $this->compra->detalles()->create([
                        'cantidad'      => $detalleData['cantidad'],
                        'unidadMedida'  => $detalleData['unidadMedida'],
                        'descripcion'   => $detalleData['descripcion'],
                        'valorUnitario' => $detalleData['valorUnitario'],
                        'afectoIgv'     => $detalleData['afectoIgv'],
                        'estado'        => $detalleData['estado'] ?? 1,
                    ]);
                }
            }

            DB::commit();

            session()->flash('message', '¡Compra actualizada exitosamente!');
            return redirect()->route('listaCompras');

        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Hubo un error al actualizar la compra: ' . $e->getMessage());
            Log::error('Error al actualizar compra: ' . $e->getMessage());
        }
    }

    public function removeExistingPdf()
    {
        if ($this->pdf_path_existente && Storage::disk('public')->exists($this->pdf_path_existente)) {
            Storage::disk('public')->delete($this->pdf_path_existente);
            $this->pdf_path_existente = null;
            $this->pdfFile = null;
            
            // <-- CORRECCIÓN: Usar $this->compra->id en lugar de $this->compraId
            if ($this->compra && $this->compra->id) {
                $compra = Compra::find($this->compra->id);
                if ($compra) {
                    $compra->pdf_path = null;
                    $compra->save();
                }
            }
            session()->flash('message', 'PDF eliminado correctamente.');
        }
    }

    public function render()
    {
        return view('livewire.compras.edit-compra', [
            'tiposDocumento'   => $this->tiposDocumento,
            'proveedores'      => $this->proveedores,
            'clientes'         => $this->clientes,
            'estados'          => $this->estados,
            'cuentasContables' => $this->cuentasContables, // <-- NUEVO
            'subdiarios'       => $this->subdiarios,        // <-- NUEVO
        ]);
    }
}