<?php

use GuzzleHttp\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

$log = new Logger('api-bridge');
$log->pushHandler(new StreamHandler(__DIR__ . '/app.log', Logger::DEBUG));

$client = new Client([
    'base_uri' => 'https://zxart.ee',
    'timeout' => 10.0,
]);

function transliterate(string $text): string {
    $map = [
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
        'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
        'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
        'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
        'У' => 'U', 'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch',
        'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '',  'Ы' => 'Y', 'Ь' => '',
        'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'shch', 'ъ' => '',  'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya'
    ];
    return strtr($text, $map);
}

$app->get('/', function (Request $request, Response $response, array $args) use ($log, $client) {
    $queryParams = $request->getQueryParams();
    $searchTerm = $queryParams['s'] ?? '';

    if (!$searchTerm) {
        $response->getBody()->write("Error: Missing search term");
        return $response->withStatus(400);
    }

    // Поддержка параметра p (страница), по 10 элементов на странице, дефолт 0
    $page = isset($queryParams['p']) ? (int)$queryParams['p'] : 0;
    $offset = $page * 10;

    $searchTerm = str_replace('*', ' ', $searchTerm);

    $apiUrl = "api/export:zxRelease/start:{$offset}/limit:10/order:title,desc/"
        . "filter:zxProdAjaxSearch={$searchTerm};"
        . "zxProdStatus=allowed,forbidden,allowedzxart,recovered,unknown;"
        . "zxReleaseFormat=tzx,sna,tap,trd,scl/"
        . "preset:zxdb";
    try {
        $log->info("Fetching from ZX-Art: search='{$searchTerm}' (page: {$page}, offset: {$offset})");

        $apiResponse = $client->request('GET', $apiUrl);

        $content = json_decode($apiResponse->getBody(), true);
        $releases = $content['responseData']['zxRelease'] ?? [];

        $output = "";
        foreach ($releases as $release) {
            $playableFiles = $release['playableFiles'] ?? [];
            if ($playableFiles === []) {
                continue;
            }
            $playableFile = $playableFiles[0];

            $output .= "^" . ($release['id'] ?? '0') . "^";
            $title = $release['title'] ?? 'Unknown';
            $output .= transliterate($title) . "^";
            $output .= ($playableFile['fileName'] ?? 'Unknown') . "^";
            $output .= ($playableFile['size'] ?? 0) . "^";
            $output .= count($playableFiles) . "^";
            $output .= ($release['year'] ?? '????') . "^\n";
        }

        $contentLength = strlen($output);
        $response->getBody()->write($output);
        return $response
            ->withHeader('Content-Type', '')
            ->withHeader('Content-Length', $contentLength);

    } catch (Exception $e) {
        $log->error("API error: " . $e->getMessage());
        $response->getBody()->write("Error fetching data");
        return $response->withStatus(500);
    }
});


$app->get('/get/{id}[/{option}]', function (Request $request, Response $response, array $args) use ($log, $client) {
    $releaseId = (int)$args['id'];
    $fileIndex = isset($args['option']) ? ((int)$args['option']) - 1 : 0;

    if (!$releaseId) {
        $response->getBody()->write("Error: Missing ID");
        return $response->withStatus(400);
    }

    try {
        $log->info("Fetching release by ID={$releaseId}");

        $apiUrl = "api/export:zxRelease/filter:zxReleaseId={$releaseId}/preset:zxdb";
        $apiResponse = $client->request('GET', $apiUrl);
        $content = json_decode($apiResponse->getBody(), true);
        $release = $content['responseData']['zxRelease'][0] ?? null;

        if (!$release) {
            $response->getBody()->write("Error: Release not found");
            return $response->withStatus(404);
        }

        $playableFiles = $release['playableFiles'] ?? [];

        if (empty($playableFiles) || !isset($playableFiles[$fileIndex])) {
            $response->getBody()->write("Error: File option not found");
            return $response->withStatus(404);
        }

        $file = $playableFiles[$fileIndex];
        $fileId = $file['id'];
        $fileName = $file['fileName'];

        $downloadUrl = "https://zxart.ee/zxfile/id:{$releaseId}/fileId:{$fileId}/" . urlencode($fileName);
        $log->info("Downloading file: {$downloadUrl}");

        $fileResponse = $client->request('GET', $downloadUrl, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => '*/*',
                'Connection' => 'keep-alive',
            ],
        ]);

        $binaryContent = $fileResponse->getBody()->getContents();
        $binaryLength = strlen($binaryContent);

        $response->getBody()->write($binaryContent);
        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->withHeader('Content-Length', $binaryLength);

    } catch (Exception $e) {
        $log->error("Download error: " . $e->getMessage());
        $response->getBody()->write("Error fetching file");
        return $response->withStatus(500);
    }
});

$app->run();