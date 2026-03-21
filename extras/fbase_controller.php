<?php
/**
 * This file is part of facturacion_base
 * Copyright (C) 2017-2021 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Controlador extendido para el plugin facturacion_base.
 *
 * @author Carlos garcía Gómez
 */
class fbase_controller extends fs_controller
{
    private const MSG_FACTURA_BORRADA = 'La factura se ha borrado.';
    private const MSG_FACTURA_NO_BORRADA = '¡Imposible borrar la factura!';

    /**
     * TRUE si el usuario tiene permisos para eliminar en la página.
     *
     * @var bool
     */
    public $allow_delete;

    /**
     * TRUE si hay más de un almacén.
     *
     * @var bool
     */
    public $multi_almacen;

    protected function private_core()
    {
        /// ¿El usuario tiene permiso para eliminar en esta página?
        $this->allow_delete = $this->user->allow_delete_on($this->class_name);

        /// ¿Hay más de un almacén?
        $fsvar = new fs_var();
        $this->multi_almacen = (bool) $fsvar->simple_get('multi_almacen');
    }

    /**
     * Vuelca en la salida estándar un json con el listado de clientes
     * que coinciden con la búsqueda. Ideal para usar con el autocomplete en js.
     *
     * @param string $query
     */
    protected function fbase_buscar_cliente($query)
    {
        /// desactivamos la plantilla HTML
        $this->template = FALSE;

        $cli = new cliente();
        $json = [];
        foreach ($cli->search($query) as $cli) {
            $nombre = $cli->nombre;
            if ($cli->nombre != $cli->razonsocial) {
                $nombre .= ' (' . $cli->razonsocial . ')';
            }

            $json[] = array('value' => $nombre, 'data' => $cli->codcliente);
        }

        header('Content-Type: application/json');
        echo json_encode(array('query' => $query, 'suggestions' => $json));
    }

    /**
     * Vuelca en la salida estándar un json con el listado de proveedores
     * que coinciden con la búsqueda. Ideal para usar con el autocomplete en js.
     *
     * @param string $query
     */
    protected function fbase_buscar_proveedor($query)
    {
        /// desactivamos la plantilla HTML
        $this->template = FALSE;

        $prov = new proveedor();
        $json = [];
        foreach ($prov->search($query) as $prov) {
            $nombre = $prov->nombre;
            if ($prov->nombre != $prov->razonsocial) {
                $nombre .= ' (' . $prov->razonsocial . ')';
            }

            $json[] = array('value' => $nombre, 'data' => $prov->codproveedor);
        }

        header('Content-Type: application/json');
        echo json_encode(array('query' => $query, 'suggestions' => $json));
    }

    /**
     * Devuelve un array con los enlaces a las páginas en función de la url,
     * total y el offset proporcionado.
     *
     * @param string  $url
     * @param integer $total
     * @param integer $offset
     *
     * @return array
     */
    protected function fbase_paginas($url, $total, $offset)
    {
        $paginas = [];
        $i = 0;
        $num = 0;
        $actual = 1;

        /// añadimos todas la página
        while ($num < $total) {
            $paginas[$i] = array(
                'url' => $url . "&offset=" . ($i * FS_ITEM_LIMIT),
                'num' => $i + 1,
                'actual' => ($num == $offset)
            );

            if ($num == $offset) {
                $actual = $i;
            }

            $i++;
            $num += FS_ITEM_LIMIT;
        }

        /// ahora descartamos
        foreach ($paginas as $j => $value) {
            $enmedio = intval($i / 2);

            /**
             * descartamos todo excepto la primera, la última, la de enmedio,
             * la actual, las 5 anteriores y las 5 siguientes
             */
            if (($j > 1 && $j < $actual - 5 && $j != $enmedio) || ( $j > $actual + 5 && $j < $i - 1 && $j != $enmedio)) {
                unset($paginas[$j]);
            }
        }

        return count($paginas) > 1 ? $paginas : [];
    }

