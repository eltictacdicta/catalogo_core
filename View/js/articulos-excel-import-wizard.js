/**
 * Wizard importación Excel — artículos catalogo_core (3 pasos)
 */
(function () {
    'use strict';

    var FIELD_OPTIONS = [
        { value: '__ignorar__', label: '— Ignorar —' },
        { value: 'referencia', label: 'Referencia' },
        { value: 'descripcion', label: 'Descripción' },
        { value: 'pvp', label: 'Precio' },
        { value: 'codfamilia', label: 'Cód. Familia' },
        { value: 'codfabricante', label: 'Cód. Fabricante' },
        { value: 'codimpuesto', label: 'Impuesto' },
        { value: 'bloqueado', label: 'Bloqueado' }
    ];

    var FIELD_LABELS = {};
    FIELD_OPTIONS.forEach(function (opt) {
        if (opt.value !== '__ignorar__') {
            FIELD_LABELS[opt.value] = opt.label;
        }
    });

    var ALIASES = {
        referencia: ['referencia', 'ref', 'codigo', 'código', 'codigo (no editar)'],
        descripcion: ['descripción', 'descripcion', 'desc', 'description'],
        pvp: ['precio', 'pvp', 'price'],
        codfamilia: ['cód. familia', 'cod. familia', 'cod familia', 'codfamilia'],
        codfabricante: ['cód. fabricante', 'cod. fabricante', 'cod fabricante', 'codfabricante', 'fabricante'],
        codimpuesto: ['impuesto', 'codimpuesto', 'iva', 'tax'],
        bloqueado: ['bloqueado', 'blocked', 'obsoleto']
    };

    function $(id) { return document.getElementById(id); }

    function show(el) { if (el) el.style.display = ''; }
    function hide(el) { if (el) el.style.display = 'none'; }

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content') || '';
        var input = document.querySelector('input[name="_csrf_token"]');
        return input ? input.value : '';
    }

    function bround(dVal, iDec) {
        var dFuzz = 0.00001;
        var iSign = (dVal !== 0.0) ? ((dVal / Math.abs(dVal)) | 0) : 1;
        dVal = Math.abs(dVal);
        var dWorking = dVal * Math.pow(10.0, iDec + 1) - Math.floor(dVal * Math.pow(10.0, iDec)) * 10.0;
        var iEvenOddDigit = Math.floor(dVal * Math.pow(10.0, iDec)) - Math.floor(dVal * Math.pow(10.0, iDec - 1)) * 10.0;
        var iRoundup;
        if (Math.abs(dWorking - 5.0) < dFuzz) {
            iRoundup = (iEvenOddDigit & 1) ? 1 : 0;
        } else {
            iRoundup = (dWorking > 5.0) ? 1 : 0;
        }
        return iSign * ((Math.floor(dVal * Math.pow(10.0, iDec)) + iRoundup) / Math.pow(10.0, iDec));
    }

    function formatPriceDisplay(num, decimals) {
        var rounded = bround(num, decimals);
        var parts = rounded.toFixed(decimals).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '');
        return decimals > 0 ? parts.join(',') : parts[0];
    }

    function suggestField(header) {
        var h = String(header || '').toLowerCase().trim();
        if (!h) return '__ignorar__';
        for (var field in ALIASES) {
            if (ALIASES[field].indexOf(h) !== -1) return field;
        }
        return '__ignorar__';
    }

    function ArticulosExcelWizard(config) {
        this.config = config || {};
        this.step = 1;
        this.token = '';
        this.sheet = '';
        this.sheets = [];
        this.headers = [];
        this.rows = [];
        this.userMapping = {};
        this.eventSource = null;
    }

    ArticulosExcelWizard.prototype.init = function () {
        var self = this;
        var btnNext = $('articulos-wizard-btn-next');
        var btnBack = $('articulos-wizard-btn-back');
        if (btnNext) btnNext.addEventListener('click', function () { self.onNext(); });
        if (btnBack) btnBack.addEventListener('click', function () { self.onBack(); });
        var sheetSel = $('articulos-wizard-sheet');
        if (sheetSel) sheetSel.addEventListener('change', function () {
            self.sheet = sheetSel.value;
            self.loadPreview();
        });
        var roundCb = $('articulos-wizard-round-price');
        if (roundCb) roundCb.addEventListener('change', function () {
            self.renderPreviewTable();
            if (self.step === 3) {
                self.renderMappedSummaryTable();
            }
        });
        var taxSel = $('articulos-wizard-default-impuesto');
        if (taxSel) taxSel.addEventListener('change', function () {
            if (self.step >= 2) {
                self.renderPreviewTable();
            }
            if (self.step === 3) {
                self.renderMappedSummaryTable();
            }
        });
        var modal = $('modal-importar-articulos-excel');
        if (modal) {
            modal.addEventListener('hidden.bs.modal', function () { self.reset(); });
        }
    };

    ArticulosExcelWizard.prototype.reset = function () {
        this.step = 1;
        this.token = '';
        this.headers = [];
        this.rows = [];
        this.userMapping = {};
        if (this.eventSource) { this.eventSource.close(); this.eventSource = null; }
        show($('articulos-wizard-step-1'));
        hide($('articulos-wizard-step-2'));
        hide($('articulos-wizard-step-3'));
        hide($('articulos-wizard-progress'));
        hide($('articulos-wizard-result'));
        show($('articulos-wizard-summary'));
        if ($('articulos-wizard-btn-back')) hide($('articulos-wizard-btn-back'));
        if ($('articulos-wizard-btn-next')) {
            $('articulos-wizard-btn-next').textContent = this.config.labelNext || 'Siguiente';
            show($('articulos-wizard-btn-next'));
        }
    };

    ArticulosExcelWizard.prototype.onNext = function () {
        if (this.step === 1) this.uploadFile();
        else if (this.step === 2) this.validateAndGoStep3();
        else if (this.step === 3) this.startApply();
    };

    ArticulosExcelWizard.prototype.onBack = function () {
        if (this.step === 2) {
            this.step = 1;
            show($('articulos-wizard-step-1'));
            hide($('articulos-wizard-step-2'));
            hide($('articulos-wizard-btn-back'));
        } else if (this.step === 3) {
            this.step = 2;
            show($('articulos-wizard-step-2'));
            hide($('articulos-wizard-step-3'));
            var btn = $('articulos-wizard-btn-next');
            if (btn) btn.textContent = this.config.labelNext || 'Siguiente';
        }
    };

    ArticulosExcelWizard.prototype.uploadFile = function () {
        var self = this;
        var fileInput = $('articulos-wizard-file');
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            this.showStep1Error('Selecciona un archivo Excel.');
            return;
        }
        var formData = new FormData();
        formData.append('file', fileInput.files[0]);
        formData.append('_csrf_token', getCsrfToken());

        var url = (this.config.baseUrl || '') + '&action=preview_excel';
        fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json.success) {
                    self.showStep1Error(json.error || 'Error al subir el archivo.');
                    return;
                }
                self.token = json.token;
                self.sheets = json.sheets || [];
                self.sheet = json.active_sheet || self.sheets[0];
                self.populateSheets();
                self.loadPreview();
            })
            .catch(function (err) { self.showStep1Error('Error de red: ' + err.message); });
    };

    ArticulosExcelWizard.prototype.showStep1Error = function (msg) {
        var el = $('articulos-wizard-step1-error');
        if (el) { el.textContent = msg; show(el); }
    };

    ArticulosExcelWizard.prototype.populateSheets = function () {
        var wrap = $('articulos-wizard-sheet-wrapper');
        var sel = $('articulos-wizard-sheet');
        if (!sel || this.sheets.length <= 1) { hide(wrap); return; }
        sel.innerHTML = '';
        this.sheets.forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s; opt.textContent = s;
            if (s === this.sheet) opt.selected = true;
            sel.appendChild(opt);
        }, this);
        show(wrap);
    };

    ArticulosExcelWizard.prototype.loadPreview = function () {
        var self = this;
        var url = (this.config.baseUrl || '') + '&action=get_preview&token='
            + encodeURIComponent(this.token) + '&sheet=' + encodeURIComponent(this.sheet);
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json.success) {
                    self.showStep1Error(json.error || 'No se pudo cargar la vista previa.');
                    return;
                }
                self.headers = json.headers || [];
                self.rows = json.rows || [];
                self.userMapping = self.normalizeMapping(json.suggested_mapping || {});
                self.renderMapping();
                self.step = 2;
                hide($('articulos-wizard-step-1'));
                show($('articulos-wizard-step-2'));
                show($('articulos-wizard-btn-back'));
            });
    };

    ArticulosExcelWizard.prototype.renderMapping = function () {
        var container = $('articulos-wizard-mapping');
        if (!container) return;
        container.innerHTML = '';
        var self = this;
        this.headers.forEach(function (header, idx) {
            var row = document.createElement('div');
            row.className = 'form-group form-group-sm';
            var label = document.createElement('label');
            label.textContent = header || ('Columna ' + (idx + 1));
            var sel = document.createElement('select');
            sel.className = 'form-control input-sm';
            sel.dataset.colIdx = String(idx);
            FIELD_OPTIONS.forEach(function (opt) {
                var o = document.createElement('option');
                o.value = opt.value;
                o.textContent = opt.label;
                sel.appendChild(o);
            });
            sel.value = self.userMapping[idx] || suggestField(header);
            self.userMapping[idx] = sel.value;
            sel.addEventListener('change', function () {
                self.userMapping[idx] = sel.value;
                self.checkMappingValidity();
                self.renderPreviewTable();
                if (self.step === 3) {
                    self.renderMappedSummaryTable();
                }
            });
            row.appendChild(label);
            row.appendChild(sel);
            container.appendChild(row);
        });
        this.checkMappingValidity();
        this.renderPreviewTable();
    };

    ArticulosExcelWizard.prototype.normalizeMapping = function (raw) {
        var out = {};
        if (!raw || typeof raw !== 'object') {
            return out;
        }
        Object.keys(raw).forEach(function (key) {
            var idx = parseInt(key, 10);
            if (!isNaN(idx)) {
                out[idx] = raw[key];
            }
        });
        return out;
    };

    ArticulosExcelWizard.prototype.getFieldForColumn = function (idx) {
        if (this.userMapping[idx]) {
            return this.userMapping[idx];
        }
        var container = $('articulos-wizard-mapping');
        if (!container) {
            return '__ignorar__';
        }
        var sel = container.querySelector('select[data-col-idx="' + idx + '"]');
        return sel ? sel.value : '__ignorar__';
    };

    ArticulosExcelWizard.prototype.checkMappingValidity = function () {
        var hasField = false;
        var hasDesc = false;
        for (var k in this.userMapping) {
            if (this.userMapping[k] && this.userMapping[k] !== '__ignorar__') hasField = true;
            if (this.userMapping[k] === 'descripcion') hasDesc = true;
        }
        var mode = document.querySelector('input[name="wizard_default_action"]:checked');
        var isCreate = !mode || mode.value === 'create_if_missing';
        var btn = $('articulos-wizard-btn-next');
        if (isCreate && !hasDesc) {
            this.showRequiredWarn('Para crear artículos debes mapear la columna Descripción.');
            if (btn) btn.disabled = true;
            return false;
        }
        if (!hasField) {
            this.showRequiredWarn('Mapea al menos una columna.');
            if (btn) btn.disabled = true;
            return false;
        }
        this.hideRequiredWarn();
        if (btn) btn.disabled = false;
        return true;
    };

    ArticulosExcelWizard.prototype.showRequiredWarn = function (msg) {
        var w = $('articulos-wizard-required-warn');
        var m = $('articulos-wizard-required-msg');
        if (m) m.textContent = msg;
        show(w);
    };

    ArticulosExcelWizard.prototype.hideRequiredWarn = function () { hide($('articulos-wizard-required-warn')); };

    ArticulosExcelWizard.prototype.isRoundPriceEnabled = function () {
        var cb = $('articulos-wizard-round-price');
        return !!(cb && cb.checked);
    };

    ArticulosExcelWizard.prototype.isCreateMode = function () {
        var mode = document.querySelector('input[name="wizard_default_action"]:checked');
        return !mode || mode.value === 'create_if_missing';
    };

    ArticulosExcelWizard.prototype.getDefaultCodimpuesto = function () {
        var sel = $('articulos-wizard-default-impuesto');
        if (sel && sel.value) {
            return sel.value;
        }
        return this.config.defaultCodimpuesto || 'IVA21';
    };

    ArticulosExcelWizard.prototype.hasMappedTaxColumn = function () {
        for (var k in this.userMapping) {
            if (this.userMapping[k] === 'codimpuesto') {
                return true;
            }
        }
        return false;
    };

    ArticulosExcelWizard.prototype.formatMappedValue = function (field, value) {
        if (field !== 'pvp' || !this.isRoundPriceEnabled()) {
            return value;
        }
        var normalized = String(value).replace(/\s/g, '').replace('€', '').replace(',', '.');
        var num = parseFloat(normalized);
        if (isNaN(num)) {
            return value;
        }
        var dec = this.config.priceDecimals != null ? parseInt(this.config.priceDecimals, 10) : 2;
        if (isNaN(dec)) {
            dec = 2;
        }
        return formatPriceDisplay(num, dec);
    };

    ArticulosExcelWizard.prototype.getMappedFields = function () {
        var fields = [];
        var seen = {};
        for (var i = 0; i < this.headers.length; i++) {
            var field = this.userMapping[i] || '__ignorar__';
            if (field === '__ignorar__' || seen[field]) {
                continue;
            }
            seen[field] = true;
            fields.push({ index: i, field: field });
        }
        return fields;
    };

    ArticulosExcelWizard.prototype.getSummaryFields = function () {
        var fields = this.getMappedFields();
        if (this.isCreateMode() && !this.hasMappedTaxColumn() && this.getDefaultCodimpuesto()) {
            fields = fields.slice();
            fields.push({ index: -1, field: 'codimpuesto' });
        }
        return fields;
    };

    ArticulosExcelWizard.prototype.buildMappedRow = function (rawRow) {
        var mapped = {};
        var fields = this.getMappedFields();
        fields.forEach(function (item) {
            var val = (rawRow && rawRow[item.index] != null) ? String(rawRow[item.index]) : '';
            if (val !== '') {
                mapped[item.field] = val;
            }
        });
        if (!mapped.codimpuesto && this.isCreateMode()) {
            mapped.codimpuesto = this.getDefaultCodimpuesto();
        }
        return mapped;
    };

    ArticulosExcelWizard.prototype.renderPreviewTable = function () {
        var table = $('articulos-wizard-preview-table');
        if (!table) return;
        var thead = table.querySelector('thead');
        var tbody = table.querySelector('tbody');
        thead.innerHTML = '';
        tbody.innerHTML = '';

        if (!this.headers.length) {
            return;
        }

        var hr = document.createElement('tr');
        for (var i = 0; i < this.headers.length; i++) {
            var th = document.createElement('th');
            var fieldName = this.getFieldForColumn(i);
            var fieldLabel = FIELD_LABELS[fieldName] || '—';
            th.innerHTML = '<small>' + this.escapeHtml(this.headers[i] || '') + '</small><br>'
                + '<small class="text-muted">' + this.escapeHtml(fieldLabel) + '</small>';
            hr.appendChild(th);
        }
        thead.appendChild(hr);

        var self = this;
        this.rows.forEach(function (row) {
            var tr = document.createElement('tr');
            for (var c = 0; c < self.headers.length; c++) {
                var td = document.createElement('td');
                var val = (row && row[c] != null) ? String(row[c]) : '';
                var fieldName = self.getFieldForColumn(c);
                td.textContent = self.formatMappedValue(fieldName, val);
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
        });
    };

    ArticulosExcelWizard.prototype.renderMappedSummaryTable = function () {
        var table = $('articulos-wizard-summary-table');
        if (!table) return;
        var thead = table.querySelector('thead');
        var tbody = table.querySelector('tbody');
        thead.innerHTML = '';
        tbody.innerHTML = '';

        var fields = this.getSummaryFields();
        if (!fields.length || !this.rows.length) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 1;
            emptyCell.className = 'text-muted';
            emptyCell.textContent = 'No hay filas de ejemplo para mostrar.';
            emptyRow.appendChild(emptyCell);
            tbody.appendChild(emptyRow);
            return;
        }

        var hr = document.createElement('tr');
        fields.forEach(function (item) {
            var th = document.createElement('th');
            th.textContent = FIELD_LABELS[item.field] || item.field;
            hr.appendChild(th);
        });
        thead.appendChild(hr);

        var self = this;
        this.rows.forEach(function (rawRow) {
            var mapped = self.buildMappedRow(rawRow);
            if (Object.keys(mapped).length === 0) {
                return;
            }
            var tr = document.createElement('tr');
            fields.forEach(function (item) {
                var td = document.createElement('td');
                td.textContent = self.formatMappedValue(item.field, mapped[item.field] || '');
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });

        if (!tbody.children.length) {
            var warnRow = document.createElement('tr');
            var warnCell = document.createElement('td');
            warnCell.colSpan = fields.length;
            warnCell.className = 'text-warning';
            warnCell.textContent = 'Las filas de ejemplo están vacías tras aplicar el mapeo.';
            warnRow.appendChild(warnCell);
            tbody.appendChild(warnRow);
        }
    };

    ArticulosExcelWizard.prototype.escapeHtml = function (text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    ArticulosExcelWizard.prototype.validateAndGoStep3 = function () {
        if (!this.checkMappingValidity()) return;
        this.renderPreviewTable();
        this.renderMappedSummaryTable();
        var total = $('articulos-wizard-summary-total');
        if (total) total.textContent = String(this.rows.length);
        this.step = 3;
        hide($('articulos-wizard-step-2'));
        show($('articulos-wizard-step-3'));
        show($('articulos-wizard-btn-back'));
        var btn = $('articulos-wizard-btn-next');
        if (btn) btn.textContent = this.config.labelApply || 'Importar';
    };

    ArticulosExcelWizard.prototype.startApply = function () {
        var self = this;
        hide($('articulos-wizard-summary'));
        show($('articulos-wizard-progress'));
        hide($('articulos-wizard-btn-next'));
        hide($('articulos-wizard-btn-back'));

        var params = ['wizard_action=start', 'token=' + encodeURIComponent(this.token),
            'sheet=' + encodeURIComponent(this.sheet)];
        var mode = document.querySelector('input[name="wizard_default_action"]:checked');
        params.push('default_action=' + encodeURIComponent(mode ? mode.value : 'create_if_missing'));
        params.push('round_price=' + (this.isRoundPriceEnabled() ? '1' : '0'));
        params.push('default_codimpuesto=' + encodeURIComponent(this.getDefaultCodimpuesto()));
        for (var i = 0; i < this.headers.length; i++) {
            params.push('mapping[' + i + ']=' + encodeURIComponent(this.userMapping[i] || '__ignorar__'));
        }
        params.push('_csrf_token=' + encodeURIComponent(getCsrfToken()));

        var base = this.config.baseUrl || 'index.php?page=ventas_articulos';
        var sep = base.indexOf('?') >= 0 ? '&' : '?';
        var url = base + sep + 'action=excel_import_sse&' + params.join('&');
        this.eventSource = new EventSource(url, { withCredentials: true });
        this.sseFinished = false;
        this.eventSource.addEventListener('progress', function (e) { self.onProgress(e); });
        this.eventSource.addEventListener('complete', function (e) { self.onComplete(e); });
        this.eventSource.addEventListener('error', function (e) {
            if (self.sseFinished || !e || !e.data) {
                return;
            }
            try {
                var payload = JSON.parse(e.data);
                if (payload.message) {
                    self.sseFinished = true;
                    self.onError(payload.message);
                }
            } catch (parseErr) { /* connection error handled in onerror */ }
        });
        this.eventSource.addEventListener('start', function (e) {
            if (e && e.data) {
                try {
                    var payload = JSON.parse(e.data);
                    if (payload.message) {
                        var msg = $('articulos-wizard-progress-msg');
                        if (msg) msg.textContent = payload.message;
                    }
                } catch (parseErr) { /* ignore */ }
            }
        });
        this.eventSource.onerror = function () {
            if (self.sseFinished) {
                return;
            }
            if (self.eventSource && self.eventSource.readyState === EventSource.CONNECTING) {
                return;
            }
            fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/event-stream' } })
                .then(function (response) {
                    return response.text().then(function (body) {
                        var message = 'Conexión SSE cerrada.';
                        try {
                            var json = JSON.parse(body);
                            if (json.error) {
                                message = json.error;
                            } else if (json.message) {
                                message = json.message;
                            }
                        } catch (parseErr) {
                            if (body && body.indexOf('event: error') !== -1) {
                                var match = body.match(/data:\s*(\{.*\})/);
                                if (match) {
                                    try {
                                        var evt = JSON.parse(match[1]);
                                        if (evt.message) {
                                            message = evt.message;
                                        }
                                    } catch (innerErr) { /* keep default */ }
                                }
                            }
                        }
                        self.sseFinished = true;
                        self.onError(message);
                    });
                })
                .catch(function () {
                    if (!self.sseFinished) {
                        self.onError('Conexión SSE cerrada.');
                    }
                });
        };
    };

    ArticulosExcelWizard.prototype.onProgress = function (e) {
        try {
            var data = JSON.parse(e.data);
            var pct = parseInt(data.percent || 0, 10);
            var bar = $('articulos-wizard-progress-bar');
            var msg = $('articulos-wizard-progress-msg');
            if (bar) { bar.style.width = pct + '%'; bar.textContent = pct + '%'; }
            if (msg) msg.textContent = data.message || '';
        } catch (err) { /* ignore */ }
    };

    ArticulosExcelWizard.prototype.onComplete = function (e) {
        this.sseFinished = true;
        if (this.eventSource) { this.eventSource.close(); this.eventSource = null; }
        hide($('articulos-wizard-progress'));
        show($('articulos-wizard-result'));
        var data = JSON.parse(e.data);
        var stats = data.stats || {};
        var ok = $('articulos-wizard-result-ok');
        if (ok) ok.textContent = data.message || 'Importación completada.';
        var st = $('articulos-wizard-result-stats');
        if (st) {
            st.textContent = 'Creados: ' + (stats.creados || 0)
                + ' | Actualizados: ' + (stats.actualizados || 0)
                + ' | Sin cambios: ' + (stats.sin_cambios || 0)
                + ' | Errores: ' + (stats.errores || 0);
        }
        var link = $('articulos-wizard-descartadas-link');
        if (link && data.csv_urls && data.csv_urls.descartadas_url) {
            link.href = data.csv_urls.descartadas_url;
            show(link);
        }
        var btn = $('articulos-wizard-btn-next');
        if (btn) {
            btn.textContent = this.config.labelClose || 'Cerrar';
            show(btn);
            btn.onclick = function () { window.location.reload(); };
        }
    };

    ArticulosExcelWizard.prototype.onError = function (msg) {
        this.sseFinished = true;
        if (this.eventSource) { this.eventSource.close(); this.eventSource = null; }
        hide($('articulos-wizard-progress'));
        show($('articulos-wizard-result'));
        var ok = $('articulos-wizard-result-ok');
        if (ok) {
            ok.className = 'alert alert-danger';
            ok.textContent = msg;
        }
    };

    window.ArticulosExcelWizard = ArticulosExcelWizard;

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof window.catalogoArticulosExcelConfig === 'undefined') return;
        var wizard = new ArticulosExcelWizard(window.catalogoArticulosExcelConfig);
        wizard.init();
        window.catalogoArticulosExcelWizard = wizard;
    });
})();
