
-- mybd_seeds.sql
-- Minimal seed data: institution, org, activities AC1..AC12, sample ISO/COBIT requirements.

USE mybd_gobierno;

INSERT INTO institucion (nombre, codigo) VALUES ('Institución Demo', 'INST-DEMO');
INSERT INTO area_direccion (institucion_id, nombre, codigo)
SELECT id, 'Tecnologías de Información', 'TI' FROM institucion LIMIT 1;
INSERT INTO subarea (area_id, nombre, codigo)
SELECT id, 'Base de Datos', 'BD' FROM area_direccion LIMIT 1;

-- Activities AC1..AC12 (names per provided mapping document)
INSERT INTO actividad (subarea_id, codigo, nombre) VALUES
((SELECT id FROM subarea LIMIT 1),'AC1','Respaldos y pruebas de restauración'),
((SELECT id FROM subarea LIMIT 1),'AC2','Gestión de accesos y privilegios'),
((SELECT id FROM subarea LIMIT 1),'AC3','Gestión de parches y vulnerabilidades'),
((SELECT id FROM subarea LIMIT 1),'AC4','Monitoreo, logging y auditoría'),
((SELECT id FROM subarea LIMIT 1),'AC5','Alta disponibilidad y DRP'),
((SELECT id FROM subarea LIMIT 1),'AC6','Cifrado de datos en reposo y en tránsito'),
((SELECT id FROM subarea LIMIT 1),'AC7','Gestión de cambios y migraciones'),
((SELECT id FROM subarea LIMIT 1),'AC8','Clasificación y manejo de datos'),
((SELECT id FROM subarea LIMIT 1),'AC9','Separación de ambientes y datos de prueba'),
((SELECT id FROM subarea LIMIT 1),'AC10','Seguridad física y del entorno'),
((SELECT id FROM subarea LIMIT 1),'AC11','Relación con proveedores (DBaaS/Cloud)'),
((SELECT id FROM subarea LIMIT 1),'AC12','Gestión de incidentes de seguridad');

-- Sample risks per activity (CID with P/I from mapping doc; R is generated)
-- AC1: D 4/5, I 3/5, C 2/4 (store as three risks, linked to AC1)
-- We'll create a few examples; teams can expand.
INSERT INTO riesgo (categoria, descripcion, prob, impacto) VALUES
('D','Pérdida de disponibilidad por fallas sin respaldo válido',4,5),
('I','Corrupción de datos por restauración fallida',3,5),
('C','Exposición de información en respaldos no cifrados',2,4);

INSERT INTO actividad_riesgo
SELECT a.id, r.id FROM actividad a JOIN riesgo r
WHERE a.codigo='AC1' AND r.descripcion IN (
  'Pérdida de disponibilidad por fallas sin respaldo válido',
  'Corrupción de datos por restauración fallida',
  'Exposición de información en respaldos no cifrados'
);

-- Norms: ISO 27002 (2013) and COBIT 4 (processes)
INSERT INTO norma (familia, codigo, titulo) VALUES
('ISO27002_2013','A.12.3.1','Respaldo de la información'),
('ISO27002_2013','A.9.1.1','Política de control de acceso'),
('ISO27002_2013','A.12.4.1','Registro de eventos'),
('ISO27002_2013','A.12.6.1','Gestión de vulnerabilidades técnicas'),
('ISO27002_2013','A.17.2.1','Redundancias'),
('COBIT4','DS5','Garantizar la seguridad de los sistemas'),
('COBIT4','DS4','Garantizar la continuidad del servicio'),
('COBIT4','DS11','Administrar datos'),
('COBIT4','PO4','Definir procesos, organización y relaciones de TI');

-- Requisitos (simple: un requisito por código en este seed)
INSERT INTO requisito (norma_id, codigo, descripcion)
SELECT id, codigo, titulo FROM norma;

-- Example controls and mapping to requirements
INSERT INTO control (nombre, descripcion, tipo, naturaleza, periodicidad, dueno, estado) VALUES
('Política y procedimiento de backups','Plan de respaldo y restauración probado','Preventivo','Mixto','Mensual','DBA','Implementado'),
('Gestión de privilegios','Revisión y ajuste de privilegios mínimos','Preventivo','Mixto','Trimestral','DBA','Implementado'),
('Auditoría y logs de BD','Registro de eventos y protección de logs','Detectivo','Automatizado','Diario','Seguridad TI','Implementado');

-- Link controls to ISO / COBIT requirements
INSERT INTO control_requisito
SELECT c.id, r.id FROM control c JOIN requisito r
WHERE (c.nombre='Política y procedimiento de backups' AND r.codigo IN ('A.12.3.1','A.17.2.1','DS11','DS4'))
   OR (c.nombre='Gestión de privilegios' AND r.codigo IN ('A.9.1.1','DS5','PO4'))
   OR (c.nombre='Auditoría y logs de BD' AND r.codigo IN ('A.12.4.1','DS5'));

-- Link risks to controls
INSERT INTO riesgo_control
SELECT (SELECT id FROM riesgo WHERE descripcion='Pérdida de disponibilidad por fallas sin respaldo válido'),
       (SELECT id FROM control WHERE nombre='Política y procedimiento de backups')
UNION ALL
SELECT (SELECT id FROM riesgo WHERE descripcion='Exposición de información en respaldos no cifrados'),
       (SELECT id FROM control WHERE nombre='Política y procedimiento de backups')
UNION ALL
SELECT (SELECT id FROM riesgo WHERE descripcion='Corrupción de datos por restauración fallida'),
       (SELECT id FROM control WHERE nombre='Política y procedimiento de backups');

-- Evidence examples
INSERT INTO evidencia (control_id, actividad_id, tipo, ubicacion, frecuencia, descripcion)
SELECT (SELECT id FROM control WHERE nombre='Política y procedimiento de backups'),
       (SELECT id FROM actividad WHERE codigo='AC1'),
       'Reporte','/evidencias/backups/2025-07-reporte.pdf','Mensual','Reporte de copia y prueba de restauración';

-- Done
