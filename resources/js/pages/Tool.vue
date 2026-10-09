<template>
    <div class="logs-view">
        <Head title="Visibilidade de logs" />

        <header class="logs-view__header">
            <div>
                <p class="logs-view__eyebrow">Observabilidade</p>
                <Heading>Visibilidade de logs</Heading>
                <p class="logs-view__subtitle">
                    Eventos locais da aplicação, organizados e protegidos para
                    diagnóstico.
                </p>
            </div>
            <div class="logs-header-actions">
                <label class="logs-auto-refresh">
                    <span>Atualização automática</span>
                    <select
                        v-model.number="autoRefresh"
                        @change="configureAutoRefresh"
                    >
                        <option :value="0">Desligada</option>
                        <option :value="30">A cada 30 s</option>
                        <option :value="60">A cada 60 s</option>
                    </select>
                </label>
                <button
                    class="logs-button logs-button--primary"
                    type="button"
                    :disabled="loading"
                    @click="load"
                >
                    <span aria-hidden="true">↻</span>
                    {{ loading ? "Atualizando…" : "Atualizar" }}
                </button>
                <small v-if="lastUpdatedAt" class="logs-last-update">
                    Atualizado {{ relativeDate(lastUpdatedAt) }}
                </small>
            </div>
        </header>

        <div v-if="error" class="logs-notice logs-notice--error" role="alert">
            <strong>Não foi possível ler os logs.</strong>
            <span>{{ error }}</span>
            <button type="button" @click="load">Tentar novamente</button>
        </div>

        <div v-if="limitations.truncated" class="logs-notice" role="status">
            A visualização foi limitada para manter o painel responsivo.
            <span v-if="limitations.truncated_files?.length">
                Arquivos parciais: {{ limitations.truncated_files.join(", ") }}.
            </span>
        </div>

        <section class="logs-kpis" aria-label="Resumo dos logs">
            <article class="logs-kpi">
                <span>Eventos encontrados</span>
                <strong>{{ number(summary.total) }}</strong>
                <small>após os filtros</small>
            </article>
            <article class="logs-kpi logs-kpi--danger">
                <span>Erros críticos</span>
                <strong>{{ number(summary.errors) }}</strong>
                <small>error, critical, alert e emergency</small>
            </article>
            <article class="logs-kpi logs-kpi--warning">
                <span>Avisos</span>
                <strong>{{ number(summary.warnings) }}</strong>
                <small>eventos warning</small>
            </article>
            <article class="logs-kpi logs-kpi--danger">
                <span>Taxa de falhas</span>
                <strong>{{ summary.failure_rate || 0 }}%</strong>
                <small>erros críticos no resultado atual</small>
            </article>
            <article class="logs-kpi">
                <span>Problemas distintos</span>
                <strong>{{ number(summary.unique_problems) }}</strong>
                <small>assinaturas de warning ou erro</small>
            </article>
            <article class="logs-kpi">
                <span>Arquivos visíveis</span>
                <strong>{{ number(files.length) }}</strong>
                <small>somente arquivos permitidos</small>
            </article>
        </section>

        <section class="logs-dashboard">
            <Card class="logs-panel">
                <div class="logs-panel__title">
                    <div>
                        <strong>Atividade recente</strong
                        ><span>Eventos por hora</span>
                    </div>
                </div>
                <div
                    v-if="activity.length"
                    class="logs-activity"
                    role="img"
                    aria-label="Gráfico de atividade recente"
                >
                    <div
                        v-for="item in activity"
                        :key="item.label"
                        class="logs-activity__column"
                    >
                        <span class="logs-activity__value">{{
                            item.value
                        }}</span>
                        <i :style="{ height: `${item.height}%` }"></i>
                        <small>{{ item.label }}</small>
                    </div>
                </div>
                <div v-else class="logs-empty logs-empty--small">
                    Nenhuma atividade no período selecionado.
                </div>
            </Card>

            <Card class="logs-panel">
                <div class="logs-panel__title">
                    <div>
                        <strong>Distribuição por nível</strong
                        ><span>Severidade dos eventos</span>
                    </div>
                </div>
                <div v-if="levelRows.length" class="logs-bars">
                    <div
                        v-for="item in levelRows"
                        :key="item.label"
                        class="logs-bar"
                    >
                        <div>
                            <span
                                ><i :class="levelClass(item.label)"></i
                                >{{ item.label }}</span
                            ><strong>{{ item.value }}</strong>
                        </div>
                        <progress
                            :value="item.value"
                            :max="summary.total || 1"
                        ></progress>
                    </div>
                </div>
                <div v-else class="logs-empty logs-empty--small">
                    Nenhum nível identificado.
                </div>
            </Card>

            <Card class="logs-panel">
                <div class="logs-panel__title">
                    <div>
                        <strong>Tipos mais frequentes</strong
                        ><span>Origem funcional dos eventos</span>
                    </div>
                </div>
                <ol v-if="typeRows.length" class="logs-ranking">
                    <li v-for="(item, index) in typeRows" :key="item.label">
                        <span class="logs-ranking__position">{{
                            index + 1
                        }}</span>
                        <span class="logs-ranking__label" :title="item.label">{{
                            item.label
                        }}</span>
                        <strong>{{ item.value }}</strong>
                    </li>
                </ol>
                <div v-else class="logs-empty logs-empty--small">
                    Nenhum tipo identificado.
                </div>
            </Card>

            <Card class="logs-panel">
                <div class="logs-panel__title">
                    <div>
                        <strong>Canais mais ativos</strong
                        ><span>Ambientes e componentes</span>
                    </div>
                </div>
                <ol v-if="channelRows.length" class="logs-ranking">
                    <li v-for="(item, index) in channelRows" :key="item.label">
                        <span class="logs-ranking__position">{{
                            index + 1
                        }}</span>
                        <span class="logs-ranking__label" :title="item.label">{{
                            item.label
                        }}</span>
                        <strong>{{ item.value }}</strong>
                    </li>
                </ol>
                <div v-else class="logs-empty logs-empty--small">
                    Nenhum canal identificado.
                </div>
            </Card>
        </section>

        <Card class="logs-problems">
            <div class="logs-panel__title">
                <div>
                    <strong>Problemas recorrentes</strong>
                    <span
                        >Erros e avisos agrupados por assinatura
                        diagnóstica</span
                    >
                </div>
                <span
                    v-if="filters.fingerprint"
                    class="logs-investigation-badge"
                >
                    Grupo selecionado
                </span>
            </div>
            <div v-if="topProblems.length" class="logs-problems__list">
                <button
                    v-for="problem in topProblems"
                    :key="problem.fingerprint"
                    type="button"
                    class="logs-problem"
                    :class="{
                        'logs-problem--active':
                            filters.fingerprint === problem.fingerprint,
                    }"
                    @click="applyProblem(problem)"
                >
                    <span
                        class="logs-level"
                        :class="levelClass(problem.level)"
                        >{{ problem.level }}</span
                    >
                    <span class="logs-problem__content">
                        <strong>{{ problem.type }}</strong>
                        <small>{{ problem.message }}</small>
                        <small>
                            {{ date(problem.first_seen) }} →
                            {{ date(problem.last_seen) }} ·
                            {{ problem.channels.length }} canal(is) ·
                            {{ problem.files.length }} arquivo(s)
                        </small>
                    </span>
                    <span class="logs-problem__count">
                        <strong>{{ number(problem.occurrences) }}</strong>
                        <small>ocorrências</small>
                    </span>
                </button>
            </div>
            <div v-else class="logs-empty logs-empty--small">
                Nenhum erro ou aviso recorrente no recorte atual.
            </div>
        </Card>

        <Card class="logs-filters mp-advanced-filter-panel">
            <div class="logs-panel__title logs-panel__title--filters">
                <div>
                    <h2 class="mp-advanced-filter-panel__title">FILTROS AVANÇADOS</h2>
                    <span>Refine a investigação sem alterar os arquivos</span>
                </div>
                <button type="button" class="logs-link" @click="resetFilters">
                    Limpar filtros
                </button>
            </div>
            <div
                class="logs-quick-filters"
                aria-label="Atalhos de investigação"
            >
                <button
                    type="button"
                    :aria-pressed="filters.severity === 'failures'"
                    @click="toggleShortcut('severity', 'failures')"
                >
                    Somente falhas
                </button>
                <button
                    type="button"
                    :aria-pressed="filters.has_trace === '1'"
                    @click="toggleShortcut('has_trace', '1')"
                >
                    Com rastreamento
                </button>
                <button
                    v-if="filters.fingerprint"
                    type="button"
                    class="logs-quick-filters__active"
                    @click="clearFingerprint"
                >
                    Limpar grupo {{ filters.fingerprint.slice(0, 8) }}
                </button>
            </div>
            <div class="logs-filters__grid">
                <label class="logs-field logs-field--search">
                    <span>Buscar</span>
                    <input
                        v-model="filters.search"
                        type="search"
                        maxlength="200"
                        placeholder="Mensagem, tipo, canal ou contexto"
                        @input="scheduleLoad"
                    />
                </label>
                <label class="logs-field">
                    <span>Arquivo</span>
                    <select v-model="filters.file" @change="filterChanged">
                        <option value="">Todos os arquivos</option>
                        <option
                            v-for="option in options.files"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label class="logs-field">
                    <span>Nível</span>
                    <select v-model="filters.level" @change="filterChanged">
                        <option value="">Todos os níveis</option>
                        <option
                            v-for="option in options.levels"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                </label>
                <label class="logs-field">
                    <span>Canal</span>
                    <select v-model="filters.channel" @change="filterChanged">
                        <option value="">Todos os canais</option>
                        <option
                            v-for="option in options.channels"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                </label>
                <label class="logs-field">
                    <span>Tipo</span>
                    <select v-model="filters.type" @change="filterChanged">
                        <option value="">Todos os tipos</option>
                        <option
                            v-for="option in options.types"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                </label>
                <label class="logs-field">
                    <span>Janela</span>
                    <select v-model="filters.period" @change="filterChanged">
                        <option value="">Todo o período lido</option>
                        <option value="1h">Última hora</option>
                        <option value="6h">Últimas 6 horas</option>
                        <option value="24h">Últimas 24 horas</option>
                        <option value="7d">Últimos 7 dias</option>
                        <option value="30d">Últimos 30 dias</option>
                    </select>
                </label>
                <label class="logs-field">
                    <span>De</span>
                    <input
                        v-model="filters.from"
                        type="date"
                        @change="filterChanged"
                    />
                </label>
                <label class="logs-field">
                    <span>Até</span>
                    <input
                        v-model="filters.to"
                        type="date"
                        @change="filterChanged"
                    />
                </label>
            </div>
        </Card>

        <Card class="logs-table-card">
            <div class="logs-table-card__header">
                <div>
                    <strong>Eventos</strong
                    ><span
                        >{{ number(meta.total) }} registro(s), mais recentes
                        primeiro</span
                    >
                </div>
                <label class="logs-per-page"
                    >Por página
                    <select
                        v-model.number="filters.per_page"
                        @change="filterChanged"
                    >
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </label>
            </div>

            <div
                v-if="loading && !entries.length"
                class="logs-loading"
                aria-live="polite"
            >
                <span class="logs-spinner"></span
                ><span>Lendo e classificando os logs…</span>
            </div>
            <div v-else-if="!entries.length" class="logs-empty">
                <strong>Nenhum evento encontrado</strong>
                <span
                    >Ajuste os filtros ou confirme se há arquivos de log
                    disponíveis.</span
                >
            </div>
            <div v-else class="logs-table-wrap">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Data e hora</th>
                            <th>Nível</th>
                            <th>Tipo / mensagem</th>
                            <th>Canal</th>
                            <th>Recorrência</th>
                            <th>Arquivo</th>
                            <th><span class="sr-only">Detalhes</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in entries"
                            :key="entry.id"
                            tabindex="0"
                            @click="openDetail(entry)"
                            @keydown.enter="openDetail(entry)"
                        >
                            <td class="logs-table__date">
                                {{ date(entry.timestamp) }}
                            </td>
                            <td>
                                <span
                                    class="logs-level"
                                    :class="levelClass(entry.level)"
                                    >{{ entry.level }}</span
                                >
                            </td>
                            <td class="logs-table__message">
                                <strong>{{ entry.type }}</strong
                                ><span>{{ entry.message }}</span>
                            </td>
                            <td>{{ entry.channel }}</td>
                            <td>
                                <span
                                    v-if="entry.diagnostic?.occurrences > 1"
                                    class="logs-recurrence"
                                    :title="`Mesma assinatura entre ${date(entry.diagnostic.first_seen)} e ${date(entry.diagnostic.last_seen)}`"
                                >
                                    ×{{ number(entry.diagnostic.occurrences) }}
                                </span>
                                <span
                                    v-else
                                    class="logs-recurrence logs-recurrence--single"
                                    >—</span
                                >
                            </td>
                            <td class="logs-table__file" :title="entry.file">
                                {{ entry.file }}
                            </td>
                            <td>
                                <button
                                    class="logs-detail-button"
                                    type="button"
                                    :aria-label="`Ver detalhes de ${entry.type}`"
                                >
                                    →
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer v-if="meta.last_page > 1" class="logs-pagination">
                <button
                    type="button"
                    :disabled="meta.current_page <= 1 || loading"
                    @click="goToPage(meta.current_page - 1)"
                >
                    Anterior
                </button>
                <span
                    >Página <strong>{{ meta.current_page }}</strong> de
                    {{ meta.last_page }}</span
                >
                <button
                    type="button"
                    :disabled="meta.current_page >= meta.last_page || loading"
                    @click="goToPage(meta.current_page + 1)"
                >
                    Próxima
                </button>
            </footer>
        </Card>

        <div
            v-if="detailOpen"
            class="logs-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="log-detail-title"
            @click.self="closeDetail"
        >
            <section class="logs-modal__panel">
                <header class="logs-modal__header">
                    <div>
                        <p>Detalhes do evento</p>
                        <h2 id="log-detail-title">
                            {{ selected?.type || "Carregando…" }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        aria-label="Fechar detalhes"
                        @click="closeDetail"
                    >
                        ×
                    </button>
                </header>
                <div
                    v-if="selected && !detailLoading && !detailError"
                    class="logs-copy-toolbar"
                    aria-label="Opções para copiar o evento"
                >
                    <div class="logs-copy-toolbar__buttons">
                        <button type="button" @click="copyEntry('text')">
                            Copiar texto
                        </button>
                        <button type="button" @click="copyEntry('markdown')">
                            Copiar MD
                        </button>
                        <button
                            class="logs-copy-toolbar__ai"
                            type="button"
                            @click="copyEntry('ai')"
                        >
                            Copiar MD para IA
                        </button>
                    </div>
                    <p
                        class="logs-copy-feedback"
                        :class="{
                            'logs-copy-feedback--error': copyStatusError,
                        }"
                        role="status"
                        aria-live="polite"
                    >
                        {{ copyStatus }}
                    </p>
                </div>
                <div v-if="detailLoading" class="logs-loading">
                    <span class="logs-spinner"></span
                    ><span>Carregando detalhes…</span>
                </div>
                <div
                    v-else-if="detailError"
                    class="logs-notice logs-notice--error"
                >
                    {{ detailError }}
                </div>
                <div v-else-if="selected" class="logs-modal__content">
                    <dl class="logs-metadata">
                        <div>
                            <dt>Data e hora</dt>
                            <dd>{{ date(selected.timestamp) }}</dd>
                        </div>
                        <div>
                            <dt>Nível</dt>
                            <dd>
                                <span
                                    class="logs-level"
                                    :class="levelClass(selected.level)"
                                    >{{ selected.level }}</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt>Canal</dt>
                            <dd>{{ selected.channel }}</dd>
                        </div>
                        <div>
                            <dt>Arquivo</dt>
                            <dd>{{ selected.file }}</dd>
                        </div>
                    </dl>
                    <section>
                        <h3>Mensagem</h3>
                        <pre>{{ selected.message }}</pre>
                    </section>
                    <section v-if="selected.has_context">
                        <h3>Contexto sanitizado</h3>
                        <pre>{{ json(selected.context) }}</pre>
                    </section>
                    <section v-if="selected.has_trace">
                        <h3>Rastreamento</h3>
                        <pre class="logs-trace">{{ selected.trace }}</pre>
                    </section>
                    <section v-if="selected.diagnostic" class="logs-diagnostic">
                        <h3>Evidência diagnóstica</h3>
                        <dl>
                            <div>
                                <dt>Assinatura</dt>
                                <dd>
                                    <code>{{
                                        selected.diagnostic.fingerprint
                                    }}</code>
                                </dd>
                            </div>
                            <div v-if="selected.diagnostic.occurrences">
                                <dt>Recorrência no recorte</dt>
                                <dd>
                                    {{
                                        number(selected.diagnostic.occurrences)
                                    }}
                                    ocorrência(s)
                                </dd>
                            </div>
                            <div v-if="selected.diagnostic.first_seen">
                                <dt>Primeira ocorrência</dt>
                                <dd>
                                    {{ date(selected.diagnostic.first_seen) }}
                                </dd>
                            </div>
                            <div v-if="selected.diagnostic.last_seen">
                                <dt>Última ocorrência</dt>
                                <dd>
                                    {{ date(selected.diagnostic.last_seen) }}
                                </dd>
                            </div>
                        </dl>
                        <div
                            v-if="selected.diagnostic.trace_hint"
                            class="logs-trace-hint"
                        >
                            <strong>Primeiro frame útil sanitizado</strong>
                            <code>{{ selected.diagnostic.trace_hint }}</code>
                        </div>
                        <p>
                            Esses dados ajudam a direcionar a investigação; não
                            representam uma causa raiz automática.
                        </p>
                    </section>
                    <p class="logs-modal__security">
                        Valores sensíveis são mascarados antes de chegar ao
                        navegador.
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>

