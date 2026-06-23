import './discussion.scss';
import { fetchWithCsrfRetry } from './discussion-request.mjs';
import { collectRemovedCommentIds, sameReactions } from './discussion-state.mjs';

/**
 * Bolt Discussion — frontend widget.
 *
 * Hydrates every `.bolt-discussion` mount: preserves the PHP-rendered first
 * page, submits comments/replies asynchronously, loads older pages, reacts,
 * collapses replies, lets editors moderate, and polls for new content. All
 * markup uses BEM-ish classes themed entirely via --bd-* custom properties.
 */

const NS = 'bolt-discussion';
const SYNC_POLL_BATCH_SIZE = 100;

class DiscussionWidget {
    constructor(root) {
        this.root = root;
        this.reference = root.dataset.reference;
        this.listUrl = root.dataset.listUrl;
        this.reactionUrlTemplate = root.dataset.reactionUrlTemplate;
        this.deleteUrlTemplate = root.dataset.deleteUrlTemplate;
        this.csrfToken = root.dataset.csrfToken;
        this.csrfTokenUrl = root.dataset.csrfTokenUrl || '';
        this.csrfRefreshPromise = null;
        this.pollInterval = parseInt(root.dataset.pollInterval || '0', 10);
        this.reactionsEnabled = root.dataset.reactionsEnabled === '1';
        this.reactions = safeJson(root.dataset.reactions, []);
        this.repliesEnabled = root.dataset.repliesEnabled === '1';
        this.requireName = root.dataset.requireName === '1';
        this.maxLength = parseInt(root.dataset.maxLength || '2000', 10);
        this.canModerate = root.dataset.canModerate === '1';
        this.i18n = safeJson(root.dataset.i18n, {});
        this.locale = root.dataset.locale || 'en';

        // Remembered across visits (localStorage): the visitor's name, prefilled
        // into the composer; and a stable id sent with API calls so the server can
        // recognise the same visitor (and their reactions) even if the cookie was
        // cleared.
        this.savedName = storageGet(`${NS}:name`) || '';
        this.visitorId = ensureVisitorId();

        this.comments = new Map(); // id -> comment
        this.lastId = 0;          // highest id seen, cursor for polling
        this.oldestRootId = null; // lowest root id loaded, cursor for "load more"
        this.hasMore = false;
        this.loadingMore = false;
        this.syncPollOffset = 0;
        this.expanded = new Set(); // root ids whose replies are expanded
        this.openPicker = null;    // currently open reaction picker

        const initial = this.readInitial();
        if (initial) {
            this.ingest(initial);
            this.oldestRootId = initial.nextBefore ?? null;
            this.hasMore = Boolean(initial.hasMore);

            if (!this.hydrateServerMarkup()) {
                this.build();
                this.render();
            }
            this.startPolling();
        } else {
            // Backwards-compatible fallback for overridden legacy templates.
            this.build();
            this.loadInitial();
        }

        window.addEventListener('pageshow', (event) => {
            if (event.persisted) this.refreshCsrfToken();
        });
    }

    /* ---------- i18n ---------- */

    t(key, fallback) {
        return this.i18n[key] || fallback;
    }

    pluralReplies(count) {
        const forms = this.i18n.replyCount || {};
        let category = 'other';
        try {
            category = new Intl.PluralRules(this.locale).select(count);
        } catch (e) { /* unknown locale: fall back to "other" */ }
        const template = forms[category] || forms.other || '%count% replies';
        return template.replace('%count%', count);
    }

    /* ---------- scaffolding ---------- */

    build() {
        this.root.innerHTML = '';

        // Composer first: write at the top, newest comments appear right below it.
        this.composer = this.buildForm(null);
        this.root.appendChild(this.composer.form);

        this.listEl = el('div', `${NS}__list`);
        this.root.appendChild(this.listEl);

        this.emptyEl = this.buildEmpty();
        this.emptyEl.hidden = true;
        this.root.appendChild(this.emptyEl);

        this.moreBtn = el('button', `${NS}__more`, this.t('loadMore', 'Load more comments'));
        this.moreBtn.type = 'button';
        this.moreBtn.hidden = true;
        this.moreBtn.addEventListener('click', () => this.loadMore());
        this.root.appendChild(this.moreBtn);
    }

