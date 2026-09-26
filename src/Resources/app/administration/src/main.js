/*
 * ModulwerkWebp - Administration (Modulwerk WebP-Konverter)
 *
 * Diese Datei wird bewusst NICHT gebaut. Shopware laedt sie ueber
 * ../../../public/administration/.vite/entrypoints.json direkt in die
 * Administration. Deshalb gilt:
 *
 *   - kein import / export, alles ueber das globale Shopware-Objekt
 *   - Templates als String-Array mit .join('\n')
 *   - Styling ueber ein injiziertes <style>-Element
 *
 * Nach jeder Aenderung diese Datei nach
 *   src/Resources/public/administration/assets/modulwerkwebp.js
 * kopieren (sync-admin.sh macht genau das).
 */
(function () {
    'use strict';

    if (typeof Shopware === 'undefined') {
        return;
    }

    if (window.__modulwerkWebpLoaded) {
        return;
    }

    window.__modulwerkWebpLoaded = true;

    var Component = Shopware.Component;
    var Module = Shopware.Module;


    /*
     * Wird von bump.sh aktuell gehalten. Die Zeile bitte in dieser Form
     * stehen lassen, das Skript ersetzt sie per Muster.
     */
    var PLUGIN_VERSION = '2.0.6';

    var BUNDLE = 'modulwerkwebp';

    var TECHNICAL_NAME = 'ModulwerkWebp';
    var CONFIG_DOMAIN = 'ModulwerkWebp.config';
    var LICENSE = 'MIT';

    /*
     * Dokumente fuer das Info-Modal. sync-admin.sh kopiert sie nach
     * src/Resources/public/administration/doc/.
     */
    var DOCUMENTS = {
        readme: { 'de-DE': 'README.md', 'en-GB': 'README_en-GB.md' },
        changelog: { 'de-DE': 'CHANGELOG_de-DE.md', 'en-GB': 'CHANGELOG_en-GB.md' },
        license: { 'de-DE': 'LICENSE.md', 'en-GB': 'LICENSE.md' }
    };

    /*
     * Die Dokumente liegen base64-kodiert direkt im ausgelieferten Asset.
     * sync-admin.sh ersetzt die folgende Zeile beim Kopieren durch die
     * echten Daten. Grund: Webserver liefern .md-Dateien aus public/
     * haeufig nicht aus, ein fetch() waere dann nicht verlaesslich.
     */
    var DOC_DATA = {}; /* __DOC_DATA__ */

    function decodeDoc(value) {
        var binary = window.atob(value);
        var bytes = new Uint8Array(binary.length);

        for (var i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }

        if (typeof TextDecoder !== 'undefined') {
            return new TextDecoder('utf-8').decode(bytes);
        }

        return decodeURIComponent(escape(binary));
    }


    /* Angaben fuer die Hersteller- und Support-Karte */
    var SUPPORT = {
        name: 'Modulwerk',
        website: 'https://danny-hombeck.de/',
        websiteLabel: 'danny-hombeck.de',
        mail: 'post@danny-hombeck.de'
    };

    /*
     * Pfad zu den veroeffentlichten Assets. Der Praefix kann je nach
     * Installation abweichen, deshalb wird er aus dem Kontext gelesen.
     */
    function assetBase() {
        var needle = '/bundles/' + BUNDLE + '/administration/';

        /*
         * Zuverlaessigster Weg: den Pfad aus dem Script-Tag ableiten, mit
         * dem diese Datei selbst geladen wurde. Damit stimmt er auch bei
         * Installationen in einem Unterverzeichnis.
         */
        var scripts = document.querySelectorAll('script[src]');

        for (var i = 0; i < scripts.length; i += 1) {
            var src = scripts[i].getAttribute('src') || '';
            var at = src.indexOf(needle);

            if (at !== -1) {
                return src.substring(0, at + needle.length - 1);
            }
        }

        /* Rueckfall ueber den Kontext */
        var base = '';
        var api = Shopware.Context && Shopware.Context.api ? Shopware.Context.api : null;

        if (api) {
            base = api.assetsPath || api.assetPath || api.basePath || '';
        }

        if (base.length > 0 && base.charAt(base.length - 1) === '/') {
            base = base.substring(0, base.length - 1);
        }

        return base + '/bundles/' + BUNDLE + '/administration';
    }

    /*
     * In die Zwischenablage schreiben. navigator.clipboard steht nur in
     * sicheren Kontexten zur Verfuegung, deshalb der Rueckfallweg.
     */
    function copyToClipboard(value) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(value);
        }

        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = value;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.left = '-9999px';
            document.body.appendChild(area);
            area.select();

            try {
                document.execCommand('copy');
                resolve();
            } catch (error) {
                reject(error);
            } finally {
                document.body.removeChild(area);
            }
        });
    }

    /*
     * Sehr kleiner Markdown-Renderer. Er deckt nur ab, was in unseren
     * Dokumenten vorkommt: Ueberschriften, Listen, Tabellen, Code,
     * Links sowie fett und kursiv. Der Text wird vorher maskiert,
     * damit aus dem Dokument kein HTML in die Seite gelangt.
     */
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderInline(text) {
        return text
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g,
                '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    }

    function renderMarkdown(source) {
        var lines = escapeHtml(source).split('\n');
        var html = [];
        var list = false;
        var code = false;
        var table = false;

        function closeList() {
            if (list) {
                html.push('</ul>');
                list = false;
            }
        }

        function closeTable() {
            if (table) {
                html.push('</table>');
                table = false;
            }
        }

        for (var i = 0; i < lines.length; i += 1) {
            var line = lines[i];

            if (line.indexOf('```') === 0) {
                closeList();
                closeTable();

                if (code) {
                    html.push('</code></pre>');
                    code = false;
                } else {
                    html.push('<pre><code>');
                    code = true;
                }

                continue;
            }

            if (code) {
                html.push(line);
                continue;
            }

            var heading = line.match(/^(#{1,4})\s+(.*)$/);

            if (heading) {
                closeList();
                closeTable();
                var level = heading[1].length + 1;
                html.push('<h' + level + '>' + renderInline(heading[2]) + '</h' + level + '>');
                continue;
            }

            /* Trennzeile einer Tabelle ueberspringen */
            if (/^\|[\s:|-]+\|$/.test(line)) {
                continue;
            }

            if (line.indexOf('|') === 0) {
                closeList();

                if (!table) {
                    html.push('<table class="modulwerk-doc__table">');
                    table = true;
                }

                var cells = line.split('|').slice(1, -1).map(function (cell) {
                    return '<td>' + renderInline(cell.trim()) + '</td>';
                });

                html.push('<tr>' + cells.join('') + '</tr>');
                continue;
            }

            closeTable();

            var item = line.match(/^\s*[-*]\s+(.*)$/);

            if (item) {
                if (!list) {
                    html.push('<ul>');
                    list = true;
                }

                html.push('<li>' + renderInline(item[1]) + '</li>');
                continue;
            }

            closeList();

            if (line.trim() === '') {
                continue;
            }

            html.push('<p>' + renderInline(line) + '</p>');
        }

        closeList();
        closeTable();

        if (code) {
            html.push('</code></pre>');
        }

        return html.join('\n');
    }

    /* ------------------------------------------------------------------ */
    /* Fehlermeldung aus einer API-Antwort lesen                           */
    /* ------------------------------------------------------------------ */

    function errorMessage(error) {
        if (error && error.response && error.response.data) {
            var data = error.response.data;

            if (data.errors && data.errors.length) {
                return data.errors.map(function (item) {
                    return item.detail || item.title || '';
                }).join(' ');
            }
        }

        return error && error.message ? error.message : String(error);
    }

    function formatBytes(bytes) {
        var value = Number(bytes) || 0;

        if (value < 1024) {
            return value + ' B';
        }

        if (value < 1024 * 1024) {
            return (value / 1024).toFixed(1).replace('.', ',') + ' KB';
        }

        if (value < 1024 * 1024 * 1024) {
            return (value / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB';
        }

        return (value / 1024 / 1024 / 1024).toFixed(2).replace('.', ',') + ' GB';
    }

    /* ------------------------------------------------------------------ */
    /* API-Service                                                         */
    /* ------------------------------------------------------------------ */

    /*
     * ApiService ist in Shopware eine ES6-Klasse und laesst sich nur per
     * "class ... extends" ableiten, nicht ueber Prototyp-Zuweisung.
     */
    var ApiService = Shopware.Classes.ApiService;

    class ModulwerkWebpApiService extends ApiService {
        constructor(httpClient, loginService) {
            super(httpClient, loginService, 'modulwerk-webp');
            this.name = 'modulwerkWebpApiService';
        }

        request(method, path, payload, params) {
            var headers = this.getBasicHeaders();
            var url = '_action/modulwerk-webp/' + path;
            var promise = method === 'get'
                ? this.httpClient.get(url, { headers: headers, params: params || {} })
                : this.httpClient.post(url, payload || {}, { headers: headers });

            return promise.then(function (response) {
                return ApiService.handleResponse(response);
            });
        }
    }

    Shopware.Service().register('modulwerkWebpApiService', function () {
        var initContainer = Shopware.Application.getContainer('init');

        return new ModulwerkWebpApiService(initContainer.httpClient, Shopware.Service('loginService'));
    });

    /* ------------------------------------------------------------------ */
    /* Snippets                                                            */
    /* ------------------------------------------------------------------ */

    /*
     * Snippets liegen in snippet/de-DE.json und snippet/en-GB.json.
     * Shopware laedt sie serverseitig (SnippetFinder), ganz ohne Build.
     * sync-admin.sh schreibt denselben Inhalt zusaetzlich in die folgende
     * Zeile, damit die Texte auch dann da sind, wenn der Snippet-Cache
     * noch nicht erneuert wurde. Gepflegt wird nur in den JSON-Dateien.
     */
    var SNIPPETS = {}; /* __SNIPPET_DATA__ */

    /*
     * Ab Shopware 6.8 gibt es nur noch $t, $tc ist entfernt. $t gibt es
     * auch in 6.7, deshalb wird durchgaengig $t benutzt.
     *
     * Platzhalter wie {count} in Snippets ersetzt vue-i18n selbst. Deshalb
     * immer $t(key, { count: ... }) benutzen - ein nachtraegliches
     * .replace('{count}', ...) findet nichts mehr, weil vue-i18n den
     * Platzhalter ohne Wert bereits durch einen Leerstring ersetzt hat.
     */

    /* ------------------------------------------------------------------ */
    /* Uebersicht                                                          */
    /* ------------------------------------------------------------------ */

    Component.register('modulwerk-webp-overview', {
        template: [
            '<sw-page class="modulwerk-webp-overview">',
            '    <template #smart-bar-header>',
            '        <div class="modulwerk-page-title">',
            '            <h2>{{ $t(\'modulwerk-webp.overview.title\') }}</h2>',
            '            <span class="modulwerk-version-badge">',
            '                {{ $t(\'modulwerk-webp.support.version\') }} {{ pluginVersion }}',
            '            </span>',
            '        </div>',
            '    </template>',
            '',
            '    <template #smart-bar-actions>',
            '        <mt-button variant="secondary" size="default" :disabled="isRunning" @click="loadAll">',
            '            {{ $t(\'modulwerk-webp.overview.buttonRefresh\') }}',
            '        </mt-button>',
            '        <mt-button variant="secondary" size="default" @click="onSettings">',
            '            {{ $t(\'modulwerk-webp.general.settings\') }}',
            '        </mt-button>',
            '    </template>',
            '',
            '    <template #content>',
            '        <sw-card-view>',
            '            <mt-card :title="$t(\'modulwerk-webp.overview.cardStatus\')" :is-loading="isLoading && !status">',
            '                <template v-if="status">',
            '                    <div class="modulwerk-webp-stats">',
            '                        <div class="modulwerk-webp-stat">',
            '                            <span class="modulwerk-webp-stat__value">{{ status.mediaTotal }}</span>',
            '                            <span class="modulwerk-webp-stat__label">{{ $t(\'modulwerk-webp.overview.statTotal\') }}</span>',
            '                        </div>',
            '                        <div class="modulwerk-webp-stat" :class="{ \'is--warn\': status.mediaPending > 0, \'is--good\': status.mediaPending === 0 }">',
            '                            <span class="modulwerk-webp-stat__value">{{ status.mediaPending }}</span>',
            '                            <span class="modulwerk-webp-stat__label">{{ $t(\'modulwerk-webp.overview.statPending\') }}</span>',
            '                        </div>',
            '                        <div class="modulwerk-webp-stat is--good">',
            '                            <span class="modulwerk-webp-stat__value">{{ status.filesConverted }}</span>',
            '                            <span class="modulwerk-webp-stat__label">{{ $t(\'modulwerk-webp.overview.statConverted\') }}</span>',
            '                        </div>',
            '                        <div class="modulwerk-webp-stat">',
            '                            <span class="modulwerk-webp-stat__value">{{ status.filesSkipped }}</span>',
            '                            <span class="modulwerk-webp-stat__label">{{ $t(\'modulwerk-webp.overview.statSkipped\') }}</span>',
            '                        </div>',
            '                        <div class="modulwerk-webp-stat" :class="{ \'is--warn\': status.filesError > 0 }">',
            '                            <span class="modulwerk-webp-stat__value">{{ status.filesError }}</span>',
            '                            <span class="modulwerk-webp-stat__label">{{ $t(\'modulwerk-webp.overview.statErrors\') }}</span>',
            '                        </div>',
            '                        <div class="modulwerk-webp-stat is--good">',
            '                            <span class="modulwerk-webp-stat__value">{{ savedLabel }}</span>',
            '                            <span class="modulwerk-webp-stat__label">{{ $t(\'modulwerk-webp.overview.statSaved\') }} {{ savedPercent }}</span>',
            '                        </div>',
            '                    </div>',
            '',
            '                    <dl class="modulwerk-help__list">',
            '                        <dt>{{ $t(\'modulwerk-webp.overview.engine\') }}</dt>',
            '                        <dd>{{ status.engine ? status.engine.toUpperCase() : $t(\'modulwerk-webp.overview.engineNone\') }}</dd>',
            '                        <dt>GD (WebP)</dt>',
            '                        <dd>{{ status.gdAvailable ? $t(\'modulwerk-webp.overview.available\') : $t(\'modulwerk-webp.overview.notAvailable\') }}</dd>',
            '                        <dt>Imagick (WebP)</dt>',
            '                        <dd>{{ status.imagickAvailable ? $t(\'modulwerk-webp.overview.available\') : $t(\'modulwerk-webp.overview.notAvailable\') }}</dd>',
            '                        <dt>{{ $t(\'modulwerk-webp.overview.memoryLimit\') }}</dt>',
            '                        <dd>{{ status.memoryLimit }}</dd>',
            '                        <dt>{{ $t(\'modulwerk-webp.overview.libraryFolder\') }}</dt>',
            '                        <dd v-if="status.mediaLibrary">',
            '                            {{ $t(\'modulwerk-webp.overview.libraryEntries\', { count: status.libraryEntries }) }}',
            '                        </dd>',
            '                        <dd v-else>{{ $t(\'modulwerk-webp.overview.libraryOff\') }}</dd>',
            '                    </dl>',
            '                </template>',
            '            </mt-card>',
            '',
            '            <mt-card :title="$t(\'modulwerk-webp.overview.cardActions\')">',
            '                <template v-if="isRunning || progressText">',
            '                    <div class="modulwerk-webp-progress">',
            '                        <div class="modulwerk-webp-progress__bar" :style="{ width: progressPercent + \'%\' }"></div>',
            '                    </div>',
            '                    <p class="modulwerk-webp-progress__text">{{ progressText }}</p>',
            '                </template>',
            '',
            '                <div class="modulwerk-webp-actions">',
            '                    <mt-button v-if="!isRunning" variant="primary" size="small"',
            '                               :disabled="!status || !status.engine || status.mediaPending === 0"',
            '                               @click="onStart">',
            '                        {{ $t(\'modulwerk-webp.overview.buttonStart\') }}',
            '                    </mt-button>',
            '                    <mt-button v-else variant="critical" size="small" @click="onStop">',
            '                        {{ $t(\'modulwerk-webp.overview.buttonStop\') }}',
            '                    </mt-button>',
            '                    <mt-button variant="secondary" size="small"',
            '                               :disabled="isRunning || !status || !status.engine || !status.mediaTotal"',
            '                               @click="onRebuild">',
            '                        {{ $t(\'modulwerk-webp.overview.buttonRebuild\') }}',
            '                    </mt-button>',
            '                    <mt-button v-if="status && status.mediaLibrary" variant="secondary" size="small" @click="onOpenFolder">',
            '                        {{ $t(\'modulwerk-webp.overview.buttonOpenFolder\') }}',
            '                    </mt-button>',
            '                    <mt-button variant="secondary" size="small" :disabled="isRunning" @click="onClearCache">',
            '                        {{ $t(\'modulwerk-webp.overview.buttonCache\') }}',
            '                    </mt-button>',
            '                    <mt-button variant="secondary" size="small" :disabled="isRunning || !status || !status.filesError" @click="onRetry">',
            '                        {{ $t(\'modulwerk-webp.overview.buttonRetry\') }}',
            '                    </mt-button>',
            '                    <mt-button variant="secondary" size="small" :disabled="isRunning" @click="onCleanup">',
            '                        {{ $t(\'modulwerk-webp.overview.buttonCleanup\') }}',
            '                    </mt-button>',
            '                    <mt-button variant="critical" size="small" :disabled="isRunning" @click="onClear">',
            '                        {{ $t(\'modulwerk-webp.overview.buttonClear\') }}',
            '                    </mt-button>',
            '                </div>',
            '',
            '                <p v-if="status && status.mediaPending === 0 && !isRunning" class="modulwerk-webp-hint">',
            '                    {{ $t(\'modulwerk-webp.overview.noPending\') }}',
            '                </p>',
            '                <p v-if="status && !status.engine" class="modulwerk-webp-hint is--error">',
            '                    {{ $t(\'modulwerk-webp.overview.engineNone\') }}',
            '                </p>',
            '                <p class="modulwerk-webp-hint">',
            '                    {{ status && status.autoClearCache ? $t(\'modulwerk-webp.overview.hintCacheAuto\') : $t(\'modulwerk-webp.overview.hintCache\') }}',
            '                </p>',
            '            </mt-card>',
            '',
            '            <mt-card :title="$t(\'modulwerk-webp.overview.cardEntries\')" :is-loading="entriesLoading">',
            '                <div class="modulwerk-webp-filter">',
            '                    <button v-for="option in filterOptions" :key="option.key" type="button"',
            '                            :class="{ \'is--active\': filter === option.key }"',
            '                            @click="onFilter(option.key)">{{ option.label }}</button>',
            '                </div>',
            '',
            '                <table v-if="entries.length" class="modulwerk-webp-table">',
            '                    <tr>',
            '                        <th>{{ $t(\'modulwerk-webp.overview.columnFile\') }}</th>',
            '                        <th>{{ $t(\'modulwerk-webp.overview.columnStatus\') }}</th>',
            '                        <th class="is--num">{{ $t(\'modulwerk-webp.overview.columnOriginal\') }}</th>',
            '                        <th class="is--num">{{ $t(\'modulwerk-webp.overview.columnWebp\') }}</th>',
            '                        <th class="is--num">{{ $t(\'modulwerk-webp.overview.columnSaved\') }}</th>',
            '                        <th>{{ $t(\'modulwerk-webp.overview.columnMessage\') }}</th>',
            '                        <th></th>',
            '                    </tr>',
            '                    <tr v-for="entry in entries" :key="entry.path">',
            '                        <td class="modulwerk-webp-table__path" :title="entry.path">',
            '                            {{ entry.isThumbnail ? $t(\'modulwerk-webp.overview.thumbnail\') : $t(\'modulwerk-webp.overview.original\') }}:',
            '                            {{ fileName(entry.path) }}',
            '                        </td>',
            '                        <td><span class="modulwerk-webp-badge" :class="\'is--\' + entry.status">{{ statusLabel(entry.status) }}</span></td>',
            '                        <td class="is--num">{{ entry.sizeOriginal !== null ? bytes(entry.sizeOriginal) : \'-\' }}</td>',
            '                        <td class="is--num">{{ entry.sizeWebp !== null ? bytes(entry.sizeWebp) : \'-\' }}</td>',
            '                        <td class="is--num">{{ savedFor(entry) }}</td>',
            '                        <td class="modulwerk-webp-table__msg">{{ messageText(entry.message) }}</td>',
            '                        <td>',
            '                            <mt-button variant="secondary" size="x-small" :disabled="isRunning" @click="onRecreate(entry)">',
            '                                {{ $t(\'modulwerk-webp.overview.buttonRecreate\') }}',
            '                            </mt-button>',
            '                        </td>',
            '                    </tr>',
            '                </table>',
            '                <p v-else-if="!entriesLoading">{{ $t(\'modulwerk-webp.overview.empty\') }}</p>',
            '            </mt-card>',
            '        </sw-card-view>',
            '    </template>',
            '</sw-page>'
        ].join('\n'),

        inject: ['modulwerkWebpApiService'],

        mixins: [Shopware.Mixin.getByName('notification')],

        data: function () {
            return {
                status: null,
                entries: [],
                filter: '',
                isLoading: false,
                entriesLoading: false,
                isRunning: false,
                stopRequested: false,
                runTotal: 0,
                runDone: 0,
                progressText: '',
                rebuildRun: false
            };
        },

        computed: {
            pluginVersion: function () {
                return PLUGIN_VERSION;
            },

            savedLabel: function () {
                if (!this.status) {
                    return '-';
                }

                return formatBytes(Math.max(0, this.status.bytesOriginal - this.status.bytesWebp));
            },

            savedPercent: function () {
                if (!this.status || !this.status.bytesOriginal) {
                    return '';
                }

                var percent = (1 - this.status.bytesWebp / this.status.bytesOriginal) * 100;

                return '(' + percent.toFixed(1).replace('.', ',') + ' %)';
            },

            progressPercent: function () {
                if (!this.runTotal) {
                    return this.isRunning ? 0 : 100;
                }

                return Math.min(100, Math.round(this.runDone / this.runTotal * 100));
            },

            filterOptions: function () {
                return [
                    { key: '', label: this.$t('modulwerk-webp.overview.filterAll') },
                    { key: 'converted', label: this.$t('modulwerk-webp.overview.statusConverted') },
                    { key: 'skipped', label: this.$t('modulwerk-webp.overview.statusSkipped') },
                    { key: 'error', label: this.$t('modulwerk-webp.overview.statusError') }
                ];
            }
        },

        created: function () {
            this.loadAll();
        },

        beforeUnmount: function () {
            this.stopRequested = true;
        },

        methods: {
            api: function () {
                return this.modulwerkWebpApiService;
            },

            notifyError: function (error) {
                this.createNotificationError({
                    message: this.$t('modulwerk-webp.overview.error') + errorMessage(error)
                });
            },

            loadAll: function () {
                return Promise.all([this.loadStatus(), this.loadEntries()]);
            },

            loadStatus: function () {
                var that = this;
                this.isLoading = true;

                return this.api().request('get', 'status')
                    .then(function (data) {
                        that.status = data;
                    })
                    .catch(function (error) {
                        that.notifyError(error);
                    })
                    .finally(function () {
                        that.isLoading = false;
                    });
            },

            loadEntries: function () {
                var that = this;
                var params = { limit: 50 };

                if (this.filter) {
                    params.status = this.filter;
                }

                this.entriesLoading = true;

                return this.api().request('get', 'entries', null, params)
                    .then(function (data) {
                        that.entries = data.entries || [];
                    })
                    .catch(function (error) {
                        that.notifyError(error);
                    })
                    .finally(function () {
                        that.entriesLoading = false;
                    });
            },

            onFilter: function (key) {
                this.filter = key;

                return this.loadEntries();
            },

            onSettings: function () {
                this.$router.push({ name: 'modulwerk.webp.settings' });
            },

            /*
             * Arbeitet die offenen Medien in Portionen ab. Jede Anfrage
             * dauert serverseitig hoechstens rund 20 Sekunden.
             */
            onStart: function () {
                var that = this;

                if (!this.status) {
                    return Promise.resolve();
                }

                var converted = 0;

                this.isRunning = true;
                this.stopRequested = false;
                this.runTotal = this.status.mediaPending;
                this.runDone = 0;
                this.updateProgressText();

                function step() {
                    if (that.stopRequested) {
                        that.progressText = that.$t('modulwerk-webp.overview.progressStopped');
                        return Promise.resolve();
                    }

                    return that.api().request('post', 'process', { limit: that.status.batchSize || 20 })
                        .then(function (result) {
                            converted += result.converted;
                            that.runDone += result.processedMedia;
                            that.status.mediaPending = result.pending;

                            if (that.runDone > that.runTotal) {
                                that.runTotal = that.runDone + result.pending;
                            }

                            that.updateProgressText();

                            if (result.processedMedia === 0 || result.pending === 0 || !result.engine) {
                                that.progressText = that.$t('modulwerk-webp.overview.progressDone');
                                return null;
                            }

                            return step();
                        });
                }

                return step()
                    .catch(function (error) {
                        that.notifyError(error);
                    })
                    .then(function () {
                        /* Auch nach Anhalten oder Fehler: Umgewandeltes soll sichtbar werden */
                        return that.finishRun(converted);
                    })
                    .finally(function () {
                        that.isRunning = false;
                        that.loadAll();
                    });
            },

            /*
             * Meldet dem Server das Ende des Laufs. Er leert den Seiten-Cache,
             * sofern das eingeschaltet ist und etwas umgewandelt wurde.
             */
            finishRun: function (converted) {
                var that = this;
                var stopped = this.stopRequested;

                /* Nach "Alle neu umwandeln" sind alte Dateien weg - dann immer leeren */
                var changed = converted > 0 || this.rebuildRun;

                this.rebuildRun = false;

                return this.api().request('post', 'finish', { changed: changed })
                    .then(function (data) {
                        if (stopped) {
                            return;
                        }

                        if (converted === 0 && !data.cacheCleared) {
                            that.progressText = that.$t('modulwerk-webp.overview.progressDoneNothing');
                        } else if (data.cacheCleared) {
                            that.progressText = that.$t('modulwerk-webp.overview.progressDoneAuto');
                        }

                        if (data.cacheCleared) {
                            that.createNotificationSuccess({
                                message: that.$t('modulwerk-webp.overview.cacheAutoCleared')
                            });
                        }
                    })
                    .catch(function (error) {
                        that.notifyError(error);
                    });
            },

            notifyCacheCleared: function (data) {
                if (data && data.cacheCleared) {
                    this.createNotificationSuccess({
                        message: this.$t('modulwerk-webp.overview.cacheAutoCleared')
                    });
                }
            },

            onOpenFolder: function () {
                if (!this.status || !this.status.libraryFolderId) {
                    return;
                }

                this.$router.push({ name: 'sw.media.index', params: { folderId: this.status.libraryFolderId } });
            },

            /*
             * Alles verwerfen und komplett neu umwandeln, z. B. nach einer
             * geaenderten Qualitaet. Der Cache wird erst am Ende geleert.
             */
            onRebuild: function () {
                var that = this;

                if (!window.confirm(this.$t('modulwerk-webp.overview.confirmRebuild'))) {
                    return Promise.resolve();
                }

                this.isRunning = true;
                this.rebuildRun = true;

                return this.api().request('post', 'clear', { deferCache: true })
                    .then(function () {
                        that.createNotificationInfo({
                            message: that.$t('modulwerk-webp.overview.rebuildStarted')
                        });

                        return that.loadStatus();
                    })
                    .then(function () {
                        that.isRunning = false;

                        return that.onStart();
                    })
                    .catch(function (error) {
                        that.isRunning = false;
                        that.notifyError(error);

                        return that.finishRun(0);
                    });
            },

            onStop: function () {
                this.stopRequested = true;
            },

            updateProgressText: function () {
                this.progressText = this.$t('modulwerk-webp.overview.progress', {
                    done: this.runDone,
                    total: this.runTotal
                });
            },

            onRetry: function () {
                var that = this;

                return this.api().request('post', 'reset-errors')
                    .then(function (data) {
                        that.createNotificationSuccess({
                            message: that.$t('modulwerk-webp.overview.retrySuccess', { count: data.reset })
                        });

                        return that.loadAll();
                    })
                    .catch(function (error) {
                        that.notifyError(error);
                    });
            },

            onCleanup: function () {
                var that = this;

                return this.api().request('post', 'cleanup')
                    .then(function (data) {
                        that.createNotificationSuccess({
                            message: that.$t('modulwerk-webp.overview.cleanupSuccess', { count: data.removed })
                        });
                        that.notifyCacheCleared(data);

                        return that.loadAll();
                    })
                    .catch(function (error) {
                        that.notifyError(error);
                    });
            },

            onClear: function () {
                var that = this;

                if (!window.confirm(this.$t('modulwerk-webp.overview.confirmClear'))) {
                    return Promise.resolve();
                }

                return this.api().request('post', 'clear')
                    .then(function (data) {
                        that.createNotificationSuccess({
                            message: that.$t('modulwerk-webp.overview.clearSuccess')
                        });
                        that.notifyCacheCleared(data);

                        return that.loadAll();
                    })
                    .catch(function (error) {
                        that.notifyError(error);
                    });
            },

            onRecreate: function (entry) {
                var that = this;

                return this.api().request('post', 'media/' + entry.mediaId)
                    .then(function (data) {
                        that.createNotificationSuccess({
                            message: that.$t('modulwerk-webp.overview.recreateSuccess', {
                                converted: data.converted,
                                skipped: data.skipped,
                                errors: data.errors
                            })
                        });
                        that.notifyCacheCleared(data);

                        return that.loadAll();
                    })
                    .catch(function (error) {
                        that.notifyError(error);
                    });
            },

            onClearCache: function () {
                var that = this;
                var cacheService = Shopware.Service('cacheApiService');

                if (!cacheService || typeof cacheService.clear !== 'function') {
                    return Promise.resolve();
                }

                return cacheService.clear()
                    .then(function () {
                        that.createNotificationSuccess({
                            message: that.$t('modulwerk-webp.overview.cacheSuccess')
                        });
                    })
                    .catch(function (error) {
                        that.notifyError(error);
                    });
            },

            statusLabel: function (status) {
                if (status === 'converted') {
                    return this.$t('modulwerk-webp.overview.statusConverted');
                }

                if (status === 'skipped') {
                    return this.$t('modulwerk-webp.overview.statusSkipped');
                }

                return this.$t('modulwerk-webp.overview.statusError');
            },

            /*
             * Der Server speichert Hinweise sprachneutral als "code|detail".
             * Aeltere oder unbekannte Eintraege erscheinen unveraendert.
             */
            messageText: function (message) {
                if (!message) {
                    return '';
                }

                var at = message.indexOf('|');
                var code = at === -1 ? message : message.substring(0, at);
                var detail = at === -1 ? '' : message.substring(at + 1);
                var key = 'modulwerk-webp.messages.' + code;

                if (!/^[a-z_]+$/.test(code) || !this.$te(key)) {
                    return message;
                }

                return this.$t(key, { detail: detail });
            },

            fileName: function (path) {
                var parts = String(path || '').split('/');

                return parts[parts.length - 1];
            },

            bytes: function (value) {
                return formatBytes(value);
            },

            savedFor: function (entry) {
                if (entry.status !== 'converted' || !entry.sizeOriginal) {
                    return '-';
                }

                return Math.round((1 - entry.sizeWebp / entry.sizeOriginal) * 100) + ' %';
            }
        }
    });

    /* ------------------------------------------------------------------ */
    /* Einstellungen                                                       */
    /*                                                                     */
    /* sw-system-config rendert die config.xml. Die Feldliste bleibt       */
    /* damit an einer Stelle gepflegt und muss hier nicht doppelt          */
    /* beschrieben werden.                                                 */
    /* ------------------------------------------------------------------ */

    Component.register('modulwerk-webp-settings', {
        template: [
            '<sw-page class="modulwerk-webp-settings">',
            '    <template #smart-bar-header>',
            '        <div class="modulwerk-page-title">',
            '            <h2>{{ $t(\'modulwerk-webp.settings.title\') }}</h2>',
            '            <span class="modulwerk-version-badge">',
            '                {{ $t(\'modulwerk-webp.support.version\') }} {{ pluginVersion }}',
            '            </span>',
            '        </div>',
            '    </template>',
            '',
            '    <template #smart-bar-actions>',
            '        <button class="modulwerk-save-hint"',
            '                type="button"',
            '                :title="$t(\'modulwerk-webp.settings.saveHint\')"',
            '                @click="onOpenDocs">',
            '            <mt-icon name="regular-exclamation-circle" size="16px" />',
            '        </button>',
            '        <mt-button variant="secondary" size="default" @click="onCancel">',
            '            {{ $t(\'modulwerk-webp.detail.buttonCancel\') }}',
            '        </mt-button>',
            '        <mt-button variant="primary" size="default" :is-loading="isSaving" @click="onSave">',
            '            {{ $t(\'modulwerk-webp.detail.buttonSave\') }}',
            '        </mt-button>',
            '    </template>',
            '',
            '    <template #content>',
            '        <sw-card-view>',
            '            <sw-system-config ref="systemConfig"',
            '                              sales-channel-switchable',
            '                              :domain="configDomain" />',
            '',
            '            <mt-card class="modulwerk-support-card"',
            '                     position-identifier="modulwerk-webp-support">',
            '                <div class="modulwerk-card-title">',
            '                    <img class="modulwerk-card-logo" :src="iconUrl" alt="">',
            '                    <span>{{ $t(\'modulwerk-webp.support.title\') }}</span>',
            '                </div>',
            '                <div class="modulwerk-support">',
            '                    <img class="modulwerk-support__logo" :src="iconUrl" alt="">',
            '',
            '                    <div class="modulwerk-support__body">',
            '                        <p class="modulwerk-support__name">{{ support.name }}</p>',
            '                        <p class="modulwerk-support__text">{{ $t(\'modulwerk-webp.support.text\') }}</p>',
            '                        <a class="modulwerk-support__link"',
            '                           :href="support.website"',
            '                           target="_blank"',
            '                           rel="noopener noreferrer">{{ support.websiteLabel }}</a>',
            '                        <a class="modulwerk-support__link"',
            '                           :href="\'mailto:\' + support.mail">{{ support.mail }}</a>',
            '                    </div>',
            '',
            '                    <div class="modulwerk-support__meta">',
            '                        <span>{{ $t(\'modulwerk-webp.support.plugin\') }}</span>',
            '                        <strong>{{ $t(\'modulwerk-webp.general.mainMenuItemGeneral\') }}</strong>',
            '                        <span v-if="pluginVersion">{{ $t(\'modulwerk-webp.support.version\') }} {{ pluginVersion }}</span>',
            '                    </div>',
            '                </div>',
            '            </mt-card>',
            '',
            '            <mt-card class="modulwerk-help-card"',
            '                     position-identifier="modulwerk-webp-help">',
            '                <div class="modulwerk-card-title">',
            '                    <img class="modulwerk-card-logo" :src="iconUrl" alt="">',
            '                    <span>{{ $t(\'modulwerk-webp.help.title\') }}</span>',
            '                </div>',
            '',
            '                <p class="modulwerk-help__text">{{ $t(\'modulwerk-webp.help.text\') }}</p>',
            '',
            '                <dl class="modulwerk-help__list">',
            '                    <template v-for="row in infoRows" :key="row.label">',
            '                        <dt>{{ row.label }}</dt>',
            '                        <dd>{{ row.value }}</dd>',
            '                    </template>',
            '                </dl>',
            '',
            '                <div class="modulwerk-help__actions">',
            '                    <mt-button variant="primary" size="small" @click="onSupportMail">',
            '                        {{ $t(\'modulwerk-webp.help.buttonMail\') }}',
            '                    </mt-button>',
            '                    <mt-button variant="secondary" size="small" @click="onCopyInfo">',
            '                        {{ $t(\'modulwerk-webp.help.buttonCopy\') }}',
            '                    </mt-button>',
            '                    <mt-button variant="secondary" size="small" @click="onWebsite">',
            '                        {{ $t(\'modulwerk-webp.help.buttonWebsite\') }}',
            '                    </mt-button>',
            '                </div>',
            '            </mt-card>',
            '        </sw-card-view>',
            '',
            '        <sw-modal v-if="showDocs"',
            '                  class="modulwerk-doc-modal"',
            '                  variant="large"',
            '                  :title="$t(\'modulwerk-webp.doc.title\')"',
            '                  @modal-close="onCloseDocs">',
            '',
            '            <div class="modulwerk-doc__bar">',
            '                <div class="modulwerk-doc__tabs">',
            '                    <button v-for="tab in docTabs"',
            '                            :key="tab.key"',
            '                            type="button"',
            '                            class="modulwerk-doc__tab"',
            '                            :class="{ \'is--active\': tab.key === activeDoc }"',
            '                            @click="onSelectDoc(tab.key)">{{ tab.label }}</button>',
            '                </div>',
            '                <div class="modulwerk-doc__langs">',
            '                    <button v-for="lang in docLanguages"',
            '                            :key="lang.key"',
            '                            type="button"',
            '                            class="modulwerk-doc__lang"',
            '                            :class="{ \'is--active\': lang.key === docLanguage }"',
            '                            @click="onSelectLanguage(lang.key)">{{ lang.label }}</button>',
            '                </div>',
            '            </div>',
            '',
            '            <div class="modulwerk-doc__body">',
            '                <p v-if="docLoading">{{ $t(\'modulwerk-webp.doc.loading\') }}</p>',
            '                <div v-else-if="docError" class="modulwerk-doc__error">',
            '                    <p>{{ $t(\'modulwerk-webp.doc.error\') }}</p>',
            '                    <p><code>{{ docUrl }}</code></p>',
            '                    <p v-if="docStatus"><code>{{ docStatus }}</code></p>',
            '                    <p>{{ $t(\'modulwerk-webp.doc.errorHint\') }}</p>',
            '                </div>',
            '                <div v-else class="modulwerk-doc__content" v-html="docHtml"></div>',
            '            </div>',
            '',
            '            <template #modal-footer>',
            '                <mt-button variant="secondary" size="small" @click="onCloseDocs">',
            '                    {{ $t(\'modulwerk-webp.doc.close\') }}',
            '                </mt-button>',
            '            </template>',
            '        </sw-modal>',
            '    </template>',
            '</sw-page>'
        ].join('\n'),

        mixins: [Shopware.Mixin.getByName('notification')],

        data: function () {
            return {
                isSaving: false,
                support: SUPPORT,
                showDocs: false,
                activeDoc: 'readme',
                docLanguage: 'de-DE',
                docHtml: '',
                docLoading: false,
                docError: false,
                docUrl: '',
                docStatus: ''
            };
        },

        computed: {
            pluginVersion: function () {
                return PLUGIN_VERSION;
            },

            iconUrl: function () {
                return assetBase() + '/img/plugin.svg';
            },

            configDomain: function () {
                return CONFIG_DOMAIN;
            },

            docTabs: function () {
                return [
                    { key: 'readme', label: this.$t('modulwerk-webp.doc.readme') },
                    { key: 'changelog', label: this.$t('modulwerk-webp.doc.changelog') },
                    { key: 'license', label: this.$t('modulwerk-webp.doc.license') }
                ];
            },

            docLanguages: function () {
                return [
                    { key: 'de-DE', label: 'Deutsch' },
                    { key: 'en-GB', label: 'English' }
                ];
            },

            shopwareVersion: function () {
                var context = Shopware.Context;

                if (context && context.app && context.app.config && context.app.config.version) {
                    return context.app.config.version;
                }

                return this.$t('modulwerk-webp.help.unknown');
            },

            infoRows: function () {
                return [
                    {
                        label: this.$t('modulwerk-webp.help.labelPlugin'),
                        value: this.$t('modulwerk-webp.general.mainMenuItemGeneral')
                    },
                    {
                        label: this.$t('modulwerk-webp.help.labelTechnical'),
                        value: TECHNICAL_NAME
                    },
                    {
                        label: this.$t('modulwerk-webp.help.labelVersion'),
                        value: PLUGIN_VERSION
                    },
                    {
                        label: this.$t('modulwerk-webp.help.labelShopware'),
                        value: this.shopwareVersion
                    },
                    {
                        label: this.$t('modulwerk-webp.help.labelLicense'),
                        value: LICENSE
                    },
                    {
                        label: this.$t('modulwerk-webp.help.labelDomain'),
                        value: CONFIG_DOMAIN
                    }
                ];
            },

            infoText: function () {
                return this.infoRows.map(function (row) {
                    return row.label + ': ' + row.value;
                }).join('\n');
            }
        },

        methods: {
            onSave: function () {
                var that = this;
                var config = this.$refs.systemConfig;

                if (!config || typeof config.saveAll !== 'function') {
                    return Promise.resolve();
                }

                this.isSaving = true;

                return config.saveAll()
                    .then(function () {
                        that.createNotificationSuccess({
                            message: that.$t('modulwerk-webp.settings.saveSuccess')
                        });
                    })
                    .catch(function (error) {
                        that.createNotificationError({
                            message: that.$t('modulwerk-webp.settings.saveError') + errorMessage(error)
                        });
                    })
                    .finally(function () {
                        that.isSaving = false;
                    });
            },

            onOpenDocs: function () {
                /* Sprache der Oberflaeche als Vorauswahl uebernehmen */
                var locale = Shopware.Context && Shopware.Context.app
                    ? Shopware.Context.app.adminLocale
                    : null;

                this.docLanguage = (locale && locale.indexOf('de') === 0) ? 'de-DE' : 'en-GB';
                this.showDocs = true;

                return this.loadDoc();
            },

            onCloseDocs: function () {
                this.showDocs = false;
            },

            onSelectDoc: function (key) {
                this.activeDoc = key;

                return this.loadDoc();
            },

            onSelectLanguage: function (key) {
                this.docLanguage = key;

                return this.loadDoc();
            },

            loadDoc: function () {
                var that = this;
                var files = DOCUMENTS[this.activeDoc];

                if (!files) {
                    return Promise.resolve();
                }

                var file = files[this.docLanguage] || files['de-DE'];

                this.docLoading = true;
                this.docError = false;
                this.docHtml = '';

                var url = assetBase() + '/doc/' + file;

                this.docUrl = url;
                this.docStatus = '';

                /* Eingebettete Fassung bevorzugen */
                if (DOC_DATA[file]) {
                    try {
                        this.docHtml = renderMarkdown(decodeDoc(DOC_DATA[file]));
                        this.docLoading = false;

                        return Promise.resolve();
                    } catch (error) {
                        if (window.console && window.console.error) {
                            window.console.error('[ModulwerkWebp] doc decode', error);
                        }
                    }
                }

                return fetch(url, { cache: 'no-cache' })
                    .then(function (response) {
                        that.docStatus = 'HTTP ' + response.status;

                        if (!response.ok) {
                            throw new Error(that.docStatus);
                        }

                        return response.text();
                    })
                    .then(function (text) {
                        /*
                         * Liefert der Server statt der Datei die index.php
                         * aus (haeufig bei fehlendem assets:install), kommt
                         * HTML zurueck. Das wuerde als leere Seite enden,
                         * deshalb wird es hier erkannt.
                         */
                        if (/^\s*<(!doctype|html)/i.test(text)) {
                            that.docStatus = 'HTML statt Markdown';
                            throw new Error(that.docStatus);
                        }

                        var html = renderMarkdown(text);

                        if (html.replace(/\s/g, '') === '') {
                            /* Nichts erkannt: dann wenigstens den Rohtext */
                            that.docHtml = '<pre>' + escapeHtml(text) + '</pre>';
                            return;
                        }

                        that.docHtml = html;
                    })
                    .catch(function (error) {
                        that.docError = true;

                        if (window.console && window.console.error) {
                            window.console.error('[ModulwerkWebp] doc', url, error);
                        }
                    })
                    .finally(function () {
                        that.docLoading = false;
                    });
            },

            onCopyInfo: function () {
                var that = this;

                return copyToClipboard(this.infoText)
                    .then(function () {
                        that.createNotificationSuccess({
                            message: that.$t('modulwerk-webp.help.copySuccess')
                        });
                    })
                    .catch(function () {
                        that.createNotificationError({
                            message: that.$t('modulwerk-webp.help.copyError')
                        });
                    });
            },

            onSupportMail: function () {
                var subject = this.$t('modulwerk-webp.help.mailSubject')
                    + TECHNICAL_NAME + ' ' + PLUGIN_VERSION;

                var body = this.$t('modulwerk-webp.help.mailIntro')
                    + '\n\n---\n' + this.infoText;

                window.location.href = 'mailto:' + SUPPORT.mail
                    + '?subject=' + encodeURIComponent(subject)
                    + '&body=' + encodeURIComponent(body);
            },

            onWebsite: function () {
                window.open(SUPPORT.website, '_blank', 'noopener');
            },

            onCancel: function () {
                this.$router.push({ name: 'modulwerk.webp.overview' });
            }
        }
    });

    /* ------------------------------------------------------------------ */
    /* Plugin-Icon fuer Einstellungen > Erweiterungen                      */
    /*                                                                     */
    /* sw-settings-index rendert statt des mt-icon eine eigene Komponente, */
    /* wenn der settingsItem-Eintrag 'iconComponent' mitbringt.            */
    /* ------------------------------------------------------------------ */

    Component.register('modulwerk-webp-settings-icon', {
        template: '<img class="modulwerk-settings-icon" :src="iconUrl" alt="">',

        computed: {
            iconUrl: function () {
                return assetBase() + '/img/plugin.svg';
            }
        }
    });

    /* ------------------------------------------------------------------ */
    /* Modul                                                               */
    /* ------------------------------------------------------------------ */

    /*
     * Das Hauptmenue wird beim Start einmal aus den registrierten Modulen
     * aufgebaut. Wird das Plugin-Asset erst danach geladen, fehlt der
     * Eintrag unter "Inhalte", obwohl das Modul registriert ist (der
     * Eintrag unter Einstellungen wird dagegen bei jedem Aufruf neu
     * gelesen und ist deshalb da). Deshalb wird die Liste hier nachtraeglich
     * erneuert, bis der eigene Eintrag enthalten ist.
     */
    function ensureMenuEntry(attempt) {
        attempt = attempt || 0;

        try {
            var store = Shopware.Store && typeof Shopware.Store.get === 'function'
                ? Shopware.Store.get('adminMenu')
                : null;
            var menuService = Shopware.Service('menuService');

            if (store && menuService && typeof menuService.getNavigationFromAdminModules === 'function') {
                var entries = store.adminModuleNavigation || [];
                var found = entries.some(function (entry) {
                    return entry && entry.id === 'modulwerk-webp';
                });

                if (found) {
                    return;
                }

                store.adminModuleNavigation = menuService.getNavigationFromAdminModules();
            }
        } catch (error) {
            /* Store oder Service noch nicht da - naechster Versuch */
        }

        if (attempt < 40) {
            window.setTimeout(function () {
                ensureMenuEntry(attempt + 1);
            }, 500);
        }
    }

    Module.register('modulwerk-webp', {
        type: 'plugin',
        name: 'modulwerk-webp',
        title: 'modulwerk-webp.general.mainMenuItemGeneral',
        description: 'modulwerk-webp.general.descriptionTextModule',
        color: '#ff68b4',
        icon: 'regular-image',

        snippets: SNIPPETS,

        routes: {
            overview: {
                component: 'modulwerk-webp-overview',
                path: 'overview',
                meta: {
                    privilege: 'media.viewer'
                }
            },
            settings: {
                component: 'modulwerk-webp-settings',
                path: 'settings',
                meta: {
                    parentPath: 'modulwerk.webp.overview',
                    privilege: 'system.plugin_maintain'
                }
            }
        },

        /*
         * Zusaetzlicher Eintrag unter Einstellungen > Erweiterungen.
         * Zeigt direkt auf die Einstellungsseite.
         */
        settingsItem: [{
            group: 'plugins',
            to: 'modulwerk.webp.settings',
            icon: 'regular-image',
            iconComponent: 'modulwerk-webp-settings-icon',
            label: 'modulwerk-webp.general.mainMenuItemGeneral',
            privilege: 'system.plugin_maintain'
        }],

        /*
         * Menuepunkt unter "Inhalte", direkt bei den Medien.
         * Seit Shopware 6.4 werden Eintraege ohne 'parent' nicht gerendert.
         */
        navigation: [{
            id: 'modulwerk-webp',
            parent: 'sw-content',
            label: 'modulwerk-webp.general.mainMenuItemGeneral',
            path: 'modulwerk.webp.overview',
            icon: 'regular-image',
            color: '#ff68b4',
            position: 100,
            privilege: 'media.viewer'
        }]
    });

    ensureMenuEntry();

    /* Styling ohne SCSS-Build */
    var LOGO_URL = assetBase() + '/img/plugin.svg';

    /*
     * Die Karten aus sw-system-config kommen aus der config.xml und lassen
     * sich im Template nicht anfassen. Ihre Ueberschrift bekommt das Logo
     * deshalb per CSS, damit sie genauso aussieht wie die eigenen Karten.
     * Meteor und die aelteren sw-Karten werden beide adressiert.
     */
    var CARD_TITLE_SELECTORS = [
        '.modulwerk-webp-settings .mt-card__title',
        '.modulwerk-webp-settings .sw-card__title',
        '.modulwerk-webp-settings .mt-card__header .mt-card__title-wrapper',
        '.modulwerk-webp-overview .mt-card__title',
        '.modulwerk-webp-overview .sw-card__title'
    ];

    var style = document.createElement('style');
    style.textContent = [
        '.modulwerk-webp-table{width:100%;border-collapse:collapse;font-size:13px}',
        '.modulwerk-webp-table th{text-align:left;font-weight:600;padding:8px 12px 8px 0;white-space:nowrap;color:#52667a}',
        '.modulwerk-webp-table td{padding:8px 12px 8px 0;border-top:1px solid #e0e6eb;vertical-align:middle}',
        '.modulwerk-webp-table td.is--num{text-align:right;white-space:nowrap}',
        '.modulwerk-webp-table__path{max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:monospace;font-size:12px}',
        '.modulwerk-webp-table__msg{color:#758ca3;max-width:280px}',
        '.modulwerk-webp-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:20px}',
        '.modulwerk-webp-stat{padding:12px 14px;border:1px solid #dfe3e8;border-radius:4px;background:#f9fafb}',
        '.modulwerk-webp-stat__value{display:block;font-size:22px;font-weight:600;color:#2b3136;line-height:1.2}',
        '.modulwerk-webp-stat__label{display:block;margin-top:4px;color:#758ca3;font-size:12px}',
        '.modulwerk-webp-stat.is--good .modulwerk-webp-stat__value{color:#1f6c3d}',
        '.modulwerk-webp-stat.is--warn .modulwerk-webp-stat__value{color:#c2410c}',
        '.modulwerk-webp-progress{height:10px;border-radius:5px;background:#e0e6eb;overflow:hidden;margin:4px 0 8px 0}',
        '.modulwerk-webp-progress__bar{height:100%;background:#37d046;transition:width .3s ease}',
        '.modulwerk-webp-progress__text{margin:0 0 16px 0;color:#52667a;font-size:13px}',
        '.modulwerk-webp-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}',
        '.modulwerk-webp-hint{margin:12px 0 0 0;padding:10px 12px;border-left:3px solid #189eff;background:#f0f6fa;color:#52667a;font-size:13px}',
        '.modulwerk-webp-hint.is--error{border-left-color:#de294c;background:#fdf0f2}',
        '.modulwerk-webp-badge{display:inline-block;padding:1px 8px;border-radius:2px;font-size:12px;font-weight:600;white-space:nowrap}',
        '.modulwerk-webp-badge.is--converted{background:#e6f6ec;color:#1f6c3d}',
        '.modulwerk-webp-badge.is--skipped{background:#f0f2f5;color:#52667a}',
        '.modulwerk-webp-badge.is--error{background:#fdf0f2;color:#b3163a}',
        '.modulwerk-webp-filter{display:flex;gap:4px;margin-bottom:12px}',
        '.modulwerk-webp-filter button{padding:4px 10px;border:1px solid #dfe3e8;border-radius:2px;background:#fff;color:#52667a;cursor:pointer;font-size:12px}',
        '.modulwerk-webp-filter button.is--active{background:#189eff;border-color:#189eff;color:#fff}',
        '.modulwerk-card-title{display:flex;align-items:center;gap:10px;margin-bottom:24px;padding-bottom:14px;border-bottom:1px solid #dfe3e8;color:#2b3136;font-size:16px;font-weight:600;line-height:1.3}',
        '.modulwerk-card-logo{width:20px;height:20px;object-fit:contain;border-radius:4px;flex:0 0 auto}',
        '.modulwerk-support{display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap}',
        '.modulwerk-support__logo{width:56px;height:56px;object-fit:contain;border-radius:8px;flex:0 0 auto}',
        '.modulwerk-support__body{flex:1 1 240px;min-width:0}',
        '.modulwerk-support__name{margin:0 0 4px 0;font-weight:600}',
        '.modulwerk-support__text{margin:0 0 8px 0;color:#758ca3}',
        '.modulwerk-support__link{display:block;text-decoration:none}',
        '.modulwerk-support__link:hover{text-decoration:underline}',
        '.modulwerk-support__meta{display:flex;flex-direction:column;gap:2px;text-align:right;color:#758ca3;flex:0 0 auto}',
        '.modulwerk-support__meta strong{color:#52667a}',
        '.modulwerk-help__text{margin:0 0 16px 0;color:#758ca3}',
        '.modulwerk-help__list{display:grid;grid-template-columns:auto 1fr;gap:6px 24px;margin:0 0 20px 0}',
        '.modulwerk-help__list dt{color:#758ca3;margin:0}',
        '.modulwerk-help__list dd{margin:0;color:#2b3136;word-break:break-word}',
        '.modulwerk-help__actions{display:flex;gap:8px;flex-wrap:wrap}',
        '.modulwerk-save-hint{display:inline-flex;align-items:center;margin-right:8px;padding:4px;border:0;border-radius:2px;background:transparent;color:#758ca3;cursor:pointer}',
        '.modulwerk-save-hint:hover{color:#189eff;background:#f0f6fa}',
        '.modulwerk-doc__bar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px;border-bottom:1px solid #dfe3e8}',
        '.modulwerk-doc__tabs,.modulwerk-doc__langs{display:flex;gap:4px}',
        '.modulwerk-doc__tab,.modulwerk-doc__lang{padding:8px 12px;border:0;background:transparent;color:#52667a;cursor:pointer;border-bottom:2px solid transparent;font-size:14px}',
        '.modulwerk-doc__tab.is--active{color:#189eff;border-bottom-color:#189eff;font-weight:600}',
        '.modulwerk-doc__lang{border-bottom:0;border-radius:2px}',
        '.modulwerk-doc__lang.is--active{background:#e6f6ec;border:1px solid #b8e2c8;color:#1f6c3d;font-weight:600}',
        '.modulwerk-doc__body{max-height:60vh;overflow:auto}',
        '.modulwerk-doc__error{color:#758ca3}',
        '.modulwerk-doc__error p{margin:0 0 6px 0}',
        '.modulwerk-doc__content h2{margin:0 0 12px 0;font-size:20px}',
        '.modulwerk-doc__content h3{margin:24px 0 8px 0;font-size:16px}',
        '.modulwerk-doc__content h4,.modulwerk-doc__content h5{margin:16px 0 6px 0;font-size:14px}',
        '.modulwerk-doc__content p{margin:0 0 8px 0;line-height:1.5}',
        '.modulwerk-doc__content ul{margin:0 0 12px 0;padding-left:20px}',
        '.modulwerk-doc__content li{margin-bottom:4px;line-height:1.5}',
        '.modulwerk-doc__content code{padding:1px 4px;border-radius:2px;background:#f0f2f5;font-family:monospace;font-size:13px}',
        '.modulwerk-doc__content pre{margin:0 0 12px 0;padding:12px;border-radius:2px;background:#f0f2f5;overflow:auto}',
        '.modulwerk-doc__content pre code{padding:0;background:transparent}',
        '.modulwerk-doc__table{border-collapse:collapse;margin:0 0 12px 0;width:100%}',
        '.modulwerk-doc__table td{padding:6px 10px;border:1px solid #dfe3e8;vertical-align:top}',
        CARD_TITLE_SELECTORS.join(',') + '{display:flex;align-items:center;gap:10px;color:#2b3136;font-size:16px;font-weight:600;line-height:1.3}',
        CARD_TITLE_SELECTORS.map(function (selector) { return selector + '::before'; }).join(',')
            + '{content:"";display:inline-block;width:20px;height:20px;flex:0 0 auto;border-radius:4px;'
            + 'background-image:url(' + LOGO_URL + ');background-size:contain;'
            + 'background-repeat:no-repeat;background-position:center}',
        '.modulwerk-settings-icon{display:block;width:20px;height:20px;object-fit:contain;border-radius:4px}',
        '.modulwerk-page-title{display:flex;flex-direction:column;align-items:flex-start;gap:4px;line-height:1.2}',
        '.modulwerk-page-title h2{margin:0}',
        '.modulwerk-version-badge{display:inline-block;padding:1px 8px;border-radius:2px;background:#e6f6ec;border:1px solid #b8e2c8;color:#1f6c3d;font-size:12px;font-weight:600;white-space:nowrap}'
    ].join('');
    document.head.appendChild(style);
})();

