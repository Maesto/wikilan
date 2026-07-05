/* WikiLAN push service worker: shows incoming push payloads as notifications. */
self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: event.data ? event.data.text() : 'LAN' };
    }
    var title = data.title || 'LAN';
    var opts = {
        body: data.body || '',
        data: { url: data.url || '/' },
        icon: data.icon || undefined,
        tag: data.tag || undefined
    };
    event.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                if (list[i].url === url && 'focus' in list[i]) return list[i].focus();
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});