    buildEmpty() {
        const wrap = el('div', `${NS}__empty`);
        wrap.appendChild(icon('bubble', `${NS}__empty-icon`));
        wrap.appendChild(el('p', `${NS}__empty-text`, this.t('empty', 'Be the first to comment.')));
        return wrap;
    }

    /**
     * Build the composer (parentId null) or an inline reply form.
     */
    buildForm(parentId) {
        const isReply = parentId != null;
        const form = el('form', `${NS}__form${isReply ? ` ${NS}__form--reply` : ''}`);

        const fields = el('div', `${NS}__form-fields`);

        const nameInput = el('input', `${NS}__input`);
        nameInput.type = 'text';
        nameInput.placeholder = this.t('name', 'Your name');
        nameInput.autocomplete = 'name';
        nameInput.required = this.requireName && !this.canModerate;
        if (!this.canModerate) {
            fields.appendChild(nameInput);
        }

        const textarea = el('textarea', `${NS}__textarea`);
        textarea.placeholder = isReply
            ? this.t('replyPlaceholder', 'Write a reply…')
            : this.t('commentPlaceholder', 'Join the discussion…');
        textarea.maxLength = this.maxLength;
        textarea.rows = isReply ? 2 : 3;
        textarea.required = true;
        fields.appendChild(textarea);
        form.appendChild(fields);

        // Honeypot: hidden from humans, tempting to bots.
        const honeypot = el('input', `${NS}__honeypot`);
        honeypot.type = 'text';
        honeypot.name = 'website';
        honeypot.tabIndex = -1;
        honeypot.autocomplete = 'off';
        honeypot.setAttribute('aria-hidden', 'true');

        const error = el('div', `${NS}__error`);
        error.hidden = true;

        const footer = el('div', `${NS}__form-footer`);
        const submit = el('button', `${NS}__btn ${NS}__btn--primary`, isReply ? this.t('reply', 'Reply') : this.t('post', 'Post comment'));
        submit.type = 'submit';
        footer.appendChild(submit);

        form.append(honeypot, error, footer);

        return this.bindForm(form, parentId, { nameInput, textarea, honeypot, error, submit });
    }

