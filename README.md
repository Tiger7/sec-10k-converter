# SEC 10-K → Markdown / EPUB: 전체 설치 안내

## 0. 무엇을 설치하는가

초보자를 위한 DB 없는 출발점입니다. PHP 소스는 app.php, public/index.php, worker.php 세 파일입니다.

- 호스트 Apache의 /var/www/html은 사용자가 그대로 사용한다고 간주합니다.
- 변환기는 /opt/sec-10k-converter/public/만 /sec10k/ 주소로 공개합니다.
- .env, vendor, logs, storage는 웹 DocumentRoot 밖에 있습니다.
- Docker는 호스트와 별도 Apache를 127.0.0.1:8080에서 실행합니다.
- 웹 요청은 작업 JSON만 등록하고 worker.php가 변환합니다.
- AJAX는 상태를 2초 간격으로 읽습니다. 5/35/60/85/100%는 단계 표시이며 실제 시간 추정치가 아닙니다.

S&P 구성 종목은 개발용 공개 CSV로 확인하며 SEC 공식 지수 데이터가 아닙니다.
CSV 수집시각은 데이터 공급원의 실제 갱신시각이 아닙니다. 수집했다고 최신 구성 종목임이 보장되지 않습니다.
현재 구성 종목에 대해 과거 10-K를 조회하는 서비스입니다. 해당 과거 연도의 S&P 편입 여부를 복원하지 않습니다.

10-K만 포함하며 10-K/A, 20-F, 40-F, 기타 연차보고서 형식은 제외합니다.
reportDate(보고기간 말일)와 filingDate(제출일)를 구분합니다. fiscalYear는 reportDate의 연도 문자열이지 기업 고유 회계연도 명칭을 엄밀하게 판정한 값이 아닙니다.
메타데이터에서 primaryDocument가 .htm/.html인 자료만 보여줍니다. 오래된 텍스트 제출은 포함되지 않을 수 있습니다.

## 1. Ubuntu 기본 도구

터미널을 열고 아래 명령을 한 줄씩 실행합니다. sudo는 관리자 권한이며 암호 입력 때 글자가 보이지 않아도 정상입니다.
우선 Ubuntu 24.04 LTS의 PHP 8.3 환경을 권장하는 예제입니다. 다른 Ubuntu 버전이면 기본 PHP 버전을 확인하세요.

```bash
cat /etc/os-release
sudo apt update
sudo apt upgrade -y
sudo apt install -y ca-certificates curl unzip zip git nano
```

## 2. 호스트 Apache와 PHP

```bash
sudo apt install -y apache2 php libapache2-mod-php php-cli php-curl php-mbstring php-xml php-zip
sudo systemctl enable --now apache2
php -v
php -m
sudo apache2ctl configtest
```

php-xml 패키지는 DOM 등 XML 관련 모듈을 제공합니다. Composer에 필요한 PHP는 8.3 이상, 9.0 미만입니다.
`php -v`가 8.1/8.2라면 그대로 다음 단계로 진행하지 마세요. Ubuntu 업그레이드 또는 아래 Docker 환경을 사용하세요.
호스트 PHP와 CLI PHP가 같은 버전인지 확인해야 합니다. 여러 PHP 버전이 설치되어 있다면 Apache가 활성화한 모듈도 확인합니다.

```bash
apache2ctl -M | grep php
php -r 'foreach(["curl","dom","mbstring","zip","xml"] as $e){echo $e,": ",extension_loaded($e)?"OK":"MISSING",PHP_EOL;}'
curl -I http://127.0.0.1/
```

/var/www/html/index.html을 삭제하거나 이 프로젝트 파일로 덮어쓰지 않습니다.
아직 구동중인 웹페이지가 없다면 / 주소(80port)에는 Ubuntu Apache 기본 화면이 나올 수 있습니다.

## 3. Composer 전역 설치

간단하게 Ubuntu 패키지를 사용합니다. 외부 설치 스크립트를 검증 없이 curl | php로 실행하지 않습니다.

```bash
sudo apt install -y composer
composer --version
```

Composer 2.x인지 확인합니다. 프로젝트 의존성 설치에 sudo composer install을 사용하지 마세요.

## 4. 소스 압축 해제 및 프로젝트 배치

다운로드한 ZIP의 실제 이름/위치는 본인의 파일에 맞게 바꾸세요. 압축 안에는 sec-10k-converter/ 폴더가 있습니다.

