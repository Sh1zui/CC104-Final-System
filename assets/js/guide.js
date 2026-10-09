/*
 * Guide page: highlight the contents entry for the section on screen,
 * and "Back to the top" without a dead #anchor.
 */
(function () {
    'use strict';

    var links = Array.prototype.slice.call(document.querySelectorAll('.guide-toc a'));
    var sections = links.map(function (a) { return document.querySelector(a.getAttribute('href')); }).filter(Boolean);

    function mark(id) {
        links.forEach(function (a) {
            var on = a.getAttribute('href') === '#' + id;
            a.classList.toggle('active', on);
            if (on) a.setAttribute('aria-current', 'location'); else a.removeAttribute('aria-current');
        });
    }

    if ('IntersectionObserver' in window && sections.length) {
        var visible = {};
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { visible[e.target.id] = e.isIntersecting; });
            var first = sections.find(function (s) { return visible[s.id]; });
            if (first) mark(first.id);
        }, { rootMargin: '-80px 0px -55% 0px' });
        sections.forEach(function (s) { io.observe(s); });
        mark(sections[0].id);
    }

    document.querySelectorAll('[data-scroll-top]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            if (links[0]) links[0].focus();
        });
    });
})();
