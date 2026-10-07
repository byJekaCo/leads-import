# Імпорт заявок

Сторінка завантажує Excel `.xlsx` і записує всі рядки в MySQL. Розраховано на файл близько 100 000 заявок при стандартному `max_execution_time=30` (ліміт не збільшується в коді).

## Структура бази

Файл `sql/schema.sql`:

- база `leads_import`
- таблиця `applications` (один рядок файлу = один запис; `external_id` індексований, але не унікальний — у файлі є повторні номери)

Колонки збігаються з заголовком аркуша: `external_id`, `created_at`, `first_name`, `last_name`, `phone`, `email`, `city`, `source`, `utm_campaign`, `product`, `budget_uah`, `status`, `manager`, `comment`, `next_contact_at`.

Дати в Excel (серійний номер) перетворюються на `DATETIME`. Порожній бюджет і коментар зберігаються як `NULL`.

## Запуск

Потрібні PHP 8.1+ (`mysqli`, `zip`, `xmlreader`) і MySQL 8 або MariaDB.

```sql
SOURCE sql/schema.sql;
```

Схема також створюється сама під час відкриття сторінки.

Скопіюйте `config.php` за потреби або задайте змінні `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`. Локальні перевизначення можна покласти в `config.local.php` (цей файл не входить у git).

Корінь сайту — каталог `public`. Для файлу ~11 МБ потрібні `upload_max_filesize=32M` і `post_max_size=40M` (див. `public/.user.ini` і `public/.htaccess`).

```bash
php -d max_execution_time=30 -d upload_max_filesize=32M -d post_max_size=40M -S 127.0.0.1:8080 -t public
```

Відкрийте сторінку, оберіть `.xlsx` і натисніть «Імпортувати». Повторний імпорт очищає таблицю і записує файл наново. Тестовий файл лежить у `samples/baza-danyh.xlsx` (100 000 рядків).

З консолі:

```bash
php -d max_execution_time=30 bin/import.php path/to/file.xlsx
```
