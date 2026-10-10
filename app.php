<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
use GuzzleHttp\Client;
use League\HTMLToMarkdown\HtmlConverter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\DomCrawler\Crawler;

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
const ROOT = __DIR__;
foreach (['storage/cache', 'storage/jobs', 'storage/output', 'logs'] as $dir) {
    if (!is_dir(ROOT . '/' . $dir) && !mkdir(ROOT . '/' . $dir, 0770, true)) {
        throw new RuntimeException('저장소 디렉터리 생성 실패: ' . $dir);
    }
}
function cfg(string $key, string $default = ''): string {
    return (string)($_ENV[$key] ?? getenv($key) ?: $default);
}
function logger(): Logger {
    static $log;
    if (!$log) {
        $log = new Logger('sec10k');
        $log->pushHandler(new StreamHandler(ROOT . '/logs/app.log', Logger::INFO));
        if (PHP_SAPI === 'cli') {
            $log->pushHandler(new StreamHandler('php://stderr', Logger::INFO));
        }
    }
    return $log;
}
function readJson(string $path): array {
    return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
function writeText(string $path, string $text): void {
    // 임시 파일을 같은 디렉터리에 쓴 다음 rename: 부분 저장 상태를 읽지 않도록 합니다.
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    try {
        if (file_put_contents($tmp, $text, LOCK_EX) === false || !rename($tmp, $path)) {
            throw new RuntimeException('파일 저장 실패');
        }
        chmod($path, 0660);
    } finally {
        if (is_file($tmp)) unlink($tmp);
    }
}
function writeJson(string $path, array $value): void {
    writeText($path, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
function locked(string $path, callable $fn): mixed {
    $f = fopen($path, 'c+');
    if (!$f || !flock($f, LOCK_EX)) throw new RuntimeException('파일 잠금 실패');
    try { return $fn($f); }
    finally { flock($f, LOCK_UN); fclose($f); }
}
function cached(string $key, callable $load): array {
    $path = ROOT . '/storage/cache/' . hash('sha256', $key) . '.json';
    return locked($path . '.lock', function () use ($path, $load) {
        if (is_file($path) && filemtime($path) > time() - (int)cfg('CACHE_TTL', '86400')) {
            return readJson($path);
        }
        $value = $load();
        writeJson($path, $value);
        return $value;
    });
}
function secPace(): void {
    // 웹과 작업자가 동일 파일을 잠가, 이 프로젝트 전체 요청을 약 3.3회/초 이하로 직렬화합니다.
    locked(ROOT . '/storage/sec-rate.lock', function ($f) {
        rewind($f);
        $last = (float)stream_get_contents($f);
        $interval = max(0.2, (int)cfg('SEC_INTERVAL_MS', '300') / 1000);
        $wait = $last + $interval - microtime(true);
        if ($wait > 0) usleep((int)ceil($wait * 1000000));
        rewind($f); ftruncate($f, 0);
        fwrite($f, (string)microtime(true)); fflush($f);
    });
}
function httpGet(string $url, bool $sec = true, int $maxBytes = 12000000): string {
    $ua = cfg('SEC_USER_AGENT');
    if ($sec && (!preg_match('/\S+@\S+\.\S+/', $ua) || str_contains($ua, 'your_email'))) {
        throw new RuntimeException('.env의 SEC_USER_AGENT를 실제 이름과 연락 이메일로 변경하세요.');
    }
    $client = new Client(['connect_timeout' => 10, 'timeout' => 90,
        'allow_redirects' => false, 'http_errors' => false,
        'headers' => ['User-Agent' => $sec ? $ua : 'sec10k-converter', 'Accept-Encoding' => 'gzip']]);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($sec) secPace();
        $response = $client->get($url, ['stream' => true]);
        $code = $response->getStatusCode();
        if ($code === 429 || $code >= 500) {
            $response->getBody()->close();
            if ($attempt === 2) throw new RuntimeException("HTTP $code: 요청 재시도 한도 초과");
            $retry = $response->getHeaderLine('Retry-After');
            $pause = ctype_digit($retry) ? min(60, max(2, (int)$retry)) : 2 ** ($attempt + 1);
            logger()->warning('HTTP 재시도', ['url'=>$url, 'status'=>$code, 'wait'=>$pause]);
            sleep($pause); continue;
        }
        if ($code !== 200) {
            $response->getBody()->close();
            throw new RuntimeException("HTTP $code: SEC 차단/주소/네트워크 상태를 확인하세요.");
        }
        $length = (int)$response->getHeaderLine('Content-Length');
        if ($length > $maxBytes) throw new RuntimeException('다운로드 파일 크기 제한 초과');
        $body = $response->getBody(); $result = '';
        try {
            while (!$body->eof()) {
                $chunk = $body->read(65536);
                if ($chunk === '' && !$body->eof()) throw new RuntimeException('다운로드 스트림 중단');
                $result .= $chunk;
                if (strlen($result) > $maxBytes) throw new RuntimeException('다운로드 파일 크기 제한 초과');
            }
        } finally { $body->close(); }
        return $result;
    }
    throw new RuntimeException('다운로드 실패');
}
function fetchJson(string $url): array {
    return json_decode(httpGet($url), true, 512, JSON_THROW_ON_ERROR);
}
function symbolKey(string $s): string {
    return str_replace('.', '-', strtoupper(trim($s)));
}
function sp500(): array {
    return cached('sp500-v1', function () {
        // URL은 관리자의 설정값이며 사용자가 웹에서 지정할 수 없습니다.
        $url = cfg('SP500_CSV_URL');
        if (!str_starts_with($url, 'https://')) throw new RuntimeException('구성 종목 URL은 HTTPS여야 합니다.');
        $csv = httpGet($url, false, 2000000);
        $f = fopen('php://temp', 'w+'); fwrite($f, $csv); rewind($f);
        $header = fgetcsv($f, 0, ',', '"', '');
        if (!$header) throw new RuntimeException('구성 종목 CSV가 비어 있습니다.');
        $header[0] = ltrim((string)$header[0], "\xEF\xBB\xBF");
        $column = array_search('Symbol', $header, true);
        if ($column === false) throw new RuntimeException('CSV Symbol 열 없음: 공급원 구조 변경');
        $tickers = [];
        while (($row = fgetcsv($f, 0, ',', '"', '')) !== false) {
            if (isset($row[$column]) && trim($row[$column]) !== '') $tickers[] = symbolKey($row[$column]);
        }
        fclose($f);
        if (count($tickers) < 400) throw new RuntimeException('구성 종목 데이터가 불완전합니다.');
        return ['fetchedAt'=>gmdate('c'), 'source'=>$url, 'tickers'=>array_values(array_unique($tickers))];
    });
}
function company(string $ticker): array {
    $ticker = symbolKey($ticker);
    if (!preg_match('/^[A-Z0-9-]{1,12}$/D', $ticker)) throw new InvalidArgumentException('티커 형식이 잘못되었습니다.');
    $members = sp500();
    if (!in_array($ticker, $members['tickers'], true)) {
        throw new InvalidArgumentException('현재 사용 중인 S&P 500 구성 종목 데이터에 없는 티커입니다.');
    }
    $mapping = cached('sec-tickers-v1', fn()=>fetchJson('https://www.sec.gov/files/company_tickers.json'));
    foreach ($mapping as $row) {
        if (symbolKey((string)$row['ticker']) === $ticker) {
            return ['ticker'=>$ticker, 'cik'=>(int)$row['cik_str'], 'name'=>$row['title'],
                'membershipFetchedAt'=>$members['fetchedAt']];
        }
    }
    throw new RuntimeException('SEC 티커→CIK 데이터에서 찾지 못했습니다.');
}
function filings(string $ticker): array {
    $c = company($ticker);
    $rows = cached('filings-v2-' . $c['cik'], function () use ($c) {
        $base = 'https://data.sec.gov/submissions/';
        $data = fetchJson($base . 'CIK' . str_pad((string)$c['cik'], 10, '0', STR_PAD_LEFT) . '.json');
        $blocks = [$data['filings']['recent'] ?? []];
        // 최근 배열만 사용하면 오래된 10-K가 빠집니다. SEC가 지정한 과거 파일도 조회합니다.
        foreach ($data['filings']['files'] ?? [] as $old) {
            $name = $old['name'] ?? '';
            if (!preg_match('/^CIK\d{10}-submissions-\d+\.json$/D', $name)) {
                throw new RuntimeException('과거 제출 파일 이름 형식 변경');
            }
            $blocks[] = cached('history-' . $name, fn()=>fetchJson($base . $name));
        }
        $result = [];
        foreach ($blocks as $b) {
            foreach ($b['form'] ?? [] as $i=>$form) {
                if ($form !== '10-K') continue; // 10-K/A는 수정본이므로 별도 취급, 여기서는 제외
                $acc = (string)($b['accessionNumber'][$i] ?? '');
                $doc = (string)($b['primaryDocument'][$i] ?? '');
                if (!preg_match('/^\d{10}-\d{2}-\d{6}$/D', $acc) ||
                    !preg_match('/^[A-Za-z0-9_.-]+\.html?$/iD', $doc)) continue;
                $date = (string)($b['reportDate'][$i] ?? '');
                $result[$acc] = ['accession'=>$acc, 'form'=>$form,
                    'filingDate'=>$b['filingDate'][$i] ?? '', 'reportDate'=>$date,
                    'fiscalYear'=>$date !== '' ? substr($date, 0, 4) : '',
                    'url'=>'https://www.sec.gov/Archives/edgar/data/' . $c['cik'] . '/' .
                        str_replace('-', '', $acc) . '/' . rawurlencode($doc)];
            }
        }
        $result = array_values($result);
        usort($result, fn($a,$b)=>strcmp($b['filingDate'], $a['filingDate']));
        return ['fetchedAt'=>gmdate('c'), 'rows'=>$result];
    });
    return ['company'=>$c, 'fetchedAt'=>$rows['fetchedAt'], 'filings'=>$rows['rows']];
}
function jobPath(string $id): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new InvalidArgumentException('잘못된 작업 ID');
    return ROOT . '/storage/jobs/' . $id . '.json';
}
function loadJob(string $id): array {
    $path = jobPath($id);
    if (!is_file($path)) throw new InvalidArgumentException('작업이 없거나 보존 기간이 지났습니다.');
    return readJson($path);
}
function ownJob(string $id, string $owner): array {
    $job = loadJob($id);
    if (!hash_equals($job['owner'], $owner)) throw new InvalidArgumentException('이 세션의 작업이 아닙니다.');
    return $job;
}
function saveJob(array $job): void { writeJson(jobPath($job['id']), $job); }
function progress(array &$job, int $percent, string $message): void {
    $job['progress'] = $percent; $job['message'] = $message; $job['updated'] = time();
    saveJob($job);
    logger()->info($message, ['job'=>$job['id'], 'ticker'=>$job['company']['ticker'],
        'reportDate'=>$job['filing']['reportDate'], 'progress'=>$percent]);
}
function enqueue(string $ticker, string $url, string $owner): array {
    $listing = filings($ticker); $filing = null;
    // SSRF 방어: 임의 URL을 요청하지 않고 조회한 목록과 문자열이 정확히 일치해야 합니다.
    foreach ($listing['filings'] as $row) if (hash_equals($row['url'], $url)) $filing = $row;
    if (!$filing) throw new InvalidArgumentException('조회된 기업의 10-K HTML 주소만 허용됩니다.');
    return locked(ROOT . '/storage/queue.lock', function () use ($listing, $filing, $owner) {
        $active = 0; $mine = 0;
        foreach (glob(ROOT . '/storage/jobs/*.json') as $path) {
            $j = readJson($path);
            if (in_array($j['status'], ['queued','running'], true)) {
                $active++; if (hash_equals($j['owner'], $owner)) $mine++;
            }
        }
        if ($active >= (int)cfg('MAX_QUEUE','20') || $mine >= (int)cfg('MAX_USER_QUEUE','2')) {
            throw new RuntimeException('작업 대기 한도에 도달했습니다. 완료 후 다시 시도하세요.');
        }
        $job = ['id'=>bin2hex(random_bytes(16)), 'owner'=>$owner,
            'company'=>$listing['company'], 'filing'=>$filing,
            'status'=>'queued', 'progress'=>0, 'message'=>'작업자 실행을 기다리는 중',
            'created'=>time(), 'updated'=>time()];
        saveJob($job); return $job;
    });
}
function cleanDocument(string $html, string $source): array {
    // LIBXML_NONET: 파싱 중 외부 네트워크 접근 금지. 원문 HTML은 웹 화면에 직접 출력하지 않습니다.
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('HTML 파싱 실패');
        }
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    $crawler = new Crawler($dom);
    $bodyNodes = $crawler->filterXPath('//body');
    if ($bodyNodes->count() === 0) throw new RuntimeException('HTML body 없음');
    $inputBody = $bodyNodes->getNode(0);
    $out = new DOMDocument('1.0', 'UTF-8');
    $root = $out->createElementNS('http://www.w3.org/1999/xhtml', 'html');
    $root->setAttribute('lang', 'en'); $root->setAttribute('xml:lang','en'); $out->appendChild($root);
    $head = $out->createElementNS($root->namespaceURI, 'head'); $root->appendChild($head);
    $head->appendChild($out->createElementNS($root->namespaceURI, 'title', 'SEC 10-K'));
    $style = $out->createElementNS($root->namespaceURI, 'style');
    $style->appendChild($out->createTextNode('body{font-family:serif;line-height:1.5}table{border-collapse:collapse;width:100%;font-size:.85em}td,th{border:1px solid #888;padding:.2em;vertical-align:top}pre{white-space:pre-wrap}'));
    $head->appendChild($style);
    $body = $out->createElementNS($root->namespaceURI,'body'); $root->appendChild($body);
    $notice = $out->createElementNS($root->namespaceURI,'p');
    $notice->appendChild($out->createTextNode('Source: '));
    $link = $out->createElementNS($root->namespaceURI,'a'); $link->setAttribute('href',$source);
    $link->appendChild($out->createTextNode($source)); $notice->appendChild($link); $body->appendChild($notice);
    $allowed = array_flip(explode(' ', 'p div span h1 h2 h3 h4 h5 h6 table thead tbody tfoot tr td th caption colgroup col ul ol li dl dt dd a b strong i em u s sub sup br hr pre code blockquote'));
    $skip = array_flip(['script','style','iframe','object','embed','form','input','button','link','meta','svg','canvas','noscript','ix:header']);
    $copy = function (DOMNode $node, DOMNode $target) use (&$copy, $out, $allowed, $skip, $source, $root) {
        if ($node instanceof DOMText) {
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $node->nodeValue ?? '') ?? '';
            $target->appendChild($out->createTextNode($text)); return;
        }
        if (!$node instanceof DOMElement) return;
        $tag = strtolower($node->tagName);
        if (isset($skip[$tag])) return;
        if ($tag === 'img') {
            // 외부 이미지 다운로드를 하지 않는 텍스트 중심 버전. 대체 문구를 남깁니다.
            $target->appendChild($out->createTextNode('[image omitted: ' . ($node->getAttribute('alt') ?: 'see original filing') . ']'));
            return;
        }
        $next = $target;
        if (isset($allowed[$tag])) {
            $next = $out->createElementNS($root->namespaceURI, $tag); $target->appendChild($next);
            foreach (['rowspan','colspan','start'] as $attr) {
                if ($node->hasAttribute($attr) && preg_match('/^[1-9][0-9]{0,3}$/D',$node->getAttribute($attr))) {
                    $next->setAttribute($attr, $node->getAttribute($attr));
                }
            }
            if ($tag === 'a' && $node->hasAttribute('href')) {
                try {
                    $uri = GuzzleHttp\Psr7\UriResolver::resolve(new GuzzleHttp\Psr7\Uri($source), new GuzzleHttp\Psr7\Uri($node->getAttribute('href')));
                    if (in_array(strtolower($uri->getScheme()), ['http','https'], true)) $next->setAttribute('href',(string)$uri);
                } catch (Throwable) { /* 이상한 링크는 제외하되 텍스트는 유지합니다. */ }
            }
        }
        // inline XBRL 등의 알 수 없는 태그는 껍데기만 제거하고 안쪽 텍스트를 유지합니다.
        foreach ($node->childNodes as $child) $copy($child, $next);
    };
    foreach ($inputBody->childNodes as $node) $copy($node, $body);
    $bodyHtml = '';
    foreach ($body->childNodes as $node) $bodyHtml .= $out->saveXML($node);
    return ['body'=>$bodyHtml, 'xhtml'=>$out->saveXML()];
}
function xmlEscape(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function epub(string $path, string $xhtml, string $title, string $source, string $id): void {
    $title = xmlEscape($title); $source = xmlEscape($source); $modified = gmdate('Y-m-d\TH:i:s\Z');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('EPUB ZIP 열기 실패');
    $entries = [
        'mimetype'=>'application/epub+zip',
        'META-INF/container.xml'=>'<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="EPUB/package.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
        'EPUB/package.opf'=>"<?xml version=\"1.0\" encoding=\"UTF-8\"?><package xmlns=\"http://www.idpf.org/2007/opf\" version=\"3.0\" unique-identifier=\"book-id\"><metadata xmlns:dc=\"http://purl.org/dc/elements/1.1/\"><dc:identifier id=\"book-id\">urn:sec10k:$id</dc:identifier><dc:title>$title</dc:title><dc:language>en</dc:language><dc:source>$source</dc:source><meta property=\"dcterms:modified\">$modified</meta></metadata><manifest><item id=\"nav\" href=\"nav.xhtml\" media-type=\"application/xhtml+xml\" properties=\"nav\"/><item id=\"report\" href=\"report.xhtml\" media-type=\"application/xhtml+xml\"/></manifest><spine><itemref idref=\"report\"/></spine></package>",
        'EPUB/nav.xhtml'=>"<?xml version=\"1.0\" encoding=\"UTF-8\"?><html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\" lang=\"en\"><head><title>Contents</title></head><body><nav epub:type=\"toc\" id=\"toc\"><h1>Contents</h1><ol><li><a href=\"report.xhtml\">$title</a></li></ol></nav></body></html>",
        'EPUB/report.xhtml'=>$xhtml
    ];
    foreach ($entries as $name=>$value) {
        if (!$zip->addFromString($name,$value)) { $zip->close(); throw new RuntimeException('EPUB 항목 저장 실패'); }
        if ($name === 'mimetype' && !$zip->setCompressionName($name, ZipArchive::CM_STORE)) {
            $zip->close(); throw new RuntimeException('mimetype 비압축 설정 실패');
        }
    }
    if (!$zip->close()) throw new RuntimeException('EPUB 저장 실패');
}
function convertJob(array &$job): void {
    $job['status'] = 'running'; progress($job, 5, 'SEC HTML 다운로드');
    $raw = httpGet($job['filing']['url'], true, (int)cfg('MAX_HTML_MB','40') * 1024 * 1024);
    progress($job, 35, 'HTML 정리 및 XHTML 생성');
    $clean = cleanDocument($raw, $job['filing']['url']); unset($raw);
    progress($job, 60, 'Markdown 생성');
    // colspan/rowspan 금융표는 Markdown 표로 바꾸면 병합 구조가 손실됩니다.
    // table을 raw HTML로 유지하여 Markdown 안에 보존합니다.
    $converter = new HtmlConverter(['header_style'=>'atx', 'strip_tags'=>true,
        'hard_break'=>true, 'preserve_comments'=>false, 'remove_nodes'=>'script style']);
    // 병합 셀과 중첩 표는 raw HTML 표로 보존합니다.
    $tables = [];
    $tableDom = new DOMDocument('1.0','UTF-8');
    $prev = libxml_use_internal_errors(true);
    try { $tableDom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $clean['body'] . '</body></html>', LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($prev); }
    $nodes = iterator_to_array($tableDom->getElementsByTagName('table'));
    foreach ($nodes as $table) {
        // 안쪽 표는 바깥쪽 표가 이미 보존하므로 따로 처리하지 않습니다.
        $parent = $table->parentNode; $nested = false;
        for ($p=$parent; $p; $p=$p->parentNode) if ($p instanceof DOMElement && strtolower($p->tagName)==='table') $nested=true;
        if ($nested) continue;
        $marker = 'SEC10KTABLE' . strtoupper(bin2hex(random_bytes(12)));
        $tables[$marker] = "\n\n" . $tableDom->saveHTML($table) . "\n\n";
        $p = $tableDom->createElement('p', $marker); $parent->replaceChild($p, $table);
    }
    $markdown = $converter->convert($tableDom->saveHTML());
    $markdown = strtr($markdown, $tables);
    $title = $job['company']['ticker'] . ' 10-K ' . $job['filing']['reportDate'];
    $dir = ROOT . '/storage/output/' . $job['id'];
    if (!is_dir($dir) && !mkdir($dir, 0770)) throw new RuntimeException('출력 폴더 생성 실패');
    writeText($dir . '/report.md', '# ' . $title . "\n\n" . $markdown);
    unset($markdown, $tables, $tableDom);
    progress($job, 85, 'EPUB 패키징');
    epub($dir . '/report.epub', $clean['xhtml'], $title, $job['filing']['url'], $job['id']);
    chmod($dir . '/report.epub',0660);
    $job['status'] = 'done'; progress($job, 100, 'Markdown·EPUB 생성 완료');
}
