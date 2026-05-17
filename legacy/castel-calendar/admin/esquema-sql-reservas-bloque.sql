-- Esquema actualizado para reservas por bloque (reemplaza reservas_por_dia)
-- RoomKeeper - calendario privado

-- Tabla: bloques_horarios (malla horaria del colegio)
CREATE TABLE IF NOT EXISTS bloques_horarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    tipo ENUM('clase', 'recreo', 'almuerzo') DEFAULT 'clase',
    es_bloqueado BOOLEAN DEFAULT FALSE,
    dia_semana SET('L', 'M', 'X', 'J', 'V', 'S', 'D') DEFAULT 'L,M,X,J,V',
    INDEX idx_tipo (tipo),
    INDEX idx_bloqueado (es_bloqueado)
);

-- Insertar bloques de clase y recreos si no existen
INSERT IGNORE INTO bloques_horarios (id, nombre, hora_inicio, hora_fin, tipo, es_bloqueado) VALUES
(1, 'Bloque 1', '08:00:00', '09:30:00', 'clase', FALSE),
(2, 'Bloque 2', '09:45:00', '11:15:00', 'clase', FALSE),
(3, 'Bloque 3', '11:30:00', '13:00:00', 'clase', FALSE),
(4, 'Recreo 1', '09:30:00', '09:45:00', 'recreo', TRUE),
(5, 'Recreo 2', '11:15:00', '11:30:00', 'recreo', TRUE),
(6, 'Almuerzo', '13:00:00', '14:00:00', 'almuerzo', TRUE),
(7, 'Bloque 4', '14:00:00', '15:30:00', 'clase', FALSE),
(8, 'Bloque 5', '15:45:00', '17:15:00', 'clase', FALSE),
(9, 'Recreo 3', '15:30:00', '15:45:00', 'recreo', TRUE);

-- Tabla: reservas_por_bloque (reservas individuales por bloque)
CREATE TABLE IF NOT EXISTS reservas_por_bloque (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bloque_id INT NOT NULL,
    slot_id VARCHAR(10) NOT NULL,
    fecha DATE NOT NULL,
    room VARCHAR(20) NOT NULL DEFAULT 'basica',
    status ENUM('disponible', 'reservada', 'mantenimiento', 'bloqueada') DEFAULT 'disponible',
    owner_email VARCHAR(255) NOT NULL,
    owner_name VARCHAR(255) NOT NULL,
    responsable_label VARCHAR(255) DEFAULT '',
    notes TEXT DEFAULT '',
    version INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fecha_bloque (fecha, bloque_id),
    INDEX idx_fecha_slot (fecha, slot_id),
    INDEX idx_room_fecha (room, fecha),
    INDEX idx_owner (owner_email),
    FOREIGN KEY (bloque_id) REFERENCES bloques_horarios(id)
);

-- Tabla: solicitudes_cambio_bloque (cambios sobre reservas ajenas)
CREATE TABLE IF NOT EXISTS solicitudes_cambio_bloque (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reserva_id INT NOT NULL,
    bloque_id INT NOT NULL,
    slot_id VARCHAR(10) NOT NULL,
    fecha DATE NOT NULL,
    room VARCHAR(20) NOT NULL,
    requested_status ENUM('disponible', 'reservada', 'mantenimiento', 'bloqueada'),
    requested_responsable_label VARCHAR(255) DEFAULT '',
    requested_notes TEXT DEFAULT '',
    requested_by_email VARCHAR(255) NOT NULL,
    requested_by_name VARCHAR(255) NOT NULL,
    reason TEXT DEFAULT '',
    decision ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fecha (fecha),
    INDEX idx_solicitante (requested_by_email),
    INDEX idx_decision (decision),
    FOREIGN KEY (bloque_id) REFERENCES bloques_horarios(id)
);

-- Tabla: jornadas_especiales (feriados internos)
CREATE TABLE IF NOT EXISTS jornadas_especiales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fecha DATE NOT NULL UNIQUE,
    label VARCHAR(255) NOT NULL,
    tipo ENUM('feriado', 'interna', 'mantencion') DEFAULT 'feriado',
    created_by VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fecha (fecha)
);

-- Tabla: configuracion_jornada_ti (horario admin TI)
CREATE TABLE IF NOT EXISTS configuracion_jornada_ti (
    id INT PRIMARY KEY,
    dia_semana SET('L', 'M', 'X', 'J', 'V') DEFAULT 'L,M,X,J',
    hora_salida TIME DEFAULT '17:30:00',
    hora_salida_viernes TIME DEFAULT '16:35:00',
    mensaje_fuera_horario VARCHAR(100) DEFAULT '⚠️ Fuera de Horario Soporte',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insertar config jornada TI por defecto
INSERT IGNORE INTO configuracion_jornada_ti (id, dia_semana, hora_salida, hora_salida_viernes) VALUES
(1, 'L,M,X,J', '17:30:00', '16:35:00');

-- Tabla: cursos (nómina de cursos)
CREATE TABLE IF NOT EXISTS cursos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    nivel VARCHAR(20) NOT NULL,
    orden INT DEFAULT 0,
    INDEX idx_nivel (nivel),
    INDEX idx_orden (orden)
);

-- Insertar cursos
INSERT IGNORE INTO cursos (id, nombre, nivel, orden) VALUES
(1, '1° básico', 'basica', 1),
(2, '2° básico', 'basica', 2),
(3, '3° básico', 'basica', 3),
(4, '4° básico', 'basica', 4),
(5, '5° básico', 'basica', 5),
(6, '6° básico', 'basica', 6),
(7, '7° básico', 'basica', 7),
(8, '8° básico', 'basica', 8),
(9, '1° medio', 'media', 9),
(10, '2° medio', 'media', 10),
(11, '3° medio', 'media', 11),
(12, '4° medio', 'media', 12);

-- Tabla: mapa_sala_40_puestos (alumnos por reserva)
CREATE TABLE IF NOT EXISTS mapa_sala_40_puestos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reserva_bloque_id INT NOT NULL,
    numero_puesto INT NOT NULL,
    nombre_alumno VARCHAR(255) DEFAULT '',
    INDEX idx_reserva (reserva_bloque_id),
    INDEX idx_puesto (numero_puesto),
    FOREIGN KEY (reserva_bloque_id) REFERENCES reservas_por_bloque(id)
);

-- Vista: reservas_del_dia (para mostrar reservas por día)
CREATE OR REPLACE VIEW vista_reservas_dia AS
SELECT 
    r.fecha,
    r.room,
    COUNT(CASE WHEN r.status = 'reservada' THEN 1 END) as bloques_ocupados,
    COUNT(*) as total_bloques
FROM reservas_por_bloque r
GROUP BY r.fecha, r.room;

-- Vista: bloques_disponibles (para calendario)
CREATE OR REPLACE VIEW vista_bloques_disponibles AS
SELECT 
    b.id,
    b.nombre,
    b.hora_inicio,
    b.hora_fin,
    b.tipo,
    r.fecha,
    r.room,
    r.status,
    r.owner_name,
    r.responsable_label
FROM bloques_horarios b
LEFT JOIN reservas_por_bloque r ON b.id = r.bloque_id AND r.status = 'reservada';
