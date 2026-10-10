<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
ini_set('display_errors','0');
set_time_limit(300);
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax',
    'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off', 'path'=>'/sec10k/']);
session_start();
$_SESSION['owner'] ??= bin2hex(random_bytes(24));
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$owner = $_SESSION['owner']; $csrf = $_SESSION['csrf'];
session_write_close(); // 폴링이 다른 요청의 세션 잠금에 막히지 않도록 빨리 해제.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$action = (string)($_GET['action'] ?? '');
function respond(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
}
if ($action !== '') {
    try {
        if (in_array($action, ['list','create'], true)) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error'=>'POST가 필요합니다.'],405);
            if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) respond(['error'=>'CSRF 검증 실패: 화면 새로고침'],403);
        }
        switch ($action) {
            case 'list':
                respond(filings((string)($_POST['ticker'] ?? '')));
            case 'create':
                $job = enqueue((string)($_POST['ticker'] ?? ''), (string)($_POST['url'] ?? ''), $owner);
                respond(['id'=>$job['id'], 'status'=>$job['status']]);
            case 'status':
                $job = ownJob((string)($_GET['id'] ?? ''), $owner);
                respond(['id'=>$job['id'], 'status'=>$job['status'], 'progress'=>$job['progress'],
                    'message'=>$job['message']]);
            case 'download':
                $job = ownJob((string)($_GET['id'] ?? ''), $owner);
                $format = (string)($_GET['format'] ?? '');
                if ($job['status'] !== 'done' || !in_array($format,['md','epub'],true)) {
                    throw new InvalidArgumentException('완료된 파일만 다운로드할 수 있습니다.');
                }
                $file = ROOT . '/storage/output/' . $job['id'] . '/report.' . $format;
                if (!is_file($file)) throw new RuntimeException('파일이 없거나 정리되었습니다.');
                $name = $job['company']['ticker'] . '-10K-' . $job['filing']['reportDate'] . '.' . $format;
                header('Content-Type: ' . ($format==='md' ? 'text/markdown; charset=utf-8' : 'application/epub+zip'));
                header('Content-Disposition: attachment; filename="' . $name . '"');
                header('Content-Length: ' . filesize($file)); header('Cache-Control: private, no-store');
                readfile($file); exit;
            default: respond(['error'=>'알 수 없는 API'],404);
        }
    } catch (Throwable $e) {
        logger()->error('웹 요청 실패', ['action'=>$action, 'ticker'=>$_POST['ticker'] ?? null, 'exception'=>$e]);
        $safe = $e instanceof InvalidArgumentException ? $e->getMessage() : '서버 처리 오류: logs/app.log를 확인하세요.';
        respond(['error'=>$safe], $e instanceof InvalidArgumentException ? 400 : 500);
    }
}
$nonce = bin2hex(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>SEC 10-K 변환기</title>
<style nonce="<?= $nonce ?>">
body{font-family:system-ui,sans-serif;max-width:1100px;margin:30px auto;padding:0 16px;line-height:1.6}
input,button{padding:8px;margin:4px}input[type=url]{width:90%}table{border-collapse:collapse;width:100%;font-size:.9em}
th,td{border:1px solid #ddd;padding:8px;vertical-align:top}td a{overflow-wrap:anywhere}progress{width:100%;height:24px}
.note{background:#f3f5f7;padding:12px}#message{white-space:pre-wrap}button{cursor:pointer}button:disabled{cursor:wait}
</style></head><body>
<h1>SEC 10-K → Markdown · EPUB</h1>
<p class="note">현재 구성 종목 데이터 기준 S&P 500 기업만 조회합니다. 보고기간 말일과 제출일은 다릅니다.
10-K/A 수정본은 제외합니다. 이미지·원문 레이아웃은 보존되지 않습니다. 금융표는 Markdown 내부에 HTML 표로 보존합니다.</p>
<form id="lookup"><label>티커 <input id="ticker" value="AAPL" required maxlength="12"></label><button id="lookupButton">10-K 목록 조회</button></form>
<p id="company"></p><div id="listing"></div>
<form id="convert"><label>목록의 HTML 주소를 선택하거나 그대로 붙여넣으세요.<br><input type="url" id="url" required></label><br><button id="convertButton">백그라운드 변환 시작</button></form>
<progress id="progress" max="100" value="0"></progress><p id="message" role="status"></p><div id="downloads"></div>
<p>작업·파일은 약 24시간 보관됩니다. 같은 브라우저 세션에서 다운로드하세요.</p>
<script nonce="<?= $nonce ?>">
const csrf = <?= json_encode($csrf) ?>;
const $ = id=>document.getElementById(id);
let selectedTicker = '', currentJob = '', pollTimer;
async function api(action, data) {
    const options = data ? {method:'POST', body:new URLSearchParams({...data,csrf})} : {};
    const response = await fetch('?action='+action, options);
    let result;
    try { result=await response.json(); } catch { throw new Error('JSON 응답 없음: 서버/네트워크 오류'); }
    if (!response.ok) throw new Error(result.error || '요청 실패'); return result;
}
$('lookup').addEventListener('submit', async e=>{
    e.preventDefault(); $('lookupButton').disabled=true; $('message').textContent='메타데이터 조회 중. 최초 과거 목록 조회는 시간이 걸릴 수 있습니다.';
    $('listing').replaceChildren(); selectedTicker='';
    try {
        const result=await api('list',{ticker:$('ticker').value});
        selectedTicker=result.company.ticker;
        $('company').textContent=`${result.company.name} (${selectedTicker}), CIK ${result.company.cik} | 메타데이터 ${result.fetchedAt} | 구성 종목 수집 ${result.company.membershipFetchedAt}`;
        const table=document.createElement('table');
        const head=document.createElement('tr');
        for (const label of ['보고기간 말일','제출일','SEC HTML 원문','선택']) { const th=document.createElement('th'); th.textContent=label; head.append(th); }
        table.append(head);
        for (const row of result.filings) {
            const tr=document.createElement('tr');
            for (const key of ['reportDate','filingDate']) {const td=document.createElement('td');td.textContent=row[key];tr.append(td);}
            const td=document.createElement('td'), a=document.createElement('a');
            a.href=row.url;a.textContent=row.url;a.target='_blank';a.rel='noopener noreferrer';td.append(a);tr.append(td);
            const cell=document.createElement('td'), b=document.createElement('button');b.type='button';b.textContent='선택';b.onclick=()=>{$('url').value=row.url;};cell.append(b);tr.append(cell);table.append(tr);
        }
        $('listing').append(table);$('message').textContent=`10-K ${result.filings.length}건 조회 완료`;
    } catch (e) {$('message').textContent=e.message;} finally {$('lookupButton').disabled=false;}
});
function pollLater(){pollTimer=setTimeout(poll,2000);}
async function poll(){
    try{
        const r=await api('status&id='+encodeURIComponent(currentJob));$('progress').value=r.progress;$('message').textContent=`${r.status}: ${r.message}\n작업 ID: ${r.id}`;
        if(r.status==='done'){
            for(const format of ['md','epub']){const a=document.createElement('a');a.href=`?action=download&id=${encodeURIComponent(r.id)}&format=${format}`;a.textContent=format.toUpperCase()+' 다운로드';$('downloads').append(a,document.createTextNode('   '));}
            $('convertButton').disabled=false;sessionStorage.removeItem('sec10k-job');
        }else if(r.status==='failed'){$('convertButton').disabled=false;sessionStorage.removeItem('sec10k-job');}
        else pollLater();
    }catch(e){$('message').textContent=e.message+' — 5초 후 상태 조회 재시도';pollTimer=setTimeout(poll,5000);}
}
$('convert').addEventListener('submit',async e=>{
    e.preventDefault();if(!selectedTicker){$('message').textContent='먼저 티커 목록을 조회하세요.';return;}
    $('convertButton').disabled=true;$('downloads').replaceChildren();$('progress').value=0;
    try{const r=await api('create',{ticker:selectedTicker,url:$('url').value});currentJob=r.id;sessionStorage.setItem('sec10k-job',r.id);clearTimeout(pollTimer);poll();}
    catch(e){$('message').textContent=e.message;$('convertButton').disabled=false;}
});
const saved=sessionStorage.getItem('sec10k-job');
if(saved){currentJob=saved;$('convertButton').disabled=true;poll();}
</script></body></html>