    bindForm(form, parentId, controls = null) {
        const bound = controls || {
            nameInput: form.querySelector('[data-bd-name]'),
            textarea: form.querySelector('[data-bd-body]'),
            honeypot: form.querySelector('[data-bd-honeypot]'),
            error: form.querySelector('[data-bd-error]'),
            submit: form.querySelector('[data-bd-submit]'),
        };
        if (!bound.textarea || !bound.honeypot || !bound.error || !bound.submit) return null;

        // Prefill the remembered name so returning visitors don't retype it.
        if (bound.nameInput && this.savedName && !bound.nameInput.value) {
            bound.nameInput.value = this.savedName;
        }

        autoGrow(bound.textarea);
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.submit({ form, ...bound, parentId });
        });

        return { form, ...bound };
    }

    /* ---------- data ---------- */

    readInitial() {
        const script = this.root.querySelector('script[data-bd-initial]');
        if (!script) return null;
        return safeJson(script.textContent, null);
    }

    hydrateServerMarkup() {
        const form = this.root.querySelector('[data-bd-composer]');
        this.listEl = this.root.querySelector('[data-bd-list]');
        this.emptyEl = this.root.querySelector('[data-bd-empty]');
        this.moreBtn = this.root.querySelector('[data-bd-more]');
        if (!form || !this.listEl || !this.emptyEl || !this.moreBtn) return false;

        this.composer = this.bindForm(form, null);
        if (!this.composer) return false;

        this.emptyEl.hidden = this.comments.size > 0;
        this.moreBtn.hidden = !this.hasMore;
        this.moreBtn.addEventListener('click', () => this.loadMore());
        this.bindServerInteractions();

        return true;
    }

    bindServerInteractions() {
        this.root.querySelectorAll('[data-bd-reply]').forEach((button) => {
            button.addEventListener('click', () => {
                const wrap = button.closest(`.${NS}__comment`);
                if (wrap) this.toggleReply(parseInt(button.dataset.bdReply, 10), wrap);
            });
        });

        this.root.querySelectorAll('[data-bd-delete]').forEach((button) => {
            button.addEventListener('click', () => this.delete(parseInt(button.dataset.bdDelete, 10)));
        });

        this.root.querySelectorAll('[data-bd-reaction]').forEach((button) => {
            button.addEventListener('click', () => {
                if (button.closest(`.${NS}__picker`)) this.closePicker();
                this.react(parseInt(button.dataset.bdCommentId, 10), button.dataset.bdReaction);
            });
        });

        this.root.querySelectorAll('[data-bd-picker]').forEach((button) => {
            const picker = button.parentElement?.querySelector(`.${NS}__picker`);
            if (!picker) return;
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                this.togglePicker(picker);
            });
        });

        this.root.querySelectorAll('[data-bd-replies-toggle]').forEach((toggle) => {
            const repliesEl = toggle.nextElementSibling;
            if (!repliesEl?.classList.contains(`${NS}__replies`)) return;
            this.bindRepliesToggle(
                toggle,
                parseInt(toggle.dataset.bdRepliesToggle, 10),
                parseInt(toggle.dataset.bdReplyCount, 10),
                repliesEl,
            );
        });
    }

    /** Request headers shared by every API call, including the visitor id. */
    apiHeaders(extra = {}) {
        const headers = { Accept: 'application/json', ...extra };
        if (this.visitorId) headers['X-BD-Visitor'] = this.visitorId;
        return headers;
    }

    async fetchJson(url) {
        try {
            const res = await fetch(url, {
                headers: this.apiHeaders(),
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!res.ok) return null;
            return await res.json();
        } catch (e) {
            return null;
        }
    }

    async refreshCsrfToken() {
        if (!this.csrfTokenUrl) return false;
        if (this.csrfRefreshPromise) return this.csrfRefreshPromise;

        this.csrfRefreshPromise = (async () => {
            try {
                const response = await fetch(this.csrfTokenUrl, {
                    headers: this.apiHeaders(),
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!response.ok) return false;

                const data = await response.json();
                if (typeof data.token !== 'string' || data.token === '') return false;

                this.csrfToken = data.token;
                this.root.dataset.csrfToken = data.token;

                return true;
            } catch (e) {
                return false;
            }
        })();

        try {
            return await this.csrfRefreshPromise;
        } finally {
            this.csrfRefreshPromise = null;
        }
    }

    ingest(data) {
        if (typeof data.canModerate === 'boolean') {
            this.canModerate = data.canModerate;
        }
        (data.comments || []).forEach((c) => this.comments.set(c.id, c));
        if (data.lastId) this.lastId = Math.max(this.lastId, data.lastId);
    }

    async loadInitial() {
        const data = await this.fetchJson(this.listUrl);
        if (!data) return;
        this.ingest(data);
        this.oldestRootId = data.nextBefore ?? null;
        this.hasMore = Boolean(data.hasMore);
        this.render();
        this.startPolling();
    }

    async loadMore() {
        if (!this.hasMore || this.loadingMore || this.oldestRootId == null) return;
        this.loadingMore = true;
        this.moreBtn.disabled = true;

        const data = await this.fetchJson(`${this.listUrl}?before=${this.oldestRootId}`);
        this.loadingMore = false;
        this.moreBtn.disabled = false;
        if (!data) return;

        this.ingest(data);
        if (data.nextBefore != null) this.oldestRootId = data.nextBefore;
        this.hasMore = Boolean(data.hasMore);
        this.render();
    }

    startPolling() {
        if (this.pollInterval > 0 && !this.pollTimer) {
            this.pollTimer = setInterval(() => this.poll(), this.pollInterval);
        }
    }

    async poll() {
        const params = new URLSearchParams({ since: String(this.lastId) });
        const syncIds = this.nextSyncPollIds();
        if (syncIds.length > 0) params.set('sync_ids', syncIds.join(','));

        const data = await this.fetchJson(`${this.listUrl}?${params.toString()}`);
        if (!data) return;

        this.ingest(data);
        const commentsRemoved = this.applyRemovedComments(data.removedCommentIds || []);
        const reactionsChanged = this.applyReactionUpdates(data.reactionUpdates || []);
        if (!(data.comments || []).length && !commentsRemoved && !reactionsChanged) return;
        this.render();
    }

    nextSyncPollIds() {
        const ids = [...this.comments.keys()].sort((a, b) => a - b);
        if (ids.length <= SYNC_POLL_BATCH_SIZE) return ids;

        if (this.syncPollOffset >= ids.length) this.syncPollOffset = 0;
        const batch = ids.slice(this.syncPollOffset, this.syncPollOffset + SYNC_POLL_BATCH_SIZE);
        this.syncPollOffset = (this.syncPollOffset + SYNC_POLL_BATCH_SIZE) % ids.length;

        return batch;
    }

    applyRemovedComments(ids) {
        const removed = collectRemovedCommentIds(this.comments, ids);
        if (removed.size === 0) return false;

        removed.forEach((id) => {
            this.comments.delete(id);
            this.expanded.delete(id);
        });

        return true;
    }

    applyReactionUpdates(updates) {
        let changed = false;
        updates.forEach((update) => {
            const comment = this.comments.get(update.commentId);
            if (!comment) return;

            const next = update.reactions || [];
            if (!sameReactions(comment.reactions || [], next)) {
                comment.reactions = next;
                changed = true;
            }
        });

        return changed;
    }

    /* ---------- rendering ---------- */

    render() {
        this.closePicker();
        const all = [...this.comments.values()];
        const roots = all
            .filter((c) => !c.parentId)
            .sort((a, b) => b.id - a.id); // newest first

        this.listEl.innerHTML = '';
        this.emptyEl.hidden = roots.length > 0;
        this.moreBtn.hidden = !this.hasMore;

        roots.forEach((root) => {
            const replies = all
                .filter((c) => c.parentId === root.id)
                .sort((a, b) => a.id - b.id);
            this.listEl.appendChild(this.renderComment(root, replies));
        });
    }

    renderComment(comment, replies = []) {
        const isReply = Boolean(comment.parentId);
        const wrap = el('article', `${NS}__comment${isReply ? ` ${NS}__comment--reply` : ''}`);
        wrap.dataset.id = comment.id;

        const rowEl = el('div', `${NS}__row`);
        rowEl.appendChild(avatarFor(comment.author));

        const main = el('div', `${NS}__main`);

        const meta = el('div', `${NS}__meta`);
        meta.appendChild(el('span', `${NS}__author`, comment.author));
        if (comment.authenticated) {
            meta.appendChild(el('span', `${NS}__badge ${NS}__badge--staff`, this.t('staff', 'staff')));
        }
        if (comment.status === 'pending') {
            meta.appendChild(el('span', `${NS}__badge ${NS}__badge--pending`, this.t('pending', 'awaiting review')));
        }
        const rt = relTime(comment.createdAt, this.locale);
        const time = el('time', `${NS}__time`, rt.text);
        time.dateTime = comment.createdAt;
        time.title = rt.title;
        meta.appendChild(time);
        main.appendChild(meta);

        const body = el('div', `${NS}__body`);
        renderMultiline(body, comment.body);
        main.appendChild(body);

        main.appendChild(this.renderToolbar(comment, wrap));
        rowEl.appendChild(main);
        wrap.appendChild(rowEl);

        // Replies (one level), collapsed behind a toggle on the root.
        const repliesEl = el('div', `${NS}__replies`);
        replies.forEach((reply) => repliesEl.appendChild(this.renderComment(reply)));

        if (!isReply && replies.length > 0) {
            wrap.appendChild(this.renderRepliesToggle(comment.id, replies.length, repliesEl));
        }
        wrap.appendChild(repliesEl);

        return wrap;
    }

    renderToolbar(comment, wrap) {
        const bar = el('div', `${NS}__toolbar`);
        if (this.reactionsEnabled) {
            bar.appendChild(this.renderReactions(comment));
        }
        if (this.repliesEnabled && !comment.parentId) {
            const replyBtn = el('button', `${NS}__action`, this.t('reply', 'Reply'));
            replyBtn.type = 'button';
            replyBtn.prepend(icon('reply', `${NS}__action-icon`));
            replyBtn.addEventListener('click', () => this.toggleReply(comment.id, wrap));
            bar.appendChild(replyBtn);
        }
        if (this.canModerate && comment.status !== 'deleted') {
            const delBtn = el('button', `${NS}__action ${NS}__action--danger`, this.t('delete', 'Delete'));
            delBtn.type = 'button';
            delBtn.addEventListener('click', () => this.delete(comment.id));
            bar.appendChild(delBtn);
        }
        return bar;
    }

    renderReactions(comment) {
        const wrap = el('div', `${NS}__reactions`);
        const counts = {};
        (comment.reactions || []).forEach((r) => { counts[r.emoji] = r; });

        // Show a chip only for reactions that have a count or that you picked.
        this.reactions.forEach((emoji) => {
            const data = counts[emoji];
            if (data && (data.count > 0 || data.mine)) {
                wrap.appendChild(this.reactionChip(comment.id, emoji, data));
            }
        });

        // "+" trigger reveals all available reactions in a small popover.
        const add = el('div', `${NS}__react-add`);
        const addBtn = el('button', `${NS}__react-add-btn`);
        addBtn.type = 'button';
        addBtn.setAttribute('aria-label', this.t('addReaction', 'Add reaction'));
        addBtn.appendChild(icon('smile'));

        const picker = el('div', `${NS}__picker`);
        picker.hidden = true;
        this.reactions.forEach((emoji) => {
            const opt = el('button', `${NS}__picker-option`, emoji);
            opt.type = 'button';
            opt.addEventListener('click', () => { this.closePicker(); this.react(comment.id, emoji); });
            picker.appendChild(opt);
        });
        addBtn.addEventListener('click', (e) => { e.stopPropagation(); this.togglePicker(picker); });

        add.append(addBtn, picker);
        wrap.appendChild(add);
        return wrap;
    }

    reactionChip(commentId, emoji, data) {
        const btn = el('button', `${NS}__reaction${data.mine ? ` ${NS}__reaction--active` : ''}`);
        btn.type = 'button';
        btn.setAttribute('aria-pressed', data.mine ? 'true' : 'false');
        btn.append(
            el('span', `${NS}__reaction-emoji`, emoji),
            el('span', `${NS}__reaction-count`, String(data.count)),
        );
        btn.addEventListener('click', () => this.react(commentId, emoji));
        return btn;
    }

    renderRepliesToggle(commentId, count, repliesEl) {
        const toggle = el('button', `${NS}__replies-toggle`);
        toggle.type = 'button';
        toggle.prepend(icon('chevron', `${NS}__replies-chevron`));
        const label = el('span', `${NS}__replies-label`);
        toggle.appendChild(label);
        this.bindRepliesToggle(toggle, commentId, count, repliesEl);
        return toggle;
    }

    bindRepliesToggle(toggle, commentId, count, repliesEl) {
        const label = toggle.querySelector(`.${NS}__replies-label`);
        if (!label) return;
        const open = this.expanded.has(commentId);
        const setState = (isOpen) => {
            repliesEl.hidden = !isOpen;
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.classList.toggle(`${NS}__replies-toggle--open`, isOpen);
            label.textContent = isOpen ? this.t('hideReplies', 'Hide replies') : this.pluralReplies(count);
        };
        setState(open);
        toggle.addEventListener('click', () => {
            const isOpen = !this.expanded.has(commentId);
            if (isOpen) this.expanded.add(commentId);
            else this.expanded.delete(commentId);
            setState(isOpen);
        });
    }

    /* ---------- reaction picker ---------- */

    togglePicker(picker) {
        const willOpen = picker.hidden;
        this.closePicker();
        if (!willOpen) return;
        picker.hidden = false;
        this.openPicker = picker;
        this.docClick = (ev) => { if (!picker.contains(ev.target)) this.closePicker(); };
        setTimeout(() => document.addEventListener('click', this.docClick), 0);
    }

    closePicker() {
        if (this.openPicker) { this.openPicker.hidden = true; this.openPicker = null; }
        if (this.docClick) { document.removeEventListener('click', this.docClick); this.docClick = null; }
    }

    /* ---------- actions ---------- */

    toggleReply(commentId, wrap) {
        const main = wrap.querySelector(`:scope > .${NS}__row > .${NS}__main`);
        const existing = main.querySelector(`:scope > .${NS}__form`);
        if (existing) {
            existing.remove();
            return;
        }
        const replyForm = this.buildForm(commentId);
        main.appendChild(replyForm.form);
        replyForm.textarea.focus();
    }

    async submit({ nameInput, textarea, honeypot, error, submit, parentId, form }) {
        error.hidden = true;
        submit.disabled = true;

        try {
            const { response: res, data } = await fetchWithCsrfRetry(
                () => fetch(this.listUrl, {
                    method: 'POST',
                    headers: this.apiHeaders({ 'Content-Type': 'application/json' }),
                    credentials: 'same-origin',
                    cache: 'no-store',
                    body: JSON.stringify({
                        body: textarea.value,
                        authorName: nameInput?.value || '',
                        parentId: parentId || null,
                        website: honeypot.value,
                        _token: this.csrfToken,
                    }),
                }),
                () => this.refreshCsrfToken(),
            );

            if (!res.ok) {
                error.textContent = data?.error || this.t('genericError', 'Something went wrong.');
                error.hidden = false;
                return;
            }

            // Remember the name for next time (and prefill open reply forms).
            this.rememberName(nameInput?.value);

            textarea.value = '';
            textarea.style.height = '';
            if (data.comment) {
                this.comments.set(data.comment.id, data.comment);
                this.lastId = Math.max(this.lastId, data.comment.id);
                // Keep the thread open so the author sees their new reply.
                if (parentId) this.expanded.add(parentId);
                this.render();
            }
            if (data.status === 'pending') {
                this.flash(this.composer.form, this.t('awaitingModeration', 'Thanks! Your comment is awaiting moderation.'));
            }
            if (parentId) {
                form.remove();
            }
        } catch (e) {
            error.textContent = this.t('networkError', 'Network error. Please try again.');
            error.hidden = false;
        } finally {
            submit.disabled = false;
        }
    }

    async react(commentId, emoji) {
        try {
            const { response: res, data } = await fetchWithCsrfRetry(
                () => fetch(this.reactionUrlTemplate.replace('__ID__', commentId), {
                    method: 'POST',
                    headers: this.apiHeaders({ 'Content-Type': 'application/json' }),
                    credentials: 'same-origin',
                    cache: 'no-store',
                    body: JSON.stringify({ emoji, _token: this.csrfToken }),
                }),
                () => this.refreshCsrfToken(),
            );
            if (!res.ok) return;

            const comment = this.comments.get(commentId);
            if (!comment) return;
            comment.reactions = comment.reactions || [];
            const idx = comment.reactions.findIndex((r) => r.emoji === emoji);
            if (idx >= 0) {
                comment.reactions[idx] = { emoji, count: data.count, mine: data.mine };
            } else {
                comment.reactions.push({ emoji, count: data.count, mine: data.mine });
            }
            this.render();
        } catch (e) { /* ignore */ }
    }

    async delete(commentId) {
        if (!window.confirm(this.t('confirmDelete', 'Delete this comment?'))) return;
        try {
            const { response: res } = await fetchWithCsrfRetry(
                () => fetch(this.deleteUrlTemplate.replace('__ID__', commentId), {
                    method: 'DELETE',
                    headers: this.apiHeaders({ 'X-CSRF-Token': this.csrfToken }),
                    credentials: 'same-origin',
                    cache: 'no-store',
                }),
                () => this.refreshCsrfToken(),
            );
            if (!res.ok) return;
            this.comments.delete(commentId);
            [...this.comments.values()]
                .filter((c) => c.parentId === commentId)
                .forEach((c) => this.comments.delete(c.id));
            this.render();
        } catch (e) { /* ignore */ }
    }

    /** Persist the name to localStorage and reflect it in open forms. */
    rememberName(value) {
        const name = (value || '').trim();
        if (name === '' || name === this.savedName) return;
        this.savedName = name;
        storageSet(`${NS}:name`, name);
        this.root.querySelectorAll(`.${NS}__input`).forEach((input) => {
            if (!input.value) input.value = name;
        });
    }

    flash(formEl, message) {
        let notice = formEl.querySelector(`.${NS}__notice`);
        if (!message) {
            if (notice) notice.remove();
            return;
        }
        if (!notice) {
            notice = el('div', `${NS}__notice`);
            formEl.appendChild(notice);
        }
        notice.textContent = message;
    }
}

/* ---------- helpers ---------- */

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
}

