-- =====================================================
-- Table: b_bitrix_inventar_equipment
-- Stores all IT equipment in inventory
-- =====================================================
CREATE TABLE IF NOT EXISTS b_bitrix_inventar_equipment (
    ID INT(11) NOT NULL AUTO_INCREMENT,
    COD_INVENTAR VARCHAR(50) NOT NULL,
    DENUMIRE VARCHAR(255) NOT NULL,
    TIP_ENUM VARCHAR(50) NOT NULL,
    PRODUCATOR VARCHAR(100),
    MODEL VARCHAR(100),
    SERIAL_NR VARCHAR(100),
    DATA_ACHIZITIE DATE,
    FURNIZOR VARCHAR(255),
    COST_ACHIZITIE DECIMAL(10,2),
    DATA_EXPIRARE_GARANTIE DATE,
    STARE_ENUM VARCHAR(50) NOT NULL DEFAULT 'in_stock',
    LOCATIE VARCHAR(255),
    CONTRACT_SERVICE VARCHAR(255),
    OTHERS_INFO TEXT,
    BARCODE_TEXT VARCHAR(255),
    QR_CODE_TEXT TEXT,
    NOTIFICATION_SENT VARCHAR(1) DEFAULT 'N',
    CREATED_BY INT(11),
    CREATED_AT TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UPDATED_BY INT(11),
    UPDATED_AT TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ID),
    UNIQUE KEY uk_cod_inventar (COD_INVENTAR),
    UNIQUE KEY uk_serial (SERIAL_NR),
    INDEX idx_tip (TIP_ENUM),
    INDEX idx_stare (STARE_ENUM),
    INDEX idx_created (CREATED_AT)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: b_bitrix_inventar_allocation
-- Stores equipment allocations to users
-- =====================================================
CREATE TABLE IF NOT EXISTS b_bitrix_inventar_allocation (
    ID INT(11) NOT NULL AUTO_INCREMENT,
    EQUIPMENT_ID INT(11) NOT NULL,
    USER_ID INT(11) NOT NULL,
    DATA_PREDARE DATE NOT NULL,
    DATA_RETURNARE DATE,
    MOTIV_RETURNARE TEXT,
    CREATED_AT TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ID),
    INDEX idx_equipment (EQUIPMENT_ID),
    INDEX idx_user (USER_ID),
    INDEX idx_data_predare (DATA_PREDARE),
    INDEX idx_data_returnare (DATA_RETURNARE),
    FOREIGN KEY (EQUIPMENT_ID) REFERENCES b_bitrix_inventar_equipment(ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: b_bitrix_inventar_history
-- Audit log for equipment changes
-- =====================================================
CREATE TABLE IF NOT EXISTS b_bitrix_inventar_history (
    ID INT(11) NOT NULL AUTO_INCREMENT,
    EQUIPMENT_ID INT(11) NOT NULL,
    USER_ID INT(11) NOT NULL,
    ACTION VARCHAR(50) NOT NULL,
    FIELD_NAME VARCHAR(100),
    OLD_VALUE TEXT,
    NEW_VALUE TEXT,
    CREATED_AT TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ID),
    INDEX idx_equipment (EQUIPMENT_ID),
    INDEX idx_user (USER_ID),
    INDEX idx_action (ACTION),
    INDEX idx_created (CREATED_AT),
    FOREIGN KEY (EQUIPMENT_ID) REFERENCES b_bitrix_inventar_equipment(ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: b_bitrix_inventar_service
-- Service and repair management
-- =====================================================
CREATE TABLE IF NOT EXISTS b_bitrix_inventar_service (
    ID INT(11) NOT NULL AUTO_INCREMENT,
    EQUIPMENT_ID INT(11) NOT NULL,
    DATA_INTRARE DATE NOT NULL,
    DATA_IESIRE DATE,
    PROBLEMA TEXT,
    SOLUTIE TEXT,
    COST_SERVICE DECIMAL(10,2),
    STATUS_ENUM VARCHAR(50) DEFAULT 'in_service',
    CREATED_AT TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ID),
    INDEX idx_equipment (EQUIPMENT_ID),
    INDEX idx_status (STATUS_ENUM),
    INDEX idx_data_intrare (DATA_INTRARE),
    FOREIGN KEY (EQUIPMENT_ID) REFERENCES b_bitrix_inventar_equipment(ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: b_bitrix_inventar_types
-- Stores equipment types
-- =====================================================
CREATE TABLE IF NOT EXISTS b_bitrix_inventar_types (
    ID INT(11) NOT NULL AUTO_INCREMENT,
    CODE VARCHAR(50) NOT NULL,
    NAME VARCHAR(100) NOT NULL,
    SORT INT(11) DEFAULT 100,
    PRIMARY KEY (ID),
    UNIQUE KEY uk_code (CODE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO b_bitrix_inventar_types (CODE, NAME, SORT) VALUES
('Workstation', 'Workstation', 10),
('monitor', 'Monitor', 20),
('multifunctional', 'Printer & Scanner', 30),
('peripheral', 'Peripherals', 40),
('accessories', 'Cables & Accessories', 50);

-- =====================================================
-- Table: b_bitrix_inventar_status
-- Stores equipment statuses
-- =====================================================
CREATE TABLE IF NOT EXISTS b_bitrix_inventar_status (
    ID INT(11) NOT NULL AUTO_INCREMENT,
    CODE VARCHAR(50) NOT NULL,
    NAME VARCHAR(100) NOT NULL,
    SORT INT(11) DEFAULT 100,
    COLOR VARCHAR(20) DEFAULT '#666666',
    PRIMARY KEY (ID),
    UNIQUE KEY uk_code (CODE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO b_bitrix_inventar_status (CODE, NAME, SORT, COLOR) VALUES
('in_use', 'In use', 10, '#4CAF50'),
('in_stock', 'In stock', 20, '#9E9E9E'),
('repair', 'In repair', 30, '#FF9800'),
('scrapped', 'Scrapped', 40, '#f44336'),
('lost', 'Lost', 50, '#5D4037');

-- =====================================================
-- Table: b_bitrix_inventar_custom_fields
-- Stores custom fields per type
-- =====================================================
CREATE TABLE IF NOT EXISTS b_bitrix_inventar_custom_fields (
    ID INT(11) NOT NULL AUTO_INCREMENT,
    TYPE_CODE VARCHAR(50) NOT NULL,
    FIELD_LABEL VARCHAR(100) NOT NULL,
    FIELD_TYPE VARCHAR(20) NOT NULL DEFAULT 'text',
    FIELD_OPTIONS TEXT,
    SORT INT(11) DEFAULT 100,
    PRIMARY KEY (ID),
    INDEX idx_type_code (TYPE_CODE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;