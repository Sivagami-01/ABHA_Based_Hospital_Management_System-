CREATE TABLE consultation_fee_settings (
    hospital_id INT NOT NULL,
    online_fee DECIMAL(10,2) NOT NULL,
    in_person_fee DECIMAL(10,2) NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (hospital_id),
    CONSTRAINT fk_consultation_fee_hospital
        FOREIGN KEY (hospital_id) REFERENCES hospitals(id),
    CONSTRAINT fk_consultation_fee_admin
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_consultation_fees_nonnegative
        CHECK (online_fee >= 0 AND in_person_fee >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    consultation_type ENUM('online','in_person') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_mode ENUM('demo') NOT NULL DEFAULT 'demo',
    status ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    transaction_reference VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_appointment_payment_appointment (appointment_id),
    UNIQUE KEY uq_appointment_payment_reference (transaction_reference),
    KEY idx_appointment_payment_patient (patient_id, status),
    KEY idx_appointment_payment_doctor (doctor_id, status),
    CONSTRAINT fk_appointment_payment_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    CONSTRAINT fk_appointment_payment_patient
        FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_appointment_payment_doctor
        FOREIGN KEY (doctor_id) REFERENCES doctors(id),
    CONSTRAINT chk_appointment_payment_amount
        CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