function renderMultiline(container, text) {
    String(text).split('\n').forEach((line, i) => {
        if (i > 0) container.appendChild(document.createElement('br'));
        container.appendChild(document.createTextNode(line));
    });
}

function safeJson(value, fallback) {
    try { return JSON.parse(value); } catch (e) { return fallback; }
}

/* localStorage access guarded for private mode / disabled storage. */
function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
}

function storageSet(key, value) {
    try { window.localStorage.setItem(key, value); } catch (e) { /* quota / disabled: ignore */ }
}

/** A stable 32-char hex visitor id, generated once and kept in localStorage. */
function ensureVisitorId() {
    let id = storageGet(`${NS}:vid`);
    if (!id || !/^[a-f0-9]{32}$/.test(id)) {
        id = randomHex(16);
        storageSet(`${NS}:vid`, id);
    }
    return id;
}

function randomHex(bytes) {
    const crypto = window.crypto || window.msCrypto;
    if (crypto && crypto.getRandomValues) {
        const arr = new Uint8Array(bytes);
        crypto.getRandomValues(arr);
        return [...arr].map((b) => b.toString(16).padStart(2, '0')).join('');
    }
    let hex = '';
    while (hex.length < bytes * 2) hex += Math.floor(Math.random() * 16).toString(16);
    return hex.slice(0, bytes * 2);
}

