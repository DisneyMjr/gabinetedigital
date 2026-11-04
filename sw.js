importScripts("https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js");
const CACHE_NAME = 'gabinete-digital-v2';

self.addEventListener('install', event => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.filter(cacheName => {
                    return cacheName !== CACHE_NAME;
                }).map(cacheName => {
                    return caches.delete(cacheName);
                })
            );
        })
    );
    self.clients.claim();
});

// A MÁGICA ANTI-ERRO DO SAFARI E ONESIGNAL FICA AQUI
self.addEventListener('fetch', event => {
    // Ignora requisições que não sejam GET e ignora TUDO que for do OneSignal
    if (event.request.method !== 'GET' || event.request.url.includes('onesignal.com')) {
        return;
    }

    event.respondWith(
        fetch(event.request).then(response => {
            // Se a rede retornar uma resposta válida, nós a usamos
            return response;
        }).catch(error => {
            // Se a internet falhar (ou der erro no Safari), tenta buscar do cache
            return caches.match(event.request).then(cacheResponse => {
                if (cacheResponse) {
                    return cacheResponse;
                }
                
                // O SEGREDO: Se não achar nada, NUNCA retorna 'null'. 
                // Retorna uma página de erro genérica embutida para não travar o Safari.
                return new Response(
                    '<html><head><meta charset="UTF-8"><title>Sem Conexão</title></head><body style="font-family:sans-serif;text-align:center;padding:50px;color:#333;"><h2>Sem conexão com a internet</h2><p>Verifique o seu Wi-Fi ou 4G e recarregue a página.</p></body></html>', 
                    {
                        status: 503,
                        statusText: 'Service Unavailable',
                        headers: new Headers({ 'Content-Type': 'text/html' })
                    }
                );
            });
        })
    );
});