<script>
import {
    logAsAiMarkdown,
    logAsMarkdown,
    logAsText,
} from "../log-copy-formats.mjs";

const emptySummary = () => ({
    total: 0,
    errors: 0,
    warnings: 0,
    failure_rate: 0,
    unique_problems: 0,
    latest_failure_at: null,
    levels: {},
    top_types: {},
    top_channels: {},
    activity: {},
    top_problems: [],
});

export default {
    data: () => ({
        loading: true,
        error: null,
        entries: [],
        files: [],
        summary: emptySummary(),
        options: { files: [], levels: [], channels: [], types: [] },
        limitations: { truncated: false, truncated_files: [] },
        meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
        filters: {
            search: "",
            file: "",
            level: "",
            channel: "",
            type: "",
            severity: "",
            has_trace: "",
            period: "",
            fingerprint: "",
            from: "",
            to: "",
            page: 1,
            per_page: 50,
        },
        detailOpen: false,
        detailLoading: false,
        detailError: null,
        selected: null,
        copyStatus: "",
        copyStatusError: false,
        copyResetTimer: null,
        timer: null,
        autoRefresh: 0,
        autoRefreshTimer: null,
        lastUpdatedAt: null,
        requestSequence: 0,
    }),
    computed: {
        activity() {
            const rows = Object.entries(this.summary.activity || {}).map(
                ([label, value]) => ({ label, value }),
            );
            const max = Math.max(1, ...rows.map((item) => item.value));
            return rows.map((item) => ({
                ...item,
                height: Math.max(8, Math.round((item.value / max) * 100)),
            }));
        },
        levelRows() {
            return Object.entries(this.summary.levels || {}).map(
                ([label, value]) => ({ label, value }),
            );
        },
        typeRows() {
            return Object.entries(this.summary.top_types || {}).map(
                ([label, value]) => ({ label, value }),
            );
        },
        channelRows() {
            return Object.entries(this.summary.top_channels || {}).map(
                ([label, value]) => ({ label, value }),
            );
        },
        topProblems() {
            return this.summary.top_problems || [];
        },
    },
    mounted() {
        window.addEventListener("keydown", this.handleKeydown);
        document.addEventListener(
            "visibilitychange",
            this.handleVisibilityChange,
        );
        this.load();
    },
    beforeUnmount() {
        window.removeEventListener("keydown", this.handleKeydown);
        document.removeEventListener(
            "visibilitychange",
            this.handleVisibilityChange,
        );
        clearTimeout(this.timer);
        clearTimeout(this.copyResetTimer);
        clearTimeout(this.autoRefreshTimer);
    },
    methods: {
        async load() {
            clearTimeout(this.autoRefreshTimer);
            const sequence = ++this.requestSequence;
            this.loading = true;
            this.error = null;
            try {
                const { data } = await Nova.request().get(
                    "/nova-vendor/nova-logs-view/entries",
                    { params: this.filters },
                );
                if (sequence !== this.requestSequence) return;
                this.entries = data.data;
                this.files = data.files;
                this.summary = data.summary;
                this.options = data.options;
                this.limitations = data.limitations;
                this.meta = data.meta;
                this.filters.page = data.meta.current_page;
                this.lastUpdatedAt = new Date();
            } catch (error) {
                if (sequence !== this.requestSequence) return;
                this.error =
                    error.response?.status === 403
                        ? "Seu usuário não tem permissão administrativa para esta ferramenta."
                        : error.response?.data?.message ||
                          "Ocorreu um erro inesperado ao consultar os arquivos.";
            } finally {
                if (sequence === this.requestSequence) {
                    this.loading = false;
                    this.scheduleAutoRefresh();
                }
            }
        },
        scheduleLoad() {
            clearTimeout(this.timer);
            this.filters.page = 1;
            this.timer = setTimeout(this.load, 350);
        },
        filterChanged() {
            this.filters.page = 1;
            this.load();
        },
        resetFilters() {
            this.filters = {
                search: "",
                file: "",
                level: "",
                channel: "",
                type: "",
                severity: "",
                has_trace: "",
                period: "",
                fingerprint: "",
                from: "",
                to: "",
                page: 1,
                per_page: 50,
            };
            this.load();
        },
        toggleShortcut(key, value) {
            this.filters[key] = this.filters[key] === value ? "" : value;
            this.filterChanged();
        },
        applyProblem(problem) {
            this.filters.fingerprint = problem.fingerprint;
            this.filterChanged();
        },
        clearFingerprint() {
            this.filters.fingerprint = "";
            this.filterChanged();
        },
        goToPage(page) {
            this.filters.page = page;
            this.load();
            window.scrollTo({ top: 0, behavior: "smooth" });
        },
        async openDetail(entry) {
            this.detailOpen = true;
            this.detailLoading = true;
            this.detailError = null;
            this.copyStatus = "";
            this.copyStatusError = false;
            clearTimeout(this.copyResetTimer);
            clearTimeout(this.autoRefreshTimer);
            this.selected = entry;
            try {
                const { data } = await Nova.request().get(
                    `/nova-vendor/nova-logs-view/entries/${entry.id}`,
                    { params: { file: entry.file_id } },
                );
                this.selected = {
                    ...this.selected,
                    ...data.data,
                    diagnostic: {
                        ...(this.selected?.diagnostic || {}),
                        ...(data.data.diagnostic || {}),
                    },
                };
            } catch (error) {
                this.detailError =
                    error.response?.status === 404
                        ? "Este evento não está mais disponível. O arquivo pode ter sido rotacionado."
                        : "Não foi possível carregar os detalhes.";
            } finally {
                this.detailLoading = false;
            }
        },
        closeDetail() {
            this.detailOpen = false;
            this.selected = null;
            this.detailError = null;
            this.copyStatus = "";
            this.copyStatusError = false;
            clearTimeout(this.copyResetTimer);
            this.scheduleAutoRefresh();
        },
        configureAutoRefresh() {
            clearTimeout(this.autoRefreshTimer);
            this.scheduleAutoRefresh();
        },
        scheduleAutoRefresh() {
            clearTimeout(this.autoRefreshTimer);

            if (
                !this.autoRefresh ||
                this.detailOpen ||
                document.visibilityState !== "visible"
            ) {
                return;
            }

            this.autoRefreshTimer = setTimeout(
                () => this.load(),
                this.autoRefresh * 1000,
            );
        },
        handleVisibilityChange() {
            if (document.visibilityState === "visible") {
                this.scheduleAutoRefresh();
            } else {
                clearTimeout(this.autoRefreshTimer);
            }
        },
        async copyEntry(format) {
            if (!this.selected || this.detailLoading || this.detailError) {
                return;
            }

            const exporters = {
                text: [logAsText, "Texto copiado."],
                markdown: [logAsMarkdown, "Markdown copiado."],
                ai: [logAsAiMarkdown, "Markdown para IA copiado."],
            };
            const exporter = exporters[format];

            if (!exporter) return;

            try {
                await this.writeClipboard(exporter[0](this.selected));
                this.copyStatus = exporter[1];
                this.copyStatusError = false;
            } catch {
                this.copyStatus =
                    "Não foi possível copiar. Verifique a permissão do navegador.";
                this.copyStatusError = true;
            }

            clearTimeout(this.copyResetTimer);
            this.copyResetTimer = setTimeout(() => {
                this.copyStatus = "";
                this.copyStatusError = false;
            }, 3000);
        },
        async writeClipboard(content) {
            if (navigator.clipboard?.writeText && window.isSecureContext) {
                await navigator.clipboard.writeText(content);

                return;
            }

            const textarea = document.createElement("textarea");
            textarea.value = content;
            textarea.setAttribute("readonly", "");
            textarea.style.position = "fixed";
            textarea.style.opacity = "0";
            document.body.appendChild(textarea);
            textarea.select();

            try {
                if (!document.execCommand("copy")) {
                    throw new Error("Clipboard copy was rejected.");
                }
            } finally {
                textarea.remove();
            }
        },
        handleKeydown(event) {
            if (event.key === "Escape" && this.detailOpen) this.closeDetail();
        },
        levelClass(level) {
            return `logs-level--${String(level).toLowerCase()}`;
        },
        date(value) {
            return value
                ? new Intl.DateTimeFormat("pt-BR", {
                      dateStyle: "short",
                      timeStyle: "medium",
                  }).format(new Date(value))
                : "Sem data";
        },
        relativeDate(value) {
            const seconds = Math.max(
                0,
                Math.round((Date.now() - new Date(value).getTime()) / 1000),
            );

            if (seconds < 5) return "agora";
            if (seconds < 60) return `há ${seconds} s`;

            return `há ${Math.floor(seconds / 60)} min`;
        },
        number(value) {
            return new Intl.NumberFormat("pt-BR").format(value || 0);
        },
        json(value) {
            return JSON.stringify(value, null, 2);
        },
    },
};
</script>