```bash
mkdir -p ~/projects
cd ~/projects
unzip ~/Downloads/sec-10k-converter.zip
sudo mkdir -p /opt/sec-10k-converter
sudo cp -a ~/projects/sec-10k-converter/. /opt/sec-10k-converter/
sudo chown -R "$USER":www-data /opt/sec-10k-converter
cd /opt/sec-10k-converter
chmod 750 /opt/sec-10k-converter
find . -type d -not -path './.git*' -exec chmod 750 {} \;
find . -type f -not -path './.git*' -exec chmod 640 {} \;
```

폴더에는 경로를 탐색할 실행 권한이 필요합니다. PHP 파일 자체에는 실행 권한이 필요하지 않습니다.

composer.json은 필수 PHP 확장과 라이브러리를 정의합니다.

```bash
composer validate --strict
composer install --no-dev --prefer-dist
composer check-platform-reqs
composer audit
```

첫 설치는 composer.lock을 생성합니다. 이 파일을 Git에 올려야 다른 컴퓨터에서 같은 라이브러리 버전으로 설치됩니다.
composer.json 버전 범위만으로는 완전한 재현성이 보장되지 않습니다.
빌드 이미지 태그 php:8.3-apache 및 composer:2도 가변 태그입니다. 엄밀한 운영 배포에서는 검증한 이미지 digest까지 고정하세요.

## 5. 환경 변수, 로그, 캐시 권한

```bash
cp .env.example .env
nano .env
```

다음 값을 반드시 본인의 실제 연락처로 변경합니다.

```dotenv
SEC_USER_AGENT="MySecReader 실제연락이메일@example.com"
```

이메일은 API 키가 아닙니다. SEC가 자동화 요청의 운영자를 식별/연락할 수 있게 하는 값입니다.
영문 앱/조직 이름과 실제 이메일 사용을 권장합니다. example.com 예제 주소를 그대로 쓰지 마세요.

```bash
mkdir -p storage/cache storage/jobs storage/output logs
sudo chown -R "$USER":www-data storage logs
find storage logs -type d -exec chmod 2770 {} \;
find storage logs -type f -exec chmod 660 {} \;
chmod 640 .env
sudo -u www-data test -r .env && echo 'env readable'
sudo -u www-data test -w storage/jobs && echo 'queue writable'
sudo -u www-data test -w logs && echo 'logs writable'
```

2770의 앞자리 2는 새 파일/폴더가 폴더의 그룹을 이어받도록 하는 setgid입니다.
777로 해결하지 않습니다. 코드와 .env는 작업자가 읽기만 하고 저장소/로그만 쓰게 합니다.
캐시는 기본 24시간이며, 만료된 파일은 해당 키에 다시 접근할 때 갱신됩니다.
캐시가 오래되면 그대로 사용하지 않고 재조회 오류를 반환합니다. 구성 종목 데이터를 조용히 최신인 것처럼 표시하지 않습니다.

## 6. 기존 웹서버와 공존하는 Apache 설정

```bash
sudo cp deploy/sec10k.conf /etc/apache2/conf-available/sec10k.conf
sudo a2enconf sec10k
sudo apache2ctl configtest
sudo systemctl reload apache2
curl -I http://127.0.0.1/sec10k/
```

접속 주소는 http://서버IP/sec10k/ 입니다. 끝의 /를 포함하세요.
URL /sec10k/만 public/에 매핑합니다. 기존 웹서버의의 / 주소와 index.php는 바뀌지 않습니다.
프로젝트 전체를 /var/www/html/sec10k에 넣지 않는 이유는 .env/로그/캐시 공개 위험을 피하기 위해서입니다.
기존 웹서버의 가상호스트가 별도 제한/재작성 규칙을 가진 경우 실제 사이트 설정을 검토하세요.

처음에는 동일 컴퓨터 또는 신뢰하는 LAN에서만 사용하세요. UFW를 이미 사용한다면 LAN 대역만 허용할 수 있습니다.
SSH 원격 설치 중이라면 SSH 허용 없이 ufw enable을 실행하지 마세요.

```bash
# 예시: 자신의 LAN 대역으로 반드시 바꿉니다. 자동 실행하지 마세요.
sudo ufw allow from 192.168.0.0/24 to any port 80 proto tcp
```

