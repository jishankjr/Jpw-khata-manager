USE jp_workshop_khata;
ALTER TABLE purchases ADD COLUMN description VARCHAR(255) NULL AFTER total;
