<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Compra extends Model
{
    use HasFactory;

    protected $fillable = [
        'tipoDocumento',
        'serie',
        'numero',
        'idProveedor',
        'idCliente',
        'numeroFile',
        'formaPago',
        'fechaEmision',
        'moneda',
        'subTotal',
        'inafecto',
        'igv',
        'montoDetraccion',
        'numero_constancia_detraccion', // <-- NUEVO
        'fecha_pago_detraccion',        // <-- NUEVO
        'idCuentaContable',             // <-- FALTABA EN EDICIÓN
        'idSubdiario',                  // <-- FALTABA EN EDICIÓN
        'total',
        'totalLetras',
        'observacion',
        'pdf_path',
        'estado',
        'usuarioCreacion',
        'usuarioModificacion',
        'idCuentaContable',
        'idSubdiario',
    ];

    // **AÑADE ESTA LÍNEA** o modifica si ya tienes la propiedad $casts
    protected $casts = [
        'fechaEmision' => 'datetime',
        'fechaVencimiento' => 'date',
        'fecha_pago_detraccion' => 'date',
    ];

    // Relaciones
    public function tipoDocumentoR()
    {
        return $this->belongsTo(TipoDocumento::class, 'tipoDocumento');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'idProveedor');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'idCliente');
    }

    public function estadoR()
    {
        return $this->belongsTo(Estado::class, 'estado');
    }

    public function usuarioCreador()
    {
        return $this->belongsTo(User::class, 'usuarioCreacion');
    }

    public function usuarioModificador()
    {
        return $this->belongsTo(User::class, 'usuarioModificacion');
    }
    public function detalles()
    {
        return $this->hasMany(CompraDetalle::class, 'idCompra');
    }
    public function cuentaContable()
    {
        return $this->belongsTo(CuentaContable::class, 'idCuentaContable');
    }
    public function subdiario()
    {
        return $this->belongsTo(Subdiario::class, 'idSubdiario');
    }
}