/** Locale-aware relative timestamp ("2 hours ago"), with an absolute title. */
function relTime(iso, locale) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return { text: '', title: '' };
    const diff = (d.getTime() - Date.now()) / 1000;
    const abs = Math.abs(diff);
    let text;
    try {
        const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
        if (abs < 45) text = rtf.format(Math.round(diff), 'second');
        else if (abs < 2700) text = rtf.format(Math.round(diff / 60), 'minute');
        else if (abs < 86400) text = rtf.format(Math.round(diff / 3600), 'hour');
        else if (abs < 2592000) text = rtf.format(Math.round(diff / 86400), 'day');
        else text = d.toLocaleDateString(locale, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
        text = d.toLocaleString();
    }
    let title = text;
    try { title = d.toLocaleString(locale); } catch (e) { /* keep */ }
    return { text, title };
}

const NAME_INITIALS = (name) => {
    const parts = String(name).trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
};

const NAME_HUE = (name) => {
    let h = 0;
    for (const ch of String(name)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
    return h % 360;
};

/** Author avatar: initials on a colour derived from the name. */
function avatarFor(name) {
    const av = el('span', `${NS}__avatar`, NAME_INITIALS(name));
    av.style.setProperty('--bd-avatar-hue', String(NAME_HUE(name)));
    av.setAttribute('aria-hidden', 'true');
    return av;
}

const ICONS = {
    smile: '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5s1.3 2 3.5 2 3.5-2 3.5-2"/><line x1="9" y1="9.5" x2="9.01" y2="9.5"/><line x1="15" y1="9.5" x2="15.01" y2="9.5"/>',
    reply: '<path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 0 1 5 5v1"/>',
    chevron: '<path d="M9 6l6 6-6 6"/>',
    bubble: '<path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/>',
};

/** Inline, currentColor SVG icon (stroke style) keyed by name. */
function icon(name, className) {
    const wrap = el('span', `${NS}__icon${className ? ` ${className}` : ''}`);
    wrap.setAttribute('aria-hidden', 'true');
    wrap.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${ICONS[name] || ''}</svg>`;
    return wrap;
}

/** Textarea that grows with its content. */
function autoGrow(textarea) {
    const resize = () => {
        textarea.style.height = 'auto';
        textarea.style.height = `${textarea.scrollHeight}px`;
    };
    textarea.addEventListener('input', resize);
}

function init() {
    document.querySelectorAll(`.${NS}:not([data-bd-ready])`).forEach((root) => {
        root.setAttribute('data-bd-ready', '1');
        new DiscussionWidget(root);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