인터넷 공개에는 로그인/HTTPS/요청 제한/디스크 모니터링을 먼저 추가합니다.
이 예제의 세션은 파일 소유권 확인이지 사용자 인증이 아닙니다.

## 7. 백그라운드 작업자

처음에는 터미널에서 실행합니다. 작업자가 없으면 queued 상태에서 멈춥니다.

```bash
cd /opt/sec-10k-converter
sudo -u www-data php worker.php
```

별도 브라우저에서 티커를 조회하고 변환을 요청하세요. 터미널에는 각 단계 로그가 나오며 같은 로그가 logs/app.log에도 기록됩니다.
Ctrl+C는 작업자를 중지합니다. 다음 시작 때 이전 running 작업은 실패로 표시됩니다.

상시 실행으로 전환하려면 수동 작업자를 먼저 Ctrl+C로 종료하고:

```bash
sudo cp deploy/sec10k-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sec10k-worker
sudo systemctl status sec10k-worker --no-pager
sudo journalctl -u sec10k-worker -f
```

이 서비스는 서버 재부팅 후 자동 시작하고 실패하면 재시작합니다.
CLI PHP 메모리 한도는 768M입니다. worker.php는 실행시간 제한을 끄지만, 다운로드에는 90초 timeout이 있습니다.
set_time_limit(300)은 300초로 설정하는 것이지 제한을 해제하는 명령이 아닙니다. 해제는 0입니다.
웹 프록시 제한과 PHP 제한은 서로 다르며, AJAX만 붙인다고 서버 작업이 자동 백그라운드가 되지 않습니다.
여기서는 실제로 별도 CLI 프로세스가 파일 큐를 처리합니다.

## 8. 동작 흐름

1. 티커 정규화(BRK.B와 BRK-B를 같은 키로 처리).
2. 공개 CSV에서 현재 S&P 500 구성 종목 여부 확인.
3. SEC company_tickers.json에서 CIK 확인.
4. data.sec.gov/submissions/CIK0000000000.json 조회.
5. filings.recent 및 filings.files에 명시된 과거 JSON까지 확인.
6. form이 정확히 10-K이고 primaryDocument가 HTML인 제출을 모음.
7. CIK/접수번호/primaryDocument로 SEC Archives 원문 주소 생성.
8. 사용자가 선택한 URL이 서버에서 조회한 해당 기업 목록에 정확히 있는지 확인.
9. 랜덤 ID로 작업 JSON 저장. 큐 한도 검사는 queue.lock 잠금 안에서 수행.
10. 작업자가 HTML 수집 → HTML 정리 → Markdown → EPUB → done 상태 저장.
11. 웹 화면은 progress 상태를 읽고 같은 세션의 사용자에게만 파일 전달.

등록된 URL 이외의 URL은 허용하지 않습니다. localhost, 다른 사이트, ixviewer 주소 등도 거절됩니다.
자동 리디렉션도 금지합니다. SEC 주소가 이동하여 3xx가 나오면 관리자 확인 후 코드를 수정해야 합니다.

HTTP 요청은 Guzzle, HTML body 선택은 DomCrawler, Markdown은 League, .env는 phpdotenv, 로그는 Monolog가 담당합니다.
EPUB은 ZipArchive로 EPUB 3 컨테이너를 작성합니다. 추가 EPUB 패키지/Node/Python 서버는 필요하지 않습니다.

## 9. 표/수식/이미지 범위

이것은 텍스트 중심 변환입니다. 원본 보고서를 완벽하게 재현하지 않습니다.

- 표는 Markdown의 파이프 표로 강제 변환하지 않고 raw HTML <table>을 보존합니다. colspan/rowspan, 중첩 표 손실을 줄이기 위한 선택입니다.
- raw HTML이 금지된 Markdown 뷰어에서는 표가 표시되지 않거나 다르게 보일 수 있습니다.
- EPUB에는 표를 XHTML로 보존하지만 전자책 화면이 작으면 넓은 재무표가 읽기 어려울 수 있습니다.
- XHTML은 원문의 태그를 그대로 저장하지 않고 허용 태그/속성만 새 DOM에 복사합니다. script, iframe, event handler 등을 제거합니다.
- inline XBRL은 주로 껍데기를 벗겨 가시적인 텍스트를 남깁니다. hidden metadata인 ix:header는 제거합니다.
- 이미지/SVG/차트는 포함하지 않습니다. img는 생략 문구를 남깁니다.
- MathML의 구조나 이미지 수식을 보존하지 않습니다. 일반 텍스트와 sub/sup는 최대한 남지만 Markdown 렌더링 차이가 있습니다.
- SEC의 원문 CSS를 제거하므로 일부 CSS 숨김 요소/페이지 반복 머리말이 텍스트에 나타날 수 있습니다.
- 본문은 한 개 XHTML chapter로 저장하고 목차는 한 항목입니다. Item 1/1A/7을 분할하는 정교한 자동 목차는 구현하지 않았습니다.
- EPUB 파일 내부 내용은 영어입니다. 한국어 번역 기능은 없습니다.