    /**
     * Devuelve un array con los valores distintos de la columna en la tabla.
     * Si se proporciona una columna2 y un valor, se filtran los valores
     * que coincidan con ese valor en la columna2.
     *
     * @param string $tabla
     * @param string $columna
     * @param string $columna2
     * @param string $valor
     *
     * @return array
     */
    public function fbase_sql_distinct($tabla, $columna, $columna2 = '', $valor = '')
    {
        $final = [];

        if ($this->db->table_exists($tabla)) {
            $sql = "SELECT DISTINCT " . $columna . " FROM " . $tabla . " ORDER BY " . $columna . " ASC;";
            if ($valor != '') {
                $valor = mb_strtolower($valor, 'UTF8');
                $sql = "SELECT DISTINCT " . $columna . " FROM " . $tabla . " WHERE lower(" . $columna2 . ") = "
                    . $this->var2str($valor) . " ORDER BY " . $columna . " ASC;";
            }

            $data = $this->db->select($sql);
            if ($data) {
                foreach ($data as $d) {
                    if ($d[$columna] != '') {
                        /// usamos las minúsculas para filtrar
                        $final[mb_strtolower($d[$columna], 'UTF8')] = $d[$columna];
                    }
                }
            }
        }

        return $final;
    }

    /**
     * Devuelve el total de elementos en una tabla atendiendo a la columna.
     *
     * @param string $tabla
     * @param string $columna
     * @param string $where
     *
     * @return int
     */
    public function fbase_sql_total($tabla, $columna, $where = '')
    {
        $data = $this->db->select("SELECT COUNT(" . $columna . ") as total FROM " . $tabla . ' ' . $where . ";");
        if ($data) {
            return intval($data[0]['total']);
        }

        return 0;
    }

    /**
     * Devuelve el escalar del descuento unificado equivalente
     * Por ejemplo: recibe descuentos = [50, 10] y devuelve 0.45
     * 
     * @param array $descuentos contiene un array de float.
     *
     * @return float
     */
    public function fbase_calc_due($descuentos)
    {
        return 1 - $this->fbase_calc_desc_due($descuentos) / 100;
    }

    /**
     * Devuelve el descuento unificado equivalente
     * Por ejemplo: recibe descuentos = [50, 10] y devuelve 55
     * 
     * @param array $descuentos contiene un array de float.
     *
     * @return float
     */
    public function fbase_calc_desc_due($descuentos)
    {
        $dto = 1;
        foreach ($descuentos as $descuento) {
            $dto *= (1 - $descuento / 100);
        }
        return (1 - $dto) * 100;
    }

    /**
     * 
     * @param linea_factura_cliente[] $lineas
     * @param float                   $due_totales
     *
     * @return array
     */
    public function fbase_get_subtotales_documento($lineas, $due_totales = 1)
    {
        $subtotales = [];
        $irpf = 0;

        foreach ($lineas as $linea) {
            $codimpuesto = ($linea->codimpuesto === null ) ? 0 : $linea->codimpuesto;
            if (!array_key_exists($codimpuesto, $subtotales)) {
                $subtotales[$codimpuesto] = array(
                    'netosindto' => 0,
                    'neto' => 0,
                    'iva' => 0, // Total IVA
                    'recargo' => 0, // Total Recargo
                    'irpf' => 0, // Total IRPF, pero no se acumula por IVA
                );
            }

            $subtotales[$codimpuesto]['netosindto'] += $linea->pvptotal;

            // Hacemos el recalculo del PVP por línea, con el descuento adicional de fin de documento
            $pvpcondto = $due_totales * $linea->pvptotal;

            $subtotales[$codimpuesto]['neto'] += $pvpcondto;
            $subtotales[$codimpuesto]['iva'] += $pvpcondto * $linea->iva / 100;
            $subtotales[$codimpuesto]['recargo'] += $pvpcondto * $linea->recargo / 100;
            $irpf += $pvpcondto * $linea->irpf / 100;
        }

        /// ahora añadimos el irpf a los subtotales
        foreach ($subtotales as $key => $value) {
            $subtotales[$key]['irpf'] = $irpf;
            break;
        }

        /// ahora redondeamos
        foreach ($subtotales as $key => $value) {
            $subtotales[$key]['netosindto'] = round($value['netosindto'], FS_NF0);
            $subtotales[$key]['neto'] = round($value['neto'], FS_NF0);
            $subtotales[$key]['iva'] = round($value['iva'], FS_NF0);
            $subtotales[$key]['recargo'] = round($value['recargo'], FS_NF0);
            $subtotales[$key]['irpf'] = round($value['irpf'], FS_NF0);
        }

        return $subtotales;
    }

