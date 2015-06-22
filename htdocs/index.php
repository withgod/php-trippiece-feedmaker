<?php

require dirname(__FILE__) . '/../vendor/autoload.php';

$app = new \Slim\Slim();

$app->get('/rss/:token/:keyword(/:purge)', function ($token, $keyword, $purge = 0) use ($app) {
    $expire    = 3600;
    $adapter   = new \Desarrolla2\Cache\Adapter\File(sys_get_temp_dir());
    $cache     = new \Desarrolla2\Cache\Cache($adapter);
    $cache_key = 'tprss_' . sha1($keyword);
    if (!empty($purge)) {
        $cache->delete($cache_key);
    }
    $contents  = $cache->get($cache_key);

    $default = [
        'action'     => 'search',
        'content'    => 'plan',
        'finished'   => 'False',
        'is_visible' => 'True',
        'page'       => 1,
        'page_size'  => 20,
        'reset'      => 'true',
        'state'      => 'None',
    ];
    $search = [ 'q' => $keyword, 'search' => $keyword];

    if (empty($contents)) {
        $c = new \GuzzleHttp\Client();
        $r = $c->get('https://api.trippiece.com/plans/', [
            'headers' => [
                'User-Agent'    => 'trippiece feedmaker(contact @withgod)',
                'Authorization' => "Token $token",
            ],
            'query'  => array_merge($default, $search)
        ]);

        if ($r->getStatusCode() == 200) {
            $body = (string)$r->getBody();
            $json = json_decode($body);

            date_default_timezone_set('UTC');
            $rss  = new \PicoFeed\Syndication\Rss20();
            $rss->title = 'trippiece search result [' . $keyword . ']';
            $rss->site_url = 'https://trippiece.com/';
            $rss->feed_url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $rss->author = ['name' => 'withgod', 'url' => 'http://withgod.hatenablog.com/', 'email' => 'noname@withgod.jp'];
            foreach ($json->results as $result) {
                #2015-06-21T12:17:31
                //var_dump(DateTime::createFromFormat('y-m-dTH:i:sZ', $result->updated, DateTimeZone::UTC));
                $utc = new DateTimeZone('UTC');
                $rss->items[] = [
                    'title'     => $result->title,
                    'updated'   => DateTime::createFromFormat('y-m-dTH:i:sZ', $result->updated, $utc),
                    'published' => DateTime::createFromFormat('y-m-dTH:i:sZ', $result->created, $utc),
                    'url'       => 'https://trippiece.com/plans/' . $result->id . '/',
                    'author'    => [ 'name' => $result->producers[0]->user->full_name ],
                    'summary'   => $result->why,
                    //'content'   => $result->notes[0]->text,
                    ];
            }
            $contents = $rss->execute();
            $cache->set($cache_key, $contents, $expire);
        } else {
            $app->halt(503, 'Service Temporarily Unavailable');
        }
    }
    $app->response->headers->set('Content-type', 'application/rss+xml');
    //convert DATE_RFC822 to DATE_RFC2822
    $replaced = preg_replace_callback(
        '|<pubDate>([^<]+)|',
        function ($matches) {
            $date = $matches[1];
            $arr  = explode(' ', $date);

            return '<pubDate>' . $arr[0] . ' ' . $arr[1] . ' ' . $arr[2] . ' 20' . $arr[3] . ' ' . $arr[4] . ' ' . $arr[5];
        },
        $contents
    );
    echo $replaced;
});
$app->get('/', function () {
    print <<< EOT
        <html>
        <body>
        <a href="/rss/:authtoken/:keyword">/rss/:authtoken/:keyword</a>
        </body>
        </html>
EOT;
});

$app->run();
