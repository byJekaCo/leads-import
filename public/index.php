<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/LeadImporter.php';

$error = null;
$report = null;

if (isset($_GET['rows'], $_GET['sec'], $_GET['db'])) {
    $report = [
        'rows' => (int) $_GET['rows'],
        'seconds' => (float) $_GET['sec'],
        'db_count' => (int) $_GET['db'],
    ];
}

try {
    $db = app_db();
} catch (Throwable $e) {
    $db = null;
    $error = 'Немає з’єднання з базою. Перевірте config.php. ' . $e->getMessage();
}

if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new RuntimeException('Оберіть файл .xlsx.');
        }
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int) ($file['error'] ?? 0);
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                throw new RuntimeException('Файл більший за ліміт upload_max_filesize / post_max_size.');
            }
            throw new RuntimeException('Не вдалося завантажити файл.');
        }
        $name = (string) ($file['name'] ?? '');
        if (!preg_match('/\.xlsx$/i', $name)) {
            throw new RuntimeException('Потрібен файл Excel .xlsx.');
        }
        $result = (new LeadImporter($db))->import((string) $file['tmp_name']);
        header('Location: index.php?rows=' . $result['rows'] . '&sec=' . rawurlencode(number_format($result['seconds'], 2, '.', '')) . '&db=' . $result['db_count']);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$total = null;
$preview = [];
if ($db) {
    $total = (int) $db->query('SELECT COUNT(*) FROM applications')->fetch_row()[0];
    $result = $db->query(
        'SELECT external_id, created_at, first_name, last_name, phone, email, city, source, product, budget_uah, status, manager, comment, next_contact_at
         FROM applications
         ORDER BY id
         LIMIT 20'
    );
    while ($row = $result->fetch_assoc()) {
        $preview[] = $row;
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Імпорт заявок</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font: 16px/1.45 "Segoe UI", sans-serif; background: #f4f6f8; color: #1c2430; }
        main { max-width: 1100px; margin: 0 auto; padding: 32px 20px 64px; }
        h1 { margin: 0 0 8px; font-size: 28px; }
        p.lead { margin: 0 0 24px; color: #526072; }
        form, .card { background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; padding: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; }
        input[type=file] { display: block; margin-bottom: 16px; }
        button { background: #0b6e4f; color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font: inherit; cursor: pointer; }
        button:hover { background: #095c42; }
        .msg { margin: 16px 0; padding: 12px 14px; border-radius: 8px; }
        .ok { background: #e7f6ee; color: #0d5c32; }
        .err { background: #fdecec; color: #8d1d1d; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: #fff; font-size: 13px; }
        th, td { border-bottom: 1px solid #e6ebf0; padding: 8px 10px; text-align: left; vertical-align: top; }
        th { background: #f8fafb; }
        .meta { margin-top: 28px; color: #526072; }
    </style>
</head>
<body>
<main>
    <h1>Імпорт заявок</h1>
    <p class="lead">Файл Excel записується в таблицю <code>applications</code>. Повторний імпорт замінює попередні рядки.</p>

    <form method="post" enctype="multipart/form-data">
        <label for="file">Файл .xlsx</label>
        <input id="file" type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        <button type="submit">Імпортувати</button>
    </form>

    <?php if ($error): ?>
        <div class="msg err"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($report): ?>
        <div class="msg ok">
            Записано <?= (int) $report['rows'] ?> рядків за <?= h(number_format((float) $report['seconds'], 2, '.', '')) ?> с.
            У базі зараз <?= (int) $report['db_count'] ?>.
        </div>
    <?php endif; ?>

    <?php if ($total !== null): ?>
        <p class="meta">Зараз у базі: <strong><?= (int) $total ?></strong></p>
        <?php if ($preview): ?>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Створено</th>
                    <th>Ім’я</th>
                    <th>Телефон</th>
                    <th>Місто</th>
                    <th>Продукт</th>
                    <th>Бюджет</th>
                    <th>Статус</th>
                    <th>Менеджер</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($preview as $row): ?>
                    <tr>
                        <td><?= h($row['external_id']) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h(trim($row['first_name'] . ' ' . $row['last_name'])) ?></td>
                        <td><?= h($row['phone']) ?></td>
                        <td><?= h($row['city']) ?></td>
                        <td><?= h($row['product']) ?></td>
                        <td><?= h($row['budget_uah']) ?></td>
                        <td><?= h($row['status']) ?></td>
                        <td><?= h($row['manager']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
