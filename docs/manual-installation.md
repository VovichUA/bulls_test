# Ручне встановлення Ubuntu без Docker

Це альтернатива `setup.sh` для окремої чистої VM Ubuntu 22.04/24.04 LTS
із systemd. Автоматичний шлях описано в README; не виконуйте обидва способи
поверх одного встановлення: setup.sh не приймає чужий `.env` або наявну БД.
Ручний шлях нижче є інструкцією, а не окремим перевіреним прогоном чистої VM.
Автоматичний setup перевірено двічі на Ubuntu 24.04.

## 1. Системні залежності

```bash
sudo apt update
sudo apt install -y software-properties-common ca-certificates curl git unzip xz-utils openssl nginx mysql-server supervisor cron composer
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4-cli php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl
php8.4 -v
php8.4 /usr/bin/composer --version
```

Потрібні PHP **>=8.4.1** і Composer 2. Використання PHP 8.3 з поточним lock
не підтримується. PPA потребує доступу до Launchpad; спочатку усуньте мережеву
помилку, якщо репозиторій не додається.
[Офіційна інструкція PHP для Ubuntu](https://www.php.net/downloads.php?os=linux&osvariant=linux-ubuntu&usage=web&version=8.4).

Установіть офіційний Linux binary **Node.js 22 >=22.12** із перевіркою SHA256.
Наступні команди розраховані на Bash, архітектуру amd64/arm64 і вільний
каталог `/opt/bulls/node` на чистій VM:

```bash
case "$(dpkg --print-architecture)" in
    amd64) node_arch=x64 ;;
    arm64) node_arch=arm64 ;;
    *) echo "Unsupported Node architecture"; exit 1 ;;
esac
node_tmp=$(mktemp -d)
curl --fail --show-error --silent --location https://nodejs.org/dist/latest-v22.x/SHASUMS256.txt -o "$node_tmp/SHASUMS256.txt"
read -r node_checksum node_archive < <(awk -v arch="$node_arch" '$2 ~ ("^node-v22\\.[0-9]+\\.[0-9]+-linux-" arch "\\.tar\\.xz$") {print $1, $2}' "$node_tmp/SHASUMS256.txt")
curl --fail --show-error --silent --location "https://nodejs.org/dist/latest-v22.x/$node_archive" -o "$node_tmp/$node_archive"
(cd "$node_tmp" && printf '%s  %s\n' "$node_checksum" "$node_archive" | sha256sum --check) || exit 1
sudo install -d -m 0755 /opt/bulls/node
sudo tar -xJf "$node_tmp/$node_archive" --strip-components=1 -C /opt/bulls/node
rm -rf "$node_tmp"
export PATH=/opt/bulls/node/bin:$PATH
node --version
npm --version
node -e 'const [major,minor]=process.versions.node.split(".").map(Number); process.exit(major===22&&minor>=12?0:1)'
```

Джерело: [Node.js Downloads](https://nodejs.org/en/download).
Звичайний пакет nodejs з Ubuntu може бути старішим за вимоги Vite.

## 2. Код і користувач

Розмістіть перевірений checkout у `/var/www/bulls`; `src` має бути його
підкаталогом. Не копіюйте локальні `.env`, ключі, vendor або node_modules.
Виконайте з кореня checkout:

```bash
sudo useradd --system --create-home --home-dir /var/lib/bulls --shell /usr/sbin/nologin bulls
sudo usermod -aG www-data bulls
sudo chown -R bulls:www-data /var/www/bulls/src
sudo -u bulls mkdir -p src/storage/app/private src/storage/app/public src/storage/framework/cache/data src/storage/framework/cache/locks src/storage/framework/sessions src/storage/framework/views src/storage/logs src/bootstrap/cache
```

Ці команди призначені для першого встановлення; не створюйте повторно
користувача, якщо він уже існує. Визначте локальну функцію для команд застосунку:

```bash
app_run() {
    sudo /usr/sbin/runuser -u bulls -- /usr/bin/env -i HOME=/var/lib/bulls USER=bulls LOGNAME=bulls LC_ALL=C.UTF-8 PATH=/opt/bulls/node/bin:/usr/local/bin:/usr/bin:/bin /bin/sh -c 'umask 0002; cd /var/www/bulls/src; exec "$@"' sh "$@"
}
app_run /usr/bin/php8.4 /usr/bin/composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
app_run /usr/bin/php8.4 /usr/bin/composer check-platform-reqs --no-dev
app_run npm ci --ignore-scripts --no-audit
app_run npm run build
app_run cp .env.example .env
sudo chmod 0640 src/.env
```

## 3. MySQL та `.env`

Увімкніть MySQL і згенеруйте окремий пароль локально (`openssl rand -hex 32`).
Не надсилайте його в чат і не додавайте до Git. Увійдіть у MySQL без збереження
історії та виконайте SQL, замінивши `YOUR_RANDOM_PASSWORD`:

```bash
sudo systemctl enable --now mysql
sudo env MYSQL_HISTFILE=/dev/null mysql --protocol=socket -uroot
```

```sql
CREATE DATABASE bulls CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bulls_app'@'localhost' IDENTIFIED BY 'YOUR_RANDOM_PASSWORD';
GRANT ALL PRIVILEGES ON bulls.* TO 'bulls_app'@'localhost';
EXIT;
```

Редактором запишіть у `src/.env` реальні значення:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://YOUR_VM_IP
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=bulls
DB_USERNAME=bulls_app
DB_PASSWORD=YOUR_RANDOM_PASSWORD
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=720
CACHE_STORE=database
GOOGLE_SHEETS_CREDENTIALS=storage/app/private/google-service-account.json
ADMIN_NAME="Administrator"
ADMIN_EMAIL=YOUR_ADMIN_EMAIL
ADMIN_PASSWORD="YOUR_PRIVATE_ADMIN_PASSWORD"
```

Пароль адміністратора — 12+ символів, максимум 72 байти. Це placeholders,
готових доступів немає. DB_URL має бути відсутнім або порожнім.
У застосунку `config('app.timezone')` дорівнює UTC; дата знімка — Europe/Kyiv.
Виконайте:

```bash
app_run /usr/bin/php8.4 artisan config:clear
app_run /usr/bin/php8.4 artisan key:generate --force
app_run /usr/bin/php8.4 artisan migrate --force
app_run /usr/bin/php8.4 /var/www/bulls/deploy/setup-config.php bootstrap
app_run /usr/bin/php8.4 artisan storage:link
```

`bootstrap` виконує AdminSeeder, у finally прибирає ADMIN_PASSWORD із `.env`
і лише потім створює config cache; помилка seed не кешує пароль.
Не запускайте key:generate повторно на вже налаштованому застосунку.

## 4. Права, nginx, FPM, Supervisor та cron

Підставте свій IP/домен замість YOUR_VM_IP. Використовуються ті самі шаблони,
що перевірені в setup.sh. Наведені імена конфігів мають бути вільними:
не перезаписуйте налаштування іншого застосунку.

```bash
sudo find src/storage src/bootstrap/cache -type d -exec chmod 2770 {} +
sudo find src/storage src/bootstrap/cache -type f -exec chmod 0660 {} +
sudo chown -R bulls:www-data src/storage src/bootstrap/cache
sudo install -d /etc/systemd/system/php8.4-fpm.service.d
php8.4 deploy/setup-config.php template YOUR_VM_IP nginx | sudo tee /etc/nginx/sites-available/bulls >/dev/null
php8.4 deploy/setup-config.php template YOUR_VM_IP fpm | sudo tee /etc/php/8.4/fpm/pool.d/bulls.conf >/dev/null
php8.4 deploy/setup-config.php template YOUR_VM_IP fpm-umask | sudo tee /etc/systemd/system/php8.4-fpm.service.d/bulls-umask.conf >/dev/null
php8.4 deploy/setup-config.php template YOUR_VM_IP supervisor | sudo tee /etc/supervisor/conf.d/bulls-sheets.conf >/dev/null
sudo ln -s /etc/nginx/sites-available/bulls /etc/nginx/sites-enabled/bulls
sudo php-fpm8.4 --test
sudo nginx -t
sudo systemctl daemon-reload
sudo systemctl enable --now php8.4-fpm nginx supervisor cron
sudo systemctl restart php8.4-fpm
sudo systemctl reload nginx
```

Корінь nginx — `/var/www/bulls/src/public`, не корінь checkout. FPM має окремий
socket `/run/php/bulls-php84.sock`. UMask=0002, спільна група www-data та setgid
дозволяють FPM і воркеру записувати логи. Після асинхронного reload перевірте:

```bash
. deploy/setup-filesystem.sh
bulls_wait_http YOUR_VM_IP http://127.0.0.1/up
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status bulls-sheets-worker
php8.4 deploy/setup-config.php template YOUR_VM_IP cron | sudo tee /etc/cron.d/bulls-sheets >/dev/null
sudo chown root:root /etc/cron.d/bulls-sheets
sudo chmod 0644 /etc/cron.d/bulls-sheets
sudo /bin/sh -c '. /var/www/bulls/deploy/setup-filesystem.sh; bulls_harden_code /var/www/bulls/src root:www-data'
```

Має працювати один воркер. Не вмикайте паралельно Docker automation.
Cron щохвилини викликає Laravel scheduler; фактичний імпорт — о 02:00 Kyiv.
Відкрийте http://YOUR_VM_IP/login і ввійдіть своїм адміністратором.
Для HTTPS налаштуйте TLS і SESSION_SECURE_COOKIE=true за production-розділом README.

## 5. Google та демо

За інструкцією Google API в README покладіть ключ у
`/var/www/bulls/src/storage/app/private/google-service-account.json`:

```bash
sudo chown bulls:www-data src/storage/app/private/google-service-account.json
sudo chmod 0640 src/storage/app/private/google-service-account.json
app_run /usr/bin/php8.4 artisan db:seed --class=SheetConfigurationSeeder --force
```

Після додавання ключа config:cache повторювати не потрібно, якщо шлях не змінено.
Далі імпортуйте CSV з `examples/sheets`, замініть placeholder ID у UI та активуйте
демо-джерело. Ручний запуск і succeeded у журналі перевіряють повний шлях.
