/**
 * Utilidades para los módulos de familias
 * @module familias/utils
 */

/**
 * Formatea bytes a una cadena legible (KB, MB, GB)
 * @param {number} bytes
 * @param {number} decimals
 * @returns {string}
 */
export function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

/**
 * Obtiene el token CSRF del meta tag
 * @returns {string}
 */
export function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * Obtiene la configuración de familias
 * @returns {object}
 */
export function getConfig() {
    return window.familiasConfig || {};
}
