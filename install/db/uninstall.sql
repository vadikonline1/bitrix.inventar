-- =====================================================
-- Module uninstall
-- File: install/db/uninstall.sql
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS b_bitrix_inventar_custom_fields;
DROP TABLE IF EXISTS b_bitrix_inventar_status;
DROP TABLE IF EXISTS b_bitrix_inventar_types;
DROP TABLE IF EXISTS b_bitrix_inventar_service;
DROP TABLE IF EXISTS b_bitrix_inventar_history;
DROP TABLE IF EXISTS b_bitrix_inventar_allocation;
DROP TABLE IF EXISTS b_bitrix_inventar_equipment;

SET FOREIGN_KEY_CHECKS = 1;