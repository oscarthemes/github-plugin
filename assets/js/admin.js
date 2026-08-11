(function(){
    // Enhanced admin UI: settings, create webhook, activity feed, compare viewer
    function el( tag, attrs, ...children ){
        const e = document.createElement(tag);
        if (attrs) for (let k in attrs) e.setAttribute(k, attrs[k]);
        for (let c of children) {
            if (typeof c === 'string') e.appendChild(document.createTextNode(c));
            else if (c instanceof Node) e.appendChild(c);
        }
        return e;
    }

    const base = window.GH_UPDATER && window.GH_UPDATER.restBase ? window.GH_UPDATER.restBase : '/wp-json/gh-updater/v1';
    const nonce = window.GH_UPDATER && window.GH_UPDATER.nonce ? window.GH_UPDATER.nonce : '';

    function api( path, opts ){
        opts = opts || {};
        opts.headers = opts.headers || {};
        opts.headers['X-WP-Nonce'] = nonce;
        return fetch( base + path, opts ).then( r => { if (!r.ok) throw new Error('API error: '+r.status); return r.json(); } );
    }

    function showSettings(container){
        const box = el('div', {class:'gh-settings'}, el('h2', {}, 'Settings'));
        const tokenInput = el('input', {type:'text', placeholder:'Personal Access Token', id:'gh-token', style:'width:60%'});
        const secretInput = el('input', {type:'text', placeholder:'Webhook secret (optional)', id:'gh-secret', style:'width:60%'});
        const saveBtn = el('button', {}, 'Save');
        box.appendChild(el('div', {}, tokenInput));
        box.appendChild(el('div', {style:'margin-top:6px'}, secretInput));
        box.appendChild(el('div', {style:'margin-top:6px'}, saveBtn));
        container.appendChild(box);

        // load basic info
        api('/settings').then(function(info){
            if (info.has_token) tokenInput.placeholder = 'Token configured';
            if (info.webhook_secret) secretInput.placeholder = 'Webhook secret configured';
        }).catch(e => console.warn(e));

        saveBtn.addEventListener('click', function(){
            const body = { token: tokenInput.value || undefined, webhook_secret: secretInput.value || undefined };
            api('/settings', { method: 'POST', body: JSON.stringify(body), headers: { 'Content-Type': 'application/json' } }).then(function(){
                alert('Saved');
            }).catch(function(err){ alert('Error: ' + err.message); });
        });
    }

    function showRepos(container){
        const box = el('div', {class:'gh-repos'}, el('h2', {}, 'Repositories'));
        container.appendChild(box);
        api('/repos').then(function(data){
            const list = el('div');
            data.forEach(function(repo){
                const item = el('div', {class:'gh-repo'}, el('h3', {}, repo.full_name || repo.fullName || repo.name));
                const btn = el('button', {}, 'Create webhook');
                btn.addEventListener('click', function(){
                    const owner = repo.owner.login; const name = repo.name;
                    api('/hooks/create', { method: 'POST', body: JSON.stringify({ owner: owner, repo: name }), headers: { 'Content-Type': 'application/json' } }).then(function(resp){
                        alert('Webhook creation status: ' + resp.status);
                    }).catch(function(err){ alert('Error: ' + err.message); });
                });
                item.appendChild(el('div', {class:'gh-meta'}, 'Visibility: ' + (repo.private ? 'Private' : 'Public') + ' — Default branch: ' + (repo.default_branch || repo.defaultBranch || 'master')));
                item.appendChild(btn);
                list.appendChild(item);
            });
            box.appendChild(list);
        }).catch(function(err){
            box.appendChild(el('div', {class:'gh-error'}, err.message));
        });
    }

    function showActivity(container){
        const box = el('div', {class:'gh-activity'}, el('h2', {}, 'Activity'));
        const feed = el('div', {id:'gh-activity-list'});
        box.appendChild(feed);
        const loadBtn = el('button', {}, 'Refresh');
        loadBtn.addEventListener('click', function(){ loadActivity(feed); });
        box.appendChild(loadBtn);
        container.appendChild(box);
        loadActivity(feed);
    }

    function loadActivity(container){
        container.innerHTML = 'Loading…';
        api('/activity').then(function(rows){
            container.innerHTML = '';
            if (!rows || rows.length === 0) container.appendChild(el('div', {}, 'No activity yet'));
            rows.forEach(function(r){
                const meta = r.meta ? JSON.parse(r.meta) : null;
                const item = el('div', {class:'gh-activity-item'}, el('strong', {}, r.event_type), el('div', {}, r.repo_full_name || ''), el('div', {}, r.actor || ''), el('div', {}, meta && meta.ref ? 'Ref: ' + meta.ref : (meta && meta.sha ? 'SHA: ' + meta.sha : '')));
                container.appendChild(item);
            });
        }).catch(function(err){ container.innerHTML = 'Error: ' + err.message; });
    }

    document.addEventListener('DOMContentLoaded', function(){
        const app = document.getElementById('gh-updater-app');
        if (!app) return;
        app.innerHTML = '';
        showSettings(app);
        showRepos(app);
        showActivity(app);
    });
})();