    /**
     * 
     * @param albaran_cliente[] $albaranes
     * @param string            $fecha
     * @param string            $codpago
     */
    protected function fbase_facturar_albaran_cliente($albaranes, $fecha = '', $codpago = '')
    {
        $factura = $this->buildFacturaClienteHeader($albaranes, $codpago);
        $cliente = $this->applyClienteDireccionFacturacion($factura, $albaranes);

        if ($fecha == '') {
            $fecha = $albaranes[0]->fecha;
        }

        $ejercicio = $this->applyFacturaClienteEjercicioYPago($factura, $fecha, $cliente);

        if (!fs_generar_numero2($factura)) {
            $factura->numero2 = $albaranes[0]->numero2;
        }

        if (!$this->validateFacturaEjercicio($ejercicio, $factura)) {
            return FALSE;
        }

        if (!$factura->save()) {
            $this->new_error_msg("¡Imposible guardar la factura!");
            return FALSE;
        }

        if (!$this->saveFacturaClienteLines($factura, $albaranes)) {
            $this->rollbackFacturaOnFailure($factura);
            return FALSE;
        }

        if (!$this->finalizeFacturaClienteTotales($factura, $albaranes)) {
            $this->rollbackFacturaOnFailure($factura);
            return FALSE;
        }

        $this->fbase_generar_asiento($factura);
        fs_documento_post_save($factura);

        return TRUE;
    }

    private function buildFacturaClienteHeader(array $albaranes, string $codpago): factura_cliente
    {
        if (empty($albaranes)) {
            throw new \RuntimeException('No se puede crear una factura sin albaranes.');
        }

        $factura = new factura_cliente();
        $alb = $albaranes[0];

        $factura->codagente = empty($alb->codagente) ? $this->user->codagente : $alb->codagente;
        $factura->codalmacen = $alb->codalmacen;
        $factura->coddivisa = $alb->coddivisa;
        $factura->tasaconv = $alb->tasaconv;
        $factura->codpago = $codpago ?: $alb->codpago;
        $factura->codserie = $alb->codserie;
        $factura->irpf = $alb->irpf;

        if (count($albaranes) == 1) {
            $factura->observaciones = $alb->observaciones;
        }

        $factura->apartado = $alb->apartado;
        $factura->cifnif = $alb->cifnif;
        $factura->ciudad = $alb->ciudad;
        $factura->codcliente = $alb->codcliente;
        $factura->coddir = $alb->coddir;
        $factura->codpais = $alb->codpais;
        $factura->codpostal = $alb->codpostal;
        $factura->direccion = $alb->direccion;
        $factura->nombrecliente = $alb->nombrecliente;
        $factura->provincia = $alb->provincia;

        $factura->envio_apellidos = $alb->envio_apellidos;
        $factura->envio_ciudad = $alb->envio_ciudad;
        $factura->envio_codigo = $alb->envio_codigo;
        $factura->envio_codpostal = $alb->envio_codpostal;
        $factura->envio_codtrans = $alb->envio_codtrans;
        $factura->envio_direccion = $alb->envio_direccion;
        $factura->envio_nombre = $alb->envio_nombre;
        $factura->envio_provincia = $alb->envio_provincia;

        $factura->dtopor1 = $alb->dtopor1;
        $factura->dtopor2 = $alb->dtopor2;
        $factura->dtopor3 = $alb->dtopor3;
        $factura->dtopor4 = $alb->dtopor4;
        $factura->dtopor5 = $alb->dtopor5;

        return $factura;
    }

