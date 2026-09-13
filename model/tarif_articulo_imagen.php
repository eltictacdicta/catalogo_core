<?php
/**
 * This file is part of tarifario
 * Copyright (C) 2025 FSFramework Team
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
 * Imagen de un artículo del tarifario.
 * Las imágenes se almacenan en imgs/tarifario/ para persistir independientemente del plugin.
 */
class tarif_articulo_imagen extends \fs_model
{
    /**
     * Clave primaria. Autoincremental.
     * @var integer
     */
    public $id;

    /**
     * Referencia del artículo.
     * @var string
     */
    public $referencia;

    /**
     * Nombre del archivo de imagen.
     * @var string
     */
    public $nombre_archivo;

    /**
     * Si es la imagen destacada del artículo.
     * @var boolean
     */
    public $destacada;

    /**
     * Orden de la imagen.
     * @var integer
     */
    public $orden;

    /**
     * Fecha de subida.
     * @var string
     */
    public $fecha_subida;

    /**
     * Directorio donde se almacenan las imágenes (relativo a la raíz).
     */
    const IMAGES_DIR = 'imgs/tarifario/';

    private static $column_list = 'id,referencia,nombre_archivo,destacada,orden,fecha_subida';

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_articulo_imagenes');

        if ($data) {
            $this->id = intval($data['id']);
            $this->referencia = $data['referencia'];
            $this->nombre_archivo = $data['nombre_archivo'];
            $this->destacada = $this->str2bool($data['destacada']);
            $this->orden = intval($data['orden']);
            $this->fecha_subida = isset($data['fecha_subida']) ? $data['fecha_subida'] : date('Y-m-d H:i:s');
        } else {
            $this->id = NULL;
            $this->referencia = NULL;
            $this->nombre_archivo = NULL;
            $this->destacada = FALSE;
            $this->orden = 0;
            $this->fecha_subida = date('Y-m-d H:i:s');
        }
    }

    protected function install()
    {
        // Forzamos la creación de la tabla de articulos primero
        new \FSFramework\model\articulo();
        return '';
    }

    /**
     * Devuelve la ruta completa del directorio de imágenes.
     * @return string
     */
    public static function get_images_path()
    {
        // Usar ruta relativa desde el directorio de trabajo actual
        // Esto es más confiable que FS_FOLDER en entornos Docker/DDEV
        return self::IMAGES_DIR;
    }

    /**
     * Asegura que el directorio de imágenes existe.
     * @return boolean
     */
    public static function ensure_images_dir()
    {
        $path = self::get_images_path();
        
        if (!file_exists($path)) {
            $result = @mkdir($path, 0755, TRUE);
            if (!$result) {
                error_log("Tarifario: No se pudo crear directorio: " . $path . " - CWD: " . getcwd());
                return FALSE;
            }
        }
        
        if (!is_writable($path)) {
            error_log("Tarifario: Directorio no escribible: " . $path);
            return FALSE;
        }
        
        return is_dir($path);
    }

    /**
     * Devuelve la URL de la imagen.
     * @return string
     */
    public function url_imagen()
    {
        return self::IMAGES_DIR . $this->nombre_archivo;
    }

    /**
     * Devuelve la ruta completa del archivo.
     * @return string
     */
    public function ruta_archivo()
    {
        return self::get_images_path() . $this->nombre_archivo;
    }

    /**
     * Genera un nombre de archivo único para la imagen.
     * @param string $referencia
     * @param string $extension
     * @return string
     */
    public static function generar_nombre_archivo($referencia, $extension)
    {
        $ref_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $referencia);
        $timestamp = time();
        $random = substr(md5(uniqid()), 0, 8);
        return $ref_safe . '_' . $timestamp . '_' . $random . '.' . strtolower($extension);
    }

    /**
     * Obtiene una imagen por su ID.
     * @param integer $id
     * @return tarif_articulo_imagen|false
     */
    public function get($id)
    {
        $data = $this->db->select("SELECT " . self::$column_list . " FROM " . $this->table_name 
            . " WHERE id = " . $this->var2str($id) . ";");
        if ($data) {
            return new tarif_articulo_imagen($data[0]);
        }
        return FALSE;
    }

    /**
     * Obtiene todas las imágenes de un artículo.
     * @param string $referencia
     * @return array
     */
    public function all_from_articulo($referencia)
    {
        $list = [];
        $sql = "SELECT " . self::$column_list . " FROM " . $this->table_name 
            . " WHERE referencia = " . $this->var2str($referencia) 
            . " ORDER BY destacada DESC, orden ASC, id ASC";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_articulo_imagen($d);
            }
        }
        return $list;
    }

    /**
     * Obtiene la imagen destacada de un artículo.
     * @param string $referencia
     * @return tarif_articulo_imagen|false
     */
    public function get_destacada($referencia)
    {
        $sql = "SELECT " . self::$column_list . " FROM " . $this->table_name 
            . " WHERE referencia = " . $this->var2str($referencia) 
            . " AND destacada = TRUE LIMIT 1";
        $data = $this->db->select($sql);
        if ($data) {
            return new tarif_articulo_imagen($data[0]);
        }
        return FALSE;
    }

    /**
     * Cuenta las imágenes de un artículo.
     * @param string $referencia
     * @return integer
     */
    public function count_from_articulo($referencia)
    {
        $data = $this->db->select("SELECT COUNT(*) as total FROM " . $this->table_name 
            . " WHERE referencia = " . $this->var2str($referencia) . ";");
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    /**
     * Marca esta imagen como destacada y desmarca las demás del mismo artículo.
     * @return boolean
     */
    public function set_destacada()
    {
        // Primero desmarcamos todas las del artículo
        $sql = "UPDATE " . $this->table_name . " SET destacada = FALSE"
            . " WHERE referencia = " . $this->var2str($this->referencia) . ";";
        $this->db->exec($sql);

        // Marcamos esta como destacada
        $this->destacada = TRUE;
        return $this->save();
    }

    /**
     * Verifica si hay que auto-marcar como destacada (si es la única imagen).
     */
    private function auto_destacar()
    {
        if (!$this->destacada) {
            $count = $this->count_from_articulo($this->referencia);
            // Si es la primera imagen (count=0 porque aún no se ha guardado), será destacada
            if ($count == 0) {
                $this->destacada = TRUE;
            }
        }
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return FALSE;
        }
        return (bool) $this->db->select("SELECT id FROM " . $this->table_name 
            . " WHERE id = " . $this->var2str($this->id) . ";");
    }

    public function test()
    {
        if (is_null($this->referencia) || strlen($this->referencia) < 1) {
            $this->new_error_msg("Referencia de artículo no válida.");
            return FALSE;
        }

        if (is_null($this->nombre_archivo) || strlen($this->nombre_archivo) < 1) {
            $this->new_error_msg("Nombre de archivo no válido.");
            return FALSE;
        }

        return TRUE;
    }

    public function save()
    {
        if ($this->test()) {
            // Auto-destacar si es la primera imagen
            $this->auto_destacar();

            if ($this->exists()) {
                $sql = "UPDATE " . $this->table_name . " SET "
                    . "referencia = " . $this->var2str($this->referencia)
                    . ", nombre_archivo = " . $this->var2str($this->nombre_archivo)
                    . ", destacada = " . $this->var2str($this->destacada)
                    . ", orden = " . $this->var2str($this->orden)
                    . " WHERE id = " . $this->var2str($this->id) . ";";
            } else {
                $sql = "INSERT INTO " . $this->table_name 
                    . " (referencia,nombre_archivo,destacada,orden,fecha_subida) VALUES ("
                    . $this->var2str($this->referencia) . ","
                    . $this->var2str($this->nombre_archivo) . ","
                    . $this->var2str($this->destacada) . ","
                    . $this->var2str($this->orden) . ","
                    . $this->var2str($this->fecha_subida) . ");";
            }

            if ($this->db->exec($sql)) {
                if (is_null($this->id)) {
                    $this->id = $this->db->lastval();
                }
                return TRUE;
            }

            $this->new_error_msg('DB: ' . $this->db->get_error_msg());
        }
        return FALSE;
    }

    public function delete()
    {
        // Eliminar el archivo físico
        $ruta = $this->ruta_archivo();
        if (file_exists($ruta)) {
            @unlink($ruta);
        }

        $era_destacada = $this->destacada;
        $referencia = $this->referencia;

        $sql = "DELETE FROM " . $this->table_name . " WHERE id = " . $this->var2str($this->id) . ";";
        if ($this->db->exec($sql)) {
            // Si era la destacada, marcar la primera imagen restante como destacada
            if ($era_destacada) {
                $imagenes = $this->all_from_articulo($referencia);
                if (count($imagenes) > 0) {
                    $imagenes[0]->set_destacada();
                }
            }
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Sube una imagen desde $_FILES.
     * @param string $referencia
     * @param array $file Array de $_FILES
     * @return tarif_articulo_imagen|false
     */
    public static function upload($referencia, $file)
    {
        // Verificar que hay archivo temporal
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            error_log("Tarifario upload: No hay archivo temporal");
            return FALSE;
        }

        // Verificar que el archivo existe
        if (!file_exists($file['tmp_name'])) {
            error_log("Tarifario upload: El archivo temporal no existe: " . $file['tmp_name']);
            return FALSE;
        }

        // Validar tipo de archivo
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_types)) {
            error_log("Tarifario upload: Tipo MIME no permitido: " . $mime_type);
            return FALSE;
        }

        // Obtener extensión
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        $extension = $extensions[$mime_type];

        // Asegurar que el directorio existe
        if (!self::ensure_images_dir()) {
            error_log("Tarifario upload: No se pudo crear/acceder al directorio de imágenes");
            return FALSE;
        }

        // Generar nombre de archivo
        $nombre_archivo = self::generar_nombre_archivo($referencia, $extension);
        $ruta_destino = self::get_images_path() . $nombre_archivo;

        error_log("Tarifario upload: Intentando mover de " . $file['tmp_name'] . " a " . $ruta_destino);

        // Intentar mover el archivo - primero con move_uploaded_file
        $moved = false;
        if (is_uploaded_file($file['tmp_name'])) {
            $moved = @move_uploaded_file($file['tmp_name'], $ruta_destino);
            if (!$moved) {
                error_log("Tarifario upload: move_uploaded_file falló, intentando con copy()");
            }
        }
        
        // Si move_uploaded_file falla, intentar con copy() (útil en entornos Docker/DDEV)
        if (!$moved) {
            $moved = @copy($file['tmp_name'], $ruta_destino);
            if ($moved) {
                // Eliminar el archivo temporal si copy() funcionó
                @unlink($file['tmp_name']);
            }
        }
        
        // Si también falla copy(), intentar con file_put_contents
        if (!$moved) {
            $content = @file_get_contents($file['tmp_name']);
            if ($content !== false) {
                $moved = @file_put_contents($ruta_destino, $content) !== false;
            }
        }

        if (!$moved) {
            error_log("Tarifario upload: No se pudo copiar el archivo. Verificar permisos de " . self::get_images_path());
            return FALSE;
        }

        // Verificar que el archivo se creó correctamente
        if (!file_exists($ruta_destino)) {
            error_log("Tarifario upload: El archivo no existe después de copiar: " . $ruta_destino);
            return FALSE;
        }

        error_log("Tarifario upload: Archivo guardado exitosamente: " . $ruta_destino);

        // Crear el registro en la base de datos
        $imagen = new tarif_articulo_imagen();
        $imagen->referencia = $referencia;
        $imagen->nombre_archivo = $nombre_archivo;

        if ($imagen->save()) {
            return $imagen;
        } else {
            // Si falla el guardado, eliminar el archivo
            error_log("Tarifario upload: Error al guardar en BD, eliminando archivo");
            @unlink($ruta_destino);
            return FALSE;
        }
    }

    /**
     * Importa una imagen desde datos base64.
     * El nombre del archivo debe coincidir con la referencia del producto.
     * 
     * @param string $referencia Referencia del artículo
     * @param string $base64_data Datos de la imagen en base64
     * @param string $original_name Nombre original del archivo
     * @return tarif_articulo_imagen|false
     */
    public static function upload_from_base64($referencia, $base64_data, $original_name, &$error = null)
    {
        $error = null;
        error_log("Tarifario upload_base64: Iniciando para ref=$referencia, file=$original_name, base64_len=" . strlen($base64_data));
        
        // Remover prefijo data:image si existe (igual que en ImageHelper de api_auth)
        $base64_data = preg_replace('/^data:image\/\w+;base64,/', '', $base64_data);
        
        // Limpiar posibles espacios en blanco y saltos de línea
        $base64_data = str_replace(["\r", "\n", " "], '', $base64_data);
        
        error_log("Tarifario upload_base64: Después de limpiar, base64_len=" . strlen($base64_data));
        
        // Decodificar los datos base64 (sin modo estricto para mayor compatibilidad)
        $image_data = base64_decode($base64_data);
        if ($image_data === false || empty($image_data)) {
            $error = 'Error al decodificar base64';
            error_log("Tarifario upload_base64: Error al decodificar base64 para $referencia (len=" . strlen($base64_data) . ")");
            return FALSE;
        }
        
        error_log("Tarifario upload_base64: Decodificado OK, image_data_len=" . strlen($image_data));

        // Detectar tipo MIME desde los datos
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->buffer($image_data);

        // Validar tipo de archivo
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime_type, $allowed_types)) {
            $error = 'Tipo de imagen no permitido: ' . $mime_type;
            error_log("Tarifario upload_base64: Tipo MIME no permitido: " . $mime_type);
            return FALSE;
        }

        // Obtener extensión
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        $extension = $extensions[$mime_type];

        // Asegurar que el directorio existe
        if (!self::ensure_images_dir()) {
            $error = 'No se pudo crear o acceder al directorio de imagenes';
            error_log("Tarifario upload_base64: No se pudo crear/acceder al directorio de imágenes");
            return FALSE;
        }

        // Generar nombre de archivo
        $nombre_archivo = self::generar_nombre_archivo($referencia, $extension);
        $ruta_destino = self::get_images_path() . $nombre_archivo;

        // Guardar archivo
        if (file_put_contents($ruta_destino, $image_data) === false) {
            $error = 'No se pudo guardar el archivo en disco';
            error_log("Tarifario upload_base64: No se pudo guardar el archivo: " . $ruta_destino);
            return FALSE;
        }

        // Verificar que el archivo se creó correctamente
        if (!file_exists($ruta_destino)) {
            $error = 'El archivo no existe despues de guardar';
            error_log("Tarifario upload_base64: El archivo no existe después de guardar: " . $ruta_destino);
            return FALSE;
        }

        error_log("Tarifario upload_base64: Archivo guardado exitosamente: " . $ruta_destino);

        // Crear el registro en la base de datos
        $imagen = new tarif_articulo_imagen();
        $imagen->referencia = $referencia;
        $imagen->nombre_archivo = $nombre_archivo;

        if ($imagen->save()) {
            return $imagen;
        } else {
            $errors = $imagen->get_errors();
            if (!empty($errors)) {
                $error = trim(end($errors));
            } else {
                $error = 'Error al guardar la imagen en la base de datos';
            }
            // Si falla el guardado, eliminar el archivo
            error_log("Tarifario upload_base64: Error al guardar en BD, eliminando archivo");
            @unlink($ruta_destino);
            return FALSE;
        }
    }

    /**
     * Extrae la referencia del nombre de un archivo de imagen.
     * Elimina la extensión y devuelve el nombre base.
     * 
     * @param string $filename Nombre del archivo (ej: "REF123.jpg")
     * @return string Referencia extraída (ej: "REF123")
     */
    public static function extract_referencia_from_filename($filename)
    {
        // Obtener el nombre sin la ruta
        $basename = basename($filename);
        
        // Eliminar la extensión
        $info = pathinfo($basename);
        $referencia = isset($info['filename']) ? $info['filename'] : $basename;
        
        // Limpiar espacios al inicio y final
        return trim($referencia);
    }

    /**
     * Verifica si existe un artículo con la referencia dada.
     * 
     * @param string $referencia
     * @return bool
     */
    public static function articulo_exists($referencia)
    {
        $db = new \fs_db2();
        // Escapar la referencia manualmente ya que var2str es metodo de fs_model
        $ref_escaped = "'" . $db->escape_string($referencia) . "'";
        $sql = "SELECT referencia FROM articulos WHERE referencia = " . $ref_escaped . " LIMIT 1;";
        $data = $db->select($sql);
        return !empty($data);
    }

    /**
     * Cuenta las imágenes totales en la tabla.
     * @return integer
     */
    public function count_all()
    {
        $data = $this->db->select("SELECT COUNT(*) as total FROM " . $this->table_name . ";");
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }
}
