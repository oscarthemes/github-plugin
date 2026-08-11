(function(){
    // Full-featured admin dashboard for GitHub Updater plugin
    // Sections: Overview, Settings, Repositories, Activity, Compare, Wizard

    const base = window.GH_UPDATER && window.GH_UPDATER.restBase ? window.GH_UPDATER.restBase : '/wp-json/gh-updater/v1';
    const nonce = window.GH_UPDATER && window.GH_UPDATER.nonce ? window.GH_UPDATER.nonce : '';

    function el(tag, attrs, ...children){
        const e = document.createElement(tag);
        if (attrs) for (let k in attrs) {
            if (k === 'class') e.className = attrs[k];
            else e.setAttribute(k, attrs[k]);
        }
        for (let c of children) {
            if (typeof c === 'string') e.appendChild(document.createTextNode(c));
            else if (c instanceof Node) e.appendChild(c);
        }
        return e;
    }

    function api(path, opts={}){
        opts.headers = opts.headers || {};
        opts.headers['X-WP-Nonce'] = nonce;
        opts.credentials = 'same-origin';
        return fetch(base + path, opts).then(async r => {
            const contentType = r.headers.get('content-type') || '';
            let body = null;
            if (contentType.indexOf('application/json') !== -1) body = await r.json();
            else body = await r.text();
            if (!r.ok) throw { status: r.status, body };
            return body;
        });
    }

    function showMessage(container, msg, type='info'){
        const existing = container.querySelector('.gh-message'); if (existing) existing.remove();
        const msgEl = el('div', {class: 'gh-message ' + type}, msg);
        container.insertBefore(msgEl, container.firstChild);
        setTimeout(()=>{ if(msgEl) msgEl.remove(); }, 6000);
    }

    function buildTabs(tabs){
        const nav = el('div', {class:'gh-tabs'});
        const content = el('div', {class:'gh-tab-content'});
        tabs.forEach((t, i) => {
            const btn = el('button', {class: 'gh-tab-btn' + (i===0? ' active':'')}, t.title);
            btn.addEventListener('click', ()=>{
                nav.querySelectorAll('.gh-tab-btn').forEach(b=>b.classList.remove('active'));
                btn.classList.add('active');
                content.innerHTML = '';
                content.appendChild(t.render());
            });
            nav.appendChild(btn);
        });
        // initial
        content.appendChild(tabs[0].render());
        const wrapper = el('div', {class:'gh-tabs-wrap'}, nav, content);
        return wrapper;
    }

    // Settings view
    function SettingsView(){
        const container = el('div', {class:'gh-section gh-settings'});
        const form = el('div', {class:'gh-settings-form'});
        const tokenRow = el('div', {class:'row'}, el('label', {}, 'Personal Access Token (PAT): '), el('input', {type:'text', id:'gh-token', placeholder:'Paste PAT here', style:'width:60%'}));
        const secretRow = el('div', {class:'row'}, el('label', {}, 'Webhook secret (optional): '), el('input', {type:'text', id:'gh-secret', placeholder:'Leave blank to auto-generate', style:'width:60%'}));
        const actions = el('div', {class:'row'}, el('button', {id:'gh-save'}, 'Save'), el('button', {id:'gh-test', style:'margin-left:8px'}, 'Test token'), el('button', {id:'gh-remove', style:'margin-left:8px'}, 'Remove token'));
        form.appendChild(tokenRow); form.appendChild(secretRow); form.appendChild(actions);
        container.appendChild(form);

        // load state
        api('/settings').then(info => {
            if (info.has_token) document.getElementById('gh-token').placeholder = 'Token configured';
            if (info.webhook_secret) document.getElementById('gh-secret').placeholder = 'Webhook secret configured';
        }).catch(e => {});

        form.querySelector('#gh-save').addEventListener('click', ()=>{
            const token = document.getElementById('gh-token').value.trim();
            const secret = document.getElementById('gh-secret').value.trim();
            api('/settings', { method: 'POST', body: JSON.stringify({ token: token || undefined, webhook_secret: secret || undefined }), headers: { 'Content-Type': 'application/json' } })
                .then(()=> showMessage(container, 'Settings saved', 'success'))
                .catch(err => showMessage(container, 'Error saving settings: ' + (err.body && err.body.message ? err.body.message : JSON.stringify(err)), 'error'));
        });

        form.querySelector('#gh-test').addEventListener('click', ()=>{
            const token = document.getElementById('gh-token').value.trim();
            api('/settings/test-token', { method: 'POST', body: JSON.stringify({ token: token || undefined }), headers: { 'Content-Type': 'application/json' } })
                .then(res => {
                    const u = res.user && res.user.login ? res.user.login : (res.user && res.user.body && res.user.body.login ? res.user.body.login : 'unknown');
                    showMessage(container, 'Token valid for ' + u + ' — rate: ' + JSON.stringify(res.rate), 'success');
                }).catch(err => showMessage(container, 'Token test failed: ' + (err.body && err.body.message ? err.body.message : JSON.stringify(err)), 'error'));
        });

        form.querySelector('#gh-remove').addEventListener('click', ()=>{
            if (!confirm('Remove stored token? This will prevent the plugin from creating webhooks.')) return;
            api('/settings/remove-token', { method: 'POST' }).then(()=> showMessage(container, 'Token removed', 'success')).catch(err=> showMessage(container, 'Error removing token', 'error'));
        });

        // helper quick links
        const help = el('div', {class:'gh-help'}, el('h4', {}, 'Quick help'), el('ol', {}, el('li', {}, 'Create a GitHub Personal Access Token with repo and admin:repo_hook scopes.'), el('li', {}, 'Paste it above and click Save.'), el('li', {}, 'Create webhooks on repositories from the Repositories tab.')));
        container.appendChild(help);

        return container;
    }

    // Repos view
    function ReposView(){
        const container = el('div', {class:'gh-section gh-repos'});
        const list = el('div', {class:'gh-repo-list'});
        const refresh = el('button', {}, 'Refresh repos');
        container.appendChild(refresh);
        container.appendChild(list);

        refresh.addEventListener('click', load);

        function load(){
            list.innerHTML = 'Loading repositories…';
            api('/repos').then(repos => {
                list.innerHTML = '';
                if (!Array.isArray(repos) || repos.length === 0) { list.appendChild(el('div', {}, 'No repositories found.')); return; }
                repos.forEach(repo => {
                    const item = el('div', {class:'gh-repo-item'}, el('h3', {}, repo.full_name || repo.name));
                    const meta = el('div', {class:'gh-meta'}, 'Visibility: ' + (repo.private ? 'Private' : 'Public') + ' — Default: ' + (repo.default_branch || repo.defaultBranch || 'master'));
                    const controls = el('div', {class:'gh-controls'});
                    const createHook = el('button', {}, 'Create webhook');
                    const listHooks = el('button', {}, 'List hooks');
                    const pingHook = el('button', {}, 'Ping hook');
                    const branchesBtn = el('button', {}, 'Show branches');
                    controls.appendChild(createHook); controls.appendChild(listHooks); controls.appendChild(pingHook); controls.appendChild(branchesBtn);
                    item.appendChild(meta); item.appendChild(controls);
                    const hookArea = el('div', {class:'gh-hook-area'});
                    item.appendChild(hookArea);

                    createHook.addEventListener('click', ()=>{
                        createHook.disabled = true;
                        api('/hooks/create', { method: 'POST', body: JSON.stringify({ owner: repo.owner.login, repo: repo.name }), headers: { 'Content-Type': 'application/json' } })
                            .then(r=> { showMessage(hookArea, 'Webhook create status: ' + r.status, 'success'); createHook.disabled = false; })
                            .catch(e=> { showMessage(hookArea, 'Error creating webhook: ' + JSON.stringify(e), 'error'); createHook.disabled = false; });
                    });

                    listHooks.addEventListener('click', ()=>{
                        hookArea.innerHTML = 'Loading hooks…';
                        api('/hooks/list?owner=' + encodeURIComponent(repo.owner.login) + '&repo=' + encodeURIComponent(repo.name))
                            .then(r => {
                                hookArea.innerHTML = '';
                                const hooks = r.body || [];
                                if (hooks.length === 0) hookArea.appendChild(el('div', {}, 'No hooks')); else {
                                    hooks.forEach(h=>{
                                        const hEl = el('div', {class:'gh-hook'}, el('div', {}, 'ID: ' + h.id + ' URL: ' + (h.config && h.config.url ? h.config.url : '')), el('div', {}, 'Events: ' + (h.events || []).join(', ')));
                                        hookArea.appendChild(hEl);
                                    });
                                }
                            }).catch(e=> showMessage(hookArea, 'Error listing hooks: ' + JSON.stringify(e), 'error'));
                    });

                    pingHook.addEventListener('click', ()=>{
                        hookArea.innerHTML = 'Pinging…';
                        api('/hooks/ping', { method: 'POST', body: JSON.stringify({ owner: repo.owner.login, repo: repo.name }), headers: { 'Content-Type': 'application/json' } })
                            .then(r => showMessage(hookArea, 'Ping status: ' + r.status, 'success'))
                            .catch(e => showMessage(hookArea, 'Ping failed: ' + JSON.stringify(e), 'error'));
                    });

                    branchesBtn.addEventListener('click', ()=>{
                        const bArea = item.querySelector('.gh-branches');
                        if (bArea) { bArea.remove(); return; }
                        const bwrap = el('div', {class:'gh-branches'});
                        bwrap.innerHTML = 'Loading branches…';
                        item.appendChild(bwrap);
                        api('/repos/' + encodeURIComponent(repo.owner.login) + '/' + encodeURIComponent(repo.name) + '/branches')
                            .then(bs => { bwrap.innerHTML = ''; if (!Array.isArray(bs)) { bwrap.appendChild(el('div', {}, 'No branches found')); return; } bs.forEach(b=> bwrap.appendChild(el('div', {}, b.name))); })
                            .catch(e => { bwrap.innerHTML = 'Error: ' + JSON.stringify(e); });
                    });

                    list.appendChild(item);
                });
            }).catch(err => { list.innerHTML = 'Error loading repos: ' + (err.body && err.body.message ? err.body.message : JSON.stringify(err)); });
        }

        load();
        return container;
    }

    // Activity view
    function ActivityView(){
        const container = el('div', {class:'gh-section gh-activity'});
        const controls = el('div', {class:'row'}, el('button', {id:'gh-activity-refresh'}, 'Refresh'), el('button', {id:'gh-process-events', style:'margin-left:8px'}, 'Process queued events'));
        const feed = el('div', {id:'gh-activity-feed'});
        container.appendChild(controls); container.appendChild(feed);

        container.querySelector('#gh-activity-refresh').addEventListener('click', load);
        container.querySelector('#gh-process-events').addEventListener('click', ()=>{
            api('/process-events', { method: 'POST' }).then(()=> { load(); showMessage(container, 'Processing triggered', 'success'); }).catch(e=> showMessage(container, 'Error processing events: ' + JSON.stringify(e), 'error'));
        });

        function load(){
            feed.innerHTML = 'Loading…';
            api('/activity').then(rows => {
                feed.innerHTML = '';
                if (!rows || rows.length === 0) { feed.appendChild(el('div', {}, 'No activity yet')); return; }
                rows.forEach(r => {
                    const meta = r.meta ? safeParse(r.meta) : null;
                    const item = el('div', {class:'gh-activity-item'}, el('div', {class:'gh-activity-header'}, el('strong', {}, r.event_type), ' — ', el('span', {}, r.repo_full_name || '')), el('div', {}, 'By: ' + (r.actor || '')), el('div', {}, meta && meta.ref ? 'Ref: ' + meta.ref : (meta && meta.sha ? 'SHA: ' + meta.sha : '')));
                    feed.appendChild(item);
                });
            }).catch(e => feed.innerHTML = 'Error: ' + JSON.stringify(e));
        }

        function safeParse(s){ try { return JSON.parse(s); } catch(e) { return null; } }

        load();
        return container;
    }

    // Compare view
    function CompareView(){
        const container = el('div', {class:'gh-section gh-compare'});
        const form = el('div', {class:'gh-compare-form'});
        const owner = el('input', {placeholder:'owner', id:'cmp-owner'});
        const repo = el('input', {placeholder:'repo', id:'cmp-repo'});
        const base = el('input', {placeholder:'base (branch or commit)', id:'cmp-base'});
        const head = el('input', {placeholder:'head (branch or commit)', id:'cmp-head'});
        const btn = el('button', {}, 'Compare');
        form.appendChild(el('div', {}, owner, repo));
        form.appendChild(el('div', {}, base, head, btn));
        const out = el('div', {class:'gh-compare-out'});
        container.appendChild(form); container.appendChild(out);

        btn.addEventListener('click', ()=>{
            out.innerHTML = 'Comparing…';
            const o = owner.value.trim(), r = repo.value.trim(), b = base.value.trim(), h = head.value.trim();
            if (!o||!r||!b||!h) { out.innerHTML = 'Fill all fields'; return; }
            api('/repos/' + encodeURIComponent(o) + '/' + encodeURIComponent(r) + '/compare?base=' + encodeURIComponent(b) + '&head=' + encodeURIComponent(h))
                .then(resp => {
                    out.innerHTML = '';
                    if (resp.files && resp.files.length) {
                        const list = el('div');
                        resp.files.forEach(f => {
                            const status = f.status; const filename = f.filename;
                            const patch = f.patch || '';
                            const header = el('div', {class:'file-header'}, filename + ' — ' + status);
                            const patchEl = el('pre', {class:'file-patch'}, patch);
                            list.appendChild(el('div', {class:'gh-compare-file'}, header, patchEl));
                        });
                        out.appendChild(list);
                    } else {
                        out.appendChild(el('div', {}, 'No files or unable to load patch -- check API response.'));
                    }
                }).catch(e => out.innerHTML = 'Compare failed: ' + JSON.stringify(e));
        });

        return container;
    }

    // Guided wizard for non-technical users
    function WizardView(){
        const container = el('div', {class:'gh-section gh-wizard'});
        container.appendChild(el('h2', {}, 'Setup wizard'));
        const steps = el('ol', {},
            el('li', {}, 'Create a GitHub Personal Access Token: Click the link and create a token with repo and admin:repo_hook scopes.'),
            el('li', {}, 'Paste token into Settings and Save.'),
            el('li', {}, 'Create a webhook for a repository from the Repositories tab.'),
            el('li', {}, 'Click Ping hook to verify delivery.'));
        const links = el('div', {class:'gh-wizard-links'}, el('a', {href:'https://github.com/settings/tokens', target:'_blank', rel:'noopener'}, 'Create token on GitHub'));
        const checkBtn = el('button', {}, 'Run quick check');
        const out = el('div', {class:'gh-wizard-out'});
        container.appendChild(steps); container.appendChild(links); container.appendChild(checkBtn); container.appendChild(out);

        checkBtn.addEventListener('click', ()=>{
            out.innerHTML = 'Running checks…';
            api('/settings').then(info => {
                if (!info.has_token) { out.innerHTML = 'No token configured. Please paste a token in Settings.'; return; }
                // test token
                api('/settings/test-token', { method: 'POST', body: JSON.stringify({}), headers: { 'Content-Type': 'application/json' } })
                    .then(r => { out.innerHTML = 'Token OK for ' + (r.user && r.user.login ? r.user.login : 'unknown'); })
                    .catch(e => { out.innerHTML = 'Token test failed: ' + JSON.stringify(e); });
            }).catch(e=> out.innerHTML = 'Error: ' + JSON.stringify(e));
        });

        return container;
    }

    // Build full dashboard
    function buildDashboard(){
        const tabs = [
            { title: 'Overview', render: function(){ return el('div', {}, el('h2', {}, 'GitHub Updater'), el('p', {}, 'Monitor repositories, branches, commits and pull requests from here. Use the tabs to configure and manage webhooks.')) } },
            { title: 'Settings', render: SettingsView },
            { title: 'Repositories', render: ReposView },
            { title: 'Activity', render: ActivityView },
            { title: 'Compare', render: CompareView },
            { title: 'Wizard', render: WizardView },
        ];
        return buildTabs(tabs);
    }

    document.addEventListener('DOMContentLoaded', function(){
        const app = document.getElementById('gh-updater-app');
        if (!app) return;
        app.innerHTML = '';
        app.appendChild(buildDashboard());
    });

})();
