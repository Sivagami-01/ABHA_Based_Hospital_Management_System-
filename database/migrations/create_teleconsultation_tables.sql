CREATE TABLE IF NOT EXISTS doctor_availability (
    id INT NOT NULL AUTO_INCREMENT,
    doctor_id INT NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doctor_availability_window (doctor_id, day_of_week, start_time, end_time),
    KEY idx_doctor_availability_lookup (doctor_id, day_of_week, is_active, start_time),
    CONSTRAINT fk_doctor_availability_doctor
        FOREIGN KEY (doctor_id) REFERENCES doctors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teleconsultations (
    id INT NOT NULL AUTO_INCREMENT,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    requested_department_id INT NOT NULL,
    doctor_id INT NULL,
    room_name VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teleconsultation_appointment (appointment_id),
    UNIQUE KEY uq_teleconsultation_room_name (room_name),
    KEY idx_teleconsultation_patient (patient_id, created_at),
    KEY idx_teleconsultation_department (requested_department_id, appointment_id),
    KEY idx_teleconsultation_doctor (doctor_id, appointment_id),
    CONSTRAINT fk_teleconsultation_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    CONSTRAINT fk_teleconsultation_patient
        FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_teleconsultation_department
        FOREIGN KEY (requested_department_id) REFERENCES departments(id),
    CONSTRAINT fk_teleconsultation_doctor
        FOREIGN KEY (doctor_id) REFERENCES doctors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
