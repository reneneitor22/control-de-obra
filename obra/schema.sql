-- Control de Obra — esquema MySQL para importar en phpMyAdmin (cPanel).
-- Equivalente a init_db.php. Charset utf8mb4.

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    usuario VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    -- Roles: 'admin' | 'supervisor' (ligado a proyecto_id) | 'bodeguero' (bodega central).
    rol VARCHAR(20) NOT NULL DEFAULT 'supervisor',
    proyecto_id INT NULL,
    activo TINYINT NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proyectos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(160) NOT NULL,
    cliente VARCHAR(160) NULL,
    ubicacion VARCHAR(255) NULL,
    descripcion VARCHAR(255) NULL,
    unidad VARCHAR(30) NOT NULL DEFAULT 'Villa',
    unidad_plural VARCHAR(30) NOT NULL DEFAULT 'Villas',
    inv_responsable VARCHAR(160) NULL,
    inv_cargo VARCHAR(120) NOT NULL DEFAULT 'Bodeguero',
    inv_fecha DATE NULL,
    arquitecto_nombre VARCHAR(160) NULL,
    arquitecto_whatsapp VARCHAR(40) NULL,
    maquinaria_whatsapp VARCHAR(40) NULL,
    activo TINYINT NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS villas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    tipo VARCHAR(40) NOT NULL DEFAULT 'Villa',
    num_personas INT NOT NULL DEFAULT 0,
    personas_real INT NOT NULL DEFAULT 0,
    responsable VARCHAR(120) NULL,
    notas VARCHAR(255) NULL,
    orden INT NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_villas_proyecto (proyecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tareas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    villa_id INT NOT NULL,
    fase TINYINT NOT NULL DEFAULT 1,
    fase_nombre VARCHAR(120) NULL,
    codigo VARCHAR(12) NULL,
    concepto VARCHAR(200) NOT NULL DEFAULT '',
    estado VARCHAR(14) NOT NULL DEFAULT 'planeacion',
    prioridad VARCHAR(10) NOT NULL DEFAULT 'media',
    fecha_inicio DATE NULL,
    fecha_fin DATE NULL,
    fecha_inicio_real DATE NULL,
    fecha_fin_real DATE NULL,
    orden INT NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tareas_villa (villa_id),
    INDEX idx_tareas_fase (villa_id, fase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventario_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    cantidad VARCHAR(20) NULL,
    descripcion VARCHAR(200) NOT NULL,
    modelo VARCHAR(120) NULL,
    no_serie VARCHAR(120) NULL,
    observaciones VARCHAR(255) NULL,
    orden INT NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inv_proyecto (proyecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Plantilla de trabajadores por obra (asistencia con nombres consistentes).
CREATE TABLE IF NOT EXISTS trabajadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    puesto VARCHAR(80) NULL,
    activo TINYINT NOT NULL DEFAULT 1,
    orden INT NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trab_proyecto (proyecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Asistencia diaria (presente/falta + nota). Alimenta el reporte semanal plan vs real.
CREATE TABLE IF NOT EXISTS asistencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    villa_id INT NULL,
    trabajador_id INT NULL,
    nombre VARCHAR(120) NOT NULL,
    fecha DATE NOT NULL,
    hora VARCHAR(5) NULL,
    estado VARCHAR(10) NOT NULL DEFAULT 'presente',
    nota VARCHAR(255) NULL,
    creado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_asis_proyecto (proyecto_id),
    INDEX idx_asis_fecha (proyecto_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Solicitudes de material (cabecera) + renglones. El arquitecto aprueba/rechaza.
CREATE TABLE IF NOT EXISTS solicitudes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    villa_id INT NULL,
    solicitante VARCHAR(120) NULL,
    fecha_requerida DATE NULL,
    estado VARCHAR(10) NOT NULL DEFAULT 'pendiente',
    nota VARCHAR(255) NULL,
    creado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revisado_por INT NULL,
    revisado_en DATETIME NULL,
    INDEX idx_sol_proyecto (proyecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitud_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    solicitud_id INT NOT NULL,
    material VARCHAR(160) NOT NULL,
    material_alt VARCHAR(160) NULL,
    cantidad VARCHAR(20) NULL,
    unidad VARCHAR(20) NULL,
    orden INT NOT NULL DEFAULT 0,
    INDEX idx_solit_solicitud (solicitud_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rentas de maquinaria/equipo (días, u horas para la retroexcavadora).
CREATE TABLE IF NOT EXISTS rentas_equipo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    villa_id INT NULL,
    equipo VARCHAR(160) NOT NULL,
    proveedor VARCHAR(160) NULL,
    modo VARCHAR(5) NOT NULL DEFAULT 'dia',
    cantidad VARCHAR(10) NULL,
    costo_unitario VARCHAR(20) NULL,
    costo_total VARCHAR(20) NULL,
    fecha_inicio DATE NULL,
    fecha_fin DATE NULL,
    nota VARCHAR(255) NULL,
    creado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_renta_proyecto (proyecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Planos / documentos de la obra (PDF, imágenes, DWG…) por proyecto y opcional unidad.
-- El archivo físico vive en data/planos/ y se sirve por plano_archivo.php.
CREATE TABLE IF NOT EXISTS planos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    villa_id INT NULL,
    titulo VARCHAR(160) NOT NULL,
    categoria VARCHAR(40) NULL,
    archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NULL,
    mime VARCHAR(100) NULL,
    tamano INT NOT NULL DEFAULT 0,
    orden INT NOT NULL DEFAULT 0,
    creado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_planos_proyecto (proyecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Documentos legales (contratos, licencias, permisos, pólizas, fianzas, escrituras).
-- proyecto_id NULL = documento de la EMPRESA (acta constitutiva, RFC, IMSS…).
-- archivo NULL = registrado pero todavía sin PDF (trámite en proceso).
-- reemplaza_a = id del documento que esta renovación sustituye (el viejo se archiva).
-- El archivo físico vive en data/legales/<proyecto_id|0>/ y se sirve por legal_archivo.php.
CREATE TABLE IF NOT EXISTS documentos_legales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NULL,
    villa_id INT NULL,
    tipo VARCHAR(40) NOT NULL DEFAULT 'Otro',
    titulo VARCHAR(160) NOT NULL,
    contraparte VARCHAR(160) NULL,
    folio VARCHAR(80) NULL,
    monto DECIMAL(14,2) NULL,
    fecha_emision DATE NULL,
    fecha_vencimiento DATE NULL,
    alerta_dias INT NOT NULL DEFAULT 30,
    archivado TINYINT NOT NULL DEFAULT 0,
    reemplaza_a INT NULL,
    notas VARCHAR(500) NULL,
    archivo VARCHAR(255) NULL,
    nombre_original VARCHAR(255) NULL,
    mime VARCHAR(100) NULL,
    tamano INT NOT NULL DEFAULT 0,
    creado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_legal_proyecto (proyecto_id),
    INDEX idx_legal_venc (fecha_vencimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuario admin inicial (contraseña: admin123 — CÁMBIALA).
-- Hash generado con password_hash('admin123', PASSWORD_DEFAULT).
INSERT INTO usuarios (nombre, usuario, password_hash, rol, activo)
SELECT 'Administrador', 'admin',
       '$2y$10$DOTvm5800ppsdvyivSc3o.LRv2upBQiXfL0FJbnWS7002/WBDCWYC', 'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE rol = 'admin');
