<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");
set_time_limit(0);
ini_set('memory_limit', '768M');

// 한 저장소에 작업자 하나만 실행: 중복 변환 방지.
$workerLock = fopen(ROOT . '/storage/worker.lock', 'c+');
if (!$workerLock || !flock($workerLock, LOCK_EX | LOCK_NB)) exit("다른 작업자가 실행 중입니다.\n");
$once = in_array('--once', $argv, true);
logger()->info('작업자 시작', ['once'=>$once]);

// 이전 프로세스가 강제 종료된 running 작업은 재시도하지 않고 실패로 명확하게 표시합니다.
foreach (glob(ROOT . '/storage/jobs/*.json') as $path) {
    $j = readJson($path);
    if ($j['status'] === 'running') {
        $j['status']='failed'; $j['updated']=time(); $j['message']='이전 작업자가 중단되었습니다. 새 변환을 요청하세요.'; saveJob($j);
    }
}
$lastClean = 0;
do {
    if (time() - $lastClean > 3600) {
        // 하루가 지난 완료/실패 파일 및 하루 넘게 대기한 작업을 정리합니다.
        locked(ROOT . '/storage/queue.lock', function () {
            foreach (glob(ROOT . '/storage/jobs/*.json') as $path) {
                $j = readJson($path);
                if ($j['status'] !== 'running' && $j['updated'] < time() - 86400) {
                    $dir = ROOT . '/storage/output/' . $j['id'];
                    if (is_dir($dir)) {
                        foreach (glob($dir . '/*') as $f) if (is_file($f)) unlink($f);
                        rmdir($dir);
                    }
                    unlink($path);
                }
            }
        });
        $lastClean = time();
    }
    $paths = glob(ROOT . '/storage/jobs/*.json');
    usort($paths, fn($a,$b)=>filemtime($a)<=>filemtime($b));
    $job = null;
    foreach ($paths as $path) {
        if (!is_file($path)) continue;
        $j = readJson($path);
        if ($j['status']==='queued') { $job=$j; break; }
    }
    if ($job) {
        try { convertJob($job); }
        catch (Throwable $e) {
            logger()->error('변환 실패', ['job'=>$job['id'], 'ticker'=>$job['company']['ticker'],
                'reportDate'=>$job['filing']['reportDate'], 'url'=>$job['filing']['url'], 'exception'=>$e]);
            $job['status']='failed'; $job['updated']=time();
            $job['message']='변환 실패. 작업 ID로 logs/app.log를 확인하세요.';
            saveJob($job);
        }
    } elseif (!$once) { sleep(2); }
} while (!$once);
