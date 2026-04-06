<?php
/**
 * This file is part of facturacion_base
 * Copyright (C) 2012-2019 Carlos Garcia Gomez <neorazorx@gmail.com>
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
namespace FSFramework\model;

/**
 * Almacena los datos de un artículos.
 * 
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class articulo extends \fs_model
{

    /**
     * Clave primaria. Varchar (18).
     * @var string 
     */
    public $referencia;

    /**
     * Define el tipo de artículo, así se pueden establecer distinciones
     * según un tipo u otro.
     * @var string Varchar (10).
     */
    public $tipo;

    /**
     * Código de la familia a la que pertenece. En la clase familia.
     * @var string 
     */
    public $codfamilia;

    /**
     * Descripción del artículo. Tipo text, sin límite de caracteres.
     * @var string 
     */
    public $descripcion;

    /**
     * Código del fabricante al que pertenece. En la clase fabricante.
     * @var string 
     */
    public $codfabricante;

    /**
     * Precio del artículo, sin impuestos.
     * @var double
     */
    public $pvp;

    /**
     * Almacena el valor del pvp antes de hacer el cambio.
     * Esta valor no se almacena en la base de datos, es decir,
     * no se recuerda.
     * @var double
     */
    public $pvp_ant;

    /**
     * Fecha de actualización del pvp.
     * @var string 
     */
    public $factualizado;

    /**
     * Coste medio al comprar el artículo. Calculado.
     * @var double
     */
    public $costemedio;

    /**
     * Precio de coste editado manualmente.
     * No necesariamente es el precio de compra, puede incluir
     * también otros costes.
     * @var double
     */
    public $preciocoste;

    /**
     * Impuesto asignado. Clase impuesto.
     * @var string 
     */
    public $codimpuesto;

    /**
     * TRUE => el artículos está bloqueado / obsoleto.
     * @var boolean 
     */
    public $bloqueado;
    public $secompra;
    public $sevende;

    /**
     * TRUE -> se mostrará sincronizará con la tienda online.
     * @var boolean 
     */
    public $publico;

    /**
     * Código de equivalencia. Varchar (18).
     * Dos artículos o más son equivalentes si tienen el mismo código de equivalencia.
     * @var string 
     */
    public $equivalencia;

    /**
     * Partnumber del producto. Máximo 38 caracteres.
     * @var string 
     */
    public $partnumber;

    /**
     * Stock físico. La suma de las cantidades de esta referencia que en la tabla stocks.
     * @var double 
     */
    public $stockfis;
    public $stockmin;
    public $stockmax;

    /**
     * TRUE -> permitir ventas sin stock.
     * Si, sé que no tiene sentido que poner controlstock a TRUE
     * implique la ausencia de control de stock. Pero es una cagada de
     * FacturaLux -> Abanq -> Eneboo, y por motivos de compatibilidad
     * se mantiene.
     * @var boolean 
     */
    public $controlstock;

    /**
     * TRUE -> no controlar el stock.
     * Activarlo implica poner a TRUE $controlstock;
     * @var boolean 
     */
    public $nostock;

    /**
     * Código de barras.
     * @var string 
     */
    public $codbarras;
    public $observaciones;

    /**
     * Código de la subcuenta para compras.
     * @var string 
     */
    public $codsubcuentacom;

    /**
     * Código para la subcuenta de compras, pero con IRPF.
     * @var string 
     */
    public $codsubcuentairpfcom;

    /**
     * Control de trazabilidad.
     * @var boolean 
     */
    public $trazabilidad;

    /**
     * % IVA del impuesto asignado.
     * @var double 
     */
    private $iva;
    private $imagen;
    private $exists;

    private const IMAGES_PATH = 'images/articulos/';
    private const IMG_SUFFIX_PNG = '-1.png';
    private const IMG_SUFFIX_JPG = '-1.jpg';
    private const ORDER_BY_REF = ' ORDER BY lower(referencia) ASC';
    private const ERR_STOCK_UPDATE = '¡Error al actualizar el stock del artículo!';
    private const SQL_SELECT_ALL = 'SELECT * FROM ';
    private const SQL_UPDATE = 'UPDATE ';
    private const PK_REFERENCIA = ' WHERE referencia = ';

    private static $impuestos;
    private static $search_tags;
    private static $cleaned_cache;
    private static $column_list;

    public function __construct($data = FALSE)
    {
        parent::__construct('articulos');

        if (!isset(self::$impuestos)) {
            self::$impuestos = array();
            self::$column_list = 'referencia,codfamilia,codfabricante,descripcion,pvp,factualizado,costemedio,' .
                'preciocoste,codimpuesto,stockfis,stockmin,stockmax,controlstock,nostock,bloqueado,' .
                'secompra,sevende,equivalencia,codbarras,observaciones,imagen,publico,tipo,' .
                'partnumber,codsubcuentacom,codsubcuentairpfcom,trazabilidad';
        }

        if ($data) {
            $this->referencia = $data['referencia'];
            $this->tipo = $data['tipo'];
            $this->codfamilia = $data['codfamilia'];
            $this->codfabricante = $data['codfabricante'];
            $this->descripcion = $this->hydrateStoredHtmlField($data['descripcion']);
            $this->pvp = floatval($data['pvp']);
            $this->factualizado = Date('d-m-Y', strtotime($data['factualizado']));
            $this->costemedio = floatval($data['costemedio']);
            $this->preciocoste = floatval($data['preciocoste']);
            $this->codimpuesto = $data['codimpuesto'];
            $this->stockfis = floatval($data['stockfis']);
            $this->stockmin = floatval($data['stockmin']);
            $this->stockmax = floatval($data['stockmax']);

            $this->controlstock = $this->str2bool($data['controlstock']);
            $this->nostock = $this->str2bool($data['nostock']);
            if ($this->nostock) {
                $this->controlstock = TRUE;
            }

            $this->bloqueado = $this->str2bool($data['bloqueado']);
            $this->secompra = $this->str2bool($data['secompra']);
            $this->sevende = $this->str2bool($data['sevende']);
            $this->publico = $this->str2bool($data['publico']);
            $this->equivalencia = $data['equivalencia'];
            $this->partnumber = $data['partnumber'];
            $this->codbarras = $data['codbarras'];
            $this->observaciones = $this->hydrateStoredHtmlField($data['observaciones']);
            $this->codsubcuentacom = $data['codsubcuentacom'];
            $this->codsubcuentairpfcom = $data['codsubcuentairpfcom'];
            $this->trazabilidad = $this->str2bool($data['trazabilidad']);
            $this->imagen = NULL;
            $this->exists = TRUE;
        } else {
            $this->referencia = NULL;
            $this->tipo = NULL;
            $this->codfamilia = NULL;
            $this->codfabricante = NULL;
            $this->descripcion = '';
            $this->pvp = 0.0;
            $this->factualizado = Date('d-m-Y');
            $this->costemedio = 0.0;
            $this->preciocoste = 0.0;
            $this->codimpuesto = NULL;
            $this->stockfis = 0.0;
            $this->stockmin = 0.0;
            $this->stockmax = 0.0;
            $this->controlstock = (bool) FS_VENTAS_SIN_STOCK;
            $this->nostock = FALSE;
            $this->bloqueado = FALSE;
            $this->secompra = TRUE;
            $this->sevende = TRUE;
            $this->publico = FALSE;
            $this->equivalencia = NULL;
            $this->partnumber = NULL;
            $this->codbarras = '';
            $this->observaciones = '';
            $this->codsubcuentacom = NULL;
            $this->codsubcuentairpfcom = NULL;
            $this->trazabilidad = FALSE;
            $this->imagen = NULL;
            $this->exists = FALSE;
        }

        $this->pvp_ant = 0.0;
        $this->iva = NULL;
    }

    protected function install()
    {
        /**
         * Limpiamos la caché por si el usuario ha borrado la tabla, pero ya tenía búsquedas.
         */
        $this->clean_cache();

        /**
         * La tabla articulos tiene varias claves ajenas, por eso debemos forzar la comprobación de esas tablas.
         */
        $fabricante = new \fabricante();
        $familia = new \familia();
        $impuesto = new \impuesto();
        // Las instancias se crean para forzar la comprobación de las tablas de claves ajenas

        return '';
    }

    public function descripcion($len = 120)
    {
        if (mb_strlen($this->descripcion, 'UTF8') > $len) {
            return mb_substr($this->descripcion, 0, $len) . '...';
        }

        return $this->descripcion;
    }

    public function pvp_iva()
    {
        return $this->pvp * (100 + $this->get_iva()) / 100;
    }

    /**
     * Devuelve el precio de coste, ya esté configurado como calculado o editable.
     * @return double
     */
    public function preciocoste()
    {
        if ($this->secompra && FS_COST_IS_AVERAGE) {
            return $this->costemedio;
        }

        return $this->preciocoste;
    }

    public function preciocoste_iva()
    {
        return $this->preciocoste() * (100 + $this->get_iva()) / 100;
    }

    /**
     * Devuelve la url donde ver/modificar estos datos
     * @return string
     */
    public function url()
    {
        if (is_null($this->referencia)) {
            return "index.php?page=ventas_articulos";
        }

        return "index.php?page=ventas_articulo&ref=" . urlencode($this->referencia);
    }

    /**
     * Devuelve la referencia codificada para poder ser usada en imágenes.
     * Evitamos así errores con caracteres especiales como / y \.
     * @param string $ref
     * @return string
     */
    public function image_ref($ref = FALSE)
    {
        if ($ref === FALSE) {
            $ref = $this->referencia;
        }

        return str_replace(array('/', '\\'), array('_', '_'), $ref);
    }

    /**
     * Devuelve una nueva referencia, la siguiente a la última de la base de datos.
     * @return string
     */
    public function get_new_referencia()
    {
        if (strtolower(FS_DB_TYPE) == 'postgresql') {
            $sql = "SELECT referencia from " . $this->table_name . " where referencia ~ '^\d+$'"
                . " ORDER BY referencia::bigint DESC";
        } else {
            $sql = "SELECT referencia from " . $this->table_name . " where referencia REGEXP '^[0-9]+$'"
                . " ORDER BY ABS(referencia) DESC";
        }

        $ref = 1;
        $data = $this->db->select_limit($sql, 1, 0);
        if ($data) {
            $ref = sprintf(1 + intval($data[0]['referencia']));
        }

        $this->exists = FALSE;
        return (string) $ref;
    }

    /**
     * Devuelve un artículo a partir de su referencia
     * @param string $ref
     * @return boolean|\articulo
     */
    public function get($ref)
    {
        $data = $this->db->select("SELECT " . self::$column_list . " FROM " . $this->table_name . self::PK_REFERENCIA . $this->var2str($ref) . ";");
        if ($data) {
            return new \articulo($data[0]);
        }

        return FALSE;
    }

    /**
     * Devuelve la familia del artículo.
     * @return \familia|false
     */
    public function get_familia()
    {
        if (is_null($this->codfamilia)) {
            return FALSE;
        }

        $fam = new \familia();
        return $fam->get($this->codfamilia);
    }

    /**
     * Devuelve el fabricante del artículo.
     * @return \fabricante|false
     */
    public function get_fabricante()
    {
        if (is_null($this->codfabricante)) {
            return FALSE;
        }

        $fab = new \fabricante();
        return $fab->get($this->codfabricante);
    }

    public function get_stock()
    {
        if ($this->nostock) {
            return array();
        }

        $stock = new \stock();
        return $stock->all_from_articulo($this->referencia);
    }

    /**
     * Devuelve el impuesto del artículo
     * @return impuesto
     */
    public function get_impuesto()
    {
        $imp = new \impuesto();
        return $imp->get($this->codimpuesto);
    }

    /**
     * Devuelve el % de IVA del artículo.
     * Si $reload es TRUE, vuelve a consultarlo en lugar de usar los datos cargados.
     * @param boolean $reload
     * @return double
     */
    public function get_iva($reload = FALSE)
    {
        if ($reload) {
            $this->iva = NULL;
        }

        if (is_null($this->iva)) {
            $this->iva = 0.0;

            if (!is_null($this->codimpuesto)) {
                $encontrado = FALSE;
                foreach (self::$impuestos as $i) {
                    if ($i->codimpuesto == $this->codimpuesto) {
                        $this->iva = $i->iva;
                        $encontrado = TRUE;
                        break;
                    }
                }
                if (!$encontrado) {
                    $imp = new \impuesto();
                    $imp0 = $imp->get($this->codimpuesto);
                    if ($imp0) {
                        $this->iva = $imp0->iva;
                        self::$impuestos[] = $imp0;
                    }
                }
            }
        }

        return $this->iva;
    }

    /**
     * Devuelve un array con los artículos que tengan el mismo código de
     * equivalencia que el artículo.
     * @return \articulo
     */
    public function get_equivalentes()
    {
        $artilist = array();

        if (isset($this->equivalencia)) {
            $data = $this->db->select("SELECT " . self::$column_list . " FROM " . $this->table_name .
                " WHERE equivalencia = " . $this->var2str($this->equivalencia) . " ORDER BY referencia ASC;");
            if ($data) {
                foreach ($data as $d) {
                    if ($d['referencia'] != $this->referencia) {
                        $artilist[] = new \articulo($d);
                    }
                }
            }
        }

        return $artilist;
    }

    /**
     * Devuelve las últimas líneas de albaranes de clientes con este artículo.
     * @deprecated since version 106
     * @param integer $offset
     * @param integer $limit
     * @return linea_albaran_cliente
     */
    public function get_lineas_albaran_cli($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $linea = new \linea_albaran_cliente();
        return $linea->all_from_articulo($this->referencia, $offset, $limit);
    }

    /**
     * Devuelve las últimas líneas de albaranes de proveedores con este artículo.
     * @deprecated since version 106
     * @param integer $offset
     * @param integer $limit
     * @return linea_albaran_proveedor
     */
    public function get_lineas_albaran_prov($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $linea = new \linea_albaran_proveedor();
        return $linea->all_from_articulo($this->referencia, $offset, $limit);
    }

    /**
     * Devuelve la media del precio de compra del artículo en los últimos albaranes o facturas.
     * @return double
     */
    public function get_costemedio()
    {
        $coste = 0;
        $media = [];
        $stock = 0;

        /// obtenemos las últimas líneas de facturas con este artículo
        $lfp = new \linea_factura_proveedor();
        $lineasfac = $lfp->all_from_articulo($this->referencia);

        /// obtenemos las últimas líneas de albaranes con este artículo
        $lap = new \linea_albaran_proveedor();
        $lineasalb = $lap->all_from_articulo($this->referencia);

        /**
         * Ahora comprobamos la fecha del primer elemento de una y otra lista
         * para ver cual usamos.
         */
        if ($lineasfac && $lineasalb && strtotime($lineasalb[0]->show_fecha()) > strtotime($lineasfac[0]->show_fecha())) {
            /**
             * la fecha del último albarán es posterior a la de la última factura.
             * Usamos los albaranes para el cálculo.
             */
            foreach ($lineasalb as $linea) {
                $media[] = empty($linea->cantidad) ? 0 : abs($linea->pvptotal / $linea->cantidad);
                if ($stock < $this->stockfis) {
                    $coste += $linea->pvptotal;
                    $stock += $linea->cantidad;
                }
            }
        }

        if (!empty($lineasfac)) {
            /// usamos las facturas para el cálculo.
            foreach ($lineasfac as $linea) {
                $media[] = empty($linea->cantidad) ? 0 : abs($linea->pvptotal / $linea->cantidad);
                if ($stock < $this->stockfis) {
                    $coste += $linea->pvptotal;
                    $stock += $linea->cantidad;
                }
            }
        }

        if (!empty($lineasalb)) {
            /// usamos los albaranes para el cálculo.
            foreach ($lineasalb as $linea) {
                $media[] = empty($linea->cantidad) ? 0 : abs($linea->pvptotal / $linea->cantidad);
                if ($stock < $this->stockfis) {
                    $coste += $linea->pvptotal;
                    $stock += $linea->cantidad;
                }
            }
        }

        /// evitamos división por cero o costes negativos
        if ($stock > 0 && $coste > 0) {
            return (float) $coste / $stock;
        }

        return empty($media) ? 0.0 : (float) array_sum($media) / count($media);
    }

    /**
     * Devuelve la url relativa de la imagen del artículo.
     * @return boolean
     */
    public function imagen_url()
    {
        if (file_exists(FS_MYDOCS . self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_PNG)) {
            return self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_PNG;
        } else if (file_exists(FS_MYDOCS . self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_JPG)) {
            return self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_JPG;
        }

        return FALSE;
    }

    /**
     * Asigna una imagen a un artículo.
     * @param string $img
     * @param boolean $png
     */
    public function set_imagen($img, $png = TRUE)
    {
        $this->imagen = NULL;

        if (file_exists(FS_MYDOCS . self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_PNG)) {
            unlink(FS_MYDOCS . self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_PNG);
        } else if (file_exists(FS_MYDOCS . self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_JPG)) {
            unlink(FS_MYDOCS . self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_JPG);
        }

        if ($img) {
            if (!file_exists(FS_MYDOCS . rtrim(self::IMAGES_PATH, '/'))) {
                @mkdir(FS_MYDOCS . rtrim(self::IMAGES_PATH, '/'), 0777, TRUE);
            }

            if ($png) {
                $f = @fopen(FS_MYDOCS . self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_PNG, 'a');
            } else {
                $f = @fopen(FS_MYDOCS . self::IMAGES_PATH . $this->image_ref() . self::IMG_SUFFIX_JPG, 'a');
            }

            if ($f) {
                fwrite($f, $img);
                fclose($f);
            }
        }
    }

    public function set_pvp($p)
    {
        $p = bround($p, FS_NF0_ART);

        if (!$this->floatcmp($this->pvp, $p, FS_NF0_ART + 2)) {
            $this->pvp_ant = $this->pvp;
            $this->factualizado = Date('d-m-Y');
            $this->pvp = $p;
        }
    }

    public function set_pvp_iva($p)
    {
        $this->set_pvp((100 * $p) / (100 + $this->get_iva()));
    }

    /**
     * Cambia la referencia del artículo.
     * Lo hace en el momento, no hace falta hacer save().
     * @param string $ref
     */
    public function set_referencia($ref)
    {
        $ref = trim($ref);
        if (is_null($ref) || strlen($ref) < 1 || strlen($ref) > 18) {
            $this->new_error_msg("¡Referencia de artículo no válida! Debe tener entre 1 y 18 caracteres.");
            return false;
        }

        if (is_null($this->referencia) || $ref == $this->referencia) {
            /// nada que hacer
            return false;
        }

        $sql = self::SQL_UPDATE . $this->table_name . " SET referencia = " . $this->var2str($ref)
            . self::PK_REFERENCIA . $this->var2str($this->referencia) . ";";
        if ($this->db->exec($sql)) {
            /// renombramos la imagen, si la hay
            $img_path = FS_MYDOCS . self::IMAGES_PATH;
            if (file_exists($img_path . $this->image_ref() . self::IMG_SUFFIX_PNG)) {
                rename($img_path . $this->image_ref() . self::IMG_SUFFIX_PNG, $img_path . $this->image_ref($ref) . self::IMG_SUFFIX_PNG);
            } elseif (file_exists($img_path . $this->image_ref() . self::IMG_SUFFIX_JPG)) {
                rename($img_path . $this->image_ref() . self::IMG_SUFFIX_JPG, $img_path . $this->image_ref($ref) . self::IMG_SUFFIX_JPG);
            }

            $this->referencia = $ref;
            $this->exists = FALSE;
            return true;
        }

        $this->new_error_msg('Imposible modificar la referencia.');
        return false;
    }

    /**
     * Cambia el impuesto asociado al artículo.
     * @param string $codimpuesto
     */
    public function set_impuesto($codimpuesto)
    {
        if ($codimpuesto != $this->codimpuesto) {
            $this->codimpuesto = $codimpuesto;

            $encontrado = FALSE;
            foreach (self::$impuestos as $i) {
                if ($i->codimpuesto == $this->codimpuesto) {
                    $this->iva = floatval($i->iva);
                    $encontrado = TRUE;
                    break;
                }
            }
            if (!$encontrado) {
                $imp = new \impuesto();
                $imp0 = $imp->get($this->codimpuesto);
                if ($imp0) {
                    $this->iva = floatval($imp0->iva);
                    self::$impuestos[] = $imp0;
                } else {
                    $this->iva = 0.0;
                }
            }
        }
    }

    /**
     * Modifica el stock del artículo en un almacén concreto.
     * Ya se encarga de ejecutar save() si es necesario.
     * @param string $codalmacen
     * @param double $cantidad
     * @return boolean
     */
    public function set_stock($codalmacen, $cantidad = 1)
    {
        if ($this->nostock) {
            return true;
        }

        $stock = new \stock();
        $result = $this->updateOrCreateStockRow($stock, $codalmacen, $cantidad, false);

        if (!$result) {
            $this->new_error_msg("Error al guardar el stock");
            return false;
        }

        $nuevo_stock = $stock->total_from_articulo($this->referencia);
        if ($this->stockfis != $nuevo_stock) {
            $this->stockfis = $nuevo_stock;
            return $this->persistStockFields(false);
        }

        return true;
    }

    /**
     * Suma la cantidad especificada al stock del artículo en el almacén especificado.
     * Ya se encarga de ejecutar save() si es necesario.
     * @param string $codalmacen
     * @param double $cantidad
     * @param boolean $recalcula_coste
     * @param string $codcombinacion
     * @return boolean
     */
    public function sum_stock($codalmacen, $cantidad = 1, $recalcula_coste = FALSE, $codcombinacion = NULL)
    {
        if ($recalcula_coste) {
            $this->costemedio = $this->get_costemedio();
        }

        if ($this->nostock) {
            return $this->handleNoStockCostUpdate($recalcula_coste);
        }

        $stock = new \stock();
        $result = $this->updateOrCreateStockRow($stock, $codalmacen, $cantidad, true);

        if (!$result) {
            $this->new_error_msg("¡Error al guardar el stock!");
            return false;
        }

        return $this->persistArticleStockAndCost($stock, $codcombinacion, $cantidad);
    }

    private function handleNoStockCostUpdate(bool $recalcula_coste): bool
    {
        if (!$recalcula_coste) {
            return true;
        }

        if ($this->exists) {
            $this->clean_cache();
            return $this->db->exec(self::SQL_UPDATE . $this->table_name
                . " SET costemedio = " . $this->var2str($this->costemedio)
                . self::PK_REFERENCIA . $this->var2str($this->referencia) . ";");
        }

        if (!$this->save()) {
            $this->new_error_msg(self::ERR_STOCK_UPDATE);
            return false;
        }

        return true;
    }

    /**
     * @return boolean
     */
    private function updateOrCreateStockRow(\stock $stock, string $codalmacen, float $cantidad, bool $isSum): bool
    {
        $stocks = $stock->all_from_articulo($this->referencia);
        foreach ($stocks as $k => $value) {
            if ($value->codalmacen == $codalmacen) {
                $isSum ? $stocks[$k]->sum_cantidad($cantidad) : $stocks[$k]->set_cantidad($cantidad);
                return $stocks[$k]->save();
            }
        }

        $stock->referencia = $this->referencia;
        $stock->codalmacen = $codalmacen;
        $stock->set_cantidad($cantidad);
        return $stock->save();
    }

    private function persistArticleStockAndCost(\stock $stock, ?string $codcombinacion, float $cantidad): bool
    {
        $nuevo_stock = $stock->total_from_articulo($this->referencia);
        if ($this->stockfis == $nuevo_stock) {
            return true;
        }

        $this->stockfis = $nuevo_stock;
        $result = $this->persistStockFields(true);

        if ($codcombinacion && $result) {
            $com0 = new \articulo_combinacion();
            foreach ($com0->all_from_codigo($codcombinacion) as $combi) {
                $combi->stockfis += $cantidad;
                $combi->save();
            }
        }

        return $result;
    }

    private function persistStockFields(bool $includeCost): bool
    {
        if ($this->exists) {
            $this->clean_cache();
            $sql = self::SQL_UPDATE . $this->table_name . " SET stockfis = " . $this->var2str($this->stockfis);
            if ($includeCost) {
                $sql .= ", costemedio = " . $this->var2str($this->costemedio);
            }
            $sql .= self::PK_REFERENCIA . $this->var2str($this->referencia) . ";";
            return $this->db->exec($sql);
        }

        if (!$this->save()) {
            $this->new_error_msg(self::ERR_STOCK_UPDATE);
            return false;
        }

        return true;
    }

    /**
     * Esta función devuelve TRUE si el artículo ya existe en la base de datos.
     * Por motivos de rendimiento y al ser esta una clase de uso intensivo,
     * se utiliza la variable $this->exists para almacenar el resultado.
     * @return boolean
     */
    public function exists()
    {
        if (!$this->exists) {
            if ($this->db->select("SELECT referencia FROM " . $this->table_name . self::PK_REFERENCIA . $this->var2str($this->referencia) . ";")) {
                $this->exists = TRUE;
            }
        }

        return $this->exists;
    }

    /**
     * Devuelve TRUE  si los datos del artículo son correctos.
     * @return boolean
     */
    public function test()
    {
        $this->sanitizeFields();
        $this->applyStockRules();

        if ($this->bloqueado) {
            $this->publico = FALSE;
        }

        return $this->validateReferenciaAndEquivalencia();
    }

    private function sanitizeFields(): void
    {
        $this->descripcion = $this->sanitizeHtmlFieldForPersistence($this->descripcion);
        $this->codbarras = $this->no_html($this->codbarras);
        $this->observaciones = $this->sanitizeHtmlFieldForPersistence($this->observaciones);

        if ($this->equivalencia === '') {
            $this->equivalencia = NULL;
        }
    }

    /**
     * Los textos persistidos pueden venir escapados con no_html(); fs_fix_html()
     * los normaliza al hidratar descripcion y observaciones para uso interno.
     */
    private function hydrateStoredHtmlField(?string $value): string
    {
        return fs_fix_html((string) $value);
    }

    /**
     * test() y save() deben seguir la misma ruta de saneamiento: primero
     * normalizar entidades previas con fs_fix_html() y después escapar con
     * no_html() para evitar XSS y dobles escapes al persistir.
     */
    private function sanitizeHtmlFieldForPersistence(?string $value): string
    {
        return $this->no_html($this->hydrateStoredHtmlField($value));
    }

    private function applyStockRules(): void
    {
        if (!$this->nostock) {
            return;
        }

        $this->controlstock = TRUE;
        $this->stockfis = 0.0;
        $this->stockmax = 0.0;
        $this->stockmin = 0.0;
    }

    private function validateReferenciaAndEquivalencia(): bool
    {
        if (is_null($this->referencia) || strlen($this->referencia) < 1 || strlen($this->referencia) > 18) {
            $this->new_error_msg("Referencia de artículo no válida: " . $this->referencia . ". Debe tener entre 1 y 18 caracteres.");
            return false;
        }

        if (isset($this->equivalencia) && strlen($this->equivalencia) > 25) {
            $this->new_error_msg("Código de equivalencia del artículos no válido: " . $this->equivalencia .
                ". Debe tener entre 1 y 25 caracteres.");
            return false;
        }

        return true;
    }

    /**
     * Guarda en la base de datos los datos del artículo.
     * @return boolean
     */
    public function save()
    {
        if (!$this->test()) {
            return FALSE;
        }

        $this->clean_cache();
        $sql = $this->exists() ? $this->buildUpdateSql() : $this->buildInsertSql();

        if ($this->db->exec($sql)) {
            $this->exists = TRUE;
            return TRUE;
        }

        return FALSE;
    }

    private function buildUpdateSql(): string
    {
        $sql = self::SQL_UPDATE . $this->table_name . " SET descripcion = " . $this->var2str($this->descripcion) .
            ", codfamilia = " . $this->var2str($this->codfamilia) .
            ", codfabricante = " . $this->var2str($this->codfabricante) .
            ", pvp = " . $this->var2str($this->pvp) .
            ", factualizado = " . $this->var2str($this->factualizado) .
            ", costemedio = " . $this->var2str($this->costemedio) .
            ", preciocoste = " . $this->var2str($this->preciocoste) .
            ", codimpuesto = " . $this->var2str($this->codimpuesto) .
            ", stockfis = " . $this->var2str($this->stockfis) .
            ", stockmin = " . $this->var2str($this->stockmin) .
            ", stockmax = " . $this->var2str($this->stockmax) .
            ", controlstock = " . $this->var2str($this->controlstock) .
            ", nostock = " . $this->var2str($this->nostock) .
            ", bloqueado = " . $this->var2str($this->bloqueado) .
            ", sevende = " . $this->var2str($this->sevende) .
            ", publico = " . $this->var2str($this->publico) .
            ", secompra = " . $this->var2str($this->secompra) .
            ", equivalencia = " . $this->var2str($this->equivalencia) .
            ", partnumber = " . $this->var2str($this->partnumber) .
            ", codbarras = " . $this->var2str($this->codbarras) .
            ", observaciones = " . $this->var2str($this->observaciones) .
            ", tipo = " . $this->var2str($this->tipo) .
            ", imagen = " . $this->var2str($this->imagen) .
            ", codsubcuentacom = " . $this->var2str($this->codsubcuentacom) .
            ", codsubcuentairpfcom = " . $this->var2str($this->codsubcuentairpfcom) .
            ", trazabilidad = " . $this->var2str($this->trazabilidad) .
            self::PK_REFERENCIA . $this->var2str($this->referencia) . ";";

        if ($this->nostock && $this->stockfis != 0) {
            $this->stockfis = 0.0;
            $sql .= "DELETE FROM stocks" . self::PK_REFERENCIA . $this->var2str($this->referencia) . ";";
            $sql .= self::SQL_UPDATE . $this->table_name . " SET stockfis = " . $this->var2str($this->stockfis) .
                self::PK_REFERENCIA . $this->var2str($this->referencia) . ";";
        }

        return $sql;
    }

    private function buildInsertSql(): string
    {
        return "INSERT INTO " . $this->table_name . " (" . self::$column_list . ") VALUES (" .
            $this->var2str($this->referencia) . "," .
            $this->var2str($this->codfamilia) . "," .
            $this->var2str($this->codfabricante) . "," .
            $this->var2str($this->descripcion) . "," .
            $this->var2str($this->pvp) . "," .
            $this->var2str($this->factualizado) . "," .
            $this->var2str($this->costemedio) . "," .
            $this->var2str($this->preciocoste) . "," .
            $this->var2str($this->codimpuesto) . "," .
            $this->var2str($this->stockfis) . "," .
            $this->var2str($this->stockmin) . "," .
            $this->var2str($this->stockmax) . "," .
            $this->var2str($this->controlstock) . "," .
            $this->var2str($this->nostock) . "," .
            $this->var2str($this->bloqueado) . "," .
            $this->var2str($this->secompra) . "," .
            $this->var2str($this->sevende) . "," .
            $this->var2str($this->equivalencia) . "," .
            $this->var2str($this->codbarras) . "," .
            $this->var2str($this->observaciones) . "," .
            $this->var2str($this->imagen) . "," .
            $this->var2str($this->publico) . "," .
            $this->var2str($this->tipo) . "," .
            $this->var2str($this->partnumber) . "," .
            $this->var2str($this->codsubcuentacom) . "," .
            $this->var2str($this->codsubcuentairpfcom) . "," .
            $this->var2str($this->trazabilidad) . ");";
    }

    /**
     * Elimina el artículo de la base de datos.
     * @return boolean
     */
    public function delete()
    {
        $this->clean_cache();

        $sql = "DELETE FROM articulosprov" . self::PK_REFERENCIA . $this->var2str($this->referencia) . ";";
        $sql .= "DELETE FROM " . $this->table_name . self::PK_REFERENCIA . $this->var2str($this->referencia) . ";";
        if ($this->db->exec($sql)) {
            $this->set_imagen(FALSE);
            $this->exists = FALSE;
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Comprueba y añade una cadena a la lista de búsquedas precargadas
     * en memcache. Devuelve TRUE si la cadena ya está en la lista de
     * precargadas.
     * @param string $tag
     * @return boolean
     */
    private function new_search_tag($tag)
    {
        $encontrado = FALSE;
        $actualizar = FALSE;

        if (strlen($tag) > 1) {
            /// obtenemos los datos de memcache
            $this->get_search_tags();

            foreach (self::$search_tags as $i => $value) {
                if ($value['tag'] == $tag) {
                    $encontrado = TRUE;
                    if (time() + 5400 > $value['expires'] + 300) {
                        self::$search_tags[$i]['count'] ++;
                        self::$search_tags[$i]['expires'] = time() + (self::$search_tags[$i]['count'] * 5400);
                        $actualizar = TRUE;
                    }
                    break;
                }
            }
            if (!$encontrado) {
                self::$search_tags[] = array('tag' => $tag, 'expires' => time() + 5400, 'count' => 1);
                $actualizar = TRUE;
            }

            if ($actualizar) {
                $this->cache->set('articulos_searches', self::$search_tags, 5400);
            }
        }

        return $encontrado;
    }

    public function get_search_tags()
    {
        if (!isset(self::$search_tags)) {
            self::$search_tags = $this->cache->get_array('articulos_searches');
        }

        return self::$search_tags;
    }

    private function clean_cache()
    {
        /*
         * Durante las actualizaciones masivas de artículos se ejecuta esta
         * función cada vez que se guarda un artículo, por eso es mejor limitarla.
         */
        if (!self::$cleaned_cache) {
            /// obtenemos los datos de memcache
            $this->get_search_tags();

            if (!empty(self::$search_tags)) {
                foreach (self::$search_tags as $value) {
                    $this->cache->delete('articulos_search_' . $value['tag']);
                }
            }

            self::$cleaned_cache = TRUE;
        }
    }

    /**
     * Devuelve un array con los artículos encontrados en base a la búsqueda.
     *
     * @param string $query
     * @param int    $offset
     * @param string $codfamilia
     * @param bool   $con_stock
     * @param string $codfabricante
     * @param bool   $bloqueados
     *
     * @return \articulo[]
     */
    public function search($query = '', $offset = 0, $codfamilia = '', $con_stock = FALSE, $codfabricante = '', $bloqueados = FALSE)
    {
        $query = $this->no_html(mb_strtolower($query, 'UTF8'));

        $artilist = $this->tryCacheSearch($query, $offset, $codfamilia, $codfabricante, $con_stock, $bloqueados);
        if (!empty($artilist)) {
            return $artilist;
        }

        $sql = "SELECT " . self::$column_list . " FROM " . $this->table_name;
        $separador = ' WHERE';

        $this->buildSearchWhereClause($sql, $separador, $codfamilia, $codfabricante, $con_stock, $bloqueados);
        $this->appendTextSearchConditions($sql, $separador, $query);

        if (strtolower(FS_DB_TYPE) == 'mysql') {
            $sql .= self::ORDER_BY_REF;
        } else {
            $sql .= " ORDER BY lower(referencia) ASC";
        }

        return $this->all_from($sql, $offset);
    }

    /**
     * @return \articulo[]
     */
    private function tryCacheSearch(string $query, int $offset, string $codfamilia, string $codfabricante, bool $con_stock, bool $bloqueados): array
    {
        if ($query === '' || $offset !== 0 || $codfamilia !== '' || $codfabricante !== '' || $con_stock || $bloqueados) {
            return [];
        }

        if ($this->new_search_tag($query)) {
            return $this->cache->get_array('articulos_search_' . $query);
        }

        return [];
    }

    private function buildSearchWhereClause(string &$sql, string &$separador, string $codfamilia, string $codfabricante, bool $con_stock, bool $bloqueados): void
    {
        if ($codfamilia !== '') {
            $sql .= $separador . " codfamilia = " . $this->var2str($codfamilia);
            $separador = ' AND';
        }

        if ($codfabricante !== '') {
            $sql .= $separador . " codfabricante = " . $this->var2str($codfabricante);
            $separador = ' AND';
        }

        if ($con_stock) {
            $sql .= $separador . " stockfis > 0";
            $separador = ' AND';
        }

        $sql .= $separador . ($bloqueados ? " bloqueado = TRUE" : " bloqueado = FALSE");
        $separador = ' AND';
    }

    private function appendTextSearchConditions(string &$sql, string $separador, string $query): void
    {
        if ($query === '') {
            return;
        }

        $escaped = $this->escapeForLike($query);

        if (is_numeric($query)) {
            $sql .= $separador . " (referencia = " . $this->var2str($query)
                . " OR referencia LIKE " . $this->var2str('%' . $escaped . '%') . " ESCAPE '|'"
                . " OR partnumber LIKE " . $this->var2str('%' . $escaped . '%') . " ESCAPE '|'"
                . " OR equivalencia LIKE " . $this->var2str('%' . $escaped . '%') . " ESCAPE '|'"
                . " OR descripcion LIKE " . $this->var2str('%' . $escaped . '%') . " ESCAPE '|'"
                . " OR codbarras = " . $this->var2str($query) . ")";
            return;
        }

        $palabras = explode(' ', $query);
        $baseConditions = " (lower(referencia) = " . $this->var2str($query)
            . " OR lower(referencia) LIKE " . $this->var2str('%' . $escaped . '%') . " ESCAPE '|'"
            . " OR lower(partnumber) LIKE " . $this->var2str('%' . $escaped . '%') . " ESCAPE '|'"
            . " OR lower(equivalencia) LIKE " . $this->var2str('%' . $escaped . '%') . " ESCAPE '|'";

        if (count($palabras) > 1) {
            $descParts = array_map(fn($pal) => "lower(descripcion) LIKE " . $this->var2str('%' . $this->escapeForLike($pal) . '%') . " ESCAPE '|'", $palabras);
            $sql .= $separador . $baseConditions . " OR (" . implode(' AND ', $descParts) . "))";
        } else {
            $sql .= $separador . $baseConditions
                . " OR lower(codbarras) = " . $this->var2str($query)
                . " OR lower(descripcion) LIKE " . $this->var2str('%' . $escaped . '%') . " ESCAPE '|')";
        }
    }

    private function escapeForLike(string $s): string
    {
        return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $s);
    }

    private function all_from($sql, $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $artilist = array();
        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $a) {
                $artilist[] = new \articulo($a);
            }
        }

        return $artilist;
    }

    /**
     * Devuelve un array con los artículos que tengan $cod como código de barras.
     *
     * @param string $cod
     * @param int    $offset
     * @param int    $limit
     *
     * @return \articulo[]
     */
    public function search_by_codbar($cod, $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $sql = "SELECT " . self::$column_list . " FROM " . $this->table_name
            . " WHERE codbarras = " . $this->var2str($cod)
            . self::ORDER_BY_REF;

        return $this->all_from($sql, $offset, $limit);
    }

    /**
     * Devuelve el listado de artículos desde el resultado $offset hasta $offset+$limit.
     *
     * @param integer $offset desde
     * @param integer $limit nº de elementos devuelto
     *
     * @return \articulo[]
     */
    public function all($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $sql = "SELECT " . self::$column_list . " FROM " . $this->table_name
            . self::ORDER_BY_REF;

        return $this->all_from($sql, $offset, $limit);
    }

    /**
     * Devuelve el listado de artículos públicos, desde $offset hasta $offset+$limit
     *
     * @param integer $offset
     * @param integer $limit
     *
     * @return \articulo[]
     */
    public function all_publico($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $sql = "SELECT " . self::$column_list . " FROM " . $this->table_name
            . " WHERE publico" . self::ORDER_BY_REF;

        return $this->all_from($sql, $offset, $limit);
    }

    /**
     * Devuelve los artículos de una familia.
     *
     * @param string  $cod
     * @param integer $offset
     * @param integer $limit
     *
     * @return \articulo[]
     */
    public function all_from_familia($cod, $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $sql = "SELECT " . self::$column_list . " FROM " . $this->table_name . " WHERE codfamilia = "
            . $this->var2str($cod) . self::ORDER_BY_REF;

        return $this->all_from($sql, $offset, $limit);
    }

    /**
     * Devuelve los artículos de un fabricante.
     *
     * @param string  $cod
     * @param integer $offset
     * @param integer $limit
     *
     * @return \articulo[]
     */
    public function all_from_fabricante($cod, $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $sql = self::SQL_SELECT_ALL . $this->table_name . " WHERE codfabricante = "
            . $this->var2str($cod) . self::ORDER_BY_REF;

        return $this->all_from($sql, $offset, $limit);
    }

    public function cron_job()
    {
        /// aceleramos las búsquedas
        if ($this->get_search_tags()) {
            foreach (self::$search_tags as $i => $value) {
                if ($value['expires'] < time()) {
                    /// eliminamos las búsquedas antiguas
                    unset(self::$search_tags[$i]);
                } else if ($value['count'] > 1) {
                    /// guardamos los resultados de la búsqueda en memcache
                    $this->cache->set('articulos_search_' . $value['tag'], $this->search($value['tag']), 5400);
                    echo '.';
                }
            }

            /// guardamos en memcache la lista de búsquedas
            $this->cache->set('articulos_searches', self::$search_tags, 5400);
        }

        $this->fix_db();
    }

    /**
     * Realizamos algunas correcciones a la base de datos.
     */
    public function fix_db()
    {
        $this->db->exec(self::SQL_UPDATE . $this->table_name . " SET bloqueado = true WHERE bloqueado IS NULL;");
        $this->db->exec(self::SQL_UPDATE . $this->table_name . " SET nostock = false WHERE nostock IS NULL;");

        /// desvinculamos de fabricantes que no existan
        $this->db->exec(self::SQL_UPDATE . $this->table_name . " SET codfabricante = null WHERE codfabricante IS NOT NULL"
            . " AND codfabricante NOT IN (SELECT codfabricante FROM fabricantes);");

        /// desvinculamos de familias que no existan
        $this->db->exec(self::SQL_UPDATE . $this->table_name . " SET codfamilia = null WHERE codfamilia IS NOT NULL"
            . " AND codfamilia NOT IN (SELECT codfamilia FROM familias);");
    }
}
