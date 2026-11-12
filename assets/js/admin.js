/* WC Git Sync v2 – Admin SPA */
;(function ($) {
    'use strict';

    const { ajaxUrl, nonce, intervals } = wcgsData;

    /* ──────────────────────────────────── STATE ── */
    let state = {
        projects:       [],   // WCGS_Project[]
        activeView:     'overview',   // 'overview' | 'project:{id}' | 'log' | 'settings'
        activeProject:  null,
        projectStatus:  {},   // { [id]: statusObj }
        loadingStatus:  {},
        modal:          null, // null | { mode:'add'|'edit', project?:obj }
    };

    /* ──────────────────────────────────── AJAX ── */
    function ajax(action, data = {}) {
        return $.post(ajaxUrl, { action, nonce, ...data });
    }

    /* ──────────────────────────────────── RENDER ── */
    function render() {
        const root = document.getElementById('wcgs-root');
        root.innerHTML = '';

        const shell = el('div', { class: 'wcgs wcgs-shell' });
        shell.appendChild(renderSidebar());
        shell.appendChild(renderTopbar());
        shell.appendChild(renderMain());

        root.appendChild(shell);

        if (state.modal) {
            root.appendChild(renderModal());
        }

        attachEvents();
    }

    /* ── SIDEBAR ── */
    function renderSidebar() {
        const s = el('nav', { class: 'wcgs-sidebar' });

        s.innerHTML = `
            <div class="sidebar-brand">
                <span class="dashicons dashicons-randomize brand-icon"></span>
                <span class="brand-name">Git Sync</span>
                <span class="brand-ver">v2</span>
            </div>
            <div class="sidebar-label">Visão Geral</div>
            <div class="sidebar-item ${v('overview')}" data-view="overview">
                <span class="dashicons dashicons-layout"></span> Dashboard
            </div>
            <div class="sidebar-divider"></div>
            <div class="sidebar-label">Projetos</div>
            ${state.projects.map(p => `
                <div class="sidebar-item ${v('project:' + p.id)}" data-view="project:${p.id}" title="${esc(p.repo_path)}">
                    <span class="dashicons dashicons-admin-plugins"></span>
                    <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(p.name)}</span>
                    ${statusBadgeMini(p.id)}
                </div>
            `).join('')}
            <div class="sidebar-add-btn" id="btn-add-project">
                <span class="dashicons dashicons-plus-alt2"></span> Novo Projeto
            </div>
            <div class="sidebar-divider"></div>
            <div class="sidebar-label">Sistema</div>
            <div class="sidebar-item ${v('log')}" data-view="log">
                <span class="dashicons dashicons-list-view"></span> Log Global
            </div>
        `;
        return s;
    }

    function statusBadgeMini(id) {
        const st = state.projectStatus[id];
        if (!st) return '';
        if (st.has_local_changes) return '<span class="proj-badge" style="color:var(--warn)">●</span>';
        return '<span class="proj-badge" style="color:var(--success)">●</span>';
    }

    /* ── TOPBAR ── */
    function renderTopbar() {
        const bar = el('div', { class: 'wcgs-topbar' });
        let title = 'Dashboard';
        if (state.activeView === 'log') title = 'Log Global';
        else if (state.activeView.startsWith('project:')) {
            const p = state.projects.find(p => p.id === state.activeView.split(':')[1]);
            title = p ? `<span>Projeto</span> <span class="project-name">${esc(p.name)}</span>` : 'Projeto';
        }
        bar.innerHTML = `
            <div class="topbar-title"><span class="dashicons dashicons-randomize" style="color:var(--accent)"></span> ${title}</div>
            <div class="topbar-actions">
                ${state.activeView.startsWith('project:') ? `
                    <button class="wcgs-btn btn-ghost btn-sm" id="btn-refresh-status">
                        <span class="dashicons dashicons-update"></span> Atualizar status
                    </button>
                ` : ''}
            </div>
        `;
        return bar;
    }

    /* ── MAIN ── */
    function renderMain() {
        const main = el('div', { class: 'wcgs-main' });

        if (state.activeView === 'overview') {
            main.appendChild(renderOverview());
        } else if (state.activeView.startsWith('project:')) {
            const id = state.activeView.split(':')[1];
            const p  = state.projects.find(p => p.id === id);
            if (p) main.appendChild(renderProjectView(p));
        } else if (state.activeView === 'log') {
            main.appendChild(renderLogView(null));
        }

        return main;
    }

    /* ── OVERVIEW ── */
    function renderOverview() {
        const wrap = el('div');
        wrap.innerHTML = `
            <div class="wcgs-section-header">
                <h2><span class="dashicons dashicons-layout"></span> Todos os Projetos</h2>
                <button class="wcgs-btn btn-primary" id="btn-add-project-main">
                    <span class="dashicons dashicons-plus-alt2"></span> Novo Projeto
                </button>
            </div>
        `;

        if (state.projects.length === 0) {
            wrap.innerHTML += `
                <div class="empty-state">
                    <span class="dashicons dashicons-admin-plugins"></span>
                    <p>Nenhum projeto cadastrado ainda.</p>
                    <button class="wcgs-btn btn-primary" id="btn-add-project-empty">
                        <span class="dashicons dashicons-plus-alt2"></span> Adicionar primeiro projeto
                    </button>
                </div>
            `;
        } else {
            const grid = el('div', { style: 'display:flex;flex-direction:column;gap:10px' });
            state.projects.forEach(p => {
                const st = state.projectStatus[p.id];
                grid.innerHTML += `
                    <div class="project-card">
                        <div class="proj-icon"><span class="dashicons dashicons-admin-plugins"></span></div>
                        <div class="proj-info">
                            <div class="proj-name">${esc(p.name)}</div>
                            <div class="proj-path">${esc(p.repo_path)}</div>
                            <div class="proj-meta">
                                <span class="badge badge-neutral">${esc(p.branch)}</span>
                                ${p.auto_pull ? '<span class="badge badge-ok">auto-pull</span>' : ''}
                                ${p.auto_push ? '<span class="badge badge-ok">auto-push</span>' : ''}
                                ${st ? (st.has_local_changes
                                    ? '<span class="badge badge-warn">mudanças locais</span>'
                                    : '<span class="badge badge-ok">sincronizado</span>') : ''}
                            </div>
                        </div>
                        <div class="proj-actions">
                            <button class="wcgs-btn btn-accent btn-sm" data-view="project:${p.id}">Abrir</button>
                            <button class="wcgs-btn btn-ghost btn-sm do-pull" data-id="${p.id}">Pull</button>
                        </div>
                    </div>
                `;
            });
            wrap.appendChild(grid);
        }
        return wrap;
    }

    /* ── PROJECT VIEW ── */
    function renderProjectView(p) {
        const st = state.projectStatus[p.id] || {};
        const wrap = el('div');

        wrap.innerHTML = `
            <!-- Status + commits -->
            <div class="cards-grid" style="margin-bottom:16px">
                <div class="wcgs-card">
                    <h3><span class="dashicons dashicons-info"></span> Status do Repositório</h3>
                    <div class="stat-row"><span class="stat-label">Branch atual</span>
                        <span class="stat-value">${esc(st.current_branch || '—')}</span></div>
                    <div class="stat-row"><span class="stat-label">Caminho</span>
                        <span class="stat-value" style="font-size:10px;word-break:break-all">${esc(p.repo_path)}</span></div>
                    <div class="stat-row"><span class="stat-label">Repositório válido</span>
                        <span class="stat-value">${st.is_valid_repo === false
                            ? '<span class="badge badge-err">Inválido</span>'
                            : '<span class="badge badge-ok">OK</span>'}</span></div>
                    <div class="stat-row"><span class="stat-label">Mudanças locais</span>
                        <span class="stat-value">${st.has_local_changes
                            ? '<span class="badge badge-warn">Sim</span>'
                            : '<span class="badge badge-ok">Não</span>'}</span></div>
                </div>

                <div class="wcgs-card">
                    <h3><span class="dashicons dashicons-backup"></span> Últimos Commits</h3>
                    <ul class="commit-list">
                        ${(st.recent_commits || []).map(c => {
                            const [hash, ...rest] = c.split(' ');
                            return `<li class="commit-item"><span class="commit-hash">${esc(hash)}</span>${esc(rest.join(' '))}</li>`;
                        }).join('') || '<li class="commit-item" style="color:var(--muted)">Nenhum commit encontrado</li>'}
                    </ul>
                </div>
            </div>

            <!-- Actions -->
            <div class="cards-grid" style="margin-bottom:16px">
                <div class="wcgs-card">
                    <h3><span class="dashicons dashicons-download"></span> Pull do GitHub</h3>
                    <p class="action-desc">Busca e aplica as últimas alterações do GitHub no servidor (<code>git pull --rebase</code>).</p>
                    <button class="wcgs-btn btn-primary do-pull" data-id="${p.id}" id="btn-pull-${p.id}">
                        <span class="dashicons dashicons-download"></span> Executar Pull
                    </button>
                    <div class="wcgs-result" id="result-pull-${p.id}" style="display:none"></div>
                </div>

                <div class="wcgs-card">
                    <h3><span class="dashicons dashicons-upload"></span> Push para GitHub</h3>
                    <p class="action-desc">Envia alterações locais para o GitHub. Apenas os arquivos modificados são enviados.</p>
                    <div class="action-row" style="margin-bottom:8px">
                        <input type="text" class="wcgs-input" id="push-msg-${p.id}" placeholder="Mensagem do commit…">
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="wcgs-btn btn-accent do-push" data-id="${p.id}" id="btn-push-${p.id}">
                            <span class="dashicons dashicons-upload"></span> Executar Push
                        </button>
                        <button class="wcgs-btn btn-ghost btn-sm do-clear-cache" data-id="${p.id}" title="Limpa o cache local de SHAs. Use se o push não estiver detectando mudanças.">
                            <span class="dashicons dashicons-trash"></span> Limpar Cache
                        </button>
                    </div>
                    <div class="wcgs-result" id="result-push-${p.id}" style="display:none"></div>
                </div>
            </div>

            <!-- Webhook -->
            <div class="wcgs-card" style="margin-bottom:16px">
                <h3><span class="dashicons dashicons-networking"></span> Webhook GitHub → Servidor</h3>
                <p style="font-size:12px;color:var(--muted);margin:0 0 10px">
                    Configure este URL em <strong style="color:var(--text2)">GitHub → Settings → Webhooks</strong> para que o GitHub acione um pull automático a cada push.
                </p>
                <div class="webhook-url-row">
                    <input type="text" class="wcgs-input" readonly value="${esc(p.webhook_url)}" id="webhook-url-${p.id}">
                    <button class="wcgs-btn btn-ghost btn-sm do-copy-webhook" data-id="${p.id}">Copiar</button>
                </div>
                <ul class="checklist">
                    <li>Content type: <code>application/json</code></li>
                    <li>Secret: <code>${esc(p.webhook_secret)}</code></li>
                    <li>Evento: <strong>Just the push event</strong></li>
                    <li>Branch monitorado: <code>${esc(p.branch)}</code></li>
                </ul>
                <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border2)">
                    <p style="font-size:12px;color:var(--muted);margin:0 0 10px">
                        Verifique se o endpoint está acessível e a assinatura HMAC está correta antes de configurar no GitHub.
                    </p>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                        <button class="wcgs-btn btn-ghost btn-sm do-test-webhook" data-id="${p.id}" id="btn-test-wh-${p.id}">
                            <span class="dashicons dashicons-controls-play"></span> Testar Webhook
                        </button>
                        <span class="test-wh-result" id="test-wh-result-${p.id}" style="display:none;font-size:12px;font-family:var(--mono)"></span>
                    </div>
                    <div class="wcgs-result" id="result-test-wh-${p.id}" style="display:none;margin-top:10px"></div>
                </div>
            </div>

            <!-- Edit / Delete -->
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button class="wcgs-btn btn-ghost btn-sm do-view-log" data-id="${p.id}">
                    <span class="dashicons dashicons-list-view"></span> Ver log
                </button>
                <button class="wcgs-btn btn-ghost btn-sm do-edit" data-id="${p.id}">
                    <span class="dashicons dashicons-edit"></span> Editar
                </button>
                <button class="wcgs-btn btn-danger btn-sm do-delete" data-id="${p.id}">
                    <span class="dashicons dashicons-trash"></span> Remover
                </button>
            </div>
        `;
        return wrap;
    }

    /* ── LOG VIEW ── */
    function renderLogView(projectId) {
        const wrap = el('div');
        const projName = projectId
            ? (state.projects.find(p => p.id === projectId)?.name || projectId)
            : 'Global';

        wrap.innerHTML = `
            <div class="wcgs-section-header">
                <h2><span class="dashicons dashicons-list-view"></span> Log – ${esc(projName)}</h2>
            </div>
            <div class="wcgs-card">
                <div class="log-toolbar">
                    <div class="log-toolbar-left">
                        <select class="log-filter" id="log-level-filter">
                            <option value="">Todos os níveis</option>
                            <option value="error">Erros</option>
                            <option value="warning">Avisos</option>
                            <option value="success">Sucesso</option>
                            <option value="info">Info</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px">
                        <button class="wcgs-btn btn-ghost btn-sm" id="btn-refresh-log" data-pid="${projectId || ''}">
                            <span class="dashicons dashicons-update"></span> Atualizar
                        </button>
                        <button class="wcgs-btn btn-danger btn-sm" id="btn-clear-log" data-pid="${projectId || ''}">
                            <span class="dashicons dashicons-trash"></span> Limpar
                        </button>
                    </div>
                </div>
                <div class="log-container" id="log-entries">
                    <div class="log-empty">Carregando…</div>
                </div>
            </div>
        `;
        return wrap;
    }

    let logXhr = null;
    function loadAndRenderLog(projectId) {
        const $c = $('#log-entries');
        if (!$c.length) return;

        // Cancel any in-flight request
        if (logXhr) { logXhr.abort(); logXhr = null; }

        $c.html('<div class="log-empty">Carregando…</div>');

        logXhr = ajax('wcgs_get_log', { project_id: projectId || '' }).done(res => {
            logXhr = null;
            if (!res.success || !res.data.entries.length) {
                $c.html('<div class="log-empty">Nenhum registro encontrado.</div>');
                return;
            }
            const lvFilter = $('#log-level-filter').val() || '';
            let entries = lvFilter
                ? res.data.entries.filter(e => e.level === lvFilter)
                : res.data.entries;

            // Limit to 100 entries for performance
            entries = entries.slice(0, 100);

            $c.html(entries.map(e => `
                <div class="log-entry">
                    <span class="log-ts">${esc(e.ts)}</span>
                    <span class="log-level ${e.level}">${e.level}</span>
                    <span class="log-msg">${esc(e.message)}</span>
                </div>
            `).join('') || '<div class="log-empty">Nenhum registro para este filtro.</div>');
        }).fail((xhr) => {
            logXhr = null;
            if (xhr.statusText !== 'abort') {
                $c.html('<div class="log-empty">Erro ao carregar log.</div>');
            }
        });
    }

    /* ── MODAL ── */
    function renderModal() {
        const { mode, project: p } = state.modal;
        const isEdit = mode === 'edit';

        const overlay = el('div', { class: 'wcgs-modal-overlay', id: 'modal-overlay' });
        overlay.innerHTML = `
            <div class="wcgs-modal">
                <div class="modal-header">
                    <h2>${isEdit ? 'Editar Projeto' : 'Novo Projeto'}</h2>
                    <button class="modal-close" id="btn-modal-close">✕</button>
                </div>
                <div class="modal-body">
                    <form id="project-form">
                        ${isEdit ? `<input type="hidden" name="project_id" value="${p.id}">` : ''}
                        <div class="wcgs-form-grid">

                            <label class="form-label">Nome do Projeto</label>
                            <div class="form-field">
                                <input type="text" class="wcgs-input" name="name" value="${esc(p?.name || '')}" placeholder="Meu Plugin WooCommerce" required>
                            </div>

                            <label class="form-label">Caminho no Servidor<small>absoluto</small></label>
                            <div class="form-field">
                                <input type="text" class="wcgs-input" name="repo_path" value="${esc(p?.repo_path || '')}" placeholder="/var/www/html/wp-content/plugins/meu-plugin" required>
                                <p class="form-hint">Caminho absoluto onde o repositório Git está localizado.</p>
                            </div>

                            <label class="form-label">Branch</label>
                            <div class="form-field">
                                <input type="text" class="wcgs-input" name="branch" value="${esc(p?.branch || 'main')}" placeholder="main">
                            </div>

                            <label class="form-label">Remote</label>
                            <div class="form-field">
                                <input type="text" class="wcgs-input" name="remote" value="${esc(p?.remote || 'origin')}" placeholder="origin">
                            </div>

                            <div class="form-sep"></div>

                            <label class="form-label">URL do Repositório GitHub</label>
                            <div class="form-field">
                                <input type="text" class="wcgs-input" name="github_repo_url" value="${esc(p?.github_repo_url || '')}" placeholder="https://github.com/usuario/meu-plugin">
                                <p class="form-hint">URL do repositório no GitHub. Usado para criar o .git/config automaticamente.</p>
                            </div>

                            <label class="form-label">Token GitHub (PAT)<small>escopo: repo</small></label>
                            <div class="form-field">
                                <input type="password" class="wcgs-input" name="github_token" value="${esc(p?.github_token || '')}" autocomplete="new-password" placeholder="ghp_…">
                                <p class="form-hint">Personal Access Token com permissão <code>repo</code>.</p>
                            </div>

                            <label class="form-label">Webhook Secret</label>
                            <div class="form-field">
                                <input type="text" class="wcgs-input" name="webhook_secret" value="${esc(p?.webhook_secret || '')}">
                                <p class="form-hint">Use o mesmo valor no campo Secret do webhook no GitHub.</p>
                            </div>

                            <div class="form-sep"></div>

                            <label class="form-label">Sync Agendado</label>
                            <div class="form-field">
                                <select class="wcgs-select" name="sync_interval">
                                    ${Object.entries(intervals).map(([val, label]) =>
                                        `<option value="${val}" ${(p?.sync_interval || 'disabled') === val ? 'selected' : ''}>${label}</option>`
                                    ).join('')}
                                </select>
                            </div>

                            <label class="form-label">Auto Pull</label>
                            <div class="form-field">
                                <label class="toggle-label">
                                    <label class="wcgs-toggle">
                                        <input type="checkbox" name="auto_pull" value="1" ${p?.auto_pull !== false ? 'checked' : ''}>
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <span>Ativar pull automático ao receber webhook</span>
                                </label>
                            </div>

                            <label class="form-label">Auto Push</label>
                            <div class="form-field">
                                <label class="toggle-label">
                                    <label class="wcgs-toggle">
                                        <input type="checkbox" name="auto_push" value="1" ${p?.auto_push ? 'checked' : ''}>
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <span>Incluir no sync agendado (push)</span>
                                </label>
                            </div>

                            <label class="form-label">E-mail de Alerta</label>
                            <div class="form-field">
                                <input type="email" class="wcgs-input" name="notify_email" value="${esc(p?.notify_email || '')}" placeholder="admin@exemplo.com.br">
                                <p class="form-hint">Deixe em branco para usar o e-mail global.</p>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="wcgs-btn btn-ghost" id="btn-modal-cancel">Cancelar</button>
                    <button class="wcgs-btn btn-primary" id="btn-modal-save">
                        <span class="dashicons dashicons-yes"></span> ${isEdit ? 'Salvar Alterações' : 'Criar Projeto'}
                    </button>
                    <span class="inline-result" id="modal-result"></span>
                </div>
            </div>
        `;
        return overlay;
    }

    /* ──────────────────────────────────── EVENTS ── */
    function attachEvents() {
        // Remove all previous handlers to prevent accumulation on re-render
        $(document).off('.wcgs');

        // navigation
        $(document).on('click.wcgs', '[data-view]', function () {
            navigateTo($(this).data('view'));
        });

        // sidebar add
        $(document).on('click.wcgs', '#btn-add-project, #btn-add-project-main, #btn-add-project-empty', () => openModal('add'));

        // topbar refresh
        $(document).on('click.wcgs', '#btn-refresh-status', () => {
            if (state.activeProject) loadStatus(state.activeProject);
        });

        // pull
        $(document).on('click.wcgs', '.do-pull', function () {
            const id  = $(this).data('id');
            const $btn = $(this);
            setLoading($btn, true);
            ajax('wcgs_pull', { project_id: id }).done(res => {
                showResult(`result-pull-${id}`, res.success, res.data?.output || res.data?.message || '');
                if (res.success) loadStatus(id);
            }).fail(() => showResult(`result-pull-${id}`, false, 'Erro AJAX'))
              .always(() => setLoading($btn, false));
        });

        // push
        $(document).on('click.wcgs', '.do-push', function () {
            const id  = $(this).data('id');
            const msg = $(`#push-msg-${id}`).val().trim() || 'WP auto-commit';
            const $btn = $(this);
            setLoading($btn, true);
            ajax('wcgs_push', { project_id: id, message: msg }).done(res => {
                showResult(`result-push-${id}`, res.success, res.data?.output || res.data?.message || '');
                if (res.success) loadStatus(id);
            }).fail(() => showResult(`result-push-${id}`, false, 'Erro AJAX'))
              .always(() => setLoading($btn, false));
        });

        // copy webhook
        $(document).on('click.wcgs', '.do-copy-webhook', function () {
            const id  = $(this).data('id');
            const val = $(`#webhook-url-${id}`).val();
            navigator.clipboard.writeText(val).then(() => {
                $(this).text('Copiado!');
                setTimeout(() => $(this).text('Copiar'), 2000);
            });
        });

        // test github connection
        $(document).on('click.wcgs', '.do-test-webhook', function () {
            const id   = $(this).data('id');
            const $btn = $(this);
            const $res = $(`#result-test-wh-${id}`);
            setLoading($btn, true);
            $res.removeClass('is-success is-error').html(
                '<span style="color:var(--muted)">Testando conexão com GitHub…</span>'
            ).show();

            ajax('wcgs_test_webhook', { project_id: id }).done(res => {
                const data  = res.data || {};
                const steps = data.steps || [];
                const msg   = data.message || '';

                let html = steps.map(s => {
                    const icon   = s.ok === true ? '✓' : s.ok === false ? '✗' : '·';
                    const color  = s.ok === true ? 'var(--success)' : s.ok === false ? '#f85149' : 'var(--warn)';
                    const detail = s.detail ? ` <span style="color:var(--muted)">${esc(s.detail)}</span>` : '';
                    return `<div><span style="color:${color};font-weight:700">${icon}</span> ${esc(s.label)}${detail}</div>`;
                }).join('');

                if (msg) {
                    html += `<div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border2);font-weight:600">${esc(msg)}</div>`;
                }

                $res.removeClass('is-success is-error')
                    .addClass(res.success ? 'is-success' : 'is-error')
                    .html(html).show();

            }).fail(() => {
                $res.addClass('is-error').text('✗ Erro na requisição AJAX.').show();
            }).always(() => setLoading($btn, false));
        });

        // clear cache
        $(document).on('click.wcgs', '.do-clear-cache', function () {
            const id   = $(this).data('id');
            const $btn = $(this);
            const $res = $(`#result-push-${id}`);
            if (!confirm('Limpar cache de SHAs? O próximo push irá recomparar todos os arquivos com o GitHub.')) return;
            setLoading($btn, true);
            ajax('wcgs_clear_cache', { project_id: id }).done(res => {
                $res.removeClass('is-success is-error').addClass('is-success')
                    .text(res.data.message || 'Cache limpo.').show();
            }).fail(() => {
                $res.addClass('is-error').text('Erro ao limpar cache.').show();
            }).always(() => setLoading($btn, false));
        });

        // edit
        $(document).on('click.wcgs', '.do-edit', function () {
            const id = $(this).data('id');
            const p  = state.projects.find(p => p.id === id);
            if (p) openModal('edit', p);
        });

        // delete
        $(document).on('click.wcgs', '.do-delete', function () {
            const id = $(this).data('id');
            const p  = state.projects.find(p => p.id === id);
            if (!p || !confirm(`Remover o projeto "${p.name}"? Isso NÃO apaga o repositório local.`)) return;
            ajax('wcgs_delete_project', { project_id: id }).done(() => {
                state.projects = state.projects.filter(p => p.id !== id);
                state.activeView = 'overview';
                state.activeProject = null;
                render();
            });
        });

        // view log per project
        $(document).on('click.wcgs', '.do-view-log', function () {
            const id = $(this).data('id');
            showProjectLog(id);
        });

        // log tab events
        $(document).on('click.wcgs', '#btn-refresh-log', function () {
            loadAndRenderLog($(this).data('pid'));
        });
        $(document).on('click.wcgs', '#btn-clear-log', function () {
            const pid = $(this).data('pid');
            if (!confirm('Limpar registros de log?')) return;
            ajax('wcgs_clear_log', { project_id: pid }).done(() => loadAndRenderLog(pid));
        });
        $(document).on('change.wcgs', '#log-level-filter', function () {
            const pid = $('#btn-refresh-log').data('pid');
            loadAndRenderLog(pid);
        });

        // modal
        $(document).on('click.wcgs', '#btn-modal-close, #btn-modal-cancel, #modal-overlay', function (e) {
            if (e.target.id === 'modal-overlay' || e.target.id === 'btn-modal-close' || e.target.id === 'btn-modal-cancel') {
                state.modal = null;
                render();
            }
        });
        $(document).on('click.wcgs', '#btn-modal-save', saveProject);
    }

    /* ──────────────────────────────────── ACTIONS ── */
    function navigateTo(view) {
        state.activeView = view;
        if (view.startsWith('project:')) {
            const id = view.split(':')[1];
            state.activeProject = id;
            if (!state.projectStatus[id]) loadStatus(id);
        }
        render();
        if (view === 'log') loadAndRenderLog(null);
    }

    function showProjectLog(projectId) {
        // inject log view into main
        const main = document.querySelector('.wcgs-main');
        if (!main) return;
        const logEl = renderLogView(projectId);
        main.innerHTML = '';
        main.appendChild(logEl);
        loadAndRenderLog(projectId);
    }

    function openModal(mode, project = null) {
        state.modal = { mode, project };
        render();
    }

    function saveProject() {
        const $btn    = $('#btn-modal-save');
        const $result = $('#modal-result');
        setLoading($btn, true);
        $result.text('').removeClass('ok err');

        const formData = {};
        $('#project-form').serializeArray().forEach(f => formData[f.name] = f.value);
        // checkboxes falsos = '0' se não marcados
        ['auto_pull','auto_push'].forEach(k => {
            if (!formData[k]) formData[k] = '0';
            else formData[k] = '1';
        });

        ajax('wcgs_save_project', formData).done(res => {
            if (res.success) {
                loadProjects(() => {
                    state.modal = null;
                    const id = res.data.project.id;
                    state.activeView = 'project:' + id;
                    state.activeProject = id;
                    loadStatus(id);
                    render();
                });
            } else {
                $result.text('Erro ao salvar.').addClass('err');
                setLoading($btn, false);
            }
        }).fail(() => { $result.text('Erro AJAX.').addClass('err'); setLoading($btn, false); });
    }

    function loadStatus(projectId) {
        state.loadingStatus[projectId] = true;
        ajax('wcgs_get_status', { project_id: projectId }).done(res => {
            if (res.success) {
                state.projectStatus[projectId] = res.data;
                state.loadingStatus[projectId] = false;
                // partial re-render of sidebar badges only
                if (state.activeView === 'project:' + projectId) render();
            }
        });
    }

    function loadProjects(callback) {
        ajax('wcgs_get_projects').done(res => {
            if (res.success) {
                state.projects = res.data.projects;
                if (callback) callback();
                else render();
            }
        });
    }

    /* ──────────────────────────────────── HELPERS ── */
    function v(view) { return state.activeView === view ? 'active' : ''; }

    function el(tag, attrs = {}) {
        const e = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        return e;
    }

    function esc(str) {
        return String(str || '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function setLoading($btn, state) {
        $btn.prop('disabled', state);
        const $icon = $btn.find('.dashicons');
        if (state) $icon.addClass('spin'); else $icon.removeClass('spin');
    }

    function showResult(id, success, text) {
        const $r = $(`#${id}`);
        if (!$r.length) return;
        $r.removeClass('is-success is-error')
          .addClass(success ? 'is-success' : 'is-error')
          .text(text).show();
        if (success) setTimeout(() => $r.fadeOut(), 10000);
    }

    /* ──────────────────────────────────── BOOT ── */
    $(function () {
        loadProjects(() => {
            // load status for all projects in background
            state.projects.forEach(p => loadStatus(p.id));
            render();
        });
    });

})(jQuery);
