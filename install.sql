-- PalliCare MySQL Database Schema (converted from Prisma PostgreSQL schema)
-- Run this in cPanel phpMyAdmin before first use
-- Default admin: admin@pallicare.dev / password123

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `refresh_tokens`;
DROP TABLE IF EXISTS `video_call_requests`;
DROP TABLE IF EXISTS `prescription_items`;
DROP TABLE IF EXISTS `prescriptions`;
DROP TABLE IF EXISTS `doctor_assignments`;
DROP TABLE IF EXISTS `medicines`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL UNIQUE,
  `email` VARCHAR(255) DEFAULT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('HEALTH_WORKER','DOCTOR','ADMIN') NOT NULL,
  `status` ENUM('PENDING','ACTIVE','SUSPENDED') NOT NULL DEFAULT 'PENDING',
  `can_write_prescription` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `refresh_tokens` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `token` VARCHAR(512) NOT NULL UNIQUE,
  `user_id` VARCHAR(36) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_rt_user` (`user_id`),
  KEY `idx_rt_token` (`token`),
  CONSTRAINT `fk_rt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `doctor_assignments` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `doctor_id` VARCHAR(36) NOT NULL,
  `health_worker_id` VARCHAR(36) NOT NULL UNIQUE,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_da_doctor` (`doctor_id`),
  KEY `idx_da_hw` (`health_worker_id`),
  CONSTRAINT `fk_da_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_da_hw` FOREIGN KEY (`health_worker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `medicines` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `generic_name` VARCHAR(100) DEFAULT NULL,
  `form` ENUM('TABLET','CAPSULE','SYRUP','INJECTION','OINTMENT','DROPS','INHALER','SUPPOSITORY','PATCH','OTHER') NOT NULL DEFAULT 'TABLET',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_med_name` (`name`),
  KEY `idx_med_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `prescriptions` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `health_worker_id` VARCHAR(36) NOT NULL,
  `patient_name` VARCHAR(100) NOT NULL,
  `patient_age` INT NOT NULL,
  `patient_gender` VARCHAR(20) NOT NULL,
  `chief_complaints` TEXT NOT NULL,
  `on_examination` TEXT DEFAULT NULL,
  `advice` TEXT DEFAULT NULL,
  `status` ENUM('DRAFT','SUBMITTED','REVIEWED') NOT NULL DEFAULT 'DRAFT',
  `reviewed_by_id` VARCHAR(36) DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `review_notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_pres_hw` (`health_worker_id`),
  KEY `idx_pres_status` (`status`),
  KEY `idx_pres_created` (`created_at`),
  CONSTRAINT `fk_pres_hw` FOREIGN KEY (`health_worker_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_pres_reviewer` FOREIGN KEY (`reviewed_by_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `prescription_items` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `prescription_id` VARCHAR(36) NOT NULL,
  `medicine_id` VARCHAR(36) NOT NULL,
  `dose` VARCHAR(50) NOT NULL,
  `frequency` VARCHAR(50) NOT NULL,
  `duration` VARCHAR(50) NOT NULL,
  `instructions` VARCHAR(255) DEFAULT NULL,
  KEY `idx_pi_pres` (`prescription_id`),
  KEY `idx_pi_med` (`medicine_id`),
  CONSTRAINT `fk_pi_pres` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pi_med` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `video_call_requests` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `requester_id` VARCHAR(36) NOT NULL,
  `receiver_id` VARCHAR(36) NOT NULL,
  `note` TEXT DEFAULT NULL,
  `status` ENUM('PENDING','ACCEPTED','DECLINED') NOT NULL DEFAULT 'PENDING',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_vcr_requester` (`requester_id`),
  KEY `idx_vcr_receiver` (`receiver_id`),
  KEY `idx_vcr_status` (`status`),
  CONSTRAINT `fk_vcr_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vcr_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `admin_id` VARCHAR(36) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `target_entity_type` VARCHAR(50) DEFAULT NULL,
  `target_entity_id` VARCHAR(36) DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_al_admin` (`admin_id`),
  KEY `idx_al_action` (`action`),
  KEY `idx_al_created` (`created_at`),
  CONSTRAINT `fk_al_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed medicines only. Users and assignments are seeded securely by admin/install.php
-- which generates real bcrypt hashes on the server.
INSERT INTO `medicines` (`id`, `name`, `generic_name`, `form`, `is_active`)
VALUES
('med_001', 'Paracetamol 500mg', 'Paracetamol', 'TABLET', 1),
('med_002', 'Amoxicillin 500mg', 'Amoxicillin', 'CAPSULE', 1),
('med_003', 'Metformin 500mg', 'Metformin', 'TABLET', 1),
('med_004', 'Omeprazole 20mg', 'Omeprazole', 'CAPSULE', 1),
('med_005', 'Amlodipine 5mg', 'Amlodipine', 'TABLET', 1),
('med_006', 'Cetirizine 10mg', 'Cetirizine', 'TABLET', 1),
('med_007', 'Azithromycin 500mg', 'Azithromycin', 'TABLET', 1),
('med_008', 'Antacid Suspension', 'Magnesium Hydroxide', 'SYRUP', 1),
('med_009', 'Salbutamol Inhaler', 'Salbutamol', 'INHALER', 1),
('med_010', 'ORS Powder', 'Oral Rehydration Salts', 'OTHER', 1),
('med_011', 'Zinc 20mg', 'Zinc Sulphate', 'TABLET', 1),
('med_012', 'Vitamin C 500mg', 'Ascorbic Acid', 'TABLET', 1),
('med_013', 'Iron + Folate', 'Ferrous Sulphate', 'TABLET', 1),
('med_014', 'Clotrimazole Cream', 'Clotrimazole', 'OINTMENT', 1),
('med_015', 'Diclofenac 50mg', 'Diclofenac Sodium', 'TABLET', 1),
('med_016', 'Metronidazole 400mg', 'Metronidazole', 'TABLET', 1),
('med_017', 'Ciprofloxacin 500mg', 'Ciprofloxacin', 'TABLET', 1),
('med_018', 'Cough Syrup (Adults)', 'Dextromethorphan', 'SYRUP', 1),
('med_019', 'Eye Drops (Chloramphenicol)', 'Chloramphenicol', 'DROPS', 1),
('med_020', 'Atenolol 50mg', 'Atenolol', 'TABLET', 1);