원문 링크를 제공하고 투자 판단 전에 표의 금액/단위/주석을 원문과 비교하세요.

## 10. Docker 설치 (호스트 Apache와 별도)

공식 Docker 저장소 방식입니다. 기존 docker.io/containerd/runc 등 충돌 패키지가 설치되어 있으면 Docker 공식 안내에 따라 먼저 정리합니다.
신규 Ubuntu에서 진행하는 명령입니다.

```bash
sudo apt update
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
. /etc/os-release
sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: ${UBUNTU_CODENAME:-$VERSION_CODENAME}
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker version
sudo docker compose version
```

보안을 위해 docker 그룹에 사용자 계정을 추가하는 설명은 생략하고 sudo docker를 사용합니다.

### 실행

호스트 worker를 이미 실행했다면 먼저 중지합니다. 동일 storage에 호스트/Docker 작업자를 동시에 돌리지 않습니다.
컨테이너 www-data는 UID/GID 33을 가정합니다. 일반 Ubuntu에서도 같은지 확인하세요.

```bash
id www-data
sudo docker run --rm php:8.3-apache id www-data
cd /opt/sec-10k-converter
sudo systemctl stop sec10k-worker  # 설치했을 때만 실행
sudo chown -R 33:33 storage logs
sudo docker compose config
sudo docker compose up -d --build
sudo docker compose ps
sudo docker compose logs -f worker
```

접속 주소는 http://127.0.0.1:8080/sec10k/ 입니다. 호스트의 80번 포트와 충돌하지 않습니다.
외부 컴퓨터에서 이 주소의 127.0.0.1은 외부 컴퓨터 자신이므로 접속되지 않습니다.
LAN 접근만 필요할 때는 compose ports의 127.0.0.1을 서버의 실제 LAN IP로 바꾼 후 up -d를 실행하세요.
예: "192.168.0.10:8080:80". 라우터 포트포워딩을 열지 않습니다.
Docker 포트는 UFW 규칙을 우회할 수 있습니다. IP 바인딩만으로 인터넷 운영 보안이 완성되지 않습니다.

더 나은 외부 공개 방식은 Docker 포트를 127.0.0.1에 유지하고 호스트 Apache에서 인증/HTTPS를 제공하는 리버스 프록시를 구성하는 것입니다.
같은 /sec10k/ 경로에는 Alias와 ProxyPass를 동시에 적용하지 마세요. 먼저 호스트 Alias 설정을 비활성화해야 합니다.
TLS 프록시 뒤에서 secure cookie를 적용하려면 신뢰할 프록시와 서버 HTTPS 인식 설정을 별도로 검토해야 합니다.

이미지에는 앱 코드/vendor가 들어가고 storage/logs만 bind mount합니다. 코드 변경 때 다시 빌드합니다.
.env는 .dockerignore로 이미지에 포함하지 않으며 Compose env_file로 런타임에 전달합니다.

```bash
sudo docker compose exec web php -m
sudo docker compose exec web composer check-platform-reqs
sudo docker compose exec web composer audit
sudo docker compose down
```

down은 호스트 storage/logs를 삭제하지 않습니다.

### 호스트 PHP를 사용하지 않고 Docker만으로 최초 Composer 설치

PHP 버전이 낮아 호스트 composer install이 불가능한 경우에도 Docker에서 lock을 생성할 수 있습니다.
아래 명령은 source bind mount를 지정한 일회성 Composer 실행입니다. 운영 컨테이너에는 source bind mount를 하지 않습니다.

```bash
sudo docker compose build web
sudo docker compose run --rm --no-deps --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD:/opt/sec-10k-converter" web composer install --no-dev --prefer-dist
sudo docker compose build --no-cache
sudo docker compose up -d
```

