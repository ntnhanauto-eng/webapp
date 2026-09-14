// sw.js - Service Worker hỗ trợ Offline hoàn toàn cho TKB
const CACHE_NAME = 'tkb-static-v1';
const STATIC_ASSETS = [
    './',
    './index.php',
    './manifest.json',
    'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'
];

// Cài đặt và lưu cache bộ khung tĩnh
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
    self.skipWaiting();
});

// Xóa cache phiên bản cũ khi kích hoạt
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            );
        })
    );
    self.clients.claim();
});

// Chiến lược: Network-First cho API, Cache-First cho giao diện và bộ khung tĩnh
self.addEventListener('fetch', (event) => {
    const requestUrl = new URL(event.request.url);

    // Không cache file gọi API động api.php
    if (requestUrl.pathname.includes('api.php')) {
        event.respondWith(
            fetch(event.request).catch(() => new Response(JSON.stringify({ offline: true }), {
                headers: { 'Content-Type': 'application/json' }
            }))
        );
        return;
    }

    // Các tài nguyên giao diện: Ưu tiên lấy mạng trước, mất mạng thì trả về cache
    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response && response.status === 200) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            })
            .catch(() => caches.match(event.request).then((cached) => cached || caches.match('./index.php')))
    );
});
