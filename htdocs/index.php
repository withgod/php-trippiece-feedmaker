<?php

require dirname(__FILE__) . '/../vendor/autoload.php';

$app = new \Slim\Slim();

$app->get('/rss/:token/:keyword', function ($token, $keyword) use ($app) {
    $expire    = 3600;
    $adapter   = new \Desarrolla2\Cache\Adapter\File(sys_get_temp_dir());
    $cache     = new \Desarrolla2\Cache\Cache($adapter);
    $cache_key = 'tprss';
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
            $atom = new \FeedWriter\ATOM();
            $atom->setTitle('trippiece search result [' . $keyword . ']');
            $atom->setLink('https://trippiece.com/');
            $atom->setDate(new DateTime());
            foreach ($json->results as $result) {
                $item = $atom->createNewItem();
                $item->setTitle($result->title);
                $item->setLink('https://trippiece.com/plans/' . $result->id . '/');
                $item->setDate($result->updated);
                $item->setAuthor($result->producers[0]->user->full_name);
                $item->setDescription($result->why);
                //$item->setContent($result->notes[0]->text);
                //$item->Enclosure();
                $atom->addItem($item);
            }
            $contents = $atom->generateFeed();
            $cache->set($cache_key, $contents, $expire);
        } else {
            $app->halt(503, 'Service Temporarily Unavailable');
        }
    }
    $app->response->headers->set('Content-type', 'application/atom+xml');
    echo $contents;
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
