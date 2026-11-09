<?php

namespace App\Http\Livewire\Gestion;

use Livewire\Component;
use App\Models\Counter;
use App\Models\Boleto;
use App\Models\Aerolinea;
use App\Models\Cliente;
use App\Models\BoletoRuta;
use App\Models\BoletoPago;
use App\Models\TarjetaCredito;
use App\Models\MedioPago;
use App\Models\TipoCambio;
use App\Models\Area;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Clases\Funciones;
use Illuminate\Support\Collection;
use DateTime;
use App\Models\File;
use App\Models\FileDetalle;

class Integrador extends Component
{
    public $idCounter=1,$boleto="",$selectedGds="sabre";
    public $idRegistro,$numeroBoleto,$numeroFile,$fechaEmision,$idCliente,
    $idTipoFacturacion,$idTipoDocumento=6,$idArea=1,$idVendedor,$idConsolidador=2,$codigoReserva,
    $fechaReserva,$idGds=2,$idTipoTicket=1,$tipoRuta="NACIONAL",$tipoTarifa="NORMAL",$idAerolinea=7,
    $origen="BSP",$pasajero,$bookingAgent,
    $idTipoPasajero=1,$ruta,$destino,$idDocumento,$tipoCambio,$idMoneda=2,$tarifaNeta=0,$igv=0,
    $otrosImpuestos=0,$yr=0,$hw=0,$xm=0,$total=0,$totalOrigen=0,$porcentajeComision,$montoComision=0,
    $descuentoCorporativo,$codigoDescCorp,$tarifaNormal,$tarifaAlta,$tarifaBaja,
    $idTipoPagoConsolidador,$centroCosto,$cod1,$cod2,$cod3,$cod4,$observaciones,$estado=1,
    $usuarioCreacion,$fechaCreacion,$usuarioModificacion,$fechaModificacion,$checkFile, $detalleRutas;

    public $ciudadSalida,$ciudadLlegada,$idAerolineaRuta,$vuelo,$clase,$fechaSalida,$horaSalida,$fechaLlegada,
            $horaLlegada,$farebasis, $boletoRutas;

    public $idMedioPago,$idTarjetaCredito,$numeroTarjeta,$monto,$fechaVencimientoTC,$boletoPagos;

    public function mount(){
        $this->boletoRutas = new Collection();
    }
    public function render()
    { 
        $counters = Counter::all()->sortBy('nombre');
        $areas = Area::all()->sortBy('descripcion');
        return view('livewire.gestion.integrador',compact('counters','areas'));
    }

