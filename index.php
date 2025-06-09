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

function createFriendlyFileName(array $release, array $file): string
{
    $fileName = $file['fileName'] ?? '';
    $extension = pathinfo($fileName, PATHINFO_EXTENSION);

    $releaseType = trim($release['releaseType'] ?? '');
    $languages = $release['language'] ?? [];
    $langsString = implode(',', array_filter($languages));

    $releaser = '';
    if (!empty($release['authorsInfoShort'][0]['title'])) {
        $releaser = $release['authorsInfoShort'][0]['title'];
    } elseif (!empty($release['publishersInfo'][0]['title'])) {
        $releaser = $release['publishersInfo'][0]['title'];
    }

    $year = $release['year'] ?? '';
    if ($year === '????') {
        $year = '';
    }

    $parts = [];

    $typePart = '';
    if ($releaseType !== '') {
        $typePart = $releaseType;
    }
    if ($langsString !== '') {
        if ($typePart !== '') {
            $typePart .= ' ';
        }
        $typePart .= '(' . $langsString . ')';
    }
    if ($typePart !== '') {
        $parts[] = $typePart;
    }

    $authorPart = trim($releaser . ($year ? ' ' . $year : ''));
    if ($authorPart !== '') {
        $parts[] = $authorPart;
    }

    $friendly = trim(implode(' - ', $parts));

    if ($friendly === '') {
        $friendly = pathinfo($fileName, PATHINFO_FILENAME);
    }

    if ($extension !== '') {
        $friendly .= '.' . $extension;
    }

    return $friendly;
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
            $output .= ($release['title'] ?? 'Unknown') . "^";
            $friendly = createFriendlyFileName($release, $playableFile);

            $output .= $friendly . "^";
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
        $friendlyName = createFriendlyFileName($release, $file);

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
            ->withHeader('Content-Disposition', 'attachment; filename="' . $friendlyName . '"')
            ->withHeader('Content-Length', $binaryLength);

    } catch (Exception $e) {
        $log->error("Download error: " . $e->getMessage());
        $response->getBody()->write("Error fetching file");
        return $response->withStatus(500);
    }
});

$app->run();
