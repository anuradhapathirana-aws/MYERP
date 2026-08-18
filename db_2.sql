-- -----------------------------------------------------
-- Table `erp_central`.`inv_transactions`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `erp_central`.`inv_transactions` (
  `id` INT NOT NULL,
  `transaction_date` DATETIME(10) NULL,
  `item_id` VARCHAR(45) NULL,
  `inv_products_id` BIGINT UNSIGNED NOT NULL,
  `inv_stores_id` BIGINT UNSIGNED NOT NULL,
  `inv_locations_id` BIGINT UNSIGNED NOT NULL,
  `transaction_type` VARCHAR(45) NULL,
  `qty_in` VARCHAR(45) NULL,
  `qty_out` VARCHAR(45) NULL,
  PRIMARY KEY (`id`),
  INDEX `fk_inventory_transactions_inv_products1_idx` (`inv_products_id` ASC) VISIBLE,
  INDEX `fk_inventory_transactions_inv_stores1_idx` (`inv_stores_id` ASC) VISIBLE,
  INDEX `fk_inventory_transactions_inv_locations1_idx` (`inv_locations_id` ASC) VISIBLE,
  CONSTRAINT `fk_inventory_transactions_inv_products1`
    FOREIGN KEY (`inv_products_id`)
    REFERENCES `erp_central`.`inv_products` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_inventory_transactions_inv_stores1`
    FOREIGN KEY (`inv_stores_id`)
    REFERENCES `erp_central`.`inv_stores` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_inventory_transactions_inv_locations1`
    FOREIGN KEY (`inv_locations_id`)
    REFERENCES `erp_central`.`inv_locations` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `erp_central`.`inv_pr`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `erp_central`.`inv_pr` (
  `id` INT NOT NULL,
  `date` VARCHAR(45) NULL,
  `reference_no` VARCHAR(45) NULL,
  `purpose` VARCHAR(45) NULL,
  `inv_prcol` VARCHAR(45) NULL,
  `inv_stores_id` BIGINT UNSIGNED NOT NULL,
  `remarks` VARCHAR(45) NULL,
  `target_location` VARCHAR(45) NULL,
  `required_date` VARCHAR(45) NULL,
  `target_store` VARCHAR(45) NULL,
  `transport_mode` VARCHAR(45) NULL,
  `inv_locations_id1` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `fk_inv_pr_inv_stores1_idx` (`inv_stores_id` ASC) VISIBLE,
  INDEX `fk_inv_pr_inv_locations1_idx` (`inv_locations_id1` ASC) VISIBLE,
  CONSTRAINT `fk_inv_pr_inv_stores1`
    FOREIGN KEY (`inv_stores_id`)
    REFERENCES `erp_central`.`inv_stores` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_inv_pr_inv_locations1`
    FOREIGN KEY (`inv_locations_id1`)
    REFERENCES `erp_central`.`inv_locations` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `erp_central`.`inv_po`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `erp_central`.`inv_po` (
  `id` INT NOT NULL,
  `transaction_date` VARCHAR(45) NULL,
  `expected_date` VARCHAR(45) NULL,
  `po_no` VARCHAR(45) NULL,
  `payment_terms` VARCHAR(45) NULL,
  `reference_no` VARCHAR(45) NULL,
  `purchase_request` VARCHAR(45) NULL,
  `location` VARCHAR(45) NULL,
  `contact_person_name` VARCHAR(45) NULL,
  `` VARCHAR(45) NULL,
  `inv_stores_id` BIGINT UNSIGNED NOT NULL,
  `biling_address` VARCHAR(45) NULL,
  `shipping_address` VARCHAR(45) NULL,
  `remark` VARCHAR(45) NULL,
  `consigment_basis` TINYINT NULL,
  `inv_pr_id` INT NULL,
  `inv_supplier_masters_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `fk_inv_po_inv_stores1_idx` (`inv_stores_id` ASC) VISIBLE,
  INDEX `fk_inv_po_inv_pr1_idx` (`inv_pr_id` ASC) VISIBLE,
  INDEX `fk_inv_po_inv_supplier_masters1_idx` (`inv_supplier_masters_id` ASC) VISIBLE,
  CONSTRAINT `fk_inv_po_inv_stores1`
    FOREIGN KEY (`inv_stores_id`)
    REFERENCES `erp_central`.`inv_stores` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_inv_po_inv_pr1`
    FOREIGN KEY (`inv_pr_id`)
    REFERENCES `erp_central`.`inv_pr` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_inv_po_inv_supplier_masters1`
    FOREIGN KEY (`inv_supplier_masters_id`)
    REFERENCES `erp_central`.`inv_supplier_masters` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `erp_central`.`inv_grn`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `erp_central`.`inv_grn` (
  `id` INT NOT NULL,
  `transaction_date` VARCHAR(45) NULL,
  `grn_no` VARCHAR(45) NULL,
  `reference_no` VARCHAR(45) NULL,
  `last_grn_date` VARCHAR(45) NULL,
  `last_grn_ammount` VARCHAR(45) NULL,
  `inv_grncol1` VARCHAR(45) NULL,
  `inv_po_id` INT NOT NULL,
  `inv_po_inv_supplier_masters_id` BIGINT UNSIGNED NOT NULL,
  `payment_terms` VARCHAR(45) NULL,
  PRIMARY KEY (`id`),
  INDEX `fk_inv_grn_inv_po1_idx` (`inv_po_id` ASC, `inv_po_inv_supplier_masters_id` ASC) VISIBLE,
  CONSTRAINT `fk_inv_grn_inv_po1`
    FOREIGN KEY (`inv_po_id`)
    REFERENCES `erp_central`.`inv_po` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `erp_central`.`inv_grn_items`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `erp_central`.`inv_grn_items` (
  `id` INT NOT NULL,
  `inv_grn_id` INT NOT NULL,
  `inv_products_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`, `inv_grn_id`),
  INDEX `fk_inv_grn_items_inv_grn1_idx` (`inv_grn_id` ASC) VISIBLE,
  INDEX `fk_inv_grn_items_inv_products1_idx` (`inv_products_id` ASC) VISIBLE,
  CONSTRAINT `fk_inv_grn_items_inv_grn1`
    FOREIGN KEY (`inv_grn_id`)
    REFERENCES `erp_central`.`inv_grn` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_inv_grn_items_inv_products1`
    FOREIGN KEY (`inv_products_id`)
    REFERENCES `erp_central`.`inv_products` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `erp_central`.`inv_pr_items`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `erp_central`.`inv_pr_items` (
  `id` INT NOT NULL,
  `inv_pr_id` INT NOT NULL,
  `inv_products_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`, `inv_pr_id`),
  INDEX `fk_inv_pr_items_inv_pr1_idx` (`inv_pr_id` ASC) VISIBLE,
  INDEX `fk_inv_pr_items_inv_products1_idx` (`inv_products_id` ASC) VISIBLE,
  CONSTRAINT `fk_inv_pr_items_inv_pr1`
    FOREIGN KEY (`inv_pr_id`)
    REFERENCES `erp_central`.`inv_pr` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_inv_pr_items_inv_products1`
    FOREIGN KEY (`inv_products_id`)
    REFERENCES `erp_central`.`inv_products` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `erp_central`.`inv_po_items`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `erp_central`.`inv_po_items` (
  `id` INT NOT NULL,
  `inv_po_id` INT NOT NULL,
  `inv_products_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`, `inv_po_id`),
  INDEX `fk_inv_po_items_inv_po1_idx` (`inv_po_id` ASC) VISIBLE,
  INDEX `fk_inv_po_items_inv_products1_idx` (`inv_products_id` ASC) VISIBLE,
  CONSTRAINT `fk_inv_po_items_inv_po1`
    FOREIGN KEY (`inv_po_id`)
    REFERENCES `erp_central`.`inv_po` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_inv_po_items_inv_products1`
    FOREIGN KEY (`inv_products_id`)
    REFERENCES `erp_central`.`inv_products` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;