    public function obtenerBoleto(){
        if($this->checkFile and !$this->numeroFile){
            session()->flash('error', 'Debe Ingresar Numero de File.');
            return;
        }
        $lineas = explode("\n",$this->boleto);
        $lineasBoleto = array_values($lineas);
        if ($this->selectedGds == 'sabre') {
            $this->parsearSabre($lineasBoleto);
        }else if($this->selectedGds == 'kiu'){
            $this->parsearKiu($lineasBoleto);
        }
        else if($this->selectedGds == 'ndc'){
            $this->parsearNdc($lineasBoleto);
        }
        else if($this->selectedGds == 'amadeus'){
            $this->parsearAmadeus($lineasBoleto);
        }
    }
    public function parsearSabre($boleto){
        $contador = 0;
        if (count($boleto) < 5) {
            session()->flash('error', 'Formato incorrecto del boleto.');
            return;
        }
        foreach ($boleto as $linea) {
            $contador = $contador + 1;
            //Obtener Pasajero
            $posPasajero = strpos($linea,"NAME:");
            if($posPasajero !== false){
                $this->pasajero = str_replace(" ","",$linea);
                $this->pasajero = str_replace("NAME:","",$this->pasajero);
                $this->pasajero = str_replace("/"," ",$this->pasajero);
            }

            //Obtener Aerolínea y Numero de Boleto
            $posBoleto = strpos($linea,"ETKT");
            if($posBoleto !== false){
                $codigoAerolinea = substr($linea,$posBoleto+10,3);
                $oAerolinea = Aerolinea::where('codigoIata',$codigoAerolinea)->first();
                $this->idAerolinea = $oAerolinea->id;

                $this->numeroBoleto = substr($linea,-10);
                $oBoleto = Boleto::where('numeroBoleto',$this->numeroBoleto)->first();
                if($oBoleto){
                    session()->flash('error', 'El boleto ya está integrado.');
                    return;
                }
            }
            
            //Obtener Código de Reserva
            $posPnr = strpos($linea,"BOOKING REFERENCE:");
            if ($posPnr !== false) {
                $this->codigoReserva = substr($linea,$posPnr+19,6);
            }

            //Obtener Emisor
            $posPnr = strpos($linea,"BOOKING AGENT:");
            if ($posPnr !== false) {
                $this->bookingAgent = substr($linea,$posPnr+15,7);
            }

            //Obtener Fecha de Emision
            $posFechaEmision = strpos($linea,"DATE OF ISSUE:");
            if ($posFechaEmision !== false) {
                $fechaOriginal = substr($linea,$posFechaEmision+15,7);
                $fechaFormat = Carbon::createFromFormat('dMy',$fechaOriginal);
                $this->fechaEmision = $fechaFormat->format('Y-m-d');

                $tc = TipoCambio::where('fechaCambio',$this->fechaEmision)->first();
                if($tc){
                    $this->tipoCambio = $tc->montoCambio;
                }else{
                    $this->tipoCambio = 0.00;
                }
            }
            
            //Obtener Cliente
            $posCliente = strpos($linea,"NAME REF:");
            if ($posCliente !== false) {
                $posRuc = strpos($linea,"RUC");
                if ($posRuc !== false) {
                    $ruc = substr($linea,$posCliente+13,11);
                    $oCliente = Cliente::where('numeroDocumentoIdentidad',$ruc)->first();
                    if ($oCliente) {
                        $this->idCliente = $oCliente->id;
                        $this->idTipoFacturacion = $oCliente->tipoFacturacion;
                        // $this->idArea = $oCliente->area;
                        $this->idVendedor = $oCliente->vendedor;
                    }
                }else{
                    $doc = substr($linea,-8);
                    $oCliente = Cliente::where('numeroDocumentoIdentidad',$doc)->first();
                    if ($oCliente) {
                        $this->idCliente = $oCliente->id;
                        $this->idTipoFacturacion = $oCliente->tipoFacturacion;
                        // $this->idArea = $oCliente->area;
                        $this->idVendedor = $oCliente->vendedor;
                    }
                }
            }

            //Obtener Ruta / Destino
            $posRuta = strpos($linea,"FARE CALC:");
            if ($posRuta !== false) {
                $cadena = Str::remove(range(0,9),$linea);
                $cadena = Str::remove("ROE",$cadena);
                $cadena = Str::remove("USD",$cadena);
                $cadena = Str::remove("END",$cadena);
                // $cadena = Str::remove(".",$cadena);
                $cadena = str_replace("."," ",$cadena);
                
                $cadena = Str::remove("NUC",$cadena);
                $palabras = Str::of($cadena)->explode(' ');
                $palabras3 = $palabras->filter(function($palabra){
                    return Str::length($palabra) == 3;
                });
                foreach ($palabras3 as $word) {
                    $this->ruta = $this->ruta . $word . "/";
                }
                $this->ruta = substr($this->ruta,0,strlen($this->ruta)-1);
                
                $dest = str_replace("/","",$this->ruta);
                $incioCadena = round(((strlen($dest) / 3) / 2),0,PHP_ROUND_HALF_DOWN) * 3;
                $this->destino =  substr($dest, $incioCadena, 3);
            }

            //Obtener Forma de Pago
            $posFpago = strpos($linea,"FORM OF PAYMENT:");
            if ($posFpago !== false){
                if(strpos($linea,"CA")){
                    $oTc = TarjetaCredito::where('codigo','XX')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','009')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"VISA")){
                    $oTc = TarjetaCredito::where('codigo','VI')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"MASTERCARD")){
                    $oTc = TarjetaCredito::where('codigo','MA')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"MASTER CARD")){
                    $oTc = TarjetaCredito::where('codigo','MA')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"DINERS CLUB")){
                    $oTc = TarjetaCredito::where('codigo','DC')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"AMERICAN EXPRESS")){
                    $oTc = TarjetaCredito::where('codigo','AX')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
            }

            // Obtener Tarifas
            $posDy = strpos($linea,"DY");
            if ($posDy !== false) {
                $this->tipoRuta = "INTERNACIONAL";
            }else{
                $this->tipoRuta = "NACIONAL";
            }
            $posTNeta = strpos($linea,"FARE:");
            if ($posTNeta !== false) {
                $neto = substr($linea,$posTNeta+9,7);
                $this->tarifaNeta = $neto;
            }
            $posTax = strpos($linea,"TAX: USD");
            if ($posTax !== false) {
                $tax = substr($linea,$posTax+8,7);
                $this->otrosImpuestos = $tax;
            }
            $posPe = strpos($linea,"PE   ");
            if ($posPe !== false) {
                $pe = substr($linea,$posPe-6,6);
                $this->igv = trim($pe);
            }
            $posYr = strpos($linea,"YR   ");
            if ($posYr !== false) {
                $nyr = substr($linea,$posYr-6,6);
                $this->yr = trim($nyr);
            }
            
            // dd($this->tipoRuta);
        }
        $this->tarifaNeta = $this->tarifaNeta + $this->yr;
        $this->otrosImpuestos = $this->otrosImpuestos - $this->igv - $this->yr;

        // Obtener Rutas detalle
        $patron = '/-{10,}\s*(.*?)\s*ENDORSEMENTS:/s';
        // $patron = '/NAME REF:\s*(.*?)\s*ENDORSEMENTS:/s';

        if (preg_match($patron, $this->boleto, $coincidencias)) {
            $seccion_deseada = trim($coincidencias[1]);
            $this->detalleRutas = $seccion_deseada;
        } 
        // else {
        //     echo "No se encontró la sección deseada.";
        // }

        // Obtener Segmentos
        $anio = date('Y');
        $posFecha2 = 1;
        $posVuelo2 = 1;
        $posClase2 = 1;
        $posFareBasis2 = 1;
        for ($i=0; $i < count($boleto)-1; $i++) { 
            $posFecha = strpos($boleto[$i],"DATE  AIRLINE              FLT    CLASS     FARE BASIS      STATUS");
            $posVuelo = strpos($boleto[$i],"FLT    CLASS     FARE BASIS      STATUS");
            $posClase = strpos($boleto[$i],"CLASS     FARE BASIS      STATUS");
            $posFareBasis = strpos($boleto[$i],"FARE BASIS      STATUS");
            if($posFecha !== false){
                $posFecha2 = $posFecha;
                $posVuelo2 = $posVuelo;
                $posClase2 = $posClase;
                $posFareBasis2 = $posFareBasis;
            }
        }    
        for ($i=0; $i < count($boleto)-1; $i++) { 
            $posConfirmed = strpos($boleto[$i],"        CONFIRMED");
            if($posConfirmed !== false){
                $date = substr($boleto[$i],$posFecha2,5) . $anio;
                $date2 = $this->formatearFecha($date);
                $flt = substr($boleto[$i],$posVuelo2,4);
                $fareBasis = substr($boleto[$i],$posFareBasis2,8);
                $class = trim(substr($boleto[$i],$posClase2,9));
                $lv = rtrim(substr($boleto[$i+1],16,18));
                $horaSalida = rtrim(substr($boleto[$i+1],39,4));
                $ar = rtrim(substr($boleto[$i+2],16,18));
                $horaLlegada = rtrim(substr($boleto[$i+2],39,4));
                // dd($date2);
                $this->boletoRutas->add(array(
                    'ciudadSalida' =>  $lv,
                    'ciudadLlegada' =>  $ar,
                    'idAerolinea' =>  (int)$this->idAerolinea,
                    'vuelo' =>  $flt,
                    'clase' =>  $class,
                    'fechaSalida' =>  $date2,
                    'horaSalida' =>  $horaSalida,
                    'fechaLlegada' =>  $date2,
                    'horaLlegada' =>  $horaLlegada,
                    'farebasis' =>  $fareBasis
                ));
            }
        }
        // dd($this->boletoRutas);
        $this->idGds = 1;
        $this->grabarBoleto();
        // dd($this->idAerolinea);
    }

    public function formatearFecha($fecha){
        // Fecha en formato "21MAY24"
        $fecha_original = $fecha;

        // Crear un objeto Carbon a partir de la fecha original
        $fecha_objeto = Carbon::createFromFormat('dMY', $fecha_original);

        // Formatear la fecha en el nuevo formato "YYYY-MM-DD"
        $fecha_formateada = $fecha_objeto->format('Y-m-d');

        // Mostrar la fecha formateada
        return $fecha_formateada; // Salida: 2024-05-21
    }   

    public function crearFile($boleto){
        $fechaActual = Carbon::now();
        if(!$this->checkFile and !$this->numeroFile){
            $file = new File();
            $file->numeroFile = $boleto->numeroFile;
            $file->idArea = $boleto->idArea;
            $file->idCliente = $boleto->idCliente;
            $file->descripcion = $boleto->ruta;
            $file->totalPago = 0;
            $file->totalCobro = 0;
            $file->fechaFile = Carbon::parse($fechaActual)->format("Y-m-d");
            $file->idEstado = 1;
            $file->usuarioCreacion = auth()->user()->id;
            $file->save();

            $fileDetalle = new FileDetalle();
            $fileDetalle->idFile = $file->id;
            $fileDetalle->numeroFile = $file->numeroFile;
            $fileDetalle->idBoleto = $boleto->id;
            $fileDetalle->idEstado = 1;
            $fileDetalle->usuarioCreacion = auth()->user()->id;
            $fileDetalle->save();
        }else{
            $oFile = File::where("numeroFile",$this->numeroFile)->first();
            $fileDetalle = new FileDetalle();
            $fileDetalle->idFile = $oFile->id;
            $fileDetalle->numeroFile = $oFile->numeroFile;
            $fileDetalle->idBoleto = $boleto->id;
            $fileDetalle->idEstado = 1;
            $fileDetalle->usuarioCreacion = auth()->user()->id;
            $fileDetalle->save();
        }
        

    }

    public function grabarBoleto(){
        $area = Area::find($this->idArea);
        $boleto = new Boleto();
        $funciones = new Funciones();
        $boleto->numeroBoleto = $this->numeroBoleto;
        if($this->checkFile){
            $boleto->numeroFile = $this->numeroFile;
        }else{
            $file = $funciones->generaFile('FILES');
            $boleto->numeroFile = $area->codigo . str_pad($file,7,"0",STR_PAD_LEFT);
        }
        
        $boleto->idCliente = $this->idCliente;
        $boleto->idSolicitante = 0;
        $boleto->fechaEmision = $this->fechaEmision;
        $boleto->idCounter = $this->idCounter;
        $boleto->idTipoFacturacion = 1;
        $boleto->idTipoDocumento = 6;
        $boleto->idArea = $this->idArea;
        $boleto->idVendedor = 1;
        $boleto->idConsolidador = 9;
        $boleto->codigoReserva = $this->codigoReserva;
        $boleto->fechaReserva = $this->fechaEmision;
        $boleto->idGds = $this->idGds;
        $boleto->idTipoTicket = 1;
        $boleto->tipoRuta = $this->tipoRuta;
        $boleto->tipoTarifa = 'NORMAL';
        $boleto->idAerolinea = $this->idAerolinea;
        $boleto->origen = 'BSP';
        $boleto->pasajero = $this->pasajero;
        $boleto->idTipoPasajero = 1;
        $boleto->ruta = $this->ruta;
        $boleto->destino = $this->destino;
        $boleto->tipoCambio = $this->tipoCambio;
        $boleto->idMoneda = 1;
        $boleto->tarifaNeta = $this->tarifaNeta;
        $boleto->inafecto = 0;
        $boleto->igv = $this->igv;
        $boleto->otrosImpuestos = $this->otrosImpuestos;
        $boleto->xm = 0;
        $boleto->total = $this->tarifaNeta + $this->igv + $this->otrosImpuestos;
        $boleto->totalOrigen = $this->tarifaNeta + $this->igv + $this->otrosImpuestos;
        $boleto->porcentajeComision = 0;
        $boleto->montoComision = 0;
        $boleto->descuentoCorporativo = 0;
        $boleto->codigoDescCorp = ' ';
        $boleto->tarifaNormal = 0;
        $boleto->tarifaAlta = 0;
        $boleto->tarifaBaja = 0;
        $boleto->idTipoPagoConsolidador = 6;
        $boleto->centroCosto = ' ';
        $boleto->cod1 = ' ';
        $boleto->cod2 = ' ';
        $boleto->cod3 = ' ';
        $boleto->cod4 = ' ';
        $boleto->observaciones = ' ';
        $boleto->detalleRutas = $this->detalleRutas;
        if($this->bookingAgent){
            $boleto->bookingAgent = $this->bookingAgent;
        }else{
            $boleto->bookingAgent = '4IHF';
        }
        $boleto->estado = 1;
        $boleto->usuarioCreacion = auth()->user()->id;
        $boleto->save();
        $this->grabarPagosSabre($boleto->id);
        $this->grabarRutas($boleto->id, $this->boletoRutas);
        $this->crearFile($boleto);
        try {
            return redirect()->route('listaBoletos');
        } catch (\Throwable $th) {
            session()->flash('error', 'Ocurrió un error intentando grabar.');
        }
    }

    public function parsearKiu($boleto){
        $contador = 0;
        if (count($boleto) < 5) {
            session()->flash('error', 'Formato incorrecto del boleto.');
            return;
        }
        foreach ($boleto as $linea) {
            $contador = $contador + 1;
            //Obtener Pasajero
            $posPasajero = strpos($linea,"NAME/NOMBRE:");
            if($posPasajero !== false){
                $this->pasajero = substr($linea,$posPasajero+13,25);
                $this->pasajero = trim($this->pasajero);
                $this->pasajero = str_replace("/"," ",$this->pasajero);
            }

            //Obtener Aerolínea y Numero de Boleto
            $posBoleto = strpos($linea,"TICKET NBR:");
            if($posBoleto !== false){
                $codigoAerolinea = substr($linea,$posBoleto+12,3);
                $oAerolinea = Aerolinea::where('codigoIata',$codigoAerolinea)->first();
                $this->idAerolinea = $oAerolinea->id;

                $this->numeroBoleto = substr(trim($linea),-10);
                $oBoleto = Boleto::where('numeroBoleto',$this->numeroBoleto)->first();
                if($oBoleto){
                    session()->flash('error', 'El boleto ya está integrado.');
                    return;
                }
            }
            
            //Obtener Código de Reserva
            $posPnr = strpos($linea,"BOOKING REF./CODIGO DE RESERVA:");
            if ($posPnr !== false) {
                $this->codigoReserva = substr(trim($linea),-6);
            }

            //Obtener Fecha de Emision
            $posFechaEmision = strpos($linea,"ISSUE DATE/FECHA DE EMISION:");
            if ($posFechaEmision !== false) {
                $fechaOriginal = substr($linea,$posFechaEmision+29,11);
                
                $fechaFormat = Carbon::createFromFormat('d M Y',$fechaOriginal);
                $this->fechaEmision = $fechaFormat->format('Y-m-d');
                
                $tc = TipoCambio::where('fechaCambio',$this->fechaEmision)->first();
                if($tc){
                    $this->tipoCambio = $tc->montoCambio;
                }else{
                    $this->tipoCambio = 0.00;
                }
            }

            //Obtener Emisor
            $posEmisor = strpos($linea,"ISSUE AGENT/AGENTE EMISOR:");
            if ($posEmisor !== false) {
                $this->bookingAgent = substr($linea,$posEmisor+27,9);
            }
            
            //Obtener Cliente
            $posCliente = strpos($linea,"RUC           :");
            if ($posCliente !== false) {
                $posRuc = strpos($linea,"RUC");
                if ($posRuc !== false) {
                    $ruc = substr($linea,-11);
                    $oCliente = Cliente::where('numeroDocumentoIdentidad',$ruc)->first();
                    if ($oCliente) {
                        $this->idCliente = $oCliente->id;
                        $this->idTipoFacturacion = $oCliente->tipoFacturacion;
                        // $this->idArea = $oCliente->area;
                        $this->idVendedor = $oCliente->vendedor;
                    }
                }else{
                    $doc = substr($linea,-8);
                    $oCliente = Cliente::where('numeroDocumentoIdentidad',$doc)->first();
                    if ($oCliente) {
                        $this->idCliente = $oCliente->id;
                        $this->idTipoFacturacion = $oCliente->tipoFacturacion;
                        // $this->idArea = $oCliente->area;
                        $this->idVendedor = $oCliente->vendedor;
                    }
                }
            }

            //Obtener Ruta / Destino
            $posRuta = strpos($linea,"FARE CALC./CALCULO DE TARIFA:");
            if ($posRuta !== false) {
                $cadena = Str::remove(range(0,9),$linea);
                $cadena = Str::remove(".",$cadena);
                $cadena = Str::remove("NUC",$cadena);
                $palabras = Str::of($cadena)->explode(' ');
                $palabras3 = $palabras->filter(function($palabra){
                    return Str::length($palabra) == 3;
                });
                foreach ($palabras3 as $word) {
                    $this->ruta = $this->ruta . $word . "/";
                }
                $this->ruta = substr($this->ruta,0,strlen($this->ruta)-1);
                
                $dest = str_replace("/","",$this->ruta);
                $incioCadena = round(((strlen($dest) / 3) / 2),0,PHP_ROUND_HALF_DOWN) * 3;
                $this->destino =  substr($dest, $incioCadena, 3);
            }

            //Obtener Forma de Pago
            $posFpago = strpos($linea,"FORM OF PAYMENT/FORMA DE PAGO      :");
            if ($posFpago !== false){
                if(strpos($linea,"CASH")){
                    $oTc = TarjetaCredito::where('codigo','XX')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','009')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"VISA")){
                    $oTc = TarjetaCredito::where('codigo','VI')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"MASTERCARD")){
                    $oTc = TarjetaCredito::where('codigo','MA')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"MASTER CARD")){
                    $oTc = TarjetaCredito::where('codigo','MA')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"DINERS CLUB")){
                    $oTc = TarjetaCredito::where('codigo','DC')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
                if(strpos($linea,"AMERICAN EXPRESS")){
                    $oTc = TarjetaCredito::where('codigo','AX')->first();
                    $this->idTarjetaCredito = $oTc->id;
                    $oMp = MedioPago::where('codigo','006')->first();
                    $this->idMedioPago = $oMp->id;
                }
            }

            // Obtener Tarifas
            
            $posTNeta = strpos($linea,"AIR FARE/TARIFA :");
            if ($posTNeta !== false) {
                $neto = substr($linea,-8);
                $this->tarifaNeta = trim($neto);
            }
            $posTax = strpos($linea,"TAX/IMPUESTOS   :");
            if ($posTax !== false) {
                $posPe = strpos($linea,"PE");
                if ($posPe !== false) {
                    $pe = substr($linea,$posPe-6,6);
                    $this->igv = trim($pe);
                }
                $posHw = strpos($linea,"HW");
                if ($posHw !== false) {
                    $hw = substr($linea,$posHw-6,6);
                    $this->hw = trim($hw);
                }
            }
            
            // dd($this->tipoRuta);
        }
        $this->otrosImpuestos = $this->hw;

        // Obtener Segmentos
        $anio = date('Y');
        $posDesde2 = 1;
        $posFecha2 = 1;
        $posVuelo2 = 1;
        $posClase2 = 1;
        $posFareBasis2 = 1;
        for ($i=0; $i < count($boleto)-1; $i++) { 
            $posDesde = strpos($boleto[$i],"FROM/TO     FLIGHT CL DATE  DEP  ARR  FARE BASIS      NVB   NVA   BAG  ST");
            $posVuelo = strpos($boleto[$i],"FLIGHT CL DATE  DEP  ARR  FARE BASIS      NVB   NVA   BAG  ST");
            $posClase = strpos($boleto[$i],"CL DATE  DEP  ARR  FARE BASIS      NVB   NVA   BAG  ST");
            $posFecha = strpos($boleto[$i],"DATE  DEP  ARR  FARE BASIS      NVB   NVA   BAG  ST");
            $posFareBasis = strpos($boleto[$i],"FARE BASIS      NVB   NVA   BAG  ST");
            if($posFecha !== false){
                $posDesde2 = $posDesde;
                $posFecha2 = $posFecha;
                $posVuelo2 = $posVuelo;
                $posClase2 = $posClase;
                $posFareBasis2 = $posFareBasis;
            }
        }    
        for ($i=0; $i < count($boleto)-1; $i++) { 
            $posConfirmed = strpos($boleto[$i],"  OK ");
            if($posConfirmed !== false){
                $date = trim(substr($boleto[$i],$posFecha2,5)) . $anio;
                $date2 = $this->formatearFecha($date);
                $flt = substr($boleto[$i],$posVuelo2,6);
                $fareBasis = substr($boleto[$i],$posFareBasis2,5);
                $class = substr($boleto[$i],$posClase2,1);
                $lv = rtrim(substr($boleto[$i],$posDesde2,12));
                $horaSalida = rtrim(substr($boleto[$i],$posFecha2+6,4));
                $ar = rtrim(substr($boleto[$i+1],$posDesde2,12));
                $horaLlegada = rtrim(substr($boleto[$i],$posFecha2+11,4));
                $this->boletoRutas->add(array(
                    'ciudadSalida' =>  $lv,
                    'ciudadLlegada' =>  $ar,
                    'idAerolinea' =>  (int)$this->idAerolinea,
                    'vuelo' =>  $flt,
                    'clase' =>  $class,
                    'fechaSalida' =>  $date2,
                    'horaSalida' =>  $horaSalida,
                    'fechaLlegada' =>  $date2,
                    'horaLlegada' =>  $horaLlegada,
                    'farebasis' =>  $fareBasis
                ));
            }
        }
        // dd($this->boletoRutas);
        $this->idGds = 2;
        
        $this->grabarBoleto();
        // dd($this->idAerolinea);
        
    }

    public function parsearNdc($boleto){
        $contador = 0;
        $contPE = 0;
        if (count($boleto) < 5) {
            session()->flash('error', 'Formato incorrecto del boleto.');
            return;
        }
        foreach ($boleto as $linea) {
            $contador = $contador + 1;
            
            //Obtener Pasajero
            $posPasajero = strpos($linea,"PASSENGER NAME:");
            if($posPasajero !== false){
                $this->pasajero = trim(str_replace("PASSENGER NAME:","",$linea));
            }
            
            //Obtener Aerolínea y Numero de Boleto
            $posBoleto = strpos($linea,"TICKETING NUMBER:");
            if($posBoleto !== false){
                $codigoAerolinea = substr($linea,$posBoleto+18,3);
                $oAerolinea = Aerolinea::where('codigoIata',$codigoAerolinea)->first();
                $this->idAerolinea = $oAerolinea->id;
                $this->numeroBoleto = substr($linea,21,10);
                $oBoleto = Boleto::where('numeroBoleto',$this->numeroBoleto)->first();
                if($oBoleto){
                    session()->flash('error', 'El boleto ya está integrado.');
                    return;
                }
            }
            
            //Obtener Código de Reserva
            $posPnr = strpos($linea,"BOOKING REFERENCE:");
            if ($posPnr !== false) {
                $this->codigoReserva = substr($linea,$posPnr+19,6);
            }

            //Obtener Fecha de Emision
            $posFechaEmision = strpos($linea,"DATE OF ISSUE:");
            if ($posFechaEmision !== false) {
                $this->fechaEmision = substr($linea,$posFechaEmision+15,10);

                $tc = TipoCambio::where('fechaCambio',$this->fechaEmision)->first();
                if($tc){
                    $this->tipoCambio = $tc->montoCambio;
                }else{
                    $this->tipoCambio = 0.00;
                }
            }
            
            //Obtener Cliente
            $posCliente = strpos($linea,"DOCUMENT NUMBER:");
            if ($posCliente !== false) {
                $ruc = substr($linea,$posCliente+17,11);
                $oCliente = Cliente::where('numeroDocumentoIdentidad',$ruc)->first();
                if ($oCliente) {
                    $this->idCliente = $oCliente->id;
                    $this->idTipoFacturacion = $oCliente->tipoFacturacion;
                    // $this->idArea = $oCliente->area;
                    $this->idVendedor = $oCliente->vendedor;
                }
            }

            // //Obtener Ruta / Destino
            // $posRuta = strpos($linea,"FARE CALC:");
            // if ($posRuta !== false) {
            //     $cadena = Str::remove(range(0,9),$linea);
            //     $cadena = Str::remove(".",$cadena);
            //     $cadena = Str::remove("NUC",$cadena);
            //     $palabras = Str::of($cadena)->explode(' ');
            //     $palabras3 = $palabras->filter(function($palabra){
            //         return Str::length($palabra) == 3;
            //     });
            //     foreach ($palabras3 as $word) {
            //         $this->ruta = $this->ruta . $word . "/";
            //     }
            //     $this->ruta = substr($this->ruta,0,strlen($this->ruta)-1);

            //     $dest = str_replace("/","",$this->ruta);
            //     $incioCadena = round(((strlen($dest) / 3) / 2),0,PHP_ROUND_HALF_DOWN) * 3;
            //     $this->destino =  substr($dest, $incioCadena, 3);
            // }

            //Obtener Forma de Pago
            $posFpago = strpos($linea,"PAYMENT METHOD:");
            if ($posFpago !== false){
                if(strpos($linea,"TC")){
                    $this->idTarjetaCredito = 2;
                    $this->idMedioPago = 6;
                }else{
                    $this->idTarjetaCredito = 1;
                    $this->idMedioPago = 8;
                }
            }

            // Obtener Tarifas
            // $posDy = strpos($linea,"DY");
            // if ($posDy !== false) {
            //     $this->tipoRuta = "INTERNACIONAL";
            // }else{
            //     $this->tipoRuta = "NACIONAL";
            // }
            $posTNeta = strpos($linea,"FARE: USD");
            if ($posTNeta !== false) {
                $neto = substr($linea,$posTNeta+9,7);
                $this->tarifaNeta = trim($neto);
            }else{
                $posTNeta = strpos($linea,"FARE: ");
                if ($posTNeta !== false) {
                    $neto = substr($linea,$posTNeta+6,7);
                    $this->tarifaNeta = trim($neto);
                }
            }
            
            $posPe = strpos($linea,"PE: USD");
            if ($posPe !== false) {
                $pe = substr($linea,$posPe+7,6);
                $this->igv = trim($pe);
            }else{
                $posPe = strpos($linea,"PE: ");
                if ($posPe !== false) {
                    $contPE = $contPE + 1;
                    if($contPE == 2){
                        $pe = substr($linea,$posPe+4,6);
                        $this->igv = trim($pe);
                    } 
                } 
            }

            $posHw = strpos($linea,"HW: USD");
            if ($posHw !== false) {
                $nhw = substr($linea,$posHw+7,6);
                $this->hw = trim($nhw);
            }else{
                $posHw = strpos($linea,"HW:");
                if ($posHw !== false){
                    $nhw = substr($linea,$posHw+4,6);
                    $this->hw = trim($nhw);
                }
                
            }
            
            // // dd($this->tipoRuta);
        }
        $this->otrosImpuestos = $this->hw;

        // Obtener Rutas detalle
        // $patron = '/-{10,}\s*(.*?)\s*ENDORSEMENTS:/s';
        $patron = '/Flight number\s*(.*?)\s*PAYMENT METHOD:/s';

        if (preg_match($patron, $this->boleto, $coincidencias)) {
            $seccion_deseada = trim($coincidencias[1]);

            $detalleR = explode("\n",$seccion_deseada);
            array_shift($detalleR);
            array_pop($detalleR);
            foreach ($detalleR as $linea) {
                $segmento = explode(' ',$linea);
                $this->boletoRutas->add(array(
                    'ciudadSalida' =>  $segmento[1],
                    'ciudadLlegada' =>  $segmento[2],
                    'idAerolinea' =>  (int)$this->idAerolinea,
                    'vuelo' =>  $segmento[0],
                    'clase' =>  strtoupper($segmento[8]),
                    'fechaSalida' =>  DateTime::createFromFormat('d/m/y', $segmento[3])->format('Y-m-d'),
                    'horaSalida' =>  Str::remove(':',$segmento[4]),
                    'fechaLlegada' =>  DateTime::createFromFormat('d/m/y', $segmento[5])->format('Y-m-d'),
                    'horaLlegada' =>  Str::remove(':',$segmento[6]),
                    'farebasis' =>  $segmento[7]
                ));
            }
        } 

        $this->idGds = 1;
        $this->grabarBoleto();
        // dd($this->idAerolinea);
    }

    public function parsearAmadeus($boleto){
        $contador = 0;
        $contPE = 0;
        if (count($boleto) < 5) {
            session()->flash('error', 'Formato incorrecto del boleto.');
            return;
        }
        foreach ($boleto as $linea) {
            $contador = $contador + 1;
            
            //Obtener Pasajero
            $posPasajero = strpos($linea,"Traveler ");
            if($posPasajero !== false){
                $this->pasajero = trim(str_replace("Traveler ","",$linea));
                $this->pasajero = trim(str_replace(" (ADT)","",$this->pasajero));
            }
            $posPasajero = strpos($linea,"Viajero ");
            if($posPasajero !== false){
                $this->pasajero = trim(str_replace("Viajero ","",$linea));
                $this->pasajero = trim(str_replace(" (ADT)","",$this->pasajero));
            }

            //Obtener Aerolínea y Numero de Boleto
            $posBoleto = strpos($linea,"Ticket: ");
            if($posBoleto !== false){
                $codigoAerolinea = substr($linea,$posBoleto+8,3);
                $oAerolinea = Aerolinea::where('codigoIata',$codigoAerolinea)->first();
                $this->idAerolinea = $oAerolinea->id;
                
                $this->numeroBoleto = substr($linea,12,10);
                $oBoleto = Boleto::where('numeroBoleto',$this->numeroBoleto)->first();
                if($oBoleto){
                    session()->flash('error', 'El boleto ya está integrado.');
                    return;
                }
            }
            $posBoleto = strpos($linea,"Billete Electrónico: ");
            if($posBoleto !== false){
                $codigoAerolinea = substr($linea,$posBoleto+22,3);
                $oAerolinea = Aerolinea::where('codigoIata',$codigoAerolinea)->first();
                $this->idAerolinea = $oAerolinea->id;
                
                $this->numeroBoleto = substr($linea,26,10);
                $oBoleto = Boleto::where('numeroBoleto',$this->numeroBoleto)->first();
                if($oBoleto){
                    session()->flash('error', 'El boleto ya está integrado.');
                    return;
                }
            }
            
            //Obtener Código de Reserva
            $posPnr = strpos($linea,"Booking ref:");
            if ($posPnr !== false) {
                $this->codigoReserva = substr($linea,$posPnr+13,6);
            }
            $posPnr = strpos($linea,"Loc. Reserva: ");
            if ($posPnr !== false) {
                $this->codigoReserva = substr($linea,$posPnr+14,6);
            }

            //Obtener Fecha de Emision
            $posFechaEmision = strpos($linea,"Issue date:");
            if ($posFechaEmision !== false) {
                $fecEmision = substr($linea,$posFechaEmision+12,30);
                $fecEmision = str_replace(" Baggage","",$fecEmision);
                $datetime = DateTime::createFromFormat('d F y', $fecEmision);
                $this->fechaEmision = $datetime->format('Y-m-d');
                
                $tc = TipoCambio::where('fechaCambio',$this->fechaEmision)->first();
                if($tc){
                    $this->tipoCambio = $tc->montoCambio;
                }else{
                    $this->tipoCambio = 0.00;
                }
            }
            $posFechaEmision = strpos($linea,"Fecha de Emisión: ");
            if ($posFechaEmision !== false) {
                $fecEmision = substr($linea,$posFechaEmision+18,30);
                $fecEmision = trim(str_replace(" Equipaje","",$fecEmision));
                $fecEmision = str_replace("ENERO","JANUARY",$fecEmision);
                $fecEmision = str_replace("FEBRERO","FEBRUARY",$fecEmision);
                $fecEmision = str_replace("MARZO","MARCH",$fecEmision);
                $fecEmision = str_replace("ABRIL","APRIL",$fecEmision);
                $fecEmision = str_replace("MAYO","MAY",$fecEmision);
                $fecEmision = str_replace("JUNIO","JUNE",$fecEmision);
                $fecEmision = str_replace("JULIO","JULY",$fecEmision);
                $fecEmision = str_replace("AGOSTO","AUGUST",$fecEmision);
                $fecEmision = str_replace("SEPTIEMBRE","SEPTEMBER",$fecEmision);
                $fecEmision = str_replace("OCTUBRE","OCTOBER",$fecEmision);
                $fecEmision = str_replace("NOVIEMBRE","NOVEMBER",$fecEmision);
                $fecEmision = str_replace("DICIEMBRE","DECEMBER",$fecEmision);
                $datetime = DateTime::createFromFormat('d M y', $fecEmision);
                $this->fechaEmision = $datetime->format('Y-m-d');
                $tc = TipoCambio::where('fechaCambio',$this->fechaEmision)->first();
                if($tc){
                    $this->tipoCambio = $tc->montoCambio;
                }else{
                    $this->tipoCambio = 0.00;
                }
            }
            
            //Obtener Cliente
            $posCliente = strpos($linea,"RUC2");
            if ($posCliente !== false) {
                $ruc = substr($linea,$posCliente+3,11);
                $oCliente = Cliente::where('numeroDocumentoIdentidad',$ruc)->first();
                if ($oCliente) {
                    $this->idCliente = $oCliente->id;
                    $this->idTipoFacturacion = $oCliente->tipoFacturacion;
                    // $this->idArea = $oCliente->area;
                    $this->idVendedor = $oCliente->vendedor;
                }
            }
            if(!$this->idCliente){
                $posClienteNI = strpos($linea,"NI");
                if ($posClienteNI !== false){
                    if(strlen($linea) == 10){
                        $dni = substr($linea,2,8);
                        $oCliente = Cliente::where('numeroDocumentoIdentidad',$dni)->first();
                        if ($oCliente) {
                            $this->idCliente = $oCliente->id;
                            $this->idTipoFacturacion = $oCliente->tipoFacturacion;
                            // $this->idArea = $oCliente->area;
                            $this->idVendedor = $oCliente->vendedor;
                        }
                    }
                }
            }
            if(!$this->idCliente){
                $posClientePSP = strpos($linea,"P");
                if ($posClientePSP !== false){
                    if(strlen($linea) == 10){
                        $psp = substr($linea,1,9);
                        $oCliente = Cliente::where('numeroDocumentoIdentidad',$psp)->first();
                        if ($oCliente) {
                            $this->idCliente = $oCliente->id;
                            $this->idTipoFacturacion = $oCliente->tipoFacturacion;
                            // $this->idArea = $oCliente->area;
                            $this->idVendedor = $oCliente->vendedor;
                        }
                    }
                }
            }

            //Obtener Ruta / Destino
            $posRuta = strpos($linea,"Fare Calculation :");
            if ($posRuta !== false) {
                $cadena = Str::remove(range(0,9),$linea);
                $cadena = str_replace("."," ",$cadena);
                $cadena = Str::remove("NUC",$cadena);
                $cadena = Str::remove("USD",$cadena);
                $cadena = Str::remove("END",$cadena);
                $cadena = Str::remove("ROE",$cadena);
                $cadena = Str::remove("X/",$cadena);
                $palabras = Str::of($cadena)->explode(' ');
                $palabras3 = $palabras->filter(function($palabra){
                    return Str::length($palabra) == 3;
                });
                foreach ($palabras3 as $word) {
                    $this->ruta = $this->ruta . $word . "/";
                }
                $this->ruta = substr($this->ruta,0,strlen($this->ruta)-1);
                $dest = str_replace("/","",$this->ruta);
                $incioCadena = round(((strlen($dest) / 3) / 2),0,PHP_ROUND_HALF_DOWN) * 3;
                $this->destino =  substr($dest, $incioCadena, 3);
            }
            $posRuta = strpos($linea,"Cálculo de Tarifa :");
            if ($posRuta !== false) {
                $cadena = Str::remove(range(0,9),$linea);
                $cadena = str_replace("."," ",$cadena);
                $cadena = Str::remove("NUC",$cadena);
                $cadena = Str::remove("USD",$cadena);
                $cadena = Str::remove("END",$cadena);
                $cadena = Str::remove("ROE",$cadena);
                $cadena = Str::remove("X/",$cadena);
                $palabras = Str::of($cadena)->explode(' ');
                $palabras3 = $palabras->filter(function($palabra){
                    return Str::length($palabra) == 3;
                });
                foreach ($palabras3 as $word) {
                    $this->ruta = $this->ruta . $word . "/";
                }
                $this->ruta = substr($this->ruta,0,strlen($this->ruta)-1);
                $dest = str_replace("/","",$this->ruta);
                $incioCadena = round(((strlen($dest) / 3) / 2),0,PHP_ROUND_HALF_DOWN) * 3;
                $this->destino =  substr($dest, $incioCadena, 3);
            }

            //Obtener Forma de Pago
            $posFpago = strpos($linea,"Form of payment :");
            if ($posFpago !== false){
                if(strpos($linea,"CCVI")){
                    $this->idTarjetaCredito = 2;
                    $this->idMedioPago = 6;
                }else{
                    $this->idTarjetaCredito = 1;
                    $this->idMedioPago = 8;
                }
            }
            $posFpago = strpos($linea,"Modo de pago :");
            if ($posFpago !== false){
                if(strpos($linea,"CCVI")){
                    $this->idTarjetaCredito = 2;
                    $this->idMedioPago = 6;
                }else{
                    $this->idTarjetaCredito = 1;
                    $this->idMedioPago = 8;
                }
            }

            // Obtener Tarifas
            // $posDy = strpos($linea,"DY");
            // if ($posDy !== false) {
            //     $this->tipoRuta = "INTERNACIONAL";
            // }else{
            //     $this->tipoRuta = "NACIONAL";
            // }
            
            $posTNeta = strpos($linea,"Air Fare :");
            if ($posTNeta !== false) {
                $neto = substr($linea,$posTNeta+15,8);
                $this->tarifaNeta = trim($neto);
                $this->igv = $this->tarifaNeta * 0.18;
            }
            $posTNeta = strpos($linea,"Tarifa aérea :");
            if ($posTNeta !== false) {
                $neto = substr($linea,$posTNeta+19,8);
                $this->tarifaNeta = trim($neto);
                // $this->igv = $this->tarifaNeta * 0.18;
            }
            $posYR = strpos($linea,"Airline Surcharges :");
            if ($posYR !== false){
                $this->yr = substr($linea,$posYR+25,10);
                $this->yr = Str::remove("YR",$this->yr);
                $this->tarifaNeta = $this->tarifaNeta + $this->yr;
                $this->igv = $this->tarifaNeta * 0.18;
            }
            $posYR = strpos($linea,"Recargo De Aerolinea :");
            if ($posYR !== false){
                $this->yr = substr($linea,$posYR+27,10);
                $this->yr = Str::remove("YR",$this->yr);
                $this->tarifaNeta = $this->tarifaNeta + $this->yr;
                $this->igv = $this->tarifaNeta * 0.18;
            }
            
            // $posPe = strpos($linea,"PE: USD");
            // if ($posPe !== false) {
            //     $pe = substr($linea,$posPe+7,6);
            //     $this->igv = trim($pe);
            // }else{
            //     $posPe = strpos($linea,"PE: ");
            //     if ($posPe !== false) {
            //         $contPE = $contPE + 1;
            //         if($contPE == 2){
            //             $pe = substr($linea,$posPe+4,6);
            //             $this->igv = trim($pe);
            //         } 
            //     } 
            // }

            $posTotal = strpos($linea,"Total Amount");
            if ($posTotal !== false){
                $total = substr($linea,$posTotal+19,8);
                $this->otrosImpuestos = $total - $this->tarifaNeta - $this->igv;
            }
            $posTotal = strpos($linea,"Importe Total :");
            if ($posTotal !== false){
                $total = substr($linea,$posTotal+20,8);
                $this->otrosImpuestos = $total - $this->tarifaNeta - $this->igv;
            }
            
        }
        
        for ($i=0; $i < count($boleto) ; $i++) { 
            $posTotal = strpos($boleto[$i],"Itinerary");
            if ($posTotal !== false){
                // dd($boleto[$i]);
            }
        }
        $anio = date('Y');
        for ($i=0; $i < count($boleto)-1; $i++) { 
            $posOperado = strpos($boleto[$i],"Operado por");
            if ($posOperado !== false){
                $linea = $boleto[$i-2];
                $lineaFare = $boleto[$i-1];
                $segmento = explode(' ',$linea);
                $segmentoFare = explode(' ',$lineaFare);
                $date1 = $this->formatearFecha($segmento[4] . $anio);
                $date2 = $this->formatearFecha($segmento[8] . $anio);
                $this->boletoRutas->add(array(
                    'ciudadSalida' =>  $segmento[0],
                    'ciudadLlegada' =>  $segmento[1],
                    'idAerolinea' =>  (int)$this->idAerolinea,
                    'vuelo' =>  $segmento[2],
                    'clase' =>  $segmento[3],
                    'fechaSalida' =>  $date1,
                    'horaSalida' =>  Str::remove(':',$segmento[5]),
                    'fechaLlegada' =>  $date2,
                    'horaLlegada' =>  Str::remove(':',$segmento[6]),
                    'farebasis' =>  $segmentoFare[count($segmentoFare) - 1]
                ));
            }

            $posOperado = strpos($boleto[$i],"Operated by");
            if ($posOperado !== false){
                $linea = $boleto[$i-2];
                $lineaFare = $boleto[$i-1];
                $segmento = explode(' ',$linea);
                $segmentoFare = explode(' ',$lineaFare);
                $date1 = $this->formatearFecha($segmento[4] . $anio);
                $date2 = $this->formatearFecha($segmento[8] . $anio);
                $this->boletoRutas->add(array(
                    'ciudadSalida' =>  $segmento[0],
                    'ciudadLlegada' =>  $segmento[1],
                    'idAerolinea' =>  (int)$this->idAerolinea,
                    'vuelo' =>  $segmento[2],
                    'clase' =>  $segmento[3],
                    'fechaSalida' =>  $date1,
                    'horaSalida' =>  Str::remove(':',$segmento[5]),
                    'fechaLlegada' =>  $date2,
                    'horaLlegada' =>  Str::remove(':',$segmento[6]),
                    'farebasis' =>  $segmentoFare[count($segmentoFare) -1]
                ));
            }
        }
        
        $this->idGds = 4;
        $this->grabarBoleto();
    }

    public function grabarRutasNdc1($idBoleto){
        $boletoRuta = new BoletoRuta();
        $boletoRuta->idBoleto = $idBoleto;
        $boletoRuta->idAerolinea = 1;
        $boletoRuta->ciudadSalida = 'LIM';
        $boletoRuta->ciudadLlegada = 'PCL';
        $boletoRuta->vuelo = ' ';
        $boletoRuta->clase = 'S';
        $boletoRuta->fechaSalida = '2023-10-06';
        $boletoRuta->horaSalida = '1425';
        $boletoRuta->fechaLlegada = '2023-10-06';
        $boletoRuta->horaLlegada = '1545';
        $boletoRuta->farebasis = 'S00QP5ZB';
        $boletoRuta->idEstado = 1;
        $boletoRuta->usuarioCreacion = auth()->user()->id;
        $boletoRuta->save();
    }
    public function grabarRutasNdc2($idBoleto){
        $boletoRuta = new BoletoRuta();
        $boletoRuta->idBoleto = $idBoleto;
        $boletoRuta->idAerolinea = 1;
        $boletoRuta->ciudadSalida = 'PCL';
        $boletoRuta->ciudadLlegada = 'LIM';
        $boletoRuta->vuelo = ' ';
        $boletoRuta->clase = 'X';
        $boletoRuta->fechaSalida = '2023-10-15';
        $boletoRuta->horaSalida = '1310';
        $boletoRuta->fechaLlegada = '2023-10-15';
        $boletoRuta->horaLlegada = '1425';
        $boletoRuta->farebasis = 'X00QP5ZB';
        $boletoRuta->idEstado = 1;
        $boletoRuta->usuarioCreacion = auth()->user()->id;
        $boletoRuta->save();
    }
    public function grabarPagosNdc($idBoleto){
        $boletoPago = new BoletoPago();
        $boletoPago->idBoleto = $idBoleto;
        $boletoPago->idMedioPago = 6;
        $boletoPago->idTarjetaCredito = 2;
        $boletoPago->numeroTarjeta = ' ';
        $boletoPago->monto = 133.35;
        $boletoPago->fechaVencimientoTC = ' ';
        $boletoPago->idEstado = 1;
        $boletoPago->usuarioCreacion = auth()->user()->id;
        $boletoPago->save();
    }

    public function grabarRutasKiu1($idBoleto){
        $boletoRuta = new BoletoRuta();
        $boletoRuta->idBoleto = $idBoleto;
        $boletoRuta->idAerolinea = 3;
        $boletoRuta->ciudadSalida = 'IQT';
        $boletoRuta->ciudadLlegada = 'PCL';
        $boletoRuta->vuelo = '2I3132';
        $boletoRuta->clase = 'W';
        $boletoRuta->fechaSalida = '2023-09-25';
        $boletoRuta->horaSalida = '1500';
        $boletoRuta->fechaLlegada = '2023-09-25';
        $boletoRuta->horaLlegada = '1600';
        $boletoRuta->farebasis = 'WOW';
        $boletoRuta->idEstado = 1;
        $boletoRuta->usuarioCreacion = auth()->user()->id;
        $boletoRuta->save();
    }
    public function grabarRutasKiu2($idBoleto){
        $boletoRuta = new BoletoRuta();
        $boletoRuta->idBoleto = $idBoleto;
        $boletoRuta->idAerolinea = 3;
        $boletoRuta->ciudadSalida = 'PCL';
        $boletoRuta->ciudadLlegada = 'IQT';
        $boletoRuta->vuelo = '2I3131';
        $boletoRuta->clase = 'W';
        $boletoRuta->fechaSalida = '2023-09-30';
        $boletoRuta->horaSalida = '1325';
        $boletoRuta->fechaLlegada = '2023-09-30';
        $boletoRuta->horaLlegada = '1425';
        $boletoRuta->farebasis = 'WOW';
        $boletoRuta->idEstado = 1;
        $boletoRuta->usuarioCreacion = auth()->user()->id;
        $boletoRuta->save();
    }
    public function grabarPagosKiu($idBoleto){
        $boletoPago = new BoletoPago();
        $boletoPago->idBoleto = $idBoleto;
        $boletoPago->idMedioPago = 9;
        $boletoPago->idTarjetaCredito = 1;
        $boletoPago->numeroTarjeta = ' ';
        $boletoPago->monto = 223.56;
        $boletoPago->fechaVencimientoTC = ' ';
        $boletoPago->idEstado = 1;
        $boletoPago->usuarioCreacion = auth()->user()->id;
        $boletoPago->save();
    }

    public function grabarRutas($idBoleto, $rutas){
        foreach($rutas as $item){
            BoletoRuta::create([
                'idBoleto' => $idBoleto,
                'ciudadSalida' => $item['ciudadSalida'],
                'ciudadLlegada' => $item['ciudadLlegada'],
                'idAerolinea' => $item['idAerolinea'],
                'vuelo' => $item['vuelo'],
                'clase' => $item['clase'],
                'fechaSalida' => $item['fechaSalida'],
                'horaSalida' => $item['horaSalida'],
                'fechaLlegada' => $item['fechaLlegada'],
                'horaLlegada' => $item['horaLlegada'],
                'farebasis' => $item['farebasis'],
                'idEstado' => 1,
                'usuarioCreacion' => auth()->user()->id
            ]);
        }
    }
    public function grabarRutasSabre2($idBoleto){
        $boletoRuta = new BoletoRuta();
        $boletoRuta->idBoleto = $idBoleto;
        $boletoRuta->idAerolinea = 2;
        $boletoRuta->ciudadSalida = 'BOG';
        $boletoRuta->ciudadLlegada = 'LIM';
        $boletoRuta->vuelo = '52';
        $boletoRuta->clase = 'L';
        $boletoRuta->fechaSalida = '2023-09-22';
        $boletoRuta->horaSalida = '1840';
        $boletoRuta->fechaLlegada = '2023-09-22';
        $boletoRuta->horaLlegada = '2150';
        $boletoRuta->farebasis = 'LEOB2BRG';
        $boletoRuta->idEstado = 1;
        $boletoRuta->usuarioCreacion = auth()->user()->id;
        $boletoRuta->save();
    }
    public function grabarPagosSabre($idBoleto){
        $boletoPago = new BoletoPago();
        $boletoPago->idBoleto = $idBoleto;
        $boletoPago->idMedioPago = $this->idMedioPago;
        $boletoPago->idTarjetaCredito = $this->idTarjetaCredito;
        $boletoPago->numeroTarjeta = ' ';
        $boletoPago->monto = $this->tarifaNeta + $this->igv + $this->otrosImpuestos;
        $boletoPago->fechaVencimientoTC = ' ';
        $boletoPago->idEstado = 1;
        $boletoPago->usuarioCreacion = auth()->user()->id;
        $boletoPago->save();
    }
}