    /**
     * Actualiza la dirección de facturación si el cliente tiene una más reciente.
     *
     * @return object|null El cliente encontrado, o null
     */
    private function applyClienteDireccionFacturacion(object $factura, array $albaranes): ?object
    {
        $cliente_model = new cliente();
        $cliente = $cliente_model->get($albaranes[0]->codcliente);

        if (!$cliente) {
            return null;
        }

        foreach ($cliente->get_direcciones() as $dir) {
            if ($dir->domfacturacion && strtotime($dir->fecha) > strtotime($albaranes[0]->fecha)) {
                $factura->apartado = $dir->apartado;
                $factura->cifnif = $cliente->cifnif;
                $factura->ciudad = $dir->ciudad;
                $factura->codcliente = $cliente->codcliente;
                $factura->coddir = $dir->id;
                $factura->codpais = $dir->codpais;
                $factura->codpostal = $dir->codpostal;
                $factura->direccion = $dir->direccion;
                $factura->nombrecliente = $cliente->razonsocial;
                $factura->provincia = $dir->provincia;
                break;
            }
        }

        return $cliente;
    }

    /**
     * @return object|null El ejercicio asignado, o null si no se encontró
     */
    private function applyFacturaClienteEjercicioYPago(object $factura, string $fecha, ?object $cliente): ?object
    {
        $ejercicio_model = new ejercicio();
        $ejercicio = $ejercicio_model->get_by_fecha($fecha);
        if ($ejercicio) {
            $factura->codejercicio = $ejercicio->codejercicio;
            $factura->set_fecha_hora($fecha, $factura->hora);
        }

        $forma_pago_model = new forma_pago();
        $forma_pago = $forma_pago_model->get($factura->codpago);
        if ($forma_pago) {
            if ($forma_pago->genrecibos == 'Pagados') {
                $factura->pagada = TRUE;
            }

            $factura->vencimiento = $cliente
                ? $forma_pago->calcular_vencimiento($factura->fecha, $cliente->diaspago)
                : $forma_pago->calcular_vencimiento($factura->fecha);
        }

        return $ejercicio;
    }

    private function saveFacturaClienteLines(object $factura, array $albaranes): bool
    {
        foreach ($albaranes as $alb) {
            foreach ($alb->get_lineas() as $l) {
                $n = new linea_factura_cliente();
                $n->idalbaran = $alb->idalbaran;
                $n->idlineaalbaran = $l->idlinea;
                $n->idfactura = $factura->idfactura;
                $n->cantidad = $l->cantidad;
                $n->codimpuesto = $l->codimpuesto;
                $n->descripcion = $l->descripcion;
                $n->dtopor = $l->dtopor;
                $n->dtopor2 = $l->dtopor2;
                $n->dtopor3 = $l->dtopor3;
                $n->dtopor4 = $l->dtopor4;
                $n->irpf = $l->irpf;
                $n->iva = $l->iva;
                $n->pvpsindto = $l->pvpsindto;
                $n->pvptotal = $l->pvptotal;
                $n->pvpunitario = $l->pvpunitario;
                $n->recargo = $l->recargo;
                $n->referencia = $l->referencia;
                $n->codcombinacion = $l->codcombinacion;
                $n->mostrar_cantidad = $l->mostrar_cantidad;
                $n->mostrar_precio = $l->mostrar_precio;

                if (!$n->save()) {
                    $this->new_error_msg("¡Imposible guardar la línea el artículo " . $n->referencia . "! ");
                    return false;
                }
            }
        }

        return true;
    }

