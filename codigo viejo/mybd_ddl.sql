-- Esquema de gobierno
USE mybd_gobierno;

-- Cabecera de la evaluación (un llenado del form)
CREATE TABLE IF NOT EXISTS evaluacion_cid (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actividad_id BIGINT NOT NULL,
  evaluador VARCHAR(120) NOT NULL,
  comentarios TEXT,
  exp_c INT NOT NULL DEFAULT 0,  -- exposición (conteo ponderado) Confidencialidad
  exp_i INT NOT NULL DEFAULT 0,  -- exposición Integridad
  exp_d INT NOT NULL DEFAULT 0,  -- exposición Disponibilidad
  FOREIGN KEY (actividad_id) REFERENCES actividad(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Detalle por pregunta del form
CREATE TABLE IF NOT EXISTS evaluacion_cid_det (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  evaluacion_id BIGINT NOT NULL,
  pregunta VARCHAR(400) NOT NULL,
  respuesta ENUM('SI','NO','NA') NOT NULL,
  c_aplica BOOLEAN NOT NULL DEFAULT 0,  -- columna CONFIDENCIALIDAD marcada
  i_aplica BOOLEAN NOT NULL DEFAULT 0,  -- columna INTEGRIDAD marcada
  d_aplica BOOLEAN NOT NULL DEFAULT 0,  -- columna DISPONIBILIDAD marcada
  requisito_id BIGINT NULL,             -- ISO 27002 / COBIT 4.x referenciado
  FOREIGN KEY (evaluacion_id) REFERENCES evaluacion_cid(id) ON DELETE CASCADE,
  FOREIGN KEY (requisito_id) REFERENCES requisito(id) ON DELETE SET NULL,
  INDEX (evaluacion_id)
) ENGINE=InnoDB;