생성된 composer.lock을 Git에 커밋한 다음 두 번째 빌드가 lock 기반인지 로그를 확인하세요.

## 11. 검증: 생략하지 마세요

제공 코드에 대해 이 환경에서는 PHP/Composer/Docker/SEC 실서버 실행을 수행하여 잘 작동하는 것을 확인했습니다.
PHP lint, 설치, HTTP 통신, EPUBCheck 검증을 아래 실제 머신에서 수행하세요.

```bash
cd /opt/sec-10k-converter
php -l app.php
php -l worker.php
php -l public/index.php
composer validate --strict
composer check-platform-reqs
composer audit
sudo apache2ctl configtest
```

Docker 환경만 사용한다면 php/composer 앞에 sudo docker compose exec web을 붙입니다.

브라우저 점검:

- AAPL을 조회하여 회사명, CIK, 보고기간 말일, 제출일, 원문 주소 확인.
- SEC 원문 주소를 직접 열어 목록과 같은 제출인지 비교.
- 오래된 제출도 보이는지 확인(과거 JSON 첫 수집은 느릴 수 있음).
- 목록에서 선택하여 변환, queued→running→done, 두 형식 다운로드 확인.
- EPUB reader에서 열기. 작은 ZIP이라고 실제 호환성이 보장되지 않으므로 EPUBCheck로 검증.
- Markdown raw HTML 표의 숫자/병합 셀을 원문과 대조.
- 다른 브라우저/시크릿 창에서 작업 ID를 사용해도 다운로드가 거절되는지 확인.
- URL에 http://127.0.0.1/ 또는 임의 외부 사이트를 넣으면 거절되는지 확인.
- worker를 중지하면 queued로 남고, 재시작하면 처리되는지 확인.
- worker 강제 중단 시 다음 시작에서 running 작업이 failed로 바뀌는지 확인.
- /sec10k/.env, /sec10k/storage/jobs/, /sec10k/vendor/가 파일을 노출하지 않는지 확인.
- 기존웹서버의 / 화면이 정상인지 확인.

EPUB 컨테이너를 확인할 때는 다운로드한 실제 파일 경로로 바꾸세요.

```bash
unzip -l ~/Downloads/AAPL-10K-실제날짜.epub
unzip -p ~/Downloads/AAPL-10K-실제날짜.epub mimetype
```

첫 항목 mimetype, 내용 application/epub+zip, 비압축 여부까지 확인하세요.
EPUBCheck를 별도 설치했다면 해당 실행 파일 사용법에 따라 검사하세요. 이 소스는 EPUBCheck를 자동 실행하지 않습니다.

## 12. 로그, 오류, 운영 제약

```bash
tail -f /opt/sec-10k-converter/logs/app.log
sudo journalctl -u sec10k-worker -n 100 --no-pager
sudo tail -n 100 /var/log/apache2/error.log
sudo docker compose logs --tail=100 worker web
```

- queued 지속: worker가 없는지, storage 권한/같은 volume인지 확인.
- HTTP 403: User-Agent 실제 연락처, SEC 차단, 공유 IP의 다른 크롤러 확인. 공격적으로 재시도하지 않습니다.
- HTTP 429/5xx: 최대 3회, backoff. Retry-After 숫자는 최대 60초까지만 대기.
- 파일 쓰기 실패: storage/logs 소유권 확인. 777을 쓰지 않습니다.
- 메모리 부족: HTML 크기 제한을 낮추거나 메모리를 늘림. PHP fatal/OOM은 catch로 잡히지 않을 수 있으며 프로세스 재시작 때 작업이 failed로 바뀝니다.
- 리스트 첫 조회가 오래 걸림: 과거 submissions files 전체를 읽기 때문. 변환은 백그라운드지만 메타데이터 조회 자체는 동기 처리이며 프록시 timeout에 걸릴 수 있습니다. 운영 확장 시 메타데이터 수집도 작업 큐로 분리하세요.
- 표가 안 보임: Markdown 뷰어가 raw HTML을 허용하는지 확인하고 EPUB/SEC 원문과 비교.
- 출력/작업은 작업자 실행 중 주기적으로 정리되며 약 하루 후 없어집니다. 캐시와 app.log 전체 보관 용량을 자동 제한하지는 않습니다.