    private function finalizeFacturaClienteTotales(object $factura, array $albaranes): bool
    {
        $due_totales = $this->fbase_calc_due([$factura->dtopor1, $factura->dtopor2, $factura->dtopor3, $factura->dtopor4, $factura->dtopor5]);
        foreach ($this->fbase_get_subtotales_documento($factura->get_lineas(), $due_totales) as $subt) {
            $factura->netosindto += $subt['netosindto'];
            $factura->neto += $subt['neto'];
            $factura->totaliva += $subt['iva'];
            $factura->totalirpf += $subt['irpf'];
            $factura->totalrecargo += $subt['recargo'];
        }

        $factura->total = round($factura->neto + $factura->totaliva - $factura->totalirpf + $factura->totalrecargo, FS_NF0);
        if (!$factura->save()) {
            $this->new_error_msg("¡Imposible guardar los totales de la factura!");
            return false;
        }

        foreach ($albaranes as $alb) {
            $alb->idfactura = $factura->idfactura;
            $alb->ptefactura = FALSE;

            if (!$alb->save()) {
                $this->new_error_msg("¡Imposible vincular el " . FS_ALBARAN . " con la nueva factura!");
                return false;
            }
        }

        return true;
    }

    /**
     * 
     * @param albaran_proveedor[] $albaranes
     * @param string              $fecha
     */
    protected function fbase_facturar_albaran_proveedor($albaranes, $fecha = '')
    {
        $factura = $this->buildFacturaProveedorHeader($albaranes);

        if ($fecha == '') {
            $fecha = $albaranes[0]->fecha;
        }

        $ejercicio = $this->applyFacturaProveedorEjercicioYPago($factura, $fecha);

        if (!fs_generar_numproveedor($factura)) {
            $factura->numproveedor = $albaranes[0]->numproveedor;
        }

        if (!$this->validateFacturaEjercicio($ejercicio, $factura)) {
            return FALSE;
        }

        if (!$factura->save()) {
            $this->new_error_msg("¡Imposible guardar la factura!");
            return FALSE;
        }

        if (!$this->saveFacturaProveedorLines($factura, $albaranes)) {
            $this->rollbackFacturaOnFailure($factura);
            return FALSE;
        }

        if (!$this->finalizeFacturaProveedorTotales($factura, $albaranes)) {
            $this->rollbackFacturaOnFailure($factura);
            return FALSE;
        }

        $this->fbase_generar_asiento($factura);
        fs_documento_post_save($factura);

        return TRUE;
    }

    private function buildFacturaProveedorHeader(array $albaranes): factura_proveedor
    {
        if (empty($albaranes)) {
            throw new \RuntimeException('No se puede crear una factura sin albaranes.');
        }

        $factura = new factura_proveedor();
        $alb = $albaranes[0];

        $factura->codagente = empty($alb->codagente) ? $this->user->codagente : $alb->codagente;
        $factura->codalmacen = $alb->codalmacen;
        $factura->coddivisa = $alb->coddivisa;
        $factura->tasaconv = $alb->tasaconv;
        $factura->codpago = $alb->codpago;
        $factura->codserie = $alb->codserie;
        $factura->irpf = $alb->irpf;

        if (count($albaranes) == 1) {
            $factura->observaciones = $alb->observaciones;
        }

        $proveedor_model = new proveedor();
        $proveedor = $proveedor_model->get($alb->codproveedor);
        if ($proveedor) {
            $factura->cifnif = $proveedor->cifnif;
            $factura->codproveedor = $proveedor->codproveedor;
            $factura->nombre = $proveedor->razonsocial;
        }

        return $factura;
    }

    /**
     * @return object|null El ejercicio asignado, o null si no se encontró
     */
    private function applyFacturaProveedorEjercicioYPago(object $factura, string $fecha): ?object
    {
        $ejercicio_model = new ejercicio();
        $ejercicio = $ejercicio_model->get_by_fecha($fecha);
        if ($ejercicio) {
            $factura->codejercicio = $ejercicio->codejercicio;
            $factura->set_fecha_hora($fecha, $factura->hora);
        }

        $forma_pago_model = new forma_pago();
        $forma_pago = $forma_pago_model->get($factura->codpago);
        if ($forma_pago && $forma_pago->genrecibos == 'Pagados') {
            $factura->pagada = TRUE;
        }

        return $ejercicio;
    }

