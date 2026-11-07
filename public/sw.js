// ReviewPilot service worker: shows push notifications and opens the review on tap.
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = {
            title: 'ReviewPilot',
            body: event.data ? event.data.text() : '',
        };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'ReviewPilot', {
            body: data.body || '',
            icon: '/logo-512.png',
            badge: '/logo-120.png',
            tag: data.tag || undefined,
            renotify: Boolean(data.tag),
            data: { url: data.url || '/approvals' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(
        event.notification.data?.url || '/approvals',
        self.location.origin,
    ).href;

    event.waitUntil(
        clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((windows) => {
                for (const w of windows) {
                    if (w.url === url && 'focus' in w) {
                        return w.focus();
                    }
                }

                return clients.openWindow(url);
            }),
    );
});
