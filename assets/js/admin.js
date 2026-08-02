(function(){
    // Simple admin UI for repository listing. This is intentionally minimal to act as a scaffold.
    function el( tag, attrs, ...children ){
        const e = document.createElement(tag);
        if (attrs) for (let k in attrs) e.setAttribute(k, attrs[k]);
        for (let c of children) {
            if (typeof c === 'string') e.appendChild(document.createTextNode(c));
            else if (c instanceof Node) e.appendChild(c);
        }
        return e;
    }

    function showError(container, msg){
        container.innerHTML = '';
        container.appendChild(el('div', {class:'gh-error'}, msg));
    }

    document.addEventListener('DOMContentLoaded', function(){
        const app = document.getElementById('gh-updater-app');
        if (!app) return;
        app.innerHTML = '<p>Loading repositories…</p>';

        const base = window.GH_UPDATER && window.GH_UPDATER.restBase ? window.GH_UPDATER.restBase : '/wp-json/gh-updater/v1';

        fetch(base + '/repos', {
            headers: { 'X-WP-Nonce': window.GH_UPDATER && window.GH_UPDATER.nonce ? window.GH_UPDATER.nonce : '' }
        }).then(function(res){
            if (!res.ok) throw new Error('API error: ' + res.status);
            return res.json();
        }).then(function(data){
            app.innerHTML = '';
            const list = el('div', {class:'gh-repo-list'});
            if (!Array.isArray(data) || data.length === 0) {
                app.appendChild(el('div', {}, 'No repositories found or token not configured.'));
                return;
            }
            data.forEach(function(repo){
                const item = el('div', {class:'gh-repo'},
                    el('h3', {}, repo.full_name || repo.fullName || repo.name),
                    el('div', {class:'gh-meta'}, 'Visibility: ' + (repo.private ? 'Private' : 'Public') + ' — Default branch: ' + (repo.default_branch || repo.defaultBranch || 'master')),
                    el('button', {class:'gh-branches-btn', 'data-owner': repo.owner.login, 'data-repo': repo.name}, 'View branches')
                );
                list.appendChild(item);
            });
            app.appendChild(list);

            app.addEventListener('click', function(e){
                if (e.target.matches('.gh-branches-btn')){
                    const owner = e.target.getAttribute('data-owner');
                    const repo = e.target.getAttribute('data-repo');
                    const container = e.target.parentNode;
                    fetch(base + '/repos/' + owner + '/' + repo + '/branches', { headers: { 'X-WP-Nonce': window.GH_UPDATER.nonce } })
                        .then(r => r.json()).then(bs => {
                            const ul = el('ul', {class:'gh-branch-list'});
                            bs.forEach(b => ul.appendChild(el('li', {}, b.name)));
                            // toggle
                            const existing = container.querySelector('.gh-branch-list');
                            if (existing) existing.remove();
                            else container.appendChild(ul);
                        }).catch(err => showError(container, err.message));
                }
            });
        }).catch(function(err){
            showError(app, err.message);
        });
    });
})();