중요 운영 한계:
- 전역 rate lock은 이 storage를 공유하는 프로세스들에만 적용됩니다. 같은 IP의 다른 앱/머신 요청까지 합산하지 않습니다.
- SEC 정책은 여러 머신을 쓰더라도 사용자의 총 요청이 한도를 넘지 않아야 합니다.
- 20개 전체 큐/세션당 2개 한도는 queue.lock으로 경쟁 조건을 줄입니다. 그러나 새 세션을 계속 만드는 공격을 막는 로그인/사용자별 quota는 없습니다.
- HTTP body는 chunk로 받지만 최종 파싱은 DOM 메모리에 적재합니다. 진정한 스트리밍 변환이 아닙니다.
- 작업자 1개로 처리합니다. 멀티서버/대규모 공개 서비스용 분산 큐가 아닙니다.
- worker에 CPU wall-clock 강제 중단 제한은 없습니다. 시스템/컨테이너 자원 제한과 watchdog을 운영 시 추가하세요.
- 캐시 파일 수, 디스크, 로그 용량을 모니터링하고 logrotate를 운영 설정에 추가하세요.
- 의존성 취약점 검사는 정기적으로 composer audit, 업데이트는 검토 후 composer update 및 lock 커밋.
- 이미지/수식/자동 세부 목차/변환 결과 검증 자동화는 후속 개발 대상입니다.

## 13. GitHub 업로드

GitHub 웹사이트에서 빈 저장소를 만듭니다. 기존 코드와 충돌을 피하려면 처음 만들 때 README/license/gitignore 자동 생성을 선택하지 않습니다.
예제 YOUR_ID는 본인의 GitHub 계정으로 바꿉니다.

```bash
cd /opt/sec-10k-converter
git init
git branch -M main
git config user.name '본인의 이름'
git config user.email '본인의 Git 이메일'
git add .
git status --short
git ls-files .env vendor storage logs
```

마지막 출력에는 storage/.gitkeep, logs/.gitkeep만 있어야 하며 .env, vendor, 실제 캐시/작업/보고서는 없어야 합니다.
composer.json, composer.lock, Dockerfile, docker-compose.yml이 올라가는지 확인합니다.

```bash
git commit -m 'Add SEC 10-K converter with file queue and Docker'
git remote add origin https://github.com/YOUR_ID/sec-10k-converter.git
git push -u origin main
```

HTTPS push 인증은 계정 비밀번호 대신 GitHub에서 지원하는 토큰/자격증명 관리 또는 SSH 인증을 사용합니다.
토큰을 remote URL이나 README에 적지 마세요.
.env가 이미 커밋되었다면 gitignore만 추가한다고 과거 기록에서 사라지지 않습니다. 민감정보 유출 대응과 이력 정리가 필요합니다.

프로젝트 코드는 MIT로 제공하지만 SEC 문서 내 제3자 저작물/지수 데이터 이용 조건까지 MIT가 되는 것은 아닙니다.
공개/상업 서비스에서는 데이터 공급원 최신성/라이선스/배포 권한을 따로 검토하세요.

## 참고 공식 문서

- SEC access: https://www.sec.gov/search-filings/edgar-search-assistance/accessing-edgar-data
- SEC API: https://www.sec.gov/search-filings/edgar-application-programming-interfaces
- SEC developer policy: https://www.sec.gov/about/developer-resources
- 구성 종목 데이터: https://github.com/datasets/s-and-p-500-companies
- HTML to Markdown: https://github.com/thephpleague/html-to-markdown
- Docker Ubuntu: https://docs.docker.com/engine/install/ubuntu/
- EPUB 규격: https://www.w3.org/TR/epub-33/


## 로컬 실행 확인 및 수정 사항

Ubuntu의 Docker 환경에서 다음 기능을 확인했습니다.

- 웹 화면 접속
- 티커를 통한 SEC 10-K 목록 조회
- Markdown·EPUB 변환 및 다운로드

초기 코드에서 다음 사항을 수정했습니다.

- Docker 이미지 내 소스 파일의 소유권과 읽기 권한 명시
- DomCrawler의 filter('body')를 filterXPath('//body')로 변경

이 확인은 로컬 기능 테스트이며, EPUBCheck 규격 검증이나
인터넷 공개 서비스의 보안 검증까지 완료했다는 의미는 아닙니다.
