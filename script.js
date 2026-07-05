/* WikiLAN frontend: site-wide notice widget + push, seat map, attendance,
 * signups, shared-games finder. Config arrives via JSINFO.wikilan. */
(function () {
    'use strict';

    function cfg() {
        return (window.JSINFO && JSINFO.wikilan) || null;
    }

    function ajax(fn, data, method) {
        var c = cfg();
        var params = new URLSearchParams();
        params.set('call', 'plugin_wikilan');
        params.set('fn', fn);
        Object.keys(data || {}).forEach(function (k) { params.set(k, data[k]); });
        if (method === 'POST' && c && c.sectok) params.set('sectok', c.sectok);
        var url = DOKU_BASE + 'lib/exe/ajax.php';
        var opts = { method: method || 'GET', credentials: 'same-origin' };
        if (method === 'POST') {
            opts.body = params;
        } else {
            url += '?' + params.toString();
        }
        return fetch(url, opts).then(function (r) { return r.json(); });
    }

    /* ------------------------------------------------------------ toasts */

    var toastBox = null;
    function toast(title, body, kind) {
        if (!toastBox) {
            toastBox = document.createElement('div');
            toastBox.className = 'wl-toasts';
            document.body.appendChild(toastBox);
        }
        var t = document.createElement('div');
        t.className = 'wl-toast wl-toast-' + (kind || 'info');
        var h = document.createElement('strong');
        h.textContent = title;
        t.appendChild(h);
        if (body) {
            var b = document.createElement('div');
            b.textContent = body;
            t.appendChild(b);
        }
        toastBox.appendChild(t);
        setTimeout(function () { t.classList.add('wl-show'); }, 20);
        setTimeout(function () {
            t.classList.remove('wl-show');
            setTimeout(function () { t.remove(); }, 400);
        }, 8000);
    }

    /* ------------------------------------------------------------ notice widget */

    function initWidget() {
        var c = cfg();
        if (!c) return;

        var wrap = document.createElement('div');
        wrap.className = 'wl-widget';
        wrap.innerHTML =
            '<button class="wl-widget-bell" title="LAN">📢</button>' +
            '<div class="wl-widget-panel" hidden>' +
            '<div class="wl-widget-head"></div>' +
            '<ul class="wl-widget-list"></ul>' +
            '<div class="wl-widget-push"></div>' +
            '</div>';
        document.body.appendChild(wrap);
        var bell = wrap.querySelector('.wl-widget-bell');
        var panel = wrap.querySelector('.wl-widget-panel');
        var list = wrap.querySelector('.wl-widget-list');
        bell.addEventListener('click', function () {
            panel.hidden = !panel.hidden;
        });

        var lastId = parseInt(localStorage.getItem('wl_last_notice') || '0', 10);
        var first = true;

        function renderNotice(n) {
            var li = document.createElement('li');
            li.className = 'wl-notice-' + n.kind;
            var when = new Date(n.ts * 1000);
            li.textContent = ('0' + when.getHours()).slice(-2) + ':' +
                ('0' + when.getMinutes()).slice(-2) + ' ' + n.title +
                (n.body ? ' — ' + n.body : '');
            if (n.link) {
                var a = document.createElement('a');
                a.href = n.link;
                a.textContent = ' →';
                li.appendChild(a);
            }
            list.insertBefore(li, list.firstChild);
            while (list.children.length > 15) list.removeChild(list.lastChild);
        }

        function poll() {
            ajax('notices', { since: first ? 0 : lastId }).then(function (res) {
                (res.notices || []).forEach(function (n) {
                    if (n.id > lastId) {
                        if (!first) {
                            toast(n.title, n.body, n.kind);
                            bell.classList.add('wl-widget-unread');
                        }
                        lastId = n.id;
                        localStorage.setItem('wl_last_notice', String(lastId));
                        renderNotice(n);
                    } else if (first) {
                        renderNotice(n);
                    }
                });
                first = false;
            }).catch(function () { /* offline / logged-out poll hiccup */ });
        }
        poll();
        setInterval(poll, Math.max(5, c.poll || 30) * 1000);
        bell.addEventListener('click', function () {
            bell.classList.remove('wl-widget-unread');
        });

        initPush(wrap.querySelector('.wl-widget-push'));
    }

    /* ------------------------------------------------------------ web push */

    function b64urlToBytes(s) {
        var pad = '='.repeat((4 - (s.length % 4)) % 4);
        var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        var arr = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    function initPush(box) {
        var c = cfg();
        if (!c || !c.user) return; // push is the per-user channel
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

        var btn = document.createElement('button');
        btn.className = 'wl-push-btn';
        box.appendChild(btn);

        function setLabel(subscribed) {
            btn.textContent = subscribed ? '🔔 ✓' : '🔔 ?';
            btn.dataset.on = subscribed ? '1' : '0';
        }

        // the SW is scoped to the plugin dir, so serviceWorker.ready (which
        // waits for a worker controlling the *page*) never resolves — keep
        // the registration handle instead
        var regP = navigator.serviceWorker.register(c.sw);
        // prefetch the VAPID key so subscribe() runs inside the click gesture
        // (an async fetch in between loses the user-activation window)
        var keyP = ajax('push_pubkey');

        regP.then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            setLabel(!!sub);
        }).catch(function () { btn.remove(); });

        btn.addEventListener('click', function () {
            if (btn.dataset.on === '1') {
                regP.then(function (reg) {
                    return reg.pushManager.getSubscription();
                }).then(function (sub) {
                    if (!sub) return setLabel(false);
                    ajax('push_unsubscribe', { endpoint: sub.endpoint }, 'POST');
                    sub.unsubscribe().then(function () { setLabel(false); });
                });
                return;
            }
            Promise.all([regP, keyP]).then(function (rk) {
                return rk[0].pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: b64urlToBytes(rk[1].key)
                });
            }).then(function (sub) {
                var json = sub.toJSON();
                return ajax('push_subscribe', {
                    endpoint: sub.endpoint,
                    p256dh: json.keys.p256dh,
                    auth: json.keys.auth
                }, 'POST');
            }).then(function () { setLabel(true); })
              .catch(function () { setLabel(false); });
        });
    }

    /* ------------------------------------------------------------ seat map */

    function initSeating() {
        var c = cfg();
        document.querySelectorAll('.wl-seating').forEach(function (box) {
            var live = box.dataset.live === '1';
            var lan = box.dataset.lan;

            box.querySelectorAll('.wl-seat').forEach(bindSeat);

            function bindSeat(el) {
                var t = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                el.appendChild(t);
                updateTooltip(el);
                syncAvatar(el);
                el.addEventListener('click', function () {
                    var seat = el.dataset.seat;
                    var mine = c && c.user && el.dataset.user === c.user;
                    if (el.dataset.user && !mine) {
                        // someone else's seat: go to their profile (mods move
                        // people via the admin page, not by clicking here)
                        if (el.dataset.profile) location.href = el.dataset.profile;
                        return;
                    }
                    if (!live || !c || !c.user) return;
                    if (mine && el.dataset.buddy) {
                        // buddy-capable table: offer release OR sharing
                        openMenu(el, seat);
                        return;
                    }
                    var fn = mine ? 'seat_release' : 'seat_reserve';
                    ajax(fn, { lan: lan, seat: seat }, 'POST').then(function (res) {
                        if (res.confirm) {
                            // holding another seat — the server won't move us
                            // without an explicit ok
                            if (window.confirm(res.confirm)) {
                                ajax(fn, { lan: lan, seat: seat, move: 1 }, 'POST')
                                    .then(function (r2) {
                                        if (r2.error) toast(seat, r2.error, 'error');
                                        refresh(box, lan);
                                    });
                            }
                            return;
                        }
                        if (res.error) toast(seat, res.error, 'error');
                        refresh(box, lan);
                    });
                });
            }

            function lstr(key, arg) {
                var l = (window.LANG && LANG.plugins && LANG.plugins.wikilan) || {};
                return (l[key] || key).replace('%s', arg || '');
            }

            /* release / share-with-a-buddy menu for the own seat */
            function openMenu(el, seat) {
                closeMenu();
                var menu = document.createElement('div');
                menu.className = 'wl-seatmenu';
                var rel = document.createElement('button');
                rel.textContent = lstr('release', seat);
                rel.addEventListener('click', function () {
                    ajax('seat_release', { lan: lan, seat: seat }, 'POST').then(function (res) {
                        if (res.error) toast(seat, res.error, 'error');
                        closeMenu();
                        refresh(box, lan);
                    });
                });
                menu.appendChild(rel);

                var buddyEl = box.querySelector('.wl-seat[data-seat="' + el.dataset.buddy + '"]');
                if (!buddyEl) { // buddy spot still free: offer sharing
                    var label = document.createElement('span');
                    label.textContent = lstr('share_with');
                    var sel = document.createElement('select');
                    var go = document.createElement('button');
                    go.textContent = lstr('share_btn');
                    go.disabled = true;
                    ajax('buddy_candidates', { lan: lan }).then(function (res) {
                        var users = res.users || [];
                        if (!users.length) {
                            label.textContent = lstr('no_candidates');
                            sel.remove();
                            go.remove();
                            return;
                        }
                        users.forEach(function (u) {
                            var o = document.createElement('option');
                            o.value = u.user;
                            o.textContent = u.name;
                            sel.appendChild(o);
                        });
                        go.disabled = false;
                    });
                    go.addEventListener('click', function () {
                        ajax('seat_share', { lan: lan, user: sel.value }, 'POST').then(function (res) {
                            if (res.error) toast(seat, res.error, 'error');
                            closeMenu();
                            refresh(box, lan);
                        });
                    });
                    menu.appendChild(label);
                    menu.appendChild(sel);
                    menu.appendChild(go);
                }

                var cancel = document.createElement('button');
                cancel.textContent = lstr('cancel');
                cancel.addEventListener('click', closeMenu);
                menu.appendChild(cancel);
                box.insertBefore(menu, box.firstChild);
            }

            function closeMenu() {
                var m = box.querySelector('.wl-seatmenu');
                if (m) m.remove();
            }

            function updateTooltip(el) {
                var t = el.querySelector('title');
                if (!t) return;
                t.textContent = el.dataset.seat +
                    (el.dataset.username ? ': ' + el.dataset.username : '');
            }

            /* draw the occupant's avatar inside the hotspot (clipped to a
             * circle by CSS); the image ignores pointer events so clicks
             * still land on the hotspot itself */
            function syncAvatar(el) {
                var svg = el.ownerSVGElement;
                if (!svg) return;
                var img = svg.querySelector('.wl-seat-img[data-for="' + el.dataset.seat + '"]');
                if (!el.dataset.avatar) {
                    if (img) img.remove();
                    return;
                }
                var bb;
                try { bb = el.getBBox(); } catch (e) { return; }
                var d = Math.min(bb.width, bb.height) - 5; // keep the state ring visible
                if (d <= 0) return;
                if (!img) {
                    img = document.createElementNS('http://www.w3.org/2000/svg', 'image');
                    img.setAttribute('class', 'wl-seat-img');
                    img.dataset.for = el.dataset.seat;
                    el.parentNode.insertBefore(img, el.nextSibling);
                }
                img.setAttribute('href', el.dataset.avatar);
                img.setAttribute('x', bb.x + (bb.width - d) / 2);
                img.setAttribute('y', bb.y + (bb.height - d) / 2);
                img.setAttribute('width', d);
                img.setAttribute('height', d);
            }

            function refresh(bx, lanNs) {
                ajax('seat_states', { lan: lanNs }).then(function (res) {
                    var seats = res.seats || {};
                    // split/merge buddy pairs first so the styling pass below
                    // finds the elements; geometry comes from the data attrs
                    // the renderer took from the plan's own labels
                    Object.keys(seats).forEach(function (id) {
                        var s = seats[id];
                        if (!s.buddy_of) return;
                        var host = bx.querySelector('.wl-seat[data-seat="' + s.buddy_of + '"]');
                        if (!host || !host.dataset.bpos) return;
                        var bel = bx.querySelector('.wl-seat[data-seat="' + id + '"]');
                        function setPos(elm, xy) {
                            var p = xy.split(',');
                            elm.setAttribute('cx', p[0]);
                            elm.setAttribute('cy', p[1]);
                        }
                        if (s.user) {
                            setPos(host, host.dataset.home);
                            if (!bel) {
                                bel = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                                setPos(bel, host.dataset.bpos);
                                bel.setAttribute('r', host.getAttribute('r'));
                                bel.setAttribute('class', 'wl-seat');
                                bel.setAttribute('data-seat', id);
                                bel.setAttribute('data-host', s.buddy_of);
                                host.parentNode.insertBefore(bel, host.nextSibling);
                                bindSeat(bel);
                            }
                        } else {
                            setPos(host, host.dataset.mid);
                            if (bel) {
                                var img = bx.querySelector('.wl-seat-img[data-for="' + id + '"]');
                                if (img) img.remove();
                                bel.remove();
                            }
                        }
                    });
                    Object.keys(seats).forEach(function (id) {
                        var s = seats[id];
                        var el = bx.querySelector('.wl-seat[data-seat="' + id + '"]');
                        if (el) {
                            var cls = 'wl-seat wl-seat-' +
                                (s.state || (s.admin_only ? 'adminonly' : 'free'));
                            if (s.user && c && s.user === c.user) cls += ' wl-seat-mine';
                            el.setAttribute('class', cls);
                            if (s.user) {
                                el.dataset.user = s.user;
                                el.dataset.username = s.username || s.user;
                                if (s.profile) el.dataset.profile = s.profile;
                                if (s.avatar) el.dataset.avatar = s.avatar;
                                else delete el.dataset.avatar;
                            } else {
                                delete el.dataset.user;
                                delete el.dataset.username;
                                delete el.dataset.profile;
                                delete el.dataset.avatar;
                            }
                            updateTooltip(el);
                            syncAvatar(el);
                        }
                        var row = bx.querySelector('.wl-seat-table tr[data-seat="' + id + '"]');
                        if (row) {
                            var cells = row.querySelectorAll('td');
                            cells[1].textContent = s.state || (s.admin_only ? 'admin' : 'free');
                            cells[1].className = 'wl-state wl-seat-' +
                                (s.state || (s.admin_only ? 'adminonly' : 'free'));
                            cells[2].textContent = '';
                            if (s.username) {
                                var ua = document.createElement('a');
                                ua.href = s.profile || '#';
                                ua.textContent = s.username;
                                cells[2].appendChild(ua);
                            }
                        }
                    });
                });
            }

            if (live) setInterval(function () { refresh(box, lan); }, 30000);
        });

        // wrong-seat banner action
        document.querySelectorAll('.wl-move-reservation').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                ajax('seat_move', {}, 'POST').then(function () { location.reload(); });
            });
        });
    }

    /* ------------------------------------------------------------ attendance & signups */

    function initButtons() {
        var c = cfg();
        document.querySelectorAll('.wl-attend-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var lan = btn.closest('.wl-attendance').dataset.lan;
                var next = btn.dataset.attending !== '1';
                ajax('attend', { lan: lan, attending: next ? 1 : 0 }, 'POST').then(function (res) {
                    if (res.error) return toast('', res.error, 'error');
                    location.reload();
                });
            });
        });

        document.querySelectorAll('.wl-signup').forEach(function (box) {
            var pid = box.dataset.event;
            box.querySelectorAll('.wl-signup-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var comment = box.querySelector('.wl-signup-comment');
                    ajax('signup', {
                        event: pid,
                        state: btn.dataset.state,
                        comment: comment ? comment.value : ''
                    }, 'POST').then(function (res) {
                        if (res.error) return toast('', res.error, 'error');
                        location.reload();
                    });
                });
            });
            box.querySelectorAll('.wl-paid-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    ajax('signup_paid', {
                        event: pid,
                        user: btn.dataset.user,
                        paid: btn.dataset.paid
                    }, 'POST').then(function (res) {
                        if (res.error) return toast('', res.error, 'error');
                        location.reload();
                    });
                });
            });
        });
    }

    /* ------------------------------------------------------------ shared games */

    function initSharedGames() {
        document.querySelectorAll('.wl-sharedgames').forEach(function (box) {
            var usersBox = box.querySelector('.wl-shared-users');
            var results = box.querySelector('.wl-shared-results');
            var lan = box.dataset.lan || '';

            ajax('attendee_options', { lan: lan }).then(function (res) {
                (res.users || []).forEach(function (u) {
                    var label = document.createElement('label');
                    label.className = 'wl-shared-user';
                    var cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.value = u.user;
                    label.appendChild(cb);
                    label.appendChild(document.createTextNode(' ' + u.name));
                    usersBox.appendChild(label);
                });
            });

            box.querySelector('.wl-shared-go').addEventListener('click', function () {
                var picked = Array.prototype.map.call(
                    usersBox.querySelectorAll('input:checked'),
                    function (cb) { return cb.value; }
                );
                var mponly = box.querySelector('.wl-shared-mponly input').checked;
                var minp = box.querySelector('.wl-shared-minplayers input');
                results.textContent = '…';
                ajax('sharedgames', {
                    users: picked.join(','),
                    mponly: mponly ? 1 : 0,
                    minplayers: minp ? (parseInt(minp.value, 10) || 0) : 0
                })
                    .then(function (res) {
                        results.textContent = '';
                        var games = res.games || [];
                        if (!games.length) {
                            results.textContent = '—';
                            return;
                        }
                        var ul = document.createElement('ul');
                        games.forEach(function (g) {
                            var li = document.createElement('li');
                            var a = document.createElement('a');
                            a.href = 'https://store.steampowered.com/app/' + g.appid + '/';
                            a.target = '_blank';
                            a.rel = 'noopener';
                            a.textContent = g.name || ('#' + g.appid);
                            li.appendChild(a);
                            if (g.maxplayers) li.appendChild(
                                document.createTextNode(' (' + g.maxplayers + 'P)'));
                            if (g.multiplayer === null) li.appendChild(
                                document.createTextNode(' (?)'));
                            ul.appendChild(li);
                        });
                        results.appendChild(ul);
                    });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!cfg()) return;
        initWidget();
        initSeating();
        initButtons();
        initSharedGames();
    });
})();
