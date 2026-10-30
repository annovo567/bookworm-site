<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');

// Ловим фатальные ошибки и возвращаем их в JSON
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array(
            'success' => false,
            'message' => 'Fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']
        ), JSON_UNESCAPED_UNICODE);
    }
});

// ============================================================
//  Совместимо с PHP 5.3+ (работает и на 7.x, и на 8.x)
// ============================================================
session_start();

$host     = 'localhost';
$user     = 'bolzens_europa';
$password = '';
$database = 'bolzens_europa';

$conn = @new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'message' => 'Ошибка подключения к БД'));
        exit;
    }
    die('Ошибка подключения: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// ---------- Таблица пользователей ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        name          VARCHAR(100) NOT NULL DEFAULT '',
        nick          VARCHAR(50)  NOT NULL UNIQUE,
        email         VARCHAR(150) NOT NULL UNIQUE,
        phone         VARCHAR(30)  DEFAULT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('user','author','admin') NOT NULL DEFAULT 'user',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Таблица кодов подтверждения ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS email_codes (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        email      VARCHAR(150) NOT NULL,
        code       VARCHAR(10)  NOT NULL,
        payload    TEXT         NOT NULL,
        expires_at DATETIME     NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Таблица книг (с полем author_user_id) ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS books (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        title         VARCHAR(255) NOT NULL,
        author        VARCHAR(255) NOT NULL,
        genre         VARCHAR(100) NOT NULL DEFAULT '',
        description   TEXT,
        price         DECIMAL(10,2) NOT NULL DEFAULT 0,
        old_price     DECIMAL(10,2) DEFAULT NULL,
        discount      INT NOT NULL DEFAULT 0,
        cover         VARCHAR(255) NOT NULL DEFAULT '',
        is_bestseller TINYINT(1) NOT NULL DEFAULT 0,
        editor_pick   TINYINT(1) NOT NULL DEFAULT 0,
        author_user_id INT NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// На случай, если таблица была создана до миграции — добавим колонку
$conn->query("ALTER TABLE books ADD COLUMN IF NOT EXISTS author_user_id INT NULL DEFAULT NULL");

// ---------- Таблица заказов ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS orders (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL,
        total         DECIMAL(10,2) NOT NULL DEFAULT 0,
        customer_name VARCHAR(150) NOT NULL,
        phone         VARCHAR(30)  NOT NULL,
        address       TEXT         NOT NULL,
        comment       TEXT,
        payment_code  VARCHAR(50)  NOT NULL,
        status        ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (payment_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Таблица позиций заказа ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS order_items (
        id       INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        book_id  INT NOT NULL,
        title    VARCHAR(255) NOT NULL,
        price    DECIMAL(10,2) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        INDEX (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Таблица корзины пользователя ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS cart_items (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        book_id    INT NOT NULL,
        quantity   INT NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_book (user_id, book_id),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Таблица рукописей ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS manuscripts (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL,
        name          VARCHAR(150) NOT NULL,
        email         VARCHAR(150) NOT NULL,
        genre         VARCHAR(100) NOT NULL DEFAULT '',
        synopsis      TEXT NOT NULL,
        file_name     VARCHAR(255) DEFAULT NULL,
        file_path     VARCHAR(255) DEFAULT NULL,
        status        ENUM('pending','review','accepted','rejected') NOT NULL DEFAULT 'pending',
        admin_comment TEXT,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Таблица заявок на книги ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS book_requests (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL,
        title         VARCHAR(255) NOT NULL,
        author        VARCHAR(255) NOT NULL,
        genre         VARCHAR(100) NOT NULL DEFAULT '',
        description   TEXT,
        status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        admin_comment TEXT,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Таблица отзывов ----------
$conn->query("
    CREATE TABLE IF NOT EXISTS reviews (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        book_id     INT NOT NULL,
        user_id     INT NOT NULL,
        rating      TINYINT NOT NULL DEFAULT 5,
        text        TEXT,
        is_approved TINYINT(1) NOT NULL DEFAULT 1,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (book_id),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Автосев книг (только если таблица пуста) ----------
$r = $conn->query('SELECT COUNT(*) AS cnt FROM books');
if ($r) {
    $row = $r->fetch_assoc();
    if ((int)$row['cnt'] === 0) {
        $seed = array(
            array(1,'Анна Каренина','Лев Николаевич Толстой','Роман-эпопея','Роман о трагической любви замужней аристократки к блестящему офицеру, которая рушит её семью и приводит к катастрофе.',450,null,0,'1.png',1,0),
            array(2,'Спящий убийца','Агата Кристи','Детектив','Юная пара, расследуя давнее убийство в собственном доме, выводит на чистую воду спящего десятилетиями преступника.',620,790,21,'2.png',1,1),
            array(3,'Утраченные иллюзии','Оноре Де Бальзак','Роман','Честолюбивый молодой поэт из провинции поднимается и падает в парижском обществе, где всё продаётся и покупается.',850,1100,23,'3.png',0,0),
            array(4,'Консуэло','Жорж Санд','Роман-исповедь','Роман-исповедь о певице с венецианскими и богемными мотивами, история бедной, но одарённой девушки.',320,null,0,'4.png',0,1),
            array(5,'Темные Аллеи','Иван Бунин','Новелла','Цикл новелл о любви-воспоминании, где каждая история показывает любовь как сиюминутную, часто трагическую вспышку.',590,890,34,'5.png',1,1),
            array(6,'Темные аллеи. Рассказы и стихи','Иван Бунин','Триллер','Цикл новелл о любви-воспоминании.',710,null,0,'6.png',0,0),
            array(7,'Десять негритят','Агата Кристи','Триллер','Психологический детективный триллер о десяти незнакомцах, запертых на острове.',490,600,18,'7.png',0,1),
            array(8,'Сумерки','Стефани Майер','Романтическое фэнтези','Подростковый любовно-фэнтезийный роман о школьнице, влюбившейся в вампира.',890,null,0,'8.png',0,0),
            array(9,'Утраченные иллюзии','Оноре Де Бальзак','Роман','Честолюбивый молодой поэт из провинции поднимается и падает в парижском обществе.',750,950,21,'9.png',1,0),
            array(10,'Темные Аллеи','Иван Бунин','Новелла','Цикл новелл о любви-воспоминании.',540,null,0,'13.png',0,1),
            array(11,'Любовь к жизни','Джек Лондон','Рассказ','Приключенческий рассказ о выживании на бескрайних просторах Аляски.',680,850,20,'14.png',0,0),
            array(12,'Портрет Дориана Грея','Оскар Уайльд','Роман','Философский роман с элементами готики и декаданса о вечно юном юноше и его портрете.',620,null,0,'15.png',1,1)
        );
        $stmt = $conn->prepare('INSERT INTO books (id, title, author, genre, description, price, old_price, discount, cover, is_bestseller, editor_pick) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($seed as $b) {
            $stmt->bind_param('issssddisii',
                $b[0], $b[1], $b[2], $b[3], $b[4],
                $b[5], $b[6], $b[7], $b[8], $b[9], $b[10]
            );
            $stmt->execute();
        }
        $stmt->close();
    }
}

// ============================================================
//  SMTP-функция
// ============================================================
$smtpHost = 'ssl://smtp.beget.com';
$smtpPort = 465;
$smtpUser = 'bolzens_europa@spaceitam.ru';
$smtpPass = 'fuNG8N6&FSAy';
$smtpFrom = 'bolzens_europa@spaceitam.ru';

function smtpGetResponse($socket) {
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (substr($line, 3, 1) == ' ') break;
    }
    return $response;
}
function smtpSendCommand($socket, $cmd) {
    fwrite($socket, $cmd . "\r\n");
    return smtpGetResponse($socket);
}
function smtpSend($to, $subject, $body, $attachment = null) {
    global $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFrom;
    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 30);
    if (!$socket) { error_log("SMTP: connect failed: $errstr ($errno)"); return false; }
    smtpGetResponse($socket);
    smtpSendCommand($socket, "EHLO " . gethostname());
    smtpSendCommand($socket, "AUTH LOGIN");
    smtpSendCommand($socket, base64_encode($smtpUser));
    smtpSendCommand($socket, base64_encode($smtpPass));
    smtpSendCommand($socket, "MAIL FROM:<$smtpFrom>");
    smtpSendCommand($socket, "RCPT TO:<$to>");
    smtpSendCommand($socket, "DATA");
    $boundary = '=_Part_' . md5(uniqid(time()));
    $headers  = "From: $smtpFrom\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    if ($attachment && !empty($attachment['tmp_name']) && is_file($attachment['tmp_name'])) {
        $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        $message  = "--$boundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($body)) . "\r\n";
        $filename = basename($attachment['name']);
        $fileData = file_get_contents($attachment['tmp_name']);
        $message .= "--$boundary\r\n";
        $message .= "Content-Type: application/octet-stream; name=\"=?UTF-8?B?" . base64_encode($filename) . "?=\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"=?UTF-8?B?" . base64_encode($filename) . "?=\"\r\n\r\n";
        $message .= chunk_split(base64_encode($fileData)) . "\r\n";
        $message .= "--$boundary--\r\n";
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
        $message  = chunk_split(base64_encode($body)) . "\r\n";
    }
    fwrite($socket, $headers . "\r\n" . $message . ".\r\n");
    smtpGetResponse($socket);
    smtpSendCommand($socket, "QUIT");
    fclose($socket);
    return true;
}

// ============================================================
//  Роутер AJAX
// ============================================================
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    // --- РЕГИСТРАЦИЯ: ШАГ 1 ---
    if ($action === 'register_send_code') {
        $name  = isset($_POST['name'])     ? trim($_POST['name'])     : '';
        $nick  = isset($_POST['nick'])     ? trim($_POST['nick'])     : '';
        $email = isset($_POST['email'])    ? trim($_POST['email'])    : '';
        $phone = isset($_POST['phone'])    ? trim($_POST['phone'])    : '';
        $pass  = isset($_POST['password']) ? $_POST['password']       : '';

        if ($name === '' || $nick === '' || $email === '' || $pass === '') {
            echo json_encode(array('success' => false, 'message' => 'Заполните все поля'), JSON_UNESCAPED_UNICODE); exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(array('success' => false, 'message' => 'Некорректный email'), JSON_UNESCAPED_UNICODE); exit;
        }
        if (mb_strlen($pass, 'UTF-8') < 6) {
            echo json_encode(array('success' => false, 'message' => 'Пароль минимум 6 символов'), JSON_UNESCAPED_UNICODE); exit;
        }

        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? OR nick = ? LIMIT 1');
        $stmt->bind_param('ss', $email, $nick);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            echo json_encode(array('success' => false, 'message' => 'Email или ник уже заняты'), JSON_UNESCAPED_UNICODE); exit;
        }
        $stmt->close();

        $code = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $payload = json_encode(array(
            'name' => $name, 'nick' => $nick, 'email' => $email, 'phone' => $phone,
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT)
        ), JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare('DELETE FROM email_codes WHERE email = ?');
        $stmt->bind_param('s', $email); $stmt->execute(); $stmt->close();

        $expires = date('Y-m-d H:i:s', time() + 15 * 60);
        $stmt = $conn->prepare('INSERT INTO email_codes (email, code, payload, expires_at) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $email, $code, $payload, $expires);
        $stmt->execute(); $stmt->close();

        $subject = 'Код подтверждения регистрации — Книжный червь';
        $body    = "Здравствуйте, $name!\n\nВаш код подтверждения регистрации:\n\n    $code\n\nКод действует 15 минут.\n";

        if (!smtpSend($email, $subject, $body)) {
            echo json_encode(array('success' => false, 'message' => 'Не удалось отправить код на email'), JSON_UNESCAPED_UNICODE); exit;
        }
        echo json_encode(array('success' => true, 'message' => 'Код отправлен на ' . $email), JSON_UNESCAPED_UNICODE); exit;
    }

    // --- РЕГИСТРАЦИЯ: ШАГ 2 ---
    if ($action === 'register_confirm') {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $code  = isset($_POST['code'])  ? trim($_POST['code'])  : '';
        if ($email === '' || $code === '') {
            echo json_encode(array('success' => false, 'message' => 'Введите код'), JSON_UNESCAPED_UNICODE); exit;
        }

        $stmt = $conn->prepare('SELECT code, payload, expires_at FROM email_codes WHERE email = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('s', $email); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if (!$row) { echo json_encode(array('success' => false, 'message' => 'Код не найден. Запросите новый.'), JSON_UNESCAPED_UNICODE); exit; }
        if (strtotime($row['expires_at']) < time()) { echo json_encode(array('success' => false, 'message' => 'Код истёк.'), JSON_UNESCAPED_UNICODE); exit; }
        if ($row['code'] !== $code) { echo json_encode(array('success' => false, 'message' => 'Неверный код'), JSON_UNESCAPED_UNICODE); exit; }

        $data = json_decode($row['payload'], true);
        if (!$data || empty($data['email'])) { echo json_encode(array('success' => false, 'message' => 'Ошибка данных.'), JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? OR nick = ? LIMIT 1');
        $stmt->bind_param('ss', $data['email'], $data['nick']);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) { $stmt->close(); echo json_encode(array('success' => false, 'message' => 'Email или ник уже заняты'), JSON_UNESCAPED_UNICODE); exit; }
        $stmt->close();

        $stmt = $conn->prepare('INSERT INTO users (name, nick, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?, "user")');
        $stmt->bind_param('sssss', $data['name'], $data['nick'], $data['email'], $data['phone'], $data['password_hash']);
        if (!$stmt->execute()) { $stmt->close(); echo json_encode(array('success' => false, 'message' => 'Ошибка при регистрации'), JSON_UNESCAPED_UNICODE); exit; }
        $newId = $stmt->insert_id; $stmt->close();

        $stmt = $conn->prepare('DELETE FROM email_codes WHERE email = ?');
        $stmt->bind_param('s', $data['email']); $stmt->execute(); $stmt->close();

        $_SESSION['user_id'] = $newId;
        $_SESSION['role']    = 'user';
        echo json_encode(array('success' => true, 'message' => 'Регистрация подтверждена!'), JSON_UNESCAPED_UNICODE); exit;
    }

    // --- ВХОД ---
    if ($action === 'login') {
        $email = isset($_POST['email'])    ? trim($_POST['email']) : '';
        $pass  = isset($_POST['password']) ? $_POST['password']    : '';
        if ($email === '' || $pass === '') { echo json_encode(array('success' => false, 'message' => 'Введите email и пароль'), JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $conn->prepare('SELECT id, password_hash, role FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if ($row && password_verify($pass, $row['password_hash'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['role']    = $row['role'];
            echo json_encode(array('success' => true, 'message' => 'Вы вошли!'), JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(array('success' => false, 'message' => 'Неверный email или пароль'), JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- ДЕМО ---
    if ($action === 'demo') {
        $role = isset($_POST['role']) ? $_POST['role'] : '';
        if ($role === 'admin') {
            $_SESSION['user_id'] = 0; $_SESSION['role'] = 'admin'; $_SESSION['demo_name'] = 'Администратор';
            echo json_encode(array('success' => true, 'message' => 'Демо-вход'), JSON_UNESCAPED_UNICODE);
        } elseif ($role === 'author') {
            $_SESSION['user_id'] = 0; $_SESSION['role'] = 'author'; $_SESSION['demo_name'] = 'Иван Петров';
            echo json_encode(array('success' => true, 'message' => 'Демо-вход'), JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(array('success' => false, 'message' => 'Неизвестная роль'), JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- ПРОФИЛЬ ---
    if ($action === 'profile') {
        if (empty($_SESSION['user_id']) && empty($_SESSION['role'])) {
            echo json_encode(array('authenticated' => false), JSON_UNESCAPED_UNICODE); exit;
        }
        if (empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
            echo json_encode(array(
                'authenticated' => true,
                'user' => array(
                    'id' => 0,
                    'name' => isset($_SESSION['demo_name']) ? $_SESSION['demo_name'] : 'Гость',
                    'nick' => $_SESSION['role'], 'email' => 'demo@local', 'phone' => '',
                    'role' => $_SESSION['role'], 'created_at' => date('Y-m-d')
                )
            ), JSON_UNESCAPED_UNICODE); exit;
        }
        $uid = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare('SELECT id, name, nick, email, phone, role, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $uid); $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($u) echo json_encode(array('authenticated' => true, 'user' => $u), JSON_UNESCAPED_UNICODE);
        else    echo json_encode(array('authenticated' => false), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- ВЫХОД ---
    if ($action === 'logout') {
        $_SESSION = array(); session_destroy();
        echo json_encode(array('success' => true, 'message' => 'Вы вышли'), JSON_UNESCAPED_UNICODE); exit;
    }

    // --- ОБНОВЛЕНИЕ ПРОФИЛЯ ---
    if ($action === 'update_profile') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) { echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit; }
        $name  = isset($_POST['name'])  ? trim($_POST['name'])  : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $nick  = isset($_POST['nick'])  ? trim($_POST['nick'])  : '';
        if ($name === '' || $nick === '') {
            echo json_encode(array('success' => false, 'message' => 'Имя и ник обязательны'), JSON_UNESCAPED_UNICODE); exit;
        }

        // Проверка уникальности ника
        $stmt = $conn->prepare('SELECT id FROM users WHERE nick = ? AND id <> ? LIMIT 1');
        $stmt->bind_param('si', $nick, $uid);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) { $stmt->close(); echo json_encode(array('success' => false, 'message' => 'Ник уже занят'), JSON_UNESCAPED_UNICODE); exit; }
        $stmt->close();

        $stmt = $conn->prepare('UPDATE users SET name = ?, phone = ?, nick = ? WHERE id = ?');
        $stmt->bind_param('sssi', $name, $phone, $nick, $uid);
        $stmt->execute(); $stmt->close();

        echo json_encode(array('success' => true, 'message' => 'Профиль обновлён'), JSON_UNESCAPED_UNICODE); exit;
    }

    // --- СМЕНА ПАРОЛЯ ---
    if ($action === 'change_password') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) { echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit; }
        $old = isset($_POST['old_password']) ? $_POST['old_password'] : '';
        $new = isset($_POST['new_password']) ? $_POST['new_password'] : '';
        if ($old === '' || $new === '') { echo json_encode(array('success' => false, 'message' => 'Заполните оба поля'), JSON_UNESCAPED_UNICODE); exit; }
        if (mb_strlen($new, 'UTF-8') < 6) { echo json_encode(array('success' => false, 'message' => 'Пароль минимум 6 символов'), JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $uid); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row || !password_verify($old, $row['password_hash'])) {
            echo json_encode(array('success' => false, 'message' => 'Неверный текущий пароль'), JSON_UNESCAPED_UNICODE); exit;
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $uid); $stmt->execute(); $stmt->close();

        echo json_encode(array('success' => true, 'message' => 'Пароль изменён'), JSON_UNESCAPED_UNICODE); exit;
    }

    // --- ОТПРАВКА РУКОПИСИ (из общей формы) ---
    if ($action === 'manuscript') {
        $name     = isset($_POST['name'])     ? trim($_POST['name'])     : '';
        $email    = isset($_POST['email'])    ? trim($_POST['email'])    : '';
        $genre    = isset($_POST['genre'])    ? trim($_POST['genre'])    : '';
        $synopsis = isset($_POST['synopsis']) ? trim($_POST['synopsis']) : '';
        if ($name === '' || $email === '' || $synopsis === '') {
            echo json_encode(array('success' => false, 'message' => 'Заполните имя, email и синопсис'), JSON_UNESCAPED_UNICODE); exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(array('success' => false, 'message' => 'Некорректный email'), JSON_UNESCAPED_UNICODE); exit;
        }
        $body  = "Новая заявка на рукопись\n------------------------\nИмя: $name\nEmail: $email\nЖанр: $genre\n\nСинопсис:\n$synopsis\n";
        $attachment = null;
        if (!empty($_FILES['manuscript_file']['tmp_name'])) {
            $f = $_FILES['manuscript_file'];
            if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(array('success' => false, 'message' => 'Ошибка загрузки файла'), JSON_UNESCAPED_UNICODE); exit; }
            if ($f['size'] > 10 * 1024 * 1024) { echo json_encode(array('success' => false, 'message' => 'Файл слишком большой'), JSON_UNESCAPED_UNICODE); exit; }
            $allowed = array('doc','docx','pdf','txt');
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) { echo json_encode(array('success' => false, 'message' => 'Разрешены только .doc, .docx, .pdf, .txt'), JSON_UNESCAPED_UNICODE); exit; }
            $attachment = array('tmp_name' => $f['tmp_name'], 'name' => $f['name']);
        }
        $ok = smtpSend('novoksonovaana13@mail.ru', 'Новая рукопись — Книжный червь', $body, $attachment);
        echo json_encode(array('success' => (bool)$ok, 'message' => $ok ? 'Рукопись отправлена!' : 'Не удалось отправить письмо'), JSON_UNESCAPED_UNICODE); exit;
    }

    // --- СПИСОК КНИГ ---
    if ($action === 'get_books') {
        $res = $conn->query('SELECT id, title, author, genre, description, price, old_price, discount, cover, is_bestseller, editor_pick, author_user_id FROM books ORDER BY id ASC');
        $books = array();
        while ($b = $res->fetch_assoc()) {
            $books[] = array(
                'id'           => (int)$b['id'],
                'title'        => $b['title'],
                'author'       => $b['author'],
                'genre'        => $b['genre'],
                'desc'         => $b['description'],
                'price'        => (float)$b['price'],
                'oldPrice'     => $b['old_price'] !== null ? (float)$b['old_price'] : null,
                'discount'     => (int)$b['discount'],
                'cover'        => $b['cover'],
                'isBestseller' => (bool)$b['is_bestseller'],
                'editorPick'   => (bool)$b['editor_pick'],
                'authorUserId' => $b['author_user_id'] !== null ? (int)$b['author_user_id'] : null
            );
        }
        echo json_encode(array('success' => true, 'books' => $books), JSON_UNESCAPED_UNICODE); exit;
    }

    // --- КОРЗИНА: загрузить ---
    if ($action === 'cart_get') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) {
            echo json_encode(array('success' => true, 'items' => array()), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $conn->prepare('
            SELECT c.book_id AS id, c.quantity,
                   b.title, b.author, b.price, b.cover
            FROM cart_items c
            JOIN books b ON b.id = c.book_id
            WHERE c.user_id = ?
            ORDER BY c.id ASC
        ');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = array();
        while ($row = $res->fetch_assoc()) {
            $items[] = array(
                'id'       => (int)$row['id'],
                'title'    => $row['title'],
                'author'   => $row['author'],
                'price'    => (float)$row['price'],
                'cover'    => $row['cover'],
                'quantity' => (int)$row['quantity']
            );
        }
        $stmt->close();
        echo json_encode(array('success' => true, 'items' => $items), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- КОРЗИНА: сохранить ---
    if ($action === 'cart_save') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) {
            echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $cartJson = isset($_POST['cart']) ? $_POST['cart'] : '[]';
        $items = json_decode($cartJson, true);
        if (!is_array($items)) $items = array();

        $validItems = array();
        foreach ($items as $it) {
            $bid = isset($it['id']) ? (int)$it['id'] : 0;
            $qty = isset($it['quantity']) ? (int)$it['quantity'] : 1;
            if ($bid <= 0 || $qty <= 0) continue;

            $stmt = $conn->prepare('SELECT id FROM books WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $bid);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists) $validItems[] = array('id' => $bid, 'quantity' => $qty);
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('DELETE FROM cart_items WHERE user_id = ?');
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();

            if (count($validItems) > 0) {
                $stmt = $conn->prepare('INSERT INTO cart_items (user_id, book_id, quantity) VALUES (?, ?, ?)');
                foreach ($validItems as $v) {
                    $stmt->bind_param('iii', $uid, $v['id'], $v['quantity']);
                    $stmt->execute();
                }
                $stmt->close();
            }
            $conn->commit();
            echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            $conn->rollback();
            error_log('cart_save error: ' . $e->getMessage());
            echo json_encode(array('success' => false, 'message' => 'Ошибка сохранения корзины'), JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- ИСТОРИЯ ЗАКАЗОВ ПОЛЬЗОВАТЕЛЯ ---
    if ($action === 'my_orders') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) {
            echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $conn->prepare('
            SELECT id, total, status, payment_code, created_at, customer_name, phone, address
            FROM orders
            WHERE user_id = ?
            ORDER BY id DESC
        ');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $orders = array();
        while ($o = $res->fetch_assoc()) {
            $orderId = (int)$o['id'];

            $stmt2 = $conn->prepare('SELECT book_id, title, price, quantity FROM order_items WHERE order_id = ?');
            $stmt2->bind_param('i', $orderId);
            $stmt2->execute();
            $r2 = $stmt2->get_result();
            $items = array();
            while ($it = $r2->fetch_assoc()) {
                $items[] = array(
                    'book_id'  => (int)$it['book_id'],
                    'title'    => $it['title'],
                    'price'    => (float)$it['price'],
                    'quantity' => (int)$it['quantity']
                );
            }
            $stmt2->close();

            $orders[] = array(
                'id'           => $orderId,
                'total'        => (float)$o['total'],
                'status'       => $o['status'],
                'payment_code' => $o['payment_code'],
                'created_at'   => $o['created_at'],
                'name'         => $o['customer_name'],
                'phone'        => $o['phone'],
                'address'      => $o['address'],
                'items'        => $items
            );
        }
        $stmt->close();

        echo json_encode(array('success' => true, 'orders' => $orders), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- ОФОРМЛЕНИЕ ЗАКАЗА ---
    if ($action === 'checkout') {
        $uid  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
        if (empty($role) && $uid === 0) {
            echo json_encode(array('success' => false, 'message' => 'Только авторизованные пользователи могут оформить заказ'), JSON_UNESCAPED_UNICODE); exit;
        }
        if ($uid === 0 && $role !== '') {
            echo json_encode(array('success' => false, 'message' => 'Демо-режим: оформление недоступно. Войдите как реальный пользователь.'), JSON_UNESCAPED_UNICODE); exit;
        }

        $name     = isset($_POST['name'])    ? trim($_POST['name'])    : '';
        $phone    = isset($_POST['phone'])   ? trim($_POST['phone'])   : '';
        $address  = isset($_POST['address']) ? trim($_POST['address']) : '';
        $comment  = isset($_POST['comment']) ? trim($_POST['comment']) : '';
        $cartJson = isset($_POST['cart'])    ? $_POST['cart']          : '[]';

        if ($name === '' || $phone === '' || $address === '') {
            echo json_encode(array('success' => false, 'message' => 'Заполните имя, телефон и адрес'), JSON_UNESCAPED_UNICODE); exit;
        }
        $cartItems = json_decode($cartJson, true);
        if (!is_array($cartItems) || count($cartItems) === 0) {
            echo json_encode(array('success' => false, 'message' => 'Корзина пуста'), JSON_UNESCAPED_UNICODE); exit;
        }

        $total = 0; $validated = array();
        foreach ($cartItems as $item) {
            $bookId = isset($item['id']) ? (int)$item['id'] : 0;
            $qty    = isset($item['quantity']) ? (int)$item['quantity'] : 1;
            if ($bookId <= 0 || $qty <= 0) continue;

            $stmt = $conn->prepare('SELECT id, title, price FROM books WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $bookId); $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$r) continue;

            $price = (float)$r['price'];
            $total += $price * $qty;
            $validated[] = array('book_id' => (int)$r['id'], 'title' => $r['title'], 'price' => $price, 'quantity' => $qty);
        }
        if (count($validated) === 0) {
            echo json_encode(array('success' => false, 'message' => 'Некорректные товары в корзине'), JSON_UNESCAPED_UNICODE); exit;
        }

        $paymentCode = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('INSERT INTO orders (user_id, total, customer_name, phone, address, comment, payment_code) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('idsssss', $uid, $total, $name, $phone, $address, $comment, $paymentCode);
            if (!$stmt->execute()) throw new Exception('order insert: ' . $stmt->error);
            $orderId = $stmt->insert_id; $stmt->close();

            $stmt = $conn->prepare('INSERT INTO order_items (order_id, book_id, title, price, quantity) VALUES (?, ?, ?, ?, ?)');
            foreach ($validated as $v) {
                $stmt->bind_param('iisdi', $orderId, $v['book_id'], $v['title'], $v['price'], $v['quantity']);
                if (!$stmt->execute()) throw new Exception('item insert: ' . $stmt->error);
            }
            $stmt->close();

            $stmt = $conn->prepare('DELETE FROM cart_items WHERE user_id = ?');
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('Checkout error: ' . $e->getMessage());
            echo json_encode(array('success' => false, 'message' => 'Ошибка оформления заказа'), JSON_UNESCAPED_UNICODE); exit;
        }

        echo json_encode(array('success' => true, 'message' => 'Заказ оформлен!', 'order_id' => $orderId, 'total' => $total, 'payment_code' => $paymentCode), JSON_UNESCAPED_UNICODE); exit;
    }

    // --- СТАТУС ЗАКАЗА ---
    if ($action === 'order_status') {
        $code = isset($_POST['payment_code']) ? trim($_POST['payment_code']) : '';
        if ($code === '') { echo json_encode(array('success' => false, 'message' => 'Не указан код'), JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $conn->prepare('SELECT id, total, status, created_at FROM orders WHERE payment_code = ? LIMIT 1');
        $stmt->bind_param('s', $code); $stmt->execute();
        $o = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$o) { echo json_encode(array('success' => false, 'message' => 'Заказ не найден'), JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(array('success' => true, 'order' => $o), JSON_UNESCAPED_UNICODE); exit;
    }

    // --- ИМИТАЦИЯ ОПЛАТЫ ---
    if ($action === 'pay_order') {
        if (empty($_SESSION['user_id'])) {
            echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit;
        }
        $code = isset($_POST['payment_code']) ? trim($_POST['payment_code']) : '';
        $uid  = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare('UPDATE orders SET status = "paid" WHERE payment_code = ? AND user_id = ?');
        $stmt->bind_param('si', $code, $uid); $stmt->execute();
        $affected = $stmt->affected_rows; $stmt->close();
        if ($affected > 0) echo json_encode(array('success' => true, 'message' => 'Оплата подтверждена'), JSON_UNESCAPED_UNICODE);
        else                echo json_encode(array('success' => false, 'message' => 'Заказ не найден или уже оплачен'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- ОТМЕНА ЗАКАЗА ---
    if ($action === 'cancel_order') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) {
            echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit;
        }
        $code = isset($_POST['payment_code']) ? trim($_POST['payment_code']) : '';
        if ($code === '') {
            echo json_encode(array('success' => false, 'message' => 'Не указан код заказа'), JSON_UNESCAPED_UNICODE); exit;
        }

        $stmt = $conn->prepare('SELECT id, status FROM orders WHERE payment_code = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('si', $code, $uid); $stmt->execute();
        $o = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if (!$o) { echo json_encode(array('success' => false, 'message' => 'Заказ не найден'), JSON_UNESCAPED_UNICODE); exit; }
        if ($o['status'] === 'paid') { echo json_encode(array('success' => false, 'message' => 'Оплаченный заказ нельзя отменить'), JSON_UNESCAPED_UNICODE); exit; }
        if ($o['status'] === 'cancelled') { echo json_encode(array('success' => false, 'message' => 'Заказ уже отменён'), JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $conn->prepare('UPDATE orders SET status = "cancelled" WHERE id = ?');
        $stmt->bind_param('i', $o['id']); $stmt->execute(); $stmt->close();

        echo json_encode(array('success' => true, 'message' => 'Заказ отменён'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ============================================================
    //  АВТОРСКИЕ ЭКШЕНЫ
    // ============================================================

    // --- АВТОР: ОТПРАВИТЬ РУКОПИСЬ (из профиля, с сохранением в БД) ---
    if ($action === 'author_submit_manuscript') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0 || (isset($_SESSION['role']) && $_SESSION['role'] !== 'author' && $_SESSION['role'] !== 'admin')) {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для авторов'), JSON_UNESCAPED_UNICODE); exit;
        }
        $name     = isset($_POST['name'])     ? trim($_POST['name'])     : '';
        $email    = isset($_POST['email'])    ? trim($_POST['email'])    : '';
        $genre    = isset($_POST['genre'])    ? trim($_POST['genre'])    : '';
        $synopsis = isset($_POST['synopsis']) ? trim($_POST['synopsis']) : '';
        if ($name === '' || $email === '' || $synopsis === '') {
            echo json_encode(array('success' => false, 'message' => 'Заполните имя, email и синопсис'), JSON_UNESCAPED_UNICODE); exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(array('success' => false, 'message' => 'Некорректный email'), JSON_UNESCAPED_UNICODE); exit;
        }

        $fileName = null; $filePath = null; $attachment = null;
        if (!empty($_FILES['manuscript_file']['tmp_name'])) {
            $f = $_FILES['manuscript_file'];
            if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(array('success' => false, 'message' => 'Ошибка загрузки файла'), JSON_UNESCAPED_UNICODE); exit; }
            if ($f['size'] > 10 * 1024 * 1024) { echo json_encode(array('success' => false, 'message' => 'Файл слишком большой'), JSON_UNESCAPED_UNICODE); exit; }
            $allowed = array('doc','docx','pdf','txt');
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) { echo json_encode(array('success' => false, 'message' => 'Разрешены только .doc, .docx, .pdf, .txt'), JSON_UNESCAPED_UNICODE); exit; }
            $fileName = basename($f['name']);
            $attachment = array('tmp_name' => $f['tmp_name'], 'name' => $f['name']);
        }

        $stmt = $conn->prepare('INSERT INTO manuscripts (user_id, name, email, genre, synopsis, file_name, status) VALUES (?, ?, ?, ?, ?, ?, "pending")');
        $stmt->bind_param('isssss', $uid, $name, $email, $genre, $synopsis, $fileName);
        if (!$stmt->execute()) {
            $stmt->close();
            echo json_encode(array('success' => false, 'message' => 'Ошибка сохранения рукописи'), JSON_UNESCAPED_UNICODE); exit;
        }
        $msId = $stmt->insert_id; $stmt->close();

        // Дополнительно отправим email редактору
        $body = "Новая рукопись #$msId\n----------------------\nИмя: $name\nEmail: $email\nЖанр: $genre\n\nСинопсис:\n$synopsis\n";
        smtpSend('novoksonovaana13@mail.ru', 'Новая рукопись — Книжный червь', $body, $attachment);

        echo json_encode(array('success' => true, 'message' => 'Рукопись отправлена!', 'id' => $msId), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АВТОР: СПИСОК СВОИХ РУКОПИСЕЙ ---
    if ($action === 'author_my_manuscripts') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) { echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit; }
        $stmt = $conn->prepare('SELECT id, name, email, genre, synopsis, file_name, status, admin_comment, created_at FROM manuscripts WHERE user_id = ? ORDER BY id DESC');
        $stmt->bind_param('i', $uid); $stmt->execute();
        $res = $stmt->get_result();
        $list = array();
        while ($m = $res->fetch_assoc()) {
            $list[] = array(
                'id'            => (int)$m['id'],
                'name'          => $m['name'],
                'email'         => $m['email'],
                'genre'         => $m['genre'],
                'synopsis'      => $m['synopsis'],
                'file_name'     => $m['file_name'],
                'status'        => $m['status'],
                'admin_comment' => $m['admin_comment'],
                'created_at'    => $m['created_at']
            );
        }
        $stmt->close();
        echo json_encode(array('success' => true, 'manuscripts' => $list), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АВТОР: УДАЛИТЬ СВОЮ РУКОПИСЬ (только pending) ---
    if ($action === 'author_delete_manuscript') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $id  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($uid === 0 || $id <= 0) { echo json_encode(array('success' => false, 'message' => 'Некорректные данные'), JSON_UNESCAPED_UNICODE); exit; }
        $stmt = $conn->prepare('SELECT id, status FROM manuscripts WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $uid); $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$m) { echo json_encode(array('success' => false, 'message' => 'Рукопись не найдена'), JSON_UNESCAPED_UNICODE); exit; }
        if ($m['status'] !== 'pending') { echo json_encode(array('success' => false, 'message' => 'Можно удалять только рукописи в статусе «На рассмотрении»'), JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $conn->prepare('DELETE FROM manuscripts WHERE id = ?');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        echo json_encode(array('success' => true, 'message' => 'Рукопись удалена'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АВТОР: МОИ КНИГИ ---
    if ($action === 'author_my_books') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) { echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit; }
        $stmt = $conn->prepare('SELECT id, title, author, genre, description, price, old_price, discount, cover, is_bestseller, editor_pick FROM books WHERE author_user_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $uid); $stmt->execute();
        $res = $stmt->get_result();
        $books = array();
        while ($b = $res->fetch_assoc()) {
            $books[] = array(
                'id'           => (int)$b['id'],
                'title'        => $b['title'],
                'author'       => $b['author'],
                'genre'        => $b['genre'],
                'desc'         => $b['description'],
                'price'        => (float)$b['price'],
                'oldPrice'     => $b['old_price'] !== null ? (float)$b['old_price'] : null,
                'discount'     => (int)$b['discount'],
                'cover'        => $b['cover'],
                'isBestseller' => (bool)$b['is_bestseller'],
                'editorPick'   => (bool)$b['editor_pick']
            );
        }
        $stmt->close();
        echo json_encode(array('success' => true, 'books' => $books), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АВТОР: ОБНОВИТЬ СВОЮ КНИГУ (без editor_pick, без is_bestseller) ---
    if ($action === 'author_update_book') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) { echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit; }
        $id          = isset($_POST['id'])          ? (int)$_POST['id']      : 0;
        $title       = isset($_POST['title'])       ? trim($_POST['title'])  : '';
        $author      = isset($_POST['author'])      ? trim($_POST['author']) : '';
        $genre       = isset($_POST['genre'])       ? trim($_POST['genre'])  : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $cover       = isset($_POST['cover'])       ? trim($_POST['cover'])  : '';
        if ($id <= 0 || $title === '' || $author === '') {
            echo json_encode(array('success' => false, 'message' => 'Некорректные данные'), JSON_UNESCAPED_UNICODE); exit;
        }
        // Проверяем, что книга принадлежит этому автору
        $stmt = $conn->prepare('SELECT id FROM books WHERE id = ? AND author_user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $uid); $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$exists) { echo json_encode(array('success' => false, 'message' => 'Книга не найдена или не принадлежит вам'), JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $conn->prepare('UPDATE books SET title=?, author=?, genre=?, description=?, cover=? WHERE id=?');
        $stmt->bind_param('sssssi', $title, $author, $genre, $description, $cover, $id);
        $stmt->execute(); $stmt->close();
        echo json_encode(array('success' => true, 'message' => 'Книга обновлена'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АВТОР: СТАТИСТИКА ПРОДАЖ ПО СВОИМ КНИГАМ ---
    if ($action === 'author_stats') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) { echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit; }

        // Итоги
        $totals = array('qty' => 0, 'revenue' => 0.0, 'books_count' => 0);

        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM books WHERE author_user_id = ?');
        $stmt->bind_param('i', $uid); $stmt->execute();
        $totals['books_count'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(oi.quantity),0) AS qty,
                   COALESCE(SUM(oi.price * oi.quantity),0) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN books b ON b.id = oi.book_id
            WHERE b.author_user_id = ? AND o.status <> 'cancelled'
        ");
        $stmt->bind_param('i', $uid); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $totals['qty']     = (int)$row['qty'];
        $totals['revenue'] = (float)$row['revenue'];

        // По книгам
        $byBooks = array();
        $stmt = $conn->prepare("
            SELECT oi.book_id, oi.title,
                   SUM(oi.quantity) AS qty,
                   SUM(oi.price * oi.quantity) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN books b ON b.id = oi.book_id
            WHERE b.author_user_id = ? AND o.status <> 'cancelled'
            GROUP BY oi.book_id, oi.title
            ORDER BY qty DESC
        ");
        $stmt->bind_param('i', $uid); $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $byBooks[] = array(
                'book_id' => (int)$r['book_id'],
                'title'   => $r['title'],
                'qty'     => (int)$r['qty'],
                'revenue' => (float)$r['revenue']
            );
        }
        $stmt->close();

        echo json_encode(array('success' => true, 'totals' => $totals, 'byBooks' => $byBooks), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АВТОР: ЗАЯВКА НА НОВУЮ КНИГУ ---
    if ($action === 'author_request_book') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) { echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit; }
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $author = isset($_POST['author']) ? trim($_POST['author']) : '';
        $genre = isset($_POST['genre']) ? trim($_POST['genre']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        if ($title === '' || $author === '') {
            echo json_encode(array('success' => false, 'message' => 'Укажите название и автора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $stmt = $conn->prepare('INSERT INTO book_requests (user_id, title, author, genre, description, status) VALUES (?, ?, ?, ?, ?, "pending")');
        $stmt->bind_param('issss', $uid, $title, $author, $genre, $description);
        if (!$stmt->execute()) { $stmt->close(); echo json_encode(array('success' => false, 'message' => 'Ошибка сохранения заявки'), JSON_UNESCAPED_UNICODE); exit; }
        $stmt->close();
        echo json_encode(array('success' => true, 'message' => 'Заявка отправлена!'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АВТОР: МОИ ЗАЯВКИ НА КНИГИ ---
    if ($action === 'author_my_requests') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) { echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit; }
        $stmt = $conn->prepare('SELECT id, title, author, genre, description, status, admin_comment, created_at FROM book_requests WHERE user_id = ? ORDER BY id DESC');
        $stmt->bind_param('i', $uid); $stmt->execute();
        $res = $stmt->get_result();
        $list = array();
        while ($r = $res->fetch_assoc()) {
            $list[] = array(
                'id'            => (int)$r['id'],
                'title'         => $r['title'],
                'author'        => $r['author'],
                'genre'         => $r['genre'],
                'description'   => $r['description'],
                'status'        => $r['status'],
                'admin_comment' => $r['admin_comment'],
                'created_at'    => $r['created_at']
            );
        }
        $stmt->close();
        echo json_encode(array('success' => true, 'requests' => $list), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АВТОР: ОТЗЫВЫ НА МОИ КНИГИ ---
    if ($action === 'author_reviews') {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid === 0) { echo json_encode(array('success' => false, 'message' => 'Требуется авторизация'), JSON_UNESCAPED_UNICODE); exit; }
        $stmt = $conn->prepare("
            SELECT r.id, r.book_id, r.rating, r.text, r.created_at,
                   b.title AS book_title,
                   u.name AS user_name, u.email AS user_email
            FROM reviews r
            JOIN books b ON b.id = r.book_id
            LEFT JOIN users u ON u.id = r.user_id
            WHERE b.author_user_id = ? AND r.is_approved = 1
            ORDER BY r.id DESC
        ");
        $stmt->bind_param('i', $uid); $stmt->execute();
        $res = $stmt->get_result();
        $list = array();
        while ($r = $res->fetch_assoc()) {
            $list[] = array(
                'id'         => (int)$r['id'],
                'book_id'    => (int)$r['book_id'],
                'book_title' => $r['book_title'],
                'rating'     => (int)$r['rating'],
                'text'       => $r['text'],
                'user_name'  => $r['user_name'],
                'user_email' => $r['user_email'],
                'created_at' => $r['created_at']
            );
        }
        $stmt->close();
        echo json_encode(array('success' => true, 'reviews' => $list), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ============================================================
    //  АДМИНСКИЕ ЭКШЕНЫ
    // ============================================================

    // --- АДМИН: СПИСОК ВСЕХ ЗАКАЗОВ ---
    if ($action === 'admin_orders') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }

        $stmt = $conn->prepare('
            SELECT o.id, o.user_id, o.total, o.status, o.payment_code, o.created_at,
                   o.customer_name, o.phone, o.address, o.comment,
                   u.name AS user_name, u.email AS user_email, u.nick AS user_nick
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            ORDER BY o.id DESC
        ');
        $stmt->execute();
        $res = $stmt->get_result();
        $orders = array();
        while ($o = $res->fetch_assoc()) {
            $orderId = (int)$o['id'];

            $stmt2 = $conn->prepare('SELECT book_id, title, price, quantity FROM order_items WHERE order_id = ?');
            $stmt2->bind_param('i', $orderId);
            $stmt2->execute();
            $r2 = $stmt2->get_result();
            $items = array();
            while ($it = $r2->fetch_assoc()) {
                $items[] = array(
                    'book_id'  => (int)$it['book_id'],
                    'title'    => $it['title'],
                    'price'    => (float)$it['price'],
                    'quantity' => (int)$it['quantity']
                );
            }
            $stmt2->close();

            $orders[] = array(
                'id'           => $orderId,
                'user_id'      => (int)$o['user_id'],
                'user_name'    => $o['user_name'],
                'user_email'   => $o['user_email'],
                'user_nick'    => $o['user_nick'],
                'total'        => (float)$o['total'],
                'status'       => $o['status'],
                'payment_code' => $o['payment_code'],
                'created_at'   => $o['created_at'],
                'name'         => $o['customer_name'],
                'phone'        => $o['phone'],
                'address'      => $o['address'],
                'comment'      => $o['comment'],
                'items'        => $items
            );
        }
        $stmt->close();

        echo json_encode(array('success' => true, 'orders' => $orders), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: СМЕНИТЬ СТАТУС ЗАКАЗА ---
    if ($action === 'admin_set_status') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $status  = isset($_POST['status'])   ? trim($_POST['status']) : '';
        $allowed = array('pending', 'paid', 'cancelled');
        if ($orderId <= 0 || !in_array($status, $allowed)) {
            echo json_encode(array('success' => false, 'message' => 'Некорректные данные'), JSON_UNESCAPED_UNICODE); exit;
        }
        $stmt = $conn->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $status, $orderId);
        $stmt->execute();
        $affected = $stmt->affected_rows; $stmt->close();

        if ($affected > 0) echo json_encode(array('success' => true, 'message' => 'Статус обновлён'), JSON_UNESCAPED_UNICODE);
        else               echo json_encode(array('success' => false, 'message' => 'Заказ не найден или статус не изменился'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: УДАЛИТЬ ЗАКАЗ ---
    if ($action === 'admin_delete_order') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        if ($orderId <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Не указан заказ'), JSON_UNESCAPED_UNICODE); exit;
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('DELETE FROM order_items WHERE order_id = ?');
            $stmt->bind_param('i', $orderId); $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare('DELETE FROM orders WHERE id = ?');
            $stmt->bind_param('i', $orderId); $stmt->execute();
            $affected = $stmt->affected_rows; $stmt->close();

            $conn->commit();
            if ($affected > 0) echo json_encode(array('success' => true, 'message' => 'Заказ удалён'), JSON_UNESCAPED_UNICODE);
            else               echo json_encode(array('success' => false, 'message' => 'Заказ не найден'), JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            $conn->rollback();
            error_log('admin_delete_order error: ' . $e->getMessage());
            echo json_encode(array('success' => false, 'message' => 'Ошибка удаления заказа'), JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- АДМИН: СПИСОК ПОЛЬЗОВАТЕЛЕЙ ---
    if ($action === 'admin_users') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $res = $conn->query('SELECT id, name, nick, email, phone, role, created_at FROM users ORDER BY id ASC');
        $users = array();
        while ($u = $res->fetch_assoc()) {
            $users[] = array(
                'id'         => (int)$u['id'],
                'name'       => $u['name'],
                'nick'       => $u['nick'],
                'email'      => $u['email'],
                'phone'      => $u['phone'],
                'role'       => $u['role'],
                'created_at' => $u['created_at']
            );
        }
        echo json_encode(array('success' => true, 'users' => $users), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: СМЕНИТЬ РОЛЬ ПОЛЬЗОВАТЕЛЯ ---
    if ($action === 'admin_set_role') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $role   = isset($_POST['role'])    ? trim($_POST['role'])    : '';
        $allowed = array('user', 'author', 'admin');
        if ($userId <= 0 || !in_array($role, $allowed)) {
            echo json_encode(array('success' => false, 'message' => 'Некорректные данные'), JSON_UNESCAPED_UNICODE); exit;
        }
        if ($userId === (int)(isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0)) {
            echo json_encode(array('success' => false, 'message' => 'Нельзя менять свою собственную роль'), JSON_UNESCAPED_UNICODE); exit;
        }
        $stmt = $conn->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->bind_param('si', $role, $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows; $stmt->close();

        if ($affected > 0) echo json_encode(array('success' => true, 'message' => 'Роль обновлена'), JSON_UNESCAPED_UNICODE);
        else               echo json_encode(array('success' => false, 'message' => 'Пользователь не найден или роль не изменилась'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: ДОБАВИТЬ КНИГУ ---
    if ($action === 'admin_add_book') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $title        = isset($_POST['title'])        ? trim($_POST['title'])        : '';
        $author       = isset($_POST['author'])       ? trim($_POST['author'])       : '';
        $genre        = isset($_POST['genre'])        ? trim($_POST['genre'])        : '';
        $description  = isset($_POST['description'])  ? trim($_POST['description'])  : '';
        $price        = isset($_POST['price'])        ? (float)$_POST['price']       : 0;
        $oldPriceRaw  = isset($_POST['old_price'])    ? trim($_POST['old_price'])    : '';
        $discount     = isset($_POST['discount'])     ? (int)$_POST['discount']      : 0;
        $cover        = isset($_POST['cover'])        ? trim($_POST['cover'])        : '';
        $isBestseller = !empty($_POST['is_bestseller']) ? 1 : 0;
        $editorPick   = !empty($_POST['editor_pick'])   ? 1 : 0;
        $authorUserId = isset($_POST['author_user_id']) && $_POST['author_user_id'] !== '' ? (int)$_POST['author_user_id'] : null;

        if ($title === '' || $author === '') {
            echo json_encode(array('success' => false, 'message' => 'Укажите название и автора'), JSON_UNESCAPED_UNICODE); exit;
        }
        if ($price < 0) $price = 0;
        if ($discount < 0) $discount = 0;
        if ($discount > 100) $discount = 100;
        $oldPrice = ($oldPriceRaw === '') ? null : (float)$oldPriceRaw;

        $stmt = $conn->prepare('INSERT INTO books (title, author, genre, description, price, old_price, discount, cover, is_bestseller, editor_pick, author_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('ssssddsiiii', $title, $author, $genre, $description, $price, $oldPrice, $discount, $cover, $isBestseller, $editorPick, $authorUserId);
        if (!$stmt->execute()) {
            $stmt->close();
            echo json_encode(array('success' => false, 'message' => 'Ошибка добавления книги'), JSON_UNESCAPED_UNICODE); exit;
        }
        $newId = $stmt->insert_id; $stmt->close();

        echo json_encode(array('success' => true, 'message' => 'Книга добавлена', 'id' => $newId), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: ИЗМЕНИТЬ КНИГУ ---
    if ($action === 'admin_update_book') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $id           = isset($_POST['id'])           ? (int)$_POST['id']            : 0;
        $title        = isset($_POST['title'])        ? trim($_POST['title'])        : '';
        $author       = isset($_POST['author'])       ? trim($_POST['author'])       : '';
        $genre        = isset($_POST['genre'])        ? trim($_POST['genre'])        : '';
        $description  = isset($_POST['description'])  ? trim($_POST['description'])  : '';
        $price        = isset($_POST['price'])        ? (float)$_POST['price']       : 0;
        $oldPriceRaw  = isset($_POST['old_price'])    ? trim($_POST['old_price'])    : '';
        $discount     = isset($_POST['discount'])     ? (int)$_POST['discount']      : 0;
        $cover        = isset($_POST['cover'])        ? trim($_POST['cover'])        : '';
        $isBestseller = !empty($_POST['is_bestseller']) ? 1 : 0;
        $editorPick   = !empty($_POST['editor_pick'])   ? 1 : 0;
        $authorUserId = isset($_POST['author_user_id']) && $_POST['author_user_id'] !== '' ? (int)$_POST['author_user_id'] : null;

        if ($id <= 0 || $title === '' || $author === '') {
            echo json_encode(array('success' => false, 'message' => 'Некорректные данные'), JSON_UNESCAPED_UNICODE); exit;
        }
        if ($price < 0) $price = 0;
        if ($discount < 0) $discount = 0;
        if ($discount > 100) $discount = 100;
        $oldPrice = ($oldPriceRaw === '') ? null : (float)$oldPriceRaw;

        $stmt = $conn->prepare('UPDATE books SET title=?, author=?, genre=?, description=?, price=?, old_price=?, discount=?, cover=?, is_bestseller=?, editor_pick=?, author_user_id=? WHERE id=?');
        $stmt->bind_param('ssssddsiiiii', $title, $author, $genre, $description, $price, $oldPrice, $discount, $cover, $isBestseller, $editorPick, $authorUserId, $id);
        $stmt->execute();
        $affected = $stmt->affected_rows; $stmt->close();

        echo json_encode(array('success' => true, 'message' => 'Книга обновлена', 'affected' => $affected), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: УДАЛИТЬ КНИГУ ---
    if ($action === 'admin_delete_book') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Не указана книга'), JSON_UNESCAPED_UNICODE); exit;
        }

        $stmt = $conn->prepare('DELETE FROM cart_items WHERE book_id = ?');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();

        $stmt = $conn->prepare('DELETE FROM books WHERE id = ?');
        $stmt->bind_param('i', $id); $stmt->execute();
        $affected = $stmt->affected_rows; $stmt->close();

        if ($affected > 0) echo json_encode(array('success' => true, 'message' => 'Книга удалена'), JSON_UNESCAPED_UNICODE);
        else               echo json_encode(array('success' => false, 'message' => 'Книга не найдена'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: СТАТИСТИКА ---
    if ($action === 'admin_stats') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $totals = array(
            'orders_total'   => 0,
            'orders_pending' => 0,
            'orders_paid'    => 0,
            'orders_cancel'  => 0,
            'revenue_paid'   => 0.0,
            'revenue_all'    => 0.0,
            'users_total'    => 0,
            'books_total'    => 0
        );
        $r = $conn->query('SELECT COUNT(*) AS c FROM orders'); if ($r) { $totals['orders_total'] = (int)$r->fetch_assoc()['c']; }
        $r = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'pending'"); if ($r) { $totals['orders_pending'] = (int)$r->fetch_assoc()['c']; }
        $r = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'paid'"); if ($r) { $totals['orders_paid'] = (int)$r->fetch_assoc()['c']; }
        $r = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'cancelled'"); if ($r) { $totals['orders_cancel'] = (int)$r->fetch_assoc()['c']; }
        $r = $conn->query("SELECT COALESCE(SUM(total),0) AS s FROM orders WHERE status = 'paid'"); if ($r) { $totals['revenue_paid'] = (float)$r->fetch_assoc()['s']; }
        $r = $conn->query("SELECT COALESCE(SUM(total),0) AS s FROM orders WHERE status <> 'cancelled'"); if ($r) { $totals['revenue_all'] = (float)$r->fetch_assoc()['s']; }
        $r = $conn->query('SELECT COUNT(*) AS c FROM users'); if ($r) { $totals['users_total'] = (int)$r->fetch_assoc()['c']; }
        $r = $conn->query('SELECT COUNT(*) AS c FROM books'); if ($r) { $totals['books_total'] = (int)$r->fetch_assoc()['c']; }

        $topBooks = array();
        $r = $conn->query("
            SELECT oi.book_id, oi.title,
                   SUM(oi.quantity) AS qty,
                   SUM(oi.price * oi.quantity) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.status <> 'cancelled'
            GROUP BY oi.book_id, oi.title
            ORDER BY qty DESC
            LIMIT 10
        ");
        if ($r) { while ($row = $r->fetch_assoc()) { $topBooks[] = array('book_id'=>(int)$row['book_id'],'title'=>$row['title'],'qty'=>(int)$row['qty'],'revenue'=>(float)$row['revenue']); } }

        $topAuthors = array();
        $r = $conn->query("
            SELECT b.author,
                   SUM(oi.quantity) AS qty,
                   SUM(oi.price * oi.quantity) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN books b ON b.id = oi.book_id
            WHERE o.status <> 'cancelled'
            GROUP BY b.author
            ORDER BY revenue DESC
            LIMIT 10
        ");
        if ($r) { while ($row = $r->fetch_assoc()) { $topAuthors[] = array('author'=>$row['author'] !== null ? $row['author'] : '—','qty'=>(int)$row['qty'],'revenue'=>(float)$row['revenue']); } }

        $topCustomers = array();
        $r = $conn->query("
            SELECT u.id AS user_id, u.name, u.email,
                   COUNT(o.id) AS orders_count,
                   COALESCE(SUM(o.total),0) AS total
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.status = 'paid'
            GROUP BY u.id, u.name, u.email
            ORDER BY total DESC
            LIMIT 10
        ");
        if ($r) { while ($row = $r->fetch_assoc()) { $topCustomers[] = array('user_id'=>$row['user_id'] !== null ? (int)$row['user_id'] : 0,'name'=>$row['name'] !== null ? $row['name'] : '—','email'=>$row['email'] !== null ? $row['email'] : '—','orders_count'=>(int)$row['orders_count'],'total'=>(float)$row['total']); } }

        echo json_encode(array('success'=>true,'totals'=>$totals,'topBooks'=>$topBooks,'topAuthors'=>$topAuthors,'topCustomers'=>$topCustomers), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: РУКОПИСИ ---
    if ($action === 'admin_manuscripts') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $r = $conn->query('SELECT m.id, m.user_id, m.name, m.email, m.genre, m.synopsis, m.file_name, m.status, m.admin_comment, m.created_at, u.name AS user_name, u.email AS user_email FROM manuscripts m LEFT JOIN users u ON u.id = m.user_id ORDER BY m.id DESC');
        $list = array();
        while ($m = $r->fetch_assoc()) {
            $list[] = array(
                'id'            => (int)$m['id'],
                'user_id'       => (int)$m['user_id'],
                'user_name'     => $m['user_name'],
                'user_email'    => $m['user_email'],
                'name'          => $m['name'],
                'email'         => $m['email'],
                'genre'         => $m['genre'],
                'synopsis'      => $m['synopsis'],
                'file_name'     => $m['file_name'],
                'status'        => $m['status'],
                'admin_comment' => $m['admin_comment'],
                'created_at'    => $m['created_at']
            );
        }
        echo json_encode(array('success' => true, 'manuscripts' => $list), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: ИЗМЕНИТЬ СТАТУС/КОММЕНТАРИЙ РУКОПИСИ ---
    if ($action === 'admin_update_manuscript') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status  = isset($_POST['status']) ? trim($_POST['status']) : '';
        $comment = isset($_POST['admin_comment']) ? trim($_POST['admin_comment']) : '';
        $allowed = array('pending','review','accepted','rejected');
        if ($id <= 0 || !in_array($status, $allowed)) { echo json_encode(array('success' => false, 'message' => 'Некорректные данные'), JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $conn->prepare('UPDATE manuscripts SET status = ?, admin_comment = ? WHERE id = ?');
        $stmt->bind_param('ssi', $status, $comment, $id);
        $stmt->execute(); $stmt->close();
        echo json_encode(array('success' => true, 'message' => 'Рукопись обновлена'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: ЗАЯВКИ НА КНИГИ ---
    if ($action === 'admin_book_requests') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $r = $conn->query('SELECT r.id, r.user_id, r.title, r.author, r.genre, r.description, r.status, r.admin_comment, r.created_at, u.name AS user_name, u.email AS user_email FROM book_requests r LEFT JOIN users u ON u.id = r.user_id ORDER BY r.id DESC');
        $list = array();
        while ($m = $r->fetch_assoc()) {
            $list[] = array(
                'id'            => (int)$m['id'],
                'user_id'       => (int)$m['user_id'],
                'user_name'     => $m['user_name'],
                'user_email'    => $m['user_email'],
                'title'         => $m['title'],
                'author'        => $m['author'],
                'genre'         => $m['genre'],
                'description'   => $m['description'],
                'status'        => $m['status'],
                'admin_comment' => $m['admin_comment'],
                'created_at'    => $m['created_at']
            );
        }
        echo json_encode(array('success' => true, 'requests' => $list), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- АДМИН: ОБРАБОТАТЬ ЗАЯВКУ НА КНИГУ ---
    if ($action === 'admin_update_book_request') {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            echo json_encode(array('success' => false, 'message' => 'Доступ только для администратора'), JSON_UNESCAPED_UNICODE); exit;
        }
        $id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status  = isset($_POST['status']) ? trim($_POST['status']) : '';
        $comment = isset($_POST['admin_comment']) ? trim($_POST['admin_comment']) : '';
        $allowed = array('pending','approved','rejected');
        if ($id <= 0 || !in_array($status, $allowed)) { echo json_encode(array('success' => false, 'message' => 'Некорректные данные'), JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $conn->prepare('UPDATE book_requests SET status = ?, admin_comment = ? WHERE id = ?');
        $stmt->bind_param('ssi', $status, $comment, $id);
        $stmt->execute(); $stmt->close();
        echo json_encode(array('success' => true, 'message' => 'Заявка обновлена'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(array('success' => false, 'message' => 'Неизвестное действие'), JSON_UNESCAPED_UNICODE);
    exit;
}

$isLoggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['role']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Книжный червь — современное издательство</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="header">
    <div class="container-custom header-container">
        <div class="logo">
            <img src="logo.png" alt="Логотип">
            <span>Книжный червь</span>
        </div>
        <div class="user-widget">
            <div id="authButtonGroup">
                <button class="btn-custom btn-primary-custom" id="openAuthModalBtn" data-bs-toggle="modal" data-bs-target="#accountModal">Вход / Регистрация</button>
            </div>
            <div id="userInfoArea" style="display: none;" class="d-flex align-items-center gap-3">
                <span id="userNameSpan" class="fw-bold text-white"></span>
                <div class="user-avatar" id="userAvatarBtn" data-bs-toggle="modal" data-bs-target="#accountModal" style="cursor:pointer;"><i class="fas fa-user-edit"></i></div>
            </div>
        </div>
    </div>
</header>

<nav class="navbar">
    <div class="container-custom">
        <ul>
            <li><a href="#home">Главная</a></li>
            <li><a href="#about">О нас</a></li>
            <li><a href="#bestsellers">Бестселлеры</a></li>
            <li><a href="#catalog">Каталог</a></li>
            <li><a href="#editorsChoice">Выбор редакции</a></li>
            <li><a href="#promo">Акции</a></li>
            <li><a href="#cart">Корзина</a></li>
            <li><a href="#manuscript">Рукопись</a></li>
            <li><a href="#contacts">Контакты</a></li>
        </ul>
    </div>
</nav>

<main>
    <section id="home" class="section" style="padding-top: 20px;">
        <div class="container-custom">
            <div id="newsCarousel" class="carousel slide carousel-fade" data-bs-ride="carousel" data-bs-interval="4000">
                <div class="carousel-indicators">
                    <button type="button" data-bs-target="#newsCarousel" data-bs-slide-to="0" class="active"></button>
                    <button type="button" data-bs-target="#newsCarousel" data-bs-slide-to="1"></button>
                    <button type="button" data-bs-target="#newsCarousel" data-bs-slide-to="2"></button>
                </div>
                <div class="carousel-inner rounded-4 shadow-lg">
                    <div class="carousel-item active">
                        <img src="10.png" class="d-block w-100" alt="Новинка">
                        <div class="carousel-caption d-none d-md-block bg-dark bg-opacity-50 rounded-3">
                            <h5>Литературная премия 2026</h5>
                            <p>Книга «Принцесса Ардена» — финалист</p>
                        </div>
                    </div>
                    <div class="carousel-item">
                        <img src="11.png" class="d-block w-100" alt="Ярмарка">
                        <div class="carousel-caption d-none d-md-block bg-dark bg-opacity-50 rounded-3">
                            <h5>Встреча с авторами</h5>
                            <p>29 апреля литературный вечер</p>
                        </div>
                    </div>
                    <div class="carousel-item">
                        <img src="12.png" class="d-block w-100" alt="Скидки">
                        <div class="carousel-caption d-none d-md-block bg-dark bg-opacity-50 rounded-3">
                            <h5>Скидка 25%</h5>
                            <p>Весь апрель</p>
                        </div>
                    </div>
                </div>
                <button class="carousel-control-prev" type="button" data-bs-target="#newsCarousel" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon"></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#newsCarousel" data-bs-slide="next">
                    <span class="carousel-control-next-icon"></span>
                </button>
            </div>

            <div class="row mt-5 g-4">
                <div class="col-md-3 col-6"><div class="stat-item"><i class="fas fa-book-open fa-3x" style="color: var(--accent-burgundy);"></i><h3 class="mt-2">500+</h3><p>изданных книг</p></div></div>
                <div class="col-md-3 col-6"><div class="stat-item"><i class="fas fa-users fa-3x" style="color: var(--accent-burgundy);"></i><h3 class="mt-2">120+</h3><p>авторов</p></div></div>
                <div class="col-md-3 col-6"><div class="stat-item"><i class="fas fa-trophy fa-3x" style="color: var(--accent-burgundy);"></i><h3 class="mt-2">35</h3><p>литературных премий</p></div></div>
                <div class="col-md-3 col-6"><div class="stat-item"><i class="fas fa-globe fa-3x" style="color: var(--accent-burgundy);"></i><h3 class="mt-2">15</h3><p>стран-партнеров</p></div></div>
            </div>
        </div>
    </section>

    <section id="about" class="section bg-light">
        <div class="container-custom">
            <h2 class="section-title">О издательстве</h2>
            <div class="row align-items-center">
                <div class="col-md-7">
                    <p class="fs-5">«Книжный червь» — издательство с 15-летней историей. Мы выпускаем более 50 книг в год.</p>
                    <p>Ежемесячно проводим встречи с писателями, конкурсы рукописей и книжные ярмарки.</p>
                </div>
                <div class="col-md-5"><img src="16.png" class="img-fluid rounded-4 shadow" alt="Типография"></div>
            </div>
        </div>
    </section>

    <section id="bestsellers" class="section">
        <div class="container-custom">
            <h2 class="section-title">Книги-бестселлеры</h2>
            <div class="swiper bestsellerSwiper">
                <div class="swiper-wrapper" id="bestsellerSlider"></div>
                <div class="swiper-pagination"></div>
                <div class="swiper-button-next"></div>
                <div class="swiper-button-prev"></div>
            </div>
        </div>
    </section>

    <section id="catalog" class="section bg-white">
        <div class="container-custom">
            <h2 class="section-title">Каталог книг</h2>

            <div class="catalog-filters mb-4 p-3 bg-light rounded-4 shadow-sm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold mb-1">Жанр</label>
                        <select id="filterGenre" class="form-select">
                            <option value="">Все жанры</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold mb-1">
                            Цена: <span id="priceRangeLabel">0 – 0 ₽</span>
                        </label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="number" id="filterPriceMin" class="form-control form-control-sm"
                                   min="0" step="10" placeholder="от">
                            <span>—</span>
                            <input type="number" id="filterPriceMax" class="form-control form-control-sm"
                                   min="0" step="10" placeholder="до">
                        </div>
                        <input type="range" id="filterPriceRange" class="form-range mt-2"
                               min="0" max="1000" step="10" value="1000">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold mb-1">Сортировка</label>
                        <div class="d-flex gap-2">
                            <select id="filterSort" class="form-select">
                                <option value="default">По умолчанию</option>
                                <option value="price-asc">Цена ↑</option>
                                <option value="price-desc">Цена ↓</option>
                                <option value="title-asc">Название А–Я</option>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" id="filterResetBtn"
                                    title="Сбросить фильтры">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-3 d-flex flex-wrap gap-2" id="activeFilters"></div>
            </div>

            <div class="text-muted small mb-2" id="catalogCount"></div>

            <div class="books-grid" id="booksGrid"></div>
            <div class="text-center p-4 text-muted d-none" id="catalogEmpty">
                <i class="fas fa-search fa-3x mb-3"></i>
                <p>По вашим фильтрам ничего не найдено</p>
            </div>
        </div>
    </section>

    <section id="editorsChoice" class="section bg-light">
        <div class="container-custom">
            <h2 class="section-title">Выбор редакции</h2>
            <div class="row" id="editorsChoiceGrid"></div>
        </div>
    </section>

    <section id="promo" class="section">
        <div class="container-custom">
            <h2 class="section-title">Текущие акции</h2>
            <div class="row g-4" id="promoContainer"></div>
        </div>
    </section>

    <section id="cart" class="section bg-white">
        <div class="container-custom">
            <h2 class="section-title">Корзина</h2>
            <div id="cartContainer" class="bg-light p-4 rounded-4 shadow-sm">Загрузка...</div>
        </div>
    </section>

    <section id="manuscript" class="section">
        <div class="container-custom">
            <h2 class="section-title">Отправить рукопись</h2>
            <div class="rules-list">
                <i class="fas fa-info-circle me-2" style="color:var(--accent-burgundy)"></i>
                <strong>Правила приёма рукописей:</strong>
                <ul class="mt-2 mb-0">
                    <li>Объём: от 5 до 25 авторских листов.</li>
                    <li>Форматы: .doc, .docx, .pdf, .txt.</li>
                    <li>Жанры: проза, детектив, фантастика, нон-фикшн, поэзия.</li>
                    <li>Рукопись должна быть оригинальной.</li>
                    <li>Приложите синопсис и краткую биографию.</li>
                    <li>Срок рассмотрения — до 30 дней.</li>
                </ul>
            </div>
            <form id="manuscriptForm" class="p-4 bg-white rounded-4 shadow-sm" enctype="multipart/form-data">
                <input type="text"  id="msName"     class="form-control mb-3" placeholder="Имя / псевдоним" required>
                <input type="email" id="msEmail"    class="form-control mb-3" placeholder="Email для связи" required>
                <select             id="msGenre"    class="form-select mb-3">
                    <option>Детектив</option><option>Фантастика</option><option>Поэзия</option><option>Нон-фикшн</option>
                </select>
                <input type="file" name="manuscript_file" id="msFile" class="form-control mb-3" accept=".doc,.docx,.pdf,.txt">
                <textarea           id="msSynopsis" class="form-control mb-3" rows="3" placeholder="Краткое описание (синопсис)"></textarea>
                <div class="text-danger small mb-2" id="msError"></div>
                <div class="text-success small mb-2" id="msSuccess"></div>
                <button type="submit" class="btn btn-primary-custom w-100">Отправить рукопись</button>
            </form>
        </div>
    </section>

    <section id="contacts" class="section bg-light">
        <div class="container-custom">
            <h2 class="section-title">Контакты</h2>
            <div class="p-4 bg-white rounded-4 shadow-sm">
                <p><i class="fas fa-map-marker-alt"></i> Иркутск, ул. Ленина 10</p>
                <p><i class="fas fa-phone"></i> +7 (495) 123-45-67</p>
                <p><i class="fas fa-envelope"></i> info@knizhny-cherv.ru</p>
                <p><i class="fab fa-telegram"></i> Telegram-канал: @knizhny_cherv</p>
            </div>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="container-custom">
        <div class="row">
            <div class="col-md-4 mb-4"><h5><i class="fas fa-book"></i> Книжный червь</h5><p>Издательство современной литературы. Любим книги с 2010 года.</p></div>
            <div class="col-md-4 mb-4"><h5>Контакты</h5><p>Email: editor@knizhny-cherv.ru<br>Тел: +7 (495) 765-43-21<br>Для авторов: manuscript@knizhny-cherv.ru</p></div>
            <div class="col-md-4 mb-4">
                <h5>Мы в соцсетях</h5>
                <div class="social-icons">
                    <a href="#" target="_blank"><i class="fab fa-vk"></i></a>
                    <a href="#" target="_blank"><i class="fab fa-telegram"></i></a>
                    <a href="#" target="_blank"><i class="fab fa-odnoklassniki"></i></a>
                    <a href="#" target="_blank"><i class="fab fa-yandex"></i></a>
                </div>
            </div>
        </div>
        <div class="text-center pt-3 border-top border-secondary"><p>&copy; 2026 Издательство «Книжный червь». Все права защищены.</p></div>
    </div>
</footer>

<!-- Модальное окно аккаунта -->
<div class="modal fade" id="accountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Личный кабинет</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#loginTab">Вход</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#regTab">Регистрация</button></li>
                    <li class="nav-item" id="profileTabLi" style="display:none;"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#profileTab">Профиль</button></li>
                </ul>
                <div class="tab-content mt-3">
                    <div class="tab-pane fade show active" id="loginTab">
                        <input type="email" id="loginEmail" class="form-control mb-2" placeholder="Email">
                        <input type="password" id="loginPass" class="form-control mb-3" placeholder="Пароль">
                        <div class="text-danger small mb-2" id="loginError"></div>
                        <button class="btn btn-primary-custom w-100" id="doLoginBtn">Войти</button>
                        <hr>
                        <button class="btn btn-outline-secondary w-100 mt-2" id="adminDemoBtn">Администратор</button>
                        <button class="btn btn-outline-secondary w-100 mt-2" id="authorDemoBtn">Автор (Иван Петров)</button>
                    </div>
                    <div class="tab-pane fade" id="regTab">
                        <div id="regStep1">
                            <input type="text" id="regName" class="form-control mb-2" placeholder="Имя Фамилия">
                            <input type="text" id="regNick" class="form-control mb-2" placeholder="Никнейм">
                            <input type="email" id="regEmail" class="form-control mb-2" placeholder="Email">
                            <input type="tel" id="regPhone" class="form-control mb-2" placeholder="Телефон">
                            <input type="password" id="regPass" class="form-control mb-3" placeholder="Пароль">
                            <div class="text-danger small mb-2" id="regError"></div>
                            <button class="btn btn-primary-custom w-100" id="doRegBtn">Зарегистрироваться</button>
                        </div>
                        <div id="regStep2" style="display:none;">
                            <p class="small text-muted mb-2">Мы отправили 6-значный код на <b id="regCodeEmail"></b>.</p>
                            <input type="text" id="regCode" class="form-control mb-3" placeholder="Код из письма" maxlength="6" inputmode="numeric">
                            <div class="text-danger small mb-2" id="regCodeError"></div>
                            <button class="btn btn-primary-custom w-100" id="doConfirmCodeBtn">Подтвердить</button>
                            <button class="btn btn-outline-secondary w-100 mt-2" id="regBackBtn">Назад</button>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="profileTab">
                        <div class="profile-card" id="profileInfoContainer"></div>

                        <!-- Общие настройки профиля -->
                        <div id="settingsPanel" style="display:none;">
                            <hr>
                            <h6 class="mt-3 mb-2"><i class="fas fa-user-cog me-1"></i> Настройки профиля</h6>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <input type="text" id="setName" class="form-control form-control-sm" placeholder="Имя">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" id="setNick" class="form-control form-control-sm" placeholder="Ник">
                                </div>
                                <div class="col-md-4">
                                    <input type="tel" id="setPhone" class="form-control form-control-sm" placeholder="Телефон">
                                </div>
                            </div>
                            <div class="text-danger small mt-2" id="setError"></div>
                            <div class="text-success small mt-2" id="setSuccess"></div>
                            <button class="btn btn-sm btn-primary-custom mt-2" id="saveProfileBtn">Сохранить</button>

                            <hr>
                            <h6 class="mt-2 mb-2"><i class="fas fa-key me-1"></i> Смена пароля</h6>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <input type="password" id="oldPass" class="form-control form-control-sm" placeholder="Текущий пароль">
                                </div>
                                <div class="col-md-6">
                                    <input type="password" id="newPass" class="form-control form-control-sm" placeholder="Новый пароль">
                                </div>
                            </div>
                            <div class="text-danger small mt-2" id="pwdError"></div>
                            <div class="text-success small mt-2" id="pwdSuccess"></div>
                            <button class="btn btn-sm btn-outline-secondary mt-2" id="changePwdBtn">Сменить пароль</button>
                        </div>

                        <hr>
                        <h6 class="mt-3 mb-2" id="myOrdersHeader"><i class="fas fa-box-open me-1"></i> Мои заказы</h6>
                        <div id="myOrdersContainer" class="small">Загрузка…</div>

                        <!-- Панель автора -->
                        <div id="authorPanel" style="display:none;">
                            <hr>
                            <h6 class="mt-3 mb-2"><i class="fas fa-feather-alt me-1"></i> Кабинет автора</h6>

                            <ul class="nav nav-pills mb-3" id="authorTabs">
                                <li class="nav-item">
                                    <button class="nav-link active" data-author-tab="manuscripts">Мои рукописи</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-author-tab="books">Мои книги</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-author-tab="stats">Статистика</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-author-tab="requests">Заявки на книги</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-author-tab="reviews">Отзывы</button>
                                </li>
                            </ul>

                            <!-- Мои рукописи -->
                            <div id="authorManuscriptsPane">
                                <button class="btn btn-sm btn-primary-custom mb-2" id="authorShowMsFormBtn">
                                    <i class="fas fa-plus"></i> Отправить рукопись
                                </button>

                                <div id="authorMsForm" class="border rounded-3 p-3 mb-3 bg-light" style="display:none;">
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <input type="text" id="amsName" class="form-control form-control-sm" placeholder="Имя / псевдоним *">
                                        </div>
                                        <div class="col-md-6">
                                            <input type="email" id="amsEmail" class="form-control form-control-sm" placeholder="Email *">
                                        </div>
                                        <div class="col-md-4">
                                            <select id="amsGenre" class="form-select form-select-sm">
                                                <option>Детектив</option>
                                                <option>Фантастика</option>
                                                <option>Поэзия</option>
                                                <option>Нон-фикшн</option>
                                                <option>Проза</option>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <input type="file" id="amsFile" class="form-control form-control-sm" accept=".doc,.docx,.pdf,.txt">
                                        </div>
                                        <div class="col-12">
                                            <textarea id="amsSynopsis" class="form-control form-control-sm" rows="3" placeholder="Синопсис *"></textarea>
                                        </div>
                                    </div>
                                    <div class="text-danger small mt-2" id="amsError"></div>
                                    <div class="text-success small mt-2" id="amsSuccess"></div>
                                    <div class="mt-2 d-flex gap-2">
                                        <button class="btn btn-sm btn-primary-custom" id="amsSubmitBtn">Отправить</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="amsCancelBtn">Отмена</button>
                                    </div>
                                </div>

                                <div id="authorManuscriptsContainer" class="small">Загрузка…</div>
                            </div>

                            <!-- Мои книги -->
                            <div id="authorBooksPane" style="display:none;">
                                <div id="authorBooksContainer" class="small">Загрузка…</div>
                            </div>

                            <!-- Статистика -->
                            <div id="authorStatsPane" style="display:none;">
                                <div id="authorStatsContainer" class="small">Загрузка…</div>
                            </div>

                            <!-- Заявки на книги -->
                            <div id="authorRequestsPane" style="display:none;">
                                <button class="btn btn-sm btn-primary-custom mb-2" id="authorShowReqFormBtn">
                                    <i class="fas fa-plus"></i> Новая заявка
                                </button>

                                <div id="authorReqForm" class="border rounded-3 p-3 mb-3 bg-light" style="display:none;">
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <input type="text" id="areqTitle" class="form-control form-control-sm" placeholder="Название *">
                                        </div>
                                        <div class="col-md-6">
                                            <input type="text" id="areqAuthor" class="form-control form-control-sm" placeholder="Автор *">
                                        </div>
                                        <div class="col-md-4">
                                            <input type="text" id="areqGenre" class="form-control form-control-sm" placeholder="Жанр">
                                        </div>
                                        <div class="col-md-8">
                                            <input type="text" id="areqDescription" class="form-control form-control-sm" placeholder="Краткое описание">
                                        </div>
                                    </div>
                                    <div class="text-danger small mt-2" id="areqError"></div>
                                    <div class="text-success small mt-2" id="areqSuccess"></div>
                                    <div class="mt-2 d-flex gap-2">
                                        <button class="btn btn-sm btn-primary-custom" id="areqSubmitBtn">Отправить</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="areqCancelBtn">Отмена</button>
                                    </div>
                                </div>

                                <div id="authorRequestsContainer" class="small">Загрузка…</div>
                            </div>

                            <!-- Отзывы -->
                            <div id="authorReviewsPane" style="display:none;">
                                <div id="authorReviewsContainer" class="small">Загрузка…</div>
                            </div>
                        </div>

                        <!-- Админ-блок -->
                        <div id="adminPanel" style="display:none;">
                            <hr>
                            <h6 class="mt-3 mb-2"><i class="fas fa-shield-alt me-1"></i> Админ-панель</h6>

                            <ul class="nav nav-pills mb-3" id="adminTabs">
                                <li class="nav-item">
                                    <button class="nav-link active" data-admin-tab="orders">Все заказы</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-admin-tab="users">Пользователи</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-admin-tab="books">Книги</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-admin-tab="stats">Статистика</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-admin-tab="manuscripts">Рукописи</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-admin-tab="requests">Заявки</button>
                                </li>
                            </ul>

                            <div id="adminOrdersPane">
                                <div class="mb-2 d-flex gap-2 align-items-center">
                                    <label class="small text-muted mb-0">Статус:</label>
                                    <select id="adminOrderStatusFilter" class="form-select form-select-sm" style="width:auto;">
                                        <option value="">Все</option>
                                        <option value="pending">Ожидают оплаты</option>
                                        <option value="paid">Оплачены</option>
                                        <option value="cancelled">Отменены</option>
                                    </select>
                                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="adminOrdersRefreshBtn">
                                        <i class="fas fa-sync"></i> Обновить
                                    </button>
                                </div>
                                <div id="adminOrdersContainer" class="small">Загрузка…</div>
                            </div>

                            <div id="adminUsersPane" style="display:none;">
                                <div class="mb-2 d-flex">
                                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="adminUsersRefreshBtn">
                                        <i class="fas fa-sync"></i> Обновить
                                    </button>
                                </div>
                                <div id="adminUsersContainer" class="small">Загрузка…</div>
                            </div>

                            <div id="adminBooksPane" style="display:none;">
                                <div class="mb-2 d-flex gap-2">
                                    <button class="btn btn-sm btn-primary-custom" id="adminBookAddBtn">
                                        <i class="fas fa-plus"></i> Добавить книгу
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="adminBooksRefreshBtn">
                                        <i class="fas fa-sync"></i> Обновить
                                    </button>
                                </div>

                                <div id="adminBookForm" class="border rounded-3 p-3 mb-3 bg-light" style="display:none;">
                                    <h6 class="mb-3" id="adminBookFormTitle">Новая книга</h6>
                                    <input type="hidden" id="abId">
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <input type="text" id="abTitle" class="form-control form-control-sm" placeholder="Название *">
                                        </div>
                                        <div class="col-md-6">
                                            <input type="text" id="abAuthor" class="form-control form-control-sm" placeholder="Автор *">
                                        </div>
                                        <div class="col-md-6">
                                            <input type="text" id="abGenre" class="form-control form-control-sm" placeholder="Жанр">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" id="abPrice" class="form-control form-control-sm" placeholder="Цена, ₽" step="0.01" min="0">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" id="abOldPrice" class="form-control form-control-sm" placeholder="Старая цена" step="0.01" min="0">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" id="abDiscount" class="form-control form-control-sm" placeholder="Скидка, %" min="0" max="100">
                                        </div>
                                        <div class="col-md-9">
                                            <input type="text" id="abCover" class="form-control form-control-sm" placeholder="Файл обложки (например, 1.png)">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" id="abAuthorUserId" class="form-control form-control-sm" placeholder="ID автора-пользователя">
                                        </div>
                                        <div class="col-12">
                                            <textarea id="abDescription" class="form-control form-control-sm" rows="2" placeholder="Описание"></textarea>
                                        </div>
                                        <div class="col-md-6 d-flex align-items-center gap-3">
                                            <label class="small mb-0"><input type="checkbox" id="abBestseller"> Бестселлер</label>
                                            <label class="small mb-0"><input type="checkbox" id="abEditor"> Выбор редакции</label>
                                        </div>
                                    </div>
                                    <div class="text-danger small mt-2" id="abError"></div>
                                    <div class="mt-3 d-flex gap-2">
                                        <button class="btn btn-sm btn-primary-custom" id="abSaveBtn">Сохранить</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="abCancelBtn">Отмена</button>
                                    </div>
                                </div>

                                <div id="adminBooksContainer" class="small">Загрузка…</div>
                            </div>

                            <div id="adminStatsPane" style="display:none;">
                                <div class="mb-2 d-flex">
                                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="adminStatsRefreshBtn">
                                        <i class="fas fa-sync"></i> Обновить
                                    </button>
                                </div>
                                <div id="adminStatsContainer" class="small">Загрузка…</div>
                            </div>

                            <div id="adminManuscriptsPane" style="display:none;">
                                <div class="mb-2 d-flex">
                                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="adminManuscriptsRefreshBtn">
                                        <i class="fas fa-sync"></i> Обновить
                                    </button>
                                </div>
                                <div id="adminManuscriptsContainer" class="small">Загрузка…</div>
                            </div>

                            <div id="adminRequestsPane" style="display:none;">
                                <div class="mb-2 d-flex">
                                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="adminRequestsRefreshBtn">
                                        <i class="fas fa-sync"></i> Обновить
                                    </button>
                                </div>
                                <div id="adminRequestsContainer" class="small">Загрузка…</div>
                            </div>
                        </div>

                        <button class="btn btn-danger mt-3 w-100" id="logoutFromModalBtn">Выйти</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно книги -->
<div class="modal fade" id="bookModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Детали книги</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bookModalBody"></div>
            <div class="modal-footer">
                <button class="btn btn-primary-custom" id="modalAddToCartBtn">В корзину</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно оформления заказа -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Оформление заказа</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">ФИО получателя *</label>
          <input type="text" id="coName" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Телефон *</label>
          <input type="tel" id="coPhone" class="form-control" placeholder="+7 (___) ___-__-__" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Адрес доставки *</label>
          <textarea id="coAddress" class="form-control" rows="2" required></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Комментарий к заказу</label>
          <textarea id="coComment" class="form-control" rows="2"></textarea>
        </div>
        <div class="text-danger small mb-2" id="coError"></div>
        <div class="alert alert-info py-2 mb-0">
          <b>Итого к оплате: <span id="coTotal">0</span> ₽</b>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button class="btn btn-primary-custom" id="coSubmitBtn">Оформить заказ</button>
      </div>
    </div>
  </div>
</div>

<!-- Модальное окно оплаты с QR -->
<div class="modal fade" id="paymentModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center">
      <div class="modal-header">
        <h5 class="modal-title">Оплата заказа</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">Номер заказа:</p>
        <h6 class="mb-3" id="payOrderId"></h6>
        <p class="mb-1">К оплате:</p>
        <h3 class="text-danger mb-3"><span id="payTotal">0</span> ₽</h3>
        <div id="qrContainer" class="d-inline-block p-3 bg-white border rounded-3 mb-3"></div>
        <p class="small text-muted">Отсканируйте QR-код в приложении банка.<br>Платёжный код: <code id="payCode"></code></p>
        <div class="alert alert-success d-none" id="paySuccessAlert">
          <i class="fas fa-check-circle"></i> Оплата получена! Спасибо за заказ.
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <button class="btn btn-outline-secondary" id="payCheckBtn">Проверить оплату</button>
        <button class="btn btn-primary-custom" id="paySimulateBtn">Оплатить (демо)</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<script>
(function () {
  var API = window.location.pathname;

  function send(data) {
    var body = new URLSearchParams(data);
    return fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'include'
    }).then(function (r) { return r.text(); })
      .then(function (t) {
        try { return JSON.parse(t); }
        catch (e) { console.error('Не JSON:', t); return { success: false, message: 'Ошибка сервера' }; }
      });
  }
  function $(id) { return document.getElementById(id); }

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ============= АВТОРИЗАЦИЯ =============
  var currentUser = null;

  function setLoggedInUI(user) {
    $('authButtonGroup').style.display = 'none';
    $('userInfoArea').style.display    = 'flex';
    $('userNameSpan').textContent      = user.name || user.nick || user.email || 'Профиль';
    $('profileTabLi').style.display    = '';
  }
  function setLoggedOutUI() {
    $('authButtonGroup').style.display = '';
    $('userInfoArea').style.display    = 'none';
    $('profileTabLi').style.display    = 'none';
    $('profileInfoContainer').innerHTML = '';
    if ($('myOrdersContainer')) $('myOrdersContainer').innerHTML = '';
    if ($('adminPanel')) $('adminPanel').style.display = 'none';
    if ($('authorPanel')) $('authorPanel').style.display = 'none';
    if ($('settingsPanel')) $('settingsPanel').style.display = 'none';
    currentUser = null;
    cart = [];
    saveCartLocal();
    renderCart();
  }
  function isAuthed() {
    return $('userInfoArea').style.display !== 'none';
  }

  $('doRegBtn').addEventListener('click', function () {
    $('regError').textContent = '';
    send({
      action: 'register_send_code',
      name: $('regName').value.trim(), nick: $('regNick').value.trim(),
      email: $('regEmail').value.trim(), phone: $('regPhone').value.trim(),
      password: $('regPass').value
    }).then(function (res) {
      if (!res.success) { $('regError').textContent = res.message; return; }
      $('regCodeEmail').textContent = $('regEmail').value.trim();
      $('regStep1').style.display = 'none';
      $('regStep2').style.display = '';
      $('regCode').value = '';
      $('regCodeError').textContent = '';
      $('regCode').focus();
    });
  });

  $('doConfirmCodeBtn').addEventListener('click', function () {
    $('regCodeError').textContent = '';
    send({
      action: 'register_confirm',
      email: $('regEmail').value.trim(),
      code: $('regCode').value.trim()
    }).then(function (res) {
      if (!res.success) { $('regCodeError').textContent = res.message; return; }
      refreshProfile();
      $('regStep1').style.display = '';
      $('regStep2').style.display = 'none';
      var modal = bootstrap.Modal.getInstance($('accountModal'));
      if (modal) modal.hide();
    });
  });

  $('regBackBtn').addEventListener('click', function () {
    $('regStep1').style.display = '';
    $('regStep2').style.display = 'none';
    $('regCodeError').textContent = '';
  });

  $('doLoginBtn').addEventListener('click', function () {
    $('loginError').textContent = '';
    send({ action: 'login', email: $('loginEmail').value.trim(), password: $('loginPass').value }).then(function (res) {
      if (!res.success) { $('loginError').textContent = res.message; return; }
      refreshProfile();
    });
  });

  $('adminDemoBtn').addEventListener('click', function () { send({ action: 'demo', role: 'admin' }).then(refreshProfile); });
  $('authorDemoBtn').addEventListener('click', function () { send({ action: 'demo', role: 'author' }).then(refreshProfile); });

  function refreshProfile() {
    return send({ action: 'profile' }).then(function (res) {
      if (!res.authenticated) { setLoggedOutUI(); return; }
      var u = res.user;
      currentUser = u;

      var roles = { user: 'Пользователь', author: 'Автор', admin: 'Администратор' };
      var role  = roles[u.role] || u.role;

      setLoggedInUI(u);
      $('profileInfoContainer').innerHTML =
        '<div class="p-3">' +
          '<h5 class="mb-2">' + escapeHtml(u.name || '') + '</h5>' +
          '<p class="mb-1"><b>Ник:</b> ' + escapeHtml(u.nick || '—') + '</p>' +
          '<p class="mb-1"><b>Email:</b> ' + escapeHtml(u.email || '—') + '</p>' +
          '<p class="mb-1"><b>Телефон:</b> ' + escapeHtml(u.phone || '—') + '</p>' +
          '<p class="mb-1"><b>Роль:</b> ' + escapeHtml(role) + '</p>' +
          '<p class="text-muted small mb-0">Регистрация: ' + escapeHtml(u.created_at || '') + '</p>' +
        '</div>';

      // Настройки (для не-демо)
      if (u.id > 0) {
        $('settingsPanel').style.display = '';
        $('setName').value = u.name || '';
        $('setNick').value = u.nick || '';
        $('setPhone').value = u.phone || '';
      } else {
        $('settingsPanel').style.display = 'none';
      }

      var tab = document.querySelector('[data-bs-target="#profileTab"]');
      if (tab && window.bootstrap) new bootstrap.Tab(tab).show();

      loadCartFromServer();
      renderMyOrders();
      renderAdminPanel(u.role);
      renderAuthorPanel(u.role);
    });
  }

  $('logoutFromModalBtn').addEventListener('click', function () {
    send({ action: 'logout' }).then(function () {
      setLoggedOutUI();
      var tab = document.querySelector('[data-bs-target="#loginTab"]');
      if (tab && window.bootstrap) new bootstrap.Tab(tab).show();
    });
  });

  $('accountModal').addEventListener('show.bs.modal', refreshProfile);

  // ---- Настройки профиля ----
  $('saveProfileBtn').addEventListener('click', function () {
    $('setError').textContent = ''; $('setSuccess').textContent = '';
    send({
      action: 'update_profile',
      name: $('setName').value.trim(),
      nick: $('setNick').value.trim(),
      phone: $('setPhone').value.trim()
    }).then(function (res) {
      if (!res.success) { $('setError').textContent = res.message; return; }
      $('setSuccess').textContent = res.message;
      refreshProfile();
    });
  });

  $('changePwdBtn').addEventListener('click', function () {
    $('pwdError').textContent = ''; $('pwdSuccess').textContent = '';
    send({
      action: 'change_password',
      old_password: $('oldPass').value,
      new_password: $('newPass').value
    }).then(function (res) {
      if (!res.success) { $('pwdError').textContent = res.message; return; }
      $('pwdSuccess').textContent = res.message;
      $('oldPass').value = ''; $('newPass').value = '';
    });
  });

  // ============= МОИ ЗАКАЗЫ =============
  function renderMyOrders() {
    var cont = $('myOrdersContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';

    send({ action: 'my_orders' }).then(function (res) {
      if (!res.success) {
        cont.innerHTML = '<div class="text-muted">' + escapeHtml(res.message || 'Нет заказов') + '</div>';
        return;
      }
      if (!res.orders || res.orders.length === 0) {
        cont.innerHTML = '<div class="text-muted">У вас пока нет заказов.</div>';
        return;
      }

      var statuses = {
        pending:   '<span class="badge bg-warning text-dark">Ожидает оплаты</span>',
        paid:      '<span class="badge bg-success">Оплачен</span>',
        cancelled: '<span class="badge bg-secondary">Отменён</span>'
      };

      cont.innerHTML = res.orders.map(function (o) {
        var itemsHtml = o.items.map(function (i) {
          return '<li>' + escapeHtml(i.title) + ' × ' + i.quantity + ' — ' + (i.price * i.quantity) + ' ₽</li>';
        }).join('');

        var buttons = '';
        if (o.status === 'pending') {
          buttons =
            '<button class="btn btn-sm btn-primary-custom mt-2 me-2" data-paycode="' + o.payment_code + '" data-paytotal="' + o.total + '" data-payid="' + o.id + '">Оплатить</button>' +
            '<button class="btn btn-sm btn-outline-danger mt-2" data-cancelcode="' + o.payment_code + '">Отменить</button>';
        }

        return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
          '<div class="d-flex justify-content-between mb-1">' +
            '<b>Заказ #' + o.id + '</b>' +
            '<span>' + (statuses[o.status] || o.status) + '</span>' +
          '</div>' +
          '<div class="text-muted mb-1">' + escapeHtml(o.created_at) + ' · Код: <code>' + escapeHtml(o.payment_code) + '</code></div>' +
          '<ul class="mb-1 ps-3">' + itemsHtml + '</ul>' +
          '<div class="fw-bold">Итого: ' + o.total + ' ₽</div>' +
          buttons +
        '</div>';
      }).join('');

      cont.querySelectorAll('button[data-paycode]').forEach(function (b) {
        b.addEventListener('click', function () {
          showPayment(
            parseInt(b.dataset.payid, 10),
            parseFloat(b.dataset.paytotal),
            b.dataset.paycode
          );
        });
      });

      cont.querySelectorAll('button[data-cancelcode]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('Отменить заказ ' + b.dataset.cancelcode + '?')) return;
          b.disabled = true;
          send({ action: 'cancel_order', payment_code: b.dataset.cancelcode }).then(function (res) {
            if (!res.success) {
              alert(res.message || 'Не удалось отменить заказ');
              b.disabled = false;
              return;
            }
            renderMyOrders();
          });
        });
      });
    });
  }

  // ============= ОТПРАВКА РУКОПИСИ (общая форма) =============
  $('manuscriptForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var errEl = $('msError'), okEl = $('msSuccess');
    errEl.textContent = ''; okEl.textContent = '';

    var fd = new FormData();
    fd.append('action', 'manuscript');
    fd.append('name', $('msName').value.trim());
    fd.append('email', $('msEmail').value.trim());
    fd.append('genre', $('msGenre').value);
    fd.append('synopsis', $('msSynopsis').value.trim());

    var fileInput = $('msFile');
    if (fileInput && fileInput.files.length > 0) fd.append('manuscript_file', fileInput.files[0]);

    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        var res;
        try { res = JSON.parse(t); } catch (e) { console.error('Не JSON:', t); res = { success: false, message: 'Ошибка сервера' }; }
        if (res.success) { okEl.textContent = res.message; $('manuscriptForm').reset(); }
        else { errEl.textContent = res.message; }
      });
  });

  // ============= КНИГИ И КАТАЛОГ =============
  var BOOKS = [];
  var cart  = JSON.parse(localStorage.getItem('bookCart') || '[]');

  function saveCartLocal() {
    localStorage.setItem('bookCart', JSON.stringify(cart));
  }
  function syncCartToServer() {
    if (!isAuthed()) return Promise.resolve();
    return send({
      action: 'cart_save',
      cart: JSON.stringify(cart.map(function (i) { return { id: i.id, quantity: i.quantity }; }))
    });
  }
  function loadCartFromServer() {
    if (!isAuthed()) return Promise.resolve();
    return send({ action: 'cart_get' }).then(function (res) {
      if (res.success && Array.isArray(res.items)) {
        cart = res.items;
        saveCartLocal();
        renderCart();
      }
    });
  }
  function loadBooks() {
    return send({ action: 'get_books' }).then(function (res) {
      if (res.success) BOOKS = res.books;
    });
  }

  function addToCart(bookId) {
    var book = BOOKS.find(function (b) { return b.id === bookId; });
    if (!book) return;
    var existing = cart.find(function (i) { return i.id === bookId; });
    if (existing) existing.quantity += 1;
    else cart.push({ id: book.id, title: book.title, author: book.author, price: book.price, cover: book.cover, quantity: 1 });
    saveCartLocal();
    renderCart();
    syncCartToServer();
    alert('«' + book.title + '» добавлена в корзину');
  }
  window.changeQty = function (id, delta) {
    var idx = cart.findIndex(function (i) { return i.id === id; });
    if (idx === -1) return;
    cart[idx].quantity += delta;
    if (cart[idx].quantity <= 0) cart.splice(idx, 1);
    saveCartLocal(); renderCart(); syncCartToServer();
  };
  window.removeCartItem = function (id) {
    cart = cart.filter(function (i) { return i.id !== id; });
    saveCartLocal(); renderCart(); syncCartToServer();
  };
  window.clearCart = function () {
    if (!confirm('Очистить корзину?')) return;
    cart = []; saveCartLocal(); renderCart(); syncCartToServer();
  };

  function renderCart() {
    var cont = $('cartContainer');
    if (!cont) return;
    if (cart.length === 0) {
      cont.innerHTML = '<div class="text-center p-4 text-muted"><i class="fas fa-shopping-cart fa-3x mb-3"></i><p>Корзина пуста</p></div>';
      return;
    }
    var total = 0, html = '';
    cart.forEach(function (i) {
      total += i.price * i.quantity;
      html += '<div class="cart-item d-flex align-items-center gap-3 border-bottom py-3">' +
        '<img src="' + escapeHtml(i.cover) + '" style="width:50px;height:70px;object-fit:cover;border-radius:6px" alt="">' +
        '<div class="flex-grow-1"><div class="fw-bold">' + escapeHtml(i.title) + '</div><div class="text-muted small">' + escapeHtml(i.author) + '</div></div>' +
        '<div class="d-flex align-items-center">' +
          '<button class="btn btn-sm btn-outline-secondary" onclick="changeQty(' + i.id + ',-1)">−</button>' +
          '<span class="mx-2">' + i.quantity + '</span>' +
          '<button class="btn btn-sm btn-outline-secondary" onclick="changeQty(' + i.id + ',1)">+</button>' +
        '</div>' +
        '<div style="min-width:90px" class="text-end fw-bold">' + (i.price * i.quantity) + ' ₽</div>' +
        '<button class="btn btn-sm btn-outline-danger" onclick="removeCartItem(' + i.id + ')"><i class="fas fa-trash"></i></button>' +
      '</div>';
    });
    html += '<div class="d-flex justify-content-between align-items-center mt-4">' +
              '<button class="btn btn-outline-secondary" onclick="clearCart()">Очистить корзину</button>' +
              '<div class="text-end">' +
                '<div class="fs-4 fw-bold">Итого: ' + total + ' ₽</div>' +
                '<button class="btn btn-primary-custom mt-2" id="goCheckoutBtn"><i class="fas fa-credit-card me-1"></i> Оформить заказ</button>' +
              '</div>' +
            '</div>';
    cont.innerHTML = html;
    $('goCheckoutBtn').addEventListener('click', startCheckout);
  }

  function startCheckout() {
    if (!isAuthed()) {
      alert('Оформить заказ может только авторизованный пользователь.');
      new bootstrap.Modal($('accountModal')).show();
      return;
    }
    if (cart.length === 0) { alert('Корзина пуста'); return; }
    var total = cart.reduce(function (s, i) { return s + i.price * i.quantity; }, 0);
    $('coTotal').textContent = total;
    $('coError').textContent = '';
    new bootstrap.Modal($('checkoutModal')).show();
  }

  $('coSubmitBtn').addEventListener('click', function () {
    var err = $('coError'); err.textContent = '';
    var name = $('coName').value.trim(),
        phone = $('coPhone').value.trim(),
        address = $('coAddress').value.trim(),
        comment = $('coComment').value.trim();

    if (!name || !phone || !address) { err.textContent = 'Заполните обязательные поля'; return; }

    send({
      action: 'checkout',
      name: name, phone: phone, address: address, comment: comment,
      cart: JSON.stringify(cart.map(function (i) { return { id: i.id, quantity: i.quantity }; }))
    }).then(function (res) {
      if (!res.success) { err.textContent = res.message; return; }

      var checkoutEl  = $('checkoutModal');
      var checkoutMod = bootstrap.Modal.getInstance(checkoutEl);

      if (document.activeElement && checkoutEl.contains(document.activeElement)) {
        document.activeElement.blur();
      }

      function onCheckoutHidden() {
        checkoutEl.removeEventListener('hidden.bs.modal', onCheckoutHidden);

        cart = [];
        saveCartLocal();
        renderCart();
        showPayment(res.order_id, res.total, res.payment_code);
      }
      checkoutEl.addEventListener('hidden.bs.modal', onCheckoutHidden);

      if (checkoutMod) checkoutMod.hide();
    });
  });

  // ============= ОПЛАТА (QR) =============
  var currentPaymentCode = null;

  function showPayment(orderId, total, code) {
    currentPaymentCode = code;
    $('payOrderId').textContent = '#' + orderId;
    $('payTotal').textContent   = total;
    $('payCode').textContent    = code;
    $('paySuccessAlert').classList.add('d-none');
    $('qrContainer').innerHTML  = '';

    var modalEl = $('paymentModal');
    var modal   = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    modalEl.addEventListener('shown.bs.modal', function onShown() {
        modalEl.removeEventListener('shown.bs.modal', onShown);

        if (typeof QRCode === 'undefined') {
            $('qrContainer').innerHTML =
              '<div class="text-danger small p-2">Библиотека QR не загружена.</div>';
            return;
        }

        var baseUrl = window.location.origin + window.location.pathname;
        var qrText  = baseUrl + '?pay=' + encodeURIComponent(code) + '&sum=' + Math.round(total * 100);

        if (qrText.length > 1000) {
            qrText = 'PAY:' + code + ':' + Math.round(total * 100);
        }

        try {
            new QRCode($('qrContainer'), {
                text: qrText,
                width: 220,
                height: 220,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: (QRCode.CorrectLevel && QRCode.CorrectLevel.M) || 0
            });
        } catch (e) {
            console.error('QR generation error:', e);
            $('qrContainer').innerHTML =
              '<div class="text-danger small p-2">Не удалось сгенерировать QR:<br>' +
              escapeHtml(e.message) + '</div>';
        }
    });
  }

  $('payCheckBtn').addEventListener('click', function () {
    if (!currentPaymentCode) return;
    send({ action: 'order_status', payment_code: currentPaymentCode }).then(function (res) {
      if (!res.success) { alert(res.message); return; }
      if (res.order.status === 'paid') {
        $('paySuccessAlert').classList.remove('d-none');
        renderMyOrders();
      } else if (res.order.status === 'cancelled') {
        alert('Заказ отменён.');
        var m = bootstrap.Modal.getInstance($('paymentModal'));
        if (m) m.hide();
        renderMyOrders();
      } else {
        alert('Оплата ещё не поступила. Попробуйте позже.');
      }
    });
  });

  $('paySimulateBtn').addEventListener('click', function () {
    if (!currentPaymentCode) return;
    send({ action: 'pay_order', payment_code: currentPaymentCode }).then(function (res) {
      if (res.success) {
        $('paySuccessAlert').classList.remove('d-none');
        renderMyOrders();
      } else {
        alert(res.message);
      }
    });
  });

  // ============= ФИЛЬТРЫ КАТАЛОГА =============
  var filters = { genre: '', priceMin: null, priceMax: null, sort: 'default' };
  var priceBounds = { min: 0, max: 0 };

  function initFilterControls() {
    var genreSelect = $('filterGenre');
    if (genreSelect) {
      var genres = [];
      BOOKS.forEach(function (b) {
        if (b.genre && genres.indexOf(b.genre) === -1) genres.push(b.genre);
      });
      genres.sort();
      genreSelect.innerHTML = '<option value="">Все жанры</option>' +
        genres.map(function (g) {
          return '<option value="' + escapeHtml(g) + '">' + escapeHtml(g) + '</option>';
        }).join('');
    }

    if (BOOKS.length > 0) {
      var prices = BOOKS.map(function (b) { return b.price; });
      priceBounds.min = Math.floor(Math.min.apply(null, prices));
      priceBounds.max = Math.ceil(Math.max.apply(null, prices));
    } else {
      priceBounds.min = 0;
      priceBounds.max = 1000;
    }

    var range = $('filterPriceRange');
    if (range) {
      range.min = priceBounds.min;
      range.max = priceBounds.max;
      range.step = 10;
      range.value = priceBounds.max;
    }

    var minInput = $('filterPriceMin');
    var maxInput = $('filterPriceMax');
    if (minInput) minInput.placeholder = 'от ' + priceBounds.min;
    if (maxInput) maxInput.placeholder = 'до ' + priceBounds.max;

    updatePriceLabel();
  }

  function updatePriceLabel() {
    var label = $('priceRangeLabel');
    if (!label) return;
    var min = filters.priceMin !== null ? filters.priceMin : priceBounds.min;
    var max = filters.priceMax !== null ? filters.priceMax : priceBounds.max;
    label.textContent = min + ' – ' + max + ' ₽';
  }

  function getFilteredBooks() {
    var min = filters.priceMin !== null ? filters.priceMin : -Infinity;
    var max = filters.priceMax !== null ? filters.priceMax : Infinity;

    var list = BOOKS.filter(function (b) {
      if (filters.genre && b.genre !== filters.genre) return false;
      if (b.price < min || b.price > max) return false;
      return true;
    });

    if (filters.sort === 'price-asc') {
      list.sort(function (a, b) { return a.price - b.price; });
    } else if (filters.sort === 'price-desc') {
      list.sort(function (a, b) { return b.price - a.price; });
    } else if (filters.sort === 'title-asc') {
      list.sort(function (a, b) { return a.title.localeCompare(b.title, 'ru'); });
    }

    return list;
  }

  function renderActiveFilters() {
    var cont = $('activeFilters');
    if (!cont) return;
    var chips = [];

    if (filters.genre) {
      chips.push('<span class="badge bg-secondary d-inline-flex align-items-center gap-2">Жанр: ' +
        escapeHtml(filters.genre) + '<button type="button" class="btn-close btn-close-white btn-sm" data-clear="genre"></button></span>');
    }
    if (filters.priceMin !== null || filters.priceMax !== null) {
      var mn = filters.priceMin !== null ? filters.priceMin : priceBounds.min;
      var mx = filters.priceMax !== null ? filters.priceMax : priceBounds.max;
      chips.push('<span class="badge bg-secondary d-inline-flex align-items-center gap-2">Цена: ' +
        mn + '–' + mx + ' ₽<button type="button" class="btn-close btn-close-white btn-sm" data-clear="price"></button></span>');
    }

    cont.innerHTML = chips.join('');

    cont.querySelectorAll('button[data-clear]').forEach(function (b) {
      b.addEventListener('click', function () {
        var what = b.dataset.clear;
        if (what === 'genre') {
          filters.genre = '';
          $('filterGenre').value = '';
        } else if (what === 'price') {
          filters.priceMin = null;
          filters.priceMax = null;
          if ($('filterPriceMin')) $('filterPriceMin').value = '';
          if ($('filterPriceMax')) $('filterPriceMax').value = '';
          if ($('filterPriceRange')) $('filterPriceRange').value = priceBounds.max;
          updatePriceLabel();
        }
        renderCatalog();
      });
    });
  }

  function bindFilterEvents() {
    var genreSelect = $('filterGenre');
    if (genreSelect) {
      genreSelect.addEventListener('change', function () {
        filters.genre = genreSelect.value;
        renderCatalog();
      });
    }

    var range = $('filterPriceRange');
    if (range) {
      range.addEventListener('input', function () {
        filters.priceMax = parseInt(range.value, 10);
        if (filters.priceMax >= priceBounds.max) filters.priceMax = null;
        if ($('filterPriceMax')) {
          $('filterPriceMax').value = filters.priceMax !== null ? filters.priceMax : '';
        }
        updatePriceLabel();
        renderCatalog();
      });
    }

    var minInput = $('filterPriceMin');
    if (minInput) {
      minInput.addEventListener('input', function () {
        var v = minInput.value.trim();
        filters.priceMin = v === '' ? null : Math.max(0, parseInt(v, 10) || 0);
        renderCatalog();
      });
    }

    var maxInput = $('filterPriceMax');
    if (maxInput) {
      maxInput.addEventListener('input', function () {
        var v = maxInput.value.trim();
        filters.priceMax = v === '' ? null : Math.max(0, parseInt(v, 10) || 0);
        updatePriceLabel();
        renderCatalog();
      });
    }

    var sortSelect = $('filterSort');
    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        filters.sort = sortSelect.value;
        renderCatalog();
      });
    }

    var resetBtn = $('filterResetBtn');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        filters = { genre: '', priceMin: null, priceMax: null, sort: 'default' };
        if ($('filterGenre')) $('filterGenre').value = '';
        if ($('filterPriceMin')) $('filterPriceMin').value = '';
        if ($('filterPriceMax')) $('filterPriceMax').value = '';
        if ($('filterPriceRange')) $('filterPriceRange').value = priceBounds.max;
        if ($('filterSort')) $('filterSort').value = 'default';
        updatePriceLabel();
        renderCatalog();
      });
    }
  }

  function renderCatalog() {
    var grid = $('booksGrid');
    var empty = $('catalogEmpty');
    var counter = $('catalogCount');
    if (!grid) return;

    if (BOOKS.length === 0) {
      grid.innerHTML = '<div class="text-center p-4">Загрузка каталога…</div>';
      if (empty) empty.classList.add('d-none');
      if (counter) counter.textContent = '';
      return;
    }

    var books = getFilteredBooks();

    if (counter) counter.textContent = 'Найдено книг: ' + books.length + ' из ' + BOOKS.length;

    if (books.length === 0) {
      grid.innerHTML = '';
      if (empty) empty.classList.remove('d-none');
      renderActiveFilters();
      return;
    }
    if (empty) empty.classList.add('d-none');

    grid.innerHTML = books.map(function (book) {
      return '<div class="book-card">' +
        '<div class="book-cover" style="background-image:url(\'' + escapeHtml(book.cover) + '\')"></div>' +
        '<div class="book-info">' +
          '<div class="book-title">' + escapeHtml(book.title) + '</div>' +
          '<div class="book-author">' + escapeHtml(book.author) + '</div>' +
          '<div>' +
            (book.oldPrice ? '<span class="old-price">' + book.oldPrice + ' ₽</span> ' : '') +
            '<span class="book-price">' + book.price + ' ₽</span>' +
            (book.discount > 0 ? '<span class="discount-badge">-' + book.discount + '%</span>' : '') +
          '</div>' +
          '<button class="btn-cart w-100 mt-2 view-detail-btn" data-id="' + book.id + '">Подробнее</button>' +
          '<button class="btn btn-sm btn-gold w-100 mt-2 add-cart-btn" data-id="' + book.id + '">В корзину</button>' +
        '</div></div>';
    }).join('');

    grid.querySelectorAll('.add-cart-btn').forEach(function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); addToCart(parseInt(b.dataset.id, 10)); });
    });
    grid.querySelectorAll('.view-detail-btn').forEach(function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); openBookModal(parseInt(b.dataset.id, 10)); });
    });

    renderActiveFilters();
  }

  function renderBestsellers() {
    var wrapper = $('bestsellerSlider');
    if (!wrapper) return;
    var bestsellers = BOOKS.filter(function (b) { return b.isBestseller; });
    wrapper.innerHTML = bestsellers.map(function (book) {
      return '<div class="swiper-slide"><div class="book-card h-100">' +
        '<div class="book-cover" style="background-image:url(\'' + escapeHtml(book.cover) + '\')"></div>' +
        '<div class="book-info">' +
          '<div class="book-title">' + escapeHtml(book.title) + '</div>' +
          '<div class="book-author">' + escapeHtml(book.author) + '</div>' +
          '<div>' +
            (book.oldPrice ? '<span class="old-price">' + book.oldPrice + ' ₽</span> ' : '') +
            '<span class="book-price">' + book.price + ' ₽</span>' +
            (book.discount > 0 ? '<span class="discount-badge">-' + book.discount + '%</span>' : '') +
          '</div>' +
          '<button class="btn-cart w-100 mt-2 add-cart-swiper" data-id="' + book.id + '">В корзину</button>' +
          '<button class="btn btn-sm btn-outline-secondary w-100 mt-2 view-detail-swiper" data-id="' + book.id + '">Подробнее</button>' +
        '</div></div></div>';
    }).join('');

    wrapper.querySelectorAll('.add-cart-swiper').forEach(function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); addToCart(parseInt(b.dataset.id, 10)); });
    });
    wrapper.querySelectorAll('.view-detail-swiper').forEach(function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); openBookModal(parseInt(b.dataset.id, 10)); });
    });

    if (window.__swiper) { try { window.__swiper.destroy(true, true); } catch (e) {} }
    window.__swiper = new Swiper('.bestsellerSwiper', {
      slidesPerView: 1, spaceBetween: 20, loop: true,
      pagination: { el: '.swiper-pagination', clickable: true },
      navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' },
      breakpoints: { 640: { slidesPerView: 2 }, 1024: { slidesPerView: 4 } }
    });
  }

  function renderEditorsChoice() {
    var container = $('editorsChoiceGrid');
    if (!container) return;
    var picks = BOOKS.filter(function (b) { return b.editorPick; });
    container.innerHTML = picks.map(function (book) {
      return '<div class="col-md-4 mb-4"><div class="book-card h-100">' +
        '<div class="book-cover" style="background-image:url(\'' + escapeHtml(book.cover) + '\')"></div>' +
        '<div class="book-info">' +
          '<div class="book-title">' + escapeHtml(book.title) + '</div>' +
          '<div class="book-author">' + escapeHtml(book.author) + '</div>' +
          '<p class="small mt-2">' + escapeHtml((book.desc || '').substring(0, 80)) + '…</p>' +
          '<div class="book-price">' + book.price + ' ₽</div>' +
          '<button class="btn-cart w-100 add-cart-choice" data-id="' + book.id + '">В корзину</button>' +
          '<button class="btn btn-sm btn-outline-secondary w-100 mt-2 view-detail-choice" data-id="' + book.id + '">Подробнее</button>' +
        '</div></div></div>';
    }).join('');

    container.querySelectorAll('.add-cart-choice').forEach(function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); addToCart(parseInt(b.dataset.id, 10)); });
    });
    container.querySelectorAll('.view-detail-choice').forEach(function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); openBookModal(parseInt(b.dataset.id, 10)); });
    });
  }

  function openBookModal(id) {
    var book = BOOKS.find(function (b) { return b.id === id; });
    if (!book) return;
    $('bookModalBody').innerHTML =
      '<div class="row"><div class="col-md-4">' +
        '<img src="' + escapeHtml(book.cover) + '" class="img-fluid rounded" style="max-height:300px;width:auto;object-fit:cover" alt="">' +
      '</div><div class="col-md-8">' +
        '<h3>' + escapeHtml(book.title) + '</h3>' +
        '<p><strong>' + escapeHtml(book.author) + '</strong></p>' +
        '<p>' + escapeHtml(book.desc || '') + '</p>' +
        '<p>' +
          (book.oldPrice ? '<s>' + book.oldPrice + ' ₽</s> ' : '') +
          '<strong class="text-danger fs-3">' + book.price + ' ₽</strong>' +
          (book.discount ? '<span class="discount-badge">-' + book.discount + '%</span>' : '') +
        '</p>' +
      '</div></div>';
    window.__currentBookModalId = id;
    new bootstrap.Modal($('bookModal')).show();
  }
  $('modalAddToCartBtn').addEventListener('click', function () {
    if (window.__currentBookModalId) addToCart(window.__currentBookModalId);
  });

  // ============= АВТОР-ПАНЕЛЬ =============
  function renderAuthorPanel(role) {
    var panel = $('authorPanel');
    if (!panel) return;
    if (role !== 'author' && role !== 'admin') {
      panel.style.display = 'none';
      return;
    }
    panel.style.display = '';

    var tabs = document.querySelectorAll('#authorTabs [data-author-tab]');
    tabs.forEach(function (btn) {
      if (btn.__bound) return;
      btn.__bound = true;
      btn.addEventListener('click', function () {
        tabs.forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        var which = btn.dataset.authorTab;
        $('authorManuscriptsPane').style.display = which === 'manuscripts' ? '' : 'none';
        $('authorBooksPane').style.display       = which === 'books'       ? '' : 'none';
        $('authorStatsPane').style.display       = which === 'stats'       ? '' : 'none';
        $('authorRequestsPane').style.display    = which === 'requests'    ? '' : 'none';
        $('authorReviewsPane').style.display     = which === 'reviews'     ? '' : 'none';

        if (which === 'manuscripts') loadAuthorManuscripts();
        else if (which === 'books')  loadAuthorBooks();
        else if (which === 'stats')  loadAuthorStats();
        else if (which === 'requests') loadAuthorRequests();
        else if (which === 'reviews') loadAuthorReviews();
      });
    });

    // Формы
    var showMsBtn = $('authorShowMsFormBtn');
    if (showMsBtn && !showMsBtn.__bound) {
      showMsBtn.__bound = true;
      showMsBtn.addEventListener('click', function () {
        $('authorMsForm').style.display = '';
        if (currentUser) {
          $('amsName').value = currentUser.name || '';
          $('amsEmail').value = currentUser.email && currentUser.email !== 'demo@local' ? currentUser.email : '';
        }
      });
    }
    var msCancel = $('amsCancelBtn');
    if (msCancel && !msCancel.__bound) {
      msCancel.__bound = true;
      msCancel.addEventListener('click', function () { $('authorMsForm').style.display = 'none'; });
    }
    var msSubmit = $('amsSubmitBtn');
    if (msSubmit && !msSubmit.__bound) {
      msSubmit.__bound = true;
      msSubmit.addEventListener('click', submitAuthorManuscript);
    }

    var showReqBtn = $('authorShowReqFormBtn');
    if (showReqBtn && !showReqBtn.__bound) {
      showReqBtn.__bound = true;
      showReqBtn.addEventListener('click', function () {
        $('authorReqForm').style.display = '';
        if (currentUser) $('areqAuthor').value = currentUser.name || '';
      });
    }
    var reqCancel = $('areqCancelBtn');
    if (reqCancel && !reqCancel.__bound) {
      reqCancel.__bound = true;
      reqCancel.addEventListener('click', function () { $('authorReqForm').style.display = 'none'; });
    }
    var reqSubmit = $('areqSubmitBtn');
    if (reqSubmit && !reqSubmit.__bound) {
      reqSubmit.__bound = true;
      reqSubmit.addEventListener('click', submitAuthorRequest);
    }

    loadAuthorManuscripts();
  }

  function submitAuthorManuscript() {
    var errEl = $('amsError'), okEl = $('amsSuccess');
    errEl.textContent = ''; okEl.textContent = '';
    var fd = new FormData();
    fd.append('action', 'author_submit_manuscript');
    fd.append('name', $('amsName').value.trim());
    fd.append('email', $('amsEmail').value.trim());
    fd.append('genre', $('amsGenre').value);
    fd.append('synopsis', $('amsSynopsis').value.trim());
    if ($('amsFile').files.length > 0) fd.append('manuscript_file', $('amsFile').files[0]);

    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        var res;
        try { res = JSON.parse(t); } catch (e) { res = { success: false, message: 'Ошибка сервера' }; }
        if (!res.success) { errEl.textContent = res.message; return; }
        okEl.textContent = res.message;
        $('amsName').value = ''; $('amsEmail').value = '';
        $('amsSynopsis').value = ''; $('amsFile').value = '';
        loadAuthorManuscripts();
        setTimeout(function () { $('authorMsForm').style.display = 'none'; }, 1000);
      });
  }

  function loadAuthorManuscripts() {
    var cont = $('authorManuscriptsContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'author_my_manuscripts' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      if (!res.manuscripts || res.manuscripts.length === 0) {
        cont.innerHTML = '<div class="text-muted">Рукописей нет.</div>'; return;
      }
      var statuses = {
        pending:  '<span class="badge bg-warning text-dark">На рассмотрении</span>',
        review:   '<span class="badge bg-info text-dark">На рецензии</span>',
        accepted: '<span class="badge bg-success">Принята</span>',
        rejected: '<span class="badge bg-secondary">Отклонена</span>'
      };
      cont.innerHTML = res.manuscripts.map(function (m) {
        return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
          '<div class="d-flex justify-content-between mb-1">' +
            '<b>Рукопись #' + m.id + '</b>' +
            '<span>' + (statuses[m.status] || m.status) + '</span>' +
          '</div>' +
          '<div class="text-muted small mb-1">' + escapeHtml(m.created_at) + ' · ' + escapeHtml(m.genre || '—') + '</div>' +
          '<div class="small mb-1"><b>Имя:</b> ' + escapeHtml(m.name) + ' · <b>Email:</b> ' + escapeHtml(m.email) + '</div>' +
          (m.file_name ? '<div class="small mb-1"><i class="fas fa-paperclip"></i> ' + escapeHtml(m.file_name) + '</div>' : '') +
          '<div class="small mb-1">' + escapeHtml(m.synopsis) + '</div>' +
          (m.admin_comment ? '<div class="small alert alert-info py-1 px-2 mb-1"><b>Комментарий редактора:</b> ' + escapeHtml(m.admin_comment) + '</div>' : '') +
          (m.status === 'pending'
            ? '<button class="btn btn-sm btn-outline-danger mt-1" data-ms-del="' + m.id + '"><i class="fas fa-trash"></i> Удалить</button>'
            : '') +
        '</div>';
      }).join('');

      cont.querySelectorAll('button[data-ms-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('Удалить рукопись #' + b.dataset.msDel + '?')) return;
          b.disabled = true;
          send({ action: 'author_delete_manuscript', id: b.dataset.msDel }).then(function (res) {
            if (!res.success) { alert(res.message || 'Не удалось удалить'); b.disabled = false; return; }
            loadAuthorManuscripts();
          });
        });
      });
    });
  }

  function loadAuthorBooks() {
    var cont = $('authorBooksContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'author_my_books' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      if (!res.books || res.books.length === 0) {
        cont.innerHTML = '<div class="text-muted">За вами пока не закреплено ни одной книги.</div>'; return;
      }
      cont.innerHTML = res.books.map(function (b) {
        return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
          '<div class="d-flex gap-3">' +
            '<img src="' + escapeHtml(b.cover) + '" style="width:50px;height:70px;object-fit:cover;border-radius:6px" alt="">' +
            '<div class="flex-grow-1">' +
              '<div class="d-flex justify-content-between">' +
                '<b>' + escapeHtml(b.title) + '</b>' +
                '<span class="text-muted small">ID: ' + b.id + '</span>' +
              '</div>' +
              '<div class="small text-muted mb-1">' + escapeHtml(b.author) + ' · ' + escapeHtml(b.genre || '—') + '</div>' +
              '<div class="small mb-2">Цена: <b>' + b.price + ' ₽</b>' +
                (b.oldPrice ? ' <s class="text-muted">' + b.oldPrice + ' ₽</s>' : '') +
                (b.discount > 0 ? ' · скидка ' + b.discount + '%' : '') +
              '</div>' +
              '<button class="btn btn-sm btn-outline-primary" data-ab-edit="' + b.id + '"><i class="fas fa-pen"></i> Редактировать</button>' +
            '</div>' +
          '</div>' +
          '<div class="border rounded-3 p-2 mt-2 bg-white" data-ab-form="' + b.id + '" style="display:none;">' +
            '<div class="row g-2">' +
              '<div class="col-md-6"><input type="text" class="form-control form-control-sm" data-ab-field="title" value="' + escapeHtml(b.title) + '" placeholder="Название"></div>' +
              '<div class="col-md-6"><input type="text" class="form-control form-control-sm" data-ab-field="author" value="' + escapeHtml(b.author) + '" placeholder="Автор"></div>' +
              '<div class="col-md-4"><input type="text" class="form-control form-control-sm" data-ab-field="genre" value="' + escapeHtml(b.genre || '') + '" placeholder="Жанр"></div>' +
              '<div class="col-md-8"><input type="text" class="form-control form-control-sm" data-ab-field="cover" value="' + escapeHtml(b.cover || '') + '" placeholder="Файл обложки"></div>' +
              '<div class="col-12"><textarea class="form-control form-control-sm" data-ab-field="description" rows="2" placeholder="Описание">' + escapeHtml(b.desc || '') + '</textarea></div>' +
            '</div>' +
            '<div class="text-danger small mt-2" data-ab-error></div>' +
            '<div class="mt-2 d-flex gap-2">' +
              '<button class="btn btn-sm btn-primary-custom" data-ab-save="' + b.id + '">Сохранить</button>' +
              '<button class="btn btn-sm btn-outline-secondary" data-ab-cancel="' + b.id + '">Отмена</button>' +
            '</div>' +
          '</div>' +
        '</div>';
      }).join('');

      cont.querySelectorAll('button[data-ab-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.dataset.abEdit;
          var f = cont.querySelector('[data-ab-form="' + id + '"]');
          if (f) f.style.display = f.style.display === 'none' ? '' : 'none';
        });
      });
      cont.querySelectorAll('button[data-ab-cancel]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.dataset.abCancel;
          var f = cont.querySelector('[data-ab-form="' + id + '"]');
          if (f) f.style.display = 'none';
        });
      });
      cont.querySelectorAll('button[data-ab-save]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.dataset.abSave;
          var f = cont.querySelector('[data-ab-form="' + id + '"]');
          if (!f) return;
          var errEl = f.querySelector('[data-ab-error]');
          errEl.textContent = '';
          var get = function (name) {
            var el = f.querySelector('[data-ab-field="' + name + '"]');
            return el ? el.value.trim() : '';
          };
          send({
            action: 'author_update_book',
            id: id,
            title: get('title'),
            author: get('author'),
            genre: get('genre'),
            description: get('description'),
            cover: get('cover')
          }).then(function (res) {
            if (!res.success) { errEl.textContent = res.message || 'Ошибка'; return; }
            loadAuthorBooks();
            loadBooks().then(function () {
              initFilterControls();
              renderCatalog();
              renderBestsellers();
              renderEditorsChoice();
            });
          });
        });
      });
    });
  }

  function loadAuthorStats() {
    var cont = $('authorStatsContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'author_stats' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      var t = res.totals || {};
      var html = '';
      html += '<div class="row g-2 mb-3">';
      html += '<div class="col-6 col-md-4"><div class="border rounded-3 p-2 bg-light"><div class="small text-muted">Книг закреплено</div><div class="fw-bold" style="font-size:1.1rem">' + (t.books_count || 0) + '</div></div></div>';
      html += '<div class="col-6 col-md-4"><div class="border rounded-3 p-2 bg-light"><div class="small text-muted">Продано экз.</div><div class="fw-bold" style="font-size:1.1rem">' + (t.qty || 0) + '</div></div></div>';
      html += '<div class="col-12 col-md-4"><div class="border rounded-3 p-2 bg-light"><div class="small text-muted">Выручка</div><div class="fw-bold text-danger" style="font-size:1.1rem">' + (t.revenue || 0) + ' ₽</div></div></div>';
      html += '</div>';

      if (!res.byBooks || res.byBooks.length === 0) {
        html += '<div class="text-muted small">Пока нет продаж ваших книг.</div>';
      } else {
        html += '<div class="table-responsive"><table class="table table-sm table-striped">' +
          '<thead><tr><th>#</th><th>Книга</th><th class="text-end">Экз.</th><th class="text-end">Выручка</th></tr></thead><tbody>';
        res.byBooks.forEach(function (b, i) {
          html += '<tr><td>' + (i + 1) + '</td>' +
            '<td>' + escapeHtml(b.title) + ' <span class="text-muted small">(ID ' + b.book_id + ')</span></td>' +
            '<td class="text-end">' + b.qty + '</td>' +
            '<td class="text-end">' + b.revenue + ' ₽</td></tr>';
        });
        html += '</tbody></table></div>';
      }
      cont.innerHTML = html;
    });
  }

  function submitAuthorRequest() {
    var errEl = $('areqError'), okEl = $('areqSuccess');
    errEl.textContent = ''; okEl.textContent = '';
    send({
      action: 'author_request_book',
      title: $('areqTitle').value.trim(),
      author: $('areqAuthor').value.trim(),
      genre: $('areqGenre').value.trim(),
      description: $('areqDescription').value.trim()
    }).then(function (res) {
      if (!res.success) { errEl.textContent = res.message; return; }
      okEl.textContent = res.message;
      $('areqTitle').value = ''; $('areqGenre').value = ''; $('areqDescription').value = '';
      loadAuthorRequests();
      setTimeout(function () { $('authorReqForm').style.display = 'none'; }, 1000);
    });
  }

  function loadAuthorRequests() {
    var cont = $('authorRequestsContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'author_my_requests' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      if (!res.requests || res.requests.length === 0) {
        cont.innerHTML = '<div class="text-muted">Заявок нет.</div>'; return;
      }
      var statuses = {
        pending:  '<span class="badge bg-warning text-dark">На рассмотрении</span>',
        approved: '<span class="badge bg-success">Одобрена</span>',
        rejected: '<span class="badge bg-secondary">Отклонена</span>'
      };
      cont.innerHTML = res.requests.map(function (r) {
        return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
          '<div class="d-flex justify-content-between mb-1">' +
            '<b>' + escapeHtml(r.title) + '</b>' +
            '<span>' + (statuses[r.status] || r.status) + '</span>' +
          '</div>' +
          '<div class="text-muted small mb-1">' + escapeHtml(r.created_at) + ' · ' + escapeHtml(r.genre || '—') + '</div>' +
          '<div class="small mb-1"><b>Автор:</b> ' + escapeHtml(r.author) + '</div>' +
          (r.description ? '<div class="small mb-1">' + escapeHtml(r.description) + '</div>' : '') +
          (r.admin_comment ? '<div class="small alert alert-info py-1 px-2 mb-0"><b>Комментарий:</b> ' + escapeHtml(r.admin_comment) + '</div>' : '') +
        '</div>';
      }).join('');
    });
  }

  function loadAuthorReviews() {
    var cont = $('authorReviewsContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'author_reviews' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      if (!res.reviews || res.reviews.length === 0) {
        cont.innerHTML = '<div class="text-muted">Отзывов на ваши книги пока нет.</div>'; return;
      }
      cont.innerHTML = res.reviews.map(function (r) {
        var stars = '';
        for (var i = 0; i < 5; i++) stars += (i < r.rating ? '<i class="fas fa-star text-warning"></i>' : '<i class="far fa-star text-warning"></i>');
        return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
          '<div class="d-flex justify-content-between mb-1">' +
            '<b>' + escapeHtml(r.book_title) + '</b>' +
            '<span>' + stars + '</span>' +
          '</div>' +
          '<div class="text-muted small mb-1">' + escapeHtml(r.created_at) + ' · ' + escapeHtml(r.user_name || 'Гость') + '</div>' +
          (r.text ? '<div class="small">' + escapeHtml(r.text) + '</div>' : '') +
        '</div>';
      }).join('');
    });
  }

  // ============= АДМИН-ПАНЕЛЬ =============
  var adminOrdersCache = [];
  var adminUsersCache = [];
  var adminBooksCache = [];

  function renderAdminPanel(role) {
    var panel = $('adminPanel');
    if (!panel) return;
    if (role !== 'admin') {
      panel.style.display = 'none';
      if ($('myOrdersHeader')) $('myOrdersHeader').style.display = '';
      if ($('myOrdersContainer')) $('myOrdersContainer').style.display = '';
      return;
    }
    panel.style.display = '';
    if ($('myOrdersHeader')) $('myOrdersHeader').style.display = 'none';
    if ($('myOrdersContainer')) $('myOrdersContainer').style.display = 'none';

    var tabs = document.querySelectorAll('#adminTabs [data-admin-tab]');
    tabs.forEach(function (btn) {
      if (btn.__bound) return;
      btn.__bound = true;
      btn.addEventListener('click', function () {
        tabs.forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        var which = btn.dataset.adminTab;
        $('adminOrdersPane').style.display       = which === 'orders'       ? '' : 'none';
        $('adminUsersPane').style.display        = which === 'users'        ? '' : 'none';
        $('adminBooksPane').style.display        = which === 'books'        ? '' : 'none';
        $('adminStatsPane').style.display        = which === 'stats'        ? '' : 'none';
        $('adminManuscriptsPane').style.display  = which === 'manuscripts'  ? '' : 'none';
        $('adminRequestsPane').style.display     = which === 'requests'     ? '' : 'none';

        if (which === 'orders') loadAdminOrders();
        else if (which === 'users') loadAdminUsers();
        else if (which === 'books') loadAdminBooks();
        else if (which === 'stats') loadAdminStats();
        else if (which === 'manuscripts') loadAdminManuscripts();
        else if (which === 'requests') loadAdminRequests();
      });
    });

    var r1 = $('adminOrdersRefreshBtn');
    if (r1 && !r1.__bound) { r1.__bound = true; r1.addEventListener('click', loadAdminOrders); }
    var r2 = $('adminUsersRefreshBtn');
    if (r2 && !r2.__bound) { r2.__bound = true; r2.addEventListener('click', loadAdminUsers); }
    var r3 = $('adminBooksRefreshBtn');
    if (r3 && !r3.__bound) { r3.__bound = true; r3.addEventListener('click', loadAdminBooks); }
    var r4 = $('adminStatsRefreshBtn');
    if (r4 && !r4.__bound) { r4.__bound = true; r4.addEventListener('click', loadAdminStats); }
    var r5 = $('adminManuscriptsRefreshBtn');
    if (r5 && !r5.__bound) { r5.__bound = true; r5.addEventListener('click', loadAdminManuscripts); }
    var r6 = $('adminRequestsRefreshBtn');
    if (r6 && !r6.__bound) { r6.__bound = true; r6.addEventListener('click', loadAdminRequests); }

    var statusFilter = $('adminOrderStatusFilter');
    if (statusFilter && !statusFilter.__bound) {
      statusFilter.__bound = true;
      statusFilter.addEventListener('change', renderAdminOrders);
    }

    var addBookBtn = $('adminBookAddBtn');
    if (addBookBtn && !addBookBtn.__bound) {
      addBookBtn.__bound = true;
      addBookBtn.addEventListener('click', function () { showBookForm(null); });
    }
    var cancelBookBtn = $('abCancelBtn');
    if (cancelBookBtn && !cancelBookBtn.__bound) {
      cancelBookBtn.__bound = true;
      cancelBookBtn.addEventListener('click', function () { $('adminBookForm').style.display = 'none'; });
    }
    var saveBookBtn = $('abSaveBtn');
    if (saveBookBtn && !saveBookBtn.__bound) {
      saveBookBtn.__bound = true;
      saveBookBtn.addEventListener('click', saveBookForm);
    }

    loadAdminOrders();
  }

  function loadAdminOrders() {
    var cont = $('adminOrdersContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'admin_orders' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      adminOrdersCache = res.orders || [];
      renderAdminOrders();
    });
  }

  function renderAdminOrders() {
    var cont = $('adminOrdersContainer');
    if (!cont) return;
    var filter = $('adminOrderStatusFilter');
    var status = filter ? filter.value : '';

    var orders = adminOrdersCache.filter(function (o) { return !status || o.status === status; });
    if (orders.length === 0) { cont.innerHTML = '<div class="text-muted">Заказов нет.</div>'; return; }

    var statuses = {
      pending:   '<span class="badge bg-warning text-dark">Ожидает оплаты</span>',
      paid:      '<span class="badge bg-success">Оплачен</span>',
      cancelled: '<span class="badge bg-secondary">Отменён</span>'
    };

    cont.innerHTML = orders.map(function (o) {
      var itemsHtml = o.items.map(function (i) {
        return '<li>' + escapeHtml(i.title) + ' × ' + i.quantity + ' — ' + (i.price * i.quantity) + ' ₽</li>';
      }).join('');
      return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
        '<div class="d-flex justify-content-between mb-1">' +
          '<b>Заказ #' + o.id + '</b>' +
          '<span>' + (statuses[o.status] || o.status) + '</span>' +
        '</div>' +
        '<div class="text-muted mb-1">' + escapeHtml(o.created_at) + ' · Код: <code>' + escapeHtml(o.payment_code) + '</code></div>' +
        '<div class="mb-1"><b>Пользователь:</b> ' + escapeHtml(o.user_name || '—') + ' (' + escapeHtml(o.user_email || 'без email') + ') · ID: ' + o.user_id + '</div>' +
        '<div class="mb-1"><b>Получатель:</b> ' + escapeHtml(o.name) + ' · ' + escapeHtml(o.phone) + '</div>' +
        '<div class="mb-1"><b>Адрес:</b> ' + escapeHtml(o.address) + '</div>' +
        (o.comment ? '<div class="mb-1"><b>Комментарий:</b> ' + escapeHtml(o.comment) + '</div>' : '') +
        '<ul class="mb-1 ps-3">' + itemsHtml + '</ul>' +
        '<div class="fw-bold mb-2">Итого: ' + o.total + ' ₽</div>' +
        '<div class="d-flex flex-wrap gap-2 align-items-center">' +
          '<label class="small mb-0">Статус:</label>' +
          '<select class="form-select form-select-sm" style="width:auto" data-order-status="' + o.id + '">' +
            '<option value="pending"'   + (o.status === 'pending'   ? ' selected' : '') + '>Ожидает оплаты</option>' +
            '<option value="paid"'      + (o.status === 'paid'      ? ' selected' : '') + '>Оплачен</option>' +
            '<option value="cancelled"' + (o.status === 'cancelled' ? ' selected' : '') + '>Отменён</option>' +
          '</select>' +
          '<button class="btn btn-sm btn-outline-danger ms-auto" data-order-delete="' + o.id + '"><i class="fas fa-trash"></i> Удалить</button>' +
        '</div>' +
      '</div>';
    }).join('');

    cont.querySelectorAll('select[data-order-status]').forEach(function (sel) {
      sel.addEventListener('change', function () {
        var id = parseInt(sel.dataset.orderStatus, 10);
        var val = sel.value;
        sel.disabled = true;
        send({ action: 'admin_set_status', order_id: id, status: val }).then(function (res) {
          sel.disabled = false;
          if (!res.success) { alert(res.message || 'Не удалось изменить статус'); return; }
          adminOrdersCache.forEach(function (o) { if (o.id === id) o.status = val; });
          renderAdminOrders();
        });
      });
    });

    cont.querySelectorAll('button[data-order-delete]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.dataset.orderDelete, 10);
        if (!confirm('Удалить заказ #' + id + '? Действие необратимо.')) return;
        btn.disabled = true;
        send({ action: 'admin_delete_order', order_id: id }).then(function (res) {
          if (!res.success) { alert(res.message || 'Не удалось удалить'); btn.disabled = false; return; }
          adminOrdersCache = adminOrdersCache.filter(function (o) { return o.id !== id; });
          renderAdminOrders();
        });
      });
    });
  }

  function loadAdminUsers() {
    var cont = $('adminUsersContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'admin_users' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      adminUsersCache = res.users || [];
      renderAdminUsers();
    });
  }

  function renderAdminUsers() {
    var cont = $('adminUsersContainer');
    if (!cont) return;
    if (adminUsersCache.length === 0) { cont.innerHTML = '<div class="text-muted">Пользователей нет.</div>'; return; }
    var roleNames = { user: 'Пользователь', author: 'Автор', admin: 'Администратор' };
    cont.innerHTML = adminUsersCache.map(function (u) {
      return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
        '<div class="d-flex justify-content-between mb-1">' +
          '<b>' + escapeHtml(u.name || '—') + '</b>' +
          '<span class="badge bg-secondary">' + escapeHtml(roleNames[u.role] || u.role) + '</span>' +
        '</div>' +
        '<div class="text-muted small mb-1">ID: ' + u.id + ' · Ник: ' + escapeHtml(u.nick || '—') + '</div>' +
        '<div class="small mb-1"><b>Email:</b> ' + escapeHtml(u.email || '—') + '</div>' +
        '<div class="small mb-2"><b>Телефон:</b> ' + escapeHtml(u.phone || '—') + '</div>' +
        '<div class="d-flex gap-2 align-items-center">' +
          '<label class="small mb-0">Роль:</label>' +
          '<select class="form-select form-select-sm" style="width:auto" data-user-role="' + u.id + '">' +
            '<option value="user"'   + (u.role === 'user'   ? ' selected' : '') + '>Пользователь</option>' +
            '<option value="author"' + (u.role === 'author' ? ' selected' : '') + '>Автор</option>' +
            '<option value="admin"'  + (u.role === 'admin'  ? ' selected' : '') + '>Администратор</option>' +
          '</select>' +
        '</div>' +
      '</div>';
    }).join('');

    cont.querySelectorAll('select[data-user-role]').forEach(function (sel) {
      sel.addEventListener('change', function () {
        var id = parseInt(sel.dataset.userRole, 10);
        var val = sel.value;
        sel.disabled = true;
        send({ action: 'admin_set_role', user_id: id, role: val }).then(function (res) {
          sel.disabled = false;
          if (!res.success) { alert(res.message || 'Не удалось изменить роль'); loadAdminUsers(); return; }
          adminUsersCache.forEach(function (u) { if (u.id === id) u.role = val; });
          renderAdminUsers();
        });
      });
    });
  }

  function loadAdminBooks() {
    var cont = $('adminBooksContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'get_books' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      adminBooksCache = res.books || [];
      renderAdminBooks();
    });
  }

  function renderAdminBooks() {
    var cont = $('adminBooksContainer');
    if (!cont) return;
    if (adminBooksCache.length === 0) { cont.innerHTML = '<div class="text-muted">Книг нет.</div>'; return; }
    cont.innerHTML = adminBooksCache.map(function (b) {
      return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
        '<div class="d-flex gap-3">' +
          '<img src="' + escapeHtml(b.cover) + '" style="width:50px;height:70px;object-fit:cover;border-radius:6px" alt="">' +
          '<div class="flex-grow-1">' +
            '<div class="d-flex justify-content-between">' +
              '<b>' + escapeHtml(b.title) + '</b>' +
              '<span class="text-muted small">ID: ' + b.id + '</span>' +
            '</div>' +
            '<div class="small text-muted mb-1">' + escapeHtml(b.author) + ' · ' + escapeHtml(b.genre || '—') + '</div>' +
            '<div class="small mb-1">Цена: <b>' + b.price + ' ₽</b>' +
              (b.oldPrice ? ' <s class="text-muted">' + b.oldPrice + ' ₽</s>' : '') +
              (b.discount > 0 ? ' · скидка ' + b.discount + '%' : '') +
            '</div>' +
            '<div class="small mb-2">' +
              (b.isBestseller ? '<span class="badge bg-warning text-dark me-1">Бестселлер</span>' : '') +
              (b.editorPick ? '<span class="badge bg-info text-dark me-1">Выбор редакции</span>' : '') +
              (b.authorUserId ? '<span class="badge bg-primary me-1">Автор-пользователь ID: ' + b.authorUserId + '</span>' : '') +
            '</div>' +
            '<div class="d-flex gap-2">' +
              '<button class="btn btn-sm btn-outline-primary" data-book-edit="' + b.id + '"><i class="fas fa-pen"></i> Изменить</button>' +
              '<button class="btn btn-sm btn-outline-danger" data-book-delete="' + b.id + '"><i class="fas fa-trash"></i> Удалить</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
    }).join('');

    cont.querySelectorAll('button[data-book-edit]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.dataset.bookEdit, 10);
        var book = adminBooksCache.find(function (b) { return b.id === id; });
        if (book) showBookForm(book);
      });
    });
    cont.querySelectorAll('button[data-book-delete]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.dataset.bookDelete, 10);
        if (!confirm('Удалить книгу #' + id + '? Она также исчезнет из корзин пользователей.')) return;
        btn.disabled = true;
        send({ action: 'admin_delete_book', id: id }).then(function (res) {
          if (!res.success) { alert(res.message || 'Не удалось удалить'); btn.disabled = false; return; }
          adminBooksCache = adminBooksCache.filter(function (b) { return b.id !== id; });
          renderAdminBooks();
          loadBooks().then(function () {
            initFilterControls();
            renderCatalog();
            renderBestsellers();
            renderEditorsChoice();
          });
        });
      });
    });
  }

  function showBookForm(book) {
    var form = $('adminBookForm');
    if (!form) return;
    form.style.display = '';
    $('abError').textContent = '';
    if (book) {
      $('adminBookFormTitle').textContent = 'Редактирование книги #' + book.id;
      $('abId').value = book.id;
      $('abTitle').value = book.title || '';
      $('abAuthor').value = book.author || '';
      $('abGenre').value = book.genre || '';
      $('abPrice').value = book.price || 0;
      $('abOldPrice').value = book.oldPrice !== null && book.oldPrice !== undefined ? book.oldPrice : '';
      $('abDiscount').value = book.discount || 0;
      $('abCover').value = book.cover || '';
      $('abDescription').value = book.desc || '';
      $('abBestseller').checked = !!book.isBestseller;
      $('abEditor').checked = !!book.editorPick;
      $('abAuthorUserId').value = book.authorUserId || '';
    } else {
      $('adminBookFormTitle').textContent = 'Новая книга';
      $('abId').value = '';
      $('abTitle').value = ''; $('abAuthor').value = ''; $('abGenre').value = '';
      $('abPrice').value = ''; $('abOldPrice').value = ''; $('abDiscount').value = '';
      $('abCover').value = ''; $('abDescription').value = '';
      $('abBestseller').checked = false; $('abEditor').checked = false;
      $('abAuthorUserId').value = '';
    }
    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function saveBookForm() {
    var errEl = $('abError'); errEl.textContent = '';
    var id = $('abId').value;
    var title = $('abTitle').value.trim();
    var author = $('abAuthor').value.trim();
    if (!title || !author) { errEl.textContent = 'Укажите название и автора'; return; }

    var data = {
      action: 'admin_update_book',
      id: id,
      title: title,
      author: author,
      genre: $('abGenre').value.trim(),
      price: $('abPrice').value || 0,
      old_price: $('abOldPrice').value,
      discount: $('abDiscount').value || 0,
      cover: $('abCover').value.trim(),
      description: $('abDescription').value.trim(),
      author_user_id: $('abAuthorUserId').value.trim()
    };
    if ($('abBestseller').checked) data.is_bestseller = '1';
    if ($('abEditor').checked)     data.editor_pick = '1';

    if (!id) {
      data.action = 'admin_add_book';
      delete data.id;
    }

    send(data).then(function (res) {
      if (!res.success) { errEl.textContent = res.message || 'Ошибка сохранения'; return; }
      $('adminBookForm').style.display = 'none';
      loadAdminBooks();
      loadBooks().then(function () {
        initFilterControls();
        renderCatalog();
        renderBestsellers();
        renderEditorsChoice();
      });
    });
  }

  function loadAdminStats() {
    var cont = $('adminStatsContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'admin_stats' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      renderAdminStats(res);
    });
  }

  function renderAdminStats(data) {
    var cont = $('adminStatsContainer');
    if (!cont) return;
    var t = data.totals || {};
    var html = '';
    html += '<div class="row g-2 mb-3">';
    html += statCard('Всего заказов', t.orders_total, '#0d6efd');
    html += statCard('Оплачено', t.orders_paid, '#198754');
    html += statCard('Ожидают', t.orders_pending, '#ffc107');
    html += statCard('Отменено', t.orders_cancel, '#6c757d');
    html += statCard('Выручка (оплач.)', t.revenue_paid + ' ₽', '#dc3545');
    html += statCard('Выручка (без отмен)', t.revenue_all + ' ₽', '#6f42c1');
    html += statCard('Пользователей', t.users_total, '#0dcaf0');
    html += statCard('Книг в каталоге', t.books_total, '#fd7e14');
    html += '</div>';

    html += '<h6 class="mt-3 mb-2"><i class="fas fa-book me-1"></i> Топ-10 книг по продажам</h6>';
    if (!data.topBooks || data.topBooks.length === 0) html += '<div class="text-muted small mb-3">Нет данных.</div>';
    else {
      html += '<div class="table-responsive mb-3"><table class="table table-sm table-striped"><thead><tr><th>#</th><th>Книга</th><th class="text-end">Экз.</th><th class="text-end">Выручка</th></tr></thead><tbody>';
      data.topBooks.forEach(function (b, i) {
        html += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(b.title) + ' <span class="text-muted small">(ID ' + b.book_id + ')</span></td><td class="text-end">' + b.qty + '</td><td class="text-end">' + b.revenue + ' ₽</td></tr>';
      });
      html += '</tbody></table></div>';
    }

    html += '<h6 class="mt-3 mb-2"><i class="fas fa-user-pen me-1"></i> Топ-10 авторов по выручке</h6>';
    if (!data.topAuthors || data.topAuthors.length === 0) html += '<div class="text-muted small mb-3">Нет данных.</div>';
    else {
      html += '<div class="table-responsive mb-3"><table class="table table-sm table-striped"><thead><tr><th>#</th><th>Автор</th><th class="text-end">Экз.</th><th class="text-end">Выручка</th></tr></thead><tbody>';
      data.topAuthors.forEach(function (a, i) {
        html += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(a.author) + '</td><td class="text-end">' + a.qty + '</td><td class="text-end">' + a.revenue + ' ₽</td></tr>';
      });
      html += '</tbody></table></div>';
    }

    html += '<h6 class="mt-3 mb-2"><i class="fas fa-users me-1"></i> Топ-10 покупателей (оплаченные)</h6>';
    if (!data.topCustomers || data.topCustomers.length === 0) html += '<div class="text-muted small">Нет данных.</div>';
    else {
      html += '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>#</th><th>Покупатель</th><th class="text-end">Заказов</th><th class="text-end">Сумма</th></tr></thead><tbody>';
      data.topCustomers.forEach(function (c, i) {
        html += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(c.name) + ' <span class="text-muted small">' + escapeHtml(c.email) + '</span></td><td class="text-end">' + c.orders_count + '</td><td class="text-end">' + c.total + ' ₽</td></tr>';
      });
      html += '</tbody></table></div>';
    }

    cont.innerHTML = html;
  }

  function statCard(label, value, color) {
    return '<div class="col-6 col-md-3">' +
      '<div class="border rounded-3 p-2 bg-light h-100">' +
        '<div class="small text-muted">' + escapeHtml(label) + '</div>' +
        '<div class="fw-bold" style="color:' + color + ';font-size:1.1rem">' + escapeHtml(String(value)) + '</div>' +
      '</div>' +
    '</div>';
  }

  function loadAdminManuscripts() {
    var cont = $('adminManuscriptsContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'admin_manuscripts' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      if (!res.manuscripts || res.manuscripts.length === 0) { cont.innerHTML = '<div class="text-muted">Рукописей нет.</div>'; return; }
      var statuses = {
        pending: 'pending', review: 'review', accepted: 'accepted', rejected: 'rejected'
      };
      cont.innerHTML = res.manuscripts.map(function (m) {
        return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
          '<div class="d-flex justify-content-between mb-1">' +
            '<b>Рукопись #' + m.id + '</b>' +
            '<span class="text-muted small">' + escapeHtml(m.created_at) + '</span>' +
          '</div>' +
          '<div class="small mb-1"><b>Пользователь:</b> ' + escapeHtml(m.user_name || '—') + ' (' + escapeHtml(m.user_email || '') + ') · ID: ' + m.user_id + '</div>' +
          '<div class="small mb-1"><b>Автор:</b> ' + escapeHtml(m.name) + ' · ' + escapeHtml(m.email) + '</div>' +
          '<div class="small mb-1"><b>Жанр:</b> ' + escapeHtml(m.genre || '—') + (m.file_name ? ' · файл: ' + escapeHtml(m.file_name) : '') + '</div>' +
          '<div class="small mb-2">' + escapeHtml(m.synopsis) + '</div>' +
          '<div class="row g-2">' +
            '<div class="col-md-4">' +
              '<select class="form-select form-select-sm" data-ms-status="' + m.id + '">' +
                '<option value="pending"'  + (m.status === 'pending'  ? ' selected' : '') + '>На рассмотрении</option>' +
                '<option value="review"'   + (m.status === 'review'   ? ' selected' : '') + '>На рецензии</option>' +
                '<option value="accepted"' + (m.status === 'accepted' ? ' selected' : '') + '>Принята</option>' +
                '<option value="rejected"' + (m.status === 'rejected' ? ' selected' : '') + '>Отклонена</option>' +
              '</select>' +
            '</div>' +
            '<div class="col-md-8">' +
              '<input type="text" class="form-control form-control-sm" data-ms-comment="' + m.id + '" value="' + escapeHtml(m.admin_comment || '') + '" placeholder="Комментарий редактора">' +
            '</div>' +
          '</div>' +
          '<button class="btn btn-sm btn-primary-custom mt-2" data-ms-save="' + m.id + '">Сохранить</button>' +
        '</div>';
      }).join('');

      cont.querySelectorAll('button[data-ms-save]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.dataset.msSave;
          var statusEl = cont.querySelector('[data-ms-status="' + id + '"]');
          var commentEl = cont.querySelector('[data-ms-comment="' + id + '"]');
          send({ action: 'admin_update_manuscript', id: id, status: statusEl.value, admin_comment: commentEl.value }).then(function (res) {
            if (!res.success) { alert(res.message || 'Ошибка'); return; }
            loadAdminManuscripts();
          });
        });
      });
    });
  }

  function loadAdminRequests() {
    var cont = $('adminRequestsContainer');
    if (!cont) return;
    cont.innerHTML = 'Загрузка…';
    send({ action: 'admin_book_requests' }).then(function (res) {
      if (!res.success) { cont.innerHTML = '<div class="text-danger">' + escapeHtml(res.message || 'Ошибка') + '</div>'; return; }
      if (!res.requests || res.requests.length === 0) { cont.innerHTML = '<div class="text-muted">Заявок нет.</div>'; return; }
      cont.innerHTML = res.requests.map(function (r) {
        return '<div class="border rounded-3 p-3 mb-2 bg-light">' +
          '<div class="d-flex justify-content-between mb-1">' +
            '<b>' + escapeHtml(r.title) + '</b>' +
            '<span class="text-muted small">' + escapeHtml(r.created_at) + '</span>' +
          '</div>' +
          '<div class="small mb-1"><b>Пользователь:</b> ' + escapeHtml(r.user_name || '—') + ' (' + escapeHtml(r.user_email || '') + ') · ID: ' + r.user_id + '</div>' +
          '<div class="small mb-1"><b>Автор:</b> ' + escapeHtml(r.author) + ' · <b>Жанр:</b> ' + escapeHtml(r.genre || '—') + '</div>' +
          (r.description ? '<div class="small mb-2">' + escapeHtml(r.description) + '</div>' : '') +
          '<div class="row g-2">' +
            '<div class="col-md-4">' +
              '<select class="form-select form-select-sm" data-req-status="' + r.id + '">' +
                '<option value="pending"'  + (r.status === 'pending'  ? ' selected' : '') + '>На рассмотрении</option>' +
                '<option value="approved"' + (r.status === 'approved' ? ' selected' : '') + '>Одобрена</option>' +
                '<option value="rejected"' + (r.status === 'rejected' ? ' selected' : '') + '>Отклонена</option>' +
              '</select>' +
            '</div>' +
            '<div class="col-md-8">' +
              '<input type="text" class="form-control form-control-sm" data-req-comment="' + r.id + '" value="' + escapeHtml(r.admin_comment || '') + '" placeholder="Комментарий">' +
            '</div>' +
          '</div>' +
          '<button class="btn btn-sm btn-primary-custom mt-2" data-req-save="' + r.id + '">Сохранить</button>' +
        '</div>';
      }).join('');

      cont.querySelectorAll('button[data-req-save]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.dataset.reqSave;
          var statusEl = cont.querySelector('[data-req-status="' + id + '"]');
          var commentEl = cont.querySelector('[data-req-comment="' + id + '"]');
          send({ action: 'admin_update_book_request', id: id, status: statusEl.value, admin_comment: commentEl.value }).then(function (res) {
            if (!res.success) { alert(res.message || 'Ошибка'); return; }
            loadAdminRequests();
          });
        });
      });
    });
  }

  // ============= АКЦИИ =============
  function renderPromo() {
    var promos = [
      { title: "Скидка 25% на детективы", desc: "Все детективы со скидкой. Промокод: DETECTIVE25", bg: "#8a2929" },
      { title: "Купи 3 книги — четвёртая в подарок", desc: "Автоматически в корзине", bg: "#8a2929" },
      { title: "Бесплатная доставка от 1500₽", desc: "По Иркутску и области бесплатно", bg: "#8a2929" }
    ];
    var container = $('promoContainer');
    if (!container) return;
    container.innerHTML = promos.map(function (p) {
      return '<div class="col-md-4"><div class="promo-card" style="background: linear-gradient(135deg, ' + p.bg + ', #2c1a1a);">' +
        '<i class="fas fa-gift fa-3x mb-3"></i><h4>' + escapeHtml(p.title) + '</h4><p>' + escapeHtml(p.desc) + '</p>' +
        '<button class="btn btn-light mt-2 promo-action-btn">Подробнее</button></div></div>';
    }).join('');
    container.querySelectorAll('.promo-action-btn').forEach(function (btn) {
      btn.addEventListener('click', function () { alert('Акция действует до конца месяца!'); });
    });
  }

  // ============= СТАРТ =============
  cart = JSON.parse(localStorage.getItem('bookCart') || '[]');

  loadBooks()
    .then(function () {
      initFilterControls();
      bindFilterEvents();
      return refreshProfile();
    })
    .then(function () {
      if (isAuthed()) return loadCartFromServer();
    })
    .then(function () {
      renderCatalog();
      renderBestsellers();
      renderEditorsChoice();
      renderCart();
    });

  renderPromo();
})();
</script>

</body>
</html>