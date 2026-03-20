<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=300');

// Feed RSS ufficiali e istituzionali
$feeds = [
    [
        'nome'   => 'ANSA Politica',
        'url'    => 'https://www.ansa.it/sito/notizie/politica/politica_rss.xml',
        'fonte'  => 'ansa',
    ],
    [
        'nome'   => 'Governo Italiano – Comunicati',
        'url'    => 'https://www.governo.it/it/media/comunicati-stampa/feed',
        'fonte'  => 'governo',
    ],
];

/**
 * Recupera e fa il parsing di un feed RSS.
 * Restituisce un array di notizie o lancia un'eccezione.
 */
function fetchFeed(string $url, string $fonte): array {
    $context = stream_context_create([
        'http' => [
            'timeout'     => 8,
            'user_agent'  => 'DashboardPolitica/1.0 (+https://ministero.gov.it)',
            'header'      => "Accept: application/rss+xml, application/xml, text/xml\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $xml_raw = @file_get_contents($url, false, $context);

    if ($xml_raw === false) {
        throw new RuntimeException("Feed non raggiungibile: $url");
    }

    // Silenzia warning di SimpleXML e gestisci l'errore manualmente
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_raw);

    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        throw new RuntimeException("XML non valido per: $url — " . ($errors[0]->message ?? 'errore sconosciuto'));
    }

    $notizie = [];

    foreach ($xml->channel->item as $item) {
        // Data: prova prima pubDate, poi dc:date
        $data_raw = (string)($item->pubDate ?? $item->children('dc', true)->date ?? '');
        $timestamp = $data_raw ? strtotime($data_raw) : null;

        $notizie[] = [
            'titolo'      => html_entity_decode((string)$item->title,       ENT_QUOTES, 'UTF-8'),
            'descrizione' => html_entity_decode(strip_tags((string)$item->description), ENT_QUOTES, 'UTF-8'),
            'link'        => (string)$item->link,
            'data_iso'    => $timestamp ? date('c', $timestamp) : null,
            'data_human'  => $timestamp ? strftime('%d %B %Y', $timestamp) : 'Data non disponibile',
            'fonte'       => $fonte,
        ];

        // Limita a 15 articoli per feed
        if (count($notizie) >= 15) break;
    }

    return $notizie;
}

// ── Raccolta risultati ────────────────────────────────────────────────────────

$risposta = [
    'generato_il' => date('c'),
    'feeds'       => [],
    'errori'      => [],
];

foreach ($feeds as $feed) {
    try {
        $articoli = fetchFeed($feed['url'], $feed['fonte']);
        $risposta['feeds'][] = [
            'nome'      => $feed['nome'],
            'fonte'     => $feed['fonte'],
            'url'       => $feed['url'],
            'articoli'  => $articoli,
            'conteggio' => count($articoli),
        ];
    } catch (RuntimeException $e) {
        $risposta['errori'][] = [
            'feed'      => $feed['nome'],
            'fonte'     => $feed['fonte'],
            'messaggio' => 'Servizio momentaneamente non disponibile',
            'dettaglio' => $e->getMessage(), // rimuovere in produzione
        ];
    }
}

// Se tutti i feed falliscono, restituisci HTTP 503
if (empty($risposta['feeds']) && !empty($risposta['errori'])) {
    http_response_code(503);
    $risposta['stato'] = 'errore_critico';
    $risposta['messaggio_utente'] = 'Tutti i servizi di notizie sono momentaneamente non disponibili. Riprovare tra qualche minuto.';
} else {
    $risposta['stato'] = empty($risposta['errori']) ? 'ok' : 'parziale';
}

echo json_encode($risposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
