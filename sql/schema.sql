-- Заявки з Excel-імпорту.
-- Файл можна виконати клієнтом mysql від імені користувача з правом CREATE DATABASE.

CREATE DATABASE IF NOT EXISTS leads_import
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE leads_import;

CREATE TABLE IF NOT EXISTS applications (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  external_id     VARCHAR(32)     NOT NULL,
  created_at      DATETIME        NULL,
  first_name      VARCHAR(128)    NOT NULL DEFAULT '',
  last_name       VARCHAR(128)    NOT NULL DEFAULT '',
  phone           VARCHAR(32)     NULL,
  email           VARCHAR(255)    NULL,
  city            VARCHAR(128)    NULL,
  source          VARCHAR(128)    NULL,
  utm_campaign    VARCHAR(128)    NULL,
  product         VARCHAR(255)    NULL,
  budget_uah      DECIMAL(12,2)   NULL,
  status          VARCHAR(64)     NULL,
  manager         VARCHAR(128)    NULL,
  comment         TEXT            NULL,
  next_contact_at DATETIME        NULL,
  PRIMARY KEY (id),
  KEY idx_applications_external_id (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
