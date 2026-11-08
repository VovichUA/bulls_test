# Демо-файли Google Sheets

Це синтетичні CSV-фікстури з однаковими продажами, без credentials і приватних ID:

- `sales.csv`: date, product_name, price, amount.
- `sales-reordered.csv`: інший порядок тих самих полів і додаткова notes.

Створіть власну Google-таблицю. File → Import → Upload → виберіть `sales.csv`,
роздільник — кома; назвіть вкладку **Sales**. Імпортуйте `sales-reordered.csv`
на окрему вкладку **Sales reordered** у тому самому файлі. В обох header_row=1.
Це мають бути нативні Google Sheets, а не XLSX у режимі Office.
Надайте Service Account доступ Viewer до всього файлу.

Запустіть `SheetConfigurationSeeder` за командою з README проєкту. У UI змініть
placeholder ID джерела Sales demo на ID свого Google-файлу й активуйте джерело.
Наявний target Sales → sales уже має mappings:

| Джерело | БД | Тип |
| --- | --- | --- |
| date | date | date, Y-m-d |
| product_name | name | string |
| price | product_price | decimal(12,2) |
| amount | amount | integer |

Для другої вкладки додайте окремий tab/target з тими самими mappings. Можна
вказати ту саму таблицю `sales`: рядки розділяються за sync_target_id.
Дві вкладки дадуть чотири рядки поточного дня (по два на target), не два:
це два незалежні знімки. Для порівняння зручно також указати `sales_reordered`.
Колонку notes не мапте — імпортер її проігнорує.

Запустіть вкладки вручну за працюючого воркера й перевірте succeeded у журналі.
Цільові таблиці створює перший імпорт, не seeder. Після демонстрації деактивуйте
джерело, щоб воно не брало участі у щоденному розкладі.

CSV залишають дату в ISO-форматі. Якщо Google перетворив її на локальну дату,
задайте для стовпця Google формат yyyy-mm-dd або змініть format mapping під
фактичний формат, який повертає Google API.
