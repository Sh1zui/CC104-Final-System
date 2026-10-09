/*
 * The quick tour: a spotlight walkthrough of the screen for new users.
 *
 * Loaded only when the page carries #tour-data (see includes/header.php):
 * a new user's first dashboard visit, or ?tour=1 from the Guide page.
 * Steps come from tour_steps() in includes/guide.php; a step whose
 * target isn't on this page is skipped. Finishing or skipping tells
 * api/tour.php, so it doesn't run again.
 *
 * Keyboard: Right/Enter = next, Left = back, Esc = skip. Focus stays in
 * the card while it's open and goes back where it was afterwards.
 */
(function () {
    'use strict';

    var dataEl = document.getElementById('tour-data');
    if (!dataEl) return;
    var data;
    try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }

    var steps = (data.steps || []).filter(function (s) {
        return !s.target || document.querySelector(s.target);
    });
    if (!steps.length) return;

    var index = 0;
    var root, spot, pop, titleEl, bodyEl, countEl, backBtn, nextBtn, progressEl;
    var openedSidebar = false;
    var returnFocus = document.activeElement;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (html !== undefined) n.innerHTML = html;
        return n;
    }

    function build() {
        root = el('div', 'tour');
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', 'tour-title');
        root.setAttribute('aria-describedby', 'tour-body');

        var scrim = el('div', 'tour-scrim');
        spot = el('div', 'tour-spot');
        pop = el('div', 'tour-pop');

        countEl = el('p', 'tour-count');
        titleEl = el('h2', 'tour-title');
        titleEl.id = 'tour-title';
        bodyEl = el('p', 'tour-body');
        bodyEl.id = 'tour-body';
        progressEl = el('div', 'tour-progress');
        progressEl.setAttribute('aria-hidden', 'true');
        steps.forEach(function () { progressEl.appendChild(el('span')); });

        var actions = el('div', 'tour-actions');
        var skipBtn = el('button', 'btn btn-link tour-skip', 'Skip tour');
        skipBtn.type = 'button';
        backBtn = el('button', 'btn btn-outline-secondary btn-sm', 'Back');
        backBtn.type = 'button';
        nextBtn = el('button', 'btn btn-brand btn-sm', 'Next');
        nextBtn.type = 'button';
        var nav = el('div', 'tour-nav');
        nav.appendChild(backBtn);
        nav.appendChild(nextBtn);
        actions.appendChild(skipBtn);
        actions.appendChild(nav);

        pop.appendChild(countEl);
        pop.appendChild(titleEl);
        pop.appendChild(bodyEl);
        pop.appendChild(progressEl);
        pop.appendChild(actions);
        root.appendChild(scrim);
        root.appendChild(spot);
        root.appendChild(pop);
        document.body.appendChild(root);
        document.body.classList.add('tour-open');

        skipBtn.addEventListener('click', function () { finish(false); });
        backBtn.addEventListener('click', function () { go(index - 1); });
        nextBtn.addEventListener('click', function () { index === steps.length - 1 ? finish(true) : go(index + 1); });
        // A click outside the card does nothing: the tour only ends on purpose.
        scrim.addEventListener('click', function () { nextBtn.focus(); });

        document.addEventListener('keydown', onKey, true);
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
    }

    function onKey(e) {
        if (!root) return;
        if (e.key === 'Escape') { e.preventDefault(); finish(false); return; }
        if (e.key === 'ArrowRight') { e.preventDefault(); nextBtn.click(); return; }
        if (e.key === 'ArrowLeft' && index > 0) { e.preventDefault(); go(index - 1); return; }
        if (e.key === 'Tab') {
            // Keep focus inside the card.
            var f = pop.querySelectorAll('button:not([disabled])');
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }

    // On phones the sidebar is off-canvas: open it for steps that point into it.
    function sidebarIsHidden() {
        var sb = document.getElementById('sidebar');
        if (!sb) return false;
        var r = sb.getBoundingClientRect();
        return r.right <= 1;
    }
    function setSidebar(open) {
        var sb = document.getElementById('sidebar');
        var bd = document.getElementById('sidebar-backdrop');
        if (!sb) return;
        sb.classList.toggle('open', open);
        if (bd) bd.classList.toggle('open', open);
        openedSidebar = open;
    }

    function target() {
        var s = steps[index];
        return s.target ? document.querySelector(s.target) : null;
    }

    function go(n) {
        index = Math.max(0, Math.min(steps.length - 1, n));
        var s = steps[index];
        var t = target();

        var inSidebar = t && t.closest('#sidebar');
        var wait = 0;
        if (inSidebar && (openedSidebar || sidebarIsHidden())) {
            if (!openedSidebar) { setSidebar(true); wait = reduceMotion ? 0 : 230; }
        } else if (openedSidebar) {
            setSidebar(false);
        }

        countEl.textContent = 'Step ' + (index + 1) + ' of ' + steps.length;
        titleEl.textContent = s.title;
        bodyEl.textContent = s.body;
        backBtn.disabled = index === 0;
        backBtn.hidden = index === 0;
        nextBtn.textContent = index === steps.length - 1 ? 'Finish' : 'Next';
        Array.prototype.forEach.call(progressEl.children, function (dot, i) {
            dot.className = i < index ? 'done' : (i === index ? 'current' : '');
        });

        if (t && !inSidebar) {
            var r = t.getBoundingClientRect();
            if (r.top < 70 || r.bottom > window.innerHeight - 20) {
                t.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
                wait = Math.max(wait, reduceMotion ? 0 : 320);
            }
        }
        place();
        if (wait) setTimeout(place, wait);
        nextBtn.focus({ preventScroll: true });
    }

    function place() {
        if (!root) return;
        var t = target();
        var vw = window.innerWidth, vh = window.innerHeight;
        var phone = vw < 576;

        if (!t) {
            root.classList.add('is-centered');
            spot.style.display = 'none';
            pop.style.left = pop.style.top = '';
            return;
        }
        root.classList.remove('is-centered');
        spot.style.display = '';

        var r = t.getBoundingClientRect();
        var pad = 6;
        spot.style.left = (r.left - pad) + 'px';
        spot.style.top = (r.top - pad) + 'px';
        spot.style.width = (r.width + pad * 2) + 'px';
        spot.style.height = (r.height + pad * 2) + 'px';

        if (phone) { pop.style.left = pop.style.top = ''; return; } // CSS docks the card at the bottom

        var pw = pop.offsetWidth, ph = pop.offsetHeight, gap = 14;
        var left, top;
        if (r.right + gap + pw <= vw - 12) {            // right of the target
            left = r.right + gap; top = r.top + r.height / 2 - ph / 2;
        } else if (r.bottom + gap + ph <= vh - 12) {     // below
            left = r.left + r.width / 2 - pw / 2; top = r.bottom + gap;
        } else if (r.top - gap - ph >= 12) {             // above
            left = r.left + r.width / 2 - pw / 2; top = r.top - gap - ph;
        } else {                                         // left
            left = r.left - gap - pw; top = r.top + r.height / 2 - ph / 2;
        }
        pop.style.left = Math.max(12, Math.min(left, vw - pw - 12)) + 'px';
        pop.style.top = Math.max(12, Math.min(top, vh - ph - 12)) + 'px';
    }

    function finish(completed) {
        if (!root) return;
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('resize', place);
        window.removeEventListener('scroll', place, true);
        root.remove();
        root = null;
        document.body.classList.remove('tour-open');
        if (openedSidebar) setSidebar(false);

        // Remember it on the server (a replay is already marked; harmless).
        var meta = document.querySelector('meta[name="csrf-token"]');
        var body = new FormData();
        body.append('action', 'complete');
        body.append('csrf_token', meta ? meta.content : '');
        fetch(window.APP_BASE_URL + 'api/tour.php', { method: 'POST', body: body, credentials: 'same-origin' }).catch(function () {});

        if (data.replay && window.history && history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.delete('tour');
            history.replaceState(null, '', url.pathname + url.search + url.hash);
        }
        if (window.AutoWay && AutoWay.toast) {
            AutoWay.toast(completed
                ? 'You\'re all set. Replay the tour any time from Guide in the sidebar.'
                : 'Tour skipped. It\'s in Guide in the sidebar whenever you want it.', 'info');
        }
        if (returnFocus && returnFocus.focus) { try { returnFocus.focus({ preventScroll: true }); } catch (e) { /* gone */ } }
    }

    function start() {
        build();
        go(0);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
