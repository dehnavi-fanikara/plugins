(function () {
    'use strict';
    const root = document.querySelector('.baa-metabox');
    if (!root || !window.BAAAdmin || root.dataset.readonly === '1') return;
    const search = root.querySelector('.baa-search-input');
    const type = root.querySelector('.baa-target-type');
    const results = root.querySelector('.baa-search-results');
    const status = root.querySelector('.baa-search-status');
    const list = root.querySelector('.baa-selected-list');
    const json = root.querySelector('.baa-selected-json');
    const sourceId = Number(root.dataset.sourceId || BAAAdmin.sourceId || root.querySelector('.baa-source-id').value || 0);
    let timer = null;
    const ids = () => Array.from(list.querySelectorAll('[data-id]')).map((item) => Number(item.dataset.id));
    const sync = () => { json.value = JSON.stringify(ids()); };
    const itemHtml = (item) => '<div class="baa-result-item" data-id="' + item.id + '"><span class="baa-result-thumb">' + (item.thumbnail ? '<img src="' + item.thumbnail + '" alt="">' : '') + '</span><span><strong>' + escapeHtml(item.title) + '</strong><small>' + escapeHtml(item.postType + ' · ' + item.status) + '</small></span><button type="button" class="button baa-add-item">' + BAAAdmin.i18n.add + '</button></div>';
    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
    const searchPosts = (page) => {
        const term = search.value.trim();
        if (term.length < 2) { status.textContent = term ? BAAAdmin.i18n.minChars : ''; results.innerHTML = ''; return; }
        status.textContent = BAAAdmin.i18n.searching;
        const body = new URLSearchParams({ action: 'baa_search_posts', nonce: BAAAdmin.nonce, source_id: String(sourceId), post_id: String(sourceId), post_type: type.value, term, page: String(page || 1) });
        fetch(BAAAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body }).then((response) => response.json()).then((data) => {
            if (!data.success) throw new Error(data.data && data.data.message ? data.data.message : 'خطا');
            const available = data.data.items.filter((item) => !ids().includes(Number(item.id)));
            const content = available.map(itemHtml).join('');
            results.innerHTML = page > 1 ? results.innerHTML.replace(/<button class="baa-next-page"[\s\S]*?<\/button>/, '') + content : content;
            if (data.data.totalPages > page) results.insertAdjacentHTML('beforeend', '<button type="button" class="button baa-next-page" data-page="' + (page + 1) + '">نتایج بیشتر</button>');
            if (!available.length && page === 1) results.innerHTML = '<p>' + BAAAdmin.i18n.noResults + '</p>';
            status.textContent = '';
        }).catch((error) => { status.textContent = error.message; });
    };
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => searchPosts(1), 350); });
    type.addEventListener('change', () => searchPosts(1));
    results.addEventListener('click', (event) => {
        const nextPage = event.target.closest('.baa-next-page');
        if (nextPage) { searchPosts(Number(nextPage.dataset.page)); return; }
        const button = event.target.closest('.baa-add-item'); if (!button) return;
        const source = button.closest('.baa-result-item'); if (!source || ids().includes(Number(source.dataset.id))) return;
        const item = document.createElement('li'); item.className = 'baa-selected-item'; item.draggable = true; item.dataset.id = source.dataset.id;
        item.innerHTML = '<span class="baa-drag-handle" aria-hidden="true">⠿</span>' + source.querySelector('.baa-result-thumb').innerHTML + '<span class="baa-item-name">' + source.querySelector('strong').textContent + ' <small>(' + source.querySelector('small').textContent + ')</small></span><button type="button" class="button-link baa-move-up">بالا</button><button type="button" class="button-link baa-move-down">پایین</button><button type="button" class="button-link-delete baa-remove-item">' + BAAAdmin.i18n.remove + '</button>';
        list.appendChild(item); source.remove(); sync();
    });
    list.addEventListener('click', (event) => { const item = event.target.closest('.baa-selected-item'); if (!item) return; if (event.target.closest('.baa-remove-item')) item.remove(); if (event.target.closest('.baa-move-up') && item.previousElementSibling) item.parentNode.insertBefore(item, item.previousElementSibling); if (event.target.closest('.baa-move-down') && item.nextElementSibling) item.parentNode.insertBefore(item.nextElementSibling, item); sync(); });
    let dragged = null;
    list.addEventListener('dragstart', (event) => { dragged = event.target.closest('.baa-selected-item'); if (dragged) dragged.classList.add('baa-dragging'); });
    list.addEventListener('dragend', () => { if (dragged) dragged.classList.remove('baa-dragging'); dragged = null; sync(); });
    list.addEventListener('dragover', (event) => { event.preventDefault(); const target = event.target.closest('.baa-selected-item'); if (!dragged || !target || target === dragged) return; const rect = target.getBoundingClientRect(); target.parentNode.insertBefore(dragged, event.clientY < rect.top + rect.height / 2 ? target : target.nextElementSibling); });
    sync();
}());