    private function saveFacturaProveedorLines(object $factura, array $albaranes): bool
    {
        foreach ($albaranes as $alb) {
            foreach ($alb->get_lineas() as $l) {
                $n = new linea_factura_proveedor();
                $n->idalbaran = $alb->idalbaran;
                $n->idlineaalbaran = $l->idlinea;
                $n->idfactura = $factura->idfactura;
                $n->cantidad = $l->cantidad;
                $n->codimpuesto = $l->codimpuesto;
                $n->descripcion = $l->descripcion;
                $n->dtopor = $l->dtopor;
                $n->irpf = $l->irpf;
                $n->iva = $l->iva;
                $n->pvpsindto = $l->pvpsindto;
                $n->pvptotal = $l->pvptotal;
                $n->pvpunitario = $l->pvpunitario;
                $n->recargo = $l->recargo;
                $n->referencia = $l->referencia;
                $n->codcombinacion = $l->codcombinacion;

                if (!$n->save()) {
                    $this->new_error_msg("¡Imposible guardar la línea el artículo " . $n->referencia . "! ");
                    return false;
                }
            }
        }

        return true;
    }

    private function finalizeFacturaProveedorTotales(object $factura, array $albaranes): bool
    {
        foreach ($this->fbase_get_subtotales_documento($factura->get_lineas()) as $subt) {
            $factura->neto += $subt['neto'];
            $factura->totaliva += $subt['iva'];
            $factura->totalirpf += $subt['irpf'];
            $factura->totalrecargo += $subt['recargo'];
        }

        $factura->total = round($factura->neto + $factura->totaliva - $factura->totalirpf + $factura->totalrecargo, FS_NF0);
        if (!$factura->save()) {
            $this->new_error_msg("¡Imposible guardar los totales de la factura!");
            return false;
        }

        foreach ($albaranes as $alb) {
            $alb->idfactura = $factura->idfactura;
            $alb->ptefactura = FALSE;

            if (!$alb->save()) {
                $this->new_error_msg("¡Imposible vincular el " . FS_ALBARAN . " con la nueva factura!");
                return false;
            }
        }

        return true;
    }

    /**
     * Valida que el ejercicio exista, esté abierto y no haya regularización de IVA.
     */
    private function validateFacturaEjercicio(?object $ejercicio, object $factura): bool
    {
        if (!$ejercicio) {
            $this->new_error_msg("Ejercicio no encontrado o está cerrado.");
            return false;
        }

        if (!$ejercicio->abierto()) {
            $this->new_error_msg('El ejercicio ' . $ejercicio->codejercicio . ' está cerrado.');
            return false;
        }

        $regularizacion = new regularizacion_iva();
        if ($regularizacion->get_fecha_inside($factura->fecha)) {
            $this->new_error_msg('El ' . FS_IVA . ' de ese periodo ya ha sido regularizado. No se pueden añadir más facturas en esa fecha.');
            return false;
        }

        return true;
    }

    private function rollbackFacturaOnFailure(object $factura): void
    {
        if ($factura->delete()) {
            $this->new_error_msg(self::MSG_FACTURA_BORRADA);
        } else {
            $this->new_error_msg(self::MSG_FACTURA_NO_BORRADA);
        }
    }

    /**
     * 
     * @param aobject $factura
     * @param bool    $mensaje
     *
     * @return bool
     */
    protected function fbase_generar_asiento(&$factura, $mensaje = true)
    {
        if ($this->empresa->contintegrada) {
            $asiento_factura = new asiento_factura();

            $ok = FALSE;
            if (get_class_name($factura) == 'factura_cliente') {
                $ok = $asiento_factura->generar_asiento_venta($factura);
            } else if (get_class_name($factura) == 'factura_proveedor') {
                $ok = $asiento_factura->generar_asiento_compra($factura);
            }
        } else {
            /// de todas formas forzamos la generación de las líneas de iva
            $factura->get_lineas_iva();
            $ok = TRUE;
        }

        if ($mensaje && $ok) {
            $this->new_message("<a href='" . $factura->url() . "'>Factura</a> generada correctamente.");
        }

        return $ok;
    }
}
